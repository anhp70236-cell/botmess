<?php /* UPGRADE-V2 */
/* ==========================================================================
   telegram_bot.php  —  DEVNVIOS Telegram control panel (webhook endpoint)

   * Webhook:  Telegram calls this file. Every call must carry the secret token AND come from an admin_ids account.
   * Setup:    open  telegram_bot.php?setup=<setup_key>  once (sets the webhook + command menu).
   * Cron:     optional  telegram_bot.php?cron=<setup_key>  every minute = alerts even when nobody visits the site.
   * Data protection: by default the bot can NOT delete anything (see allow_destructive in telegram_config.php).
   * PHP 7.2 compatible. admin_cp is not touched.
   ========================================================================== */

ob_start();
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

require_once __DIR__ . '/telegram.php';

if (!tg_enabled()) {
    http_response_code(503);
    exit('Telegram is not configured yet. Fill in telegram_config.php (bot_token + admin_ids).');
}

tg_boot('bot'); // before db.php so a dead database is reported too
require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (isset($conn) && $conn instanceof mysqli) {
    try {
        $conn->set_charset('utf8mb4');
    } catch (Throwable $e) {
        // keep the charset db.php chose
    }
}

if (is_file(__DIR__ . '/ipguard.php')) {
    require_once __DIR__ . '/ipguard.php';
}
if (!function_exists('ipg_norm')) { // ipguard.php missing: keep the bot alive (IP bans then show a clear message instead of crashing)
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
tg_ensure_tables(); // ip_bans + tg_activity (CREATE IF NOT EXISTS, never touches existing data)

$CTX = ['admin' => '', 'chat' => '', 'mid' => 0, 'edit' => false, 'toast' => '', 'name' => ''];

/* ------------------------------------------------------------------
 * Database helpers (prepared statements only)
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
    if ($types !== '') {
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

/** First column of the first row; any problem returns $default (used for dashboard numbers). */
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

/* Tables / columns are always checked against the real database before they are used in SQL */

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
    if (preg_match('/pass|secret|token|hash/i', $col)) {
        return true;
    }
    return $table === 'site_config' && $col === 'config_value' && is_array($row)
        && preg_match('/secret|pass|token|salt/i', (string) (isset($row['config_key']) ? $row['config_key'] : '')) === 1;
}

/** One value for display (NULL, line breaks and length handled). */
function bv($v, $max = 200)
{
    if ($v === null) {
        return 'NULL';
    }
    return tg_clip(str_replace(["\r\n", "\r", "\n"], ' ⏎ ', (string) $v), $max);
}

/* ------------------------------------------------------------------
 * Buttons, callback data, long-payload stash
 * ------------------------------------------------------------------ */

/** Button colour: danger (red) for destructive actions, success (green) for confirmations. '' = no colour. */
function bot_btn_style($text, $data)
{
    if (preg_match('~^(dd|bk|bo|bz|ub|ba)\|~', $data) || preg_match('~^ua\|\d+\|(revoke|clear)~', $data) || preg_match('~^kx\|[^|]*\|\d+\|void~', $data)) {
        return 'danger';
    }
    if (preg_match('~^(✅|➕|🎁)~u', $text)) {
        return 'success';
    }
    if (preg_match('~^(🔄|✍)~u', $text)) {
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
    return [btn('🏠 Menu', 'home')];
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

/** Build callback_data from pieces. Telegram allows 64 bytes: longer data is stashed and referenced as ~|id. */
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
        $row[] = btn('◀', call_user_func_array('cbd', array_merge([$route], $extra, [$page - 1])));
    }
    $row[] = btn(($page + 1) . ' / ' . $pages, 'noop');
    if ($page < $pages - 1) {
        $row[] = btn('▶', call_user_func_array('cbd', array_merge([$route], $extra, [$page + 1])));
    }
    return $row;
}

/* ------------------------------------------------------------------
 * Sending / editing messages
 * ------------------------------------------------------------------ */

function ui_send($text, $markup = null)
{
    $c      = $GLOBALS['CTX'];
    $params = [
        'chat_id' => $c['chat'], 'text' => tg_clip($text, 4000),
        'parse_mode' => 'HTML', 'disable_web_page_preview' => true,
    ];
    if ($markup !== null) {
        $params['reply_markup'] = $markup;
    }
    if ($c['edit'] && $c['mid']) {
        $p = $params;
        $p['message_id'] = $c['mid'];
        $res = tg_api('editMessageText', $p);
        if (!empty($res['ok']) || stripos((string) (isset($res['description']) ? $res['description'] : ''), 'not modified') !== false) {
            return;
        }
    }
    $res = tg_api('sendMessage', $params);
    if (empty($res['ok']) && stripos((string) (isset($res['description']) ? $res['description'] : ''), 'parse') !== false) {
        unset($params['parse_mode']);
        $params['text'] = html_entity_decode(strip_tags($params['text']), ENT_QUOTES, 'UTF-8');
        tg_api('sendMessage', $params);
    }
}

function toast($text)
{
    $GLOBALS['CTX']['toast'] = $text;
}

function ui_error($text, $backCb = 'home')
{
    ui_send('❌ <b>' . tg_e($text) . '</b>', kb([[btn('⬅ Back', $backCb), btn('🏠 Menu', 'home')]]));
}

function hr()
{
    return '<code>┈┈┈┈┈┈┈┈┈┈┈┈┈┈┈┈</code>';
}

/* Conversation state (what the bot is waiting for the admin to type) */

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

/** Write to admin_logs (same table admin_cp uses, marked tg:<id>) and tell the other admins. */
function bot_log($action, $detail)
{
    $admin = $GLOBALS['CTX']['admin'];
    try {
        bq_exec(
            'INSERT INTO admin_logs (user_id, username, action, detail, ip, created_at) VALUES (NULL, ?, ?, ?, ?, NOW())',
            'ssss',
            ['tg:' . $admin, $action, tg_clip($detail, 480), 'telegram']
        );
    } catch (Throwable $e) {
        error_log('[telegram_bot.php] log: ' . $e->getMessage());
    }
    tg_event('bot_audit', ['admin' => $admin, 'action' => $action, 'detail' => $detail]);
}

/* ------------------------------------------------------------------
 * Screens
 * ------------------------------------------------------------------ */

function screen_home($note = '')
{
    $users  = (int) bq_val('SELECT COUNT(*) FROM users');
    $admins = (int) bq_val('SELECT COUNT(*) FROM users WHERE role = 1');
    $aFree  = (int) bq_val("SELECT COUNT(*) FROM users WHERE key_type = 'FREE' AND expiry_date > NOW()");
    $aVip   = (int) bq_val("SELECT COUNT(*) FROM users WHERE key_type = 'VIP' AND expiry_date > NOW()");
    $sFree  = (int) bq_val('SELECT COUNT(*) FROM keys_free WHERE status = 0');
    $sVip   = (int) bq_val('SELECT COUNT(*) FROM keys_vip WHERE status = 0');
    $used24 = (int) bq_val('SELECT (SELECT COUNT(*) FROM keys_free WHERE used_at > DATE_SUB(NOW(), INTERVAL 1 DAY)) + (SELECT COUNT(*) FROM keys_vip WHERE used_at > DATE_SUB(NOW(), INTERVAL 1 DAY))');
    $pVip   = (int) bq_val("SELECT COUNT(*) FROM products WHERE UPPER(type) = 'VIP'");
    $pFree  = (int) bq_val("SELECT COUNT(*) FROM products WHERE UPPER(type) <> 'VIP'");
    $fails  = (int) bq_val('SELECT COUNT(*) FROM key_attempts WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    $maint  = (string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'maintenance_mode'", '', [], '0') === '1';
    $last   = bq_row('SELECT created_at, username, action FROM admin_logs ORDER BY id DESC LIMIT 1');

    $blocked = (int) bq_val('SELECT COUNT(*) FROM tg_user_blocks WHERE active = 1');

    $t = '🛡 <b>' . tg_e(tg_cfg('site_name', 'DEVNVIOS')) . '</b> <i>· Control Center</i>' . "\n";
    if ($note !== '') {
        $t .= $note . "\n";
    }
    $t .= '<blockquote>'
        . '👥 <b>' . $users . '</b> users · 👑 ' . $admins . ' admin' . "\n"
        . '🟢 <b>' . ($aFree + $aVip) . '</b> active keys · <i>FREE ' . $aFree . ' · VIP ' . $aVip . '</i>' . "\n"
        . '🔑 Stock · <i>FREE ' . $sFree . ' · VIP ' . $sVip . '</i>' . "\n"
        . '📈 Used 24h <b>' . $used24 . '</b> · 🚫 wrong keys 1h <b>' . $fails . '</b>'
        . '</blockquote>' . "\n"
        . '<blockquote>'
        . '📦 Tools <b>' . ($pVip + $pFree) . '</b> · 🚫 IPs <b>' . bot_bans_count() . '</b> · ⛔ Users <b>' . $blocked . '</b>' . "\n"
        . '🛠 Maintenance ' . ($maint ? '🔴 <b>ON</b>' : '🟢 off') . ' · 🔒 Delete ' . (tg_cfg('allow_destructive') ? '⚠️ <b>on</b>' : 'locked')
        . '</blockquote>' . "\n"
        . '<i>🕒 ' . tg_e(tg_now('H:i')) . ($last ? ' · last: ' . tg_e($last['action']) : '') . '</i>';

    ui_send($t, kb([
        [btn('🔄 Refresh', 'home'), btn($maint ? '🟢 Maint. OFF' : '🛠 Maint. ON', 'mt')],
        [btn('👥 Users', cbd('us', '', 0)), btn('🔑 Keys', 'ky'), btn('📦 Tools', 'pr|0')],
        [btn('🚫 IP bans', 'bn|0', ''), btn('⛔ Blocked', 'bl|0', ''), btn('👁 Activity', 'ac|a|0')],
        [btn('⚙️ Config', 'cf|0'), btn('🗄 Database', 'db'), btn('📜 Logs', 'lg')],
        [btn('🧮 SQL', 'sq'), btn('🩺 Health', 'hc'), btn('🔔 Alerts', 'al')],
        [btn('🎨 Emoji Premium', 'em|0', '')],
    ]));
}

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

    $kbd = [];
    foreach ($rows as $r) {
        $icon = (int) $r['role'] === 1 ? '👑' : ((int) $r['active'] === 1 ? ($r['key_type'] === 'VIP' ? '💎' : '🟢') : '👤');
        $kbd[] = [btn($icon . ' #' . $r['id'] . ' · ' . tg_clip($r['username'], 28), cbd('dr', 'users', $r['id']))];
    }
    $kbd[] = [btn('🔍 Search', 'su')];
    if ($pages > 1) {
        $kbd[] = pager_row('us', [rawurlencode($q)], $page, $pages);
    }
    $kbd[] = home_row();

    $t = '👥 <b>USERS</b> · ' . $total . ($q !== '' ? ' · <code>' . tg_e($q) . '</code>' : '') . "\n<i>👑 admin · 💎 VIP · 🟢 FREE · 👤 none</i>";
    ui_send($t, kb($kbd));
}

function screen_keys()
{
    $sFree = (int) bq_val('SELECT COUNT(*) FROM keys_free WHERE status = 0');
    $uFree = (int) bq_val('SELECT COUNT(*) FROM keys_free WHERE status = 1');
    $sVip  = (int) bq_val('SELECT COUNT(*) FROM keys_vip WHERE status = 0');
    $uVip  = (int) bq_val('SELECT COUNT(*) FROM keys_vip WHERE status = 1');
    $t = '🔑 <b>KEYS</b>' . "\n" . hr() . "\n<blockquote>"
        . '🟢 <b>FREE</b>  unused ' . $sFree . ' · used ' . $uFree . "\n"
        . '💎 <b>VIP</b>  unused ' . $sVip . ' · used ' . $uVip . "</blockquote>\n"
        . "Generate with the buttons, or type:\n<code>/genkey free 10 24</code>  (type · amount · hours)";
    ui_send($t, kb([
        [btn('➕ FREE ×1', cbd('kg', 'FREE', 1, 24)), btn('➕ FREE ×5', cbd('kg', 'FREE', 5, 24))],
        [btn('➕ FREE ×10', cbd('kg', 'FREE', 10, 24)), btn('➕ FREE ×20', cbd('kg', 'FREE', 20, 24))],
        [btn('💎 VIP ×1 · 24h', cbd('kg', 'VIP', 1, 24)), btn('💎 VIP ×1 · 7d', cbd('kg', 'VIP', 1, 168))],
        [btn('💎 VIP ×1 · 30d', cbd('kg', 'VIP', 1, 720)), btn('💎 VIP ×5 · 30d', cbd('kg', 'VIP', 5, 720))],
        [btn('🟢 Unused FREE', 'kl|FREE|0|0'), btn('🟢 Unused VIP', 'kl|VIP|0|0')],
        [btn('🔴 Used FREE', 'kl|FREE|1|0'), btn('🔴 Used VIP', 'kl|VIP|1|0')],
        [btn('🔍 Find a key', 'sk')],
        home_row(),
    ]));
}

function bot_gen_keys($type, $n, $hours)
{
    $table = $type === 'VIP' ? 'keys_vip' : 'keys_free';
    $made  = [];
    for ($i = 0; $i < $n; $i++) {
        for ($try = 0; $try < 5; $try++) {
            $code = 'DEV-' . strtoupper(bin2hex(random_bytes(4))) . '-' . $type;
            try {
                bq_exec('INSERT INTO `' . $table . '` (key_code, duration_hours, status) VALUES (?, ?, 0)', 'si', [$code, $hours]);
                $made[] = $code;
                break;
            } catch (Throwable $e) {
                // duplicate code: try another one
            }
        }
    }
    return $made;
}

function screen_gen_keys($type, $n, $hours)
{
    $type  = $type === 'VIP' ? 'VIP' : 'FREE';
    $n     = max(1, min(50, (int) $n));
    $hours = max(1, min(8760, (int) $hours));
    $made  = bot_gen_keys($type, $n, $hours);
    if (!$made) {
        return ui_error('Could not create keys (database error, see error_log)', 'ky');
    }
    bot_log('gen_keys', 'Tạo ' . count($made) . ' key ' . $type . ' (' . $hours . ' giờ) [Telegram]');
    toast('✅ ' . count($made) . ' key created');
    $t = '✅ <b>' . count($made) . ' ' . $type . ' KEY(S) CREATED</b> · ' . tg_e(tg_hours($hours)) . "\n" . hr() . "\n";
    foreach ($made as $code) {
        $t .= '<code>' . tg_e($code) . "</code>\n";
    }
    $t .= hr() . "\n<i>Tap a key to copy it.</i>";
    ui_send($t, kb([[btn('🔑 Keys', 'ky'), btn('🏠 Menu', 'home')]]));
}

function screen_key_list($type, $status, $page)
{
    $type   = $type === 'VIP' ? 'VIP' : 'FREE';
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
    $kbd[] = [btn('🔑 Keys', 'ky'), btn('🏠 Menu', 'home')];
    ui_send(($status === 0 ? '🟢' : '🔴') . ' <b>' . ($status === 0 ? 'UNUSED' : 'USED') . ' ' . $type . ' KEYS</b> · ' . $total . "\n" . hr() . "\nTap a key to open it.", kb($kbd));
}

