<?php /* UPGRADE-V3-SMART-BOT */
/* ==========================================================================
   telegram_bot.php  —  IOSMODS.XYZ Smart Telegram Control Center
   - Complete silence to anyone NOT in admin_ids (zero reply, zero popup).
   - Auto-learns Telegram Custom Emojis sent in chat, replaces 100% of normal icons,
     and saves them permanently in MySQL site_config + JSON vault (even after Premium expires).
   - Auto-deletes Admin commands & Bot UI panels after a fixed delay (never deletes alerts!).
   - Bug-free Maintenance ON/OFF (full admin_cp parity with secret bypass link & password + per-tool maintenance).
   - Smart AI Monitor: Anti-DDoS shield control, Sad-Face timed bans, VPN/Multi-account control, 1-tap Key Unlock.
   - PHP 7.2 – 8.3+ compatible.
   ========================================================================== */

ob_start();
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

require_once __DIR__ . '/telegram.php';

/* ------------------------------------------------------------------
 * Background CLI Auto-Delete Timer Worker (runs quietly without DB)
 * ------------------------------------------------------------------ */
if (PHP_SAPI === 'cli' && isset($argv) && is_array($argv) && in_array('--autodelete-sweep', $argv, true)) {
    @set_time_limit(330);
    $lockFile = tg_dir() . '/tg_ad_worker.lock';
    $fh       = @fopen($lockFile, 'c+');
    if ($fh === false || !@flock($fh, LOCK_EX | LOCK_NB)) {
        exit(0);
    }
    $endAt = time() + 310;
    while (time() < $endAt) {
        @touch($lockFile);
        tg_autodelete_sweep();
        $rem = tg_store_read('autodelete');
        if (!$rem) {
            break;
        }
        $nextDue = min(array_map('intval', $rem));
        $wait    = max(1, min(10, $nextDue - time()));
        sleep($wait);
    }
    @flock($fh, LOCK_UN);
    @fclose($fh);
    @unlink($lockFile);
    exit(0);
}

if (!tg_enabled()) {
    http_response_code(503);
    exit('Telegram bot not configured.');
}

tg_boot('bot');
$MT_ALLOW_PAGES = ['telegram_bot.php', 'telegram_bot']; // Bot must stay alive even when site maintenance is ON!
require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (isset($conn) && $conn instanceof mysqli) {
    try {
        $conn->set_charset('utf8mb4');
    } catch (Throwable $e) {
        // keep existing charset
    }
}

if (is_file(__DIR__ . '/ipguard.php')) {
    require_once __DIR__ . '/ipguard.php';
}
if (!function_exists('ipg_norm')) {
    function ipg_norm($ip)
    {
        $ip = trim((string) $ip);
        return filter_var($ip, FILTER_VALIDATE_IP) ? strtolower($ip) : '';
    }
}
function bot_ipg_ready()
{
    foreach (['ipg_ban', 'ipg_unban', 'ipg_find', 'ipg_can_ban'] as $f) {
        if (!function_exists($f)) {
            return false;
        }
    }
    return true;
}
tg_ensure_tables();

$CTX = [
    'admin'        => '',
    'chat'         => '',
    'mid'          => 0,
    'edit'         => false,
    'toast'        => '',
    'name'         => '',
    'preserve_msg' => false, // true ONLY when clicking inline buttons on permanent website alerts
];

/* ------------------------------------------------------------------
 * 1. Prepared-Statement DB Helpers
 * ------------------------------------------------------------------ */

function bdb()
{
    return $GLOBALS['conn'];
}

function bq($sql, $types = '', array $params = [])
{
    $stmt = bdb()->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('prepare failed: ' . bdb()->error);
    }
    if ($types !== '' && $params) {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('execute failed: ' . $error);
    }
    return $stmt;
}

function bq_all($sql, $types = '', array $params = [])
{
    $stmt = bq($sql, $types, $params);
    $res  = $stmt->get_result();
    $rows = [];
    while ($res && ($r = $res->fetch_assoc())) {
        $rows[] = $r;
    }
    $stmt->close();
    return $rows;
}

function bq_row($sql, $types = '', array $params = [])
{
    $rows = bq_all($sql, $types, $params);
    return $rows ? $rows[0] : null;
}

function bq_val($sql, $types = '', array $params = [], $default = 0)
{
    try {
        $r = bq_row($sql, $types, $params);
    } catch (Throwable $e) {
        return $default;
    }
    if (!$r) {
        return $default;
    }
    $v = reset($r);
    return $v === null ? $default : $v;
}

function bq_exec($sql, $types = '', array $params = [])
{
    $stmt = bq($sql, $types, $params);
    $out  = ['n' => $stmt->affected_rows, 'id' => $stmt->insert_id];
    $stmt->close();
    return $out;
}

function bt_tables()
{
    static $t = null;
    if ($t === null) {
        $t = [];
        $r = mysqli_query(bdb(), 'SHOW TABLES');
        while ($r && ($row = mysqli_fetch_row($r))) {
            $t[] = $row[0];
        }
    }
    return $t;
}

function bt_valid($table)
{
    return in_array($table, bt_tables(), true);
}

function bt_cols($table)
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $cache[$table] = [];
        if (bt_valid($table)) {
            $r = mysqli_query(bdb(), 'SHOW COLUMNS FROM `' . $table . '`');
            while ($r && ($row = mysqli_fetch_assoc($r))) {
                $cache[$table][$row['Field']] = $row;
            }
        }
    }
    return $cache[$table];
}

function bt_pk($table)
{
    $pk = [];
    foreach (bt_cols($table) as $name => $c) {
        if ($c['Key'] === 'PRI') {
            $pk[] = $name;
        }
    }
    return count($pk) === 1 ? $pk[0] : null;
}

function bt_secret($col, $row = null, $table = '')
{
    if (preg_match('/pass|secret|token|hash/i', (string) $col)) {
        return true;
    }
    return $table === 'site_config' && $col === 'config_value' && is_array($row)
        && preg_match('/secret|pass|token|salt|hash/i', (string) (isset($row['config_key']) ? $row['config_key'] : '')) === 1;
}

function bv($v, $max = 200)
{
    if ($v === null) {
        return 'NULL';
    }
    return tg_clip(str_replace(["\r\n", "\r", "\n"], ' ⏎ ', (string) $v), $max);
}

/* ------------------------------------------------------------------
 * 2. UI Builders, Callback Stash & Auto-Deleting Message Sender
 * ------------------------------------------------------------------ */

function bot_btn_style($text, $data)
{
    if (preg_match('~^(dd|bk|bo|bz|ub|ba|ez)\|~', $data) || preg_match('~^mt\|on$~', $data)
        || preg_match('~^ua\|\d+\|(revoke|clear)~', $data) || preg_match('~^kx\|[^|]*\|\d+\|void~', $data)) {
        return 'danger';
    }
    if (preg_match('~^(✅|➕|🎁|🟢|🔓)~u', $text) || preg_match('~^mt\|off$~', $data)) {
        return 'success';
    }
    if (preg_match('~^(🔄|🧠|🛡)~u', $text)) {
        return 'primary';
    }
    return '';
}

function btn($text, $data, $style = null)
{
    $b = ['text' => $text, 'callback_data' => $data];
    if ($style === null) {
        $style = bot_btn_style($text, $data);
    }
    static $styleOn = null;
    if ($styleOn === null) {
        $styleOn = tg_style_on();
    }
    if ($style !== '' && $styleOn) {
        $b['style'] = $style;
    }
    return $b;
}

function kb(array $rows)
{
    return ['inline_keyboard' => $rows];
}

function home_row()
{
    return [btn('🏠 Menu chính', 'home')];
}

function hr()
{
    return '<code>────────────────────</code>';
}

function bot_stash(array $payload)
{
    $id = substr(md5(json_encode($payload) . microtime(true) . mt_rand()), 0, 10);
    tg_store_update('stash', function ($s) use ($id, $payload) {
        $s[$id] = ['p' => $payload, 't' => time()];
        if (count($s) > 300) {
            uasort($s, function ($a, $b) {
                return $b['t'] - $a['t'];
            });
            $s = array_slice($s, 0, 200, true);
        }
        return $s;
    });
    return $id;
}

function bot_unstash($id)
{
    $s = tg_store_read('stash');
    return isset($s[$id]) ? $s[$id]['p'] : null;
}

function cbd()
{
    $data = implode('|', array_map('strval', func_get_args()));
    if (strlen($data) <= 60) {
        return $data;
    }
    return '~|' . bot_stash(['d' => $data]);
}

function cbd_resolve($data)
{
    if (strpos($data, '~|') === 0) {
        $p = bot_unstash(substr($data, 2));
        return is_array($p) && isset($p['d']) ? (string) $p['d'] : '';
    }
    return $data;
}

function pager_row($route, array $extra, $page, $pages)
{
    $row = [];
    if ($page > 0) {
        $row[] = btn('◀ Trước', call_user_func_array('cbd', array_merge([$route], $extra, [$page - 1])), '');
    }
    $row[] = btn('📄 ' . ($page + 1) . '/' . $pages, 'noop', '');
    if ($page < $pages - 1) {
        $row[] = btn('Tiếp ▶', call_user_func_array('cbd', array_merge([$route], $extra, [$page + 1])), '');
    }
    return $row;
}

/** Sends or edits a Bot UI panel and schedules it for auto-delete (unless preserve_msg is set for alerts). */
function ui_send($text, $markup = null)
{
    $c      = $GLOBALS['CTX'];
    $params = [
        'chat_id'                  => $c['chat'],
        'text'                     => tg_clip($text, 4000),
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($markup !== null) {
        $params['reply_markup'] = $markup;
    }
    if ($c['edit'] && $c['mid']) {
        $p               = $params;
        $p['message_id'] = $c['mid'];
        $res             = tg_api('editMessageText', $p);
        if (!empty($res['ok']) || stripos((string) (isset($res['description']) ? $res['description'] : ''), 'not modified') !== false) {
            if (empty($c['preserve_msg'])) {
                tg_autodelete_register($c['chat'], $c['mid']);
            }
            return;
        }
    }
    $res = tg_api('sendMessage', $params);
    if (empty($res['ok']) && stripos((string) (isset($res['description']) ? $res['description'] : ''), 'parse') !== false) {
        unset($params['parse_mode']);
        $params['text'] = html_entity_decode(strip_tags($params['text']), ENT_QUOTES, 'UTF-8');
        $res            = tg_api('sendMessage', $params);
    }
    if (!empty($res['ok']) && !empty($res['result']['message_id']) && empty($c['preserve_msg'])) {
        tg_autodelete_register($c['chat'], (int) $res['result']['message_id']);
    }
}

function toast($text)
{
    $GLOBALS['CTX']['toast'] = $text;
}

function ui_error($text, $backCb = 'home')
{
    ui_send('❌ <b>' . tg_e($text) . '</b>', kb([[btn('⬅ Quay lại', $backCb), btn('🏠 Menu chính', 'home')]]));
}

function conv_get($admin)
{
    $s = tg_store_read('conv');
    if (isset($s[$admin]) && (int) $s[$admin]['at'] > time() - 600) {
        return $s[$admin];
    }
    return null;
}

function conv_set($admin, $mode, array $data = [])
{
    tg_store_update('conv', function ($s) use ($admin, $mode, $data) {
        $s[$admin] = ['mode' => $mode, 'data' => $data, 'at' => time()];
        return $s;
    });
}

function conv_clear($admin)
{
    tg_store_update('conv', function ($s) use ($admin) {
        unset($s[$admin]);
        return $s;
    });
}

function bot_log($action, $detail)
{
    $admin = $GLOBALS['CTX']['admin'];
    try {
        if (bt_valid('admin_logs')) {
            bq_exec(
                'INSERT INTO admin_logs (user_id, username, action, detail, ip, created_at) VALUES (NULL, ?, ?, ?, ?, NOW())',
                'ssss',
                ['tg:' . $admin, $action, tg_clip($detail, 480), 'telegram']
            );
        }
    } catch (Throwable $e) {
        error_log('[telegram_bot.php] log: ' . $e->getMessage());
    }
    tg_event('bot_audit', ['admin' => $admin, 'action' => $action, 'detail' => $detail]);
}

function bot_site_base_url()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '')) === 'https';
    $host  = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '' ? (string) $_SERVER['HTTP_HOST'] : 'iosmods.xyz';
    return ($https ? 'https' : 'http') . '://' . $host;
}

/* ------------------------------------------------------------------
 * 3. Control Center Home Dashboard (Gorgeous Vietnamese admin_cp UI)
 * ------------------------------------------------------------------ */

