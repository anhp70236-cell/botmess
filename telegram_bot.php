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

function screen_home($note = '')
{
    $users   = (int) bq_val('SELECT COUNT(*) FROM users');
    $aFree   = (int) bq_val("SELECT COUNT(*) FROM users WHERE key_type = 'FREE' AND expiry_date > NOW()");
    $aVip    = (int) bq_val("SELECT COUNT(*) FROM users WHERE key_type = 'VIP' AND expiry_date > NOW()");
    $sFree   = (int) bq_val('SELECT COUNT(*) FROM keys_free WHERE status = 0');
    $sVip    = (int) bq_val('SELECT COUNT(*) FROM keys_vip WHERE status = 0');
    $pTotal  = (int) bq_val('SELECT COUNT(*) FROM products');
    $maint   = (string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'maintenance_mode'", '', [], '0') === '1';
    $blocked = bt_valid('tg_user_blocks') ? (int) bq_val('SELECT COUNT(*) FROM tg_user_blocks WHERE active = 1') : 0;
    $bans    = bot_ban_counts();
    $shCfg   = function_exists('sh_cfg') ? sh_cfg() : ['enabled' => true];
    $adSec   = tg_autodelete_ttl();
    $vault   = tg_emoji_vault_read();
    $emCnt   = count(isset($vault['picks']) && is_array($vault['picks']) ? $vault['picks'] : []);

    $t = '🛡 <b>' . tg_e(tg_cfg('site_name', 'IOSMODS.XYZ')) . '</b> · <b>ADMIN CP</b>' . "\n";
    if ($note !== '') {
        $t .= $note . "\n";
    }
    $t .= '<blockquote>'
        . '👥 Thành viên: <b>' . $users . '</b> · 🟢 Đang dùng Key: <b>' . ($aFree + $aVip) . '</b> <i>(F:' . $aFree . ' · V:' . $aVip . ')</i>' . "\n"
        . '🔑 Kho Key: <b>FREE ' . $sFree . '</b> · <b>VIP ' . $sVip . '</b> · 📦 Sản phẩm: <b>' . $pTotal . '</b>'
        . '</blockquote>' . "\n"
        . '<blockquote>'
        . '🧠 AI Chống DDoS: ' . (!empty($shCfg['enabled']) ? '🟢 <b>TỐI CAO</b>' : '🔴 <b>TẮT</b>') . ' · ☹️ Tạm khoá: <b>' . $bans['temp'] . '</b> · 🚫 Cấm: <b>' . $bans['perm'] . '</b>' . "\n"
        . '🛠 Bảo trì: ' . ($maint ? '🔴 <b>ĐANG BẬT</b>' : '🟢 <b>Đang mở</b>') . ' · ⏱ Tự xoá lệnh: <b>' . ($adSec > 0 ? $adSec . 's' : 'Tắt') . '</b>'
        . ($emCnt > 0 ? ' · 🎨 Emoji: <b>Đầy đủ</b>' : '')
        . ($blocked > 0 ? ' · ⛔ User khoá: <b>' . $blocked . '</b>' : '')
        . '</blockquote>' . "\n"
        . '<i>🕒 ' . tg_e(tg_now('H:i:s d/m/Y')) . '</i>';

    ui_send($t, kb([
        [btn('🔄 Làm mới', 'home'), btn($maint ? '🟢 Tắt bảo trì ngay' : '🛠 Chế độ bảo trì', 'mt|menu')],
        [btn('🔑 Kho Key & Tạo Key', 'ky'), btn('🔓 Mở khoá Get Key', 'uk|0')],
        [btn('👥 Thành viên', cbd('us', '', 0)), btn('📦 Sản phẩm & Tool', 'pr|0')],
        [btn('🛡 Chống DDoS & IP', 'bn|0'), btn('🧠 AI Giám sát VPN/Acc', 'aihub')],
        [btn('🎨 Emoji Premium', 'em|0'), btn('🔔 Thông báo & Tự xoá', 'al')],
        [btn('⚙️ Cấu hình Web', 'cf|0'), btn('🗄 Database & SQL', 'db'), btn('🩺 Sức khoẻ', 'hc')],
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

    $title  = (string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'maintenance_title'", '', [], 'System Maintenance');
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

    $stats   = function_exists('sh_stats_read') ? sh_stats_read() : [];
    $surgeOn = function_exists('sh_is_surge_active') ? sh_is_surge_active() : false;
    $drained = (int) (isset($stats['drained_streams']) ? $stats['drained_streams'] : 0);
    $scBlk   = (int) (isset($stats['script_blocks']) ? $stats['script_blocks'] : 0) + (int) (isset($stats['bug_blocks']) ? $stats['bug_blocks'] : 0);
    $happyN  = (int) (isset($stats['happy_gates']) ? $stats['happy_gates'] : 0);

    $t = '🧠 <b>AI GIÁM SÁT TỐI CAO · QUAN SÁT VĨNH VIỄN 24/7</b>' . "\n";
    if ($note !== '') {
        $t .= $note . "\n";
    }
    $t .= '<blockquote>'
        . '🩺 Độ ổn định Web: <b>' . $score . '/100</b> · Tự vá DDoS: ' . ($surgeOn ? '🛡 <b>ĐANG XẢ LUỒNG MẠNH</b>' : '🟢 <b>Sạch 100%</b>') . "\n"
        . '🛡 Khiên DDoS & Bug/Buff: ' . (!empty($shCfg['enabled']) ? '🟢 <b>BẬT</b>' : '🔴 <b>TẮT</b>') . ' · Đã xả: <b>' . $drained . '</b> luồng' . "\n"
        . '💻 Chống Script & Xem nguồn PC: ' . (!empty($shCfg['inspect_guard']) ? '🟢 <b>BẬT</b>' : '🔴 <b>TẮT</b>') . ' · Đã chặn: <b>' . $scBlk . '</b>' . "\n"
        . '😊 Cổng Mặt cười nhắc tắt VPN/Script: ' . (!empty($shCfg['vpn_gate_enabled']) ? '🟢 <b>BẬT</b>' : '🔴 <b>TẮT</b>') . ' (' . $happyN . ' lượt)' . "\n"
        . '☹️ Đang khoá đa lớp / Mặt buồn: <b>' . $bans['temp'] . '</b> · 🚫 Cấm vĩnh viễn: <b>' . $bans['perm'] . '</b>' . "\n"
        . '🕵️ Chặn VPN/Fake IP/Trùng Acc lấy Key (24h): <b>' . $vpn24 . '</b> lượt'
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

function screen_config($page = 0)
{
    $per   = 10;
    $total = (int) bq_val("SELECT COUNT(*) FROM site_config WHERE config_key <> 'tg_emoji_vault'");
    $pages = max(1, (int) ceil($total / $per));
    $page  = max(0, min((int) $page, $pages - 1));
    $rows  = bq_all("SELECT config_key, config_value FROM site_config WHERE config_key <> 'tg_emoji_vault' ORDER BY config_key ASC LIMIT " . $per . ' OFFSET ' . ($page * $per));
    $kbd   = [[btn('➕ Thêm cấu hình mới', 'cn')]];
    foreach ($rows as $r) {
        $val   = bt_secret('config_value', $r, 'site_config') ? '••••' : bv($r['config_value'], 18);
        $kbd[] = [btn('⚙️ ' . $r['config_key'] . ' = ' . $val, cbd('dr', 'site_config', $r['config_key']))];
    }
    if ($pages > 1) {
        $kbd[] = pager_row('cf', [], $page, $pages);
    }
    $kbd[] = home_row();
    ui_send('⚙️ <b>CẤU HÌNH WEBSITE (SITE_CONFIG)</b> · ' . $total . " mục\nĐồng bộ trực tiếp với bảng điều khiển <code>admin_cp.php</code>.", kb($kbd));
}

function screen_db()
{
    $rows = bq_all('SELECT TABLE_NAME AS t, TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME');
    $kbd  = [[btn('🧮 Mở Bảng Lệnh SQL', 'sq'), btn('📜 Xem Nhật Ký Hệ Thống', 'lg')]];
    $line = [];
    foreach ($rows as $r) {
        $line[] = btn('🗄 ' . $r['t'] . ' (' . ($r['n'] === null ? '?' : '~' . $r['n']) . ')', cbd('dt', $r['t'], 0));
        if (count($line) === 2) {
            $kbd[] = $line;
            $line  = [];
        }
    }
    if ($line) {
        $kbd[] = $line;
    }
    $kbd[] = home_row();
    ui_send('🗄 <b>CƠ SỞ DỮ LIỆU & SQL</b> · ' . count($rows) . ' bảng', kb($kbd));
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
    ui_send('📜 <b>NHẬT KÝ HỆ THỐNG</b>', kb([
        [btn('👑 Thao tác Admin', 'lt|admin_logs|0'), btn('👁 Hoạt động Web', 'ac|a|0')],
        [btn('🚫 Nhập sai Key', 'lt|key_attempts|0'), btn('🔐 Đăng nhập sai', 'lt|login_attempts|0')],
        [btn('🧮 Lịch sử SQL', 'lt|sql|0')],
        [btn('⬅ Database', 'db'), btn('🏠 Menu chính', 'home')],
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
    $map = ['users' => cbd('us', '', 0), 'keys_free' => 'kl|FREE|0|0', 'keys_vip' => 'kl|VIP|0|0', 'products' => 'pr|0', 'site_config' => 'cf|0'];
    return isset($map[$table]) ? $map[$table] : cbd('dt', $table, 0);
}

function bot_row_view($table, $pkv, $note = '')
{
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

    $t = '📄 <b>' . tg_e($table) . '</b> · <code>' . tg_e($pk) . ' = ' . tg_e($pkv) . '</code>' . "\n";
    if ($note !== '') {
        $t .= $note . "\n";
    }
    $fields = '';
    foreach ($row as $col => $val) {
        $shown   = bt_secret($col, $row, $table) ? '••••••••' : bv($val, 200);
        $fields .= '<b>' . tg_e($col) . ':</b> <code>' . tg_e($shown) . "</code>\n";
    }
    $t .= '<blockquote>' . rtrim($fields) . "</blockquote>\n";

    $kbd = [];
    if ($table === 'users') {
        $active = (int) bq_val('SELECT IF(expiry_date > NOW(), 1, 0) FROM users WHERE id = ?', 'i', [(int) $pkv]);
        $blk    = tg_user_block_row((string) $row['username']);
        $t .= '🔎 <b>Key hiện tại:</b> ' . ($active ? '🟢 Đang chạy (' . tg_e($row['key_type']) . ' · ' . (int) $row['turns_left'] . ' lượt)' : '⚪ Chưa có / hết hạn') . "\n";
        $t .= '⛔ <b>Tài khoản:</b> ' . ($blk ? '🔴 ĐANG KHOÁ (' . tg_e(tg_clip($blk['reason'], 60)) . ')' : '🟢 Bình thường');
        $id = (int) $pkv;
        $kbd[] = [btn('🎁 Cấp FREE 24h', 'ua|' . $id . '|free'), btn('💎 Cấp VIP 30d', 'ua|' . $id . '|vip')];
        $kbd[] = [btn('➕ +1 lượt', 'ua|' . $id . '|t+'), btn('➖ −1 lượt', 'ua|' . $id . '|t-')];
        $kbd[] = [btn('🔓 Mở khoá Get Key', cbd('uk', 'run', (string) $row['username'])), btn('🗑 Xoá lượt', 'ua|' . $id . '|clear')];
        $kbd[] = [btn('⛔ Thu hồi Key', 'ua|' . $id . '|revoke'), btn('🔑 Đổi mật khẩu', 'ua|' . $id . '|pw')];
        $kbd[] = [btn('🚫 Xem & Chặn IP', 'bu|' . $id), btn('👑 Quyền Admin', 'ua|' . $id . '|role')];
        $kbd[] = [$blk ? btn('✅ Mở khoá tài khoản', 'uu|' . $id) : btn('⛔ Khoá tài khoản', 'ub|' . $id)];
    } elseif ($table === 'keys_free' || $table === 'keys_vip') {
        $kbd[] = [(int) $row['status'] === 0
            ? btn('🚫 Huỷ hiệu lực Key này', 'kx|' . $table . '|' . (int) $pkv . '|void')
            : btn('♻️ Khôi phục thành chưa dùng', 'kx|' . $table . '|' . (int) $pkv . '|restore')];
    } elseif ($table === 'products') {
        $meta = bot_tool_meta_load();
        $isM  = !empty($meta[(string) (int) $pkv]['maint']);
        $kbd[] = [
            btn('🔁 Đổi FREE ⇄ VIP', 'px|' . (int) $pkv),
            btn($isM ? '🟢 Tắt bảo trì Tool này' : '🛠 Bảo trì riêng Tool này', 'pm|' . (int) $pkv, $isM ? 'success' : 'danger'),
        ];
    } elseif ($table === 'site_config' && isset($row['config_value']) && ($row['config_value'] === '0' || $row['config_value'] === '1')) {
        $kbd[] = [btn('🔀 Đảo trạng thái 0 / 1', cbd('ct', $pkv))];
    }

    $line = [];
    foreach ($row as $col => $val) {
        if ($col === $pk) {
            continue;
        }
        $line[] = btn('✏️ ' . $col, cbd('de', $table, $pkv, $col), '');
        if (count($line) === 2) {
            $kbd[] = $line;
            $line  = [];
        }
    }
    if ($line) {
        $kbd[] = $line;
    }
    if (tg_cfg('allow_destructive')) {
        $kbd[] = [btn('🗑 Xoá dòng này', cbd('dd', $table, $pkv))];
    }
    $kbd[] = [btn('⬅ Quay lại', bot_back_for($table)), btn('🏠 Menu chính', 'home')];
    ui_send($t, kb($kbd));
}

function bot_edit_prompt($table, $pkv, $col)
{
    $pk   = bt_valid($table) ? bt_pk($table) : null;
    $cols = bt_cols($table);
    if ($pk === null || !isset($cols[$col]) || $col === $pk) {
        return ui_error('Không thể sửa cột này', 'db');
    }
    $row = bq_row('SELECT * FROM `' . $table . '` WHERE `' . $pk . '` = ? LIMIT 1', 's', [(string) $pkv]);
    if (!$row) {
        return ui_error('Không tìm thấy dòng', bot_back_for($table));
    }
    conv_set($GLOBALS['CTX']['admin'], 'edit', ['t' => $table, 'pk' => (string) $pkv, 'c' => $col]);
    $cur = bt_secret($col, $row, $table) ? '••••••••' : bv($row[$col], 300);
    ui_send('✏️ <b>CHỈNH SỬA</b> <code>' . tg_e($table . '.' . $col) . '</code>' . "\n" . hr() . "\n"
        . 'Giá trị hiện tại: <code>' . tg_e($cur) . "</code>\n\n"
        . "Gửi giá trị mới vào khung chat:\n• Gửi <code>NULL</code> để đặt rỗng\n• Gửi <code>NOW</code> cho cột ngày giờ",
        kb([[btn('✖ Huỷ', 'cx')]]));
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

    if ($upper === 'NULL') {
        if ($cols[$col]['Null'] !== 'YES') {
            conv_set($GLOBALS['CTX']['admin'], 'edit', ['t' => $table, 'pk' => (string) $pkv, 'c' => $col]);
            return ui_send('❌ Cột này không cho phép NULL. Hãy gửi giá trị khác.', kb([[btn('✖ Huỷ', 'cx')]]));
        }
        $val = null;
    } elseif ($upper === 'NOW' && preg_match('/date|time/', $type)) {
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
        [btn('➕ Chặn IP thủ công', 'bi'), btn('🔍 Kiểm tra 1 IP', 'bs')],
        [btn('🧠 Cài đặt Chống DDoS & Whitelist', 'aihub')],
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
    $b     = ipg_find(bdb(), $ip);
    $on    = $b && (int) $b['active'] === 1;
    $users = bot_ip_users($ip);
    list($canBan, $safeWhy) = ipg_can_ban($ip);

    $t = '🌐 <b>KIỂM TRA ĐỊA CHỈ IP</b> · <code>' . tg_e($ip) . "</code>\n";
    if ($note !== '') {
        $t .= $note . "\n";
    }
    $t .= '<blockquote>';
    $t .= '🛡 Trạng thái: ' . ($on ? '🚫 ĐANG BỊ KHOÁ' : (!$canBan ? '🟢 MIỄN TRỪ AN TOÀN (' . tg_e($safeWhy) . ')' : '🟢 Đang hoạt động bình thường')) . "\n";
    if ($b) {
        $t .= '📝 Lý do gần nhất: ' . tg_e($b['reason']) . "\n";
    }
    $t .= '👥 Thành viên trên IP: ' . ($users ? '<code>' . tg_e(implode(', ', $users)) . '</code>' : '<i>Chưa ghi nhận</i>');
    $t .= '</blockquote>';

    $kbd = [];
    if ($on) {
        $kbd[] = [btn('🔓 Gỡ chặn ngay', 'bx|' . $ip, 'success')];
    } elseif ($canBan) {
        $kbd[] = [btn('☹️ Tạm khoá 30p (Mặt buồn)', cbd('bt30', $ip)), btn('🚫 Chặn vĩnh viễn', 'bk|' . $ip)];
    }
    $kbd[] = [btn('🔓 Mở khoá Get Key cho IP này', cbd('uk', 'run', $ip))];
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
        . '✨ <b>Cơ chế tự động:</b> Bạn chỉ cần <b>gửi bất kỳ Emoji Telegram Premium nào vào cuộc trò chuyện này</b>, Bot sẽ tự động nhận diện bộ Emoji, thay thế <b>100% toàn bộ icon thường</b> thành Emoji Telegram và <b>lưu vĩnh viễn vào MySQL</b> (kể cả khi bạn hết hạn Premium vẫn không mất dữ liệu)!' . "\n"
        . '📦 Đã lưu vĩnh viễn: <b>' . count($picks) . '/' . count($slots) . '</b> vị trí'
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
    $text  = isset($m['text']) ? (string) $m['text'] : '';
    foreach ((array) (isset($m['entities']) ? $m['entities'] : []) as $en) {
        if (isset($en['type']) && $en['type'] === 'custom_emoji' && !empty($en['custom_emoji_id'])) {
            $ids[]   = (string) $en['custom_emoji_id'];
            $hints[] = '';
        }
    }
    return [$ids, $hints];
}

function bot_handle_auto_emoji(array $ids, array $hints = [])
{
    $res = tg_emoji_ingest_custom_ids($ids, $hints);
    if (empty($res['ok'])) {
        return ui_error('Không đọc được Emoji vừa gửi, vui lòng thử gửi lại.', 'em|0');
    }
    bot_log('tg_emoji_auto', 'Tự động áp dụng & lưu vĩnh viễn ' . $res['mapped'] . ' Emoji Premium' . ($res['pack'] !== '' ? ' (pack ' . $res['pack'] . ')' : ''));
    $note = '✅ <b>ĐÃ NHẬN & THAY THẾ TOÀN BỘ ICON THÀNH EMOJI TELEGRAM!</b>' . "\n"
        . '💾 Đã lưu vĩnh viễn vào Database (giữ nguyên kể cả khi hết Premium).'
        . ($res['pack'] !== '' ? ' Bộ: <code>' . tg_e($res['pack']) . '</code> (' . (int) $res['pack_count'] . ' emoji).' : '');
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
            bot_log('tg_config', $d['k'] . ' set');
            toast('✅ Đã lưu cấu hình');
            return bot_row_view('site_config', $d['k'], '✅ <b>Đã lưu</b>');

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

        case 'block_reason':
            return bot_user_block((int) $d['id'], $text, true);
    }
    return screen_home();
}

function bot_help()
{
    ui_send('🤖 <b>DANH SÁCH LỆNH NHANH</b>' . "\n" . '<blockquote>'
        . "/menu · Bảng điều khiển Admin CP\n"
        . "/baotri · Quản lý bảo trì website\n"
        . "/mokhoa <code>user_hoặc_IP</code> · Mở khoá Get Key\n"
        . "/user <code>tên</code> · /key <code>MÃ</code> · /genkey <code>vip 5 720</code>\n"
        . "/ban <code>IP lý_do</code> · /unban <code>IP</code> · /ip <code>IP</code>\n"
        . "/xoaluot <code>tên</code> · /emoji · /cancel"
        . '</blockquote>', kb([home_row()]));
}

function bot_on_message(array $m)
{
    $from    = (string) (isset($m['from']['id']) ? $m['from']['id'] : '');
    $chatId  = (string) (isset($m['chat']['id']) ? $m['chat']['id'] : '');
    $private = (isset($m['chat']['type']) ? $m['chat']['type'] : '') === 'private';

    // STRICT ADMIN-ONLY SILENCE: Non-admins receive ZERO reply!
    if ($from === '' || !$private || !in_array($from, tg_admin_ids(), true)) {
        return;
    }

    $msgId = isset($m['message_id']) ? (int) $m['message_id'] : 0;
    $text  = isset($m['text']) ? trim((string) $m['text']) : '';
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

    if ($text === '' && !$convNow) {
        return;
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
            case 'baotri':
                conv_clear($from);
                return bot_maintenance($arg === 'on' ? 'on' : ($arg === 'off' ? 'off' : 'menu'));
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

    if ($convNow) {
        return bot_conv_input($convNow, $text, $m);
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
            return screen_config((int) $a);
        case 'cn':
            conv_set($admin, 'cfg_key');
            return ui_send('⚙️ Gửi <b>tên cấu hình mới</b> (chữ, số, _):', $cancel);
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
        ['command' => 'menu',    'description' => 'Mở bảng điều khiển Admin CP'],
        ['command' => 'baotri',  'description' => 'Bật / Tắt bảo trì website'],
        ['command' => 'mokhoa',  'description' => 'Mở khoá Get Key Free cho User / IP'],
        ['command' => 'ai',      'description' => 'AI Giám sát Chống DDoS & VPN'],
        ['command' => 'genkey',  'description' => 'Tạo Key nhanh: /genkey vip 5 720'],
        ['command' => 'user',    'description' => 'Tìm thành viên: /user tên'],
        ['command' => 'key',     'description' => 'Tra cứu mã Key: /key MÃ'],
        ['command' => 'bans',    'description' => 'Danh sách IP đang khoá'],
        ['command' => 'ban',     'description' => 'Chặn IP: /ban IP lý_do'],
        ['command' => 'unban',   'description' => 'Gỡ chặn IP: /unban IP'],
        ['command' => 'emoji',   'description' => 'Kho Emoji Premium vĩnh viễn'],
        ['command' => 'health',  'description' => 'Kiểm tra sức khoẻ hệ thống'],
        ['command' => 'cancel',  'description' => 'Huỷ thao tác đang nhập'],
    ];
    tg_api_raw('setMyCommands', ['commands' => $cmds]);

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