function screen_key_search($code)
{
    $code = trim($code);
    $hits = [];
    foreach (['keys_free', 'keys_vip'] as $table) {
        foreach (bq_all('SELECT id, key_code FROM `' . $table . '` WHERE key_code LIKE ? ORDER BY id DESC LIMIT 6', 's', ['%' . $code . '%']) as $r) {
            $hits[] = [$table, $r];
        }
    }
    if (!$hits) {
        return ui_error('No key matches "' . tg_clip($code, 40) . '"', 'ky');
    }
    if (count($hits) === 1) {
        return bot_row_view($hits[0][0], $hits[0][1]['id']);
    }
    $kbd = [];
    foreach (array_slice($hits, 0, 8) as $h) {
        $kbd[] = [btn('🔑 ' . $h[1]['key_code'], cbd('dr', $h[0], $h[1]['id']))];
    }
    $kbd[] = [btn('🔑 Keys', 'ky'), btn('🏠 Menu', 'home')];
    ui_send('🔍 <b>KEY SEARCH</b> · ' . count($hits) . " result(s)\n" . hr(), kb($kbd));
}

function screen_products($page = 0)
{
    $per   = 8;
    $total = (int) bq_val('SELECT COUNT(*) FROM products');
    $pages = max(1, (int) ceil($total / $per));
    $page  = max(0, min((int) $page, $pages - 1));
    $rows  = bq_all('SELECT id, name, type FROM products ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . ($page * $per));
    $kbd   = [[btn('➕ Add a tool', 'pa')]];
    foreach ($rows as $r) {
        $kbd[] = [btn((strtoupper($r['type']) === 'VIP' ? '💎 ' : '🟢 ') . tg_clip($r['name'], 30) . ' · #' . $r['id'], cbd('dr', 'products', $r['id']))];
    }
    if ($pages > 1) {
        $kbd[] = pager_row('pr', [], $page, $pages);
    }
    $kbd[] = home_row();
    ui_send('📦 <b>PRODUCTS</b> · ' . $total . " tools\n" . hr() . "\n💎 VIP  🟢 FREE\nTap a tool to edit its name, link, image or type.", kb($kbd));
}

function screen_config($page = 0)
{
    $per   = 10;
    $total = (int) bq_val('SELECT COUNT(*) FROM site_config');
    $pages = max(1, (int) ceil($total / $per));
    $page  = max(0, min((int) $page, $pages - 1));
    $rows  = bq_all('SELECT config_key, config_value FROM site_config ORDER BY config_key ASC LIMIT ' . $per . ' OFFSET ' . ($page * $per));
    $kbd   = [[btn('➕ New setting', 'cn')]];
    foreach ($rows as $r) {
        $val = bt_secret('config_value', $r, 'site_config') ? '••••' : bv($r['config_value'], 18);
        $kbd[] = [btn('⚙️ ' . $r['config_key'] . ' = ' . $val, cbd('dr', 'site_config', $r['config_key']))];
    }
    if ($pages > 1) {
        $kbd[] = pager_row('cf', [], $page, $pages);
    }
    $kbd[] = home_row();
    ui_send('⚙️ <b>SITE CONFIG</b> · ' . $total . " settings\n" . hr() . "\nThe same settings admin_cp edits (texts, colors, links, announcement...).", kb($kbd));
}

function screen_db()
{
    $rows = bq_all('SELECT TABLE_NAME AS t, TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME');
    $kbd  = [];
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
    ui_send('🗄 <b>DATABASE</b> · ' . count($rows) . " tables\n" . hr() . "\nBrowse any table, open a row, edit any column.\nFor raw SQL use the 🧮 SQL console.", kb($kbd));
}

function screen_table($table, $page)
{
    if (!bt_valid($table)) {
        return ui_error('Unknown table', 'db');
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
            foreach (['username', 'key_code', 'name', 'action', 'violation_type', 'config_key'] as $c) {
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
    $kbd[] = [btn('🗄 Tables', 'db'), btn('🏠 Menu', 'home')];
    ui_send('🗄 <b>' . tg_e($table) . '</b> · ' . $total . " rows\n" . hr() . "\n" . ($txt !== '' ? $txt : ($pk === null ? '' : 'Tap a row to open it.')), kb($kbd));
}

function screen_logs_menu()
{
    ui_send('📜 <b>LOGS</b>' . "\n" . hr() . "\nWhat do you want to read?", kb([
        [btn('🛡 Admin actions', 'lt|admin_logs|0'), btn('🔐 Security', 'lt|security_logs|0')],
        [btn('🚫 Wrong keys', 'lt|key_attempts|0'), btn('🔑 Failed logins', 'lt|login_attempts|0')],
        [btn('🧮 SQL history (bot)', 'lt|sql|0')],
        home_row(),
    ]));
}

function screen_log($table, $page)
{
    if ($table === 'sql') {
        $s = tg_store_read('sqlhist');
        $t = '🧮 <b>SQL HISTORY</b> (bot, newest first)' . "\n" . hr() . "\n";
        foreach (array_slice(isset($s['h']) ? $s['h'] : [], 0, 8) as $h) {
            $t .= '<i>' . tg_e($h['t']) . '</i> · admin ' . tg_e($h['a']) . ' · ' . tg_e($h['r']) . "\n<code>" . tg_e(tg_clip($h['q'], 160)) . "</code>\n";
        }
        if (empty($s['h'])) {
            $t .= 'Nothing yet.';
        }
        return ui_send($t, kb([[btn('📜 Logs', 'lg'), btn('🏠 Menu', 'home')]]));
    }
    $def = [
        'admin_logs'     => ['created_at', 'username', 'action', 'detail'],
        'security_logs'  => ['created_at', 'ip_address', 'violation_type', 'user_agent'],
        'key_attempts'   => ['created_at', 'username', 'ip'],
        'login_attempts' => ['created_at', 'username', 'ip'],
    ];
    if (!isset($def[$table]) || !bt_valid($table)) {
        return ui_error('Unknown log', 'lg');
    }
    $per   = 8;
    $total = (int) bq_val('SELECT COUNT(*) FROM `' . $table . '`');
    $pages = max(1, (int) ceil($total / $per));
    $page  = max(0, min((int) $page, $pages - 1));
    $rows  = bq_all('SELECT * FROM `' . $table . '` ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . ($page * $per));
    $t = '📜 <b>' . tg_e($table) . '</b> · ' . $total . " rows\n" . hr() . "\n";
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
        $t .= 'Empty.';
    }
    $kbd = [];
    if ($pages > 1) {
        $kbd[] = pager_row('lt', [$table], $page, $pages);
    }
    $kbd[] = [btn('📜 Logs', 'lg'), btn('🏠 Menu', 'home')];
    ui_send($t, kb($kbd));
}

function screen_health()
{
    $t0   = microtime(true);
    $dbv  = (string) bq_val('SELECT VERSION()', '', [], '?');
    $ms   = (int) round((microtime(true) - $t0) * 1000);
    $me   = tg_api('getMe');
    $wh   = tg_api('getWebhookInfo');
    $info = isset($wh['result']) && is_array($wh['result']) ? $wh['result'] : [];
    $dir  = tg_dir();
    $ok   = function ($b) {
        return $b ? '✅' : '❌';
    };
    $t  = '🩺 <b>SYSTEM HEALTH</b>' . "\n" . hr() . "\n";
    $t .= $ok(true) . ' <b>PHP</b>  ' . tg_e(PHP_VERSION) . "\n";
    $t .= $ok($dbv !== '?') . ' <b>Database</b>  ' . tg_e($dbv) . ' · ' . $ms . " ms\n";
    $t .= $ok(!empty($me['ok'])) . ' <b>Telegram API</b>  ' . (!empty($me['ok']) ? '@' . tg_e(isset($me['result']['username']) ? $me['result']['username'] : '?') : tg_e(isset($me['description']) ? $me['description'] : 'unreachable')) . "\n";
    $t .= $ok(!empty($info['url'])) . ' <b>Webhook</b>  ' . (!empty($info['url']) ? 'set · pending ' . (int) (isset($info['pending_update_count']) ? $info['pending_update_count'] : 0) : 'NOT set (open ?setup=)') . "\n";
    if (!empty($info['last_error_message'])) {
        $t .= '⚠️ <b>Last webhook error</b>  ' . tg_e(tg_clip($info['last_error_message'], 120)) . "\n";
    }
    $t .= $ok(function_exists('curl_init')) . ' <b>cURL</b>   ' . $ok(function_exists('imagecreatefromstring')) . ' <b>GD</b>   ' . $ok(function_exists('mb_substr')) . " <b>mbstring</b>\n";
    $t .= $ok(bot_ipg_ready()) . ' <b>ipguard.php</b>  ' . (bot_ipg_ready() ? 'loaded (IP bans work)' : 'NOT loaded: IP bans off (user block still works)') . "\n";
    $pkName = tg_emoji_pack_name();
    $pkInfo = tg_emoji_pack();
    $t .= ($pkName !== '' ? '✅' : '⚪') . ' <b>Emoji pack</b>  ' . ($pkName !== '' ? tg_e($pkName) . ' · ' . count($pkInfo['list']) . ' emoji' . (tg_emoji_on() ? '' : ' · paused (Telegram refused it)') : 'none (send me an emoji of your pack)') . "\n";
    $t .= $ok(is_writable($dir)) . ' <b>Cache folder</b>  ' . (is_writable($dir) ? 'writable' : 'NOT writable (alerts lose their memory)') . "\n";
    $t .= $ok((string) tg_cfg('webhook_secret', '') !== '') . ' <b>Webhook secret</b>  ' . ((string) tg_cfg('webhook_secret', '') !== '' ? 'set' : 'missing!') . "\n";
    $t .= (tg_cfg('allow_destructive') ? '⚠️' : '✅') . ' <b>Deleting</b>  ' . (tg_cfg('allow_destructive') ? 'ALLOWED' : 'disabled (data protection)') . "\n";
    $t .= hr() . "\n🕒 " . tg_e(tg_now());
    ui_send($t, kb([[btn('🔔 Send test alert', 'ht'), btn('🔄 Refresh', 'hc')], home_row()]));
}

function screen_alerts()
{
    $kbd  = [];
    $line = [];
    foreach (tg_categories() as $code => $def) {
        $on     = tg_cat_on($code);
        $line[] = btn($def[0] . ($on ? '  ✓' : '  ✗'), 'at|' . $code, $on ? 'success' : 'danger');
        if (count($line) === 2) {
            $kbd[] = $line;
            $line  = [];
        }
    }
    if ($line) {
        $kbd[] = $line;
    }
    $kbd[] = home_row();
    ui_send('🔔 <b>ALERTS</b>' . "\n" . '<i>Tap to switch a category on / off.' . "\n" . 'Site errors and downtime always use 🚨.</i>', kb($kbd));
}

function screen_sql_help()
{
    ui_send('🧮 <b>SQL CONSOLE</b>' . "\n" . hr() . "\n"
        . "Send any query with <code>/sql ...</code> or press ✍️ and type it.\n\n"
        . "▸ <b>SELECT / SHOW / DESCRIBE</b> run at once\n"
        . "▸ <b>INSERT / UPDATE</b> ask you to confirm first\n"
        . "▸ <b>UPDATE</b> must have a WHERE\n"
        . '▸ <b>DELETE / DROP / TRUNCATE / ALTER</b> ' . (tg_cfg('allow_destructive') ? 'need a double confirmation' : 'are blocked (data protection)') . "\n"
        . "▸ Password columns are always masked\n\n"
        . 'Example: <code>/sql SELECT id, username FROM users ORDER BY id DESC LIMIT 5</code>', kb([
        [btn('✍️ Type SQL', 'sw')],
        [btn('📋 Tables', cbd('sr', bot_stash(['q' => 'SHOW TABLES']))), btn('👥 Last users', cbd('sr', bot_stash(['q' => 'SELECT id, username, role, key_type FROM users ORDER BY id DESC LIMIT 8'])))],
        [btn('🔑 Unused FREE keys', cbd('sr', bot_stash(['q' => 'SELECT id, key_code, duration_hours FROM keys_free WHERE status = 0 ORDER BY id DESC LIMIT 10']))), btn('🟢 Active keys', cbd('sr', bot_stash(['q' => "SELECT username, key_type, expiry_date, turns_left FROM users WHERE expiry_date > NOW() LIMIT 10"])))],
        home_row(),
    ]));
}

/* ------------------------------------------------------------------
 * Row card + generic editing (works for every table)
 * ------------------------------------------------------------------ */

function bot_back_for($table)
{
    $map = ['users' => cbd('us', '', 0), 'keys_free' => 'kl|FREE|0|0', 'keys_vip' => 'kl|VIP|0|0', 'products' => 'pr|0', 'site_config' => 'cf|0'];
    return isset($map[$table]) ? $map[$table] : cbd('dt', $table, 0);
}

function bot_row_view($table, $pkv, $note = '')
{
    if (!bt_valid($table)) {
        return ui_error('Unknown table', 'db');
    }
    $pk = bt_pk($table);
    if ($pk === null) {
        return ui_error('This table has no single primary key (view only)', cbd('dt', $table, 0));
    }
    $row = bq_row('SELECT * FROM `' . $table . '` WHERE `' . $pk . '` = ? LIMIT 1', 's', [(string) $pkv]);
    if (!$row) {
        return ui_error('Row not found', bot_back_for($table));
    }

    $t = '📄 <b>' . tg_e($table) . '</b> · <code>' . tg_e($pk) . ' = ' . tg_e($pkv) . '</code>' . "\n" . hr() . "\n";
    if ($note !== '') {
        $t .= $note . "\n" . hr() . "\n";
    }
    $fields = '';
    foreach ($row as $col => $val) {
        $shown = bt_secret($col, $row, $table) ? '••••••••' : bv($val, 220);
        $fields .= '<b>' . tg_e($col) . '</b>  <code>' . tg_e($shown) . "</code>\n";
    }
    $t .= '<blockquote>' . rtrim($fields) . "</blockquote>\n";

    $kbd = [];
    if ($table === 'users') {
        $active = (int) bq_val('SELECT IF(expiry_date > NOW(), 1, 0) FROM users WHERE id = ?', 'i', [(int) $pkv]);
        $blk    = tg_user_block_row((string) $row['username']);
        $t .= hr() . "\n🔎 <b>Key now</b>  " . ($active ? '🟢 ACTIVE (' . tg_e($row['key_type']) . ', ' . (int) $row['turns_left'] . ' turn)' : '⚪ none / expired') . "\n";
        $t .= '⛔ <b>Account</b>  ' . ($blk ? '🔴 BLOCKED · ' . tg_e(tg_clip($blk['reason'], 60)) . ' · ' . (int) $blk['hits'] . ' visit(s) refused' : '🟢 normal') . "\n";
        $id = (int) $pkv;
        $kbd[] = [btn('🎁 +FREE 24h', 'ua|' . $id . '|free'), btn('💎 +VIP 30d', 'ua|' . $id . '|vip')];
        $kbd[] = [btn('➕ +1 turn', 'ua|' . $id . '|t+'), btn('➖ −1 turn', 'ua|' . $id . '|t-')];
        $kbd[] = [btn('⛔ Revoke key', 'ua|' . $id . '|revoke'), btn('👑 Toggle admin', 'ua|' . $id . '|role')];
        $kbd[] = [btn('🔑 Set new password', 'ua|' . $id . '|pw')];
        $kbd[] = [btn('🗑 Xoá lượt', 'ua|' . $id . '|clear'), btn('🚫 Chặn IP', 'bu|' . $id)];
        $kbd[] = [$blk ? btn('✅ Mở chặn user', 'uu|' . $id) : btn('⛔ Chặn user (khoá tài khoản)', 'ub|' . $id)];
        $kbd[] = [btn('👁 Activity of this user', cbd('au', rawurlencode((string) $row['username']), 0))];
    } elseif ($table === 'keys_free' || $table === 'keys_vip') {
        $kbd[] = [(int) $row['status'] === 0
            ? btn('🚫 Void this key', 'kx|' . $table . '|' . (int) $pkv . '|void')
            : btn('♻️ Make it unused again', 'kx|' . $table . '|' . (int) $pkv . '|restore')];
    } elseif ($table === 'products') {
        $kbd[] = [btn('🔁 Switch FREE ⇄ VIP', 'px|' . (int) $pkv)];
    } elseif ($table === 'site_config' && isset($row['config_value']) && ($row['config_value'] === '0' || $row['config_value'] === '1')) {
        $kbd[] = [btn('🔀 Toggle 0 / 1', cbd('ct', $pkv))];
    }

    $line = [];
    foreach ($row as $col => $val) {
        if ($col === $pk) {
            continue;
        }
        $line[] = btn('✏️ ' . $col, cbd('de', $table, $pkv, $col));
        if (count($line) === 2) {
            $kbd[] = $line;
            $line  = [];
        }
    }
    if ($line) {
        $kbd[] = $line;
    }
    if (tg_cfg('allow_destructive')) {
        $kbd[] = [btn('🗑 Delete row', cbd('dd', $table, $pkv))];
    }
    $kbd[] = [btn('⬅ Back', bot_back_for($table)), btn('🏠 Menu', 'home')];
    ui_send($t, kb($kbd));
}

function bot_edit_prompt($table, $pkv, $col)
{
    $pk = bt_valid($table) ? bt_pk($table) : null;
    $cols = bt_cols($table);
    if ($pk === null || !isset($cols[$col]) || $col === $pk) {
        return ui_error('This column can not be edited', 'db');
    }
    $row = bq_row('SELECT * FROM `' . $table . '` WHERE `' . $pk . '` = ? LIMIT 1', 's', [(string) $pkv]);
    if (!$row) {
        return ui_error('Row not found', bot_back_for($table));
    }
    conv_set($GLOBALS['CTX']['admin'], 'edit', ['t' => $table, 'pk' => (string) $pkv, 'c' => $col]);
    $cur = bt_secret($col, $row, $table) ? '••••••••' : bv($row[$col], 300);
    ui_send('✏️ <b>EDIT</b> <code>' . tg_e($table . '.' . $col) . '</code> · #' . tg_e($pkv) . "\n" . hr() . "\n"
        . '<b>Type</b>  <code>' . tg_e($cols[$col]['Type']) . "</code>\n<b>Now</b>  <code>" . tg_e($cur) . "</code>\n" . hr() . "\n"
        . "Send the <b>new value</b> as a message.\n▸ <code>NULL</code> sets NULL\n▸ <code>NOW</code> = current date/time (date columns)\n▸ /cancel to abort",
        kb([[btn('✖ Cancel', 'cx')]]));
}

function bot_apply_edit($table, $pkv, $col, $text)
{
    $cols = bt_cols($table);
    $pk   = bt_valid($table) ? bt_pk($table) : null;
    if ($pk === null || !isset($cols[$col]) || $col === $pk) {
        return ui_error('This column can not be edited', 'db');
    }
    $row = bq_row('SELECT `' . $col . '` AS v FROM `' . $table . '` WHERE `' . $pk . '` = ? LIMIT 1', 's', [(string) $pkv]);
    if (!$row) {
        return ui_error('Row not found', bot_back_for($table));
    }
    $type  = strtolower($cols[$col]['Type']);
    $upper = strtoupper(trim($text));
    $val   = $text;
    $useNow = false;

    if ($upper === 'NULL') {
        if ($cols[$col]['Null'] !== 'YES') {
            conv_set($GLOBALS['CTX']['admin'], 'edit', ['t' => $table, 'pk' => (string) $pkv, 'c' => $col]);
            return ui_send('❌ <b>This column does not accept NULL.</b> Send another value.', kb([[btn('✖ Cancel', 'cx')]]));
        }
        $val = null;
    } elseif ($upper === 'NOW' && preg_match('/date|time/', $type)) {
        $useNow = true;
    } elseif (preg_match('/^(tiny|small|medium|big)?int/', $type) && !preg_match('/^-?\d+$/', trim($text))) {
        conv_set($GLOBALS['CTX']['admin'], 'edit', ['t' => $table, 'pk' => (string) $pkv, 'c' => $col]);
        return ui_send('❌ <b>A whole number is required.</b> Send it again.', kb([[btn('✖ Cancel', 'cx')]]));
    } elseif ($table === 'users' && $col === 'password' && strpos($text, '$2y$') !== 0) {
        $val = password_hash($text, PASSWORD_DEFAULT);
    }

    if ($useNow) {
        bq_exec('UPDATE `' . $table . '` SET `' . $col . '` = NOW() WHERE `' . $pk . '` = ? LIMIT 1', 's', [(string) $pkv]);
    } else {
        bq_exec('UPDATE `' . $table . '` SET `' . $col . '` = ? WHERE `' . $pk . '` = ? LIMIT 1', 'ss', [$val, (string) $pkv]);
    }

    $secret = bt_secret($col, null, $table);
    bot_log('tg_edit', $table . '#' . $pkv . '.' . $col . ': ' . ($secret ? '(hidden)' : bv($row['v'], 60) . ' → ' . ($useNow ? 'NOW()' : bv($val, 60))));
    $undo = bot_stash(['t' => $table, 'pk' => (string) $pkv, 'c' => $col, 'old' => $row['v']]);
    toast('✅ Saved');
    ui_send('✅ <b>SAVED</b> · <code>' . tg_e($table . '.' . $col) . '</code> · #' . tg_e($pkv) . "\n" . hr() . "\n"
        . '<b>Before</b>  <code>' . tg_e($secret ? '••••' : bv($row['v'], 160)) . "</code>\n"
        . '<b>After</b>   <code>' . tg_e($secret ? '••••' : ($useNow ? 'NOW()' : bv($val, 160))) . '</code>',
        kb([[btn('↩ Undo', 'un|' . $undo), btn('📄 Open row', cbd('dr', $table, $pkv))], home_row()]));
}

function bot_undo($stashId)
{
    $p = bot_unstash($stashId);
    if (!is_array($p) || !isset($p['t'], $p['pk'], $p['c']) || !bt_valid($p['t'])) {
        return ui_error('Nothing to undo (expired)', 'home');
    }
    $pk = bt_pk($p['t']);
    if ($pk === null || !isset(bt_cols($p['t'])[$p['c']])) {
        return ui_error('Cannot undo here', 'home');
    }
    bq_exec('UPDATE `' . $p['t'] . '` SET `' . $p['c'] . '` = ? WHERE `' . $pk . '` = ? LIMIT 1', 'ss', [$p['old'], (string) $p['pk']]);
    bot_log('tg_undo', $p['t'] . '#' . $p['pk'] . '.' . $p['c']);
    toast('↩ Restored');
    bot_row_view($p['t'], $p['pk'], '↩ <b>Restored the previous value</b>');
}

/* Row delete: only exists when allow_destructive = true. The row is saved in a recovery list first. */
function bot_delete_row($table, $pkv, $confirmed)
{
    if (!tg_cfg('allow_destructive')) {
        return ui_error('Deleting is disabled (data protection)', 'home');
    }
    $pk = bt_valid($table) ? bt_pk($table) : null;
    if ($pk === null) {
        return ui_error('This table can not be deleted from here', 'home');
    }
    if (!$confirmed) {
        return ui_send('🗑 <b>DELETE ROW?</b>' . "\n" . hr() . "\n<code>" . tg_e($table . '.' . $pk . ' = ' . $pkv) . "</code>\nThe row is copied to the bot recovery list first.",
            kb([[btn('🗑 Yes, delete', cbd('dd', $table, $pkv, 'ok')), btn('✖ Cancel', cbd('dr', $table, $pkv))]]));
    }
    $row = bq_row('SELECT * FROM `' . $table . '` WHERE `' . $pk . '` = ? LIMIT 1', 's', [(string) $pkv]);
    if ($row) {
        tg_store_update('trash', function ($s) use ($table, $row) {
            $s['rows'][] = ['t' => tg_now(), 'table' => $table, 'row' => $row];
            $s['rows'] = array_slice($s['rows'], -200);
            return $s;
        });
        bq_exec('DELETE FROM `' . $table . '` WHERE `' . $pk . '` = ? LIMIT 1', 's', [(string) $pkv]);
        bot_log('tg_delete', $table . '#' . $pkv);
    }
    toast('🗑 Deleted');
    ui_send('🗑 <b>Deleted</b> · <code>' . tg_e($table . '#' . $pkv) . '</code>', kb([[btn('⬅ Back', bot_back_for($table)), btn('🏠 Menu', 'home')]]));
}

/* ------------------------------------------------------------------
 * Shortcuts: users, keys, products, config, maintenance
 * ------------------------------------------------------------------ */

function bot_user_action($id, $act, $step)
{
    $u = bq_row('SELECT id, username, role FROM users WHERE id = ? LIMIT 1', 'i', [$id]);
    if (!$u) {
        return ui_error('User not found', cbd('us', '', 0));
    }
    $name = $u['username'];
    $msg  = '';
    switch ($act) {
        case 'free':
            bq_exec("UPDATE users SET key_type = 'FREE', expiry_date = DATE_ADD(NOW(), INTERVAL 24 HOUR), turns_left = 2, alert_msg = NULL, vip_free_clicks = 0 WHERE id = ?", 'i', [$id]);
            $msg = 'Granted a FREE key (24h, 2 turns)';
            break;
        case 'vip':
            bq_exec("UPDATE users SET key_type = 'VIP', expiry_date = DATE_ADD(NOW(), INTERVAL 720 HOUR), turns_left = 1, alert_msg = NULL, vip_free_clicks = 0 WHERE id = ?", 'i', [$id]);
            $msg = 'Granted a VIP key (30 days, 1 turn)';
            break;
        case 'revoke':
            bq_exec("UPDATE users SET key_type = 'NONE', expiry_date = '2000-01-01 00:00:00', turns_left = NULL, alert_msg = ? WHERE id = ?", 'si', ['Key của bạn đã bị Admin thu hồi.', $id]);
            $msg = 'Key revoked (the user sees the revoke notice)';
            break;
        case 'clear':
            if ($step !== 'ok') {
                return ui_send('🗑 <b>XOÁ LƯỢT?</b>' . "\n" . hr() . "\n<code>" . tg_e($name) . '</code> sẽ mất toàn bộ lượt còn lại và key đang dùng (giống như dùng hết lượt). Không xoá tài khoản, không xoá key trong kho.',
                    kb([[btn('✅ Xoá lượt', 'ua|' . $id . '|clear|ok'), btn('✖ Cancel', cbd('dr', 'users', $id))]]));
            }
            $r   = bq_exec("UPDATE users SET key_type = 'NONE', expiry_date = NULL, turns_left = NULL, vip_free_clicks = 0 WHERE id = ?", 'i', [$id]);
            $msg = 'Đã xoá lượt (key + lượt về 0)';
            break;
        case 't+':
            $r   = bq_exec('UPDATE users SET turns_left = COALESCE(turns_left, 1) + 1 WHERE id = ? AND expiry_date > NOW()', 'i', [$id]);
            $msg = $r['n'] > 0 ? '+1 turn' : 'No active key, nothing changed';
            break;
        case 't-':
            $r   = bq_exec('UPDATE users SET turns_left = COALESCE(turns_left, 1) - 1 WHERE id = ? AND expiry_date > NOW() AND COALESCE(turns_left, 1) > 1', 'i', [$id]);
            $msg = $r['n'] > 0 ? '−1 turn' : 'Needs an active key with 2+ turns';
            break;
        case 'role':
            if ($step !== 'ok') {
                return ui_send('👑 <b>CHANGE ADMIN RIGHTS?</b>' . "\n" . hr() . "\n<code>" . tg_e($name) . '</code> is ' . ((int) $u['role'] === 1 ? '<b>an admin</b>. Make a normal user?' : 'a normal user. Make <b>admin</b>?'),
                    kb([[btn('✅ Yes', 'ua|' . $id . '|role|ok'), btn('✖ Cancel', cbd('dr', 'users', $id))]]));
            }
            if ((int) $u['role'] === 1 && (int) bq_val('SELECT COUNT(*) FROM users WHERE role = 1') <= 1) {
                return ui_error('Refused: this is the last admin account', cbd('dr', 'users', $id));
            }
            bq_exec('UPDATE users SET role = IF(role = 1, 0, 1) WHERE id = ?', 'i', [$id]);
            $msg = 'Admin rights changed';
            break;
        case 'pw':
            conv_set($GLOBALS['CTX']['admin'], 'pw', ['id' => $id]);
            return ui_send('🔑 <b>NEW PASSWORD</b> for <code>' . tg_e($name) . "</code>\n" . hr() . "\nSend the new password (6+ characters). It is stored hashed and your message is deleted right away.\n/cancel to abort.", kb([[btn('✖ Cancel', 'cx')]]));
        default:
            return bot_row_view('users', $id);
    }
    bot_log('tg_user', $name . ': ' . $msg);
    toast('✅ ' . $msg);
    bot_row_view('users', $id, '✅ ' . tg_e($msg));
}

function bot_key_action($table, $id, $act)
{
    if (!in_array($table, ['keys_free', 'keys_vip'], true)) {
        return ui_error('Unknown key table', 'ky');
    }
    if ($act === 'void') {
        bq_exec('UPDATE `' . $table . '` SET status = 1 WHERE id = ?', 'i', [$id]);
        $msg = 'Key voided (can no longer be used)';
    } else {
        bq_exec('UPDATE `' . $table . '` SET status = 0, used_by = NULL, used_at = NULL WHERE id = ?', 'i', [$id]);
        $msg = 'Key is unused again';
    }
    bot_log('tg_key', $table . '#' . $id . ': ' . $msg);
    toast('✅ Done');
    bot_row_view($table, $id, '✅ ' . tg_e($msg));
}

function bot_product_swap($id)
{
    bq_exec("UPDATE products SET type = IF(UPPER(type) = 'VIP', 'FREE', 'VIP') WHERE id = ?", 'i', [$id]);
    bot_log('tg_product', 'products#' . $id . ': type switched');
    toast('✅ Type switched');
    bot_row_view('products', $id, '✅ Type switched');
}

function bot_product_add_finish($type)
{
    $admin = $GLOBALS['CTX']['admin'];
    $conv  = conv_get($admin);
    if (!$conv || $conv['mode'] !== 'pt') {
        return ui_error('Start again with ➕ Add a tool', 'pr|0');
    }
    $d    = $conv['data'];
    $type = $type === 'VIP' ? 'VIP' : 'FREE';
    $r    = bq_exec('INSERT INTO products (name, link, image, type) VALUES (?, ?, ?, ?)', 'ssss', [$d['name'], $d['link'], $d['image'], $type]);
    conv_clear($admin);
    bot_log('add_product', 'Thêm tool ' . $d['name'] . ' (' . $type . ') [Telegram]');
    toast('✅ Tool added');
    bot_row_view('products', $r['id'], '✅ <b>Tool added</b>');
}

function bot_set_config($key, $value)
{
    bq_exec('INSERT INTO site_config (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = ?', 'sss', [$key, $value, $value]);
}

function bot_config_toggle($key)
{
    if ($key === 'maintenance_mode') {
        return bot_maintenance('');
    }
    $cur = (string) bq_val('SELECT config_value FROM site_config WHERE config_key = ?', 's', [$key], '');
    if ($cur !== '0' && $cur !== '1') {
        return ui_error('Only 0 / 1 settings can be toggled', 'cf|0');
    }
    $new = $cur === '1' ? '0' : '1';
    bot_set_config($key, $new);
    bot_log('tg_config', $key . ': ' . $cur . ' → ' . $new);
    toast('✅ ' . $key . ' = ' . $new);
    bot_row_view('site_config', $key, '✅ Now <b>' . $new . '</b>');
}

function bot_maintenance($step)
{
    $on = (string) bq_val("SELECT config_value FROM site_config WHERE config_key = 'maintenance_mode'", '', [], '0') === '1';
    if ($step === 'on' || $step === 'off') {
        $new = $step === 'on' ? '1' : '0';
        bot_set_config('maintenance_mode', $new);
        bot_log('toggle_maintenance', ($new === '1' ? 'Bật' : 'Tắt') . ' bảo trì [Telegram]');
        toast($new === '1' ? '🛠 Maintenance ON' : '🟢 Maintenance OFF');
        return screen_home($new === '1' ? '🛠 <b>Maintenance is ON</b>' : '🟢 <b>Maintenance is OFF</b>');
    }
    ui_send('🛠 <b>MAINTENANCE</b>' . "\n" . hr() . "\nNow: " . ($on ? '🔴 <b>ON</b>' : '🟢 <b>OFF</b>') . "\n"
        . ($on ? "Visitors see the maintenance page.\n" : "Turning it ON closes the site for normal visitors.\nThe bot only flips the switch: admin_cp's private access link is not generated.\n")
        . "\n" . ($on ? 'Turn maintenance OFF?' : 'Turn maintenance ON?'),
        kb([[btn('✅ Yes', $on ? 'mt|off' : 'mt|on'), btn('✖ Cancel', 'home')]]));
}

/* ------------------------------------------------------------------
 * SQL console
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

function sql_pad($s, $w)
{
    return $s . str_repeat(' ', max(0, $w - tg_len($s)));
}

function sql_render(array $fields, array $rows, $more, $ms)
{
    $n    = count($fields);
    $mask = [];
    foreach ($fields as $i => $f) {
        $mask[$i] = preg_match('/pass|secret|token|hash/i', $f) === 1;
    }
    $cell = function ($v, $i, $w) use ($mask) {
        if ($mask[$i]) {
            return '••••';
        }
        if ($v === null) {
            return 'NULL';
        }
        return tg_clip(str_replace(["\r", "\n", "\t"], ' ', (string) $v), $w);
    };

    $w = [];
    foreach ($fields as $i => $f) {
        $w[$i] = min(16, tg_len($f));
        foreach ($rows as $r) {
            $w[$i] = max($w[$i], min(16, tg_len($cell($r[$i], $i, 16))));
        }
    }
    $total = array_sum($w) + 3 * max(0, $n - 1);
    $out   = '';
    if ($total <= 46) {
        $line = function ($vals) use ($w) {
            $p = [];
            foreach ($vals as $i => $v) {
                $p[] = sql_pad($v, $w[$i]);
            }
            return rtrim(implode(' │ ', $p));
        };
        $head = [];
        foreach ($fields as $f) {
            $head[] = tg_clip($f, 16);
        }
        $out .= $line($head) . "\n" . str_repeat('─', max(1, min(46, $total))) . "\n";
        foreach ($rows as $r) {
            $vals = [];
            foreach ($r as $i => $v) {
                $vals[] = $cell($v, $i, 16);
            }
            $out .= $line($vals) . "\n";
        }
    } else {
        foreach ($rows as $k => $r) {
            $out .= '#' . ($k + 1) . "\n";
            foreach ($r as $i => $v) {
                $out .= '  ' . $fields[$i] . ': ' . $cell($v, $i, 60) . "\n";
            }
        }
    }
    $out = tg_clip($out, 3200);
    return '🧮 <b>RESULT</b> · ' . count($rows) . ' row(s)' . ($more ? ' (first 25 shown, add LIMIT / OFFSET for more)' : '') . ' · ' . $ms . " ms\n<pre>" . tg_e($out) . '</pre>';
}

function bot_sql_read($sql)
{
    foreach (['SET SESSION max_statement_time=5', 'SET SESSION max_execution_time=5000'] as $guard) {
        try {
            mysqli_query(bdb(), $guard); // whichever one this server knows
        } catch (Throwable $e) {
            // not supported here: fine
        }
    }
    $t0  = microtime(true);
    $res = mysqli_query(bdb(), $sql, MYSQLI_USE_RESULT);
    if (!($res instanceof mysqli_result)) {
        sql_hist($sql, 'OK');
        return ui_send('✅ <b>OK</b>', kb([[btn('🧮 SQL', 'sq'), btn('🏠 Menu', 'home')]]));
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
    sql_hist($sql, count($rows) . ' row(s)');
    ui_send(sql_render($fields, $rows, $more, $ms), kb([[btn('✍️ Another query', 'sw'), btn('🏠 Menu', 'home')]]));
}

function bot_sql($sql)
{
    $sql = trim(rtrim(trim($sql), "; \t\r\n"));
    if ($sql === '') {
        return screen_sql_help();
    }
    if (strlen($sql) > 3000) {
        return ui_error('Query too long (max 3000 characters)', 'sq');
    }
    if (preg_match('/\binto\s+(outfile|dumpfile)\b|\bload_file\s*\(|\bload\s+data\b/i', $sql)) {
        return ui_error('File access inside SQL is blocked', 'sq');
    }
    $verb  = sql_verb($sql);
    $admin = $GLOBALS['CTX']['admin'];

    if (in_array($verb, ['select', 'show', 'describe', 'desc', 'explain'], true)) {
        return bot_sql_read($sql);
    }
    if (in_array($verb, ['insert', 'update', 'replace'], true)) {
        if ($verb === 'update' && !preg_match('/\bwhere\b/i', $sql)) {
            return ui_error('UPDATE without WHERE is refused. Add a WHERE (WHERE 1=1 if you really mean every row)', 'sq');
        }
        $id = bot_stash(['sql' => $sql, 'admin' => $admin, 'at' => time()]);
        return ui_send("⚠️ <b>CONFIRM WRITE</b>\n" . hr() . "\n<pre>" . tg_e(tg_clip($sql, 1500)) . "</pre>\nThis changes data (nothing is deleted). Run it?",
            kb([[btn('✅ Run', 'sx|' . $id), btn('✖ Cancel', 'cx')]]));
    }
    if (in_array($verb, ['delete', 'drop', 'truncate', 'alter', 'create', 'rename'], true)) {
        if (!tg_cfg('allow_destructive')) {
            return ui_send("🔒 <b>BLOCKED · DATA PROTECTION</b>\n" . hr() . "\n<code>" . tg_e(strtoupper($verb)) . "</code> is disabled so nothing can ever be lost.\nSELECT, INSERT and UPDATE still work.\n\n<i>(To allow it: allow_destructive in telegram_config.php)</i>", kb([[btn('🧮 SQL', 'sq'), btn('🏠 Menu', 'home')]]));
        }
        $preview = '';
        if ($verb === 'delete') {
            if (!preg_match('/^delete\s+from\s+(`?\w+`?)\s+(where\s+.+)$/is', $sql, $m)) {
                return ui_error('DELETE must be "DELETE FROM table WHERE ..."', 'sq');
            }
            $count   = bq_val('SELECT COUNT(*) FROM ' . $m[1] . ' ' . $m[2], '', [], null);
            $preview = $count === null ? '' : "\nRows that match: <b>" . (int) $count . '</b>';
        }
        $id = bot_stash(['sql' => $sql, 'admin' => $admin, 'at' => time()]);
        return ui_send("🚨 <b>DESTRUCTIVE QUERY</b>\n" . hr() . "\n<pre>" . tg_e(tg_clip($sql, 1500)) . '</pre>' . $preview . "\nThis can remove data. DELETE copies the rows to the recovery list first, DROP / TRUNCATE / ALTER can not be recovered.",
            kb([[btn('🚨 I understand, run it', 'sd|' . $id), btn('✖ Cancel', 'cx')]]));
    }
    return ui_error('"' . $verb . '" is not allowed in the console', 'sq');
}

function bot_sql_exec($stashId, $destructive)
{
    $p = bot_unstash($stashId);
    if (!is_array($p) || !isset($p['sql']) || (string) $p['admin'] !== (string) $GLOBALS['CTX']['admin'] || (int) $p['at'] < time() - 900) {
        return ui_error('This confirmation expired. Send the query again.', 'sq');
    }
    $sql  = (string) $p['sql'];
    $verb = sql_verb($sql);
    if ($destructive) {
        if (!tg_cfg('allow_destructive') || !in_array($verb, ['delete', 'drop', 'truncate', 'alter', 'create', 'rename'], true)) {
            return ui_error('Refused by data protection', 'sq');
        }
    } elseif (!in_array($verb, ['insert', 'update', 'replace'], true)) {
        return ui_error('Refused', 'sq');
    }
    tg_store_update('stash', function ($s) use ($stashId) {
        unset($s[$stashId]); // one shot: a button can never run twice
        return $s;
    });

    try {
        if ($destructive && $verb === 'delete' && preg_match('/^delete\s+from\s+(`?\w+`?)\s+(where\s+.+)$/is', $sql, $m)) {
            $res  = mysqli_query(bdb(), 'SELECT * FROM ' . $m[1] . ' ' . $m[2] . ' LIMIT 500');
            $keep = [];
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $keep[] = $r;
            }
            tg_store_update('trash', function ($s) use ($keep, $sql) {
                $s['rows'][] = ['t' => tg_now(), 'sql' => tg_clip($sql, 300), 'row' => $keep];
                $s['rows'] = array_slice($s['rows'], -200);
                return $s;
            });
        }
        try {
            mysqli_query(bdb(), 'SET SESSION max_statement_time=10');
        } catch (Throwable $e) {
            // not supported here: fine
        }
        mysqli_query(bdb(), $sql);
        $n  = mysqli_affected_rows(bdb());
        $id = mysqli_insert_id(bdb());
        $summary = $n . ' row(s) affected' . ($id ? ' · insert id ' . $id : '');
        sql_hist($sql, 'OK · ' . $summary);
        bot_log($destructive ? 'tg_sql_destructive' : 'tg_sql', tg_clip($sql, 300));
        toast('✅ Done');
        ui_send('✅ <b>DONE</b> · ' . tg_e($summary) . "\n<pre>" . tg_e(tg_clip($sql, 900)) . '</pre>', kb([[btn('🧮 SQL', 'sq'), btn('🏠 Menu', 'home')]]));
    } catch (Throwable $e) {
        sql_hist($sql, 'ERROR');
        ui_send('❌ <b>SQL ERROR</b>' . "\n<pre>" . tg_e(tg_clip($e->getMessage(), 600)) . '</pre>', kb([[btn('🧮 SQL', 'sq'), btn('🏠 Menu', 'home')]]));
    }
}

/* ------------------------------------------------------------------
 * IP bans (permanent, table ip_bans) and activity log (table tg_activity)
 * Ban = INSERT/UPDATE only. Unban = active 0. Nothing is ever deleted.
 * ------------------------------------------------------------------ */

function bot_bans_count()
{
    try {
        return (int) bq_val('SELECT COUNT(*) FROM ip_bans WHERE active = 1');
    } catch (Throwable $e) {
        return 0;
    }
}

/** Known IPs of a member (newest first): [ip => last seen]. */
function bot_user_ips($username, $limit = 8)
{
    $ips = [];
    $src = [
        'tg_activity'    => 'SELECT ip, MAX(created_at) AS t FROM tg_activity WHERE actor = ? AND ip <> \'\' GROUP BY ip',
        'key_attempts'   => 'SELECT ip, MAX(created_at) AS t FROM key_attempts WHERE username = ? AND ip <> \'\' GROUP BY ip',
        'login_attempts' => 'SELECT ip, MAX(created_at) AS t FROM login_attempts WHERE username = ? AND ip <> \'\' GROUP BY ip',
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
            // a log table without these columns: skip it
        }
    }
    try { // IP columns of the users table itself (register / last login ip...), whatever they are called
        $urow = bq_row('SELECT * FROM users WHERE username = ? LIMIT 1', 's', [$username]);
        if ($urow) {
            foreach ($urow as $col => $val) {
                if ($val !== null && $val !== '' && preg_match('/(^|_)ip($|_)|ip_address|last_ip|reg_ip/i', (string) $col)) {
                    $ip = ipg_norm($val);
                    if ($ip !== '' && !isset($ips[$ip])) {
                        $ips[$ip] = isset($urow['created_at']) ? (string) $urow['created_at'] : '2000-01-01 00:00:00';
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // no such columns: fine
    }
    arsort($ips);
    return array_slice($ips, 0, $limit, true);
}

/** Members seen on an IP. */
function bot_ip_users($ip, $limit = 8)
{
    $names = [];
    $src = [
        'tg_activity'  => 'SELECT actor AS u FROM tg_activity WHERE ip = ? AND role IN (\'member\', \'admin\') GROUP BY actor ORDER BY MAX(id) DESC LIMIT 8',
        'key_attempts' => 'SELECT username AS u FROM key_attempts WHERE ip = ? GROUP BY username ORDER BY MAX(id) DESC LIMIT 8',
    ];
    foreach ($src as $table => $sql) {
        if (!bt_valid($table)) {
            continue;
        }
        try {
            foreach (bq_all($sql, 's', [$ip]) as $r) {
                if ((string) $r['u'] !== '') {
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
        return bot_ipg_missing();
    }
    $per   = 8;
    $total = (int) bq_val('SELECT COUNT(*) FROM ip_bans');
    $act   = bot_bans_count();
    $pages = max(1, (int) ceil($total / $per));
    $page  = max(0, min((int) $page, $pages - 1));
    $rows  = bq_all('SELECT id, ip, reason, active, hits FROM ip_bans ORDER BY active DESC, id DESC LIMIT ' . $per . ' OFFSET ' . ($page * $per));
    $kbd   = [];
    foreach ($rows as $r) {
        $kbd[] = [btn(((int) $r['active'] === 1 ? '🚫 ' : '✅ ') . $r['ip'] . ' · ' . tg_clip($r['reason'], 22), 'bv|' . (int) $r['id'])];
    }
    $kbd[] = [btn('➕ Ban an IP', 'bi'), btn('🔍 Check an IP', 'bs')];
    if ($pages > 1) {
        $kbd[] = pager_row('bn', [], $page, $pages);
    }
    $kbd[] = home_row();
    ui_send('🚫 <b>IP BANS</b> · ' . $act . ' active / ' . $total . " total\n" . hr() . "\n🚫 banned now   ✅ unbanned (history)\n"
        . "A banned IP only sees the <b>Access blocked</b> page. Tap one to see details or unban.\n"
        . "Quick commands: <code>/ban IP reason</code> · <code>/unban IP</code> · <code>/ip IP</code>", kb($kbd));
}

function screen_ban_view($id, $note = '')
{
    $b = bq_row('SELECT * FROM ip_bans WHERE id = ? LIMIT 1', 'i', [(int) $id]);
    if (!$b) {
        return ui_error('Ban not found', 'bn|0');
    }
    $on = (int) $b['active'] === 1;
    $t  = ($on ? '🚫' : '✅') . ' <b>' . ($on ? 'BANNED IP' : 'UNBANNED IP') . '</b> · <code>BAN-' . str_pad((string) (int) $b['id'], 5, '0', STR_PAD_LEFT) . "</code>\n" . hr() . "\n";
    if ($note !== '') {
        $t .= $note . "\n" . hr() . "\n";
    }
    $t .= '🌐 <b>IP</b>  <code>' . tg_e($b['ip']) . "</code>\n";
    $t .= '📝 <b>Reason</b>  ' . tg_e($b['reason']) . "\n";
    if ((string) $b['username'] !== '') {
        $t .= '👤 <b>User</b>  <code>' . tg_e($b['username']) . "</code>\n";
    }
    $t .= '👮 <b>Banned by</b>  <code>' . tg_e($b['banned_by']) . "</code>\n";
    $t .= '🕒 <b>Since</b>  ' . tg_e($b['created_at']) . "\n";
    $t .= '🔁 <b>Blocked visits</b>  ' . (int) $b['hits'] . ($b['last_hit'] ? ' · last ' . tg_e($b['last_hit']) : '') . "\n";
    if (!$on && $b['unbanned_at']) {
        $t .= '✅ <b>Unbanned</b>  ' . tg_e($b['unbanned_at']) . ' by <code>' . tg_e($b['unbanned_by']) . "</code>\n";
    }
    $users = bot_ip_users($b['ip']);
    if ($users) {
        $t .= '👥 <b>Seen on this IP</b>  ' . tg_e(implode(', ', $users)) . "\n";
    }
    $kbd = [];
    $kbd[] = $on ? [btn('✅ Unban this IP', 'bx|' . $b['ip']), btn('✍ Change reason', 'bw|' . $b['ip'])]
                 : [btn('🚫 Ban again', 'bk|' . $b['ip'])];
    $kbd[] = [btn('⬅ Back', 'bn|0'), btn('🏠 Menu', 'home')];
    ui_send($t, kb($kbd));
}

function screen_ip($ip, $note = '')
{
    if (!bot_ipg_ready()) {
        return bot_ipg_missing();
    }
    $ip = ipg_norm($ip);
    if ($ip === '') {
        return ui_error('That is not a valid IP address', 'bn|0');
    }
    $b     = ipg_find(bdb(), $ip);
    $on    = $b && (int) $b['active'] === 1;
    $users = bot_ip_users($ip);
    $n     = 0;
    try {
        $n = (int) bq_val('SELECT COUNT(*) FROM tg_activity WHERE ip = ?', 's', [$ip]);
    } catch (Throwable $e) {
        $n = 0;
    }
    $t = '🌐 <b>IP</b> <code>' . tg_e($ip) . "</code>\n" . hr() . "\n";
    if ($note !== '') {
        $t .= $note . "\n" . hr() . "\n";
    }
    $t .= '🛡 <b>Status</b>  ' . ($on ? '🚫 BANNED' : ($b ? '✅ was banned, now unbanned' : '🟢 not banned')) . "\n";
    if ($b) {
        $t .= '📝 <b>Reason</b>  ' . tg_e($b['reason']) . "\n🔁 <b>Blocked visits</b>  " . (int) $b['hits'] . "\n";
    }
    $t .= '👥 <b>Members seen</b>  ' . ($users ? tg_e(implode(', ', $users)) : '—') . "\n";
    $t .= '👁 <b>Logged actions</b>  ' . $n . "\n";
    $kbd = [];
    $kbd[] = $on ? [btn('✅ Unban', 'bx|' . $ip)] : [btn('🚫 Ban forever', 'bk|' . $ip)];
    $kbd[] = [btn('👁 Activity of this IP', cbd('ai', $ip, 0))];
    $kbd[] = [btn('⬅ Back', 'bn|0'), btn('🏠 Menu', 'home')];
    ui_send($t, kb($kbd));
}

/** Step 1 of a ban: show who is behind the IP and ask. */
function screen_ban_confirm($ip, $username = '')
{
    if (!bot_ipg_ready()) {
        return bot_ipg_missing();
    }
    $ip = ipg_norm($ip);
    list($can, $why) = ipg_can_ban($ip);
    if (!$can) {
        return ui_error($why, 'bn|0');
    }
    $b = ipg_find(bdb(), $ip);
    if ($b && (int) $b['active'] === 1) {
        return screen_ban_view($b['id'], 'ℹ️ This IP is already banned.');
    }
    $users = bot_ip_users($ip);
    $t = '🚫 <b>BAN THIS IP FOREVER?</b>' . "\n" . hr() . "\n🌐 <code>" . tg_e($ip) . "</code>\n";
    if ($username !== '') {
        $t .= '👤 Member  <code>' . tg_e($username) . "</code>\n";
    }
    if ($users) {
        $t .= '👥 Also seen on this IP  ' . tg_e(implode(', ', $users)) . "\n";
    }
    $t .= "\nThe visitor will see a clean <b>Access blocked</b> page. You get a message here with the ban details.";
    ui_send($t, kb([
        [btn('✅ Ban forever', cbd('bo', $ip, $username))],
        [btn('✍ Ban + write a reason', cbd('bz', $ip, $username))],
        [btn('✖ Cancel', 'bn|0')],
    ]));
}

function bot_do_ban($ip, $reason, $username = '')
{
    if (!bot_ipg_ready()) {
        return bot_ipg_missing();
    }
    $admin = $GLOBALS['CTX']['admin'];
    $res   = ipg_ban(bdb(), $ip, $reason, $admin, $username);
    if (!$res['ok']) {
        return ui_error($res['msg'], 'bn|0');
    }
    $row = $res['row'];
    bot_log('tg_ipban', $row['ip'] . ' · ' . $row['reason'] . ($username !== '' ? ' · user ' . $username : ''));
    tg_event('ip_ban', ['by' => $admin, 'ip' => $row['ip'], 'username' => $username, 'reason' => $row['reason']]);
    toast('🚫 IP banned');
    screen_ban_view($row['id'], '🚫 <b>Banned forever.</b> The alert with the details was sent to the admins.');
}

function bot_do_unban($ip)
{
    if (!bot_ipg_ready()) {
        return bot_ipg_missing();
    }
    $admin = $GLOBALS['CTX']['admin'];
    $res   = ipg_unban(bdb(), $ip, $admin);
    if (!$res['ok']) {
        return ui_error($res['msg'], 'bn|0');
    }
    bot_log('tg_ipunban', $res['row']['ip']);
    tg_event('ip_unban', ['by' => $admin, 'ip' => $res['row']['ip']]);
    toast('✅ Unbanned');
    screen_ban_view($res['row']['id'], '✅ <b>Unbanned.</b> The visitor can open the site again.');
}

/** Pick one of a member's IPs to ban (or all of them). */
function screen_user_ips($uid)
{
    if (!bot_ipg_ready()) {
        return bot_ipg_missing();
    }
    $u = bq_row('SELECT id, username FROM users WHERE id = ? LIMIT 1', 'i', [(int) $uid]);
    if (!$u) {
        return ui_error('User not found', cbd('us', '', 0));
    }
    $ips = bot_user_ips($u['username']);
    $t   = '🌐 <b>IPs of</b> <code>' . tg_e($u['username']) . "</code>\n" . hr() . "\n";
    $kbd = [];
    if (!$ips) {
        $t .= "No IP recorded for this member yet. The bot learns IPs from what the member does on the site (opens the page, uses keys and tools).\nYou can also ban an IP by hand: <code>/ban IP reason</code>.";
    } else {
        $t .= "Tap an IP to ban it forever.\n";
        foreach ($ips as $ip => $seen) {
            $b      = ipg_find(bdb(), $ip);
            $banned = $b && (int) $b['active'] === 1;
            $kbd[]  = [btn(($banned ? '🚫 ' : '🌐 ') . $ip . ' · ' . substr($seen, 5, 11), $banned ? 'bv|' . (int) $b['id'] : cbd('bk', $ip, $u['username']))];
        }
        $kbd[] = [btn('🚫 Ban ALL these IPs', 'ba|' . (int) $u['id'])];
    }
    $kbd[] = [btn('⬅ User', cbd('dr', 'users', $u['id'])), btn('🏠 Menu', 'home')];
    ui_send($t, kb($kbd));
}

function bot_ban_all($uid, $step)
{
    if (!bot_ipg_ready()) {
        return bot_ipg_missing();
    }
    $u = bq_row('SELECT id, username FROM users WHERE id = ? LIMIT 1', 'i', [(int) $uid]);
    if (!$u) {
        return ui_error('User not found', cbd('us', '', 0));
    }
    $ips = array_keys(bot_user_ips($u['username']));
    if (!$ips) {
        return screen_user_ips($uid);
    }
    if ($step !== 'ok') {
        return ui_send('🚫 <b>BAN ALL IPs OF</b> <code>' . tg_e($u['username']) . "</code>?\n" . hr() . "\n<code>" . tg_e(implode("\n", $ips)) . "</code>\n\nAll of them are banned forever.",
            kb([[btn('✅ Ban all', 'ba|' . (int) $u['id'] . '|ok'), btn('✖ Cancel', 'bu|' . (int) $u['id'])]]));
    }
    $admin = $GLOBALS['CTX']['admin'];
    $done  = [];
    $skip  = [];
    foreach ($ips as $ip) {
        $res = ipg_ban(bdb(), $ip, 'Vi phạm quy định của website', $admin, $u['username']);
        if ($res['ok']) {
            $done[] = $ip;
            tg_event('ip_ban', ['by' => $admin, 'ip' => $ip, 'username' => $u['username'], 'reason' => $res['row']['reason']]);
        } else {
            $skip[] = $ip . ' (' . $res['msg'] . ')';
        }
    }
    if ($done) {
        bot_log('tg_ipban', 'user ' . $u['username'] . ': ' . implode(', ', $done));
    }
    toast('🚫 ' . count($done) . ' IP banned');
    $note = '🚫 <b>Banned ' . count($done) . ' IP(s)</b>' . ($skip ? "\n⚠️ Skipped: " . tg_e(implode('; ', $skip)) : '');
    ui_send('🚫 <b>DONE</b> · <code>' . tg_e($u['username']) . "</code>\n" . hr() . "\n" . $note,
        kb([[btn('🚫 IP bans', 'bn|0'), btn('⬅ User', cbd('dr', 'users', $u['id']))], home_row()]));
}

/* ------------------------------------------------------------------
 * USER BLOCK (account level). Works even when the member has no known IP or changes IP every minute.
 *   - the login password is replaced by an unknown random one (old hash saved)  -> can not log in again
 *   - the key / turns are revoked (old values saved)                            -> can not use any tool
 *   - optional: every known IP of the member is banned too
 *   - an active session is ended on the member's next request (see tg_block_enforce in telegram.php)
 * Nothing is deleted: "unblock" gives the password and the key back.
 * ------------------------------------------------------------------ */

function bot_ipg_missing()
{
    return ui_send('⚠️ <b>IP bans are not available</b>' . "\n" . hr() . "\nThe file <code>ipguard.php</code> is not loaded on this server, so IP bans can not be written or enforced.\n\n"
        . '✅ <b>Block user</b> still works (it locks the login and revokes the key) and does not need ipguard.php.',
        kb([[btn('⛔ Blocked users', 'bl|0', ''), btn('🏠 Menu', 'home')]]));
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

function screen_blocked($page = 0)
{
    $per   = 8;
    $total = (int) bq_val('SELECT COUNT(*) FROM tg_user_blocks WHERE active = 1');
    $pages = max(1, (int) ceil($total / $per));
    $page  = max(0, min((int) $page, $pages - 1));
    $rows  = bq_all('SELECT user_id, username, reason, hits FROM tg_user_blocks WHERE active = 1 ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . ($page * $per));
    $kbd   = [[btn('➕ Block a user', 'bb', '')]];
    foreach ($rows as $r) {
        $kbd[] = [btn('⛔ ' . tg_clip($r['username'], 22) . ' · ' . tg_clip($r['reason'], 20), cbd('dr', 'users', (int) $r['user_id']), '')];
    }
    if ($pages > 1) {
        $kbd[] = pager_row('bl', [], $page, $pages);
    }
    $kbd[] = home_row();
    ui_send('⛔ <b>BLOCKED USERS</b> · ' . $total . "\n" . hr() . "\n"
        . "A blocked member can not log in, has no key and is kicked out on the next click.\nTap a member to open the card and unblock.\n"
        . 'Quick commands: <code>/blockuser name reason</code> · <code>/unblockuser name</code>', kb($kbd));
}

/** Step 1: show what will happen and ask. */
function screen_block_confirm($uid)
{
    $u = bq_row('SELECT id, username, role FROM users WHERE id = ? LIMIT 1', 'i', [(int) $uid]);
    if (!$u) {
        return ui_error('User not found', cbd('us', '', 0));
    }
    if (tg_user_block_row($u['username'])) {
        return bot_row_view('users', (int) $u['id'], 'ℹ️ This user is already blocked.');
    }
    if ((int) $u['role'] === 1) {
        return ui_error('Refused: this is an admin account. Remove the admin rights first (👑 Toggle admin).', cbd('dr', 'users', (int) $u['id']));
    }
    $ips = array_keys(bot_user_ips($u['username']));
    $t   = '⛔ <b>BLOCK THIS USER?</b>' . "\n" . hr() . "\n👤 <code>" . tg_e($u['username']) . '</code> · #' . (int) $u['id'] . "\n"
        . '<blockquote>🔒 login locked (old password saved)' . "\n" . '🔑 key and turns revoked' . "\n"
        . '🚪 session ended on the next click' . "\n" . '🌐 IPs: ' . ($ips ? tg_e(implode(', ', $ips)) : 'none known') . '</blockquote>'
        . "\nNothing is deleted. <b>Unblock</b> gives everything back.";
    ui_send($t, kb([
        [btn('⛔ Block user + ban IPs', 'ub|' . (int) $u['id'] . '|ip')],
        [btn('⛔ Block account only', 'ub|' . (int) $u['id'] . '|acc')],
        [btn('✍ Block + write a reason', 'ub|' . (int) $u['id'] . '|why')],
        [btn('✖ Cancel', cbd('dr', 'users', (int) $u['id']))],
    ]));
}

function bot_user_block($uid, $reason, $withIps)
{
    $admin = $GLOBALS['CTX']['admin'];
    $u     = bq_row('SELECT id, username, role, password, key_type, expiry_date, turns_left FROM users WHERE id = ? LIMIT 1', 'i', [(int) $uid]);
    if (!$u) {
        return ui_error('User not found', cbd('us', '', 0));
    }
    if ((int) $u['role'] === 1) {
        return ui_error('Refused: this is an admin account. Remove the admin rights first (👑 Toggle admin).', cbd('dr', 'users', (int) $u['id']));
    }
    if (tg_user_block_row($u['username'])) {
        return bot_row_view('users', (int) $u['id'], 'ℹ️ This user is already blocked.');
    }
    $reason = trim((string) $reason);
    if ($reason === '') {
        $reason = 'Vi phạm quy định của website';
    }
    $reason = tg_clip($reason, 200);
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

    try {
        bq_exec(
            "UPDATE users SET password = ?, password_changed_at = NOW(), key_type = 'NONE', expiry_date = '2000-01-01 00:00:00', turns_left = NULL, vip_free_clicks = 0, alert_msg = ? WHERE id = ?",
            'ssi',
            [$locked, 'Tài khoản của bạn đã bị chặn. Lý do: ' . $reason, (int) $u['id']]
        );
    } catch (Throwable $e) {
        bq_exec('UPDATE tg_user_blocks SET active = 0 WHERE id = ?', 'i', [$blockId]); // the account was not changed: do not pretend it is blocked
        return ui_error('Could not lock the account: ' . tg_clip($e->getMessage(), 160), cbd('dr', 'users', (int) $u['id']));
    }

    $banned = [];
    $warn   = '';
    if ($withIps) {
        if (!bot_ipg_ready()) {
            $warn = "\n⚠️ IP ban skipped: <code>ipguard.php</code> is not loaded.";
        } else {
            foreach ($ips as $ip) {
                try {
                    $b = ipg_find(bdb(), $ip);
                    if ($b && (int) $b['active'] === 1) {
                        continue; // already banned by someone else: not ours to lift later
                    }
                    $res = ipg_ban(bdb(), $ip, $reason, $admin, $u['username']);
                    if (!empty($res['ok'])) {
                        $banned[] = $ip;
                        tg_event('ip_ban', ['by' => $admin, 'ip' => $ip, 'username' => $u['username'], 'reason' => $reason]);
                    }
                } catch (Throwable $e) {
                    // this IP could not be banned: the account block still stands
                }
            }
            bq_exec('UPDATE tg_user_blocks SET ips = ? WHERE id = ?', 'si', [json_encode($banned), $blockId]);
        }
    }
    bot_log('tg_userblock', $u['username'] . ' · ' . $reason . ($banned ? ' · IPs ' . implode(', ', $banned) : ''));
    tg_event('user_block', ['by' => $admin, 'username' => $u['username'], 'reason' => $reason, 'ips' => count($banned)]);
    toast('⛔ User blocked');
    bot_row_view('users', (int) $u['id'], '⛔ <b>User blocked.</b> Login locked, key revoked' . ($banned ? ', ' . count($banned) . ' IP(s) banned' : '') . '.' . $warn);
}

function bot_user_unblock($uid, $step)
{
    $admin = $GLOBALS['CTX']['admin'];
    $u     = bq_row('SELECT id, username, password FROM users WHERE id = ? LIMIT 1', 'i', [(int) $uid]);
    if (!$u) {
        return ui_error('User not found', cbd('us', '', 0));
    }
    $b = tg_user_block_row($u['username']);
    if (!$b) {
        return bot_row_view('users', (int) $u['id'], 'ℹ️ This user is not blocked.');
    }
    $ips = json_decode((string) $b['ips'], true);
    if (!is_array($ips)) {
        $ips = [];
    }
    if ($step !== 'ok' && $step !== 'keep') {
        return ui_send('✅ <b>UNBLOCK THIS USER?</b>' . "\n" . hr() . "\n👤 <code>" . tg_e($u['username']) . "</code>\n"
            . '<blockquote>📝 ' . tg_e($b['reason']) . "\n🕒 blocked " . tg_e($b['created_at']) . "\n🔁 blocked visits " . (int) $b['hits']
            . ($ips ? "\n🌐 banned IPs: " . tg_e(implode(', ', $ips)) : '') . '</blockquote>'
            . "\nThe old password is given back. The old key comes back if it has not expired.",
            kb([
                [btn('✅ Unblock + lift IP bans', 'uu|' . (int) $u['id'] . '|ok')],
                [btn('✅ Unblock account only', 'uu|' . (int) $u['id'] . '|keep')],
                [btn('✖ Cancel', cbd('dr', 'users', (int) $u['id']))],
            ]));
    }
    $notes = [];
    if ((string) $b['old_password'] !== '' && hash_equals((string) $b['locked_password'], (string) $u['password'])) {
        bq_exec('UPDATE users SET password = ?, password_changed_at = NOW() WHERE id = ?', 'si', [(string) $b['old_password'], (int) $u['id']]);
        $notes[] = 'password restored';
    } else {
        $notes[] = 'password was changed after the block: kept as it is';
    }
    $hasKey = (int) bq_val('SELECT IF(expiry_date > NOW(), 1, 0) FROM users WHERE id = ?', 'i', [(int) $u['id']]);
    if (!$hasKey && $b['old_expiry'] !== null && strtotime((string) $b['old_expiry']) > time() && (string) $b['old_key_type'] !== '') {
        bq_exec('UPDATE users SET key_type = ?, expiry_date = ?, turns_left = ?, alert_msg = NULL WHERE id = ?', 'ssii',
            [(string) $b['old_key_type'], (string) $b['old_expiry'], $b['old_turns'] === null ? null : (int) $b['old_turns'], (int) $u['id']]);
        $notes[] = 'key restored';
    } else {
        bq_exec('UPDATE users SET alert_msg = NULL WHERE id = ?', 'i', [(int) $u['id']]);
    }
    $lifted = 0;
    if ($step === 'ok' && $ips && bot_ipg_ready()) {
        foreach ($ips as $ip) {
            try {
                $res = ipg_unban(bdb(), $ip, $admin);
                if (!empty($res['ok'])) {
                    $lifted++;
                    tg_event('ip_unban', ['by' => $admin, 'ip' => $ip]);
                }
            } catch (Throwable $e) {
                // already unbanned
            }
        }
        $notes[] = $lifted . ' IP ban(s) lifted';
    }
    bq_exec('UPDATE tg_user_blocks SET active = 0, unblocked_at = NOW(), unblocked_by = ? WHERE id = ?', 'si', [(string) $admin, (int) $b['id']]);
    bot_log('tg_userunblock', $u['username'] . ' · ' . implode(', ', $notes));
    tg_event('user_unblock', ['by' => $admin, 'username' => $u['username']]);
    toast('✅ User unblocked');
    bot_row_view('users', (int) $u['id'], '✅ <b>User unblocked.</b> ' . tg_e(implode(' · ', $notes)));
}

/* ------------------------------------------------------------------
 * Custom emoji pack: gallery, /emojiset, /emojimap and "send me an emoji, I tell you its pack"
 * ------------------------------------------------------------------ */

function bot_emoji_gallery($page = 0, $note = '')
{
    $slots = tg_emoji_slots();
    $s     = tg_store_read('settings');
    $picks = (isset($s['emoji_pick']) && is_array($s['emoji_pick'])) ? $s['emoji_pick'] : [];
    $on    = tg_emoji_on();
    $per   = 12;
    $pages = max(1, (int) ceil(count($slots) / $per));
    $page  = max(0, min((int) $page, $pages - 1));
    $pack  = tg_emoji_pack_name();

    $n     = 0;
    $cells = [];
    foreach ($slots as $sl) {
        if (isset($picks[$sl[0]]) && preg_match('/^\d{5,}$/', (string) $picks[$sl[0]])) {
            $n++;
            $cells[] = '<tg-emoji emoji-id="' . $picks[$sl[0]] . '">' . $sl[0] . '</tg-emoji>';
        }
    }
    $t = '🎨 <b>EMOJI PREMIUM</b> · ' . ($on ? '🟢 on' : '🔴 off') . "\n";
    if ($note !== '') {
        $t .= $note . "\n";
    }
    $t .= '<i>' . $n . '/' . count($slots) . ' icons chosen. Tap an icon, then send the Premium emoji you want for it.</i>';
    if ($cells) {
        $t .= "\n<blockquote>" . implode(' ', $cells) . '</blockquote>';
    }
    if ($pack !== '') {
        $t .= "\n<i>Old pack " . tg_e($pack) . ': ' . (empty($s['emoji_legacy_off']) ? 'on (your picks win)' : 'off') . '</i>';
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
    $kbd[] = [btn('🎯 Pick in order', 'eq|' . ($page * $per), 'primary'), btn($on ? '⏸ Premium off' : '▶ Premium on', 'eo', '')];
    $row   = [btn('🧹 Reset picks', 'ez', 'danger')];
    if ($pack !== '') {
        $row[] = btn(empty($s['emoji_legacy_off']) ? '🧩 Old pack: off' : '🧩 Old pack: on', 'ee', '');
    }
    $kbd[] = $row;
    $kbd[] = home_row();
    ui_send($t, kb($kbd));
}

function bot_emoji_pick_prompt($i, $auto)
{
    $slots = tg_emoji_slots();
    $i     = max(0, min((int) $i, count($slots) - 1));
    $page  = (int) floor($i / 12);
    $t     = '🎨 <b>PICK EMOJI</b> · ' . ($i + 1) . '/' . count($slots) . "\n"
        . 'Icon  ' . $slots[$i][0] . "\u{FE0F} <b>" . tg_e($slots[$i][1]) . "</b>\n\n"
        . 'Send <b>one Premium emoji</b> now.'
        . ($auto ? "\n<i>I ask the next icon right after. You can also send several emoji in one message: they fill the next icons in order.</i>" : '');
    $btns  = $auto ? [btn('⏭ Skip', 'ek', ''), btn('✖ Stop', 'em|' . $page, '')] : [btn('✖ Cancel', 'em|' . $page, '')];
    ui_send($t, kb([$btns]));
}

function bot_emoji_pick_start($i, $auto)
{
    $slots = tg_emoji_slots();
    $i     = max(0, min((int) $i, count($slots) - 1));
    conv_set($GLOBALS['CTX']['admin'], 'emoji_pick', ['i' => $i, 'auto' => $auto ? 1 : 0]);
    bot_emoji_pick_prompt($i, $auto);
}

function bot_emoji_pick_skip()
{
    $admin = $GLOBALS['CTX']['admin'];
    $conv  = conv_get($admin);
    if (!$conv || $conv['mode'] !== 'emoji_pick') {
        return bot_emoji_gallery(0);
    }
    $i = (int) (isset($conv['data']['i']) ? $conv['data']['i'] : 0) + 1;
    if ($i >= count(tg_emoji_slots())) {
        conv_clear($admin);
        return bot_emoji_gallery(0, '✅ <b>All icons done.</b>');
    }
    conv_set($admin, 'emoji_pick', ['i' => $i, 'auto' => 1]);
    bot_emoji_pick_prompt($i, true);
}

/** The admin sent a message while the bot waits for a Premium emoji. */
function bot_emoji_pick_input(array $conv, array $m)
{
    $admin = $GLOBALS['CTX']['admin'];
    $d     = (isset($conv['data']) && is_array($conv['data'])) ? $conv['data'] : [];
    $slots = tg_emoji_slots();
    $i     = isset($d['i']) ? (int) $d['i'] : 0;
    $auto  = !empty($d['auto']);
    if (!isset($slots[$i])) {
        conv_clear($admin);
        return bot_emoji_gallery(0);
    }
    $ids = [];
    foreach ((array) (isset($m['entities']) ? $m['entities'] : []) as $en) {
        if (isset($en['type']) && $en['type'] === 'custom_emoji' && !empty($en['custom_emoji_id'])) {
            $ids[] = (string) $en['custom_emoji_id'];
        }
    }
    if (!$ids) {
        conv_set($admin, 'emoji_pick', $d);
        return ui_send('❌ <b>That is a normal message / emoji.</b>' . "\n" . 'Send a <b>Premium</b> (custom) emoji for ' . $slots[$i][0] . "\u{FE0F} <b>" . tg_e($slots[$i][1]) . '</b>.',
            kb([[btn('⏭ Skip', 'ek', ''), btn('✖ Cancel', 'em|' . (int) floor($i / 12), '')]]));
    }
    if (!$auto) {
        $ids = array_slice($ids, 0, 1);
    }
    $saved = [];
    $next  = $i;
    tg_store_update('settings', function ($s) use ($ids, $slots, &$next, &$saved) {
        foreach ($ids as $id) {
            if (!isset($slots[$next])) {
                break;
            }
            $s['emoji_pick'][$slots[$next][0]] = $id;
            $saved[] = '<tg-emoji emoji-id="' . $id . '">' . $slots[$next][0] . '</tg-emoji>';
            $next++;
        }
        return $s;
    });
    tg_store_update('emojidown', function ($x) {
        return [];
    });
    tg_emoji_reset();
    bot_log('tg_emoji', count($saved) . ' icon(s) picked, from #' . ($i + 1));
    $note = '✅ <b>Saved</b> ' . implode(' ', $saved);
    if ($auto && isset($slots[$next])) {
        conv_set($admin, 'emoji_pick', ['i' => $next, 'auto' => 1]);
        return bot_emoji_pick_prompt($next, true);
    }
    conv_clear($admin);
    bot_emoji_gallery((int) floor($i / 12), $note);
}

function bot_emoji_reset($step)
{
    if ($step !== 'ok') {
        return ui_send('🧹 <b>RESET PICKS?</b>' . "\n" . 'Every icon you chose goes back to the default.', kb([[btn('✅ Yes, reset', 'ez|ok'), btn('✖ Cancel', 'em|0', '')]]));
    }
    tg_store_update('settings', function ($s) {
        unset($s['emoji_pick']);
        return $s;
    });
    tg_emoji_reset();
    bot_log('tg_emoji', 'picks reset');
    toast('🧹 Reset');
    bot_emoji_gallery(0, '🧹 <b>Picks cleared.</b>');
}

function bot_emoji_set($arg)
{
    $name = '';
    if (preg_match('~(?:addemoji|addstickers)/([A-Za-z0-9_]+)~', $arg, $mm)) {
        $name = $mm[1];
    } else {
        $name = trim($arg, "@ \t\r\n");
    }
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
        return ui_error('That is not a pack name or a t.me/addemoji link', 'em|0');
    }
    $res = tg_api('getStickerSet', ['name' => $name]);
    if (empty($res['ok'])) {
        return ui_error('Telegram says: ' . tg_clip(isset($res['description']) ? $res['description'] : 'pack not found', 120), 'em|0');
    }
    tg_store_update('settings', function ($s) use ($name) {
        $s['emoji_pack'] = $name;
        return $s;
    });
    tg_store_update('emojipack', function ($x) {
        return [];
    });
    tg_store_update('emojidown', function ($x) {
        return [];
    });
    tg_emoji_reset();
    tg_emoji_pack(true);
    bot_log('tg_emoji', 'pack ' . $name);
    bot_emoji_gallery(0, '✅ <b>Pack saved.</b> Buttons and messages use it from now on.');
}

function bot_emoji_map_cmd($arg)
{
    $w   = preg_split('/\s+/', trim((string) $arg), -1, PREG_SPLIT_NO_EMPTY);
    $def = tg_emoji_default_map();
    if (count($w) < 2 || !isset($def[$w[0]]) || !ctype_digit($w[1]) || (int) $w[1] < 1) {
        return ui_send('🎨 <b>EMOJI MAP</b>' . "\n" . hr() . "\nUse <code>/emojimap name number</code>\nExample: <code>/emojimap ok 27</code>\n\nNames:\n<code>" . tg_e(implode(' ', array_keys($def))) . '</code>',
            kb([[btn('🎨 Emoji pack', 'em|0'), btn('🏠 Menu', 'home')]]));
    }
    $pack = tg_emoji_pack();
    if ($pack['list'] && (int) $w[1] > count($pack['list'])) {
        return ui_error('The pack has only ' . count($pack['list']) . ' emoji', 'em|0');
    }
    $key = $w[0];
    $num = (int) $w[1];
    tg_store_update('settings', function ($s) use ($key, $num) {
        $s['emoji_map'][$key] = $num;
        return $s;
    });
    tg_emoji_reset();
    bot_log('tg_emoji', $key . ' → #' . $num);
    bot_emoji_gallery(0, '✅ <b>' . tg_e($key) . '</b> → #' . $num);
}

/** The admin sent a message with custom emoji: show their ids and the pack they belong to. */
function bot_emoji_ids(array $ids)
{
    $ids   = array_slice($ids, 0, 12);
    $res   = tg_api('getCustomEmojiStickers', ['custom_emoji_ids' => $ids]);
    $found = [];
    $sets  = [];
    if (!empty($res['ok']) && isset($res['result']) && is_array($res['result'])) {
        foreach ($res['result'] as $st) {
            $found[(string) $st['custom_emoji_id']] = $st;
            if (!empty($st['set_name'])) {
                $sets[(string) $st['set_name']] = true;
            }
        }
    }
    $t = '🎨 <b>CUSTOM EMOJI</b>' . "\n" . hr() . "\n";
    foreach ($ids as $id) {
        $e  = isset($found[$id]['emoji']) ? (string) $found[$id]['emoji'] : '⭐';
        $t .= '<tg-emoji emoji-id="' . tg_e($id) . '">' . tg_e($e) . '</tg-emoji> <code>' . tg_e($id) . '</code>' . (isset($found[$id]['set_name']) ? ' · ' . tg_e($found[$id]['set_name']) : '') . "\n";
    }
    if ($sets) {
        $names = array_map('strval', array_keys($sets));
        $t    .= hr() . "\nPack: <code>" . tg_e($names[0]) . "</code>\nUse it with: <code>/emojiset " . tg_e($names[0]) . '</code>';
    }
    ui_send($t, kb([[btn('🎨 Emoji pack', 'em|0'), btn('🏠 Menu', 'home')]]));
}

/** Activity feed. $mode: a all · m members · b bot/admin · x blocked · u one user · i one IP */
function screen_activity($mode, $arg, $page)
{
    $per   = 8;
    $where = '';
    $types = '';
    $par   = [];
    $title = 'ALL ACTIVITY';
    switch ($mode) {
        case 'm':
            $where = "WHERE role IN ('member', 'admin')";
            $title = 'MEMBERS';
            break;
        case 'b':
            $where = "WHERE role IN ('bot', 'admin_cp')";
            $title = 'ADMIN (TELEGRAM BOT + ADMIN_CP)';
            break;
        case 'x':
            $where = "WHERE role = 'blocked'";
            $title = 'BLOCKED VISITS';
            break;
        case 'u':
            $where = 'WHERE actor = ?';
            $types = 's';
            $par   = [$arg];
            $title = 'ACTIVITY · ' . $arg;
            break;
        case 'i':
            $where = 'WHERE ip = ?';
            $types = 's';
            $par   = [$arg];
            $title = 'ACTIVITY · IP ' . $arg;
            break;
        default:
            $mode = 'a';
    }
    $total = (int) bq_val('SELECT COUNT(*) FROM tg_activity ' . $where, $types, $par);
    $pages = max(1, (int) ceil($total / $per));
    $page  = max(0, min((int) $page, $pages - 1));
    $rows  = bq_all('SELECT actor, role, action, detail, ip, created_at FROM tg_activity ' . $where . ' ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . ($page * $per), $types, $par);
    $t = '👁 <b>' . tg_e(tg_clip($title, 60)) . '</b> · ' . $total . " rows\n" . hr() . "\n";
    foreach ($rows as $r) {
        $t .= '<i>' . tg_e(substr($r['created_at'], 5)) . '</i> · <b>' . tg_e(tg_clip($r['actor'], 24)) . '</b> · <code>' . tg_e($r['action']) . "</code>\n";
        $line = trim(tg_clip($r['detail'], 80) . ($r['ip'] !== '' && $r['ip'] !== 'telegram' ? ' · ' . $r['ip'] : ''));
        if ($line !== '') {
            $t .= '   ' . tg_e($line) . "\n";
        }
    }
    if (!$rows) {
        $t .= 'Nothing yet.';
    }
    $kbd = [];
    if ($mode === 'u' || $mode === 'i') {
        if ($pages > 1) {
            $kbd[] = pager_row($mode === 'u' ? 'au' : 'ai', [$mode === 'u' ? rawurlencode($arg) : $arg], $page, $pages);
        }
    } else {
        $kbd[] = [btn(($mode === 'a' ? '• ' : '') . 'All', 'ac|a|0'), btn(($mode === 'm' ? '• ' : '') . 'Members', 'ac|m|0'),
                  btn(($mode === 'b' ? '• ' : '') . 'Admin', 'ac|b|0'), btn(($mode === 'x' ? '• ' : '') . 'Blocked', 'ac|x|0')];
        if ($pages > 1) {
            $kbd[] = pager_row('ac', [$mode], $page, $pages);
        }
    }
    $kbd[] = [btn('🔄 Refresh', cbd('ac', $mode === 'u' || $mode === 'i' ? 'a' : $mode, 0)), btn('🏠 Menu', 'home')];
    ui_send($t, kb($kbd));
}

/* ------------------------------------------------------------------
 * Typed answers (conversation) and commands
 * ------------------------------------------------------------------ */

function bot_conv_input(array $conv, $text, $messageId)
{
    $admin = $GLOBALS['CTX']['admin'];
    $mode  = $conv['mode'];
    $d     = isset($conv['data']) && is_array($conv['data']) ? $conv['data'] : [];
    conv_clear($admin); // one step at a time: a step that needs more input sets a new state below

    switch ($mode) {
        case 'edit':
            return bot_apply_edit($d['t'], $d['pk'], $d['c'], $text);

        case 'pw':
            tg_api('deleteMessage', ['chat_id' => $GLOBALS['CTX']['chat'], 'message_id' => $messageId]); // do not leave the password in the chat
            if (strlen($text) < 6) {
                conv_set($admin, 'pw', $d);
                return ui_send('❌ <b>Too short.</b> Send a password with 6+ characters.', kb([[btn('✖ Cancel', 'cx')]]));
            }
            bq_exec('UPDATE users SET password = ?, password_changed_at = NOW() WHERE id = ?', 'si', [password_hash($text, PASSWORD_DEFAULT), (int) $d['id']]);
            bot_log('tg_user', 'users#' . (int) $d['id'] . ': password changed');
            toast('✅ Password saved');
            return bot_row_view('users', (int) $d['id'], '✅ <b>Password changed</b> (your message was deleted)');

        case 'search_user':
            $exact = bq_row('SELECT id FROM users WHERE username = ? LIMIT 1', 's', [$text]);
            return $exact ? bot_row_view('users', $exact['id']) : screen_users(0, $text);

        case 'search_key':
            return screen_key_search($text);

        case 'cfg_key':
            if (!preg_match('/^[A-Za-z0-9_]{1,50}$/', $text)) {
                conv_set($admin, 'cfg_key');
                return ui_send('❌ <b>Use letters, numbers and _ only (max 50).</b> Send the setting name again.', kb([[btn('✖ Cancel', 'cx')]]));
            }
            conv_set($admin, 'cfg_val', ['k' => $text]);
            return ui_send('⚙️ Setting <code>' . tg_e($text) . "</code>\nNow send its <b>value</b>.", kb([[btn('✖ Cancel', 'cx')]]));

        case 'cfg_val':
            bot_set_config($d['k'], $text);
            bot_log('tg_config', $d['k'] . ' set');
            toast('✅ Saved');
            return bot_row_view('site_config', $d['k'], '✅ <b>Saved</b>');

        case 'pn':
            conv_set($admin, 'pl', ['name' => tg_clip($text, 255)]);
            return ui_send('📦 <b>ADD TOOL</b> · step 2/4' . "\n" . hr() . "\nSend the <b>download / open link</b> (https://...).", kb([[btn('✖ Cancel', 'cx')]]));

        case 'pl':
            if (!preg_match('#^https?://[^\s/]#i', $text)) {
                conv_set($admin, 'pl', $d);
                return ui_send('❌ <b>The link must start with http:// or https://</b>. Send it again.', kb([[btn('✖ Cancel', 'cx')]]));
            }
            $d['link'] = $text;
            conv_set($admin, 'pi', $d);
            return ui_send('📦 <b>ADD TOOL</b> · step 3/4' . "\n" . hr() . "\nSend the <b>image</b> path or URL (example <code>uploads/tool.jpg</code>), or <code>-</code> for none.", kb([[btn('✖ Cancel', 'cx')]]));

        case 'pi':
            $d['image'] = $text === '-' ? '' : tg_clip($text, 255);
            conv_set($admin, 'pt', $d);
            return ui_send('📦 <b>ADD TOOL</b> · step 4/4' . "\n" . hr() . "\n<b>" . tg_e($d['name']) . "</b>\nChoose the type:", kb([[btn('🟢 FREE', 'pt|FREE'), btn('💎 VIP', 'pt|VIP')], [btn('✖ Cancel', 'cx')]]));

        case 'pt':
            conv_set($admin, 'pt', $d);
            return ui_send('Use the 🟢 FREE / 💎 VIP buttons above.', kb([[btn('🟢 FREE', 'pt|FREE'), btn('💎 VIP', 'pt|VIP')], [btn('✖ Cancel', 'cx')]]));

        case 'sql':
            return bot_sql($text);

        case 'ban_ip':
            $ip = ipg_norm($text);
            if ($ip === '') {
                conv_set($admin, 'ban_ip');
                return ui_send('❌ <b>That is not a valid IP.</b> Send it again (example <code>203.0.113.7</code>).', kb([[btn('✖ Cancel', 'cx')]]));
            }
            return screen_ban_confirm($ip);

        case 'ban_reason':
            return bot_do_ban($d['ip'], $text, isset($d['u']) ? (string) $d['u'] : '');

        case 'ban_reason_edit':
            $res = ipg_ban(bdb(), $d['ip'], $text, $admin, '');
            if (!$res['ok']) {
                return ui_error($res['msg'], 'bn|0');
            }
            bot_log('tg_ipban', $d['ip'] . ' reason changed: ' . $text);
            return screen_ban_view($res['row']['id'], '✅ <b>Reason updated.</b>');

        case 'ip_info':
            return screen_ip($text);

        case 'block_user':
            $bu = bot_user_find($text);
            return $bu ? screen_block_confirm((int) $bu['id']) : ui_error('User not found', cbd('us', '', 0));

        case 'block_reason':
            return bot_user_block((int) $d['id'], $text, true);
    }
    return screen_home();
}

function bot_denied($from, array $m, $text)
{
    $chat = (string) (isset($m['chat']['id']) ? $m['chat']['id'] : '');
    $user = isset($m['from']['username']) ? '@' . $m['from']['username'] : '(no username)';
    if (stripos($text, '/id') === 0) {
        $msg = '🆔 Your Telegram ID: <code>' . tg_e($from) . '</code>';
    } else {
        $msg = "⛔ <b>Access denied</b>\nYour Telegram ID: <code>" . tg_e($from) . "</code>\nAsk the site owner to add this ID to <code>admin_ids</code> in telegram_config.php.";
    }
    tg_api('sendMessage', ['chat_id' => $chat, 'text' => $msg, 'parse_mode' => 'HTML']);
    if (tg_throttle('unauth:' . $from, 600)) {
        tg_event('bot_audit', ['admin' => '-', 'action' => 'UNAUTHORIZED ACCESS', 'detail' => 'ID ' . $from . ' ' . $user . ' wrote: ' . tg_clip($text, 80)]);
    }
}

function bot_help()
{
    ui_send('🤖 <b>COMMANDS</b>' . "\n" . '<blockquote>'
        . "/menu · control center\n"
        . "/user <code>name</code> · /key <code>CODE</code>\n"
        . "/genkey <code>free 10 24</code>\n"
        . "/sql <code>query</code> · /health\n"
        . "/ban <code>IP why</code> · /unban <code>IP</code> · /bans · /ip <code>IP</code>\n"
        . "/activity <code>name</code> · /xoaluot <code>name</code>\n"
        . "/blockuser <code>name why</code> · /unblockuser <code>name</code> · /blocked\n"
        . "/emoji · pick Premium emoji\n"
        . "/cancel · /id"
        . '</blockquote>', kb([home_row()]));
}

function bot_on_message(array $m)
{
    $from    = (string) (isset($m['from']['id']) ? $m['from']['id'] : '');
    $chatId  = (string) (isset($m['chat']['id']) ? $m['chat']['id'] : '');
    $private = (isset($m['chat']['type']) ? $m['chat']['type'] : '') === 'private';
    $text    = isset($m['text']) ? trim((string) $m['text']) : '';
    $GLOBALS['CTX'] = ['admin' => $from, 'chat' => $chatId, 'mid' => 0, 'edit' => false, 'toast' => '',
        'name' => isset($m['from']['first_name']) ? (string) $m['from']['first_name'] : 'admin'];

    if ($from === '' || !$private || !in_array($from, tg_admin_ids(), true)) {
        tg_activity('tg:' . $from, 'bot', 'DENIED', tg_clip($text, 120), 'telegram');
        return bot_denied($from, $m, $text);
    }
    if ($text === '') {
        return;
    }
    // every message an admin sends is logged (a password being typed is hidden)
    $convNow = conv_get($from);
    tg_activity('tg:' . $from, 'bot', $text[0] === '/' ? 'command' : 'typed', ($convNow && $convNow['mode'] === 'pw' && $text[0] !== '/') ? '(password hidden)' : tg_clip($text, 300), 'telegram');

    if ($text[0] !== '/' && !conv_get($from) && !empty($m['entities']) && is_array($m['entities'])) { // custom emoji sent to the bot: tell the ids and the pack name
        $ceIds = [];
        foreach ($m['entities'] as $en) {
            if (isset($en['type']) && $en['type'] === 'custom_emoji' && !empty($en['custom_emoji_id'])) {
                $ceIds[] = (string) $en['custom_emoji_id'];
            }
        }
        if ($ceIds) {
            return bot_emoji_ids(array_values(array_unique($ceIds)));
        }
    }

    if ($text[0] === '/') {
        preg_match('~^/([A-Za-z_]+)(?:@\w+)?(?:\s+(.*))?$~s', $text, $mm);
        $cmd = strtolower(isset($mm[1]) ? $mm[1] : '');
        $arg = isset($mm[2]) ? trim($mm[2]) : '';
        switch ($cmd) {
            case 'start':
                conv_clear($from);
                return screen_home('👋 Welcome back, <b>' . tg_e($GLOBALS['CTX']['name']) . '</b>!');
            case 'menu':
            case 'stats':
                conv_clear($from);
                return screen_home();
            case 'cancel':
                conv_clear($from);
                return screen_home('✖ <b>Cancelled</b>');
            case 'id':
                return ui_send('🆔 Your Telegram ID: <code>' . tg_e($from) . '</code>');
            case 'help':
                return bot_help();
            case 'health':
                return screen_health();
            case 'user':
                if ($arg === '') {
                    conv_set($from, 'search_user');
                    return ui_send('🔍 Send part of the username.', kb([[btn('✖ Cancel', 'cx')]]));
                }
                $exact = bq_row('SELECT id FROM users WHERE username = ? LIMIT 1', 's', [$arg]);
                return $exact ? bot_row_view('users', $exact['id']) : screen_users(0, $arg);
            case 'key':
                if ($arg === '') {
                    conv_set($from, 'search_key');
                    return ui_send('🔍 Send the key (or part of it).', kb([[btn('✖ Cancel', 'cx')]]));
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
                    return ui_send('🚫 Send the <b>IP</b> to ban (example <code>203.0.113.7</code>).' . "\nOr in one go: <code>/ban 203.0.113.7 reason</code>", kb([[btn('✖ Cancel', 'cx')]]));
                }
                return bot_do_ban($ip, isset($w[1]) ? $w[1] : '', '');
            case 'unban':
                $ip = ipg_norm($arg);
                if ($ip === '') {
                    return ui_send('Use <code>/unban 203.0.113.7</code>', kb([[btn('🚫 IP bans', 'bn|0'), btn('🏠 Menu', 'home')]]));
                }
                return bot_do_unban($ip);
            case 'ip':
                if (ipg_norm($arg) === '') {
                    conv_set($from, 'ip_info');
                    return ui_send('🔍 Send the <b>IP</b> to check.', kb([[btn('✖ Cancel', 'cx')]]));
                }
                return screen_ip($arg);
            case 'activity':
                return $arg === '' ? screen_activity('a', '', 0) : screen_activity('u', $arg, 0);
            case 'blocked':
                return screen_blocked(0);
            case 'blockuser':
                $bw = preg_split('/\s+/', $arg, 2, PREG_SPLIT_NO_EMPTY);
                if (!$bw) {
                    conv_set($from, 'block_user');
                    return ui_send('⛔ Send the <b>username</b> to block.' . "\n" . 'Or in one go: <code>/blockuser name reason</code>', kb([[btn('✖ Cancel', 'cx')]]));
                }
                $bu = bot_user_find($bw[0]);
                if (!$bu) {
                    return ui_error('User not found', cbd('us', '', 0));
                }
                return isset($bw[1]) ? bot_user_block((int) $bu['id'], $bw[1], true) : screen_block_confirm((int) $bu['id']);
            case 'unblockuser':
                if ($arg === '') {
                    return ui_send('Use <code>/unblockuser username</code>', kb([[btn('⛔ Blocked users', 'bl|0', ''), btn('🏠 Menu', 'home')]]));
                }
                $bu = bot_user_find($arg);
                return $bu ? bot_user_unblock((int) $bu['id'], '') : ui_error('User not found', cbd('us', '', 0));
            case 'emoji':
                return bot_emoji_gallery(0);
            case 'emojiset':
                return $arg === '' ? bot_emoji_gallery(0) : bot_emoji_set($arg);
            case 'emojimap':
                return bot_emoji_map_cmd($arg);
            case 'xoaluot':
                if ($arg === '') {
                    return ui_send('Use <code>/xoaluot username</code> to clear all turns of a member.', kb([home_row()]));
                }
                $exact = bq_row('SELECT id FROM users WHERE username = ? LIMIT 1', 's', [$arg]);
                return $exact ? bot_user_action((int) $exact['id'], 'clear', '') : ui_error('User not found', cbd('us', '', 0));
        }
        return bot_help();
    }

    $conv = conv_get($from);
    if ($conv && $conv['mode'] === 'emoji_pick') {
        return bot_emoji_pick_input($conv, $m);
    }
    if ($conv) {
        return bot_conv_input($conv, $text, isset($m['message_id']) ? (int) $m['message_id'] : 0);
    }
    ui_send('Use /menu to open the control center.', kb([home_row()]));
}

/* ------------------------------------------------------------------
 * Button presses
 * ------------------------------------------------------------------ */

function bot_route($data)
{
    $admin = $GLOBALS['CTX']['admin'];
    $p     = explode('|', $data);
    $route = array_shift($p);
    $a = isset($p[0]) ? $p[0] : '';
    $b = isset($p[1]) ? $p[1] : '';
    $c = isset($p[2]) ? $p[2] : '';
    $cancel = kb([[btn('✖ Cancel', 'cx')]]);

    switch ($route) {
        case 'noop':
            return;
        case 'home':
            return screen_home();
        case 'cx':
            conv_clear($admin);
            return screen_home('✖ <b>Cancelled</b>');
        case 'us':
            return screen_users((int) $b, rawurldecode($a));
        case 'su':
            conv_set($admin, 'search_user');
            return ui_send('🔍 Send part of the username.', $cancel);
        case 'ky':
            return screen_keys();
        case 'kg':
            return screen_gen_keys($a, $b, $c);
        case 'kl':
            return screen_key_list($a, $b, $c);
        case 'sk':
            conv_set($admin, 'search_key');
            return ui_send('🔍 Send the key (or part of it).', $cancel);
        case 'kx':
            return bot_key_action($a, (int) $b, $c);
        case 'pr':
            return screen_products((int) $a);
        case 'pa':
            conv_set($admin, 'pn');
            return ui_send('📦 <b>ADD TOOL</b> · step 1/4' . "\n" . hr() . "\nSend the <b>name</b> of the tool.", $cancel);
        case 'pt':
            return bot_product_add_finish($a);
        case 'px':
            return bot_product_swap((int) $a);
        case 'cf':
            return screen_config((int) $a);
        case 'cn':
            conv_set($admin, 'cfg_key');
            return ui_send('⚙️ <b>NEW SETTING</b>' . "\n" . hr() . "\nSend the setting <b>name</b> (letters, numbers, _).", $cancel);
        case 'ct':
            return bot_config_toggle($a);
        case 'mt':
            return bot_maintenance($a);
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
            toast('🔔 Test alert sent');
            return screen_health();
        case 'al':
            return screen_alerts();
        case 'at':
            $cats = tg_categories();
            if (isset($cats[$a])) {
                $now = !tg_cat_on($a);
                tg_store_update('settings', function ($s) use ($a, $now) {
                    $s['cat'][$a] = $now;
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
            return ui_send('🚫 <b>BAN AN IP</b>' . "\n" . hr() . "\nSend the <b>IP address</b> (example <code>203.0.113.7</code>).", $cancel);
        case 'bs':
            conv_set($admin, 'ip_info');
            return ui_send('🔍 Send the <b>IP</b> to check.', $cancel);
        case 'bk':
            return screen_ban_confirm($a, $b);
        case 'bo':
            return bot_do_ban($a, '', $b);
        case 'bz':
            conv_set($admin, 'ban_reason', ['ip' => ipg_norm($a), 'u' => $b]);
            return ui_send('✍ <b>REASON</b> for banning <code>' . tg_e($a) . "</code>\n" . hr() . "\nSend the reason (the visitor sees it on the blocked page). /cancel to abort.", $cancel);
        case 'bw':
            conv_set($admin, 'ban_reason_edit', ['ip' => ipg_norm($a)]);
            return ui_send('✍ <b>NEW REASON</b> for <code>' . tg_e($a) . "</code>\n" . hr() . "\nSend the new reason.", $cancel);
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
                return ui_send('✍ <b>REASON</b> for blocking this user' . "\n" . hr() . "\nSend the reason (it is saved in the log). /cancel to abort.", $cancel);
            }
            return screen_block_confirm((int) $a);
        case 'uu':
            return bot_user_unblock((int) $a, $b);
        case 'bl':
            return screen_blocked((int) $a);
        case 'bb':
            conv_set($admin, 'block_user');
            return ui_send('⛔ <b>BLOCK A USER</b>' . "\n" . hr() . "\nSend the <b>username</b>.", $cancel);
        case 'em':
            return bot_emoji_gallery((int) $a);
        case 'ep':
            return bot_emoji_pick_start((int) $a, false);
        case 'eq':
            return bot_emoji_pick_start((int) $a, true);
        case 'ek':
            return bot_emoji_pick_skip();
        case 'ez':
            return bot_emoji_reset($a);
        case 'eo':
        case 'ee':
            $swKey = $route === 'eo' ? 'emoji_off' : 'emoji_legacy_off';
            tg_store_update('settings', function ($s) use ($swKey) {
                $s[$swKey] = empty($s[$swKey]);
                return $s;
            });
            tg_store_update('emojidown', function ($x) {
                return [];
            });
            tg_emoji_reset();
            return bot_emoji_gallery(0);
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
            return ui_send('🧮 Type your <b>SQL query</b> now.', $cancel);
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
    $GLOBALS['CTX'] = ['admin' => $from, 'chat' => $chatId, 'mid' => (int) (isset($q['message']['message_id']) ? $q['message']['message_id'] : 0),
        'edit' => true, 'toast' => '', 'name' => isset($q['from']['first_name']) ? (string) $q['from']['first_name'] : 'admin'];

    if ($from === '' || !$private || !in_array($from, tg_admin_ids(), true)) {
        tg_api('answerCallbackQuery', ['callback_query_id' => $q['id'], 'text' => '⛔ Access denied', 'show_alert' => true]);
        return;
    }
    try {
        $routeData = cbd_resolve((string) (isset($q['data']) ? $q['data'] : ''));
        tg_activity('tg:' . $from, 'bot', 'button', tg_clip($routeData, 300), 'telegram'); // every button an admin presses is logged
        bot_route($routeData);
    } finally {
        $ack = ['callback_query_id' => $q['id']];
        if ($GLOBALS['CTX']['toast'] !== '') {
            $ack['text'] = $GLOBALS['CTX']['toast'];
        }
        tg_api('answerCallbackQuery', $ack);
    }
}

/* ------------------------------------------------------------------
 * One-time setup page:  telegram_bot.php?setup=<setup_key>
 * ------------------------------------------------------------------ */

function bot_self_url()
{
    $custom = trim((string) tg_cfg('webhook_url', ''));
    if ($custom !== '') {
        return $custom;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '')) === 'https';
    $host = (string) (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
    $path = (string) strtok((string) (isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/telegram_bot.php'), '?');
    return ($https ? 'https' : 'http') . '://' . $host . $path;
}

function bot_setup()
{
    $lines = [];
    $me    = tg_api('getMe');
    $lines[] = (!empty($me['ok']) ? '✅' : '❌') . ' Bot token: ' . (!empty($me['ok']) ? '@' . $me['result']['username'] : (isset($me['description']) ? $me['description'] : 'failed'));

    $url = bot_self_url();
    $wh  = tg_api('setWebhook', [
        'url' => $url, 'secret_token' => (string) tg_cfg('webhook_secret', ''),
        'allowed_updates' => ['message', 'callback_query'], 'max_connections' => 10,
    ]);
    $lines[] = (!empty($wh['ok']) ? '✅' : '❌') . ' Webhook ' . $url . ' → ' . (!empty($wh['ok']) ? 'set' : (isset($wh['description']) ? $wh['description'] : 'failed'));
    if (stripos($url, 'https://') !== 0) {
        $lines[] = '⚠️ Telegram only delivers to https:// addresses (use your real domain, or a tunnel such as ngrok while testing locally).';
    }

    $cmds = [
        ['command' => 'menu', 'description' => 'Open the control center'],
        ['command' => 'stats', 'description' => 'Dashboard'],
        ['command' => 'user', 'description' => 'Find a user: /user name'],
        ['command' => 'key', 'description' => 'Find a key: /key CODE'],
        ['command' => 'genkey', 'description' => 'Make keys: /genkey free 10 24'],
        ['command' => 'sql', 'description' => 'Run SQL: /sql SELECT ...'],
        ['command' => 'health', 'description' => 'System health'],
        ['command' => 'ban', 'description' => 'Ban an IP forever: /ban IP reason'],
        ['command' => 'unban', 'description' => 'Unban an IP: /unban IP'],
        ['command' => 'bans', 'description' => 'List banned IPs'],
        ['command' => 'ip', 'description' => 'Check an IP: /ip IP'],
        ['command' => 'activity', 'description' => 'Member activity: /activity name'],
        ['command' => 'xoaluot', 'description' => 'Clear turns: /xoaluot name'],
        ['command' => 'blockuser', 'description' => 'Block a member: /blockuser name reason'],
        ['command' => 'unblockuser', 'description' => 'Unblock a member: /unblockuser name'],
        ['command' => 'blocked', 'description' => 'List blocked members'],
        ['command' => 'emoji', 'description' => 'Emoji pack gallery'],
        ['command' => 'emojiset', 'description' => 'Choose the emoji pack: /emojiset name'],
        ['command' => 'emojimap', 'description' => 'Change an icon: /emojimap ok 27'],
        ['command' => 'cancel', 'description' => 'Cancel the current step'],
        ['command' => 'id', 'description' => 'Show my Telegram ID'],
    ];
    $mc = tg_api('setMyCommands', ['commands' => $cmds]);
    $lines[] = (!empty($mc['ok']) ? '✅' : '❌') . ' Command menu';
    tg_api('setMyShortDescription', ['short_description' => tg_cfg('site_name', 'DEVNVIOS') . ' control panel']);
    tg_api('setMyDescription', ['description' => 'Control panel and alerts for ' . tg_cfg('site_name', 'DEVNVIOS') . '. Only the site admins can use it.']);

    $sent = 0;
    if (!empty($wh['ok'])) {
        foreach (tg_admin_ids() as $adminId) {
            $r = tg_api('sendMessage', [
                'chat_id' => $adminId, 'parse_mode' => 'HTML',
                'text' => "✅ <b>" . tg_e(tg_cfg('site_name', 'DEVNVIOS')) . " bot connected</b>\nAlerts are on. Send /menu to open the control center.\n\n<i>📨 Admin ID: <code>" . tg_e($adminId) . '</code></i>',
            ]);
            $sent += !empty($r['ok']) ? 1 : 0;
        }
        $lines[] = ($sent > 0 ? '✅' : '⚠️') . ' Welcome message sent to ' . $sent . ' admin(s)' . ($sent === 0 ? ' (open the bot in Telegram and press Start first)' : '');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Telegram setup</title>'
        . '<body style="font:16px/1.6 -apple-system,Segoe UI,sans-serif;max-width:640px;margin:24px auto;padding:0 16px"><h2>Telegram setup</h2><pre style="white-space:pre-wrap">'
        . htmlspecialchars(implode("\n", $lines), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

/* ------------------------------------------------------------------
 * Entry point
 * ------------------------------------------------------------------ */

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

// Telegram may deliver the same update twice: never run an action twice
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
    if (isset($update['callback_query']) && is_array($update['callback_query'])) {
        bot_on_callback($update['callback_query']);
    } elseif (isset($update['message']) && is_array($update['message'])) {
        bot_on_message($update['message']);
    }
} catch (Throwable $e) {
    error_log('[telegram_bot.php] ' . $e);
    tg_event('site_error', ['where' => 'telegram_bot.php', 'error' => $e->getMessage()]);
    try {
        if ($GLOBALS['CTX']['chat'] !== '') {
            tg_api('sendMessage', ['chat_id' => $GLOBALS['CTX']['chat'], 'parse_mode' => 'HTML',
                'text' => "⚠️ <b>Something went wrong</b>\n<code>" . tg_e(tg_clip($e->getMessage(), 300)) . '</code>',
                'reply_markup' => kb([home_row()])]);
        }
    } catch (Throwable $ignored) {
        // nothing more we can do
    }
}

http_response_code(200);
exit('ok');