function bot_ban_counts()
{
    $perm = 0;
    $temp = 0;
    try {
        if (bt_valid('ip_bans')) {
            if (function_exists('ipg_sweep_expired')) {
                ipg_sweep_expired(bdb());
            }
            $cols = bt_cols('ip_bans');
            if (isset($cols['expires_at'])) {
                $perm = (int) bq_val('SELECT COUNT(*) FROM ip_bans WHERE active = 1 AND expires_at IS NULL');
                $temp = (int) bq_val('SELECT COUNT(*) FROM ip_bans WHERE active = 1 AND expires_at > NOW()');
            } else {
                $perm = (int) bq_val('SELECT COUNT(*) FROM ip_bans WHERE active = 1');
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    return ['perm' => $perm, 'temp' => $temp, 'all' => $perm + $temp];
}

function bot_snow_is_on()
{
    $v = trim((string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'ds_snow'", '', [], '1'));
    return $v !== '0';
}

function bot_purge_logo_caches()
{
    $GLOBALS['SH_LOGO_MEMO_RESET'] = 1;
    foreach (glob(__DIR__ . '/cache/brandlogo_*') ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob(__DIR__ . '/cache/favicon_*') ?: [] as $f) {
        @unlink($f);
    }
    if (function_exists('sh_dir')) {
        foreach (glob(sh_dir() . '/logo_*') ?: [] as $f) {
            @unlink($f);
        }
    }
}

function screen_home($note = '')
{
    $users   = (int) bq_val('SELECT COUNT(*) FROM users');
    $aFree   = (int) bq_val("SELECT COUNT(*) FROM users WHERE key_type = 'FREE' AND expiry_date > NOW()");
    $aVip    = (int) bq_val("SELECT COUNT(*) FROM users WHERE key_type = 'VIP' AND expiry_date > NOW()");
    $sFree   = (int) bq_val('SELECT COUNT(*) FROM keys_free WHERE status = 0');
    $sVip    = (int) bq_val('SELECT COUNT(*) FROM keys_vip WHERE status = 0');
    $pTotal  = (int) bq_val('SELECT COUNT(*) FROM products');
    $maint   = (string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'maintenance_mode'", '', [], '0') === '1';
    $snowOn  = bot_snow_is_on();
    $bans    = bot_ban_counts();
    $shCfg   = function_exists('sh_cfg') ? sh_cfg() : ['enabled' => true];
    $adSec   = tg_autodelete_ttl();
    $vault   = tg_emoji_vault_read();
    $emCnt   = count(isset($vault['picks']) && is_array($vault['picks']) ? $vault['picks'] : []);

    $allActive = function_exists('sh_collect_active_blocks') ? sh_collect_active_blocks(bdb()) : [];
    $actTotal  = count($allActive);

    $t = '🛡 <b>' . tg_e(tg_cfg('site_name', 'IOSMODS.XYZ')) . '</b> · <b>TRUNG TÂM ĐIỀU HÀNH</b>' . "\n"
        . '<i>' . ($maint ? '🔴 ĐANG BẢO TRÌ TOÀN WEB' : '🟢 WEBSITE ĐANG MỞ')
        . ' · ' . (!empty($shCfg['enabled']) ? '🛡 KHIÊN AI 24/7' : '⚠️ KHIÊN TẮT')
        . ' · ' . ($snowOn ? '❄️ TUYẾT: BẬT' : '🌤 TUYẾT: TẮT') . '</i>' . "\n";
    if ($note !== '') {
        $t .= '<blockquote>' . $note . '</blockquote>' . "\n";
    }
    $t .= '<blockquote>'
        . '👥 Thành viên: <b>' . $users . '</b> · 🟢 Đang có Key: <b>' . ($aFree + $aVip) . '</b> <i>(Free: ' . $aFree . ' · VIP: ' . $aVip . ')</i>' . "\n"
        . '🔑 Kho Key tồn: <b>Free ' . $sFree . '</b> · <b>VIP ' . $sVip . '</b> · 📦 Sản phẩm: <b>' . $pTotal . '</b>'
        . '</blockquote>' . "\n"
        . '<blockquote>'
        . '📋 Đang bị chặn hiện tại: ' . ($actTotal > 0 ? '🔴 <b>' . $actTotal . ' đối tượng</b> <i>(Tạm khoá ' . $bans['temp'] . ' · Cấm ' . $bans['perm'] . ')</i>' : '🟢 <b>Không có ai bị chặn</b>') . "\n"
        . '🧹 Tự xoá lệnh: <b>' . ($adSec > 0 ? $adSec . ' giây' : 'Đang tắt') . '</b>'
        . ($emCnt > 0 ? ' · 🎨 Biểu tượng: <b>Đã bật</b>' : '')
        . '</blockquote>' . "\n"
        . '<i>💡 Gửi trực tiếp <code>tên_tài_khoản</code>, <code>IP</code> hoặc mã <code>IMX-...</code> vào chat để kiểm tra ngay · ' . tg_e(tg_now('H:i:s d/m')) . '</i>';

    ui_send($t, kb([
        [btn('📋 Danh sách đang bị chặn (' . $actTotal . ')', 'abl|0'), btn('🔍 Kiểm tra Tài khoản / IP', 'chk|ask')],
        [btn($snowOn ? '❄️ Tắt tuyết rơi trang chính' : '❄️ Bật tuyết rơi trang chính', 'ui|snow_home', $snowOn ? '' : 'success'), btn('🖼 Đổi Logo & Giao diện', 'ui|menu')],
        [btn($maint ? '🟢 Tắt bảo trì ngay' : '🛠 Chế độ bảo trì', 'mt|menu'), btn('🔓 Mở khoá nhận Key', 'uk|0')],
        [btn('🔑 Kho Key & Tạo Key', 'ky'), btn('🛡 Chống DDoS & Khoá IP', 'bn|0')],
        [btn('🧠 AI Giám sát 24/7', 'aihub'), btn('👥 Thành viên (' . $users . ')', cbd('us', '', 0))],
        [btn('📦 Sản phẩm (' . $pTotal . ')', 'pr|0'), btn('⚙️ Cài đặt Web (Tiếng Việt)', 'cf|menu')],
        [btn('🎨 Kho Biểu tượng (60)', 'em|0'), btn('🔔 Thông báo & Tự xoá', 'al'), btn('🔄 Làm mới', 'home')],
    ]));
}

/* ------------------------------------------------------------------
 * 4. Bug-Free Maintenance Manager (100% Parity with admin_cp.php!)
 * ------------------------------------------------------------------ */

function bot_mtx_gen_password()
{
    $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $max   = strlen($alpha) - 1;
    $raw   = '';
    for ($i = 0; $i < 24; $i++) {
        $raw .= $alpha[random_int(0, $max)];
    }
    return implode('-', str_split($raw, 4));
}

function bot_set_config($key, $value)
{
    bq_exec(
        'INSERT INTO site_config (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)',
        'ss',
        [(string) $key, (string) $value]
    );
}

function bot_mtx_enable()
{
    $adminRow  = bq_row('SELECT username FROM users WHERE role = 1 ORDER BY id ASC LIMIT 1');
    $adminUser = $adminRow && !empty($adminRow['username']) ? (string) $adminRow['username'] : 'admin';
    $token     = bin2hex(random_bytes(48));
    $pw        = bot_mtx_gen_password();
    $hash      = password_hash(str_replace('-', '', $pw), PASSWORD_DEFAULT);

    bot_set_config('maintenance_access_token', $token);
    bot_set_config('maintenance_access_hash', $hash);
    bot_set_config('maintenance_access_user', $adminUser);
    bot_set_config('maintenance_mode', '1');

    // Keep last generated link/pw encrypted-safe in local bot store so Admin can view it inside Maintenance panel
    tg_store_update('maint_secret', function () use ($token, $pw, $adminUser) {
        return ['token' => $token, 'pw' => $pw, 'user' => $adminUser, 'at' => tg_now()];
    });

    return ['token' => $token, 'pw' => $pw, 'user' => $adminUser];
}

function bot_mtx_disable()
{
    bot_set_config('maintenance_mode', '0');
    try {
        bq_exec("DELETE FROM site_config WHERE config_key IN ('maintenance_access_token', 'maintenance_access_hash', 'maintenance_access_user')");
    } catch (Throwable $e) {
        // safe even if rows did not exist
    }
    tg_store_update('maint_secret', function () {
        return [];
    });
}

function bot_tool_meta_load()
{
    $raw = (string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'ds_tool_meta'", '', [], '');
    $dec = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($dec) ? $dec : [];
}

function bot_tool_meta_save(array $meta)
{
    foreach ($meta as $k => $v) {
        if (empty($v)) {
            unset($meta[$k]);
        }
    }
    bot_set_config('ds_tool_meta', json_encode($meta, JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function bot_maintenance($step)
{
    $on = (string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'maintenance_mode'", '', [], '0') === '1';

    if ($step === 'on') {
        $sec = bot_mtx_enable();
        bot_log('toggle_maintenance', 'Bật bảo trì toàn web + tạo cổng Admin bí mật [Telegram]');
        toast('🛠 Đã BẬT bảo trì toàn web');
        $on = true;
    } elseif ($step === 'off') {
        bot_mtx_disable();
        bot_log('toggle_maintenance', 'Tắt bảo trì toàn web [Telegram]');
        toast('🟢 Đã TẮT bảo trì — Web hoạt động bình thường');
        $on = false;
    } elseif ($step === 'regen' && $on) {
        $sec = bot_mtx_enable();
        bot_log('regen_maintenance_access', 'Tạo lại link & mật khẩu bảo trì [Telegram]');
        toast('🔄 Đã tạo link & mật khẩu mới');
    } elseif ($step === 'notice') {
        conv_set($GLOBALS['CTX']['admin'], 'maint_notice');
        return ui_send('📝 <b>ĐỔI THÔNG BÁO BẢO TRÌ</b>' . "\n" . hr() . "\nGửi nội dung thông báo hiển thị cho khách khi web đang bảo trì:", kb([[btn('✖ Huỷ', 'mt|menu')]]));
    } elseif ($step === 'title') {
        conv_set($GLOBALS['CTX']['admin'], 'maint_title');
        return ui_send('📝 <b>ĐỔI TIÊU ĐỀ BẢO TRÌ</b>' . "\n" . hr() . "\nGửi tiêu đề trang bảo trì mới:", kb([[btn('✖ Huỷ', 'mt|menu')]]));
    }

    $title  = (string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'maintenance_title'", '', [], 'Hệ thống đang bảo trì');
    $notice = (string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'maintenance_notice'", '', [], 'Đang nâng cấp hệ thống, vui lòng quay lại sau.');
    $meta   = bot_tool_meta_load();
    $mTools = 0;
    foreach ($meta as $mv) {
        if (is_array($mv) && !empty($mv['maint'])) {
            $mTools++;
        }
    }

    $t  = '🛠 <b>QUẢN LÝ BẢO TRÌ WEBSITE</b>' . "\n" . hr() . "\n";
    $t .= 'Trạng thái toàn web: ' . ($on ? '🔴 <b>ĐANG BẬT BẢO TRÌ</b>' : '🟢 <b>ĐANG MỞ BÌNH THƯỜNG</b>') . "\n";
    $t .= '<blockquote>'
        . '📌 Tiêu đề: <b>' . tg_e(tg_clip($title, 60)) . "</b>\n"
        . '💬 Nội dung: ' . tg_e(tg_clip($notice, 140)) . "\n"
        . '🧰 Sản phẩm bảo trì riêng: <b>' . $mTools . '</b> tool'
        . '</blockquote>';

    if ($on) {
        $sec = tg_store_read('maint_secret');
        $tok = !empty($sec['token']) ? (string) $sec['token'] : (string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'maintenance_access_token'", '', [], '');
        if ($tok !== '') {
            $url = bot_site_base_url() . '/maintenance_access.php/' . $tok;
            $t  .= "\n🔐 <b>Cổng vào Admin bí mật khi bảo trì:</b>\n"
                . '🔗 <code>' . tg_e($url) . "</code>\n";
            if (!empty($sec['pw'])) {
                $t .= '🔑 Mật khẩu: <code>' . tg_e($sec['pw']) . "</code>\n";
            } else {
                $t .= "<i>(Bấm «Tạo lại mật khẩu cổng» nếu cần lấy mật khẩu mới)</i>\n";
            }
        }
    }

    $kbd = [];
    if ($on) {
        $kbd[] = [btn('🟢 TẮT BẢO TRÌ NGAY', 'mt|off', 'success')];
        $kbd[] = [btn('🔄 Tạo lại link & mật khẩu cổng', 'mt|regen')];
    } else {
        $kbd[] = [btn('🛠 BẬT BẢO TRÌ TOÀN WEB', 'mt|on', 'danger')];
    }
    $kbd[] = [btn('✏️ Sửa tiêu đề', 'mt|title'), btn('✏️ Sửa nội dung', 'mt|notice')];
    $kbd[] = [btn('🧰 Bảo trì từng sản phẩm (' . $mTools . ')', 'pr|0')];
    $kbd[] = home_row();
    ui_send($t, kb($kbd));
}

/* ------------------------------------------------------------------
 * 5. Peak Smart AI Monitor & Anti-DDoS / Sad-Face / VPN Control Hub
 * ------------------------------------------------------------------ */

function screen_ai_hub($note = '')
{
    $bans   = bot_ban_counts();
    $shCfg  = function_exists('sh_cfg') ? sh_cfg() : ['enabled' => true, 'waf_enabled' => true, 'ddos_ban_sec' => 3600, 'key_spam_ban_sec' => 1800];
    $sFree  = (int) bq_val('SELECT COUNT(*) FROM keys_free WHERE status = 0');
    $sVip   = (int) bq_val('SELECT COUNT(*) FROM keys_vip WHERE status = 0');
    $wlList = function_exists('sh_whitelist_list') ? sh_whitelist_list() : [];

    // Count VPN & Multi-account detections in last 24h
    $vpn24  = 0;
    $altCnt = 0;
    $altClusters = [];
    try {
        if (bt_valid('free_key_ip_log')) {
            $vpn24 = (int) bq_val("SELECT COUNT(*) FROM free_key_ip_log WHERE event LIKE 'blocked%' AND created_at >= ?", 'i', [time() - 86400]);
        }
        if (bt_valid('free_key_seen')) {
            $clusters = bq_all("SELECT val, kind, COUNT(DISTINCT username) AS c, GROUP_CONCAT(DISTINCT username SEPARATOR ', ') AS ulist
                                FROM free_key_seen WHERE last_seen >= ? GROUP BY kind, val HAVING c > 1 ORDER BY c DESC LIMIT 4", 'i', [time() - 7 * 86400]);
            $altCnt = count($clusters);
            $altClusters = $clusters;
        }
    } catch (Throwable $e) {
        // ignore
    }

    // Active temporary Sad-Face bans
    $tempRows = [];
    try {
        $cols = bt_cols('ip_bans');
        if (isset($cols['expires_at'])) {
            $tempRows = bq_all('SELECT id, ip, ban_type, username, expires_at, reason FROM ip_bans WHERE active = 1 AND expires_at > NOW() ORDER BY id DESC LIMIT 5');
        }
    } catch (Throwable $e) {
        $tempRows = [];
    }

    $score = 100;
    $warns = [];
    if (empty($shCfg['enabled'])) {
        $score -= 25;
        $warns[] = '⚠️ Khiên chống DDoS đang tắt';
    }
    if ($sFree <= 3) {
        $score -= 10;
        $warns[] = '⚠️ Kho Key FREE sắp hết (còn ' . $sFree . ')';
    }
    if ($sVip <= 2) {
        $score -= 5;
        $warns[] = '⚠️ Kho Key VIP sắp hết (còn ' . $sVip . ')';
    }

    $stats    = function_exists('sh_stats_read') ? sh_stats_read() : [];
    $sentinel = function_exists('sh_sentinel_status') ? sh_sentinel_status() : ['defcon' => 'SENTINEL MAX · Giám sát 24/7', 'ticks' => 1, 'cleaned' => 0];
    $surgeOn  = function_exists('sh_is_surge_active') ? sh_is_surge_active() : false;
    $drained  = (int) (isset($stats['drained_streams']) ? $stats['drained_streams'] : 0);
    $scBlk    = (int) (isset($stats['script_blocks']) ? $stats['script_blocks'] : 0) + (int) (isset($stats['bug_blocks']) ? $stats['bug_blocks'] : 0);
    $happyN   = (int) (isset($stats['happy_gates']) ? $stats['happy_gates'] : 0);

    $t = '🧠 <b>AI GIÁM SÁT TỐI CAO · VĨNH VIỄN 24/7 KHÔNG NGỪNG</b>' . "\n";
    if ($note !== '') {
        $t .= $note . "\n";
    }
    $t .= '<blockquote>'
        . '🛰 Chế độ trực chiến: <b>' . tg_e($sentinel['defcon']) . '</b> (Nhịp #' . (int) $sentinel['ticks'] . ')' . "\n"
        . '🩺 Độ ổn định Web: <b>' . $score . '/100</b> · Tự vá DDoS: ' . ($surgeOn ? '🛡 <b>ĐANG XẢ LUỒNG MẠNH</b>' : '🟢 <b>Sạch 100%</b>') . "\n"
        . '🛡 Khiên DDoS 12 Tầng &amp; WAF: ' . (!empty($shCfg['enabled']) ? '🟢 <b>BẬT</b>' : '🔴 <b>TẮT</b>') . ' · Đã xả: <b>' . $drained . '</b> luồng' . "\n"
        . '💻 Chống Script &amp; Xem nguồn PC: ' . (!empty($shCfg['inspect_guard']) ? '🟢 <b>BẬT</b>' : '🔴 <b>TẮT</b>') . ' · Đã chặn: <b>' . $scBlk . '</b>' . "\n"
        . '🕵️ Chặn VPN / Proxy (khoá 2 phút): ' . (!empty($shCfg['vpn_gate_enabled']) ? '🟢 <b>BẬT</b>' : '🔴 <b>TẮT</b>') . ' (' . $happyN . ' lượt)' . "\n"
        . '🔒 Đang tạm khoá: <b>' . $bans['temp'] . '</b> · 🚫 Khoá bảo vệ (chờ Bot/Admin mở): <b>' . $bans['perm'] . '</b>' . "\n"
        . '🚫 Chặn VPN/Trùng Acc lấy Key (24h): <b>' . $vpn24 . '</b> lượt'
        . '</blockquote>';

    if ($warns) {
        $t .= "\n" . implode("\n", $warns) . "\n";
    }

    if ($tempRows) {
        $t .= "\n☹️ <b>IP đang ở giao diện Mặt buồn (tự động gỡ):</b>\n<blockquote>";
        foreach ($tempRows as $tr) {
            $leftMin = max(1, (int) ceil((strtotime((string) $tr['expires_at']) - time()) / 60));
            $t .= '• <code>' . tg_e($tr['ip']) . '</code> (' . tg_e($tr['ban_type']) . ') · còn <b>' . $leftMin . 'p</b>'
                . (!empty($tr['username']) ? ' · <code>' . tg_e($tr['username']) . '</code>' : '') . "\n";
        }
        $t = rtrim($t) . '</blockquote>';
    }

    if ($altClusters) {
        $t .= "\n🕵️ <b>Cụm tài khoản dùng chung Thiết bị / Mạng:</b>\n<blockquote>";
        foreach ($altClusters as $cl) {
            $lbl = $cl['kind'] === 'dev' ? '📱 Thiết bị' : '🌐 Mạng';
            $t  .= $lbl . ' (' . (int) $cl['c'] . ' acc): <code>' . tg_e(tg_clip($cl['ulist'], 65)) . "</code>\n";
        }
        $t = rtrim($t) . '</blockquote>';
    }

    $ddosMin = (int) round((isset($shCfg['ddos_ban_sec']) ? (int) $shCfg['ddos_ban_sec'] : 3600) / 60);
    $spamMin = (int) round((isset($shCfg['key_spam_ban_sec']) ? (int) $shCfg['key_spam_ban_sec'] : 1800) / 60);

    $kbd = [
        [
            btn(!empty($shCfg['enabled']) ? '🟢 Khiên DDoS: BẬT' : '🔴 Khiên DDoS: TẮT', 'sh|toggle', !empty($shCfg['enabled']) ? 'success' : 'danger'),
            btn(!empty($shCfg['inspect_guard']) ? '💻 Chống Script/F12: BẬT' : '🔴 Chống Script: TẮT', 'sh|inspect', !empty($shCfg['inspect_guard']) ? 'success' : 'danger'),
        ],
        [
            btn(!empty($shCfg['vpn_gate_enabled']) ? '😊 Mặt cười VPN/Script: BẬT' : '⚪ Mặt cười VPN: TẮT', 'sh|vpngate'),
            btn('🧹 Quét gỡ IP hết hạn', 'sh|sweep'),
        ],
        [
            btn('⏱ Khoá DDoS: ' . $ddosMin . 'p', 'sh|ddostime'),
            btn('☹️ Khoá Spam Key: ' . $spamMin . 'p', 'sh|spamtime'),
        ],
        [
            btn('🛡 DS An toàn (Whitelist)', 'sh|wl'),
            btn('🚫 Danh sách IP bị chặn', 'bn|0'),
        ],
        [
            btn('🔓 Mở khoá Get Key Free', 'uk|0'),
            btn('🔄 Làm mới AI', 'aihub'),
        ],
        home_row(),
    ];
    ui_send($t, kb($kbd));
}

function bot_shield_action($act, $arg = '')
{
    if (!function_exists('sh_cfg')) {
        return ui_error('Chưa nạp security_shield.php', 'home');
    }
    $cfg = sh_cfg();
    if ($act === 'toggle') {
        $next = empty($cfg['enabled']);
        sh_cfg_save(['enabled' => $next]);
        bot_log('tg_shield', ($next ? 'Bật' : 'Tắt') . ' khiên chống DDoS');
        toast($next ? '🛡 Đã bật Khiên DDoS' : '⏸ Đã tạm tắt Khiên DDoS');
        return screen_ai_hub();
    }
    if ($act === 'inspect') {
        $next = empty($cfg['inspect_guard']);
        sh_cfg_save(['inspect_guard' => $next]);
        bot_log('tg_shield', ($next ? 'Bật' : 'Tắt') . ' chống Script & Xem mã nguồn');
        toast($next ? '💻 Đã bật Chống Script & Xem mã nguồn' : '⏸ Đã tắt Chống Script');
        return screen_ai_hub();
    }
    if ($act === 'vpngate') {
        $next = empty($cfg['vpn_gate_enabled']);
        sh_cfg_save(['vpn_gate_enabled' => $next]);
        bot_log('tg_shield', ($next ? 'Bật' : 'Tắt') . ' cổng Mặt cười nhắc tắt VPN/Script');
        toast($next ? '😊 Đã bật Cổng Mặt cười VPN/Script' : '⏸ Đã tắt Cổng Mặt cười VPN');
        return screen_ai_hub();
    }
    if ($act === 'sweep') {
        $n = function_exists('ipg_sweep_expired') ? ipg_sweep_expired(bdb()) : 0;
        toast('✅ Đã dọn ' . $n . ' IP hết hạn');
        return screen_ai_hub('✅ Đã kiểm tra & gỡ tự động <b>' . $n . '</b> IP hết thời gian khoá.');
    }
    if ($act === 'ddostime') {
        $steps = [1800, 3600, 7200, 21600];
        $cur   = (int) $cfg['ddos_ban_sec'];
        $idx   = array_search($cur, $steps, true);
        $next  = $steps[$idx === false ? 1 : (($idx + 1) % count($steps))];
        sh_cfg_save(['ddos_ban_sec' => $next]);
        toast('⏱ Khoá DDoS: ' . ($next / 60) . ' phút');
        return screen_ai_hub();
    }
    if ($act === 'spamtime') {
        $steps = [600, 1800, 3600, 7200];
        $cur   = (int) $cfg['key_spam_ban_sec'];
        $idx   = array_search($cur, $steps, true);
        $next  = $steps[$idx === false ? 1 : (($idx + 1) % count($steps))];
        sh_cfg_save(['key_spam_ban_sec' => $next]);
        toast('☹️ Khoá Spam Key: ' . ($next / 60) . ' phút');
        return screen_ai_hub();
    }
    if ($act === 'wl') {
        $wl  = sh_whitelist_list();
        $my  = tg_ip();
        $t   = '🛡 <b>DANH SÁCH IP AN TOÀN (WHITELIST)</b>' . "\n" . hr() . "\n"
            . "Các IP trong danh sách này + IP Admin + Cloudflare + OneShield + Telegram <b>tuyệt đối không bao giờ bị chặn</b>.\n\n";
        $kbd = [[btn('➕ Thêm IP an toàn', 'sh|wladd')]];
        if ($wl) {
            foreach ($wl as $cidr => $meta) {
                $note = is_array($meta) && isset($meta['note']) ? $meta['note'] : (string) $meta;
                $t   .= '• <code>' . tg_e($cidr) . '</code> <i>(' . tg_e($note) . ")</i>\n";
                if ($note !== 'fk_config' && strlen((string) $cidr) <= 42) {
                    $kbd[] = [btn('🗑 Xoá ' . $cidr, 'sh|wldel|' . $cidr, 'danger')];
                }
            }
        } else {
            $t .= "<i>Chưa có IP thủ công nào (IP Admin đăng nhập tự động được bảo vệ 100%).</i>\n";
        }
        $kbd[] = [btn('⬅ Quay lại AI', 'aihub'), btn('🏠 Menu chính', 'home')];
        return ui_send($t, kb($kbd));
    }
    if ($act === 'wladd') {
        conv_set($GLOBALS['CTX']['admin'], 'wl_add');
        return ui_send('🛡 <b>THÊM IP AN TOÀN</b>' . "\n" . hr() . "\nGửi địa chỉ IP (hoặc dải CIDR, ví dụ <code>203.0.113.5</code>) để đưa vào danh sách miễn trừ tuyệt đối:", kb([[btn('✖ Huỷ', 'sh|wl')]]));
    }
    if ($act === 'wldel' && $arg !== '') {
        sh_whitelist_remove($arg);
        toast('🗑 Đã xoá khỏi Whitelist');
        return bot_shield_action('wl');
    }
    return screen_ai_hub();
}

/* ------------------------------------------------------------------
 * 6. 1-Tap Free-Key & Daily-Limit Unlocker (Matches admin_cp fkx_lib!)
 * ------------------------------------------------------------------ */

function bot_unlock_all_locks($userOrIp)
{
    $target = trim((string) $userOrIp);
    if ($target === '') {
        return [false, 'Vui lòng nhập tên tài khoản hoặc địa chỉ IP.'];
    }
    $notes = [];
    $since = time() - 28800;

    if (filter_var($target, FILTER_VALIDATE_IP)) {
        $ipRaw = ipg_norm($target);
        $ipKey = function_exists('sh_ip_network_key') ? sh_ip_network_key($ipRaw) : $ipRaw;

        if (bt_valid('free_key_claims')) {
            $r = bq_exec("UPDATE free_key_claims SET created_at = ?, day = CONCAT('r', LOWER(HEX(id))) WHERE created_at > ? AND (ip = ? OR ip = ?)", 'iiss', [$since - 1, $since, $ipKey, $ipRaw]);
            if ($r['n'] > 0) {
                $notes[] = 'get_key (' . $r['n'] . ' lượt)';
            }
        }
        if (bt_valid('free_key_ip_log')) {
            $r = bq_exec("UPDATE free_key_ip_log SET event = CONCAT('cleared', SUBSTRING(event, 8)) WHERE (ip = ? OR ip_raw = ?) AND event LIKE 'blocked%'", 'ss', [$ipKey, $ipRaw]);
            if ($r['n'] > 0) {
                $notes[] = 'xoá ' . $r['n'] . ' strike VPN/IP';
            }
        }
        if (bt_valid('free_key_ip_cache')) {
            bq_exec('REPLACE INTO free_key_ip_cache (ip, is_vpn, checked_at) VALUES (?, 0, ?)', 'si', [$ipRaw, time() + 30 * 86400]);
            $notes[] = 'cho phép qua bộ lọc VPN 30 ngày';
        }
        if (bt_valid('key_daily_claims')) {
            $r = bq_exec('DELETE FROM key_daily_claims WHERE ip = ?', 's', [$ipRaw]);
            if ($r['n'] > 0) {
                $notes[] = 'mở giới hạn nhập key ngày (' . $r['n'] . ')';
            }
        }
        if (bot_ipg_ready()) {
            $ub = ipg_unban(bdb(), $ipRaw, $GLOBALS['CTX']['admin'] ?: 'bot');
            if (!empty($ub['ok'])) {
                $notes[] = 'gỡ khoá IP';
            }
        }
        return [true, 'Đã mở khoá toàn diện cho IP ' . $ipRaw . ($notes ? ': ' . implode(', ', $notes) : '')];
    }

    // Unlock by Username
    $u = bq_row('SELECT id, username FROM users WHERE username = ? LIMIT 1', 's', [$target]);
    $uname = $u ? (string) $u['username'] : $target;

    // 1. Grant 1-time Free-Key Pass (24h, bypasses VPN / IP / device cooldown once!)
    try {
        mysqli_query(bdb(), 'CREATE TABLE IF NOT EXISTS free_key_pass (username VARCHAR(100) NOT NULL PRIMARY KEY, created_at INT UNSIGNED NOT NULL, expires_at INT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $now = time();
        bq_exec('REPLACE INTO free_key_pass (username, created_at, expires_at) VALUES (?, ?, ?)', 'sii', [$uname, $now, $now + 86400]);
        $notes[] = 'cấp quyền nhận Key Free 1 lần (bỏ qua khoá IP/Thiết bị/VPN trong 24h)';
    } catch (Throwable $e) {
        // ignore
    }

    if (bt_valid('free_key_claims')) {
        $r = bq_exec("UPDATE free_key_claims SET created_at = ?, day = CONCAT('r', LOWER(HEX(id))) WHERE created_at > ? AND username = ?", 'iis', [$since - 1, $since, $uname]);
        if ($r['n'] > 0) {
            $notes[] = 'mở chu kỳ get_key (' . $r['n'] . ')';
        }
    }
    if (bt_valid('free_key_ip_log')) {
        bq_exec("UPDATE free_key_ip_log SET event = CONCAT('cleared', SUBSTRING(event, 8)) WHERE username = ? AND event LIKE 'blocked%'", 's', [$uname]);
    }
    if (bt_valid('key_daily_claims')) {
        $r = bq_exec('DELETE FROM key_daily_claims WHERE username = ?', 's', [$uname]);
        if ($r['n'] > 0) {
            $notes[] = 'mở giới hạn nhập key 8h';
        }
    }
    if (bt_valid('key_attempts')) {
        bq_exec('DELETE FROM key_attempts WHERE username = ?', 's', [$uname]);
    }
    // Also clean ds_daily_data.php entry if present
    $dlFile = __DIR__ . '/ds_daily_data.php';
    if (is_file($dlFile)) {
        $raw = @file_get_contents($dlFile);
        if (is_string($raw) && ($nl = strpos($raw, "\n")) !== false) {
            $dec = json_decode(substr($raw, $nl + 1), true);
            if (is_array($dec)) {
                $ukey = 'u:' . strtolower($uname);
                foreach ($dec as $day => $map) {
                    if (is_array($map) && isset($map[$ukey])) {
                        unset($dec[$day][$ukey]);
                    }
                }
                @file_put_contents($dlFile, "<?php http_response_code(404); exit; ?>\n" . json_encode($dec), LOCK_EX);
            }
        }
    }

    return [true, 'Đã mở khoá toàn bộ cho «' . $uname . '»: ' . implode(', ', $notes)];
}

function screen_unlock($page = 0, $note = '')
{
    $recent = [];
    try {
        if (bt_valid('free_key_ip_log')) {
            $recent = bq_all("SELECT username, ip_raw, event, created_at FROM free_key_ip_log WHERE event LIKE 'blocked%' ORDER BY id DESC LIMIT 6");
        }
    } catch (Throwable $e) {
        $recent = [];
    }

    $t = '🔓 <b>MỞ KHOÁ GET KEY FREE & GIỚI HẠN NHẬP KEY</b>' . "\n" . hr() . "\n";
    if ($note !== '') {
        $t .= $note . "\n" . hr() . "\n";
    }
    $t .= "Mở khoá ngay cho thành viên bị kẹt chu kỳ 8h, trùng Wi-Fi/4G, đổi thiết bị hoặc bật nhầm VPN.\n";

    $kbd = [
        [btn('👤 Mở khoá theo Tên User', 'uk|ask_user'), btn('🌐 Mở khoá theo Địa chỉ IP', 'uk|ask_ip')],
    ];
    if ($recent) {
        $t .= "\n🕵️ <b>Các lượt bị chặn gần nhất (bấm để mở ngay):</b>\n";
        foreach ($recent as $r) {
            $u = (string) $r['username'];
            if ($u !== '' && strlen($u) <= 36) {
                $kbd[] = [btn('🔓 Mở cho ' . $u . ' (' . $r['event'] . ')', cbd('uk', 'run', $u))];
            }
        }
    }
    $kbd[] = [btn('🧹 Mở khoá Get Key cho TẤT CẢ hôm nay', 'uk|all', 'danger')];
    $kbd[] = home_row();
    ui_send($t, kb($kbd));
}

function bot_unlock_route($sub, $arg = '')
{
    $admin = $GLOBALS['CTX']['admin'];
    if ($sub === 'ask_user') {
        conv_set($admin, 'unlock_target');
        return ui_send('👤 <b>MỞ KHOÁ CHO THÀNH VIÊN</b>' . "\n" . hr() . "\nGửi <b>tên tài khoản (username)</b> cần mở khoá Get Key & Nhập Key:", kb([[btn('✖ Huỷ', 'uk|0')]]));
    }
    if ($sub === 'ask_ip') {
        conv_set($admin, 'unlock_target');
        return ui_send('🌐 <b>MỞ KHOÁ CHO ĐỊA CHỈ IP</b>' . "\n" . hr() . "\nGửi <b>địa chỉ IP</b> cần mở khoá & cho phép qua VPN:", kb([[btn('✖ Huỷ', 'uk|0')]]));
    }
    if ($sub === 'all') {
        $since = time() - 28800;
        $n     = 0;
        if (bt_valid('free_key_claims')) {
            $r = bq_exec("UPDATE free_key_claims SET created_at = ?, day = CONCAT('r', LOWER(HEX(id))) WHERE created_at > ?", 'ii', [$since - 1, $since]);
            $n += $r['n'];
        }
        if (bt_valid('free_key_ip_log')) {
            bq_exec("UPDATE free_key_ip_log SET event = CONCAT('cleared', SUBSTRING(event, 8)) WHERE event LIKE 'blocked%' AND created_at >= ?", 'i', [$since]);
        }
        bot_log('fk_unlock_all', 'Mở khoá Get Key cho tất cả (' . $n . ' lượt) [Telegram]');
        toast('🔓 Đã mở khoá tất cả');
        return screen_unlock(0, '✅ Đã mở khoá chu kỳ Get Key cho toàn bộ <b>' . $n . '</b> lượt.');
    }
    if ($sub === 'run' && $arg !== '') {
        list($ok, $msg) = bot_unlock_all_locks($arg);
        if ($ok) {
            bot_log('fk_unlock', $msg);
            toast('🔓 Đã mở khoá');
        }
        return screen_unlock(0, ($ok ? '✅ ' : '❌ ') . tg_e($msg));
    }
    return screen_unlock(0);
}

/* ------------------------------------------------------------------
 * 7. Quick Alert Action Handler (qa|... on Website Alerts — NEVER deletes Alert!)
 * ------------------------------------------------------------------ */

function bot_quick_alert_action($act, $target, array $origMessage)
{
    $GLOBALS['CTX']['preserve_msg'] = true; // NEVER auto-delete website alert messages!
    $admin = $GLOBALS['CTX']['admin'];

    $doneNote = '';
    if ($act === 'unban') {
        $ip = ipg_norm($target);
        if ($ip !== '' && bot_ipg_ready()) {
            ipg_unban(bdb(), $ip, $admin);
            bot_log('tg_ipunban', $ip . ' [Nút nhanh]');
            $doneNote = '✅ Đã gỡ khoá IP ' . $ip;
            toast($doneNote);
        }
    } elseif ($act === 'permban') {
        $ip = ipg_norm($target);
        if ($ip !== '' && bot_ipg_ready()) {
            $res = ipg_ban(bdb(), $ip, 'Chặn vĩnh viễn do tấn công / spam', $admin, '');
            if (!empty($res['ok'])) {
                bot_log('tg_ipban', $ip . ' [Nút nhanh]');
                $doneNote = '🚫 Đã chặn vĩnh viễn IP ' . $ip;
            } else {
                $doneNote = '⚠️ ' . $res['msg'];
            }
            toast($doneNote);
        }
    } elseif ($act === 'tempban') {
        $ip = ipg_norm($target);
        if ($ip !== '' && function_exists('ipg_temp_ban')) {
            $res = ipg_temp_ban(bdb(), $ip, 1800, 'Tạm khoá 30 phút để kiểm soát lạm dụng tài khoản / VPN.', $admin, '', 'vpn_abuse');
            $doneNote = !empty($res['ok']) ? '☹️ Đã tạm khoá 30p IP ' . $ip : ('⚠️ ' . $res['msg']);
            toast($doneNote);
        }
    } elseif ($act === 'unban_fk') {
        $parts = explode(':', (string) $target, 2);
        $ip    = ipg_norm($parts[0]);
        $usr   = isset($parts[1]) ? trim($parts[1]) : '';
        if ($ip !== '' && bot_ipg_ready()) {
            ipg_unban(bdb(), $ip, $admin);
        }
        if ($usr !== '') {
            bot_unlock_all_locks($usr);
        } elseif ($ip !== '') {
            bot_unlock_all_locks($ip);
        }
        bot_log('tg_appeal_unban', $ip . ($usr !== '' ? ' / ' . $usr : '') . ' [Nút yêu cầu]');
        $doneNote = '✅ Đã gỡ khoá & cấp quyền cho ' . ($usr !== '' ? $usr : $ip);
        toast(tg_clip($doneNote, 180));
    } elseif ($act === 'fkpass') {
        list($ok, $msg) = bot_unlock_all_locks($target);
        if ($ok) {
            bot_log('fk_unlock', $target . ' [Nút nhanh]');
        }
        $doneNote = $ok ? ('🔓 Đã mở khoá 1 lần cho ' . $target) : ('❌ ' . $msg);
        toast(tg_clip($doneNote, 180));
    } elseif ($act === 'ip') {
        // Open IP inspection in a separate auto-deleting panel so the alert itself stays untouched!
        $GLOBALS['CTX']['preserve_msg'] = false;
        $GLOBALS['CTX']['edit']         = false;
        $GLOBALS['CTX']['mid']          = 0;
        return screen_ip($target);
    } elseif ($act === 'case') {
        // Open full Case Verification Dossier in a separate panel while keeping the permanent alert intact!
        $GLOBALS['CTX']['preserve_msg'] = false;
        $GLOBALS['CTX']['edit']         = false;
        $GLOBALS['CTX']['mid']          = 0;
        return screen_case_lookup($target);
    }

    // Update the inline button label on the alert in-place (preserving the alert forever!)
    if ($doneNote !== '' && !empty($origMessage['message_id'])) {
        $newKb = ($act === 'permban' || $act === 'tempban')
            ? tg_alert_kb([[['text' => '🔓 Gỡ ban lại', 'callback_data' => 'qa|unban|' . ipg_norm($target)]]])
            : tg_alert_kb([[['text' => $doneNote, 'callback_data' => 'noop']]]);
        tg_api('editMessageReplyMarkup', [
            'chat_id'      => $GLOBALS['CTX']['chat'],
            'message_id'   => (int) $origMessage['message_id'],
            'reply_markup' => $newKb,
        ]);
    }
}

/* ------------------------------------------------------------------
 * 8. Users, Keys, Products (with Per-Tool Maintenance!), Config, DB & SQL
 * ------------------------------------------------------------------ */

function screen_users($page = 0, $q = '')
{
    $per   = 8;
    $where = '';
    $types = '';
    $par   = [];
    if ($q !== '') {
        $where = 'WHERE username LIKE ?';
        $types = 's';
        $par   = ['%' . $q . '%'];
    }
    $total = (int) bq_val('SELECT COUNT(*) FROM users ' . $where, $types, $par);
    $pages = max(1, (int) ceil($total / $per));
    $page  = max(0, min((int) $page, $pages - 1));
    $rows  = bq_all(
        'SELECT id, username, role, key_type, (expiry_date > NOW()) AS active FROM users ' . $where
        . ' ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . ($page * $per),
        $types,
        $par
    );

    $kbd = [[btn('🔍 Tìm kiếm thành viên', 'su')]];
    foreach ($rows as $r) {
        $icon  = (int) $r['role'] === 1 ? '👑' : ((int) $r['active'] === 1 ? ($r['key_type'] === 'VIP' ? '💎' : '🟢') : '👤');
        $kbd[] = [btn($icon . ' #' . $r['id'] . ' · ' . tg_clip($r['username'], 28), cbd('dr', 'users', $r['id']))];
    }
    if ($pages > 1) {
        $kbd[] = pager_row('us', [rawurlencode($q)], $page, $pages);
    }
    $kbd[] = home_row();

    $t = '👥 <b>QUẢN LÝ THÀNH VIÊN</b> · <b>' . $total . '</b> tài khoản' . ($q !== '' ? ' · lọc: <code>' . tg_e($q) . '</code>' : '') . "\n<i>👑 Admin · 💎 VIP · 🟢 FREE · 👤 Chưa có key</i>";
    ui_send($t, kb($kbd));
}

function screen_keys()
{
    $sFree = (int) bq_val('SELECT COUNT(*) FROM keys_free WHERE status = 0');
    $uFree = (int) bq_val('SELECT COUNT(*) FROM keys_free WHERE status = 1');
    $sVip  = (int) bq_val('SELECT COUNT(*) FROM keys_vip WHERE status = 0');
    $uVip  = (int) bq_val('SELECT COUNT(*) FROM keys_vip WHERE status = 1');
    $t = '🔑 <b>KHO KEY & TẠO KEY NHANH</b>' . "\n" . hr() . "\n<blockquote>"
        . '🟢 <b>KEY FREE:</b> Còn <b>' . $sFree . '</b> · Đã dùng ' . $uFree . "\n"
        . '💎 <b>KEY VIP:</b> Còn <b>' . $sVip . '</b> · Đã dùng ' . $uVip . "</blockquote>\n"
        . "Bấm nút bên dưới để tạo ngay hoặc gõ lệnh:\n<code>/genkey vip 5 720</code> (loại · số lượng · số giờ)";
    ui_send($t, kb([
        [btn('➕ FREE ×1 (24h)', cbd('kg', 'FREE', 1, 24)), btn('➕ FREE ×5 (24h)', cbd('kg', 'FREE', 5, 24))],
        [btn('➕ FREE ×10 (24h)', cbd('kg', 'FREE', 10, 24)), btn('➕ FREE ×20 (24h)', cbd('kg', 'FREE', 20, 24))],
        [btn('💎 VIP ×1 · 1 ngày', cbd('kg', 'VIP', 1, 24)), btn('💎 VIP ×1 · 7 ngày', cbd('kg', 'VIP', 1, 168))],
        [btn('💎 VIP ×1 · 30 ngày', cbd('kg', 'VIP', 1, 720)), btn('💎 VIP ×5 · 30 ngày', cbd('kg', 'VIP', 5, 720))],
        [btn('🟢 Xem FREE tồn', 'kl|FREE|0|0'), btn('🟢 Xem VIP tồn', 'kl|VIP|0|0')],
        [btn('🔴 Xem FREE đã dùng', 'kl|FREE|1|0'), btn('🔴 Xem VIP đã dùng', 'kl|VIP|1|0')],
        [btn('🔍 Tra cứu mã Key', 'sk'), btn('🔓 Mở khoá Get Key', 'uk|0')],
        home_row(),
    ]));
}

function bot_gen_keys($type, $n, $hours)
{
    $table = $type === 'VIP' ? 'keys_vip' : 'keys_free';
    $made  = [];
    for ($i = 0; $i < $n; $i++) {
        for ($try = 0; $try < 5; $try++) {
            $code = 'IOS-' . strtoupper(bin2hex(random_bytes(4))) . '-' . $type;
            try {
                bq_exec('INSERT INTO `' . $table . '` (key_code, duration_hours, status) VALUES (?, ?, 0)', 'si', [$code, $hours]);
                $made[] = $code;
                break;
            } catch (Throwable $e) {
                // retry on collision
            }
        }
    }
    return $made;
}

function screen_gen_keys($type, $n, $hours)
{
    $type  = strtoupper((string) $type) === 'VIP' ? 'VIP' : 'FREE';
    $n     = max(1, min(50, (int) $n));
    $hours = max(1, min(8760, (int) $hours));
    $made  = bot_gen_keys($type, $n, $hours);
    if (!$made) {
        return ui_error('Không tạo được Key (kiểm tra cơ sở dữ liệu)', 'ky');
    }
    bot_log('gen_keys', 'Tạo ' . count($made) . ' key ' . $type . ' (' . $hours . ' giờ) [Telegram]');
    toast('✅ Đã tạo ' . count($made) . ' key ' . $type);
    $t = '✅ <b>ĐÃ TẠO ' . count($made) . ' KEY ' . $type . '</b> · ' . tg_e(tg_hours($hours)) . "\n" . hr() . "\n";
    foreach ($made as $code) {
        $t .= '<code>' . tg_e($code) . "</code>\n";
    }
    $t .= hr() . "\n<i>Chạm vào mã Key để sao chép nhanh.</i>";
    ui_send($t, kb([[btn('🔑 Kho Key', 'ky'), btn('🏠 Menu chính', 'home')]]));
}

function screen_key_list($type, $status, $page)
{
    $type   = strtoupper((string) $type) === 'VIP' ? 'VIP' : 'FREE';
    $status = (int) $status === 1 ? 1 : 0;
    $table  = $type === 'VIP' ? 'keys_vip' : 'keys_free';
    $per    = 8;
    $total  = (int) bq_val('SELECT COUNT(*) FROM `' . $table . '` WHERE status = ?', 'i', [$status]);
    $pages  = max(1, (int) ceil($total / $per));
    $page   = max(0, min((int) $page, $pages - 1));
    $rows   = bq_all(
        'SELECT id, key_code, duration_hours, used_by FROM `' . $table . '` WHERE status = ? ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . ($page * $per),
        'i',
        [$status]
    );
    $kbd = [];
    foreach ($rows as $r) {
        $label = $status === 0
            ? '🔑 ' . $r['key_code'] . ' · ' . tg_hours($r['duration_hours'])
            : '🔴 ' . $r['key_code'] . ($r['used_by'] !== null ? ' · ' . tg_clip($r['used_by'], 14) : '');
        $kbd[] = [btn($label, cbd('dr', $table, $r['id']))];
    }
    if ($pages > 1) {
        $kbd[] = pager_row('kl', [$type, $status], $page, $pages);
    }
    $kbd[] = [btn('🔑 Kho Key', 'ky'), btn('🏠 Menu chính', 'home')];
    ui_send(($status === 0 ? '🟢' : '🔴') . ' <b>KEY ' . $type . ' ' . ($status === 0 ? 'CHƯA DÙNG' : 'ĐÃ DÙNG') . '</b> · ' . $total . ' mã', kb($kbd));
}

function screen_key_search($code)
{
    $code = trim((string) $code);
    $hits = [];
    foreach (['keys_free', 'keys_vip'] as $table) {
        foreach (bq_all('SELECT id, key_code FROM `' . $table . '` WHERE key_code LIKE ? ORDER BY id DESC LIMIT 6', 's', ['%' . $code . '%']) as $r) {
            $hits[] = [$table, $r];
        }
    }
    if (!$hits) {
        return ui_error('Không tìm thấy mã Key khớp «' . tg_clip($code, 40) . '»', 'ky');
    }
    if (count($hits) === 1) {
        return bot_row_view($hits[0][0], $hits[0][1]['id']);
    }
    $kbd = [];
    foreach (array_slice($hits, 0, 8) as $h) {
        $kbd[] = [btn('🔑 ' . $h[1]['key_code'], cbd('dr', $h[0], $h[1]['id']))];
    }
    $kbd[] = [btn('🔑 Kho Key', 'ky'), btn('🏠 Menu chính', 'home')];
    ui_send('🔍 <b>KẾT QUẢ TÌM KEY</b> · ' . count($hits) . ' mã', kb($kbd));
}

function screen_products($page = 0)
{
    $per   = 8;
    $total = (int) bq_val('SELECT COUNT(*) FROM products');
    $pages = max(1, (int) ceil($total / $per));
    $page  = max(0, min((int) $page, $pages - 1));
    $rows  = bq_all('SELECT id, name, type FROM products ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . ($page * $per));
    $meta  = bot_tool_meta_load();

    $kbd = [[btn('➕ Thêm sản phẩm mới', 'pa')]];
    foreach ($rows as $r) {
        $tid    = (string) (int) $r['id'];
        $isM    = !empty($meta[$tid]['maint']);
        $prefix = $isM ? '🛠 ' : (strtoupper((string) $r['type']) === 'VIP' ? '💎 ' : '🟢 ');
        $kbd[]  = [btn($prefix . tg_clip($r['name'], 28) . ($isM ? ' [Bảo trì]' : '') . ' · #' . $r['id'], cbd('dr', 'products', $r['id']))];
    }
    if ($pages > 1) {
        $kbd[] = pager_row('pr', [], $page, $pages);
    }
    $kbd[] = home_row();
    ui_send('📦 <b>QUẢN LÝ SẢN PHẨM & BẢO TRÌ TOOL</b> · ' . $total . " mục\n<i>💎 VIP · 🟢 FREE · 🛠 Đang bảo trì riêng</i>\nChạm vào sản phẩm để sửa tên, link, đổi VIP/FREE hoặc bật/tắt bảo trì riêng.", kb($kbd));
}

function bot_toggle_product_maint($id)
{
    $id   = (int) $id;
    $meta = bot_tool_meta_load();
    $k    = (string) $id;
    if (!isset($meta[$k]) || !is_array($meta[$k])) {
        $meta[$k] = [];
    }
    $nowOn = empty($meta[$k]['maint']);
    if ($nowOn) {
        $meta[$k]['maint'] = 1;
    } else {
        unset($meta[$k]['maint']);
    }
    bot_tool_meta_save($meta);
    bot_log('tg_product_maint', 'products#' . $id . ': ' . ($nowOn ? 'Bật' : 'Tắt') . ' bảo trì riêng');
    toast($nowOn ? '🛠 Đã bật bảo trì sản phẩm #' . $id : '🟢 Đã mở lại sản phẩm #' . $id);
    return bot_row_view('products', $id, $nowOn ? '🛠 <b>Sản phẩm đang ở chế độ bảo trì riêng.</b>' : '🟢 <b>Sản phẩm đã hoạt động lại bình thường.</b>');
}

function bot_set_product_pkg($id, $pkg)
{
    $id   = (int) $id;
    $pkg  = (int) $pkg;
    $meta = bot_tool_meta_load();
    $k    = (string) $id;
    if (!isset($meta[$k]) || !is_array($meta[$k])) {
        $meta[$k] = [];
    }
    if ($pkg <= 0) {
        unset($meta[$k]['pkg']);
        $msg = 'Đã đặt về tự động phân gói theo loại sản phẩm';
    } else {
        $meta[$k]['pkg'] = max(1, min(8, $pkg));
        $msg = 'Đã chuyển sản phẩm vào Gói số ' . $meta[$k]['pkg'];
    }
    bot_tool_meta_save($meta);
    bot_log('tg_product_pkg', 'products#' . $id . ': ' . $msg);
    toast('📦 ' . $msg);
    return bot_row_view('products', $id, '✅ <b>' . tg_e($msg) . '</b>');
}

/* ------------------------------------------------------------------
 * 8B. 100% Vietnamese Website Customization & Full Admin CP Settings Hub
 *     - Snow toggle (ds_snow), All iosmods.xyz Logos (direct image upload OR URL),
 *       Banner slides, Packages 1–8, Texts, System Notices, Colors & Security.
 *     - 100% Vietnamese UI labels; preserves 100% verbatim English/Vietnamese values!
 * ------------------------------------------------------------------ */

function bt_vi_table($table)
{
    $map = [
        'users'            => 'Danh sách Thành viên',
        'products'         => 'Danh sách Sản phẩm / Công cụ',
        'keys_free'        => 'Kho Key Miễn phí (Free)',
        'keys_vip'         => 'Kho Key VIP',
        'site_config'      => 'Cài đặt Website',
        'ip_bans'          => 'Danh sách Khoá địa chỉ IP',
        'tg_user_blocks'   => 'Danh sách Khoá tài khoản',
        'tg_activity'      => 'Nhật ký Hoạt động Website',
        'admin_logs'       => 'Nhật ký Thao tác Quản trị',
        'key_attempts'     => 'Nhật ký Nhập sai mã Key',
        'login_attempts'   => 'Nhật ký Đăng nhập sai',
        'free_key_claims'  => 'Lịch sử Nhận Key Miễn phí',
        'free_key_ip_log'  => 'Nhật ký Kiểm soát VPN / Trùng IP',
        'free_key_seen'    => 'Liên kết Thiết bị & Tài khoản',
        'free_key_pass'    => 'Quyền ưu tiên Nhận Key 1 lần',
    ];
    return isset($map[$table]) ? $map[$table] : ('Bảng ' . $table);
}

function bt_vi_col($col)
{
    $map = [
        'id'                  => 'Mã số',
        'username'            => 'Tài khoản web',
        'password'            => 'Mật khẩu',
        'role'                => 'Quyền hạn (1=Admin, 0=Thành viên)',
        'key_type'            => 'Loại Key đang dùng',
        'expiry_date'         => 'Thời hạn hết hạn Key',
        'turns_left'          => 'Số lượt còn lại',
        'alert_msg'           => 'Thông báo riêng cho tài khoản',
        'vip_free_clicks'     => 'Số lần bấm nhầm mục miễn phí',
        'password_changed_at' => 'Đổi mật khẩu lúc',
        'name'                => 'Tên sản phẩm',
        'link'                => 'Đường dẫn mở công cụ',
        'image'               => 'Ảnh đại diện sản phẩm',
        'type'                => 'Phân loại (FREE / VIP)',
        'description'         => 'Mô tả sản phẩm',
        'ios_version'         => 'Phiên bản iOS hỗ trợ',
        'key_code'            => 'Mã Key',
        'duration_hours'      => 'Thời hạn (số giờ)',
        'status'              => 'Trạng thái (0=Chưa dùng, 1=Đã dùng)',
        'used_by'             => 'Người đã kích hoạt',
        'used_at'             => 'Kích hoạt lúc',
        'config_key'          => 'Mã cài đặt',
        'config_value'        => 'Giá trị đang lưu',
        'ip'                  => 'Địa chỉ IP',
        'reason'              => 'Nguyên nhân / Lý do',
        'ban_type'            => 'Phân loại khoá',
        'banned_by'           => 'Người thực hiện khoá',
        'active'              => 'Đang áp dụng (1=Có, 0=Đã gỡ)',
        'hits'                => 'Số lượt bị chặn',
        'created_at'          => 'Thời gian tạo',
        'expires_at'          => 'Thời gian tự mở khoá',
        'unbanned_by'         => 'Người mở khoá',
        'unbanned_at'         => 'Thời gian mở khoá',
    ];
    return isset($map[$col]) ? $map[$col] : $col;
}

function bot_vi_cfg_groups()
{
    $groups = [
        'logo' => [
            'title' => '🖼 Logo, Hiệu ứng Tuyết rơi & Nhận diện',
            'desc'  => 'Đổi toàn bộ Logo iosmods.xyz (Trang chủ, Đăng nhập, Đăng ký, Chính sách, Điều khoản, Màn hình khoá), tốc độ lấp loá và bật/tắt tuyết rơi.',
            'items' => [
                'ds_snow'           => ['❄️ Hiệu ứng tuyết rơi giao diện chính', 'tri', 'Bật hoặc tắt hiệu ứng tuyết rơi trên trang chủ iosmods.xyz'],
                'brand_logo'        => ['🖼 Logo chính toàn bộ web iosmods.xyz', 'img_logo', 'Áp dụng đồng bộ 100% cho Trang chủ, Đăng nhập, Đăng ký, Chính sách, Điều khoản và Màn hình khoá (có thể gửi ảnh trực tiếp hoặc dán link)'],
                'brand_logo_scale'  => ['🔍 Độ phóng to Logo (chuẩn: 1.4)', 'text', 'Nhập số từ 1.0 đến 2.4 (mặc định giao diện chính là 1.4)'],
                'ds_logo_h'         => ['📏 Chiều cao gốc của Logo (chuẩn: 42 px)', 'text', 'Nhập số từ 28 đến 64 (mặc định 42)'],
                'ds_logo_skew'      => ['📐 Góc nghiêng bù thẳng Logo (chuẩn: 10)', 'text', 'Nhập từ -30 đến 30 (mặc định 10 để chữ đứng thẳng đẹp)'],
                'ds_shine'          => ['✨ Bật hiệu ứng lấp loá trên Logo', 'tri', 'Bật hoặc tắt dải sáng lướt qua Logo'],
                'ds_shine_seconds'  => ['⏱ Tốc độ lấp loá Logo (chuẩn đẹp: 1.35 giây)', 'text', 'Số nhỏ chạy nhanh, số lớn chạy chậm (1.35 = sáng đẹp vừa nhanh, 2.2 = chậm dịu)'],
                'favicon_url'       => ['🔖 Logo nhỏ trên Tab trình duyệt (Favicon)', 'img_fav', 'Gửi ảnh vuông trực tiếp hoặc dán link ảnh icon cho tab trình duyệt'],
                'favicon_bg'        => ['🎨 Màu nền Icon Tab trình duyệt', 'text', 'Mã màu dạng #14161c hoặc gõ none nếu muốn nền trong suốt'],
                'ds_avatar_url'     => ['👤 Ảnh đại diện mặc định trên Header', 'img_av', 'Gửi ảnh trực tiếp hoặc dán link ảnh đại diện góc phải màn hình'],
                'ds_avatar_shape'   => ['⭕ Hình dáng khung ảnh đại diện', 'select:circle,rounded,square', 'Chọn tròn (circle), bo góc (rounded) hoặc vuông (square)'],
            ],
        ],
        'home' => [
            'title' => '🏞 Banner trượt, Tiêu đề & Gói sản phẩm (1–8)',
            'desc'  => 'Quản lý ảnh trượt trang chủ, tiêu đề đầu trang và đặt tên/mô tả cho các gói sản phẩm (nhập tiếng Anh sẽ lưu đúng nguyên bản tiếng Anh).',
            'items' => [
                'ds_hero_title'     => ['🏷 Tiêu đề lớn đầu trang chủ', 'text', 'Để trống = mặc định (ví dụ nhập tiếng Anh: Apps)'],
                'ds_hero_desc'      => ['📝 Dòng mô tả dưới tiêu đề lớn', 'text', 'Dòng giới thiệu ngắn dưới tiêu đề trang chủ'],
                'ds_hero_images'    => ['🖼 Danh sách ảnh Banner trượt trang chủ', 'img_hero', 'Gửi ảnh trực tiếp để thêm vào Banner hoặc gửi nhiều link (mỗi dòng 1 link)'],
                'ds_hero_seconds'   => ['⏱ Số giây tự chuyển ảnh Banner (2–60)', 'text', 'Ví dụ: 4 hoặc 5 giây'],
                'ds_chips'          => ['🏷 Hàng thẻ nhỏ chạy dưới Banner', 'text', 'Mỗi dòng 1 thẻ dạng Tên|icon (giữ nguyên đúng tiếng Anh/Việt bạn nhập)'],
                'ds_pkg_count'      => ['📦 Số lượng Gói sản phẩm hiển thị (3–8)', 'text', 'Nhập từ 3 đến 8 gói hiển thị ngoài trang chủ'],
                'ds_pkg1_title'     => ['📦 Tên hiển thị Gói số 1 (VIP)', 'text', 'Tên gói 1 trên trang chủ (nhập tiếng Anh sẽ lưu đúng tiếng Anh)'],
                'ds_pkg1_sub'       => ['💬 Mô tả phụ dưới Gói số 1', 'text', 'Dòng chữ nhỏ bên dưới tên Gói 1'],
                'ds_pkg2_title'     => ['📦 Tên hiển thị Gói số 2 (Miễn phí)', 'text', 'Tên gói 2 trên trang chủ'],
                'ds_pkg2_sub'       => ['💬 Mô tả phụ dưới Gói số 2', 'text', 'Dòng chữ nhỏ bên dưới tên Gói 2'],
                'ds_pkg3_title'     => ['📦 Tên hiển thị Gói số 3 (iOS)', 'text', 'Tên gói 3 trên trang chủ'],
                'ds_pkg3_sub'       => ['💬 Mô tả phụ dưới Gói số 3', 'text', 'Dòng chữ nhỏ bên dưới tên Gói 3'],
                'ds_pkg4_title'     => ['📦 Tên hiển thị Gói số 4', 'text', 'Tên gói 4 (khi bật từ 4 gói trở lên)'],
                'ds_pkg5_title'     => ['📦 Tên hiển thị Gói số 5', 'text', 'Tên gói 5 (khi bật từ 5 gói trở lên)'],
                'ds_home_preview'   => ['🔢 Số sản phẩm hiện sẵn mỗi gói (0 = hiện hết)', 'text', 'Nhập 0 để hiện toàn bộ sản phẩm trong gói'],
                'ds_reopen_seconds' => ['🔄 Số giây mở lại sản phẩm không mất lượt', 'text', 'Ví dụ: 20 hoặc 90 giây'],
            ],
        ],
        'text' => [
            'title' => '✍️ Chữ hiển thị, Nút bấm & Liên kết Telegram',
            'desc'  => 'Chỉnh sửa mọi dòng chữ, nút bấm và liên kết trên website. Bạn gõ tiếng Anh hay tiếng Việt thì hệ thống đều lưu chính xác 100% từng ký tự.',
            'items' => [
                'site_title'          => ['🌐 Tên Website trên thanh tiêu đề trình duyệt', 'text', 'Ví dụ: iosmods'],
                'link_admin_tele'     => ['✈️ Đường dẫn Telegram Admin (nút mua Key VIP)', 'text', 'Ví dụ: https://t.me/iosnea'],
                'text_telegram_group' => ['📢 Dòng chữ nút tham gia nhóm Telegram', 'text', 'Ví dụ: JOIN THE TELEGRAM GROUP'],
                'text_key_vip'        => ['💎 Tiêu đề khung nhập Key VIP', 'text', 'Chữ tiêu đề trên ô nhập Key VIP'],
                'text_key_free'       => ['🟢 Tiêu đề khung nhập Key Miễn phí', 'text', 'Chữ tiêu đề trên ô nhập Key Free'],
                'placeholder_key_vip' => ['✏️ Chữ gợi ý mờ trong ô nhập Key VIP', 'text', 'Ví dụ: Enter your VIP key...'],
                'placeholder_key_free'=> ['✏️ Chữ gợi ý mờ trong ô nhập Key Free', 'text', 'Ví dụ: Enter your Free key...'],
                'text_kich_hoat'      => ['🔘 Chữ trên nút Kích hoạt Key', 'text', 'Ví dụ: Activate'],
                'text_get_key_free'   => ['🎁 Chữ trên nút nhận Key Miễn phí', 'text', 'Ví dụ: Get Free Key'],
                'text_buy_vip'        => ['💳 Dòng chữ hướng dẫn mua Key VIP', 'text', 'Nội dung hiển thị phần mua Key VIP'],
                'text_buy_vip_link'   => ['🔗 Chữ gắn link liên hệ mua Key VIP', 'text', 'Chữ bấm mở Telegram Admin'],
                'chao_khach'          => ['👋 Lời chào khi khách chưa đăng nhập', 'text', 'Lời chào dành cho khách'],
                'xin_chao'            => ['👋 Lời chào khi thành viên đã đăng nhập', 'text', 'Ví dụ: Hello,'],
                'footer_made_by'      => ['❤️ Tên thương hiệu ở chân trang (sau chữ by)', 'text', 'Mặc định: iosmods'],
                'footer_year'         => ['📅 Năm hiển thị dưới chân trang', 'text', 'Ví dụ: @2024'],
                'ds_replace'          => ['🔄 Thay thế chữ tự do toàn trang (Chữ gốc|Chữ mới)', 'text', 'Mỗi dòng 1 cặp dạng Chữ gốc|Chữ muốn đổi'],
            ],
        ],
        'notice' => [
            'title' => '💬 Thanh thông báo & Câu báo khi nhập Key / Mở sản phẩm',
            'desc'  => 'Tất cả thông báo nổi và hộp thoại báo trạng thái trên trang chủ (hỗ trợ biến {turns}, {plan}, {minutes}; gõ tiếng Anh lưu đúng tiếng Anh).',
            'items' => [
                'show_announcement'     => ['📢 Bật thanh thông báo chạy đầu trang chủ', 'bool', 'Bật hoặc tắt khung thông báo chung'],
                'site_announcement'     => ['📝 Nội dung thanh thông báo đầu trang', 'text', 'Nội dung thông báo gửi toàn bộ thành viên'],
                'notice_success'        => ['✅ Câu báo khi kích hoạt Key thành công', 'text', 'Có thể dùng {turns} và {plan}'],
                'notice_key_wrong'      => ['❌ Câu báo khi nhập sai mã Key', 'text', 'Ví dụ: Invalid key. Please check it and try again!'],
                'notice_not_logged_in'  => ['🔐 Câu báo nhắc đăng nhập khi bấm thao tác', 'text', 'Ví dụ: Please log in to continue!'],
                'notice_need_login'     => ['👤 Câu báo cần đăng nhập khi mở sản phẩm', 'text', 'Ví dụ: You need to log in!'],
                'notice_has_turn'       => ['🎟 Câu báo khi vẫn còn lượt mà nhập thêm Key', 'text', 'Có thể dùng {turns}'],
                'notice_need_vip'       => ['💎 Câu báo khi dùng Key Free bấm sản phẩm VIP', 'text', 'Nhắc sản phẩm yêu cầu Key VIP'],
                'notice_need_key'       => ['🔑 Câu báo khi tài khoản đã hết lượt', 'text', 'Ví dụ: You are out of turns. Please enter a key!'],
                'notice_key_expired'    => ['⌛ Câu báo khi mã Key đã hết hạn', 'text', 'Nhắc nhập mã Key mới'],
                'notice_tool_ok'        => ['🚀 Câu báo khi mở sản phẩm trừ 1 lượt', 'text', 'Có thể dùng {turns}'],
                'notice_tool_reopen'    => ['♻️ Câu báo khi mở lại sản phẩm miễn phí', 'text', 'Khi mở lại trong khung thời gian cho phép'],
                'notice_key_daily_limit'=> ['🛑 Câu báo khi chạm giới hạn nhập Key trong ngày', 'text', 'Thông báo giới hạn nhận/nhập Key ngày'],
            ],
        ],
        'style' => [
            'title' => '🎨 Màu sắc Sáng/Tối, Ẩn/Hiện mục & Khung giao diện',
            'desc'  => 'Tuỳ chỉnh màu sắc giao diện Tối/Sáng, nút bấm và ẩn/hiện từng thành phần trên trang chủ.',
            'items' => [
                'ds_show_title'     => ['👁 Hiện tiêu đề & mô tả lớn đầu trang chủ', 'tri', 'Bật hoặc ẩn khối tiêu đề lớn'],
                'ds_show_slider'    => ['👁 Hiện khung ảnh Banner trượt trang chủ', 'tri', 'Bật hoặc ẩn khung ảnh trượt'],
                'ds_show_chips'     => ['👁 Hiện hàng thẻ nhỏ dưới Banner', 'tri', 'Bật hoặc ẩn dải thẻ nhỏ'],
                'ds_show_join'      => ['👁 Hiện nút tham gia nhóm Telegram', 'tri', 'Bật hoặc ẩn dòng nhóm Telegram'],
                'ds_show_docs'      => ['👁 Hiện khối giới thiệu & tài liệu cuối trang', 'tri', 'Bật hoặc ẩn phần thông tin cuối trang'],
                'ds_show_theme_btn' => ['🌗 Hiện nút chuyển Sáng / Tối trên Header', 'tri', 'Bật hoặc ẩn nút mặt trăng/mặt trời'],
                'ds_show_lang'      => ['🌐 Hiện nút chuyển Ngôn ngữ trên Header', 'tri', 'Bật hoặc ẩn nút ngôn ngữ'],
                'ds_nav_see'        => ['✨ Hiệu ứng kính mờ thanh điều hướng dưới đáy', 'tri', 'Làm trong mờ thanh menu dưới cùng'],
                'ds_c_bg'           => ['🌑 Giao diện Tối: Màu nền trang (#000000)', 'text', 'Nhập mã màu HEX ví dụ #000000'],
                'ds_c_card'         => ['🌑 Giao diện Tối: Màu nền khung thẻ (#0a0a0b)', 'text', 'Nhập mã màu HEX ví dụ #0a0a0b'],
                'ds_c_accent'       => ['🔵 Giao diện Tối: Màu nhấn nút chính', 'text', 'Nhập mã màu HEX ví dụ #0a84ff'],
                'ds_l_bg'           => ['☀️ Giao diện Sáng: Màu nền trang (#f5f7fa)', 'text', 'Nhập mã màu HEX ví dụ #f5f7fa'],
                'ds_get_bg'         => ['🔘 Màu nền nút GET mở sản phẩm', 'text', 'Nhập mã màu HEX hoặc để trống dùng mặc định'],
                'ds_get_fg'         => ['🔤 Màu chữ nút GET mở sản phẩm', 'text', 'Nhập mã màu HEX hoặc để trống dùng mặc định'],
                'ds_custom_css'     => ['🧩 Mã CSS tuỳ chỉnh thêm cho giao diện', 'text', 'Dành cho tuỳ biến CSS nâng cao'],
            ],
        ],
        'sec' => [
            'title' => '🔐 Bảo mật Đăng nhập, Giới hạn Tài khoản & SEO Google',
            'desc'  => 'Cấu hình giới hạn sai mật khẩu, từ khoá tên tài khoản bị cấm, phiên đăng nhập và mô tả tìm kiếm Google.',
            'items' => [
                'login_max_attempts'      => ['🔑 Số lần nhập sai mật khẩu tối đa', 'text', 'Ví dụ: 5 lần'],
                'login_lock_minutes'      => ['⏱ Số phút khoá đăng nhập khi nhập sai quá mức', 'text', 'Ví dụ: 15 phút'],
                'admin_max_attempts'      => ['👑 Số lần nhập sai tối đa khi vào trang Admin', 'text', 'Ví dụ: 5 lần'],
                'session_timeout_minutes' => ['⌛ Tự đăng xuất thành viên sau N phút nghỉ (0 = tắt)', 'text', 'Nhập 0 để giữ phiên không tự thoát'],
                'min_password_length'     => ['🔒 Độ dài mật khẩu tối thiểu (6–64)', 'text', 'Ví dụ: 8 ký tự'],
                'reserved_usernames'      => ['🚫 Danh sách tên đăng ký bị cấm (cách dấu phẩy)', 'text', 'Ví dụ: admin,root,support'],
                'admin_ip_allow'          => ['🛡 Giới hạn IP được vào trang Admin CP (trống = mọi IP)', 'text', 'Cách nhau dấu phẩy hoặc để trống'],
                'seo_title'               => ['🔍 Tiêu đề chuẩn SEO hiển thị trên Google', 'text', 'Tiêu đề khi chia sẻ link hoặc tìm trên Google'],
                'seo_description'         => ['📄 Mô tả chuẩn SEO hiển thị trên Google', 'text', 'Đoạn giới thiệu website trên Google'],
                'ds_privacy_url'          => ['📜 Đường dẫn trang Chính sách bảo mật', 'text', 'Mặc định: /docs.php?doc=privacy'],
                'ds_terms_url'            => ['⚖️ Đường dẫn trang Điều khoản sử dụng', 'text', 'Mặc định: /docs.php?doc=terms'],
            ],
        ],
    ];
    return $groups;
}

function bot_vi_cfg_lookup($key)
{
    foreach (bot_vi_cfg_groups() as $gid => $g) {
        if (isset($g['items'][$key])) {
            return [$gid, $g['items'][$key][0], $g['items'][$key][1], $g['items'][$key][2]];
        }
    }
    if (preg_match('/^ds_pkg([1-8])_(title|sub)$/', (string) $key, $m)) {
        return ['home', ($m[2] === 'title' ? '📦 Tên hiển thị Gói số ' : '💬 Mô tả phụ Gói số ') . $m[1], 'text', 'Cấu hình hiển thị cho Gói sản phẩm số ' . $m[1]];
    }
    if (strpos((string) $key, 'notice_') === 0) {
        return ['notice', '💬 Câu thông báo hệ thống (' . substr((string) $key, 7) . ')', 'text', 'Nội dung thông báo hiển thị cho người dùng (nhập tiếng Anh sẽ lưu đúng tiếng Anh)'];
    }
    return ['all', '⚙️ Cài đặt «' . $key . '»', 'text', 'Mục cấu hình lưu trong bảng site_config của website'];
}

function bot_save_uploaded_image(array $m, $prefix = 'dslogo_')
{
    $fileId = '';
    $ext    = 'png';
    if (!empty($m['photo']) && is_array($m['photo'])) {
        $best   = end($m['photo']);
        $fileId = isset($best['file_id']) ? (string) $best['file_id'] : '';
        $ext    = 'jpg';
    } elseif (!empty($m['document']) && is_array($m['document'])) {
        $doc  = $m['document'];
        $mime = strtolower((string) (isset($doc['mime_type']) ? $doc['mime_type'] : ''));
        $fn   = strtolower((string) (isset($doc['file_name']) ? $doc['file_name'] : ''));
        if (strpos($mime, 'image/') === 0 || preg_match('/\.(png|webp|jpe?g|gif|ico)$/', $fn, $em)) {
            $fileId = isset($doc['file_id']) ? (string) $doc['file_id'] : '';
            if (preg_match('/\.(png|webp|jpe?g|gif|ico)$/', $fn, $em2)) {
                $ext = $em2[1] === 'jpeg' ? 'jpg' : $em2[1];
            } elseif (strpos($mime, 'webp') !== false) {
                $ext = 'webp';
            } elseif (strpos($mime, 'jpeg') !== false) {
                $ext = 'jpg';
            } elseif (strpos($mime, 'gif') !== false) {
                $ext = 'gif';
            }
        }
    } elseif (!empty($m['sticker']) && is_array($m['sticker']) && empty($m['sticker']['is_animated']) && empty($m['sticker']['is_video'])) {
        $fileId = isset($m['sticker']['file_id']) ? (string) $m['sticker']['file_id'] : '';
        $ext    = 'webp';
    }

    if ($fileId === '') {
        return [false, 'Tin nhắn không chứa ảnh hợp lệ. Hãy gửi 1 bức ảnh, tệp ảnh PNG/WEBP hoặc dán đường dẫn https://...'];
    }

    $gf = tg_api_raw('getFile', ['file_id' => $fileId], 10);
    if (empty($gf['ok']) || empty($gf['result']['file_path'])) {
        return [false, 'Không tải được tệp ảnh từ máy chủ Telegram, vui lòng thử lại.'];
    }
    $remotePath = (string) $gf['result']['file_path'];
    if (preg_match('/\.(png|webp|jpe?g|gif|ico)$/i', $remotePath, $rm)) {
        $ext = strtolower($rm[1]) === 'jpeg' ? 'jpg' : strtolower($rm[1]);
    }
    $dlUrl = rtrim((string) tg_cfg('api_base', 'https://api.telegram.org'), '/') . '/file/bot' . (string) tg_cfg('bot_token', '') . '/' . ltrim($remotePath, '/');

    $bytes = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($dlUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $bytes = @curl_exec($ch);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $bytes = @file_get_contents($dlUrl);
    }
    if (!is_string($bytes) || strlen($bytes) < 32 || strlen($bytes) > 12 * 1024 * 1024) {
        return [false, 'Dữ liệu ảnh tải về không hợp lệ hoặc vượt quá 12MB.'];
    }
    if (function_exists('getimagesizefromstring') && $ext !== 'ico') {
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return [false, 'Tệp gửi lên không phải định dạng hình ảnh chuẩn.'];
        }
        $mimeMap = ['image/png' => 'png', 'image/webp' => 'webp', 'image/jpeg' => 'jpg', 'image/gif' => 'gif'];
        if (!empty($info['mime']) && isset($mimeMap[$info['mime']])) {
            $ext = $mimeMap[$info['mime']];
        }
    }

    $upDir = __DIR__ . '/uploads';
    if (!is_dir($upDir)) {
        @mkdir($upDir, 0755, true);
    }
    $relFile = 'uploads/' . $prefix . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (@file_put_contents(__DIR__ . '/' . $relFile, $bytes, LOCK_EX) === false) {
        return [false, 'Không thể ghi tệp vào thư mục uploads/ trên máy chủ.'];
    }
    bot_purge_logo_caches();
    return [true, $relFile];
}

function screen_appearance($note = '')
{
    $snowOn   = bot_snow_is_on();
    $logoCur  = trim((string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'brand_logo'", '', [], ''));
    $scaleCur = trim((string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'brand_logo_scale'", '', [], '1.4')) ?: '1.4';
    $hCur     = trim((string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'ds_logo_h'", '', [], '42')) ?: '42';
    $shineOn  = trim((string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'ds_shine'", '', [], '1')) !== '0';
    $shineSec = trim((string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'ds_shine_seconds'", '', [], '1.35')) ?: '1.35';
    $favCur   = trim((string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'favicon_url'", '', [], ''));
    $avCur    = trim((string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'ds_avatar_url'", '', [], ''));
    $heroRaw  = trim((string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'ds_hero_images'", '', [], ''));
    $heroArr  = $heroRaw !== '' ? array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $heroRaw)))) : [];

    $t = '🖼 <b>QUẢN LÝ LOGO TOÀN WEBSITE &amp; HIỆU ỨNG TUYẾT RƠI</b>' . "\n";
    if ($note !== '') {
        $t .= '<blockquote>' . $note . "</blockquote>\n";
    }
    $t .= '<blockquote>'
        . '❄️ Hiệu ứng Tuyết rơi trang chính: ' . ($snowOn ? '🟢 <b>ĐANG BẬT</b>' : '🔴 <b>ĐANG TẮT</b>') . "\n"
        . '🖼 Logo chính toàn web (<code>iosmods.xyz</code>): <code>' . tg_e($logoCur !== '' ? tg_clip($logoCur, 42) : 'Mặc định tự nhận từ uploads/') . "</code>\n"
        . '📐 Cỡ &amp; Phóng to: <b>Cao ' . tg_e($hCur) . 'px · Tỷ lệ ' . tg_e($scaleCur) . 'x</b>' . "\n"
        . '✨ Độ lấp loá Logo: ' . ($shineOn ? '🟢 <b>Đang bật (chu kỳ ' . tg_e($shineSec) . ' giây)</b>' : '⚪ <b>Đang tắt</b>') . "\n"
        . '🔖 Icon Tab trình duyệt: <code>' . tg_e($favCur !== '' ? tg_clip($favCur, 36) : 'Mặc định') . "</code>\n"
        . '👤 Ảnh đại diện Header: <code>' . tg_e($avCur !== '' ? tg_clip($avCur, 36) : 'Mặc định') . "</code>\n"
        . '🏞 Ảnh Banner trượt trang chủ: <b>' . count($heroArr) . ' ảnh</b>'
        . '</blockquote>' . "\n"
        . '<i>📌 Khi bạn đổi Logo chính tại đây, toàn bộ Trang chủ, Đăng nhập, Đăng ký, Chính sách bảo mật, Điều khoản sử dụng và Màn hình khoá sẽ tự động cập nhật đồng bộ 100%!</i>';

    $kbd = [
        [btn($snowOn ? '❄️ Tuyết rơi: ĐANG BẬT (Bấm để Tắt)' : '❄️ Tuyết rơi: ĐANG TẮT (Bấm để Bật)', 'ui|snow', $snowOn ? 'success' : '')],
        [btn('🖼 Đổi Logo chính toàn web (Gửi ảnh / Link)', 'ui|ask_logo', 'primary')],
        [
            btn('🔍 Tỷ lệ: ' . $scaleCur . 'x', 'ui|cycle_scale'),
            btn('📏 Cao: ' . $hCur . 'px', 'ui|cycle_h'),
        ],
        [
            btn($shineOn ? '✨ Lấp loá: ' . $shineSec . 's' : '⚪ Lấp loá: TẮT', 'ui|cycle_shine'),
            btn('♻️ Đặt chuẩn (1.4x · 1.35s)', 'ui|std_logo'),
        ],
        [
            btn('🔖 Đổi Icon Tab (Favicon)', 'ui|ask_fav'),
            btn('👤 Đổi Ảnh đại diện Web', 'ui|ask_av'),
        ],
        [
            btn('🏞 Thêm ảnh Banner trượt (' . count($heroArr) . ')', 'ui|ask_hero_add'),
            btn('🧹 Xoá bớt Banner', 'ui|hero_clear'),
        ],
        [btn('⚙️ Mở toàn bộ Cài đặt Admin CP (Tiếng Việt)', 'cf|menu')],
        home_row(),
    ];
    return ui_send($t, kb($kbd));
}

function bot_ui_action($act, $arg = '')
{
    $admin = $GLOBALS['CTX']['admin'];
    if ($act === 'snow' || $act === 'snow_home') {
        $next = !bot_snow_is_on();
        bot_set_config('ds_snow', $next ? '1' : '0');
        bot_log('tg_snow', ($next ? 'Bật' : 'Tắt') . ' hiệu ứng tuyết rơi trang chính');
        toast($next ? '❄️ Đã BẬT tuyết rơi trên trang chính' : '🌤 Đã TẮT tuyết rơi trên trang chính');
        if ($act === 'snow_home') {
            return screen_home($next ? '❄️ Đã <b>BẬT</b> hiệu ứng tuyết rơi ở giao diện chính.' : '🌤 Đã <b>TẮT</b> hiệu ứng tuyết rơi ở giao diện chính.');
        }
        return screen_appearance($next ? '❄️ Đã <b>BẬT</b> hiệu ứng tuyết rơi trên giao diện chính.' : '🌤 Đã <b>TẮT</b> hiệu ứng tuyết rơi trên giao diện chính.');
    }
    if ($act === 'ask_logo') {
        conv_set($admin, 'up_brand_logo');
        return ui_send('🖼 <b>ĐỔI LOGO CHÍNH TOÀN WEBSITE IOSMODS.XYZ</b>' . "\n" . hr() . "\n"
            . "Bạn có thể thực hiện theo <b>1 trong 2 cách</b>:\n"
            . "1️⃣ <b>Gửi trực tiếp ảnh hoặc tệp PNG/WEBP không nén</b> ngay vào khung chat này (khuyên dùng ảnh nền trong suốt).\n"
            . "2️⃣ Hoặc <b>dán đường dẫn ảnh</b> (<code>https://...</code>).\n\n"
            . "<i>Logo mới sẽ áp dụng đồng bộ 100% cho Trang chủ, Đăng nhập, Đăng ký, Chính sách, Điều khoản và Màn hình khoá!</i>",
            kb([[btn('✖ Huỷ bỏ', 'ui|menu')]]));
    }
    if ($act === 'ask_fav') {
        conv_set($admin, 'up_favicon');
        return ui_send('🔖 <b>ĐỔI ICON TAB TRÌNH DUYỆT (FAVICON)</b>' . "\n" . hr() . "\n"
            . 'Hãy <b>gửi trực tiếp 1 bức ảnh vuông</b> hoặc <b>dán đường dẫn ảnh</b> (<code>https://...</code>) vào khung chat:',
            kb([[btn('✖ Huỷ bỏ', 'ui|menu')]]));
    }
    if ($act === 'ask_av') {
        conv_set($admin, 'up_avatar');
        return ui_send('👤 <b>ĐỔI ẢNH ĐẠI DIỆN MẶC ĐỊNH TRÊN HEADER</b>' . "\n" . hr() . "\n"
            . 'Hãy <b>gửi trực tiếp bức ảnh</b> hoặc <b>dán đường dẫn ảnh</b> (<code>https://...</code>) vào khung chat (gõ <code>MACDINH</code> nếu muốn xoá về mặc định):',
            kb([[btn('✖ Huỷ bỏ', 'ui|menu')]]));
    }
    if ($act === 'ask_hero_add') {
        conv_set($admin, 'up_hero_add');
        return ui_send('🏞 <b>THÊM ẢNH BANNER TRƯỢT TRANG CHỦ</b>' . "\n" . hr() . "\n"
            . 'Hãy <b>gửi trực tiếp bức ảnh</b> hoặc <b>gửi 1 hay nhiều đường dẫn ảnh</b> (mỗi dòng 1 link <code>https://...</code>) để thêm vào khung Banner trượt:',
            kb([[btn('✖ Huỷ bỏ', 'ui|menu')]]));
    }
    if ($act === 'hero_clear') {
        bot_set_config('ds_hero_images', '');
        bot_log('tg_hero_clear', 'Xoá danh sách ảnh Banner tuỳ chỉnh về mặc định');
        toast('🧹 Đã đặt lại ảnh Banner');
        return screen_appearance('✅ Đã xoá danh sách Banner tuỳ chỉnh (quay về mặc định).');
    }
    if ($act === 'cycle_scale') {
        $steps = ['1.2', '1.4', '1.55', '1.7', '1.0'];
        $cur   = trim((string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'brand_logo_scale'", '', [], '1.4')) ?: '1.4';
        $idx   = array_search($cur, $steps, true);
        $next  = $steps[$idx === false ? 1 : (($idx + 1) % count($steps))];
        bot_set_config('brand_logo_scale', $next);
        bot_purge_logo_caches();
        toast('🔍 Độ phóng to Logo: ' . $next . 'x');
        return screen_appearance('✅ Đã chỉnh độ phóng to Logo toàn web thành <b>' . $next . 'x</b>.');
    }
    if ($act === 'cycle_h') {
        $steps = ['38', '42', '46', '50'];
        $cur   = trim((string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'ds_logo_h'", '', [], '42')) ?: '42';
        $idx   = array_search($cur, $steps, true);
        $next  = $steps[$idx === false ? 1 : (($idx + 1) % count($steps))];
        bot_set_config('ds_logo_h', $next);
        bot_set_config('brand_logo_height', $next);
        bot_purge_logo_caches();
        toast('📏 Chiều cao Logo: ' . $next . 'px');
        return screen_appearance('✅ Đã chỉnh chiều cao gốc Logo thành <b>' . $next . 'px</b>.');
    }
    if ($act === 'cycle_shine') {
        $shineOn = trim((string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'ds_shine'", '', [], '1')) !== '0';
        $curSec  = trim((string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'ds_shine_seconds'", '', [], '1.35')) ?: '1.35';
        if (!$shineOn) {
            bot_set_config('ds_shine', '1');
            bot_set_config('ds_shine_seconds', '1.35');
            $msg = 'Đã bật lấp loá chuẩn đẹp (1.35 giây)';
        } elseif ($curSec === '1.35') {
            bot_set_config('ds_shine_seconds', '1.05');
            $msg = 'Đã chuyển sang lấp loá nhanh nổi bật (1.05 giây)';
        } elseif ($curSec === '1.05') {
            bot_set_config('ds_shine_seconds', '2.0');
            $msg = 'Đã chuyển sang lấp loá chậm dịu (2.0 giây)';
        } else {
            bot_set_config('ds_shine', '0');
            $msg = 'Đã tắt hẳn hiệu ứng lấp loá trên Logo';
        }
        bot_purge_logo_caches();
        toast('✨ ' . $msg);
        return screen_appearance('✅ <b>' . tg_e($msg) . '</b>');
    }
    if ($act === 'std_logo') {
        bot_set_config('brand_logo_scale', '1.4');
        bot_set_config('ds_logo_h', '42');
        bot_set_config('brand_logo_height', '42');
        bot_set_config('ds_logo_skew', '10');
        bot_set_config('ds_shine', '1');
        bot_set_config('ds_shine_seconds', '1.35');
        bot_purge_logo_caches();
        bot_log('tg_logo_std', 'Đặt chuẩn Logo 1.4x · cao 42px · nghiêng 10° · lấp loá đẹp 1.35s');
        toast('✅ Đã đặt chuẩn Logo toàn web');
        return screen_appearance('✅ Đã đồng bộ chuẩn 100%: <b>Tỷ lệ 1.4x · Cao 42px · Nghiêng 10° · Lấp loá 1.35s</b> trên toàn bộ trang!');
    }
    return screen_appearance();
}

function screen_config($sub = 'menu', $page = 0, $note = '')
{
    $groups = bot_vi_cfg_groups();
    if ($sub === '' || $sub === 'menu' || ($sub === '0' && $page === 0)) {
        $total = (int) bq_val("SELECT COUNT(*) FROM site_config WHERE config_key <> 'tg_emoji_vault'");
        $t = '⚙️ <b>CÀI ĐẶT TOÀN DIỆN WEBSITE (100% TIẾNG VIỆT)</b>' . "\n";
        if ($note !== '') {
            $t .= '<blockquote>' . $note . "</blockquote>\n";
        }
        $t .= '<blockquote>'
            . '📌 Quản lý đầy đủ mọi tính năng như bảng <b>Admin CP</b> trên web bằng giao diện <b>100% Tiếng Việt</b> dễ hiểu.' . "\n"
            . '🔤 <b>Lưu chuẩn nguyên bản:</b> Khi bạn gõ nội dung bằng <b>tiếng Anh</b> (hoặc tiếng Việt), Bot sẽ tự động lưu <b>đúng chính xác 100% tiếng Anh</b> bạn vừa nhập mà không tự ý dịch hay sửa chữ!'
            . '</blockquote>';

        $kbd = [];
        foreach ($groups as $gid => $g) {
            $kbd[] = [btn($g['title'], 'cf|' . $gid . '|0')];
        }
        $kbd[] = [
            btn('📋 Tất cả mục đang lưu (' . $total . ')', 'cf|all|0'),
            btn('➕ Thêm mục mới', 'cn'),
        ];
        $kbd[] = [btn('🗄 Quản lý Bảng dữ liệu & SQL', 'db'), btn('🏠 Menu chính', 'home')];
        return ui_send($t, kb($kbd));
    }

    if ($sub !== 'all' && isset($groups[$sub])) {
        $g     = $groups[$sub];
        $keys  = array_keys($g['items']);
        $per   = 8;
        $total = count($keys);
        $pages = max(1, (int) ceil($total / $per));
        $page  = max(0, min((int) $page, $pages - 1));
        $slice = array_slice($keys, $page * $per, $per);

        $vals = [];
        foreach (bq_all("SELECT config_key, config_value FROM site_config") as $r) {
            $vals[$r['config_key']] = (string) $r['config_value'];
        }

        $t = '<b>' . $g['title'] . '</b>' . "\n";
        if ($note !== '') {
            $t .= '<blockquote>' . $note . "</blockquote>\n";
        }
        $t .= '<i>' . tg_e($g['desc']) . '</i>';

        $kbd = [];
        foreach ($slice as $ck) {
            $meta = $g['items'][$ck];
            $rawV = isset($vals[$ck]) ? $vals[$ck] : '';
            if ($meta[1] === 'tri') {
                $disp = ($rawV === '0') ? 'Đang Tắt' : 'Đang Bật';
            } elseif ($meta[1] === 'bool') {
                $disp = ($rawV === '1') ? 'Đang Bật' : 'Đang Tắt';
            } else {
                $disp = ($rawV === '') ? 'Mặc định' : bv($rawV, 16);
            }
            $kbd[] = [btn($meta[0] . ' · [' . $disp . ']', cbd('cfk', $ck, $sub, $page))];
        }
        if ($pages > 1) {
            $kbd[] = pager_row('cf', [$sub], $page, $pages);
        }
        $kbd[] = [btn('⬅ Nhóm cài đặt', 'cf|menu'), btn('🏠 Menu chính', 'home')];
        return ui_send($t, kb($kbd));
    }

    // Fallback 'all': show every site_config row with Vietnamese explanation
    $per   = 8;
    $total = (int) bq_val("SELECT COUNT(*) FROM site_config WHERE config_key <> 'tg_emoji_vault'");
    $pages = max(1, (int) ceil($total / $per));
    $page  = max(0, min((int) $page, $pages - 1));
    $rows  = bq_all("SELECT config_key, config_value FROM site_config WHERE config_key <> 'tg_emoji_vault' ORDER BY config_key ASC LIMIT " . $per . ' OFFSET ' . ($page * $per));
    $kbd   = [[btn('➕ Thêm mục cài đặt mới', 'cn')]];
    foreach ($rows as $r) {
        list(, $viLabel) = bot_vi_cfg_lookup($r['config_key']);
        $val   = bt_secret('config_value', $r, 'site_config') ? '••••' : ($r['config_value'] === '' ? 'Trống' : bv($r['config_value'], 16));
        $kbd[] = [btn(tg_clip($viLabel, 34) . ' = ' . $val, cbd('cfk', $r['config_key'], 'all', $page))];
    }
    if ($pages > 1) {
        $kbd[] = pager_row('cf', ['all'], $page, $pages);
    }
    $kbd[] = [btn('⬅ Nhóm cài đặt', 'cf|menu'), btn('🏠 Menu chính', 'home')];
    return ui_send('📋 <b>TẤT CẢ MỤC CÀI ĐẶT TRONG CƠ SỞ DỮ LIỆU</b> · ' . $total . ' mục', kb($kbd));
}

function screen_cfg_item($key, $backGroup = 'menu', $backPage = 0, $note = '')
{
    $key = trim((string) $key);
    list($gid, $viLabel, $kind, $viDesc) = bot_vi_cfg_lookup($key);
    if ($backGroup === '') {
        $backGroup = $gid;
    }
    $row  = bq_row('SELECT config_key, config_value FROM site_config WHERE config_key = ? LIMIT 1', 's', [$key]);
    $curV = $row ? (string) $row['config_value'] : '';
    $shown = ($row && bt_secret('config_value', $row, 'site_config')) ? '••••••••' : ($curV === '' ? '(Đang để trống — dùng chuẩn mặc định)' : $curV);

    $t = '⚙️ <b>' . tg_e($viLabel) . '</b>' . "\n";
    if ($note !== '') {
        $t .= '<blockquote>' . $note . "</blockquote>\n";
    }
    $t .= '<blockquote>'
        . '📌 Giải thích: ' . tg_e($viDesc) . "\n"
        . '🔑 Mã hệ thống: <code>' . tg_e($key) . "</code>\n"
        . '💾 Giá trị hiện tại: <code>' . tg_e(tg_clip($shown, 350)) . '</code>'
        . '</blockquote>' . "\n"
        . '<i>💡 Bấm nút bên dưới để đổi nhanh hoặc nhập giá trị mới (nếu bạn gõ tiếng Anh, hệ thống sẽ lưu nguyên văn tiếng Anh 100%).</i>';

    $kbd = [];
    if ($kind === 'img_logo') {
        $kbd[] = [btn('🖼 Tải ảnh lên / Dán link Logo mới', 'ui|ask_logo', 'primary')];
    } elseif ($kind === 'img_fav') {
        $kbd[] = [btn('🔖 Tải ảnh lên / Dán link Icon Tab mới', 'ui|ask_fav', 'primary')];
    } elseif ($kind === 'img_av') {
        $kbd[] = [btn('👤 Tải ảnh lên / Dán link Ảnh đại diện', 'ui|ask_av', 'primary')];
    } elseif ($kind === 'img_hero') {
        $kbd[] = [btn('🏞 Tải ảnh lên / Thêm link Banner trượt', 'ui|ask_hero_add', 'primary')];
    }

    if ($kind === 'tri') {
        $isOn = ($curV !== '0');
        $kbd[] = [
            btn($isOn ? '🟢 Đang BẬT' : '🟢 Bật tính năng này', cbd('cfs', $key, '1', $backGroup, $backPage), $isOn ? 'success' : ''),
            btn(!$isOn ? '🔴 Đang TẮT' : '🔴 Tắt tính năng này', cbd('cfs', $key, '0', $backGroup, $backPage), !$isOn ? 'danger' : ''),
        ];
    } elseif ($kind === 'bool') {
        $isOn = ($curV === '1');
        $kbd[] = [
            btn($isOn ? '🟢 Đang BẬT' : '🟢 Bật tính năng này', cbd('cfs', $key, '1', $backGroup, $backPage), $isOn ? 'success' : ''),
            btn(!$isOn ? '🔴 Đang TẮT' : '🔴 Tắt tính năng này', cbd('cfs', $key, '0', $backGroup, $backPage), !$isOn ? 'danger' : ''),
        ];
    } elseif (strpos($kind, 'select:') === 0) {
        $opts = explode(',', substr($kind, 7));
        $optBtns = [];
        $viOpt = ['circle' => '⭕ Tròn', 'rounded' => '▢ Bo góc', 'square' => '⬜ Vuông'];
        foreach ($opts as $op) {
            $lbl = isset($viOpt[$op]) ? $viOpt[$op] : $op;
            $optBtns[] = btn(($curV === $op ? '✓ ' : '') . $lbl, cbd('cfs', $key, $op, $backGroup, $backPage), $curV === $op ? 'primary' : '');
        }
        $kbd[] = $optBtns;
    }

    $kbd[] = [
        btn('✏️ Nhập giá trị mới (Việt / Anh)', cbd('cfe', $key, $backGroup, $backPage), 'primary'),
        btn('♻️ Đặt về mặc định', cbd('cfs', $key, '__EMPTY__', $backGroup, $backPage)),
    ];
    $kbd[] = [btn('⬅ Quay lại danh sách', 'cf|' . $backGroup . '|' . (int) $backPage), btn('🏠 Menu chính', 'home')];
    return ui_send($t, kb($kbd));
}

function screen_db()
{
    $rows = bq_all('SELECT TABLE_NAME AS t, TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME');
    $kbd  = [[btn('🧮 Mở Bảng Lệnh SQL', 'sq'), btn('📜 Xem Nhật Ký Hệ Thống', 'lg')]];
    $line = [];
    foreach ($rows as $r) {
        $viT    = bt_vi_table($r['t']);
        $line[] = btn('🗄 ' . tg_clip($viT, 22) . ' (' . ($r['n'] === null ? '?' : '~' . $r['n']) . ')', cbd('dt', $r['t'], 0));
        if (count($line) === 2) {
            $kbd[] = $line;
            $line  = [];
        }
    }
    if ($line) {
        $kbd[] = $line;
    }
    $kbd[] = home_row();
    ui_send('🗄 <b>QUẢN LÝ CƠ SỞ DỮ LIỆU &amp; TRUY VẤN SQL</b> · ' . count($rows) . ' bảng', kb($kbd));
}

function screen_table($table, $page)
{
    if (!bt_valid($table)) {
        return ui_error('Bảng không tồn tại', 'db');
    }
    $pk    = bt_pk($table);
    $per   = 8;
    $total = (int) bq_val('SELECT COUNT(*) FROM `' . $table . '`');
    $pages = max(1, (int) ceil($total / $per));
    $page  = max(0, min((int) $page, $pages - 1));
    $dir   = $table === 'site_config' ? 'ASC' : 'DESC';
    $rows  = bq_all('SELECT * FROM `' . $table . '` ORDER BY ' . ($pk !== null ? '`' . $pk . '` ' . $dir : '1') . ' LIMIT ' . $per . ' OFFSET ' . ($page * $per));

    $kbd = [];
    $txt = '';
    foreach ($rows as $r) {
        if ($pk !== null) {
            $label = '#' . $r[$pk];
            foreach (['username', 'key_code', 'name', 'action', 'violation_type', 'config_key', 'ip'] as $c) {
                if (isset($r[$c]) && $c !== $pk) {
                    $label .= ' · ' . tg_clip($r[$c], 26);
                    break;
                }
            }
            $kbd[] = [btn('📄 ' . $label, cbd('dr', $table, $r[$pk]))];
        } else {
            $parts = [];
            foreach ($r as $c => $v) {
                $parts[] = $c . '=' . bv($v, 24);
            }
            $txt .= '• ' . tg_e(tg_clip(implode(' · ', $parts), 150)) . "\n";
        }
    }
    if ($pages > 1) {
        $kbd[] = pager_row('dt', [$table], $page, $pages);
    }
    $kbd[] = [btn('🗄 Danh sách bảng', 'db'), btn('🏠 Menu chính', 'home')];
    ui_send('🗄 <b>' . tg_e($table) . '</b> · ' . $total . " dòng\n" . ($txt !== '' ? $txt : ''), kb($kbd));
}

function screen_logs_menu()
{
    ui_send('📜 <b>NHẬT KÝ HỆ THỐNG WEBSITE</b>', kb([
        [btn('👑 Thao tác Quản trị', 'lt|admin_logs|0'), btn('👁 Hoạt động Website', 'ac|a|0')],
        [btn('🚫 Nhập sai mã Key', 'lt|key_attempts|0'), btn('🔐 Đăng nhập sai', 'lt|login_attempts|0')],
        [btn('🧮 Lịch sử lệnh SQL', 'lt|sql|0')],
        [btn('⬅ Cơ sở dữ liệu', 'db'), btn('🏠 Menu chính', 'home')],
    ]));
}

function screen_log($table, $page)
{
    if ($table === 'sql') {
        $s = tg_store_read('sqlhist');
        $t = '🧮 <b>LỊCH SỬ LỆNH SQL</b>' . "\n" . hr() . "\n";
        foreach (array_slice(isset($s['h']) ? $s['h'] : [], 0, 8) as $h) {
            $t .= '<i>' . tg_e($h['t']) . '</i> · ' . tg_e($h['r']) . "\n<code>" . tg_e(tg_clip($h['q'], 160)) . "</code>\n";
        }
        if (empty($s['h'])) {
            $t .= 'Chưa có lịch sử.';
        }
        return ui_send($t, kb([[btn('📜 Nhật ký', 'lg'), btn('🏠 Menu chính', 'home')]]));
    }
    $def = [
        'admin_logs'     => ['created_at', 'username', 'action', 'detail'],
        'security_logs'  => ['created_at', 'ip_address', 'violation_type', 'user_agent'],
        'key_attempts'   => ['created_at', 'username', 'ip'],
        'login_attempts' => ['created_at', 'username', 'ip'],
    ];
    if (!isset($def[$table]) || !bt_valid($table)) {
        return ui_error('Bảng nhật ký chưa có dữ liệu', 'lg');
    }
    $per   = 8;
    $total = (int) bq_val('SELECT COUNT(*) FROM `' . $table . '`');
    $pages = max(1, (int) ceil($total / $per));
    $page  = max(0, min((int) $page, $pages - 1));
    $rows  = bq_all('SELECT * FROM `' . $table . '` ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . ($page * $per));
    $t     = '📜 <b>' . tg_e($table) . '</b> · ' . $total . " dòng\n" . hr() . "\n";
    foreach ($rows as $r) {
        $t .= '<b>#' . $r['id'] . '</b> <i>' . tg_e(isset($r[$def[$table][0]]) ? $r[$def[$table][0]] : '') . "</i>\n";
        $parts = [];
        foreach (array_slice($def[$table], 1) as $c) {
            if (isset($r[$c]) && $r[$c] !== '') {
                $parts[] = bv($r[$c], 90);
            }
        }
        $t .= '   ' . tg_e(implode(' · ', $parts)) . "\n";
    }
    if (!$rows) {
        $t .= 'Trống.';
    }
    $kbd = [];
    if ($pages > 1) {
        $kbd[] = pager_row('lt', [$table], $page, $pages);
    }
    $kbd[] = [btn('📜 Nhật ký', 'lg'), btn('🏠 Menu chính', 'home')];
    ui_send($t, kb($kbd));
}

function screen_health()
{
    $t0    = microtime(true);
    $dbv   = (string) bq_val('SELECT VERSION()', '', [], '?');
    $ms    = (int) round((microtime(true) - $t0) * 1000);
    $me    = tg_api('getMe');
    $wh    = tg_api('getWebhookInfo');
    $info  = isset($wh['result']) && is_array($wh['result']) ? $wh['result'] : [];
    $dir   = tg_dir();
    $vault = tg_emoji_vault_read();
    $ok    = function ($b) { return $b ? '✅' : '❌'; };

    $t  = '🩺 <b>SỨC KHOẺ HỆ THỐNG & BOT</b>' . "\n" . hr() . "\n<blockquote>";
    $t .= $ok(true) . ' PHP: <code>' . tg_e(PHP_VERSION) . "</code>\n";
    $t .= $ok($dbv !== '?') . ' MySQL: <code>' . tg_e(tg_clip($dbv, 28)) . '</code> (' . $ms . " ms)\n";
    $t .= $ok(!empty($me['ok'])) . ' Bot: ' . (!empty($me['ok']) ? '@' . tg_e(isset($me['result']['username']) ? $me['result']['username'] : '?') : 'lỗi kết nối') . "\n";
    $t .= $ok(!empty($info['url'])) . ' Webhook: ' . (!empty($info['url']) ? 'Đã kết nối (chờ: ' . (int) (isset($info['pending_update_count']) ? $info['pending_update_count'] : 0) . ')' : 'Chưa gắn (?setup=)') . "\n";
    $t .= $ok(defined('SH_SHIELD_LOADED')) . " Khiên Chống DDoS: Đã kích hoạt (0% chặn nhầm)\n";
    $t .= $ok(!empty($vault['picks']) || !empty($vault['default_id'])) . ' Kho Emoji Vĩnh Viễn: ' . count($vault['picks']) . " icon đã lưu MySQL\n";
    $t .= $ok(is_writable($dir)) . ' Bộ nhớ đệm Cache: Ghi tốt';
    $t .= "</blockquote>\n<i>🕒 " . tg_e(tg_now()) . '</i>';
    ui_send($t, kb([[btn('🔔 Gửi thử thông báo', 'ht'), btn('🔄 Làm mới', 'hc')], home_row()]));
}

/* ------------------------------------------------------------------
 * 9. Alerts & Command Auto-Delete Timer Settings
 * ------------------------------------------------------------------ */

function screen_alerts()
{
    $adSec = tg_autodelete_ttl();
    $kbd   = [];
    $line  = [];
    foreach (tg_categories() as $code => $def) {
        $on     = tg_cat_on($code);
        $line[] = btn($def[0] . ($on ? ' ✓' : ' ✗'), 'at|' . $code, $on ? 'success' : 'danger');
        if (count($line) === 2) {
            $kbd[] = $line;
            $line  = [];
        }
    }
    if ($line) {
        $kbd[] = $line;
    }
    $kbd[] = [
        btn(($adSec === 30 ? '• ' : '') . '⏱ 30s', 'ad|30', $adSec === 30 ? 'primary' : ''),
        btn(($adSec === 60 ? '• ' : '') . '⏱ 60s', 'ad|60', $adSec === 60 ? 'primary' : ''),
        btn(($adSec === 120 ? '• ' : '') . '⏱ 120s', 'ad|120', $adSec === 120 ? 'primary' : ''),
        btn(($adSec === 300 ? '• ' : '') . '⏱ 300s', 'ad|300', $adSec === 300 ? 'primary' : ''),
    ];
    $kbd[] = [btn($adSec === 0 ? '🔴 Tự xoá lệnh: ĐANG TẮT (Bấm để bật 120s)' : '🟢 Tự xoá lệnh: ' . $adSec . 's (Thông báo KHÔNG BAO GIỜ xoá)', $adSec === 0 ? 'ad|120' : 'ad|0')];
    $kbd[] = home_row();

    $t = '🔔 <b>CÀI ĐẶT THÔNG BÁO & HẸN GIỜ TỰ XOÁ LỆNH</b>' . "\n"
        . '<blockquote>'
        . '📌 <b>Quy tắc bảo toàn:</b> Mọi thông báo từ website (Đăng ký, Get Key, Nhập Key, Bấm Sản Phẩm, Chống DDoS...) <b>tuyệt đối không bao giờ tự xoá</b>.' . "\n"
        . '⏱ <b>Tự xoá lệnh Bot:</b> Khi Admin gõ lệnh hoặc mở bảng điều khiển, tin nhắn lệnh sẽ tự động dọn sạch sau <b>' . ($adSec > 0 ? $adSec . ' giây' : 'Tắt') . '</b>.'
        . '</blockquote>';
    ui_send($t, kb($kbd));
}

/* ------------------------------------------------------------------
 * 10. Row Viewer & Editor (Users, Keys, Products, Config)
 * ------------------------------------------------------------------ */

function bot_back_for($table)
{
    $map = ['users' => cbd('us', '', 0), 'keys_free' => 'kl|FREE|0|0', 'keys_vip' => 'kl|VIP|0|0', 'products' => 'pr|0', 'site_config' => 'cf|menu'];
    return isset($map[$table]) ? $map[$table] : cbd('dt', $table, 0);
}

function bot_row_view($table, $pkv, $note = '')
{
    if ($table === 'site_config') {
        return screen_cfg_item($pkv, '', 0, $note);
    }
    if (!bt_valid($table)) {
        return ui_error('Bảng không tồn tại', 'db');
    }
    $pk = bt_pk($table);
    if ($pk === null) {
        return ui_error('Bảng này chỉ hỗ trợ xem danh sách', cbd('dt', $table, 0));
    }
    $row = bq_row('SELECT * FROM `' . $table . '` WHERE `' . $pk . '` = ? LIMIT 1', 's', [(string) $pkv]);
    if (!$row) {
        return ui_error('Không tìm thấy dữ liệu', bot_back_for($table));
    }

    $t = '📄 <b>' . tg_e(bt_vi_table($table)) . '</b> · <code>#' . tg_e($pkv) . '</code>' . "\n";
    if ($note !== '') {
        $t .= $note . "\n";
    }
    $fields = '';
    foreach ($row as $col => $val) {
        $shown   = bt_secret($col, $row, $table) ? '••••••••' : bv($val, 200);
        $fields .= '<b>' . tg_e(bt_vi_col($col)) . ':</b> <code>' . tg_e($shown) . "</code>\n";
    }
    $t .= '<blockquote>' . rtrim($fields) . "</blockquote>\n";

    $kbd = [];
    if ($table === 'users') {
        $active = (int) bq_val('SELECT IF(expiry_date > NOW(), 1, 0) FROM users WHERE id = ?', 'i', [(int) $pkv]);
        $blk    = tg_user_block_row((string) $row['username']);
        $t .= '🔎 <b>Key hiện tại:</b> ' . ($active ? '🟢 Đang chạy (' . tg_e($row['key_type']) . ' · ' . (int) $row['turns_left'] . ' lượt)' : '⚪ Chưa có / hết hạn') . "\n";
        $t .= '⛔ <b>Tài khoản:</b> ' . ($blk ? '🔴 ĐANG KHOÁ (' . tg_e(tg_clip($blk['reason'], 60)) . ')' : '🟢 Bình thường');
        $id = (int) $pkv;
        $kbd[] = [btn('🎁 Cấp Free 24h', 'ua|' . $id . '|free'), btn('💎 Cấp VIP 30 ngày', 'ua|' . $id . '|vip')];
        $kbd[] = [btn('➕ Cộng 1 lượt', 'ua|' . $id . '|t+'), btn('➖ Trừ 1 lượt', 'ua|' . $id . '|t-')];
        $kbd[] = [btn('🔓 Mở khoá nhận Key', cbd('uk', 'run', (string) $row['username'])), btn('🗑 Xoá lượt về 0', 'ua|' . $id . '|clear')];
        $kbd[] = [btn('⛔ Thu hồi Key', 'ua|' . $id . '|revoke'), btn('🔑 Đổi mật khẩu', 'ua|' . $id . '|pw')];
        $kbd[] = [btn('🚫 Xem & Chặn IP', 'bu|' . $id), btn('👑 Quyền Quản trị', 'ua|' . $id . '|role')];
        $kbd[] = [$blk ? btn('✅ Mở khoá tài khoản', 'uu|' . $id) : btn('⛔ Khoá tài khoản', 'ub|' . $id)];
    } elseif ($table === 'keys_free' || $table === 'keys_vip') {
        $kbd[] = [(int) $row['status'] === 0
            ? btn('🚫 Huỷ hiệu lực Key này', 'kx|' . $table . '|' . (int) $pkv . '|void')
            : btn('♻️ Khôi phục thành chưa dùng', 'kx|' . $table . '|' . (int) $pkv . '|restore')];
    } elseif ($table === 'products') {
        $meta   = bot_tool_meta_load();
        $pMeta  = isset($meta[(string) (int) $pkv]) && is_array($meta[(string) (int) $pkv]) ? $meta[(string) (int) $pkv] : [];
        $isM    = !empty($pMeta['maint']);
        $curPkg = !empty($pMeta['pkg']) ? (int) $pMeta['pkg'] : 0;
        $t     .= '📦 <b>Gói hiển thị trên trang chủ:</b> ' . ($curPkg > 0 ? '<b>Gói số ' . $curPkg . '</b>' : '<i>Tự động theo loại sản phẩm</i>');
        $kbd[] = [
            btn('🔁 Đổi Miễn phí ⇄ VIP', 'px|' . (int) $pkv),
            btn($isM ? '🟢 Tắt bảo trì riêng' : '🛠 Bật bảo trì riêng', 'pm|' . (int) $pkv, $isM ? 'success' : 'danger'),
        ];
        $kbd[] = [
            btn(($curPkg === 1 ? '✓ ' : '') . '📦 Gói 1', 'ppk|' . (int) $pkv . '|1'),
            btn(($curPkg === 2 ? '✓ ' : '') . '📦 Gói 2', 'ppk|' . (int) $pkv . '|2'),
            btn(($curPkg === 3 ? '✓ ' : '') . '📦 Gói 3', 'ppk|' . (int) $pkv . '|3'),
            btn($curPkg === 0 ? '✓ Tự động' : '♻️ Tự động', 'ppk|' . (int) $pkv . '|0'),
        ];
        $kbd[] = [btn('🖼 Tải ảnh mới cho sản phẩm này', 'pim|' . (int) $pkv, 'primary')];
    }

    $line = [];
    foreach ($row as $col => $val) {
        if ($col === $pk) {
            continue;
        }
        $line[] = btn('✏️ ' . tg_clip(bt_vi_col($col), 20), cbd('de', $table, $pkv, $col), '');
        if (count($line) === 2) {
            $kbd[] = $line;
            $line  = [];
        }
    }
    if ($line) {
        $kbd[] = $line;
    }
    if (tg_cfg('allow_destructive')) {
        $kbd[] = [btn('🗑 Xoá mục này', cbd('dd', $table, $pkv))];
    }
    $kbd[] = [btn('⬅ Quay lại', bot_back_for($table)), btn('🏠 Menu chính', 'home')];
    ui_send($t, kb($kbd));
}

function bot_edit_prompt($table, $pkv, $col)
{
    $pk   = bt_valid($table) ? bt_pk($table) : null;
    $cols = bt_cols($table);
    if ($pk === null || !isset($cols[$col]) || $col === $pk) {
        return ui_error('Không thể sửa mục này', 'db');
    }
    $row = bq_row('SELECT * FROM `' . $table . '` WHERE `' . $pk . '` = ? LIMIT 1', 's', [(string) $pkv]);
    if (!$row) {
        return ui_error('Không tìm thấy dữ liệu', bot_back_for($table));
    }
    conv_set($GLOBALS['CTX']['admin'], 'edit', ['t' => $table, 'pk' => (string) $pkv, 'c' => $col]);
    $cur = bt_secret($col, $row, $table) ? '••••••••' : bv($row[$col], 300);
    ui_send('✏️ <b>CHỈNH SỬA: ' . tg_e(bt_vi_col($col)) . '</b>' . "\n" . hr() . "\n"
        . 'Giá trị hiện tại: <code>' . tg_e($cur) . "</code>\n\n"
        . "Hãy gửi giá trị mới vào khung chat (nhập tiếng Anh hay tiếng Việt đều được giữ nguyên chính xác 100%):\n• Gõ <code>RONG</code> nếu muốn đặt trống\n• Gõ <code>HIENTAI</code> cho cột ngày giờ",
        kb([[btn('✖ Huỷ bỏ', 'cx')]]));
}

function bot_apply_edit($table, $pkv, $col, $text)
{
    $cols = bt_cols($table);
    $pk   = bt_valid($table) ? bt_pk($table) : null;
    if ($pk === null || !isset($cols[$col]) || $col === $pk) {
        return ui_error('Không thể sửa cột này', 'db');
    }
    $row = bq_row('SELECT `' . $col . '` AS v FROM `' . $table . '` WHERE `' . $pk . '` = ? LIMIT 1', 's', [(string) $pkv]);
    if (!$row) {
        return ui_error('Không tìm thấy dòng', bot_back_for($table));
    }
    $type   = strtolower($cols[$col]['Type']);
    $upper  = strtoupper(trim($text));
    $val    = $text;
    $useNow = false;

    if ($upper === 'NULL' || $upper === 'RONG') {
        if ($cols[$col]['Null'] !== 'YES') {
            conv_set($GLOBALS['CTX']['admin'], 'edit', ['t' => $table, 'pk' => (string) $pkv, 'c' => $col]);
            return ui_send('❌ Mục này không cho phép để rỗng. Hãy gửi giá trị khác.', kb([[btn('✖ Huỷ bỏ', 'cx')]]));
        }
        $val = null;
    } elseif (($upper === 'NOW' || $upper === 'HIENTAI') && preg_match('/date|time/', $type)) {
        $useNow = true;
    } elseif (preg_match('/^(tiny|small|medium|big)?int/', $type) && !preg_match('/^-?\d+$/', trim($text))) {
        conv_set($GLOBALS['CTX']['admin'], 'edit', ['t' => $table, 'pk' => (string) $pkv, 'c' => $col]);
        return ui_send('❌ Cột này yêu cầu số nguyên. Hãy gửi lại.', kb([[btn('✖ Huỷ', 'cx')]]));
    } elseif ($table === 'users' && $col === 'password' && strpos($text, '$2y$') !== 0) {
        $val = password_hash($text, PASSWORD_DEFAULT);
    }

    if ($useNow) {
        bq_exec('UPDATE `' . $table . '` SET `' . $col . '` = NOW() WHERE `' . $pk . '` = ? LIMIT 1', 's', [(string) $pkv]);
    } else {
        bq_exec('UPDATE `' . $table . '` SET `' . $col . '` = ? WHERE `' . $pk . '` = ? LIMIT 1', 'ss', [$val, (string) $pkv]);
    }

    $secret = bt_secret($col, null, $table);
    bot_log('tg_edit', $table . '#' . $pkv . '.' . $col . ': ' . ($secret ? '(ẩn)' : bv($val, 60)));
    $undo = bot_stash(['t' => $table, 'pk' => (string) $pkv, 'c' => $col, 'old' => $row['v']]);
    toast('✅ Đã lưu thay đổi');
    ui_send('✅ <b>ĐÃ CẬP NHẬT</b> · <code>' . tg_e($table . '.' . $col) . '</code>',
        kb([[btn('↩ Hoàn tác', 'un|' . $undo), btn('📄 Xem lại dòng', cbd('dr', $table, $pkv))], home_row()]));
}

function bot_undo($stashId)
{
    $p = bot_unstash($stashId);
    if (!is_array($p) || !isset($p['t'], $p['pk'], $p['c']) || !bt_valid($p['t'])) {
        return ui_error('Dữ liệu hoàn tác đã hết hạn', 'home');
    }
    $pk = bt_pk($p['t']);
    if ($pk === null || !isset(bt_cols($p['t'])[$p['c']])) {
        return ui_error('Không thể hoàn tác', 'home');
    }
    bq_exec('UPDATE `' . $p['t'] . '` SET `' . $p['c'] . '` = ? WHERE `' . $pk . '` = ? LIMIT 1', 'ss', [$p['old'], (string) $p['pk']]);
    bot_log('tg_undo', $p['t'] . '#' . $p['pk'] . '.' . $p['c']);
    toast('↩ Đã khôi phục giá trị cũ');
    bot_row_view($p['t'], $p['pk'], '↩ <b>Đã khôi phục giá trị trước đó</b>');
}

function bot_delete_row($table, $pkv, $confirmed)
{
    if (!tg_cfg('allow_destructive')) {
        return ui_error('Chế độ bảo vệ dữ liệu đang khoá lệnh xoá', 'home');
    }
    $pk = bt_valid($table) ? bt_pk($table) : null;
    if ($pk === null) {
        return ui_error('Không hỗ trợ xoá bảng này', 'home');
    }
    if (!$confirmed) {
        return ui_send('🗑 <b>XÁC NHẬN XOÁ DÒNG?</b>' . "\n<code>" . tg_e($table . '.' . $pk . ' = ' . $pkv) . '</code>',
            kb([[btn('🗑 Đồng ý xoá', cbd('dd', $table, $pkv, 'ok')), btn('✖ Huỷ', cbd('dr', $table, $pkv))]]));
    }
    $row = bq_row('SELECT * FROM `' . $table . '` WHERE `' . $pk . '` = ? LIMIT 1', 's', [(string) $pkv]);
    if ($row) {
        tg_store_update('trash', function ($s) use ($table, $row) {
            $s['rows'][] = ['t' => tg_now(), 'table' => $table, 'row' => $row];
            $s['rows']   = array_slice($s['rows'], -200);
            return $s;
        });
        bq_exec('DELETE FROM `' . $table . '` WHERE `' . $pk . '` = ? LIMIT 1', 's', [(string) $pkv]);
        bot_log('tg_delete', $table . '#' . $pkv);
    }
    toast('🗑 Đã xoá');
    ui_send('🗑 <b>Đã xoá</b> · <code>' . tg_e($table . '#' . $pkv) . '</code>', kb([[btn('⬅ Quay lại', bot_back_for($table)), btn('🏠 Menu chính', 'home')]]));
}

function bot_user_action($id, $act, $step)
{
    $u = bq_row('SELECT id, username, role FROM users WHERE id = ? LIMIT 1', 'i', [$id]);
    if (!$u) {
        return ui_error('Không tìm thấy thành viên', cbd('us', '', 0));
    }
    $name = $u['username'];
    $msg  = '';
    switch ($act) {
        case 'free':
            bq_exec("UPDATE users SET key_type = 'FREE', expiry_date = DATE_ADD(NOW(), INTERVAL 24 HOUR), turns_left = 2, alert_msg = NULL, vip_free_clicks = 0 WHERE id = ?", 'i', [$id]);
            $msg = 'Đã cấp Key FREE (24h · 2 lượt)';
            break;
        case 'vip':
            bq_exec("UPDATE users SET key_type = 'VIP', expiry_date = DATE_ADD(NOW(), INTERVAL 720 HOUR), turns_left = 1, alert_msg = NULL, vip_free_clicks = 0 WHERE id = ?", 'i', [$id]);
            $msg = 'Đã cấp Key VIP (30 ngày · 1 lượt)';
            break;
        case 'revoke':
            bq_exec("UPDATE users SET key_type = 'NONE', expiry_date = '2000-01-01 00:00:00', turns_left = NULL, alert_msg = ? WHERE id = ?", 'si', ['Key của bạn đã bị Admin thu hồi.', $id]);
            $msg = 'Đã thu hồi Key';
            break;
        case 'clear':
            if ($step !== 'ok') {
                return ui_send('🗑 <b>XOÁ LƯỢT CỦA</b> <code>' . tg_e($name) . "</code>?\nThành viên sẽ về 0 lượt (không xoá tài khoản).",
                    kb([[btn('✅ Xác nhận xoá lượt', 'ua|' . $id . '|clear|ok'), btn('✖ Huỷ', cbd('dr', 'users', $id))]]));
            }
            bq_exec("UPDATE users SET key_type = 'NONE', expiry_date = NULL, turns_left = NULL, vip_free_clicks = 0 WHERE id = ?", 'i', [$id]);
            $msg = 'Đã xoá lượt về 0';
            break;
        case 't+':
            $r   = bq_exec('UPDATE users SET turns_left = COALESCE(turns_left, 1) + 1 WHERE id = ? AND expiry_date > NOW()', 'i', [$id]);
            $msg = $r['n'] > 0 ? 'Đã cộng +1 lượt' : 'Tài khoản chưa có Key còn hạn';
            break;
        case 't-':
            $r   = bq_exec('UPDATE users SET turns_left = COALESCE(turns_left, 1) - 1 WHERE id = ? AND expiry_date > NOW() AND COALESCE(turns_left, 1) > 1', 'i', [$id]);
            $msg = $r['n'] > 0 ? 'Đã trừ −1 lượt' : 'Cần Key đang chạy từ 2 lượt trở lên';
            break;
        case 'role':
            if ($step !== 'ok') {
                return ui_send('👑 <b>ĐỔI QUYỀN ADMIN?</b>' . "\n<code>" . tg_e($name) . '</code> hiện là ' . ((int) $u['role'] === 1 ? '<b>Admin</b> → chuyển về Thành viên?' : '<b>Thành viên</b> → nâng lên Admin?'),
                    kb([[btn('✅ Đồng ý', 'ua|' . $id . '|role|ok'), btn('✖ Huỷ', cbd('dr', 'users', $id))]]));
            }
            if ((int) $u['role'] === 1 && (int) bq_val('SELECT COUNT(*) FROM users WHERE role = 1') <= 1) {
                return ui_error('Không thể hạ quyền tài khoản Admin cuối cùng', cbd('dr', 'users', $id));
            }
            bq_exec('UPDATE users SET role = IF(role = 1, 0, 1) WHERE id = ?', 'i', [$id]);
            $msg = 'Đã cập nhật quyền Admin';
            break;
        case 'pw':
            conv_set($GLOBALS['CTX']['admin'], 'pw', ['id' => $id]);
            return ui_send('🔑 <b>ĐẶT MẬT KHẨU MỚI</b> cho <code>' . tg_e($name) . "</code>\nGửi mật khẩu mới (tối thiểu 6 ký tự — tin nhắn mật khẩu sẽ được xoá ngay lập tức):", kb([[btn('✖ Huỷ', 'cx')]]));
        default:
            return bot_row_view('users', $id);
    }
    bot_log('tg_user', $name . ': ' . $msg);
    toast('✅ ' . $msg);
    bot_row_view('users', $id, '✅ <b>' . tg_e($msg) . '</b>');
}

function bot_key_action($table, $id, $act)
{
    if (!in_array($table, ['keys_free', 'keys_vip'], true)) {
        return ui_error('Bảng Key không hợp lệ', 'ky');
    }
    if ($act === 'void') {
        bq_exec('UPDATE `' . $table . '` SET status = 1 WHERE id = ?', 'i', [$id]);
        $msg = 'Đã huỷ hiệu lực mã Key';
    } else {
        bq_exec('UPDATE `' . $table . '` SET status = 0, used_by = NULL, used_at = NULL WHERE id = ?', 'i', [$id]);
        $msg = 'Đã khôi phục mã Key thành chưa dùng';
    }
    bot_log('tg_key', $table . '#' . $id . ': ' . $msg);
    toast('✅ ' . $msg);
    bot_row_view($table, $id, '✅ <b>' . tg_e($msg) . '</b>');
}

function bot_product_swap($id)
{
    bq_exec("UPDATE products SET type = IF(UPPER(type) = 'VIP', 'FREE', 'VIP') WHERE id = ?", 'i', [$id]);
    bot_log('tg_product', 'products#' . $id . ': đổi loại FREE/VIP');
    toast('✅ Đã đổi loại sản phẩm');
    bot_row_view('products', $id, '✅ <b>Đã chuyển đổi loại FREE ⇄ VIP</b>');
}

function bot_product_add_finish($type)
{
    $admin = $GLOBALS['CTX']['admin'];
    $conv  = conv_get($admin);
    if (!$conv || $conv['mode'] !== 'pt') {
        return ui_error('Hãy bắt đầu lại bằng nút ➕ Thêm sản phẩm', 'pr|0');
    }
    $d    = $conv['data'];
    $type = strtoupper((string) $type) === 'VIP' ? 'VIP' : 'FREE';
    $r    = bq_exec('INSERT INTO products (name, link, image, type) VALUES (?, ?, ?, ?)', 'ssss', [$d['name'], $d['link'], $d['image'], $type]);
    conv_clear($admin);
    bot_log('add_product', 'Thêm tool ' . $d['name'] . ' (' . $type . ') [Telegram]');
    toast('✅ Đã thêm sản phẩm');
    bot_row_view('products', $r['id'], '✅ <b>Đã thêm sản phẩm mới</b>');
}

function bot_config_toggle($key)
{
    if ($key === 'maintenance_mode') {
        return bot_maintenance('menu');
    }
    $cur = (string) bq_val('SELECT config_value FROM site_config WHERE config_key = ?', 's', [$key], '');
    if ($cur !== '0' && $cur !== '1') {
        return ui_error('Chỉ hỗ trợ đảo nhanh giá trị 0 / 1', 'cf|0');
    }
    $new = $cur === '1' ? '0' : '1';
    bot_set_config($key, $new);
    bot_log('tg_config', $key . ': ' . $cur . ' → ' . $new);
    toast('✅ ' . $key . ' = ' . $new);
    bot_row_view('site_config', $key, '✅ Giá trị mới: <b>' . $new . '</b>');
}

/* ------------------------------------------------------------------
 * 11. SQL Console
 * ------------------------------------------------------------------ */

function sql_verb($sql)
{
    return strtolower((string) strtok(ltrim($sql, "( \t\r\n"), " \t\r\n("));
}

function sql_hist($sql, $result)
{
    $admin = $GLOBALS['CTX']['admin'];
    tg_store_update('sqlhist', function ($s) use ($sql, $result, $admin) {
        $h = isset($s['h']) && is_array($s['h']) ? $s['h'] : [];
        array_unshift($h, ['t' => tg_now(), 'a' => $admin, 'q' => tg_clip($sql, 400), 'r' => $result]);
        $s['h'] = array_slice($h, 0, 50);
        return $s;
    });
}

function screen_sql_help()
{
    ui_send('🧮 <b>BẢNG ĐIỀU KHIỂN SQL</b>' . "\n" . hr() . "\n"
        . "Gõ <code>/sql SELECT ...</code> hoặc bấm nút bên dưới để chạy truy vấn an toàn.\n"
        . "• <b>SELECT / SHOW</b> chạy trực tiếp\n"
        . "• <b>INSERT / UPDATE</b> có bước xác nhận trước khi ghi\n"
        . "• Mật khẩu & mã bí mật tự động ẩn", kb([
        [btn('✍️ Nhập lệnh SQL', 'sw')],
        [btn('📋 DS Bảng', cbd('sr', bot_stash(['q' => 'SHOW TABLES']))), btn('👥 8 User mới', cbd('sr', bot_stash(['q' => 'SELECT id, username, role, key_type FROM users ORDER BY id DESC LIMIT 8'])))],
        [btn('⬅ Database', 'db'), btn('🏠 Menu chính', 'home')],
    ]));
}

function sql_render(array $fields, array $rows, $more, $ms)
{
    $mask = [];
    foreach ($fields as $i => $f) {
        $mask[$i] = preg_match('/pass|secret|token|hash/i', $f) === 1;
    }
    $out = '';
    foreach ($rows as $k => $r) {
        $out .= '#' . ($k + 1) . ' ';
        $parts = [];
        foreach ($r as $i => $v) {
            $val     = $mask[$i] ? '••••' : ($v === null ? 'NULL' : tg_clip(str_replace(["\r", "\n"], ' ', (string) $v), 45));
            $parts[] = $fields[$i] . '=' . $val;
        }
        $out .= implode(' | ', $parts) . "\n";
    }
    $out = tg_clip($out !== '' ? $out : '(0 dòng)', 3200);
    return '🧮 <b>KẾT QUẢ SQL</b> · ' . count($rows) . ' dòng' . ($more ? ' (hiển thị 25 dòng đầu)' : '') . ' · ' . $ms . " ms\n<pre>" . tg_e($out) . '</pre>';
}

function bot_sql($sql)
{
    $sql = trim(rtrim(trim((string) $sql), "; \t\r\n"));
    if ($sql === '') {
        return screen_sql_help();
    }
    if (strlen($sql) > 3000 || preg_match('/\binto\s+(outfile|dumpfile)\b|\bload_file\s*\(|\bload\s+data\b/i', $sql)) {
        return ui_error('Truy vấn không được phép', 'sq');
    }
    $verb  = sql_verb($sql);
    $admin = $GLOBALS['CTX']['admin'];

    if (in_array($verb, ['select', 'show', 'describe', 'desc', 'explain'], true)) {
        $t0  = microtime(true);
        $res = mysqli_query(bdb(), $sql, MYSQLI_USE_RESULT);
        if (!($res instanceof mysqli_result)) {
            sql_hist($sql, 'OK');
            return ui_send('✅ <b>OK</b>', kb([[btn('🧮 SQL', 'sq'), btn('🏠 Menu chính', 'home')]]));
        }
        $fields = [];
        foreach ($res->fetch_fields() as $f) {
            $fields[] = $f->name;
        }
        $rows = [];
        $more = false;
        while ($r = $res->fetch_row()) {
            if (count($rows) >= 25) {
                $more = true;
                break;
            }
            $rows[] = $r;
        }
        $res->free();
        $ms = (int) round((microtime(true) - $t0) * 1000);
        sql_hist($sql, count($rows) . ' dòng');
        return ui_send(sql_render($fields, $rows, $more, $ms), kb([[btn('✍️ Truy vấn khác', 'sw'), btn('🏠 Menu chính', 'home')]]));
    }

    if (in_array($verb, ['insert', 'update', 'replace'], true)) {
        if ($verb === 'update' && !preg_match('/\bwhere\b/i', $sql)) {
            return ui_error('UPDATE bắt buộc phải có điều kiện WHERE', 'sq');
        }
        $id = bot_stash(['sql' => $sql, 'admin' => $admin, 'at' => time()]);
        return ui_send("⚠️ <b>XÁC NHẬN GHI DỮ LIỆU</b>\n<pre>" . tg_e(tg_clip($sql, 1400)) . '</pre>',
            kb([[btn('✅ Thực thi', 'sx|' . $id), btn('✖ Huỷ', 'cx')]]));
    }

    if (in_array($verb, ['delete', 'drop', 'truncate', 'alter', 'create', 'rename'], true)) {
        if (!tg_cfg('allow_destructive')) {
            return ui_error('Lệnh xoá/cấu trúc đang khoá bởi chế độ bảo vệ dữ liệu', 'sq');
        }
        $id = bot_stash(['sql' => $sql, 'admin' => $admin, 'at' => time()]);
        return ui_send("🚨 <b>XÁC NHẬN LỆNH NGUY HIỂM</b>\n<pre>" . tg_e(tg_clip($sql, 1400)) . '</pre>',
            kb([[btn('🚨 Chắc chắn chạy', 'sd|' . $id), btn('✖ Huỷ', 'cx')]]));
    }
    return ui_error('Lệnh SQL không hợp lệ', 'sq');
}

function bot_sql_exec($stashId, $destructive)
{
    $p = bot_unstash($stashId);
    if (!is_array($p) || !isset($p['sql']) || (string) $p['admin'] !== (string) $GLOBALS['CTX']['admin'] || (int) $p['at'] < time() - 900) {
        return ui_error('Xác nhận đã hết hạn', 'sq');
    }
    tg_store_update('stash', function ($s) use ($stashId) {
        unset($s[$stashId]);
        return $s;
    });
    $sql = (string) $p['sql'];
    try {
        mysqli_query(bdb(), $sql);
        $n = mysqli_affected_rows(bdb());
        sql_hist($sql, 'OK · ' . $n . ' dòng');
        bot_log('tg_sql', tg_clip($sql, 300));
        toast('✅ Đã thực thi');
        ui_send('✅ <b>HOÀN TẤT</b> · Tác động <b>' . $n . "</b> dòng\n<pre>" . tg_e(tg_clip($sql, 900)) . '</pre>', kb([[btn('🧮 SQL', 'sq'), btn('🏠 Menu chính', 'home')]]));
    } catch (Throwable $e) {
        ui_send('❌ <b>LỖI SQL</b>' . "\n<pre>" . tg_e(tg_clip($e->getMessage(), 500)) . '</pre>', kb([[btn('🧮 SQL', 'sq'), btn('🏠 Menu chính', 'home')]]));
    }
}

/* ------------------------------------------------------------------
 * 12. IP Bans (Sad-Face Timed + Permanent) & User Account Blocks
 * ------------------------------------------------------------------ */

function bot_user_ips($username, $limit = 8)
{
    $ips = [];
    $src = [
        'tg_activity'     => "SELECT ip, MAX(created_at) AS t FROM tg_activity WHERE actor = ? AND ip <> '' AND ip <> 'telegram' GROUP BY ip",
        'key_attempts'    => "SELECT ip, MAX(created_at) AS t FROM key_attempts WHERE username = ? AND ip <> '' GROUP BY ip",
        'free_key_ip_log' => "SELECT ip_raw AS ip, FROM_UNIXTIME(MAX(created_at)) AS t FROM free_key_ip_log WHERE username = ? AND ip_raw <> '' GROUP BY ip_raw",
    ];
    foreach ($src as $table => $sql) {
        if (!bt_valid($table)) {
            continue;
        }
        try {
            foreach (bq_all($sql, 's', [$username]) as $r) {
                $ip = ipg_norm($r['ip']);
                if ($ip !== '' && (!isset($ips[$ip]) || (string) $r['t'] > $ips[$ip])) {
                    $ips[$ip] = (string) $r['t'];
                }
            }
        } catch (Throwable $e) {
            // skip
        }
    }
    arsort($ips);
    return array_slice($ips, 0, $limit, true);
}

function bot_ip_users($ip, $limit = 8)
{
    $names = [];
    $src   = [
        'tg_activity'     => "SELECT actor AS u FROM tg_activity WHERE ip = ? AND role IN ('member', 'admin') GROUP BY actor ORDER BY MAX(id) DESC LIMIT 8",
        'key_attempts'    => 'SELECT username AS u FROM key_attempts WHERE ip = ? GROUP BY username ORDER BY MAX(id) DESC LIMIT 8',
        'free_key_ip_log' => 'SELECT username AS u FROM free_key_ip_log WHERE ip_raw = ? OR ip = ? GROUP BY username ORDER BY MAX(id) DESC LIMIT 8',
    ];
    foreach ($src as $table => $sql) {
        if (!bt_valid($table)) {
            continue;
        }
        try {
            $rows = $table === 'free_key_ip_log' ? bq_all($sql, 'ss', [$ip, $ip]) : bq_all($sql, 's', [$ip]);
            foreach ($rows as $r) {
                if (!empty($r['u'])) {
                    $names[(string) $r['u']] = true;
                }
            }
        } catch (Throwable $e) {
            // skip
        }
    }
    return array_slice(array_keys($names), 0, $limit);
}

function screen_bans($page = 0)
{
    if (!bot_ipg_ready()) {
        return ui_error('Chưa nạp ipguard.php', 'home');
    }
    $counts = bot_ban_counts();
    $per    = 8;
    $total  = (int) bq_val('SELECT COUNT(*) FROM ip_bans');
    $pages  = max(1, (int) ceil($total / $per));
    $page   = max(0, min((int) $page, $pages - 1));
    $cols   = bt_cols('ip_bans');
    $selExp = isset($cols['expires_at']) ? ', expires_at, ban_type' : '';
    $rows   = bq_all('SELECT id, ip, reason, active, hits' . $selExp . ' FROM ip_bans ORDER BY active DESC, id DESC LIMIT ' . $per . ' OFFSET ' . ($page * $per));

    $kbd = [
        [btn('🔐 Tra mã xác minh IMX', 'csx|menu'), btn('🔍 Kiểm tra 1 IP', 'bs')],
        [btn('➕ Chặn IP thủ công', 'bi'), btn('🧠 Khiên AI & Whitelist', 'aihub')],
    ];
    foreach ($rows as $r) {
        $isAct  = (int) $r['active'] === 1;
        $isTemp = $isAct && !empty($r['expires_at']) && strpos((string) $r['expires_at'], '0000') !== 0;
        $ic     = !$isAct ? '✅ ' : ($isTemp ? '☹️ ' : '🚫 ');
        $leftTxt = '';
        if ($isTemp) {
            $leftTxt = ' (' . max(1, (int) ceil((strtotime((string) $r['expires_at']) - time()) / 60)) . 'p)';
        }
        $kbd[] = [btn($ic . $r['ip'] . $leftTxt . ' · ' . tg_clip($r['reason'], 20), 'bv|' . (int) $r['id'])];
    }
    if ($pages > 1) {
        $kbd[] = pager_row('bn', [], $page, $pages);
    }
    $kbd[] = home_row();

    $t = '🛡 <b>QUẢN LÝ CHỐNG DDOS & KHOÁ IP</b>' . "\n"
        . '<blockquote>'
        . '☹️ Tạm khoá Mặt buồn: <b>' . $counts['temp'] . '</b> IP (hết giờ tự mở)' . "\n"
        . '🚫 Chặn vĩnh viễn: <b>' . $counts['perm'] . '</b> IP · Tổng lịch sử: <b>' . $total . '</b>'
        . '</blockquote>' . "\n"
        . '<i>Lệnh nhanh:</i> <code>/ban IP lý_do</code> · <code>/unban IP</code> · <code>/ip IP</code>';
    ui_send($t, kb($kbd));
}

function screen_ban_view($id, $note = '')
{
    $b = bq_row('SELECT * FROM ip_bans WHERE id = ? LIMIT 1', 'i', [(int) $id]);
    if (!$b) {
        return ui_error('Không tìm thấy hồ sơ IP', 'bn|0');
    }
    $on     = (int) $b['active'] === 1;
    $isTemp = $on && !empty($b['expires_at']) && strpos((string) $b['expires_at'], '0000') !== 0;
    $t      = ($on ? ($isTemp ? '☹️' : '🚫') : '✅') . ' <b>HỒ SƠ IP</b> · <code>BAN-' . str_pad((string) (int) $b['id'], 5, '0', STR_PAD_LEFT) . "</code>\n";
    if ($note !== '') {
        $t .= $note . "\n";
    }
    $t .= '<blockquote>';
    $t .= '🌐 IP: <code>' . tg_e($b['ip']) . "</code>\n";
    $t .= '📌 Trạng thái: ' . (!$on ? '🟢 Đã gỡ chặn' : ($isTemp ? '☹️ Tạm khoá Mặt buồn (tự mở lúc ' . tg_e($b['expires_at']) . ')' : '🚫 Chặn vĩnh viễn')) . "\n";
    $t .= '📝 Lý do: ' . tg_e($b['reason']) . "\n";
    if (!empty($b['username'])) {
        $t .= '👤 Tài khoản: <code>' . tg_e($b['username']) . "</code>\n";
    }
    $t .= '🔁 Lượt bị cản: <b>' . (int) $b['hits'] . '</b> · Tạo bởi: <code>' . tg_e($b['banned_by']) . "</code>\n";
    if (!$on && !empty($b['unbanned_at'])) {
        $t .= '✅ Mở lại lúc: ' . tg_e($b['unbanned_at']) . ' bởi <code>' . tg_e($b['unbanned_by']) . "</code>\n";
    }
    $users = bot_ip_users($b['ip']);
    if ($users) {
        $t .= '👥 Tài khoản từng dùng IP: ' . tg_e(implode(', ', $users));
    }
    $t = rtrim($t) . '</blockquote>';

    $kbd = [];
    if ($on) {
        $kbd[] = [btn('🔓 Gỡ chặn IP ngay', 'bx|' . $b['ip'], 'success'), btn('✍ Đổi lý do', 'bw|' . $b['ip'])];
        if ($isTemp) {
            $kbd[] = [btn('🚫 Chuyển sang Chặn Vĩnh Viễn', cbd('bo', $b['ip'], (string) $b['username']))];
        }
    } else {
        $kbd[] = [btn('☹️ Tạm khoá 30p', cbd('bt30', $b['ip'])), btn('🚫 Chặn vĩnh viễn', 'bk|' . $b['ip'])];
    }
    $kbd[] = [btn('⬅ Danh sách IP', 'bn|0'), btn('🏠 Menu chính', 'home')];
    ui_send($t, kb($kbd));
}

function screen_ip($ip, $note = '')
{
    $ip = ipg_norm($ip);
    if ($ip === '') {
        return ui_error('Địa chỉ IP không hợp lệ', 'bn|0');
    }
    $b         = ipg_find(bdb(), $ip);
    $fastCache = function_exists('sh_read_ban_cache') ? sh_read_ban_cache($ip) : null;
    $on        = ($b && (int) $b['active'] === 1) || $fastCache !== null;
    $users     = bot_ip_users($ip);
    list($canBan, $safeWhy) = ipg_can_ban($ip);
    $intel     = function_exists('sh_inspect_ip_vpn') ? sh_inspect_ip_vpn($ip) : ['is_vpn' => false, 'isp' => '', 'country' => '', 'label' => ''];

    $t = '🌐 <b>HỒ SƠ TRA CỨU ĐỊA CHỈ IP</b> · <code>' . tg_e($ip) . "</code>\n";
    if ($note !== '') {
        $t .= '<blockquote>' . $note . "</blockquote>\n";
    }
    $t .= '<blockquote>';
    $t .= '🛡 Trạng thái: ' . ($on ? '🚫 <b>ĐANG BỊ KHOÁ BẢO MẬT</b>' : (!$canBan ? '🟢 <b>MIỄN TRỪ AN TOÀN</b> (' . tg_e($safeWhy) . ')' : '🟢 <b>Hoạt động bình thường</b>')) . "\n";
    if (!empty($intel['isp']) || !empty($intel['country'])) {
        $t .= '📡 Nhà mạng: <b>' . tg_e(trim($intel['isp'] . ($intel['country'] !== '' ? ' · ' . $intel['country'] : ''))) . '</b>'
            . (!empty($intel['is_vpn']) ? ' · ⚠️ <b>' . tg_e($intel['label'] ?: 'VPN/Proxy') . '</b>' : '') . "\n";
    }
    if ($fastCache && !empty($fastCache['reason'])) {
        $t .= '🔎 Nguyên nhân khoá: ' . tg_e($fastCache['reason']) . "\n";
        if (!empty($fastCache['case_code'])) {
            $t .= '🔐 Mã xác minh hiện tại: <code>' . tg_e($fastCache['case_code']) . "</code>\n";
        }
    } elseif ($b) {
        $t .= '📝 Lịch sử gần nhất: ' . tg_e($b['reason']) . "\n";
    }
    $t .= '👥 Tài khoản liên kết: ' . ($users ? '<code>' . tg_e(implode(', ', $users)) . '</code>' : '<i>Chưa ghi nhận</i>');
    $t .= '</blockquote>';

    $kbd = [];
    if ($on) {
        $kbd[] = [btn('🔓 Gỡ chặn sạch ngay', 'bx|' . $ip, 'success')];
        if (!empty($fastCache['case_code'])) {
            $kbd[] = [btn('🔐 Mở hồ sơ mã ' . $fastCache['case_code'], cbd('csx', 'view', $fastCache['case_code']))];
        }
    } elseif ($canBan) {
        $kbd[] = [btn('⏱ Tạm khoá 30p', cbd('bt30', $ip)), btn('🚫 Chặn vĩnh viễn', 'bk|' . $ip)];
    }
    $kbd[] = [btn('🔓 Mở khoá Get Key cho IP này', cbd('uk', 'run', $ip)), btn('🛡 Thêm vào Whitelist', cbd('csx', 'wl_ip', $ip))];
    $kbd[] = [btn('⬅ Quản lý IP', 'bn|0'), btn('🏠 Menu chính', 'home')];
    ui_send($t, kb($kbd));
}

function screen_ban_confirm($ip, $username = '')
{
    $ip = ipg_norm($ip);
    list($can, $why) = ipg_can_ban($ip);
    if (!$can) {
        return ui_error($why, 'bn|0');
    }
    $users = bot_ip_users($ip);
    $t = '🚫 <b>CHỌN HÌNH THỨC KHOÁ IP</b> · <code>' . tg_e($ip) . "</code>\n"
        . '<blockquote>'
        . ($username !== '' ? '👤 Thành viên: <code>' . tg_e($username) . "</code>\n" : '')
        . '👥 Tài khoản liên quan: ' . ($users ? tg_e(implode(', ', $users)) : '—') . "\n"
        . '☹️ Người bị khoá sẽ thấy giao diện Mặt buồn kèm lý do (người khác dùng web bình thường).'
        . '</blockquote>';
    ui_send($t, kb([
        [btn('☹️ Tạm khoá 30 phút (Tự gỡ)', cbd('bt30', $ip, $username))],
        [btn('🚫 Chặn vĩnh viễn ngay', cbd('bo', $ip, $username))],
        [btn('✍ Chặn vĩnh viễn + Ghi lý do riêng', cbd('bz', $ip, $username))],
        [btn('✖ Huỷ', 'bn|0')],
    ]));
}

function bot_do_ban($ip, $reason, $username = '')
{
    $admin = $GLOBALS['CTX']['admin'];
    $res   = ipg_ban(bdb(), $ip, $reason, $admin, $username);
    if (!$res['ok']) {
        return ui_error($res['msg'], 'bn|0');
    }
    $row = $res['row'];
    bot_log('tg_ipban', $row['ip'] . ' · ' . $row['reason']);
    tg_event('ip_ban', ['by' => $admin, 'ip' => $row['ip'], 'username' => $username, 'reason' => $row['reason']]);
    toast('🚫 Đã chặn vĩnh viễn IP');
    screen_ban_view($row['id'], '🚫 <b>Đã chặn vĩnh viễn IP này.</b>');
}

function bot_do_temp_ban($ip, $seconds, $username = '')
{
    $admin  = $GLOBALS['CTX']['admin'];
    $mins   = max(1, (int) ceil($seconds / 60));
    $reason = 'Tạm khoá truy cập ' . $mins . ' phút bởi Quản trị viên để kiểm soát spam.';
    $res    = ipg_temp_ban(bdb(), $ip, $seconds, $reason, $admin, $username, 'admin_temp');
    if (empty($res['ok'])) {
        return ui_error($res['msg'], 'bn|0');
    }
    bot_log('tg_iptempban', $ip . ' (' . $mins . 'p)');
    toast('☹️ Đã tạm khoá ' . $mins . ' phút');
    screen_ban_view($res['row']['id'], '☹️ <b>Đã chuyển IP sang trang Mặt buồn (' . $mins . ' phút tự gỡ).</b>');
}

function bot_do_unban($ip)
{
    $admin = $GLOBALS['CTX']['admin'];
    $res   = ipg_unban(bdb(), $ip, $admin);
    if (!$res['ok']) {
        return ui_error($res['msg'], 'bn|0');
    }
    bot_log('tg_ipunban', $ip);
    tg_event('ip_unban', ['by' => $admin, 'ip' => $ip]);
    toast('✅ Đã gỡ chặn IP');
    if (!empty($res['row']['id'])) {
        return screen_ban_view($res['row']['id'], '✅ <b>Đã gỡ chặn — người dùng vào lại web bình thường ngay lập tức.</b>');
    }
    return screen_bans(0);
}

function screen_user_ips($uid)
{
    $u = bq_row('SELECT id, username FROM users WHERE id = ? LIMIT 1', 'i', [(int) $uid]);
    if (!$u) {
        return ui_error('Không tìm thấy thành viên', cbd('us', '', 0));
    }
    $ips = bot_user_ips($u['username']);
    $t   = '🌐 <b>ĐỊA CHỈ IP CỦA</b> <code>' . tg_e($u['username']) . "</code>\n" . hr() . "\n";
    $kbd = [];
    if (!$ips) {
        $t .= 'Chưa ghi nhận IP nào của thành viên này.';
    } else {
        foreach ($ips as $ip => $seen) {
            $b      = ipg_find(bdb(), $ip);
            $banned = $b && (int) $b['active'] === 1;
            $kbd[]  = [btn(($banned ? '🚫 ' : '🌐 ') . $ip . ' · ' . substr($seen, 5, 11), $banned ? 'bv|' . (int) $b['id'] : cbd('bk', $ip, $u['username']))];
        }
        $kbd[] = [btn('🚫 Chặn tất cả IP của user này', 'ba|' . (int) $u['id'])];
    }
    $kbd[] = [btn('⬅ Thành viên', cbd('dr', 'users', $u['id'])), btn('🏠 Menu chính', 'home')];
    ui_send($t, kb($kbd));
}

function bot_ban_all($uid, $step)
{
    $u = bq_row('SELECT id, username FROM users WHERE id = ? LIMIT 1', 'i', [(int) $uid]);
    if (!$u) {
        return ui_error('Không tìm thấy thành viên', cbd('us', '', 0));
    }
    $ips = array_keys(bot_user_ips($u['username']));
    if (!$ips) {
        return screen_user_ips($uid);
    }
    if ($step !== 'ok') {
        return ui_send('🚫 <b>CHẶN TOÀN BỘ IP CỦA</b> <code>' . tg_e($u['username']) . "</code>?\n<code>" . tg_e(implode("\n", $ips)) . '</code>',
            kb([[btn('✅ Xác nhận chặn hết', 'ba|' . (int) $u['id'] . '|ok'), btn('✖ Huỷ', 'bu|' . (int) $u['id'])]]));
    }
    $admin = $GLOBALS['CTX']['admin'];
    $done  = [];
    foreach ($ips as $ip) {
        $res = ipg_ban(bdb(), $ip, 'Vi phạm quy định của website', $admin, $u['username']);
        if ($res['ok']) {
            $done[] = $ip;
        }
    }
    toast('🚫 Đã chặn ' . count($done) . ' IP');
    ui_send('🚫 Đã chặn <b>' . count($done) . '</b> địa chỉ IP của <code>' . tg_e($u['username']) . '</code>.',
        kb([[btn('⬅ Thành viên', cbd('dr', 'users', $u['id'])), btn('🏠 Menu chính', 'home')]]));
}

function bot_user_find($arg)
{
    $arg = trim((string) $arg);
    if ($arg === '') {
        return null;
    }
    $u = bq_row('SELECT id, username, role FROM users WHERE username = ? LIMIT 1', 's', [$arg]);
    if (!$u && preg_match('/^#?(\d+)$/', $arg, $m)) {
        $u = bq_row('SELECT id, username, role FROM users WHERE id = ? LIMIT 1', 'i', [(int) $m[1]]);
    }
    return $u;
}

function screen_block_confirm($uid)
{
    $u = bq_row('SELECT id, username, role FROM users WHERE id = ? LIMIT 1', 'i', [(int) $uid]);
    if (!$u) {
        return ui_error('Không tìm thấy thành viên', cbd('us', '', 0));
    }
    if ((int) $u['role'] === 1) {
        return ui_error('Không thể khoá tài khoản Admin', cbd('dr', 'users', (int) $u['id']));
    }
    ui_send('⛔ <b>KHOÁ TÀI KHOẢN</b> <code>' . tg_e($u['username']) . "</code>?\nTài khoản sẽ bị khoá đăng nhập và thu hồi lượt (có thể mở khoá khôi phục nguyên trạng bất cứ lúc nào).", kb([
        [btn('⛔ Khoá tài khoản + Chặn IP', 'ub|' . (int) $u['id'] . '|ip')],
        [btn('⛔ Chỉ khoá tài khoản', 'ub|' . (int) $u['id'] . '|acc')],
        [btn('✍ Khoá + Nhập lý do riêng', 'ub|' . (int) $u['id'] . '|why')],
        [btn('✖ Huỷ', cbd('dr', 'users', (int) $u['id']))],
    ]));
}

function bot_user_block($uid, $reason, $withIps)
{
    $admin = $GLOBALS['CTX']['admin'];
    $u     = bq_row('SELECT id, username, role, password, key_type, expiry_date, turns_left FROM users WHERE id = ? LIMIT 1', 'i', [(int) $uid]);
    if (!$u || (int) $u['role'] === 1) {
        return ui_error('Không thể khoá tài khoản này', cbd('us', '', 0));
    }
    if (tg_user_block_row($u['username'])) {
        return bot_row_view('users', (int) $u['id'], 'ℹ️ Tài khoản này đã bị khoá.');
    }
    $reason = trim((string) $reason) !== '' ? tg_clip(trim((string) $reason), 200) : 'Vi phạm quy định của website';
    $ips    = array_keys(bot_user_ips($u['username']));
    $locked = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $expiry = ($u['expiry_date'] === null || $u['expiry_date'] === '' || strpos((string) $u['expiry_date'], '0000') === 0) ? null : (string) $u['expiry_date'];

    $ins = bq_exec(
        'INSERT INTO tg_user_blocks (user_id, username, reason, active, blocked_by, created_at, old_password, locked_password, old_role, old_key_type, old_expiry, old_turns, ips) '
        . 'VALUES (?, ?, ?, 1, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)',
        'isssssissis',
        [(int) $u['id'], $u['username'], $reason, (string) $admin, (string) $u['password'], $locked, (int) $u['role'],
         $u['key_type'] === null ? null : (string) $u['key_type'], $expiry, $u['turns_left'] === null ? null : (int) $u['turns_left'], '[]']
    );
    $blockId = (int) $ins['id'];
    bq_exec(
        "UPDATE users SET password = ?, password_changed_at = NOW(), key_type = 'NONE', expiry_date = '2000-01-01 00:00:00', turns_left = NULL, vip_free_clicks = 0, alert_msg = ? WHERE id = ?",
        'ssi',
        [$locked, 'Tài khoản của bạn đã bị chặn. Lý do: ' . $reason, (int) $u['id']]
    );

    $banned = [];
    if ($withIps && bot_ipg_ready()) {
        foreach ($ips as $ip) {
            $res = ipg_ban(bdb(), $ip, $reason, $admin, $u['username']);
            if (!empty($res['ok'])) {
                $banned[] = $ip;
            }
        }
        bq_exec('UPDATE tg_user_blocks SET ips = ? WHERE id = ?', 'si', [json_encode($banned), $blockId]);
    }
    bot_log('tg_userblock', $u['username'] . ' · ' . $reason);
    tg_event('user_block', ['by' => $admin, 'username' => $u['username'], 'reason' => $reason]);
    toast('⛔ Đã khoá tài khoản');
    bot_row_view('users', (int) $u['id'], '⛔ <b>Đã khoá tài khoản thành công.</b>');
}

function bot_user_unblock($uid, $step)
{
    $admin = $GLOBALS['CTX']['admin'];
    $u     = bq_row('SELECT id, username, password FROM users WHERE id = ? LIMIT 1', 'i', [(int) $uid]);
    if (!$u) {
        return ui_error('Không tìm thấy thành viên', cbd('us', '', 0));
    }
    $b = tg_user_block_row($u['username']);
    if (!$b) {
        return bot_row_view('users', (int) $u['id'], 'ℹ️ Tài khoản này hiện không bị khoá.');
    }
    $ips = json_decode((string) $b['ips'], true);
    if (!is_array($ips)) {
        $ips = [];
    }
    if ($step !== 'ok' && $step !== 'keep') {
        return ui_send('✅ <b>MỞ KHOÁ TÀI KHOẢN</b> <code>' . tg_e($u['username']) . '</code>?', kb([
            [btn('✅ Mở khoá + Gỡ chặn IP', 'uu|' . (int) $u['id'] . '|ok')],
            [btn('✅ Chỉ mở khoá tài khoản', 'uu|' . (int) $u['id'] . '|keep')],
            [btn('✖ Huỷ', cbd('dr', 'users', (int) $u['id']))],
        ]));
    }
    if ((string) $b['old_password'] !== '' && hash_equals((string) $b['locked_password'], (string) $u['password'])) {
        bq_exec('UPDATE users SET password = ?, password_changed_at = NOW() WHERE id = ?', 'si', [(string) $b['old_password'], (int) $u['id']]);
    }
    if ($b['old_expiry'] !== null && strtotime((string) $b['old_expiry']) > time() && (string) $b['old_key_type'] !== '') {
        bq_exec('UPDATE users SET key_type = ?, expiry_date = ?, turns_left = ?, alert_msg = NULL WHERE id = ?', 'ssii',
            [(string) $b['old_key_type'], (string) $b['old_expiry'], $b['old_turns'] === null ? null : (int) $b['old_turns'], (int) $u['id']]);
    } else {
        bq_exec('UPDATE users SET alert_msg = NULL WHERE id = ?', 'i', [(int) $u['id']]);
    }
    if ($step === 'ok' && $ips && bot_ipg_ready()) {
        foreach ($ips as $ip) {
            ipg_unban(bdb(), $ip, $admin);
        }
    }
    bq_exec('UPDATE tg_user_blocks SET active = 0, unblocked_at = NOW(), unblocked_by = ? WHERE id = ?', 'si', [(string) $admin, (int) $b['id']]);
    bot_log('tg_userunblock', $u['username']);
    tg_event('user_unblock', ['by' => $admin, 'username' => $u['username']]);
    toast('✅ Đã mở khoá tài khoản');
    bot_row_view('users', (int) $u['id'], '✅ <b>Đã mở khoá & khôi phục tài khoản.</b>');
}

function screen_activity($mode, $arg, $page)
{
    $per   = 8;
    $where = '';
    $types = '';
    $par   = [];
    $title = 'HOẠT ĐỘNG GẦN ĐÂY';
    if ($mode === 'u') {
        $where = 'WHERE actor = ?';
        $types = 's';
        $par   = [$arg];
        $title = 'HOẠT ĐỘNG · ' . $arg;
    } elseif ($mode === 'i') {
        $where = 'WHERE ip = ?';
        $types = 's';
        $par   = [$arg];
        $title = 'HOẠT ĐỘNG · IP ' . $arg;
    }
    $total = (int) bq_val('SELECT COUNT(*) FROM tg_activity ' . $where, $types, $par);
    $pages = max(1, (int) ceil($total / $per));
    $page  = max(0, min((int) $page, $pages - 1));
    $rows  = bq_all('SELECT actor, action, detail, ip, created_at FROM tg_activity ' . $where . ' ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . ($page * $per), $types, $par);
    $t     = '👁 <b>' . tg_e($title) . '</b> · ' . $total . " mục\n" . hr() . "\n";
    foreach ($rows as $r) {
        $t .= '<i>' . tg_e(substr($r['created_at'], 5)) . '</i> · <b>' . tg_e(tg_clip($r['actor'], 22)) . '</b> · <code>' . tg_e($r['action']) . "</code>\n";
        if ($r['detail'] !== '') {
            $t .= '   ' . tg_e(tg_clip($r['detail'], 80)) . "\n";
        }
    }
    if (!$rows) {
        $t .= 'Chưa có hoạt động.';
    }
    $kbd = [];
    if ($pages > 1) {
        $kbd[] = pager_row($mode === 'u' ? 'au' : ($mode === 'i' ? 'ai' : 'ac'), $mode === 'u' || $mode === 'i' ? [$arg] : ['a'], $page, $pages);
    }
    $kbd[] = [btn('📜 Nhật ký', 'lg'), btn('🏠 Menu chính', 'home')];
    ui_send($t, kb($kbd));
}

/* ------------------------------------------------------------------
 * 13. Permanent Custom Emoji Gallery & Instant Auto-Replacer
 * ------------------------------------------------------------------ */

function bot_emoji_gallery($page = 0, $note = '')
{
    $slots = tg_emoji_slots();
    $vault = tg_emoji_vault_read();
    $picks = is_array($vault['picks']) ? $vault['picks'] : [];
    $on    = tg_emoji_on();
    $per   = 12;
    $pages = max(1, (int) ceil(count($slots) / $per));
    $page  = max(0, min((int) $page, $pages - 1));

    $cells = [];
    foreach (array_slice($slots, 0, 18) as $sl) {
        if (!empty($picks[$sl[0]])) {
            $cells[] = '<tg-emoji emoji-id="' . $picks[$sl[0]] . '">' . $sl[0] . '</tg-emoji>';
        }
    }

    $t = '🎨 <b>KHO EMOJI TELEGRAM PREMIUM VĨNH VIỄN</b> · ' . ($on ? '🟢 ĐANG BẬT' : '🔴 ĐANG TẮT') . "\n";
    if ($note !== '') {
        $t .= $note . "\n";
    }
    $t .= '<blockquote>'
        . '✨ <b>Cài đặt tự động thông minh (60 Emoji hoặc nhiều hơn không giới hạn):</b> Gửi <b>60, 80, 120+ Emoji Telegram Premium</b> bất kỳ vào đây, Bot sẽ <b>tự lọc hợp lệ 100% theo chuẩn API Telegram, tự phối màu &amp; ý nghĩa đẹp nhất có thể</b> cho toàn bộ giao diện + nút bấm và <b>lưu vĩnh viễn vào MySQL</b>!' . "\n"
        . '📦 Đã kích hoạt: <b>' . count($picks) . '</b> biểu tượng đồng bộ (Kho lưu: <b>' . count($vault['pool'] ?? []) . '</b> Emoji)'
        . (!empty($vault['pack_name']) ? ' · Bộ: <code>' . tg_e($vault['pack_name']) . '</code>' : '')
        . '</blockquote>';
    if ($cells) {
        $t .= "\n" . implode(' ', $cells);
    }

    $kbd  = [];
    $line = [];
    $end  = min(count($slots), ($page + 1) * $per);
    for ($i = $page * $per; $i < $end; $i++) {
        $line[] = btn($slots[$i][0] . "\u{FE0F} " . $slots[$i][1], 'ep|' . $i, '');
        if (count($line) === 3) {
            $kbd[] = $line;
            $line  = [];
        }
    }
    if ($line) {
        $kbd[] = $line;
    }
    if ($pages > 1) {
        $kbd[] = pager_row('em', [], $page, $pages);
    }
    $kbd[] = [
        btn($on ? '⏸ Tạm tắt Emoji' : '▶ Bật Emoji Premium', 'eo', ''),
        btn('🧹 Đặt lại mặc định', 'ez|ask', 'danger'),
    ];
    $kbd[] = home_row();
    ui_send($t, kb($kbd));
}

function bot_emoji_pick_start($i)
{
    $slots = tg_emoji_slots();
    $i     = max(0, min((int) $i, count($slots) - 1));
    conv_set($GLOBALS['CTX']['admin'], 'emoji_pick', ['i' => $i]);
    ui_send('🎨 <b>ĐỔI RIÊNG ICON</b> ' . $slots[$i][0] . "\u{FE0F} <b>" . tg_e($slots[$i][1]) . "</b>\nGửi 1 Emoji Premium bạn muốn gán cho vị trí này:", kb([[btn('✖ Huỷ', 'em|' . (int) floor($i / 12))]]));
}

function bot_extract_custom_emojis(array $m)
{
    $ids   = [];
    $hints = [];
    $text  = isset($m['text']) ? (string) $m['text'] : (isset($m['caption']) ? (string) $m['caption'] : '');
    $ents  = isset($m['entities']) && is_array($m['entities'])
        ? $m['entities']
        : (isset($m['caption_entities']) && is_array($m['caption_entities']) ? $m['caption_entities'] : []);
    $u16   = ($text !== '' && function_exists('mb_convert_encoding')) ? @mb_convert_encoding($text, 'UTF-16LE', 'UTF-8') : '';

    foreach ($ents as $en) {
        if (isset($en['type']) && $en['type'] === 'custom_emoji' && !empty($en['custom_emoji_id'])) {
            $ids[] = (string) $en['custom_emoji_id'];
            $hint  = '';
            if (is_string($u16) && $u16 !== '' && isset($en['offset'], $en['length'])) {
                $off = max(0, (int) $en['offset']) * 2;
                $len = max(0, (int) $en['length']) * 2;
                if ($len > 0 && $off + $len <= strlen($u16)) {
                    $sub = @mb_convert_encoding(substr($u16, $off, $len), 'UTF-8', 'UTF-16LE');
                    if (is_string($sub) && trim($sub) !== '') {
                        $hint = tg_emoji_norm(trim($sub));
                    }
                }
            }
            $hints[] = $hint;
            if (count($ids) >= 300) {
                break;
            }
        }
    }
    return [$ids, $hints];
}

function bot_handle_auto_emoji(array $ids, array $hints = [])
{
    $res = tg_emoji_ingest_custom_ids($ids, $hints);
    if (empty($res['ok'])) {
        return ui_error('Không đọc được Emoji hợp lệ vừa gửi, vui lòng thử gửi lại.', 'em|0');
    }
    $vaultNow = tg_emoji_vault_read();
    $totalMapped = count($vaultNow['picks'] ?? []);
    bot_log('tg_emoji_auto', 'Tự động thiết lập giao diện với ' . (int) $res['sent_count'] . ' Emoji Premium (lưu ' . $totalMapped . ' vị trí icon)' . ($res['pack'] !== '' ? ' [pack ' . $res['pack'] . ']' : ''));
    $samples = [];
    foreach ((array) (isset($res['sample_ids']) ? $res['sample_ids'] : []) as $sid) {
        $samples[] = '<tg-emoji emoji-id="' . $sid . '">⭐</tg-emoji>';
    }
    $note = '✅ <b>ĐÃ TỰ ĐỘNG KIỂM ĐỊNH HỢP LỆ &amp; SETUP GIAO DIỆN ĐẸP NHẤT!</b>' . "\n"
        . '🎨 Đã nhận <b>' . (int) $res['sent_count'] . '</b> Emoji (' . (int) $res['unique_cnt'] . ' mẫu hợp lệ duy nhất) → tự động phối <b>' . $totalMapped . '</b> biểu tượng &amp; nút bấm đồng bộ toàn Bot.' . "\n"
        . '💾 Đã lưu vĩnh viễn vào MySQL'
        . ($res['pack'] !== '' ? ' · Bộ: <code>' . tg_e($res['pack']) . '</code> (' . (int) $res['pack_count'] . ' icon).' : '.')
        . ($samples ? "\n" . implode(' ', $samples) : '');
    return screen_home($note);
}

/* ------------------------------------------------------------------
 * 14. Conversation Inputs, Commands & Callback Router
 * ------------------------------------------------------------------ */

function bot_conv_input(array $conv, $text, array $m)
{
    $admin     = $GLOBALS['CTX']['admin'];
    $mode      = $conv['mode'];
    $d         = isset($conv['data']) && is_array($conv['data']) ? $conv['data'] : [];
    $messageId = isset($m['message_id']) ? (int) $m['message_id'] : 0;
    conv_clear($admin);

    switch ($mode) {
        case 'emoji_pick':
            list($ids, ) = bot_extract_custom_emojis($m);
            $slots = tg_emoji_slots();
            $idx   = isset($d['i']) ? (int) $d['i'] : 0;
            if (!$ids || !isset($slots[$idx])) {
                conv_set($admin, 'emoji_pick', $d);
                return ui_send('❌ Hãy gửi <b>Emoji Telegram Premium</b> cho mục này.', kb([[btn('✖ Huỷ', 'em|0')]]));
            }
            $vault = tg_emoji_vault_read();
            $vault['picks'][$slots[$idx][0]] = $ids[0];
            if (empty($vault['default_id'])) {
                $vault['default_id'] = $ids[0];
            }
            tg_emoji_vault_write($vault);
            return bot_emoji_gallery((int) floor($idx / 12), '✅ Đã lưu vĩnh viễn Emoji cho <b>' . tg_e($slots[$idx][1]) . '</b>.');

        case 'maint_notice':
            bot_set_config('maintenance_notice', tg_clip($text, 500));
            bot_log('tg_maint_notice', 'Cập nhật nội dung bảo trì');
            toast('✅ Đã lưu nội dung bảo trì');
            return bot_maintenance('menu');

        case 'maint_title':
            bot_set_config('maintenance_title', tg_clip($text, 120));
            bot_log('tg_maint_title', 'Cập nhật tiêu đề bảo trì');
            toast('✅ Đã lưu tiêu đề bảo trì');
            return bot_maintenance('menu');

        case 'wl_add':
            $added = sh_whitelist_add($text, 'tg:' . $admin);
            if ($added === false) {
                conv_set($admin, 'wl_add');
                return ui_send('❌ Địa chỉ IP không hợp lệ. Gửi lại (ví dụ <code>203.0.113.5</code>):', kb([[btn('✖ Huỷ', 'sh|wl')]]));
            }
            bot_log('tg_whitelist', 'Thêm Whitelist IP ' . $added);
            toast('✅ Đã thêm Whitelist');
            return bot_shield_action('wl');

        case 'unlock_target':
            return bot_unlock_route('run', $text);

        case 'edit':
            return bot_apply_edit($d['t'], $d['pk'], $d['c'], $text);

        case 'pw':
            if ($messageId > 0) {
                tg_api_raw('deleteMessage', ['chat_id' => $GLOBALS['CTX']['chat'], 'message_id' => $messageId], 3);
            }
            if (strlen($text) < 6) {
                conv_set($admin, 'pw', $d);
                return ui_send('❌ Mật khẩu phải có từ 6 ký tự trở lên.', kb([[btn('✖ Huỷ', 'cx')]]));
            }
            bq_exec('UPDATE users SET password = ?, password_changed_at = NOW() WHERE id = ?', 'si', [password_hash($text, PASSWORD_DEFAULT), (int) $d['id']]);
            bot_log('tg_user', 'users#' . (int) $d['id'] . ': đổi mật khẩu');
            toast('✅ Đã lưu mật khẩu mới');
            return bot_row_view('users', (int) $d['id'], '✅ <b>Đã đổi mật khẩu (tin nhắn mật khẩu của bạn đã xoá).</b>');

        case 'search_user':
            $exact = bq_row('SELECT id FROM users WHERE username = ? LIMIT 1', 's', [$text]);
            return $exact ? bot_row_view('users', $exact['id']) : screen_users(0, $text);

        case 'search_key':
            return screen_key_search($text);

        case 'cfg_key':
            if (!preg_match('/^[A-Za-z0-9_]{1,50}$/', $text)) {
                conv_set($admin, 'cfg_key');
                return ui_send('❌ Tên cấu hình chỉ gồm chữ, số và dấu gạch dưới (_). Gửi lại:', kb([[btn('✖ Huỷ', 'cx')]]));
            }
            conv_set($admin, 'cfg_val', ['k' => $text]);
            return ui_send('⚙️ Cấu hình <code>' . tg_e($text) . "</code>\nGửi <b>giá trị</b> cho cấu hình này:", kb([[btn('✖ Huỷ', 'cx')]]));

        case 'cfg_val':
            bot_set_config($d['k'], $text);
            if (in_array($d['k'], ['brand_logo', 'brand_logo_scale', 'brand_logo_height', 'ds_logo_h', 'ds_logo_skew', 'ds_shine', 'ds_shine_seconds', 'favicon_url', 'favicon_bg'], true)) {
                bot_purge_logo_caches();
            }
            bot_log('tg_config', $d['k'] . ' = ' . tg_clip($text, 80));
            toast('✅ Đã lưu chính xác giá trị vừa nhập');
            return screen_cfg_item($d['k'], isset($d['g']) ? (string) $d['g'] : 'menu', isset($d['p']) ? (int) $d['p'] : 0, '✅ <b>Đã lưu nguyên bản 100% giá trị bạn vừa nhập!</b>');

        case 'up_brand_logo':
            $newLogo = '';
            if (!empty($m['photo']) || !empty($m['document']) || !empty($m['sticker'])) {
                list($okUp, $resUp) = bot_save_uploaded_image($m, 'dslogo_');
                if (!$okUp) {
                    conv_set($admin, 'up_brand_logo');
                    return ui_send('❌ ' . tg_e($resUp), kb([[btn('✖ Huỷ bỏ', 'ui|menu')]]));
                }
                $newLogo = $resUp;
            } elseif (preg_match('#^(https?://\S+|/?uploads/\S+)$#i', $text)) {
                $newLogo = ltrim($text, '/');
                if (stripos($text, 'http') === 0) {
                    $newLogo = $text;
                }
                bot_purge_logo_caches();
            } else {
                conv_set($admin, 'up_brand_logo');
                return ui_send('❌ Vui lòng <b>gửi trực tiếp 1 bức ảnh / tệp PNG</b> hoặc <b>dán đường dẫn ảnh (https://...)</b>:', kb([[btn('✖ Huỷ bỏ', 'ui|menu')]]));
            }
            bot_set_config('brand_logo', $newLogo);
            bot_purge_logo_caches();
            bot_log('tg_brand_logo', 'Đổi Logo toàn website -> ' . $newLogo);
            toast('✅ Đã đổi Logo toàn bộ website');
            return screen_appearance('✅ <b>Đã cập nhật Logo mới đồng bộ 100% trên toàn bộ trang iosmods.xyz!</b>' . "\n<code>" . tg_e($newLogo) . '</code>');

        case 'up_favicon':
            $newFav = '';
            if (!empty($m['photo']) || !empty($m['document']) || !empty($m['sticker'])) {
                list($okUp, $resUp) = bot_save_uploaded_image($m, 'dsfav_');
                if (!$okUp) {
                    conv_set($admin, 'up_favicon');
                    return ui_send('❌ ' . tg_e($resUp), kb([[btn('✖ Huỷ bỏ', 'ui|menu')]]));
                }
                $newFav = '/' . ltrim($resUp, '/');
            } elseif (preg_match('#^(https?://\S+|/?uploads/\S+)$#i', $text)) {
                $newFav = $text;
            } else {
                conv_set($admin, 'up_favicon');
                return ui_send('❌ Vui lòng gửi trực tiếp ảnh vuông hoặc đường dẫn https://...:', kb([[btn('✖ Huỷ bỏ', 'ui|menu')]]));
            }
            bot_set_config('favicon_url', $newFav);
            bot_purge_logo_caches();
            bot_log('tg_favicon', 'Đổi Icon Tab -> ' . $newFav);
            toast('✅ Đã đổi Icon Tab trình duyệt');
            return screen_appearance('✅ <b>Đã cập nhật Icon Tab trình duyệt mới!</b>');

        case 'up_avatar':
            if (strtoupper($text) === 'MACDINH' || $text === '-') {
                bot_set_config('ds_avatar_url', '');
                toast('♻️ Đã đặt lại ảnh đại diện mặc định');
                return screen_appearance('✅ Đã khôi phục ảnh đại diện Header về mặc định.');
            }
            $newAv = '';
            if (!empty($m['photo']) || !empty($m['document']) || !empty($m['sticker'])) {
                list($okUp, $resUp) = bot_save_uploaded_image($m, 'dsav_');
                if (!$okUp) {
                    conv_set($admin, 'up_avatar');
                    return ui_send('❌ ' . tg_e($resUp), kb([[btn('✖ Huỷ bỏ', 'ui|menu')]]));
                }
                $newAv = '/' . ltrim($resUp, '/');
            } elseif (preg_match('#^(https?://\S+|/?uploads/\S+)$#i', $text)) {
                $newAv = $text;
            } else {
                conv_set($admin, 'up_avatar');
                return ui_send('❌ Vui lòng gửi trực tiếp bức ảnh hoặc đường dẫn https://...:', kb([[btn('✖ Huỷ bỏ', 'ui|menu')]]));
            }
            bot_set_config('ds_avatar_url', $newAv);
            bot_log('tg_avatar', 'Đổi Ảnh đại diện Web -> ' . $newAv);
            toast('✅ Đã cập nhật Ảnh đại diện Header');
            return screen_appearance('✅ <b>Đã cập nhật Ảnh đại diện mặc định trên Header!</b>');

        case 'up_hero_add':
            $curHero = trim((string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'ds_hero_images'", '', [], ''));
            $lines   = $curHero !== '' ? array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $curHero)))) : [];
            if (!empty($m['photo']) || !empty($m['document'])) {
                list($okUp, $resUp) = bot_save_uploaded_image($m, 'dshero_');
                if (!$okUp) {
                    conv_set($admin, 'up_hero_add');
                    return ui_send('❌ ' . tg_e($resUp), kb([[btn('✖ Huỷ bỏ', 'ui|menu')]]));
                }
                $lines[] = '/' . ltrim($resUp, '/');
            } else {
                $addedAny = false;
                foreach (preg_split('/[\r\n]+/', $text) as $ln) {
                    $ln = trim($ln);
                    if ($ln !== '' && preg_match('#^(https?://\S+|/?uploads/\S+)$#i', $ln)) {
                        $lines[]  = $ln;
                        $addedAny = true;
                    }
                }
                if (!$addedAny) {
                    conv_set($admin, 'up_hero_add');
                    return ui_send('❌ Vui lòng gửi ảnh trực tiếp hoặc đường dẫn ảnh bắt đầu bằng https://...:', kb([[btn('✖ Huỷ bỏ', 'ui|menu')]]));
                }
            }
            $lines = array_slice(array_values(array_unique($lines)), -15);
            bot_set_config('ds_hero_images', implode("\n", $lines));
            bot_log('tg_hero_add', 'Cập nhật Banner trượt (' . count($lines) . ' ảnh)');
            toast('✅ Đã thêm vào Banner trang chủ');
            return screen_appearance('✅ Đã cập nhật khung Banner trượt trang chủ (hiện có <b>' . count($lines) . ' ảnh</b>).');

        case 'up_prod_img':
            $pid = isset($d['id']) ? (int) $d['id'] : 0;
            $newPImg = '';
            if (!empty($m['photo']) || !empty($m['document'])) {
                list($okUp, $resUp) = bot_save_uploaded_image($m, 'dsprod_');
                if (!$okUp) {
                    conv_set($admin, 'up_prod_img', $d);
                    return ui_send('❌ ' . tg_e($resUp), kb([[btn('✖ Huỷ bỏ', cbd('dr', 'products', $pid))]]));
                }
                $newPImg = '/' . ltrim($resUp, '/');
            } elseif (preg_match('#^(https?://\S+|/?uploads/\S+)$#i', $text)) {
                $newPImg = $text;
            } else {
                conv_set($admin, 'up_prod_img', $d);
                return ui_send('❌ Hãy gửi trực tiếp ảnh sản phẩm hoặc dán link https://...:', kb([[btn('✖ Huỷ bỏ', cbd('dr', 'products', $pid))]]));
            }
            bq_exec('UPDATE products SET image = ? WHERE id = ? LIMIT 1', 'si', [$newPImg, $pid]);
            bot_log('tg_prod_img', 'products#' . $pid . ' đổi ảnh');
            toast('✅ Đã đổi ảnh sản phẩm');
            return bot_row_view('products', $pid, '✅ <b>Đã cập nhật ảnh mới cho sản phẩm!</b>');

        case 'pn':
            conv_set($admin, 'pl', ['name' => tg_clip($text, 255)]);
            return ui_send('📦 <b>THÊM SẢN PHẨM</b> · Bước 2/4' . "\nGửi <b>đường dẫn tải/mở (https://...)</b>:", kb([[btn('✖ Huỷ', 'cx')]]));

        case 'pl':
            if (!preg_match('#^https?://[^\s/]#i', $text)) {
                conv_set($admin, 'pl', $d);
                return ui_send('❌ Link phải bắt đầu bằng http:// hoặc https://. Gửi lại:', kb([[btn('✖ Huỷ', 'cx')]]));
            }
            $d['link'] = $text;
            conv_set($admin, 'pi', $d);
            return ui_send('📦 <b>THÊM SẢN PHẨM</b> · Bước 3/4' . "\nGửi đường dẫn ảnh (hoặc gõ <code>-</code> nếu bỏ qua):", kb([[btn('✖ Huỷ', 'cx')]]));

        case 'pi':
            $d['image'] = $text === '-' ? '' : tg_clip($text, 255);
            conv_set($admin, 'pt', $d);
            return ui_send('📦 <b>THÊM SẢN PHẨM</b> · Bước 4/4' . "\nChọn phân loại cho <b>" . tg_e($d['name']) . '</b>:', kb([[btn('🟢 FREE', 'pt|FREE'), btn('💎 VIP', 'pt|VIP')], [btn('✖ Huỷ', 'cx')]]));

        case 'pt':
            conv_set($admin, 'pt', $d);
            return ui_send('Hãy bấm nút 🟢 FREE hoặc 💎 VIP bên trên.', kb([[btn('🟢 FREE', 'pt|FREE'), btn('💎 VIP', 'pt|VIP')], [btn('✖ Huỷ', 'cx')]]));

        case 'sql':
            return bot_sql($text);

        case 'ban_ip':
            $ip = ipg_norm($text);
            if ($ip === '') {
                conv_set($admin, 'ban_ip');
                return ui_send('❌ Địa chỉ IP không hợp lệ. Gửi lại:', kb([[btn('✖ Huỷ', 'cx')]]));
            }
            return screen_ban_confirm($ip);

        case 'ban_reason':
            return bot_do_ban($d['ip'], $text, isset($d['u']) ? (string) $d['u'] : '');

        case 'ban_reason_edit':
            $res = ipg_ban(bdb(), $d['ip'], $text, $admin, '');
            if (!$res['ok']) {
                return ui_error($res['msg'], 'bn|0');
            }
            return screen_ban_view($res['row']['id'], '✅ <b>Đã cập nhật lý do.</b>');

        case 'ip_info':
            return screen_ip($text);

        case 'case_ask':
            return screen_smart_check($text);

        case 'smart_check':
            return screen_smart_check($text);

        case 'block_reason':
            return bot_user_block((int) $d['id'], $text, true);
    }
    return screen_home();
}

/**
 * Live Active Blocked List (/dangbi, /dschan, abl|...):
 * Shows EVERYONE currently blocked right now across IP bans, pre-DB shield cache, and Account blocks.
 * Clearly announces if NOBODY is currently blocked!
 */
function screen_active_blocked($page = 0, $note = '')
{
    $items = function_exists('sh_collect_active_blocks') ? sh_collect_active_blocks(bdb()) : [];
    $total = count($items);

    $t = '📋 <b>DANH SÁCH ĐANG BỊ CHẶN TRÊN WEBSITE</b>' . "\n";
    if ($note !== '') {
        $t .= '<blockquote>' . $note . "</blockquote>\n";
    }

    if ($total === 0) {
        $t .= '<blockquote>'
            . '🟢 <b>HIỆN TẠI KHÔNG CÓ AI ĐANG BỊ CHẶN</b>' . "\n"
            . 'Toàn bộ thành viên và địa chỉ IP trên hệ thống đang hoạt động bình thường, không có bất kỳ lệnh khoá nào đang áp dụng.'
            . '</blockquote>';
        return ui_send($t, kb([
            [btn('🔍 Kiểm tra 1 User / IP cụ thể', 'chk|ask'), btn('🔄 Làm mới', 'abl|0')],
            [btn('📜 Lịch sử chặn trước đây', 'bn|0'), btn('🏠 Menu chính', 'home')],
        ]));
    }

    $per   = 6;
    $pages = max(1, (int) ceil($total / $per));
    $page  = max(0, min((int) $page, $pages - 1));
    $slice = array_slice($items, $page * $per, $per);

    $tempN = 0;
    $permN = 0;
    foreach ($items as $it) {
        if ($it['kind'] === 'temp_ip') {
            $tempN++;
        } else {
            $permN++;
        }
    }

    $t .= '<blockquote>'
        . '🔴 Đang bị chặn: <b>' . $total . ' đối tượng</b> <i>(Tạm khoá: ' . $tempN . ' · Cấm/Khoá tài khoản: ' . $permN . ')</i>'
        . '</blockquote>' . "\n";

    $kbd = [];
    foreach ($slice as $idx => $it) {
        $num     = $page * $per + $idx + 1;
        $who     = !empty($it['username']) ? ('👤 <code>' . tg_e($it['username']) . '</code>') : '';
        $ipTxt   = !empty($it['ip']) ? ('🌐 <code>' . tg_e($it['ip']) . '</code>') : '';
        $leftTxt = 'Vĩnh viễn';
        if (!empty($it['expires_ts']) && (int) $it['expires_ts'] > time()) {
            $leftTxt = 'còn ' . max(1, (int) ceil(((int) $it['expires_ts'] - time()) / 60)) . ' phút';
        }
        $t .= '<b>' . $num . '.</b> ' . implode(' · ', array_filter([$who, $ipTxt])) . ' · <i>(' . $leftTxt . ")</i>\n"
            . '   ↳ ' . tg_e(tg_clip($it['reason'], 95)) . "\n";

        if ($it['kind'] === 'user_block' && !empty($it['id'])) {
            $kbd[] = [btn('🔓 Mở khoá User ' . tg_clip($it['username'], 22), 'uu|' . (int) $it['id'] . '|ok', 'success')];
        } elseif (!empty($it['ip'])) {
            $lbl = '🔓 Gỡ ' . ($it['username'] !== '' ? ($it['username'] . ' (' . $it['ip'] . ')') : $it['ip']);
            $rowBtns = [btn(tg_clip($lbl, 34), cbd('abl', 'unban_ip', $it['ip']), 'success')];
            if (!empty($it['case_code'])) {
                $rowBtns[] = btn('🔍 Hồ sơ', cbd('csx', 'view', $it['case_code']));
            }
            $kbd[] = $rowBtns;
        }
    }

    if ($pages > 1) {
        $kbd[] = pager_row('abl', [], $page, $pages);
    }
    if ($tempN > 0) {
        $kbd[] = [btn('🧹 Gỡ toàn bộ ' . $tempN . ' IP tạm khoá', 'abl|clear_temp', 'success')];
    }
    $kbd[] = [btn('🔍 Kiểm tra User / IP', 'chk|ask'), btn('🔄 Làm mới', 'abl|' . $page)];
    $kbd[] = home_row();
    return ui_send(rtrim($t), kb($kbd));
}

/**
 * Smart Universal Checker (/check, chk|..., or typing any Username / IP / IMX-... in chat):
 * Checks ALL security layers; if the target is NOT blocked anywhere, states clearly that they are NOT blocked!
 */
function screen_smart_check($rawQuery, $note = '')
{
    $q = trim((string) $rawQuery);
    if ($q === '') {
        conv_set($GLOBALS['CTX']['admin'], 'smart_check');
        return ui_send('🔍 <b>KIỂM TRA AI ĐANG BỊ CHẶN</b>' . "\n" . hr() . "\n"
            . 'Gửi <b>tên tài khoản web</b>, <b>địa chỉ IP</b> hoặc <b>mã xác minh IMX-...</b> cần kiểm tra:',
            kb([[btn('📋 Xem danh sách đang bị chặn', 'abl|0')], [btn('✖ Huỷ', 'home')]]));
    }

    // 1. IMX-... Case Code
    if (function_exists('sh_extract_case_code')) {
        $imx = sh_extract_case_code($q);
        if ($imx !== '' || stripos($q, 'IMX-') === 0) {
            return screen_case_lookup($imx !== '' ? $imx : $q, $note);
        }
    }

    // 2. IP Address Check
    if (filter_var($q, FILTER_VALIDATE_IP) !== false) {
        $ip    = ipg_norm($q);
        $dbBan = bot_ipg_ready() ? ipg_is_banned(bdb(), $ip) : null;
        $fast  = function_exists('sh_read_ban_cache') ? sh_read_ban_cache($ip) : null;
        $fkBlk = 0;
        try {
            if (bt_valid('free_key_ip_log')) {
                $fkBlk = (int) bq_val("SELECT COUNT(*) FROM free_key_ip_log WHERE (ip_raw = ? OR ip = ?) AND event LIKE 'blocked%' AND created_at >= ?", 'ssi', [$ip, $ip, time() - 28800]);
            }
        } catch (Throwable $e) {}

        if (!$dbBan && !$fast && $fkBlk === 0) {
            $users = bot_ip_users($ip, 5);
            $t     = '🟢 <b>ĐỊA CHỈ IP KHÔNG BỊ CHẶN</b>' . "\n"
                . ($note !== '' ? '<blockquote>' . $note . "</blockquote>\n" : '')
                . '<blockquote>'
                . '🌐 IP: <code>' . tg_e($ip) . "</code>\n"
                . '✅ Trạng thái: <b>Không bị chặn</b> — truy cập website và nhận Key bình thường.'
                . ($users ? "\n👥 Tài khoản từng dùng IP: <code>" . tg_e(implode(', ', $users)) . '</code>' : '')
                . '</blockquote>';
            return ui_send($t, kb([
                [btn('🌐 Chi tiết IP', cbd('csx', 'ip_raw', $ip)), btn('📋 DS đang bị chặn', 'abl|0')],
                home_row(),
            ]));
        }
        return screen_ip($ip, $note !== '' ? $note : ($fkBlk > 0 && !$dbBan && !$fast ? '⚠️ IP này không bị khoá web nhưng đang vướng <b>' . $fkBlk . '</b> lượt chặn chu kỳ Get Key 8h.' : ''));
    }

    // 3. Username Check (checks Account Lock + Entity Lock + All Recent IPs of User + Get-Key Lock!)
    $uRow  = bq_row('SELECT id, username, role, key_type, (expiry_date > NOW()) AS has_key FROM users WHERE username = ? LIMIT 1', 's', [$q]);
    $uname = $uRow ? (string) $uRow['username'] : $q;

    $uBlock   = tg_user_block_row($uname);
    $uElock   = function_exists('sh_read_entity_lock') ? sh_read_entity_lock('user', $uname) : null;
    $userIps  = array_keys(bot_user_ips($uname, 10));
    $bannedIps = [];
    foreach ($userIps as $uip) {
        $b1 = bot_ipg_ready() ? ipg_is_banned(bdb(), $uip) : null;
        $b2 = function_exists('sh_read_ban_cache') ? sh_read_ban_cache($uip) : null;
        if ($b1 || $b2) {
            $bannedIps[$uip] = $b2 ? $b2['reason'] : $b1['reason'];
        }
    }
    $fkLock = 0;
    try {
        if (bt_valid('free_key_ip_log')) {
            $fkLock = (int) bq_val("SELECT COUNT(*) FROM free_key_ip_log WHERE username = ? AND event LIKE 'blocked%' AND created_at >= ?", 'si', [$uname, time() - 28800]);
        }
    } catch (Throwable $e) {}

    if (!$uBlock && !$uElock && !$bannedIps && $fkLock === 0) {
        $t = '🟢 <b>' . ($uRow ? 'TÀI KHOẢN «' . tg_e($uname) . '» KHÔNG BỊ CHẶN' : 'KHÔNG TÌM THẤY LỆNH CHẶN CHO «' . tg_e($uname) . '»') . '</b>' . "\n"
            . ($note !== '' ? '<blockquote>' . $note . "</blockquote>\n" : '')
            . '<blockquote>'
            . ($uRow
                ? ('👤 Thành viên: <code>' . tg_e($uname) . '</code> (#' . (int) $uRow['id'] . ')' . "\n"
                    . '✅ Trạng thái bảo mật: <b>Hoàn toàn sạch — KHÔNG bị chặn IP, thiết bị hay khoá tài khoản.</b>' . "\n"
                    . '🔑 Gói Key hiện tại: <b>' . ((int) $uRow['has_key'] === 1 ? tg_e($uRow['key_type']) : 'Chưa kích hoạt / Hết hạn') . '</b>')
                : ('ℹ️ Không có tài khoản <code>' . tg_e($uname) . '</code> trong CSDL hoặc chưa từng phát sinh lệnh chặn nào.'))
            . '</blockquote>';
        $kbd = [];
        if ($uRow) {
            $kbd[] = [btn('👤 Xem hồ sơ thành viên', cbd('dr', 'users', (int) $uRow['id'])), btn('🔓 Cấp quyền Get Key 1 lần', cbd('uk', 'run', $uname))];
        }
        $kbd[] = [btn('🔍 Kiểm tra người khác', 'chk|ask'), btn('📋 DS đang bị chặn', 'abl|0')];
        $kbd[] = home_row();
        return ui_send($t, kb($kbd));
    }

    // Target IS blocked on at least 1 layer!
    $t = '🔴 <b>PHÁT HIỆN LỆNH CHẶN ĐỐI VỚI «' . tg_e($uname) . '»</b>' . "\n";
    if ($note !== '') {
        $t .= '<blockquote>' . $note . "</blockquote>\n";
    }
    $t .= '<blockquote>';
    if ($uBlock) {
        $t .= '⛔ <b>Khoá tài khoản:</b> ' . tg_e($uBlock['reason']) . "\n";
    }
    if ($uElock) {
        $t .= '🔒 <b>Khoá liên kết thiết bị/tài khoản:</b> ' . tg_e(isset($uElock['reason']) ? $uElock['reason'] : 'Đang bị khoá đồng bộ') . "\n";
    }
    foreach ($bannedIps as $bip => $breason) {
        $t .= '🌐 <b>IP đang bị chặn (<code>' . tg_e($bip) . '</code>):</b> ' . tg_e(tg_clip($breason, 110)) . "\n";
    }
    if ($fkLock > 0) {
        $t .= '🕵️ <b>Giới hạn Get Key Free:</b> Vướng ' . $fkLock . " lượt chặn VPN/trùng thiết bị trong 8h qua.\n";
    }
    $t = rtrim($t) . '</blockquote>';

    $kbd = [
        [btn('🔓 Gỡ sạch TOÀN BỘ khoá cho ' . tg_clip($uname, 20), cbd('abl', 'unban_user', $uname), 'success')],
    ];
    if ($uRow) {
        $kbd[] = [btn('👤 Hồ sơ thành viên', cbd('dr', 'users', (int) $uRow['id']))];
    }
    $kbd[] = [btn('📋 DS đang bị chặn', 'abl|0'), btn('🏠 Menu chính', 'home')];
    return ui_send($t, kb($kbd));
}

function bot_active_blocked_action($sub, $arg = '')
{
    $admin = $GLOBALS['CTX']['admin'];
    if ($sub === 'unban_ip' && $arg !== '') {
        $ip = ipg_norm($arg);
        if ($ip !== '' && bot_ipg_ready()) {
            ipg_unban(bdb(), $ip, $admin);
        } elseif ($ip !== '' && function_exists('sh_full_clean_slate_unban')) {
            sh_full_clean_slate_unban(bdb(), $ip, '');
        }
        bot_log('tg_ipunban', $ip . ' [DS đang chặn]');
        toast('🔓 Đã gỡ chặn ' . $ip);
        return screen_active_blocked(0, '✅ Đã gỡ chặn hoàn toàn cho IP <code>' . tg_e($ip) . '</code>.');
    }
    if ($sub === 'clear_temp') {
        $items = function_exists('sh_collect_active_blocks') ? sh_collect_active_blocks(bdb()) : [];
        $n     = 0;
        foreach ($items as $it) {
            if ($it['kind'] === 'temp_ip' && !empty($it['ip'])) {
                if (bot_ipg_ready()) {
                    ipg_unban(bdb(), $it['ip'], $admin);
                }
                $n++;
            }
        }
        bot_log('tg_clear_temp_bans', 'Gỡ toàn bộ ' . $n . ' IP tạm khoá');
        toast('🧹 Đã gỡ ' . $n . ' IP tạm khoá');
        return screen_active_blocked(0, '✅ Đã gỡ sạch toàn bộ <b>' . $n . '</b> IP tạm khoá.');
    }
    if ($sub === 'unban_user' && $arg !== '') {
        $uname = trim((string) $arg);
        $uRow  = bq_row('SELECT id, username, password FROM users WHERE username = ? LIMIT 1', 's', [$uname]);
        if ($uRow && tg_user_block_row($uRow['username'])) {
            bot_user_unblock((int) $uRow['id'], 'ok');
        }
        foreach (array_keys(bot_user_ips($uname, 12)) as $uip) {
            if (bot_ipg_ready()) {
                ipg_unban(bdb(), $uip, $admin);
            }
        }
        if (function_exists('sh_full_clean_slate_unban')) {
            sh_full_clean_slate_unban(bdb(), '', $uname);
        }
        bot_unlock_all_locks($uname);
        bot_log('tg_full_unban_user', 'Gỡ toàn diện mọi khoá cho ' . $uname);
        toast('🔓 Đã gỡ sạch mọi khoá cho ' . $uname);
        return screen_smart_check($uname, '✅ Đã gỡ sạch toàn bộ khoá IP, thiết bị, tài khoản và giới hạn Get Key!');
    }
    return screen_active_blocked((int) $sub);
}

function screen_case_menu($note = '')
{
    $active = function_exists('sh_list_active_cases') ? sh_list_active_cases(6) : [];
    $t      = '🔐 <b>TRUNG TÂM TRA CỨU MÃ XÁC MINH IMX</b>' . "\n";
    if ($note !== '') {
        $t .= '<blockquote>' . $note . "</blockquote>\n";
    }
    $t .= '<blockquote>'
        . '📌 Bạn có thể <b>dán trực tiếp mã <code>IMX-...</code></b> (hoặc chuyển tiếp nguyên tin nhắn của khách có chứa mã <code>IMX-...</code>) vào khung chat bất kỳ lúc nào — Bot sẽ tự động tách mã, truy vết nguyên nhân gốc, thiết bị, nhà mạng và hiển thị đầy đủ nút xử lý.' . "\n"
        . '🔒 <i>Bảo mật: Ngay khi gỡ chặn, mã xác minh của khách sẽ tự động huỷ vĩnh viễn.</i>'
        . '</blockquote>';

    $kbd = [
        [btn('📋 Nhập / Dán mã IMX khách gửi', 'csx|ask')],
    ];
    if ($active) {
        $t .= "\n⏳ <b>Các mã xác minh đang chờ xử lý (" . count($active) . "):</b>";
        foreach ($active as $ac) {
            $shortIp = isset($ac['ip']) ? $ac['ip'] : '?';
            $btype   = function_exists('sh_ban_type_label') ? sh_ban_type_label(isset($ac['ban_type']) ? $ac['ban_type'] : '') : '';
            $kbd[]   = [btn('🔐 ' . $ac['code'] . ' · ' . $shortIp, cbd('csx', 'view', $ac['code']))];
        }
    } else {
        $t .= "\n<i>🟢 Hiện không có mã IMX nào đang bị khoá.</i>";
    }
    $kbd[] = [btn('🛡 Quản lý Khoá IP', 'bn|0'), btn('🏠 Menu chính', 'home')];
    return ui_send($t, kb($kbd));
}

function screen_case_lookup($rawCode, $note = '')
{
    if (!function_exists('sh_enrich_case_dossier')) {
        return ui_error('Chưa nạp security_shield.php', 'home');
    }
    $clean = function_exists('sh_extract_case_code') ? sh_extract_case_code($rawCode) : '';
    $query = $clean !== '' ? $clean : trim((string) $rawCode);
    $info  = sh_enrich_case_dossier(bdb(), $query);

    if (!$info || empty($info['active'])) {
        $t = '🔒 <b>MÃ XÁC MINH ĐÃ HUỶ HOẶC KHÔNG TỒN TẠI</b>' . "\n";
        if ($note !== '') {
            $t .= '<blockquote>' . $note . "</blockquote>\n";
        }
        $t .= '<blockquote>'
            . '🏷 Mã tra cứu: <code>' . tg_e(tg_clip($query, 40)) . "</code>\n"
            . 'ℹ️ Mã xác minh này <b>đã tự động huỷ</b> ngay sau khi người dùng được mở khoá (hoặc khách gửi sai ký tự mã).'
            . '</blockquote>';
        return ui_send($t, kb([
            [btn('📋 Tra mã IMX khác', 'csx|menu'), btn('🔍 Kiểm tra theo IP', 'bs')],
            [btn('🛡 Danh sách IP khoá', 'bn|0'), btn('🏠 Menu chính', 'home')],
        ]));
    }

    $code     = (string) $info['code'];
    $ip       = (string) $info['ip'];
    $streamIp = !empty($info['stream_ip']) && $info['stream_ip'] !== $ip ? (string) $info['stream_ip'] : '';
    $expTs    = isset($info['expires_ts']) ? (int) $info['expires_ts'] : 0;
    $leftSec  = $expTs > 0 ? max(1, $expTs - time()) : 0;
    $leftMin  = $leftSec > 0 ? (int) ceil($leftSec / 60) : 0;
    $uname    = !empty($info['username']) ? (string) $info['username'] : '';
    $linkedU  = bot_ip_users($ip, 5);
    if ($uname !== '' && !in_array($uname, $linkedU, true)) {
        array_unshift($linkedU, $uname);
    }

    // Check active Key status if a user account is associated
    $userStateTxt = '';
    $userId       = 0;
    $primaryUser  = $uname !== '' ? $uname : ($linkedU ? $linkedU[0] : '');
    if ($primaryUser !== '') {
        try {
            $uRow = bq_row('SELECT id, key_type, (expiry_date > NOW()) AS ok FROM users WHERE username = ? LIMIT 1', 's', [$primaryUser]);
            if ($uRow) {
                $userId       = (int) $uRow['id'];
                $userStateTxt = ((int) $uRow['ok'] === 1 && !empty($uRow['key_type']) && $uRow['key_type'] !== 'NONE')
                    ? ('Đang có Key <b>' . tg_e($uRow['key_type']) . '</b>')
                    : 'Chưa có Key hợp lệ';
            }
        } catch (Throwable $e) {}
    }

    $t = '🔐 <b>HỒ SƠ ĐIỀU TRA MÃ XÁC MINH</b>' . "\n";
    if ($note !== '') {
        $t .= '<blockquote>' . $note . "</blockquote>\n";
    }

    $t .= '<blockquote>'
        . '🏷 Mã: <code>' . tg_e($code) . "</code>\n"
        . '📂 Phân loại: <b>' . tg_e($info['type_lbl']) . "</b>\n"
        . '⏱ Thời hạn: ' . ($leftMin > 0
            ? ('Còn <b>' . $leftMin . ' phút</b> <i>(tự mở lúc ' . tg_e(date('H:i:s', $expTs)) . ')</i>')
            : '<b>Khoá vĩnh viễn</b> <i>(chờ Admin xử lý)</i>') . "\n"
        . '🕒 Bị chặn lúc: <code>' . tg_e(isset($info['created_at']) ? $info['created_at'] : '—') . '</code>'
        . '</blockquote>' . "\n";

    $t .= '<blockquote>'
        . '🔎 <b>Nguyên nhân gốc:</b> ' . tg_e($info['reason']) . "\n"
        . (!empty($info['attack_detail']) && $info['attack_detail'] !== $info['reason']
            ? '⚡ Chi tiết kỹ thuật: <code>' . tg_e(tg_clip($info['attack_detail'], 110)) . "</code>\n"
            : '')
        . '🌐 IP thật: <code>' . tg_e($ip) . '</code>'
        . ($streamIp !== '' ? ' · 🖥 Luồng: <code>' . tg_e($streamIp) . '</code>' : '') . "\n"
        . '📡 Mạng / ISP: <b>' . tg_e($info['isp']) . ($info['country'] !== '' ? ' (' . tg_e($info['country']) . ')' : '') . '</b>'
        . (!empty($info['is_vpn']) ? ' · ⚠️ <b>' . tg_e($info['vpn_lbl'] ?: 'VPN/Proxy') . '</b>' : '') . "\n"
        . '📱 Thiết bị: <b>' . tg_e($info['device']) . '</b>'
        . (!empty($info['hw_fp']) ? ' · HW: <code>' . tg_e(substr((string) $info['hw_fp'], 0, 10)) . '</code>' : '') . "\n"
        . '👥 Tài khoản: ' . ($linkedU ? '<code>' . tg_e(implode(', ', $linkedU)) . '</code>' : '<i>Khách chưa đăng nhập</i>')
        . ($userStateTxt !== '' ? ' (' . $userStateTxt . ')' : '') . "\n"
        . '📊 Cường độ: <b>' . (int) $info['hits'] . '</b> yêu cầu bị cản · Cố đổi IP/4G lách khoá: <b>' . (int) $info['evasion_attempts'] . ' lần</b>'
        . '</blockquote>' . "\n";

    $t .= '<blockquote>'
        . '🧠 <b>Đánh giá AI:</b> ' . $info['risk_badge'] . "\n"
        . '💡 <i>' . tg_e($info['ai_advice']) . '</i>'
        . '</blockquote>';

    $kbd = [
        [btn('🔓 Gỡ chặn sạch ngay & Huỷ mã này', cbd('csx', 'unban', $code), 'success')],
    ];
    if ($primaryUser !== '') {
        $kbd[] = [btn('🎁 Gỡ chặn + Mở khoá Get Key cho ' . tg_clip($primaryUser, 18), cbd('csx', 'unban_fk', $code), 'success')];
    } else {
        $kbd[] = [btn('🎁 Gỡ chặn + Cho phép IP qua lọc VPN/Get Key', cbd('csx', 'unban_fk', $code), 'success')];
    }
    $kbd[] = [
        btn('🛡 Gỡ & Đưa vào Whitelist', cbd('csx', 'wl', $code)),
        btn('⏱ +30 phút khoá', cbd('csx', 'ext30', $code)),
    ];
    $kbd[] = [
        btn('🚫 Chuyển sang Cấm Vĩnh Viễn', cbd('csx', 'perm', $code), 'danger'),
    ];
    $navRow = [btn('🌐 Hồ sơ IP', cbd('csx', 'ip', $code))];
    if ($userId > 0) {
        $navRow[] = btn('👤 Hồ sơ User', cbd('dr', 'users', $userId));
    }
    $navRow[] = btn('🔄 Làm mới', cbd('csx', 'view', $code));
    $kbd[]    = $navRow;
    $kbd[]    = [btn('⬅ Tra mã khác', 'csx|menu'), btn('🏠 Menu chính', 'home')];

    return ui_send($t, kb($kbd));
}

function bot_case_action($act, $code)
{
    $admin = $GLOBALS['CTX']['admin'];
    if ($act === 'menu') {
        return screen_case_menu();
    }
    if ($act === 'ask') {
        conv_set($admin, 'case_ask');
        return ui_send('🔐 <b>TRA CỨU MÃ XÁC MINH IMX</b>' . "\n" . hr() . "\n"
            . "Hãy dán mã <code>IMX-...</code> (hoặc chuyển tiếp nguyên tin nhắn của khách) vào đây:",
            kb([[btn('✖ Huỷ', 'csx|menu')]]));
    }
    if ($act === 'wl_ip' && $code !== '') {
        $added = function_exists('sh_whitelist_add') ? sh_whitelist_add($code, 'tg:' . $admin) : false;
        if ($added !== false && bot_ipg_ready()) {
            ipg_unban(bdb(), $code, $admin);
        }
        toast($added !== false ? '🛡 Đã thêm Whitelist & gỡ khoá' : '❌ IP không hợp lệ');
        return screen_ip($code, $added !== false ? '🛡 Đã đưa IP vào Whitelist miễn trừ an toàn.' : '');
    }
    if ($act === 'view') {
        return screen_case_lookup($code);
    }

    $info = function_exists('sh_enrich_case_dossier') ? sh_enrich_case_dossier(bdb(), $code) : null;
    if (!$info || empty($info['ip'])) {
        toast('🔒 Mã xác minh đã hết hiệu lực');
        return screen_case_lookup($code);
    }
    $ip    = (string) $info['ip'];
    $uname = !empty($info['username']) ? (string) $info['username'] : '';

    if ($act === 'ip') {
        return screen_ip($ip);
    }
    if ($act === 'unban') {
        if (bot_ipg_ready()) {
            ipg_unban(bdb(), $ip, $admin);
        } elseif (function_exists('sh_full_clean_slate_unban')) {
            sh_full_clean_slate_unban(bdb(), $ip, $uname);
        }
        bot_log('tg_case_unban', $info['code'] . ' -> gỡ sạch IP ' . $ip);
        tg_event('ip_unban', ['by' => $admin, 'ip' => $ip]);
        toast('🔓 Đã gỡ chặn sạch & huỷ mã IMX');
        return ui_send('✅ <b>ĐÃ GỠ CHẶN HOÀN TẤT &amp; HUỶ MÃ XÁC MINH</b>' . "\n"
            . '<blockquote>'
            . '🔓 IP được mở: <code>' . tg_e($ip) . "</code>\n"
            . ($uname !== '' ? '👤 Tài khoản: <code>' . tg_e($uname) . "</code>\n" : '')
            . '🗑 Mã đã huỷ vĩnh viễn: <code>' . tg_e($info['code']) . "</code>\n"
            . '✨ Đã xoá sạch 100% khoá thiết bị, IP phụ &amp; bộ đếm cũ — khách vào lại web ngay lập tức mà không bị chặn lặp lại.'
            . '</blockquote>',
            kb([
                [btn('🌐 Xem trạng thái IP', cbd('csx', 'ip_raw', $ip)), btn('🔐 Tra mã IMX khác', 'csx|menu')],
                home_row(),
            ]));
    }
    if ($act === 'unban_fk') {
        if (bot_ipg_ready()) {
            ipg_unban(bdb(), $ip, $admin);
        }
        $target = $uname !== '' ? $uname : $ip;
        list(, $unlockMsg) = bot_unlock_all_locks($target);
        bot_log('tg_case_unban_fk', $info['code'] . ' -> ' . $unlockMsg);
        toast('🎁 Đã gỡ chặn + cấp quyền Get Key');
        return ui_send('🎁 <b>ĐÃ GỠ CHẶN &amp; CẤP QUYỀN GET KEY THÀNH CÔNG</b>' . "\n"
            . '<blockquote>'
            . '🔓 IP: <code>' . tg_e($ip) . "</code>\n"
            . '✅ Chi tiết: ' . tg_e($unlockMsg) . "\n"
            . '🗑 Mã xác minh <code>' . tg_e($info['code']) . '</code> đã tự động huỷ.'
            . '</blockquote>',
            kb([[btn('🔐 Tra mã IMX khác', 'csx|menu'), btn('🏠 Menu chính', 'home')]]));
    }
    if ($act === 'wl') {
        $added = function_exists('sh_whitelist_add') ? sh_whitelist_add($ip, 'case:' . $info['code']) : false;
        if (bot_ipg_ready()) {
            ipg_unban(bdb(), $ip, $admin);
        }
        bot_log('tg_case_wl', $info['code'] . ' -> Whitelist ' . $ip);
        toast('🛡 Đã gỡ chặn & thêm vào Whitelist');
        return screen_ip($ip, '🛡 <b>Đã gỡ chặn, huỷ mã <code>' . tg_e($info['code']) . '</code> và đưa IP vào danh sách miễn trừ an toàn!</b>');
    }
    if ($act === 'ext30') {
        $curExp = !empty($info['expires_ts']) ? max(time(), (int) $info['expires_ts']) : time();
        $addSec = ($curExp - time()) + 1800;
        if (function_exists('ipg_temp_ban')) {
            ipg_temp_ban(bdb(), $ip, $addSec, $info['reason'], $admin, $uname, isset($info['ban_type']) ? $info['ban_type'] : 'temp');
        }
        // Refresh expires_ts on case file as well
        if (function_exists('sh_case_file')) {
            sh_json_update(sh_case_file($info['code']), function ($d) use ($addSec) {
                $d['expires_ts'] = time() + $addSec;
                return $d;
            });
        }
        bot_log('tg_case_ext30', $info['code'] . ' (+30p) ' . $ip);
        toast('⏱ Đã cộng thêm 30 phút khoá');
        return screen_case_lookup($info['code'], '⏱ <b>Đã gia hạn thêm 30 phút khoá cho đối tượng này.</b>');
    }
    if ($act === 'perm') {
        if (bot_ipg_ready()) {
            $res = ipg_ban(bdb(), $ip, $info['reason'] ?: 'Chặn vĩnh viễn theo hồ sơ mã ' . $info['code'], $admin, $uname);
            if (!empty($res['ok'])) {
                if (function_exists('sh_case_file')) {
                    sh_json_update(sh_case_file($info['code']), function ($d) {
                        $d['expires_ts'] = 0;
                        $d['ban_type']   = 'manual';
                        return $d;
                    });
                }
                bot_log('tg_case_perm', $info['code'] . ' -> Cấm vĩnh viễn ' . $ip);
                toast('🚫 Đã chuyển sang Cấm Vĩnh Viễn');
                return screen_case_lookup($info['code'], '🚫 <b>Đã chuyển sang lệnh CẤM VĨNH VIỄN đối với IP này.</b>');
            }
        }
    }
    return screen_case_menu();
}

function bot_help()
{
    ui_send('🤖 <b>DANH SÁCH LỆNH ĐIỀU HÀNH TIẾNG VIỆT</b>' . "\n" . '<blockquote>'
        . "/menu · Mở bảng điều khiển trung tâm\n"
        . "/tuyet · Bật / Tắt hiệu ứng tuyết rơi giao diện chính\n"
        . "/logo · Đổi toàn bộ Logo iosmods.xyz &amp; độ lấp loá\n"
        . "/cauhinh · Mở cài đặt Website tiếng Việt (như Admin CP)\n"
        . "/dangbi · Xem danh sách ai đang bị chặn\n"
        . "/check <code>tài_khoản / IP / IMX-...</code> · Kiểm tra chặn\n"
        . "/baotri · Bật / Tắt chế độ bảo trì website\n"
        . "/mokhoa <code>tài_khoản_hoặc_IP</code> · Mở khoá nhận Key\n"
        . "/user <code>tên</code> · /key <code>MÃ</code> · /genkey <code>vip 5 720</code>\n"
        . "/ban <code>IP lý_do</code> · /unban <code>IP</code> · /ip <code>IP</code>\n"
        . "/xoaluot <code>tên</code> · /emoji · /cancel"
        . '</blockquote>', kb([home_row()]));
}

/* ------------------------------------------------------------------
 * 14B. Autonomous Human-Like AI Unban Assistant (@devios_bot)
 *      - Sandboxed 100%: Non-admin private users ONLY interact with this AI assistant
 *        (zero access to Admin commands, SQL, database tables, or keys).
 *      - Tier 1 (Very Minor / First-time light spam <=6m, 0 evasions): Instant 100% Unban!
 *      - Tier 2 (Normal / Moderate temp lock + reasonable explanation): Moderate ~45% time reduction!
 *      - Tier 3 (Severe: DDoS / WAF / Script Bug / Manual perm ban / Repeat evasion):
 *        Intelligent human explanation + "Vui lòng liên hệ Admin vì điều này tôi không thể mở giúp bạn."
 *        + Instant Admin Telegram card with 1-tap [🔓 Admin mở khoá ngay] override button!
 * ------------------------------------------------------------------ */

function pub_send_reply($chatId, $html)
{
    $params = [
        'chat_id'                  => (string) $chatId,
        'text'                     => tg_clip($html, 3800),
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ];
    $res = tg_api_raw('sendMessage', $params, 6);
    if (empty($res['ok']) && stripos((string) (isset($res['description']) ? $res['description'] : ''), 'parse') !== false) {
        unset($params['parse_mode']);
        $params['text'] = html_entity_decode(strip_tags($params['text']), ENT_QUOTES, 'UTF-8');
        tg_api_raw('sendMessage', $params, 6);
    }
}

function pub_rate_allow($tgUid)
{
    $now   = time();
    $allow = true;
    tg_store_update('pub_rate', function ($s) use ($tgUid, $now, &$allow) {
        if (!is_array($s)) {
            $s = [];
        }
        foreach ($s as $k => $arr) {
            if (!is_array($arr) || empty($arr['u']) || (int) $arr['u'] < $now - 120) {
                unset($s[$k]);
            }
        }
        $hits = isset($s[$tgUid]['h']) && is_array($s[$tgUid]['h']) ? $s[$tgUid]['h'] : [];
        $keep = [];
        foreach ($hits as $ts) {
            if ((int) $ts >= $now - 60) {
                $keep[] = (int) $ts;
            }
        }
        if (count($keep) >= 6) {
            $allow = false;
            $s[$tgUid] = ['h' => $keep, 'u' => $now];
            return $s;
        }
        $keep[]    = $now;
        $s[$tgUid] = ['h' => $keep, 'u' => $now];
        return $s;
    });
    return $allow;
}

function pub_conv_get($tgUid)
{
    $s = tg_store_read('pub_conv');
    if (isset($s[$tgUid]) && is_array($s[$tgUid]) && (int) (isset($s[$tgUid]['at']) ? $s[$tgUid]['at'] : 0) > time() - 1800) {
        return $s[$tgUid];
    }
    return null;
}

function pub_conv_set($tgUid, array $state)
{
    $state['at'] = time();
    tg_store_update('pub_conv', function ($s) use ($tgUid, $state) {
        if (!is_array($s)) {
            $s = [];
        }
        if (count($s) > 300) {
            $s = array_slice($s, -200, null, true);
        }
        $s[$tgUid] = $state;
        return $s;
    });
}

function pub_conv_clear($tgUid)
{
    tg_store_update('pub_conv', function ($s) use ($tgUid) {
        if (is_array($s)) {
            unset($s[$tgUid]);
        }
        return is_array($s) ? $s : [];
    });
}

function pub_was_recently_forgiven($ip, $tgUid)
{
    $h   = tg_store_read('pub_forgive_hist');
    $now = time();
    if (!is_array($h)) {
        return false;
    }
    if ($ip !== '' && !empty($h['ip:' . $ip]) && ($now - (int) $h['ip:' . $ip]) < 43200) {
        return true;
    }
    if ($tgUid !== '' && !empty($h['tg:' . $tgUid]) && ($now - (int) $h['tg:' . $tgUid]) < 43200) {
        return true;
    }
    return false;
}

function pub_mark_forgiven($ip, $tgUid)
{
    $now = time();
    tg_store_update('pub_forgive_hist', function ($h) use ($ip, $tgUid, $now) {
        if (!is_array($h)) {
            $h = [];
        }
        foreach ($h as $k => $ts) {
            if ((int) $ts < $now - 86400) {
                unset($h[$k]);
            }
        }
        if ($ip !== '') {
            $h['ip:' . $ip] = $now;
        }
        if ($tgUid !== '') {
            $h['tg:' . $tgUid] = $now;
        }
        return $h;
    });
}

function bot_public_appeal_handler(array $m)
{
    $from      = (string) (isset($m['from']['id']) ? $m['from']['id'] : '');
    $chatId    = (string) (isset($m['chat']['id']) ? $m['chat']['id'] : '');
    $msgId     = isset($m['message_id']) ? (int) $m['message_id'] : 0;
    $firstName = trim((string) (isset($m['from']['first_name']) ? $m['from']['first_name'] : 'bạn'));
    $text      = isset($m['text']) ? trim((string) $m['text']) : '';

    if ($from === '' || $chatId === '' || !pub_rate_allow($from)) {
        return;
    }
    if (!function_exists('sh_extract_case_code') || !function_exists('sh_enrich_case_dossier')) {
        return;
    }

    // Handle /start (when user taps Unlock Assistant @devios_bot from the Block screen):
    // 1) IMMEDIATELY delete the user's /start message so "/start" NEVER shows in chat!
    // 2) Link their Telegram chat_id <-> IP/LID so future clicks push proactive greetings with ZERO /start!
    // 3) Send an instant natural greeting right away!
    if ($text !== '' && strpos($text, '/start') === 0) {
        if ($msgId > 0) {
            @tg_api_raw('deleteMessage', ['chat_id' => $chatId, 'message_id' => $msgId], 2);
        }
        $parts   = preg_split('/\s+/', $text, 2);
        $payload = isset($parts[1]) ? trim((string) $parts[1]) : '';
        $argCode = sh_extract_case_code($payload);
        $linkedCase = '';

        if ($payload !== '' && strpos($payload, 'u_') === 0 && function_exists('sh_resolve_bot_click_token')) {
            $clk = sh_resolve_bot_click_token($payload);
            if (is_array($clk)) {
                if (function_exists('sh_pub_tg_link_save')) {
                    sh_pub_tg_link_save($chatId, $firstName, (string) ($clk['ip'] ?? ''), (string) ($clk['lid'] ?? ''));
                }
                if (!empty($clk['case_code'])) {
                    $linkedCase = (string) $clk['case_code'];
                }
            }
        }

        if ($argCode === '') {
            pub_conv_set($from, ['step' => 'await_code', 'hint_code' => $linkedCase]);
            $hintLine = $linkedCase !== ''
                ? ("\n\n💡 Hệ thống nhận thấy phiên của bạn đang có <b>Mã xác minh</b> <code>" . tg_e($linkedCase) . "</code> — bạn chỉ cần <b>chạm sao chép và gửi mã đó</b> (kèm lý do nếu có) vào đây để mình xử lý ngay nhé!")
                : '';
            pub_send_reply(
                $chatId,
                'Chào <b>' . tg_e($firstName !== '' ? $firstName : 'bạn') . '</b>! 👋 Mình là trợ lý hỗ trợ mở khoá tự động 24/7 của <b>iosmods.xyz</b>.' . "\n\n"
                . 'Bạn hãy gửi <b>Mã xác minh</b> (dạng <code>IMX-XXXXXX-...</code> trên màn hình khoá của web) vào đây để mình kiểm tra nguyên nhân và xét mở khoá ngay cho bạn nhé.'
                . $hintLine
            );
            return;
        }
        $text = $argCode;
    }

    if ($text === '') {
        return;
    }

    $conv    = pub_conv_get($from);
    $imxCode = sh_extract_case_code($text);

    // If user is currently in 'await_reason' step and sent their explanation without repeating IMX-...
    $reasonInput = '';
    if ($imxCode === '' && is_array($conv) && ($conv['step'] ?? '') === 'await_reason' && !empty($conv['code'])) {
        $imxCode     = (string) $conv['code'];
        $reasonInput = $text;
    } elseif ($imxCode !== '') {
        // Extract any extra explanation written alongside the IMX-... code
        $stripped = trim(preg_replace('/[\s,.;:-]+/', ' ', str_ireplace($imxCode, ' ', $text)));
        if (strlen($stripped) >= 6) {
            $reasonInput = $stripped;
        }
    } else {
        pub_conv_set($from, ['step' => 'await_code']);
        pub_send_reply(
            $chatId,
            'Mình chưa nhận diện được <b>Mã xác minh</b> trong tin nhắn của bạn.' . "\n"
            . 'Bạn vui lòng quay lại màn hình khoá trên <b>iosmods.xyz</b>, bấm nút <b>Sao chép</b> ở mục <b>Mã xác minh</b> (bắt đầu bằng <code>IMX-...</code>) rồi dán nguyên mã đó gửi vào đây giúp mình nhé.'
        );
        return;
    }

    $info = sh_enrich_case_dossier(bdb(), $imxCode);
    if (!$info || empty($info['active']) || empty($info['ip'])) {
        pub_conv_clear($from);
        pub_send_reply(
            $chatId,
            'Mình vừa kiểm tra mã <code>' . tg_e($imxCode) . '</code> thì thấy lệnh khoá của mã này <b>đã được gỡ hoặc đã hết thời gian khoá</b> rồi (mã xác minh sau khi mở sẽ tự huỷ bảo mật).' . "\n\n"
            . 'Bạn hãy quay lại trang <b>iosmods.xyz</b>, tải lại trang và bấm nút <b>Got it</b> trên màn hình là vào lại web bình thường nhé!'
        );
        return;
    }

    $code       = (string) $info['code'];
    $ip         = (string) $info['ip'];
    if (function_exists('sh_pub_tg_link_save')) {
        sh_pub_tg_link_save($chatId, $firstName, $ip, isset($info['lid']) ? (string) $info['lid'] : '');
    }
    $banType    = isset($info['ban_type']) ? (string) $info['ban_type'] : 'temp';
    $reasonOrig = isset($info['reason']) ? (string) $info['reason'] : 'Vi phạm quy tắc bảo mật hệ thống';
    $expTs      = isset($info['expires_ts']) ? (int) $info['expires_ts'] : 0;
    $leftSec    = $expTs > 0 ? max(1, $expTs - time()) : 0;
    $leftMin    = $leftSec > 0 ? max(1, (int) ceil($leftSec / 60)) : 0;
    $initDur    = !empty($info['initial_dur']) ? (int) $info['initial_dur'] : $leftSec;
    $hits       = (int) (isset($info['hits']) ? $info['hits'] : 1);
    $evasions   = (int) (isset($info['evasion_attempts']) ? $info['evasion_attempts'] : 0);
    $aiReduced  = !empty($info['ai_reduced']);
    $device     = isset($info['device']) ? (string) $info['device'] : 'Thiết bị trình duyệt';
    $isScriptUa = (strpos($device, 'Công cụ Script') === 0);
    $uname      = !empty($info['username']) ? (string) $info['username'] : '';

    // Check hostile / profane language in user message
    $isAbusiveMsg = ($reasonInput !== '' && (bool) preg_match('/\b(dm|dmm|vcl|vl|cc|fuck|shit|ngu|địt|đụ|lồn|cặc)\b/iu', $reasonInput));

    // =========================================================================
    // TIER 3: SEVERE VIOLATION / ATTACK / REPEAT EVASION / PERMANENT ADMIN LOCK
    // =========================================================================
    $isSevere = in_array($banType, ['ddos', 'waf', 'bug_buff', 'manual'], true)
        || $expTs === 0
        || $evasions >= 2
        || $isScriptUa
        || $isAbusiveMsg;

    if ($isSevere) {
        pub_conv_clear($from);
        if ($banType === 'ddos') {
            $humanExplain = 'Hệ thống ghi nhận địa chỉ IP <code>' . tg_e($ip) . '</code> đã gửi luồng truy cập dồn dập bất thường vượt ngưỡng an toàn (cơ chế chống tấn công DDoS tự động kích hoạt với ' . $hits . ' yêu cầu bị chặn).';
        } elseif ($banType === 'waf' || $banType === 'bug_buff' || $isScriptUa) {
            $humanExplain = 'Hệ thống phát hiện kết nối từ <code>' . tg_e($ip) . '</code> (' . tg_e($device) . ') có hành vi chạy công cụ tự động / Script hoặc gửi tham số can thiệp trực tiếp vào cấu trúc bảo mật của website (' . tg_e($reasonOrig) . ').';
        } elseif ($evasions >= 2) {
            $humanExplain = 'Sau khi bị tạm khoá, thiết bị của bạn đã cố tình đổi mạng / đổi IP <b>' . $evasions . ' lần liên tiếp</b> để lách cơ chế bảo mật thay vì chờ hết hạn.';
        } elseif ($expTs === 0 || $banType === 'manual') {
            $humanExplain = 'Địa chỉ IP <code>' . tg_e($ip) . '</code> đang nằm trong diện khoá bảo mật trực tiếp bởi Quản trị viên (' . tg_e($reasonOrig) . ').';
        } else {
            $humanExplain = 'Nội dung phản hồi hoặc mức độ vi phạm hiện tại (' . tg_e($reasonOrig) . ') không đủ điều kiện để hệ thống tự động gỡ khoá.';
        }

        pub_send_reply(
            $chatId,
            'Mình đã kiểm tra kỹ toàn bộ nhật ký bảo mật của mã <code>' . tg_e($code) . '</code> rồi nhé.' . "\n\n"
            . '🔎 <b>Nguyên nhân ghi nhận:</b> ' . $humanExplain . "\n\n"
            . 'Đối với các trường hợp liên quan đến luồng tấn công, can thiệp kỹ thuật hoặc lệnh khoá nghiêm trọng này, quyền hạn của mình không được phép tự ý mở khoá. <b>Vui lòng liên hệ Admin vì điều này tôi không thể mở giúp bạn.</b>' . "\n\n"
            . '<i>(Mình cũng đã chuyển hồ sơ mã xác minh của bạn sang cho Admin kiểm tra — nếu xác nhận nhầm lẫn, Admin vẫn có thể mở khoá trực tiếp cho bạn).</i>'
        );

        // Notify Admin once per case so Admin can still override & unban with 1 tap!
        $denyOnceKey = 'pub_deny_' . md5($code);
        $alreadySent = tg_store_read('pub_notified');
        if (empty($alreadySent[$denyOnceKey])) {
            tg_store_update('pub_notified', function ($s) use ($denyOnceKey) {
                if (!is_array($s)) $s = [];
                $s[$denyOnceKey] = time();
                return count($s) > 200 ? array_slice($s, -150, null, true) : $s;
            });
            tg_event('bot_appeal_deny', [
                'ip'        => $ip,
                'username'  => $uname,
                'case_code' => $code,
                'reason'    => $reasonOrig,
                'message'   => $reasonInput,
                'note'      => 'Bot từ chối mở ban (' . $banType . ')',
            ]);
            tg_flush(false);
        }
        return;
    }

    // =========================================================================
    // TIER 1: VERY MINOR FIRST-TIME MISTAKE (<= 6m spam OR 2m VPN already turned off) -> UNBAN 100% IMMEDIATELY!
    // =========================================================================
    $isVeryMinor = (
            in_array($banType, ['prod_spam', 'key_spam'], true)
            && $expTs > 0
            && $initDur <= 360
            && $evasions === 0
            && $hits <= 8
            && !pub_was_recently_forgiven($ip, $from)
        ) || (
            $banType === 'vpn_block'
            && $expTs > 0
            && $evasions === 0
            && empty($info['is_vpn'])
            && !pub_was_recently_forgiven($ip, $from)
        );

    if ($isVeryMinor) {
        pub_conv_clear($from);
        pub_mark_forgiven($ip, $from);
        if (bot_ipg_ready()) {
            ipg_unban(bdb(), $ip, 'devios_bot_ai', true);
        } elseif (function_exists('sh_full_clean_slate_unban')) {
            sh_full_clean_slate_unban(bdb(), $ip, $uname, true, true, 'devios_bot_ai');
        }

        $whatDid = ($banType === 'key_spam')
            ? 'nhập sai mã Key liên tiếp hơi nhanh trong thời gian ngắn'
            : 'bấm lấy sản phẩm lặp lại liên tục khi tài khoản chưa kích hoạt Key';

        pub_send_reply(
            $chatId,
            'Mình vừa xem xét kỹ mã <code>' . tg_e($code) . '</code> của bạn rồi nhé.' . "\n\n"
            . '🔎 <b>Kết quả kiểm tra:</b> Địa chỉ IP <code>' . tg_e($ip) . '</code> trên <b>' . tg_e($device) . '</b> chỉ vừa ' . $whatDid . ' (vi phạm lần đầu rất nhẹ, không bật công cụ gian lận và không cố lách khoá).' . "\n\n"
            . '✅ Vì lỗi này rất nhẹ do thao tác tay hơi vội nên <b>mình đã mở khoá hoàn toàn luôn cho bạn rồi nhé</b>, không cần phải chờ giảm giờ đâu!' . "\n\n"
            . '👉 Bây giờ bạn quay lại <b>iosmods.xyz</b>, màn hình sẽ hiện <b>Đã tự động mở khoá</b> — bạn chỉ cần bấm nút <b>Got it</b> là vào web bình thường. Lần sau nhớ kích hoạt Key hợp lệ trước khi bấm mở sản phẩm giúp mình nha!'
        );

        tg_event('bot_appeal_unban', [
            'ip'        => $ip,
            'username'  => $uname,
            'case_code' => $code,
            'reason'    => $reasonOrig,
            'note'      => 'Bot tự mở khoá luôn (lỗi nhẹ lần đầu)',
        ]);
        tg_flush(false);
        return;
    }

    // =========================================================================
    // TIER 2: NORMAL / MODERATE VIOLATION -> ASK REASON & MODERATELY REDUCE TIME
    // =========================================================================
    if ($aiReduced) {
        pub_conv_clear($from);
        pub_send_reply(
            $chatId,
            'Mã xác minh <code>' . tg_e($code) . '</code> của bạn trước đó <b>đã được mình hỗ trợ giảm thời gian chờ 1 lần rồi</b> (hiện chỉ còn khoảng <b>' . $leftMin . ' phút</b> nữa là hệ thống tự động mở khoá).' . "\n\n"
            . 'Mỗi phiên tạm khoá mình chỉ được phép giảm thời gian một lần vừa phải để đảm bảo công bằng. Bạn chịu khó đợi nốt ít phút còn lại rồi bấm <b>Got it</b> trên web nhé; nếu thật sự cần mở ngay lập tức, vui lòng liên hệ Admin vì điều này tôi không thể mở giúp bạn thêm nữa.'
        );
        return;
    }

    if ($reasonInput === '') {
        pub_conv_set($from, ['step' => 'await_reason', 'code' => $code]);
        pub_send_reply(
            $chatId,
            'Mình đã tra cứu mã <code>' . tg_e($code) . '</code> của bạn:' . "\n"
            . '• Địa chỉ IP: <code>' . tg_e($ip) . '</code> (' . tg_e($device) . ')' . "\n"
            . '• Nguyên nhân tạm khoá: <b>' . tg_e($reasonOrig) . '</b>' . "\n"
            . '• Thời gian còn lại: <b>' . $leftMin . ' phút</b>' . "\n\n"
            . 'Bạn nhắn ngắn gọn cho mình biết <b>lúc nãy bạn đang thao tác gì mà bị khoá</b> (ví dụ quên tắt VPN/1.1.1.1, mạng chậm bấm nhầm nhiều lần, hay lỡ chạm phím tắt...) để mình xem xét giảm bớt thời gian chờ giúp bạn nhé!'
        );
        return;
    }

    $cleanLen = function_exists('mb_strlen') ? mb_strlen($reasonInput, 'UTF-8') : strlen($reasonInput);
    if ($cleanLen < 5 || preg_match('/^(.)\1{4,}$/u', $reasonInput)) {
        pub_conv_set($from, ['step' => 'await_reason', 'code' => $code]);
        pub_send_reply(
            $chatId,
            'Bạn miêu tả rõ hơn bằng một câu bình thường giúp mình nhé (ví dụ lúc nãy bạn đang làm thao tác gì trên web) để mình có cơ sở xét giảm thời gian cho bạn nha.'
        );
        return;
    }

    pub_conv_clear($from);
    if ($leftSec <= 210) {
        pub_send_reply(
            $chatId,
            'Cảm ơn bạn đã phản hồi nhé. Hiện tại lệnh tạm khoá của IP <code>' . tg_e($ip) . '</code> chỉ còn chưa đầy <b>' . $leftMin . ' phút</b> nữa là tự động mở hoàn toàn rồi, bạn đợi thêm đôi chút rồi tải lại trang bấm <b>Got it</b> để vào web bình thường nhé!'
        );
        return;
    }

    // Moderate reduction (~45% cut, keeping at least 3 minutes = 180s — "giảm vừa phải")
    $newLeftSec = max(180, (int) round($leftSec * 0.55));
    if ($leftSec - $newLeftSec < 90) {
        $newLeftSec = max(180, $leftSec - 120);
    }
    $newExpTs = function_exists('sh_reduce_ban_duration') ? sh_reduce_ban_duration(bdb(), $ip, $newLeftSec, $code) : (time() + $newLeftSec);
    $newMin   = max(1, (int) ceil($newLeftSec / 60));

    $remindTip = ($banType === 'vpn_abuse' || !empty($info['is_vpn']))
        ? 'Trong lúc chờ bạn nhớ tắt hẳn ứng dụng VPN / 1.1.1.1 trước khi vào lại web nhé!'
        : 'Khi vào lại web bạn nhớ thao tác từ tốn và tuân thủ nội quy để không bị hệ thống khoá lại nhé!';

    pub_send_reply(
        $chatId,
        'Cảm ơn bạn đã chia sẻ rõ ràng nhé. Mình đã đối chiếu lời giải thích của bạn với nhật ký hệ thống và thấy trường hợp này ở mức độ bình thường, không có mục đích phá hoại.' . "\n\n"
        . '⏱ Mình vừa hỗ trợ <b>giảm thời gian tạm khoá vừa phải</b> cho IP <code>' . tg_e($ip) . '</code> từ <b>' . $leftMin . ' phút</b> xuống còn <b>' . $newMin . ' phút</b> (tự động mở lúc <b>' . date('H:i', (int) $newExpTs) . '</b>).' . "\n\n"
        . 'Đợi hết ' . $newMin . ' phút này bạn quay lại <b>iosmods.xyz</b> bấm <b>Got it</b> là sử dụng bình thường nha. ' . $remindTip
    );

    tg_event('bot_appeal_reduce', [
        'ip'        => $ip,
        'username'  => $uname,
        'case_code' => $code,
        'reason'    => $reasonOrig,
        'message'   => $reasonInput,
        'old_min'   => $leftMin,
        'new_min'   => $newMin,
        'note'      => 'Bot giảm thời gian từ ' . $leftMin . 'p xuống ' . $newMin . 'p',
    ]);
    tg_flush(false);
}

function bot_on_message(array $m)
{
    $from    = (string) (isset($m['from']['id']) ? $m['from']['id'] : '');
    $chatId  = (string) (isset($m['chat']['id']) ? $m['chat']['id'] : '');
    $private = (isset($m['chat']['type']) ? $m['chat']['type'] : '') === 'private';

    if ($from === '' || !$private) {
        return;
    }

    // Non-admin private users ONLY interact with the sandboxed Autonomous AI Unban Assistant (@devios_bot)!
    if (!in_array($from, tg_admin_ids(), true)) {
        return bot_public_appeal_handler($m);
    }

    $msgId = isset($m['message_id']) ? (int) $m['message_id'] : 0;
    $text  = isset($m['text']) ? trim((string) $m['text']) : (isset($m['caption']) ? trim((string) $m['caption']) : '');
    $GLOBALS['CTX'] = [
        'admin'        => $from,
        'chat'         => $chatId,
        'mid'          => 0,
        'edit'         => false,
        'toast'        => '',
        'name'         => isset($m['from']['first_name']) ? (string) $m['from']['first_name'] : 'Admin',
        'preserve_msg' => false,
    ];

    $convNow = conv_get($from);

    // Schedule Admin's command/message for auto-deletion after fixed TTL (pw mode deletes immediately)
    if ($msgId > 0 && (!$convNow || $convNow['mode'] !== 'pw')) {
        tg_autodelete_register($chatId, $msgId);
    }

    // Check if Admin sent Telegram Custom Emoji(s) -> auto-ingest & replace 100% of bot icons!
    list($ceIds, $ceHints) = bot_extract_custom_emojis($m);
    if ($ceIds && (!$convNow || $convNow['mode'] !== 'emoji_pick') && ($text === '' || $text[0] !== '/')) {
        return bot_handle_auto_emoji($ceIds, $ceHints);
    }

    $hasMedia = !empty($m['photo']) || !empty($m['document']) || !empty($m['sticker']);
    if ($text === '' && !$convNow && !$hasMedia) {
        return;
    }

    // If Admin sent a photo/PNG directly without active conversation, offer 1-tap assignment as Main Website Logo!
    if ($hasMedia && !$convNow && ($text === '' || $text[0] !== '/')) {
        list($okUp, $resUp) = bot_save_uploaded_image($m, 'dslogo_');
        if ($okUp) {
            bot_set_config('brand_logo', $resUp);
            bot_purge_logo_caches();
            bot_log('tg_brand_logo', 'Đổi nhanh Logo toàn web -> ' . $resUp);
            return screen_appearance('✅ <b>Đã nhận ảnh &amp; cập nhật làm Logo chính cho toàn bộ trang iosmods.xyz!</b>' . "\n<code>" . tg_e($resUp) . '</code>');
        }
    }

    if ($text !== '' && $text[0] === '/') {
        preg_match('~^/([A-Za-z0-9_]+)(?:@\w+)?(?:\s+(.*))?$~s', $text, $mm);
        $cmd = strtolower(isset($mm[1]) ? $mm[1] : '');
        $arg = isset($mm[2]) ? trim($mm[2]) : '';
        switch ($cmd) {
            case 'start':
            case 'menu':
            case 'stats':
                conv_clear($from);
                return screen_home('👋 Xin chào <b>' . tg_e($GLOBALS['CTX']['name']) . '</b>!');
            case 'cancel':
                conv_clear($from);
                return screen_home('✖ Đã huỷ thao tác đang nhập.');
            case 'tuyet':
            case 'snow':
                conv_clear($from);
                if ($arg === 'on' || $arg === 'bat' || $arg === '1') {
                    bot_set_config('ds_snow', '1');
                    return screen_appearance('❄️ Đã <b>BẬT</b> hiệu ứng tuyết rơi trên giao diện chính.');
                }
                if ($arg === 'off' || $arg === 'tat' || $arg === '0') {
                    bot_set_config('ds_snow', '0');
                    return screen_appearance('🌤 Đã <b>TẮT</b> hiệu ứng tuyết rơi trên giao diện chính.');
                }
                return bot_ui_action('snow');
            case 'logo':
            case 'giaodien':
                conv_clear($from);
                return screen_appearance();
            case 'cauhinh':
            case 'caidat':
                conv_clear($from);
                return screen_config('menu');
            case 'baotri':
                conv_clear($from);
                return bot_maintenance($arg === 'on' ? 'on' : ($arg === 'off' ? 'off' : 'menu'));
            case 'dangbi':
            case 'dschan':
            case 'danhsachchan':
            case 'blocked':
                conv_clear($from);
                return screen_active_blocked(0);
            case 'check':
            case 'kiemtra':
                conv_clear($from);
                return $arg !== '' ? screen_smart_check($arg) : screen_smart_check('');
            case 'mokhoa':
                conv_clear($from);
                return $arg !== '' ? bot_unlock_route('run', $arg) : screen_unlock(0);
            case 'ai':
            case 'ddos':
                conv_clear($from);
                return screen_ai_hub();
            case 'help':
                return bot_help();
            case 'health':
                return screen_health();
            case 'id':
                return ui_send('🆔 ID Admin của bạn: <code>' . tg_e($from) . '</code>', kb([home_row()]));
            case 'user':
                if ($arg === '') {
                    conv_set($from, 'search_user');
                    return ui_send('🔍 Gửi tên tài khoản cần tìm:', kb([[btn('✖ Huỷ', 'cx')]]));
                }
                $exact = bq_row('SELECT id FROM users WHERE username = ? LIMIT 1', 's', [$arg]);
                return $exact ? bot_row_view('users', $exact['id']) : screen_users(0, $arg);
            case 'key':
                if ($arg === '') {
                    conv_set($from, 'search_key');
                    return ui_send('🔍 Gửi mã Key cần kiểm tra:', kb([[btn('✖ Huỷ', 'cx')]]));
                }
                return screen_key_search($arg);
            case 'genkey':
                $words = preg_split('/\s+/', strtolower($arg), -1, PREG_SPLIT_NO_EMPTY);
                $type  = (isset($words[0]) && $words[0] === 'vip') ? 'VIP' : 'FREE';
                $n     = isset($words[1]) ? (int) $words[1] : 1;
                $hours = isset($words[2]) ? (int) $words[2] : ($type === 'VIP' ? 720 : 24);
                return screen_gen_keys($type, $n, $hours);
            case 'sql':
                return bot_sql($arg);
            case 'bans':
                return screen_bans(0);
            case 'ban':
                $w  = preg_split('/\s+/', $arg, 2, PREG_SPLIT_NO_EMPTY);
                $ip = ipg_norm(isset($w[0]) ? $w[0] : '');
                if ($ip === '') {
                    conv_set($from, 'ban_ip');
                    return ui_send('🚫 Gửi địa chỉ IP cần chặn:', kb([[btn('✖ Huỷ', 'cx')]]));
                }
                return bot_do_ban($ip, isset($w[1]) ? $w[1] : '', '');
            case 'unban':
                $ip = ipg_norm($arg);
                return $ip !== '' ? bot_do_unban($ip) : screen_bans(0);
            case 'ip':
                return ipg_norm($arg) !== '' ? screen_ip($arg) : screen_bans(0);
            case 'emoji':
                if ($ceIds) {
                    return bot_handle_auto_emoji($ceIds, $ceHints);
                }
                return bot_emoji_gallery(0);
            case 'xoaluot':
                if ($arg === '') {
                    return ui_send('Dùng lệnh: <code>/xoaluot username</code>', kb([home_row()]));
                }
                $exact = bq_row('SELECT id FROM users WHERE username = ? LIMIT 1', 's', [$arg]);
                return $exact ? bot_user_action((int) $exact['id'], 'clear', 'ok') : ui_error('Không tìm thấy user', cbd('us', '', 0));
        }
        return bot_help();
    }

    // If Admin is currently answering a prompt (e.g. typing English/Vietnamese config text), process conv FIRST!
    if ($convNow && !in_array($convNow['mode'], ['case_ask', 'smart_check'], true)) {
        return bot_conv_input($convNow, $text, $m);
    }

    // Smart Auto-Extraction: Paste or forward ANY message containing IMX-... (even inside a sentence!)
    if (function_exists('sh_extract_case_code')) {
        $extractedCode = sh_extract_case_code($text);
        if ($extractedCode !== '') {
            conv_clear($from);
            return screen_case_lookup($extractedCode);
        }
    }
    if (preg_match('/^IMX-[A-Z0-9-]{10,40}$/i', $text)) {
        return screen_case_lookup($text);
    }

    if ($convNow) {
        return bot_conv_input($convNow, $text, $m);
    }

    // Smart Direct IP or Single-Token Username Check when no conversation is active!
    if (filter_var($text, FILTER_VALIDATE_IP) !== false) {
        return screen_smart_check($text);
    }
    if (preg_match('/^[A-Za-z0-9_.-]{3,36}$/', $text)) {
        return screen_smart_check($text);
    }

    return screen_home();
}

function bot_route($data, array $origMessage = [])
{
    $admin  = $GLOBALS['CTX']['admin'];
    $p      = explode('|', $data);
    $route  = array_shift($p);
    $a      = isset($p[0]) ? $p[0] : '';
    $b      = isset($p[1]) ? $p[1] : '';
    $c      = isset($p[2]) ? $p[2] : '';
    $cancel = kb([[btn('✖ Huỷ', 'cx')]]);

    switch ($route) {
        case 'noop':
            return;
        case 'qa':
            return bot_quick_alert_action($a, $b, $origMessage);
        case 'abl':
            return bot_active_blocked_action($a, $b);
        case 'chk':
            return screen_smart_check($a === 'ask' ? '' : $a);
        case 'csx':
            if ($a === 'ip_raw') {
                return screen_ip($b);
            }
            return bot_case_action($a, $b);
        case 'home':
            return screen_home();
        case 'cx':
            conv_clear($admin);
            return screen_home('✖ Đã huỷ thao tác.');
        case 'aihub':
            return screen_ai_hub();
        case 'sh':
            return bot_shield_action($a, $b);
        case 'uk':
            return bot_unlock_route($a, $b);
        case 'mt':
            return bot_maintenance($a !== '' ? $a : 'menu');
        case 'pm':
            return bot_toggle_product_maint((int) $a);
        case 'ppk':
            return bot_set_product_pkg((int) $a, (int) $b);
        case 'pim':
            conv_set($admin, 'up_prod_img', ['id' => (int) $a]);
            return ui_send('🖼 <b>ĐỔI ẢNH ĐẠI DIỆN SẢN PHẨM #' . (int) $a . '</b>' . "\n" . hr() . "\n"
                . 'Hãy <b>gửi trực tiếp bức ảnh</b> hoặc <b>dán đường dẫn ảnh (https://...)</b> vào khung chat:',
                kb([[btn('✖ Huỷ bỏ', cbd('dr', 'products', (int) $a))]]));
        case 'ui':
            return bot_ui_action($a, $b);
        case 'ad':
            $sec = tg_autodelete_set_ttl((int) $a);
            toast($sec > 0 ? '⏱ Tự xoá lệnh sau ' . $sec . 's' : '⏸ Đã tắt tự xoá lệnh');
            return screen_alerts();
        case 'us':
            return screen_users((int) $b, rawurldecode($a));
        case 'su':
            conv_set($admin, 'search_user');
            return ui_send('🔍 Gửi một phần tên tài khoản cần tìm:', $cancel);
        case 'ky':
            return screen_keys();
        case 'kg':
            return screen_gen_keys($a, $b, $c);
        case 'kl':
            return screen_key_list($a, $b, $c);
        case 'sk':
            conv_set($admin, 'search_key');
            return ui_send('🔍 Gửi mã Key (hoặc một phần mã):', $cancel);
        case 'kx':
            return bot_key_action($a, (int) $b, $c);
        case 'pr':
            return screen_products((int) $a);
        case 'pa':
            conv_set($admin, 'pn');
            return ui_send('📦 <b>THÊM SẢN PHẨM</b> · Bước 1/4' . "\nGửi <b>tên sản phẩm</b>:", $cancel);
        case 'pt':
            return bot_product_add_finish($a);
        case 'px':
            return bot_product_swap((int) $a);
        case 'cf':
            return screen_config($a !== '' ? $a : 'menu', (int) $b);
        case 'cfk':
            return screen_cfg_item($a, $b !== '' ? $b : 'menu', (int) $c);
        case 'cfs':
            $dArg = isset($p[3]) ? (int) $p[3] : 0;
            $newV = ($b === '__EMPTY__') ? '' : $b;
            bot_set_config($a, $newV);
            if (in_array($a, ['brand_logo', 'brand_logo_scale', 'brand_logo_height', 'ds_logo_h', 'ds_logo_skew', 'ds_shine', 'ds_shine_seconds', 'favicon_url', 'favicon_bg'], true)) {
                bot_purge_logo_caches();
            }
            bot_log('tg_config', $a . ' = ' . ($newV === '' ? '(mặc định)' : $newV));
            toast('✅ Đã cập nhật cài đặt');
            return screen_cfg_item($a, $c !== '' ? $c : 'menu', $dArg, '✅ <b>Đã cập nhật thành công!</b>');
        case 'cfe':
            list(, $viLabel, , $viDesc) = bot_vi_cfg_lookup($a);
            $curV = (string) bq_val('SELECT config_value FROM site_config WHERE config_key = ?', 's', [$a], '');
            conv_set($admin, 'cfg_val', ['k' => $a, 'g' => $b !== '' ? $b : 'menu', 'p' => (int) $c]);
            return ui_send('✏️ <b>NHẬP GIÁ TRỊ MỚI CHO: ' . tg_e($viLabel) . '</b>' . "\n" . hr() . "\n"
                . '📌 ' . tg_e($viDesc) . "\n"
                . '💾 Đang lưu: <code>' . tg_e($curV === '' ? '(Trống / Mặc định)' : tg_clip($curV, 300)) . "</code>\n\n"
                . 'Hãy gửi nội dung mới vào khung chat (bạn nhập <b>tiếng Anh</b> hay <b>tiếng Việt</b> hệ thống đều lưu <b>chính xác 100% từng ký tự</b>):',
                kb([[btn('✖ Huỷ bỏ', cbd('cfk', $a, $b !== '' ? $b : 'menu', (int) $c))]]));
        case 'cn':
            conv_set($admin, 'cfg_key');
            return ui_send('⚙️ Gửi <b>mã cấu hình mới</b> (gồm chữ, số hoặc dấu gạch dưới _):', $cancel);
        case 'ct':
            return bot_config_toggle($a);
        case 'db':
            return screen_db();
        case 'dt':
            return screen_table($a, (int) $b);
        case 'dr':
            return bot_row_view($a, $b);
        case 'de':
            return bot_edit_prompt($a, $b, $c);
        case 'un':
            return bot_undo($a);
        case 'dd':
            return bot_delete_row($a, $b, $c === 'ok');
        case 'ua':
            return bot_user_action((int) $a, $b, $c);
        case 'lg':
            return screen_logs_menu();
        case 'lt':
            return screen_log($a, (int) $b);
        case 'hc':
            return screen_health();
        case 'ht':
            tg_event('test');
            toast('🔔 Đã gửi thông báo kiểm tra');
            return screen_health();
        case 'al':
            return screen_alerts();
        case 'at':
            $cats = tg_categories();
            if (isset($cats[$a])) {
                $next = !tg_cat_on($a);
                tg_store_update('settings', function ($s) use ($a, $next) {
                    $s['cat'][$a] = $next;
                    return $s;
                });
            }
            return screen_alerts();
        case 'bn':
            return screen_bans((int) $a);
        case 'bv':
            return screen_ban_view((int) $a);
        case 'bi':
            conv_set($admin, 'ban_ip');
            return ui_send('🚫 Gửi <b>địa chỉ IP</b> cần khoá:', $cancel);
        case 'bs':
            conv_set($admin, 'ip_info');
            return ui_send('🔍 Gửi <b>địa chỉ IP</b> cần kiểm tra:', $cancel);
        case 'bk':
            return screen_ban_confirm($a, $b);
        case 'bo':
            return bot_do_ban($a, '', $b);
        case 'bt30':
            return bot_do_temp_ban($a, 1800, $b);
        case 'bz':
            conv_set($admin, 'ban_reason', ['ip' => ipg_norm($a), 'u' => $b]);
            return ui_send('✍ Gửi <b>lý do chặn</b> cho IP <code>' . tg_e($a) . '</code>:', $cancel);
        case 'bw':
            conv_set($admin, 'ban_reason_edit', ['ip' => ipg_norm($a)]);
            return ui_send('✍ Gửi <b>lý do mới</b> cho IP <code>' . tg_e($a) . '</code>:', $cancel);
        case 'bx':
            return bot_do_unban($a);
        case 'bu':
            return screen_user_ips((int) $a);
        case 'ba':
            return bot_ban_all((int) $a, $b);
        case 'ub':
            if ($b === 'ip' || $b === 'acc') {
                return bot_user_block((int) $a, '', $b === 'ip');
            }
            if ($b === 'why') {
                conv_set($admin, 'block_reason', ['id' => (int) $a]);
                return ui_send('✍ Gửi <b>lý do khoá tài khoản</b>:', $cancel);
            }
            return screen_block_confirm((int) $a);
        case 'uu':
            return bot_user_unblock((int) $a, $b);
        case 'em':
            return bot_emoji_gallery((int) $a);
        case 'ep':
            return bot_emoji_pick_start((int) $a);
        case 'eo':
            tg_store_update('settings', function ($s) {
                $s['emoji_off'] = empty($s['emoji_off']);
                return $s;
            });
            tg_store_update('emojidown', function () {
                return [];
            });
            tg_emoji_reset();
            return bot_emoji_gallery(0);
        case 'ez':
            if ($a !== 'ok') {
                return ui_send('🧹 <b>ĐẶT LẠI EMOJI MẶC ĐỊNH?</b>', kb([[btn('✅ Đồng ý đặt lại', 'ez|ok'), btn('✖ Huỷ', 'em|0')]]));
            }
            tg_emoji_vault_write(['picks' => [], 'pack_name' => '', 'pack_list' => [], 'pool' => [], 'default_id' => '', 'replace_all' => false]);
            toast('🧹 Đã đặt lại Emoji mặc định');
            return bot_emoji_gallery(0, '🧹 Đã đặt lại về biểu tượng mặc định.');
        case 'ac':
            return screen_activity($a, '', (int) $b);
        case 'au':
            return screen_activity('u', rawurldecode($a), (int) $b);
        case 'ai':
            return screen_activity('i', $a, (int) $b);
        case 'sq':
            return screen_sql_help();
        case 'sw':
            conv_set($admin, 'sql');
            return ui_send('🧮 Gửi <b>câu lệnh SQL</b> cần chạy:', $cancel);
        case 'sr':
            $payload = bot_unstash($a);
            return bot_sql(is_array($payload) && isset($payload['q']) ? (string) $payload['q'] : '');
        case 'sx':
            return bot_sql_exec($a, false);
        case 'sd':
            return bot_sql_exec($a, true);
    }
    return screen_home();
}

function bot_on_callback(array $q)
{
    $from    = (string) (isset($q['from']['id']) ? $q['from']['id'] : '');
    $chatId  = (string) (isset($q['message']['chat']['id']) ? $q['message']['chat']['id'] : '');
    $private = (isset($q['message']['chat']['type']) ? $q['message']['chat']['type'] : '') === 'private';

    // STRICT ADMIN-ONLY SILENCE: Non-admins get ZERO callback response!
    if ($from === '' || !$private || !in_array($from, tg_admin_ids(), true)) {
        return;
    }

    $origMsg = isset($q['message']) && is_array($q['message']) ? $q['message'] : [];
    $GLOBALS['CTX'] = [
        'admin'        => $from,
        'chat'         => $chatId,
        'mid'          => isset($origMsg['message_id']) ? (int) $origMsg['message_id'] : 0,
        'edit'         => true,
        'toast'        => '',
        'name'         => isset($q['from']['first_name']) ? (string) $q['from']['first_name'] : 'Admin',
        'preserve_msg' => false,
    ];

    try {
        $routeData = cbd_resolve((string) (isset($q['data']) ? $q['data'] : ''));
        bot_route($routeData, $origMsg);
    } finally {
        $ack = ['callback_query_id' => $q['id']];
        if ($GLOBALS['CTX']['toast'] !== '') {
            $ack['text'] = $GLOBALS['CTX']['toast'];
        }
        tg_api_raw('answerCallbackQuery', $ack, 4);
    }
}

/* ------------------------------------------------------------------
 * 15. One-Time Webhook Setup & Entry Point
 * ------------------------------------------------------------------ */

function bot_self_url()
{
    $custom = trim((string) tg_cfg('webhook_url', ''));
    if ($custom !== '') {
        return $custom;
    }
    $path = (string) strtok((string) (isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/telegram_bot.php'), '?');
    return bot_site_base_url() . $path;
}

function bot_setup()
{
    $lines   = [];
    $me      = tg_api_raw('getMe');
    $lines[] = (!empty($me['ok']) ? '✅' : '❌') . ' Bot: ' . (!empty($me['ok']) ? '@' . $me['result']['username'] : 'Lỗi token');

    $url = bot_self_url();
    $wh  = tg_api_raw('setWebhook', [
        'url'             => $url,
        'secret_token'    => (string) tg_cfg('webhook_secret', ''),
        'allowed_updates' => ['message', 'callback_query'],
        'max_connections' => 20,
    ]);
    $lines[] = (!empty($wh['ok']) ? '✅' : '❌') . ' Webhook: ' . $url;

    $cmds = [
        ['command' => 'start',   'description' => 'Hỗ trợ mở khoá tự động / Mở bảng Quản trị'],
        ['command' => 'menu',    'description' => 'Mở bảng điều khiển trung tâm (Admin)'],
        ['command' => 'tuyet',   'description' => 'Bật / Tắt hiệu ứng tuyết rơi giao diện chính'],
        ['command' => 'logo',    'description' => 'Đổi Logo toàn website iosmods.xyz & độ lấp loá'],
        ['command' => 'cauhinh', 'description' => 'Cài đặt toàn diện Website bằng tiếng Việt'],
        ['command' => 'dangbi',  'description' => 'Xem danh sách ai đang bị chặn'],
        ['command' => 'check',   'description' => 'Kiểm tra Tài khoản / IP / Mã IMX có bị chặn không'],
        ['command' => 'baotri',  'description' => 'Bật / Tắt chế độ bảo trì website'],
        ['command' => 'mokhoa',  'description' => 'Mở khoá nhận Key cho Tài khoản / IP'],
        ['command' => 'ai',      'description' => 'AI Giám sát Chống DDoS & VPN 24/7'],
        ['command' => 'genkey',  'description' => 'Tạo Key nhanh: /genkey vip 5 720'],
        ['command' => 'user',    'description' => 'Tìm thành viên: /user tên'],
        ['command' => 'key',     'description' => 'Tra cứu mã Key: /key MÃ'],
        ['command' => 'bans',    'description' => 'Danh sách địa chỉ IP đang khoá'],
        ['command' => 'ban',     'description' => 'Chặn IP: /ban IP lý_do'],
        ['command' => 'unban',   'description' => 'Gỡ chặn IP: /unban IP'],
        ['command' => 'emoji',   'description' => 'Kho Biểu tượng tuỳ chỉnh vĩnh viễn'],
        ['command' => 'health',  'description' => 'Kiểm tra sức khoẻ hệ thống'],
        ['command' => 'cancel',  'description' => 'Huỷ thao tác đang nhập'],
    ];
    tg_api_raw('setMyCommands', ['commands' => $cmds]);
    tg_api_raw('setMyShortDescription', ['short_description' => 'Trợ lý Mở Khoá Bảo Mật Tự Động 24/7 · iosmods.xyz']);
    tg_api_raw('setMyDescription', ['description' => "👋 Chào mừng bạn đến với Trợ lý Bảo mật 24/7 của iosmods.xyz!\n\nNếu bạn vừa bấm sang từ màn hình khoá, hãy dán Mã xác minh (IMX-...) gửi vào đây để hệ thống kiểm tra và hỗ trợ mở khoá ngay lập tức."]);

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><title>Cài đặt Bot</title><body style="font:15px/1.6 -apple-system,sans-serif;max-width:600px;margin:28px auto;padding:0 16px"><h2>Cài đặt Telegram Bot</h2><pre>'
        . htmlspecialchars(implode("\n", $lines), ENT_QUOTES, 'UTF-8') . '</pre></body>';
    exit;
}

if (isset($_GET['setup'])) {
    if (!is_string($_GET['setup']) || (string) tg_cfg('setup_key', '') === '' || !hash_equals((string) tg_cfg('setup_key', ''), $_GET['setup'])) {
        http_response_code(403);
        exit('Forbidden');
    }
    bot_setup();
}

if (isset($_GET['cron'])) {
    if (!is_string($_GET['cron']) || (string) tg_cfg('setup_key', '') === '' || !hash_equals((string) tg_cfg('setup_key', ''), $_GET['cron'])) {
        http_response_code(403);
        exit('Forbidden');
    }
    if (function_exists('sh_eternal_sentinel_tick')) {
        sh_eternal_sentinel_tick(bdb());
    }
    tg_autodelete_sweep();
    tg_scan_events(true);
    exit('ok');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('ok');
}

$expected = (string) tg_cfg('webhook_secret', '');
$given    = (string) (isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) ? $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] : '');
if ($expected !== '' && !hash_equals($expected, $given)) {
    http_response_code(403);
    exit('Forbidden');
}

$update = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($update)) {
    exit('ok');
}

$duplicate = false;
if (isset($update['update_id'])) {
    $uid = (int) $update['update_id'];
    tg_store_update('botstate', function ($s) use ($uid, &$duplicate) {
        if (isset($s['last']) && $uid <= (int) $s['last']) {
            $duplicate = true;
            return $s;
        }
        $s['last'] = $uid;
        return $s;
    });
}
if ($duplicate) {
    exit('ok');
}

try {
    tg_autodelete_sweep();
    if (isset($update['callback_query']) && is_array($update['callback_query'])) {
        bot_on_callback($update['callback_query']);
    } elseif (isset($update['message']) && is_array($update['message'])) {
        bot_on_message($update['message']);
    }
} catch (Throwable $e) {
    error_log('[telegram_bot.php] ' . $e);
    tg_event('site_error', ['where' => 'telegram_bot.php', 'error' => $e->getMessage()]);
}

http_response_code(200);
exit('ok');
