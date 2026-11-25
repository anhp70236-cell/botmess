<?php /* UPGRADE-V2 */
/* ==========================================================================
   telegram.php  —  Telegram alerts for DEVNVIOS (library)
   Loaded by index.php and telegram_bot.php with require_once.
   - Does NOTHING until telegram_config.php is filled in (the site keeps working exactly as before).
   - Never deletes or alters any data. Never throws: a Telegram problem can not break the website.
   - All function names start with tg_ so they can not clash with the rest of the site.
   - Written for PHP 7.2+ (the host runs 7.2.34).
   ========================================================================== */

if (!defined('TG_LIB_LOADED')) {
    define('TG_LIB_LOADED', 1);
}

/* ------------------------------------------------------------------
 * Config
 * ------------------------------------------------------------------ */

function tg_cfg($key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg  = [];
        $file = __DIR__ . '/telegram_config.php';
        if (is_file($file)) {
            $loaded = include $file;
            if (is_array($loaded)) {
                $cfg = $loaded;
            }
        }
        $cfg += [
            'bot_token' => '', 'admin_ids' => [], 'webhook_secret' => '', 'setup_key' => '', 'webhook_url' => '',
            'site_name' => 'DEVNVIOS', 'allow_destructive' => false, 'timezone' => 'Asia/Ho_Chi_Minh',
            'scan_interval' => 10, 'api_base' => 'https://api.telegram.org',
            'tag_user_id' => '', 'tag_name' => '', 'contact_url' => 'https://t.me/iosnea',
            'emoji_enabled' => true, 'emoji_pack' => '', 'emoji_ids' => [], 'emoji_map' => [], 'button_colors' => true,
        ];
    }
    if ($key === null) {
        return $cfg;
    }
    return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
}

/** Admin chat IDs as strings (digits, may start with - for groups). */
function tg_admin_ids()
{
    $ids = [];
    foreach ((array) tg_cfg('admin_ids', []) as $id) {
        $id = trim((string) $id);
        if (preg_match('/^-?\d{5,}$/', $id)) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

function tg_enabled()
{
    return preg_match('/^\d{6,}:[A-Za-z0-9_-]{20,}$/', (string) tg_cfg('bot_token', '')) === 1
        && count(tg_admin_ids()) > 0;
}

/* ------------------------------------------------------------------
 * Small helpers
 * ------------------------------------------------------------------ */

function tg_e($s)
{
    return htmlspecialchars((string) $s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tg_c($s)
{
    return '<code>' . tg_e($s) . '</code>';
}

function tg_len($s)
{
    return function_exists('mb_strlen') ? mb_strlen((string) $s, 'UTF-8') : strlen((string) $s);
}

function tg_sub($s, $start, $len)
{
    return function_exists('mb_substr') ? mb_substr((string) $s, $start, $len, 'UTF-8') : substr((string) $s, $start, $len);
}

function tg_clip($s, $max)
{
    $s = (string) $s;
    return tg_len($s) <= $max ? $s : tg_sub($s, 0, max(1, $max - 1)) . '…';
}

function tg_now($format = 'Y-m-d H:i:s')
{
    try {
        $d = new DateTime('now', new DateTimeZone((string) tg_cfg('timezone', 'UTC')));
        return $d->format($format);
    } catch (Exception $e) {
        return date($format);
    }
}

function tg_hours($h)
{
    $h = (int) $h;
    if ($h >= 48 && $h % 24 === 0) {
        return ($h / 24) . ' days';
    }
    return $h . ' h';
}

function tg_db()
{
    $c = isset($GLOBALS['conn']) ? $GLOBALS['conn'] : null;
    return ($c instanceof mysqli) ? $c : null;
}

function tg_ip()
{
    if (function_exists('client_ip')) {
        return (string) client_ip();
    }
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $h) { // behind Cloudflare / a proxy REMOTE_ADDR is the proxy, not the visitor
        if (!empty($_SERVER[$h]) && filter_var(trim((string) $_SERVER[$h]), FILTER_VALIDATE_IP)) {
            return substr(trim((string) $_SERVER[$h]), 0, 45);
        }
    }
    return substr((string) (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''), 0, 45);
}

/* ------------------------------------------------------------------
 * Tiny JSON storage (settings, throttles, scan positions). Files live in ./cache next to index.php
 * (falls back to the temp dir). File names carry a hash so they can not be guessed.
 * ------------------------------------------------------------------ */

function tg_dir()
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $local = __DIR__ . '/cache';
    if ((is_dir($local) || @mkdir($local, 0755, true)) && is_writable($local)) {
        return $dir = $local;
    }
    return $dir = rtrim(sys_get_temp_dir(), '/\\');
}

function tg_path($name)
{
    return tg_dir() . '/tg_' . preg_replace('/[^a-z0-9_]/i', '', $name) . '_'
        . substr(md5((string) tg_cfg('setup_key', '') . (string) tg_cfg('bot_token', '')), 0, 10) . '.json';
}

function tg_store_read($name)
{
    $raw  = @file_get_contents(tg_path($name));
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

/** Read-modify-write under an exclusive lock. $fn receives the array and returns the new array. */
function tg_store_update($name, $fn)
{
    $fh = @fopen(tg_path($name), 'c+');
    if ($fh === false) {
        $r = $fn([]);
        return is_array($r) ? $r : [];
    }
    flock($fh, LOCK_EX);
    $raw  = stream_get_contents($fh);
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        $data = [];
    }
    $new = $fn($data);
    if (!is_array($new)) {
        $new = $data;
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($new, JSON_UNESCAPED_UNICODE));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $new;
}

/** true = allowed (and remembered); false = same signature was seen less than $seconds ago. */
function tg_throttle($sig, $seconds)
{
    $allowed = false;
    tg_store_update('throttle', function ($s) use ($sig, $seconds, &$allowed) {
        $now = time();
        if (count($s) > 400) {
            foreach ($s as $k => $t) {
                if ($t < $now - 3600) {
                    unset($s[$k]);
                }
            }
        }
        if (isset($s[$sig]) && $s[$sig] > $now - $seconds) {
            return $s;
        }
        $s[$sig] = $now;
        $allowed = true;
        return $s;
    });
    return $allowed;
}

/* ------------------------------------------------------------------
 * Alert categories (the bot has a screen to switch each one on/off)
 * ------------------------------------------------------------------ */

function tg_categories()
{
    return [
        'register' => ['🆕 Registrations', true],
        'key'      => ['🔑 Keys', true],
        'freekey'  => ['🔗 Free key clicks', true],
        'tool'     => ['🧰 Tools', true],
        'admin'    => ['🛡 Admin panel', true],
        'security' => ['🔐 Security', true],
        'login'    => ['🔑 Failed logins', false],
        'error'    => ['🚨 Site errors', true],
        'audit'    => ['🤖 Bot actions', true],
        'minor'    => ['🔕 Minor events', true],
        'ban'      => ['🚫 IP bans', true],
        'visit'    => ['🚪 Page visits', true],
        'activity' => ['👁 Other actions', true],
    ];
}

function tg_cat_on($cat)
{
    $cats = tg_categories();
    if (!isset($cats[$cat])) {
        return true;
    }
    $s = tg_store_read('settings');
    if (isset($s['cat'][$cat])) {
        return (bool) $s['cat'][$cat];
    }
    return (bool) $cats[$cat][1];
}

/* ------------------------------------------------------------------
 * Tag the owner in every alert:  <a href="tg://user?id=...">@iosnea</a>  (a real mention = push notification)
 * ------------------------------------------------------------------ */

function tg_tag()
{
    $id = trim((string) tg_cfg('tag_user_id', ''));
    if (!preg_match('/^\d{5,}$/', $id)) {
        $ids = tg_admin_ids();
        $id  = $ids ? (string) $ids[0] : '';
    }
    $name = ltrim(trim((string) tg_cfg('tag_name', '')), '@');
    if ($name === '') {
        $name = 'iosnea';
    }
    if ($id === '' || $id[0] === '-') {
        return '@' . tg_e($name);
    }
    return '<a href="tg://user?id=' . $id . '">@' . tg_e($name) . '</a>';
}

/* ------------------------------------------------------------------
 * Activity log (table tg_activity): EVERY member / admin / bot action except logins.
 * Insert only: nothing here ever deletes anything.
 * ------------------------------------------------------------------ */

function tg_ensure_tables()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $c = tg_db();
    if ($c === null) {
        return;
    }
    try {
        mysqli_query($c, "CREATE TABLE IF NOT EXISTS `tg_activity` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `actor` VARCHAR(100) NOT NULL DEFAULT '',
            `role` VARCHAR(12) NOT NULL DEFAULT 'member',
            `action` VARCHAR(40) NOT NULL DEFAULT '',
            `detail` VARCHAR(500) NOT NULL DEFAULT '',
            `ip` VARCHAR(45) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_actor` (`actor`),
            KEY `idx_ip` (`ip`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        tg_ensure_block_table($c);
        if (function_exists('ipg_ensure')) {
            ipg_ensure($c);
        }
    } catch (Throwable $e) {
        error_log('[telegram.php] ensure tables: ' . $e->getMessage());
    }
}

/** Write one activity row. Never throws. $role: member | admin | bot */
function tg_activity($actor, $role, $action, $detail = '', $ip = null)
{
    $c = tg_db();
    if ($c === null || (string) $actor === '') {
        return;
    }
    if ($ip === null) {
        $ip = tg_ip();
    }
    for ($try = 0; $try < 2; $try++) {
        try {
            $stmt = $c->prepare('INSERT INTO tg_activity (actor, role, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            if ($stmt === false) {
                throw new RuntimeException($c->error);
            }
            $a  = tg_clip($actor, 100);
            $r  = tg_clip($role, 12);
            $ac = tg_clip($action, 40);
            $d  = tg_clip($detail, 490);
            $i  = substr((string) $ip, 0, 45);
            $stmt->bind_param('sssss', $a, $r, $ac, $d, $i);
            $stmt->execute();
            $stmt->close();
            return;
        } catch (Throwable $e) {
            if ($try === 0 && stripos($e->getMessage(), "doesn't exist") !== false) {
                tg_ensure_tables_force();
                continue;
            }
            error_log('[telegram.php] activity: ' . $e->getMessage());
            return;
        }
    }
}

function tg_ensure_tables_force()
{
    $c = tg_db();
    if ($c === null) {
        return;
    }
    try {
        mysqli_query($c, "CREATE TABLE IF NOT EXISTS `tg_activity` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `actor` VARCHAR(100) NOT NULL DEFAULT '',
            `role` VARCHAR(12) NOT NULL DEFAULT 'member',
            `action` VARCHAR(40) NOT NULL DEFAULT '',
            `detail` VARCHAR(500) NOT NULL DEFAULT '',
            `ip` VARCHAR(45) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_actor` (`actor`),
            KEY `idx_ip` (`ip`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        tg_ensure_block_table($c);
    } catch (Throwable $e) {
        error_log('[telegram.php] ensure tables: ' . $e->getMessage());
    }
}

/** Which events are saved in tg_activity, and how: [actor, role, action, detail] or null. Logins are never saved. */
function tg_activity_map($type, array $d)
{
    $user = isset($d['username']) ? (string) $d['username'] : '';
    switch ($type) {
        case 'register':
            return [$user, 'member', 'register', 'new account' . (isset($d['id']) && $d['id'] ? ' #' . (int) $d['id'] : '')];
        case 'key_ok':
            return [$user, 'member', 'key_ok', (isset($d['type']) ? $d['type'] : '?') . ' key ' . (isset($d['key']) ? $d['key'] : '') . ', ' . (int) (isset($d['turns']) ? $d['turns'] : 0) . ' turn(s)'];
        case 'key_invalid':
            return [$user, 'member', 'key_wrong', (isset($d['type']) ? $d['type'] : '?') . ' typed: ' . (isset($d['key']) ? $d['key'] : '')];
        case 'key_has_turn':
            return [$user, 'member', 'key_rejected', 'still has an active key'];
        case 'key_error':
            return [$user, 'member', 'key_error', 'transaction error: ' . (isset($d['key']) ? $d['key'] : '')];
        case 'key_locked':
            return [$user, 'member', 'key_locked', 'too many wrong keys'];
        case 'freekey_click':
            return [$user, 'member', 'freekey_click', 'opened the free key link'];
        case 'tool_use':
            return [$user, 'member', 'tool_' . (isset($d['outcome']) ? $d['outcome'] : '?'),
                (isset($d['tool']) ? $d['tool'] : '?') . (isset($d['tool_type']) && $d['tool_type'] !== '' ? ' (' . $d['tool_type'] . ')' : '')
                . (!empty($d['reopen']) ? ' reopen' : '') . (isset($d['turns']) ? ' turns left ' . (int) $d['turns'] : '')];
        case 'page_view':
            return [$user, isset($d['role']) ? (string) $d['role'] : 'member', 'page_view', 'opened the home page' . (!empty($d['status']) ? ' (?status=' . $d['status'] . ')' : '')];
        case 'member_action':
            return [$user, isset($d['role']) ? (string) $d['role'] : 'member', isset($d['action']) ? (string) $d['action'] : 'action', isset($d['detail']) ? (string) $d['detail'] : ''];
        case 'admin_log':
            return [$user !== '' ? $user : 'admin_cp', 'admin_cp', 'cp:' . (isset($d['action']) ? (string) $d['action'] : ''), isset($d['detail']) ? (string) $d['detail'] : ''];
        case 'ip_blocked_hit':
            return [$user !== '' ? $user : 'ip:' . (isset($d['ip']) ? $d['ip'] : ''), 'blocked', 'blocked_visit', 'banned IP tried to enter, hit #' . (int) (isset($d['hits']) ? $d['hits'] : 1)];
        case 'ip_ban':
            return ['tg:' . (isset($d['by']) ? $d['by'] : ''), 'bot', 'ip_ban', (isset($d['ip']) ? $d['ip'] : '') . (isset($d['reason']) ? ' · ' . $d['reason'] : '')];
        case 'ip_unban':
            return ['tg:' . (isset($d['by']) ? $d['by'] : ''), 'bot', 'ip_unban', isset($d['ip']) ? (string) $d['ip'] : ''];
        case 'user_block':
            return [$user, 'bot', 'user_block', (isset($d['reason']) ? $d['reason'] : '') . ' · by tg:' . (isset($d['by']) ? $d['by'] : '')];
        case 'user_unblock':
            return [$user, 'bot', 'user_unblock', 'by tg:' . (isset($d['by']) ? $d['by'] : '')];
    }
    return null;
}

/* ------------------------------------------------------------------
 * TELEGRAM CUSTOM EMOJI + coloured buttons
 * - Every normal emoji in a message / button is swapped for the matching emoji of your pack at send time.
 * - If the bot owner has no Premium (or Telegram refuses), it silently falls back to normal emoji.
 * ------------------------------------------------------------------ */

/** Position (1 = first) of each icon inside the "Reseller < Emoji >" pack. Check with /emoji, change with /emojimap. */
function tg_emoji_default_map()
{
    return [
        'apple' => 1, 'apple2' => 2, 'ceo' => 3, 'new' => 4, 'user' => 5, 'bolt' => 6, 'medal' => 7, 'award' => 8,
        'badge' => 9, 'star2' => 10, 'star' => 11, 'money' => 12, 'vip' => 13, 'load' => 14, 'flower' => 15, 'support' => 16,
        'tg' => 17, 'crown' => 18, 'pubg' => 19, 'verified' => 20, 'check' => 21, 'ok2' => 22, 'redcheck' => 23, 'done' => 24,
        'vplus' => 25, 'spin' => 26, 'ok' => 27, 'bars' => 28, 'live' => 29, 'rec' => 30, 'no' => 31, 'stop' => 32,
        'dots' => 33, 'on' => 34, 'arrow' => 35, 'next' => 36, 'go' => 37, 'fast' => 38, 'sale' => 39, 'buy' => 40,
    ];
}

/** Normal emoji (without the invisible U+FE0F) => icon name. */
function tg_emoji_unicode_map()
{
    return [
        '✅' => 'ok', '☑' => 'ok', '✔' => 'verified', '🛡' => 'verified',
        '❌' => 'no', '🚫' => 'no', '⛔' => 'stop',
        '🟢' => 'live', '🔴' => 'rec',
        '💎' => 'vip', '👑' => 'crown', '⭐' => 'star', '🌟' => 'star2', '🆕' => 'new', '⚡' => 'bolt',
        '👤' => 'user', '👥' => 'user', '💰' => 'money', '💸' => 'money',
        '🕒' => 'support', '🕘' => 'support', '➡' => 'arrow', '🔄' => 'spin',
        '📨' => 'tg', '🏷' => 'sale', '🛒' => 'buy', '📈' => 'bars',
    ];
}

function tg_emoji_reset()
{
    unset($GLOBALS['TG_EMOJI_TABLE'], $GLOBALS['TG_EMOJI_CACHE'], $GLOBALS['TG_EMOJI_CTX']);
}

function tg_emoji_on()
{
    if (!tg_cfg('emoji_enabled', true)) {
        return false;
    }
    $s = tg_store_read('settings');
    if (!empty($s['emoji_off'])) {
        return false;
    }
    $d = tg_store_read('emojidown');
    return empty($d['until']) || (int) $d['until'] <= time();
}

function tg_style_on()
{
    if (!tg_cfg('button_colors', true)) {
        return false;
    }
    $d = tg_store_read('styledown');
    return empty($d['until']) || (int) $d['until'] <= time();
}

function tg_emoji_pack_name()
{
    $s = tg_store_read('settings');
    $n = isset($s['emoji_pack']) ? (string) $s['emoji_pack'] : '';
    if ($n === '') {
        $n = (string) tg_cfg('emoji_pack', '');
    }
    $n = trim($n);
    return preg_match('/^[A-Za-z0-9_]{1,64}$/', $n) ? $n : '';
}

/** The pack: ['name' => ..., 'list' => [['id' => custom_emoji_id, 'e' => fallback emoji], ...]]  (cached 24 h) */
function tg_emoji_pack($force = false)
{
    static $memo = null;
    if ($memo !== null && !$force) {
        return $memo;
    }
    $name = tg_emoji_pack_name();
    $memo = ['name' => $name, 'list' => []];
    if ($name === '') {
        return $memo;
    }
    $c   = tg_store_read('emojipack');
    $ttl = !empty($c['fail']) ? 300 : 86400;
    if (!$force && isset($c['name'], $c['at'], $c['list']) && $c['name'] === $name && (int) $c['at'] > time() - $ttl && is_array($c['list'])) {
        $memo['list'] = $c['list'];
        return $memo;
    }
    $res  = tg_api_raw('getStickerSet', ['name' => $name], 5);
    $list = [];
    $fail = true;
    if (!empty($res['ok']) && isset($res['result']['stickers']) && is_array($res['result']['stickers'])) {
        $fail = false;
        foreach ($res['result']['stickers'] as $st) {
            if (!empty($st['custom_emoji_id'])) {
                $list[] = ['id' => (string) $st['custom_emoji_id'], 'e' => isset($st['emoji']) && $st['emoji'] !== '' ? (string) $st['emoji'] : '⭐'];
            }
        }
    }
    tg_store_update('emojipack', function ($x) use ($name, $list, $fail) {
        return ['name' => $name, 'at' => time(), 'list' => $list, 'fail' => $fail];
    });
    $memo['list'] = $list;
    return $memo;
}

/** Effective icon map: defaults < config < chat (/emojimap). */
function tg_emoji_map()
{
    $s    = tg_store_read('settings');
    $chat = isset($s['emoji_map']) && is_array($s['emoji_map']) ? $s['emoji_map'] : [];
    return array_merge(tg_emoji_default_map(), (array) tg_cfg('emoji_map', []), $chat);
}

/** custom_emoji_id of an icon name, or '' when it is not available. */
function tg_emoji_id($key)
{
    if (isset($GLOBALS['TG_EMOJI_CACHE'][$key])) {
        return $GLOBALS['TG_EMOJI_CACHE'][$key];
    }
    $id = '';
    if (!isset($GLOBALS['TG_EMOJI_CTX'])) { // read the switches / map once per request, not once per icon
        $on = tg_emoji_on();
        $GLOBALS['TG_EMOJI_CTX'] = ['on' => $on, 'map' => $on ? tg_emoji_map() : []];
    }
    if ($GLOBALS['TG_EMOJI_CTX']['on']) {
        $ids = (array) tg_cfg('emoji_ids', []);
        if (isset($ids[$key]) && preg_match('/^\d{5,}$/', (string) $ids[$key])) {
            $id = (string) $ids[$key];
        } else {
            $map = $GLOBALS['TG_EMOJI_CTX']['map'];
            if (isset($map[$key])) {
                $pack = tg_emoji_pack();
                $i    = (int) $map[$key] - 1;
                if ($i >= 0 && isset($pack['list'][$i])) {
                    $id = (string) $pack['list'][$i]['id'];
                }
            }
        }
    }
    $GLOBALS['TG_EMOJI_CACHE'][$key] = $id;
    return $id;
}

/** Normal emoji => custom_emoji_id, only for the icons that are really available. */
function tg_emoji_norm($s)
{
    return str_replace("\u{FE0F}", '', (string) $s);
}

/** The icons the bot uses. Each one can get its own Premium emoji from the bot (🎨 Emoji Premium). */
function tg_emoji_slots()
{
    static $slots = null;
    if ($slots === null) {
        $raw = [
            ['🏠', 'Menu'], ['🔄', 'Refresh'], ['⬅', 'Back'], ['✖', 'Cancel'], ['✅', 'OK'], ['❌', 'Error'],
            ['🟢', 'On'], ['🔴', 'Off'], ['🟡', 'Pending'], ['⚠', 'Warning'], ['⛔', 'Block'], ['🚫', 'Ban'],
            ['🛡', 'Shield'], ['🔐', 'Security'], ['🔒', 'Lock'], ['🔓', 'Unlock'], ['👤', 'User'], ['👥', 'Users'],
            ['👑', 'Admin'], ['💎', 'VIP'], ['🔑', 'Key'], ['🎁', 'Gift'], ['🎟', 'Turns'], ['⏳', 'Time left'],
            ['📦', 'Tools'], ['🧰', 'Toolbox'], ['🛠', 'Maint.'], ['⚙', 'Config'], ['🗄', 'Database'], ['📜', 'Logs'],
            ['🧮', 'SQL'], ['🩺', 'Health'], ['🔔', 'Alerts'], ['🎨', 'Emoji'], ['🔍', 'Search'], ['➕', 'Add'],
            ['👁', 'Activity'], ['🆕', 'New'], ['📈', 'Stats'], ['🕒', 'Clock'], ['🌐', 'IP'], ['🔗', 'Link'],
            ['📝', 'Note'], ['📄', 'File'], ['📍', 'Where'], ['📱', 'Device'], ['👮', 'Admin by'], ['🚨', 'Alarm'],
        ];
        $slots = [];
        foreach ($raw as $r) {
            $slots[] = [tg_emoji_norm($r[0]), $r[1]];
        }
    }
    return $slots;
}

/** Normal emoji => custom_emoji_id. Layers: old pack-by-number map < your picks (🎨 Emoji Premium). */
function tg_emoji_table()
{
    if (!isset($GLOBALS['TG_EMOJI_TABLE'])) {
        $t = [];
        if (tg_emoji_on()) {
            $s = tg_store_read('settings');
            if (empty($s['emoji_legacy_off'])) {
                foreach (tg_emoji_unicode_map() as $u => $key) {
                    $id = tg_emoji_id($key);
                    if ($id !== '') {
                        $t[$u] = $id;
                    }
                }
            }
            if (!empty($s['emoji_pick']) && is_array($s['emoji_pick'])) {
                foreach ($s['emoji_pick'] as $u => $id) {
                    if (preg_match('/^\d{5,}$/', (string) $id)) {
                        $t[tg_emoji_norm($u)] = (string) $id;
                    }
                }
            }
        }
        $GLOBALS['TG_EMOJI_TABLE'] = $t;
    }
    return $GLOBALS['TG_EMOJI_TABLE'];
}

function tg_emoji_regex(array $table, $lead)
{
    $alt = implode('|', array_map(function ($u) {
        return preg_quote((string) $u, '~');
    }, array_keys($table)));
    return $lead ? '~^(' . $alt . ')\x{FE0F}?\s*~u' : '~(' . $alt . ')\x{FE0F}?~u';
}

/** <tg-emoji emoji-id="ID">fallback</tg-emoji> for an icon name (or just the fallback when not available). */
function tg_ce($key, $fallback)
{
    $id = tg_emoji_id($key);
    return $id === '' ? $fallback : '<tg-emoji emoji-id="' . $id . '">' . $fallback . '</tg-emoji>';
}

/** HTML text: swap normal emoji for custom emoji (never inside tags, <code> or <pre>). */
function tg_dress_text($html)
{
    $table = tg_emoji_table();
    if (!$table) {
        return $html;
    }
    $re    = tg_emoji_regex($table, false);
    $parts = preg_split('~(<tg-emoji\b.*?</tg-emoji>|<[^>]+>)~s', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return $html;
    }
    $code = 0;
    $left = 90; // Telegram allows 100 entities per message
    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) {
            if (preg_match('~^<(code|pre)\b~i', $part)) {
                $code++;
            } elseif (preg_match('~^</(code|pre)>~i', $part)) {
                $code = max(0, $code - 1);
            }
            continue;
        }
        if ($code > 0 || $part === '' || $left <= 0) {
            continue;
        }
        $parts[$i] = preg_replace_callback($re, function ($m) use ($table, &$left) {
            if ($left <= 0) {
                return $m[0];
            }
            $left--;
            return '<tg-emoji emoji-id="' . $table[$m[1]] . '">' . $m[0] . '</tg-emoji>';
        }, $part);
    }
    return implode('', $parts);
}

/** Buttons: a leading normal emoji becomes the button icon (icon_custom_emoji_id). */
function tg_dress_markup($markup)
{
    if (!is_array($markup) || empty($markup['inline_keyboard']) || !is_array($markup['inline_keyboard'])) {
        return $markup;
    }
    $table = tg_emoji_table();
    if (!$table) {
        return $markup;
    }
    $re = tg_emoji_regex($table, true);
    foreach ($markup['inline_keyboard'] as $r => $row) {
        foreach ((array) $row as $c => $b) {
            if (!is_array($b) || !isset($b['text']) || isset($b['icon_custom_emoji_id'])) {
                continue;
            }
            if (preg_match($re, (string) $b['text'], $m)) {
                $rest = preg_replace($re, '', (string) $b['text'], 1);
                if ($rest !== null && trim($rest) !== '') {
                    $b['text']                 = $rest;
                    $b['icon_custom_emoji_id'] = $table[$m[1]];
                    $markup['inline_keyboard'][$r][$c] = $b;
                }
            }
        }
    }
    return $markup;
}

function tg_strip_button_extras($markup)
{
    if (!is_array($markup) || empty($markup['inline_keyboard']) || !is_array($markup['inline_keyboard'])) {
        return $markup;
    }
    foreach ($markup['inline_keyboard'] as $r => $row) {
        foreach ((array) $row as $c => $b) {
            if (is_array($b)) {
                unset($b['icon_custom_emoji_id'], $b['style']);
                $markup['inline_keyboard'][$r][$c] = $b;
            }
        }
    }
    return $markup;
}

function tg_dress_params($method, array $params)
{
    if ($method !== 'sendMessage' && $method !== 'editMessageText') {
        return $params;
    }
    if (isset($params['text']) && isset($params['parse_mode']) && $params['parse_mode'] === 'HTML') {
        $params['text'] = tg_dress_text((string) $params['text']);
    }
    if (isset($params['reply_markup'])) {
        $params['reply_markup'] = tg_dress_markup($params['reply_markup']);
    }
    return $params;
}

/* ------------------------------------------------------------------
 * USER BLOCK (account level). Table tg_user_blocks. Nothing is ever deleted:
 * the old password hash / key state is saved so "unblock" gives the account back exactly as it was.
 * ------------------------------------------------------------------ */

function tg_block_sql()
{
    return "CREATE TABLE IF NOT EXISTS `tg_user_blocks` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT NOT NULL DEFAULT 0,
        `username` VARCHAR(100) NOT NULL DEFAULT '',
        `reason` VARCHAR(255) NOT NULL DEFAULT '',
        `active` TINYINT NOT NULL DEFAULT 1,
        `blocked_by` VARCHAR(40) NOT NULL DEFAULT '',
        `created_at` DATETIME NOT NULL,
        `unblocked_at` DATETIME NULL DEFAULT NULL,
        `unblocked_by` VARCHAR(40) NULL DEFAULT NULL,
        `old_password` VARCHAR(255) NULL DEFAULT NULL,
        `locked_password` VARCHAR(255) NULL DEFAULT NULL,
        `old_role` INT NULL DEFAULT NULL,
        `old_key_type` VARCHAR(20) NULL DEFAULT NULL,
        `old_expiry` DATETIME NULL DEFAULT NULL,
        `old_turns` INT NULL DEFAULT NULL,
        `ips` TEXT NULL,
        `hits` INT NOT NULL DEFAULT 0,
        `last_hit` DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_username` (`username`),
        KEY `idx_active` (`active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
}

function tg_ensure_block_table($c)
{
    try {
        mysqli_query($c, tg_block_sql());
    } catch (Throwable $e) {
        error_log('[telegram.php] ensure block table: ' . $e->getMessage());
    }
}

/** The active block row of a member, or null. Never throws. */
function tg_user_block_row($username)
{
    $c = tg_db();
    if ($c === null || (string) $username === '') {
        return null;
    }
    for ($try = 0; $try < 2; $try++) {
        try {
            $stmt = $c->prepare('SELECT * FROM tg_user_blocks WHERE username = ? AND active = 1 ORDER BY id DESC LIMIT 1');
            if ($stmt === false) {
                throw new RuntimeException($c->error);
            }
            $u = (string) $username;
            $stmt->bind_param('s', $u);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            return $row ? $row : null;
        } catch (Throwable $e) {
            if ($try === 0 && stripos($e->getMessage(), "doesn't exist") !== false) {
                tg_ensure_block_table($c);
                continue;
            }
            return null;
        }
    }
    return null;
}

function tg_user_blocked($username)
{
    return tg_user_block_row($username) !== null;
}

function tg_block_page(array $row)
{
    $site    = htmlspecialchars((string) tg_cfg('site_name', 'DEVNVIOS'), ENT_QUOTES, 'UTF-8');
    $reason  = htmlspecialchars((string) (isset($row['reason']) ? $row['reason'] : ''), ENT_QUOTES, 'UTF-8');
    $contact = htmlspecialchars((string) tg_cfg('contact_url', 'https://t.me/iosnea'), ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Tài khoản bị chặn · ' . $site . '</title><style>'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0b0b12;color:#eee;font:16px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;padding:20px}'
        . '.c{max-width:420px;width:100%;background:#15151f;border:1px solid #2a2a3a;border-radius:22px;padding:32px 26px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.5)}'
        . '.i{font-size:54px;line-height:1}h1{font-size:22px;margin:14px 0 6px}p{color:#aab;margin:6px 0}'
        . '.r{margin:16px 0;padding:12px 14px;background:#1d1d2b;border-radius:12px;color:#ff8a8a;font-size:15px}'
        . 'a{display:inline-block;margin-top:14px;padding:12px 26px;border-radius:999px;background:#2f7bff;color:#fff;text-decoration:none;font-weight:600}'
        . '</style></head><body><div class="c"><div class="i">⛔</div><h1>Tài khoản đã bị chặn</h1>'
        . '<p>Tài khoản của bạn không thể sử dụng ' . $site . '.</p>'
        . ($reason !== '' ? '<div class="r">' . $reason . '</div>' : '')
        . '<p>Nếu đây là nhầm lẫn, hãy liên hệ admin.</p><a href="' . $contact . '">Liên hệ admin</a></div></body></html>';
}

/**
 * If this member is blocked: log it, end the session and show the "account blocked" page. Admins are never touched.
 * Called automatically from tg_event() for every member action; you may also call it yourself near the top of index.php:
 *     if (function_exists('tg_block_enforce') && !empty($_SESSION['username'])) { tg_block_enforce($_SESSION['username']); }
 */
function tg_block_enforce($username, $role = 'member')
{
    $username = (string) $username;
    if ($username === '' || $role === 'admin' || PHP_SAPI === 'cli' || (isset($GLOBALS['TG_CTX']) && $GLOBALS['TG_CTX'] === 'bot')) {
        return;
    }
    $row = tg_user_block_row($username);
    if ($row === null) {
        return;
    }
    $c = tg_db();
    if ($c !== null) {
        try {
            $stmt = $c->prepare('UPDATE tg_user_blocks SET hits = hits + 1, last_hit = NOW() WHERE id = ?');
            if ($stmt !== false) {
                $bid = (int) $row['id'];
                $stmt->bind_param('i', $bid);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            // counting is optional
        }
    }
    if (tg_throttle('ubl:' . $username, 20)) {
        tg_activity($username, 'blocked', 'blocked_user', 'blocked account tried to use the site');
    }
    if (tg_cat_on('ban') && tg_throttle('ubh:' . $username, 600)) {
        tg_queue(['html' => tg_card('⛔ <b>BLOCKED USER TRIED TO ENTER</b>', [
            tg_r('👤', 'User', tg_c($username)),
            tg_r('🌐', 'IP', tg_c(tg_ip())),
            tg_r('📝', 'Reason', tg_e(tg_clip(isset($row['reason']) ? $row['reason'] : '', 120))),
            tg_r('🔁', 'Blocked visits', '<b>' . ((int) $row['hits'] + 1) . '</b>'),
        ]), 'except' => '']);
    }
    if (headers_sent()) {
        return;
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        @session_destroy();
    }
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo tg_block_page($row);
    exit;
}

/* ------------------------------------------------------------------
 * Telegram HTTP API
 * tg_api() = tg_api_raw() + custom emoji dressing + automatic fallback to plain emoji if Telegram refuses them.
 * ------------------------------------------------------------------ */

function tg_strip_ce($html)
{
    return preg_replace('~<tg-emoji\b[^>]*>(.*?)</tg-emoji>~s', '$1', (string) $html);
}

function tg_api($method, array $params = [], $timeout = 6)
{
    $dressed  = tg_dress_params($method, $params);
    $res      = tg_api_raw($method, $dressed, $timeout);
    $hasExtra = isset($params['reply_markup']) && is_array($params['reply_markup']) && strpos((string) json_encode($params['reply_markup']), '"style"') !== false;
    $hasTag   = isset($params['text']) && is_string($params['text']) && strpos($params['text'], '<tg-emoji') !== false;
    if (empty($res['ok']) && isset($res['error_code']) && ($dressed !== $params || $hasExtra || $hasTag)) {
        $d = (string) (isset($res['description']) ? $res['description'] : '');
        if (preg_match('/emoji|icon_|DOCUMENT_INVALID|BUTTON_TYPE|style|entit/i', $d)) {
            $plain = $params;
            if (isset($plain['reply_markup'])) {
                $plain['reply_markup'] = tg_strip_button_extras($plain['reply_markup']);
            }
            if (isset($plain['text']) && is_string($plain['text'])) {
                $plain['text'] = tg_strip_ce($plain['text']);
            }
            $res2 = tg_api_raw($method, $plain, $timeout);
            if (!empty($res2['ok'])) {
                $flag = preg_match('/style/i', $d) ? 'styledown' : 'emojidown';
                tg_store_update($flag, function ($s) {
                    return ['until' => time() + 21600]; // do not try again for 6 hours
                });
                return $res2;
            }
        }
    }
    return $res;
}

function tg_api_raw($method, array $params = [], $timeout = 6)
{
    $url  = rtrim((string) tg_cfg('api_base', 'https://api.telegram.org'), '/') . '/bot' . (string) tg_cfg('bot_token', '') . '/' . $method;
    $body = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $raw  = null;
    $err  = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
        }
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\n",
            'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            $err = 'file_get_contents failed';
        }
    } else {
        $err = 'host has no curl and allow_url_fopen is off';
    }

    $res = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($res)) {
        $res = ['ok' => false, 'description' => $err !== '' ? $err : 'bad response from Telegram'];
    }
    return $res;
}

/* ------------------------------------------------------------------
 * Message cards
 * ------------------------------------------------------------------ */

function tg_r($icon, $label, $valueHtml)
{
    return [$icon, $label, $valueHtml];
}

function tg_card($title, array $rows, $pre = '')
{
    $out  = $title . "\n";
    $body = '';
    foreach ($rows as $r) {
        if (is_array($r)) {
            $body .= $r[0] . ' ' . tg_e($r[1]) . ' · ' . $r[2] . "\n";
        }
    }
    if ($body !== '') {
        $out .= '<blockquote>' . rtrim($body) . '</blockquote>' . "\n";
    }
    if ($pre !== '') {
        $out .= '<pre>' . tg_e(tg_clip($pre, 1200)) . '</pre>' . "\n";
    }
    return $out . '<i>🕒 ' . tg_e(tg_now('d/m H:i:s')) . ' · ' . tg_e(tg_cfg('site_name', 'DEVNVIOS')) . '</i>';
}

function tg_page()
{
    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'CLI';
    $uri    = (string) strtok((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''), '?');
    return $method . ' ' . $uri;
}

/** Turn an event into a message. Returns null (ignore) or ['cat','title','rows','pre','throttle']. */
function tg_render($type, array $d)
{
    $user = isset($d['username']) ? (string) $d['username'] : '';
    $ip   = array_key_exists('ip', $d) ? (string) $d['ip'] : tg_ip();
    $ipRow = $ip !== '' ? tg_r('🌐', 'IP', tg_c($ip)) : null;
    $userRow = $user !== '' ? tg_r('👤', 'User', tg_c($user)) : null;

    switch ($type) {
        case 'register':
            $id = isset($d['id']) ? (int) $d['id'] : 0;
            $c  = tg_db();
            if (empty($d['claimed'])) {
                if ($c !== null && $id === 0 && $user !== '') {
                    $res = mysqli_query($c, "SELECT id FROM users WHERE username = '" . mysqli_real_escape_string($c, $user) . "' LIMIT 1");
                    $row = $res ? mysqli_fetch_assoc($res) : null;
                    $id  = $row ? (int) $row['id'] : 0;
                }
                if ($id > 0 && !tg_claim_registration($id, false)) {
                    return null; // already announced
                }
            }
            $total = null;
            if ($c !== null) {
                $res   = mysqli_query($c, 'SELECT COUNT(*) AS n FROM users');
                $row   = $res ? mysqli_fetch_assoc($res) : null;
                $total = $row ? (int) $row['n'] : null;
            }
            return ['cat' => 'register', 'title' => '🆕 <b>NEW REGISTRATION</b>', 'rows' => [
                $userRow,
                $id > 0 ? tg_r('🆔', 'User ID', tg_c('#' . $id)) : null,
                $ipRow,
                $total !== null ? tg_r('👥', 'Total users', '<b>' . $total . '</b>') : null,
            ]];

        case 'key_ok':
            $hours = (int) (isset($d['hours']) ? $d['hours'] : 0);
            return ['cat' => 'key', 'title' => '🟢 <b>KEY ACTIVATED</b>', 'rows' => [
                $userRow,
                tg_r('🏷', 'Plan', '<b>' . tg_e(isset($d['type']) ? $d['type'] : '?') . '</b>'),
                $hours > 0 ? tg_r('⏳', 'Valid for', tg_c(tg_hours($hours))) : null,
                tg_r('🎟', 'Turns', tg_c((int) (isset($d['turns']) ? $d['turns'] : 0))),
                tg_r('🔑', 'Key', tg_c(isset($d['key']) ? $d['key'] : '')),
                $ipRow,
            ]];

        case 'key_invalid':
            $typed = isset($d['key']) ? (string) $d['key'] : '';
            return ['cat' => 'key', 'title' => '🔴 <b>WRONG KEY</b>', 'throttle' => ['ki:' . $user, 20], 'rows' => [
                $userRow,
                tg_r('🏷', 'Box', '<b>' . tg_e(isset($d['type']) ? $d['type'] : '?') . '</b>'),
                tg_r('⌨️', 'Typed', $typed !== '' ? tg_c(tg_clip($typed, 60)) : '<i>(empty / invalid format)</i>'),
                $ipRow,
            ]];

        case 'key_has_turn':
            return ['cat' => 'minor', 'title' => '🟡 <b>KEY REJECTED</b>', 'rows' => [
                $userRow, tg_r('ℹ️', 'Reason', 'still has an active key / turns'), $ipRow,
            ]];

        case 'key_error':
            return ['cat' => 'error', 'title' => '🚨 <b>KEY ACTIVATION FAILED</b>', 'throttle' => ['ke', 120], 'rows' => [
                $userRow,
                tg_r('⚙️', 'Problem', 'database transaction error (see server error_log)'),
                tg_r('🔑', 'Key', tg_c(isset($d['key']) ? $d['key'] : '')),
                $ipRow,
            ]];

        case 'key_locked':
            return ['cat' => 'security', 'title' => '⛔ <b>KEY LOCKOUT</b>', 'throttle' => ['kl:' . $user, 600], 'rows' => [
                $userRow, tg_r('🛑', 'Reason', 'too many wrong keys, key entry is locked'), $ipRow,
            ]];

        case 'freekey_click':
            return ['cat' => 'freekey', 'title' => '🔗 <b>GET FREE KEY CLICKED</b>', 'throttle' => ['fk:' . $user, 60], 'rows' => [
                $userRow, tg_r('➡️', 'Action', 'opened the free key link'), $ipRow,
            ]];

        case 'tool_use':
            $out   = isset($d['outcome']) ? (string) $d['outcome'] : '';
            $tool  = isset($d['tool']) ? (string) $d['tool'] : '?';
            $ttype = isset($d['tool_type']) && $d['tool_type'] !== '' ? ' (' . $d['tool_type'] . ')' : '';
            $toolRow = tg_r('📦', 'Tool', '<b>' . tg_e($tool) . '</b>' . tg_e($ttype));
            if ($out === 'open') {
                if (empty($d['spent'])) {
                    return null; // Admin exempt: nothing was spent
                }
                $left = (int) (isset($d['turns']) ? $d['turns'] : 0);
                if (!empty($d['reopen'])) {
                    return ['cat' => 'minor', 'title' => '🔁 <b>TOOL REOPENED</b> <i>(free)</i>', 'rows' => [$userRow, $toolRow, $ipRow]];
                }
                return ['cat' => 'tool', 'title' => '🧰 <b>TOOL OPENED</b>', 'rows' => [
                    $userRow, $toolRow,
                    tg_r('🏷', 'Plan', tg_c(isset($d['plan']) && $d['plan'] !== '' ? $d['plan'] : 'NONE')),
                    tg_r('🎟', 'Turns left', $left > 0 ? tg_c($left) : '<b>0</b> (key finished)'),
                    $ipRow,
                ]];
            }
            if ($out === 'warn') {
                return ['cat' => 'tool', 'title' => '⚠️ <b>VIP KEY ON FREE TOOL</b>', 'rows' => [
                    $userRow, $toolRow, tg_r('📣', 'Warning', tg_c((int) (isset($d['count']) ? $d['count'] : 0) . ' / 3')), $ipRow,
                ]];
            }
            if ($out === 'penalty') {
                return ['cat' => 'tool', 'title' => '💸 <b>PENALTY APPLIED</b>', 'rows' => [
                    $userRow, $toolRow, tg_r('➖', 'Charged', '1 VIP turn (too many Free clicks)'), $ipRow,
                ]];
            }
            if ($out === 'need_key' || $out === 'need_vip') {
                return ['cat' => 'minor', 'title' => '🚫 <b>TOOL DENIED</b>', 'rows' => [
                    $userRow, $toolRow, tg_r('ℹ️', 'Reason', $out === 'need_vip' ? 'VIP tool, user has no VIP key' : 'no turns left'), $ipRow,
                ]];
            }
            if ($out === 'csrf_fail') {
                return ['cat' => 'security', 'title' => '🛡 <b>BLOCKED REQUEST (CSRF)</b>', 'throttle' => ['csrf:' . $user, 300], 'rows' => [
                    $userRow, tg_r('ℹ️', 'Reason', 'tool request without a valid session token'), $ipRow,
                ]];
            }
            if ($out === 'missing') {
                return ['cat' => 'minor', 'title' => '❓ <b>TOOL NOT FOUND</b>', 'rows' => [$userRow, $toolRow, $ipRow]];
            }
            return null;

        case 'site_error':
        case 'site_fatal':
        case 'php_warning':
            $where = isset($d['where']) ? (string) $d['where'] : '';
            $text  = isset($d['error']) ? (string) $d['error'] : (isset($d['message']) ? (string) $d['message'] : '');
            $file  = isset($d['file']) ? str_replace(__DIR__ . DIRECTORY_SEPARATOR, '', (string) $d['file']) : '';
            $sig   = $type . '|' . substr($text, 0, 160) . '|' . $file . '|' . (isset($d['line']) ? $d['line'] : '');
            $titles = [
                'site_error'  => '🚨 <b>SITE ERROR</b>',
                'site_fatal'  => '💥 <b>PHP FATAL ERROR</b>',
                'php_warning' => '⚠️ <b>PHP WARNING</b>',
            ];
            $sess = isset($_SESSION['username']) ? (string) $_SESSION['username'] : '';
            return ['cat' => 'error', 'title' => $titles[$type], 'throttle' => ['err:' . md5($sig), $type === 'php_warning' ? 900 : 300], 'pre' => $text, 'rows' => [
                $where !== '' ? tg_r('📍', 'Where', tg_e($where)) : null,
                $file !== '' ? tg_r('📄', 'File', tg_c($file . (isset($d['line']) ? ':' . $d['line'] : ''))) : null,
                tg_r('🔗', 'Request', tg_c(tg_page())),
                $sess !== '' ? tg_r('👤', 'User', tg_c($sess)) : null,
                $ipRow,
            ]];

        case 'db_down':
            return ['cat' => 'error', 'title' => '🔌 <b>DATABASE UNREACHABLE</b>', 'throttle' => ['dbdown', 300], 'rows' => [
                tg_r('⚠️', 'Impact', 'the website can not load data right now'),
                tg_r('🔗', 'Request', tg_c(tg_page())),
                $ipRow,
            ]];

        case 'admin_log':
            $icons = ['toggle_maintenance' => '🛠', 'maintenance_access_login' => '🔓', 'regen_maintenance_access' => '🔁',
                      'gen_keys' => '🔑', 'kill_key' => '⛔', 'add_product' => '📦', 'save_site' => '🎨', 'save_advanced' => '⚙️'];
            $action = isset($d['action']) ? (string) $d['action'] : '';
            $icon   = isset($icons[$action]) ? $icons[$action] : '🛡';
            return ['cat' => 'admin', 'title' => $icon . ' <b>ADMIN PANEL</b> · ' . tg_e($action), 'rows' => [
                tg_r('👤', 'Admin', tg_c($user)),
                tg_r('📝', 'Detail', tg_e(tg_clip(isset($d['detail']) ? $d['detail'] : '', 300))),
                isset($d['ip']) && $d['ip'] !== '' ? tg_r('🌐', 'IP', tg_c($d['ip'])) : null,
                isset($d['created_at']) ? tg_r('🕒', 'Logged at', tg_c($d['created_at'])) : null,
            ]];

        case 'security_log':
            return ['cat' => 'security', 'title' => '🔐 <b>SECURITY</b> · ' . tg_e(isset($d['violation_type']) ? $d['violation_type'] : '?'), 'rows' => [
                tg_r('🌐', 'IP', tg_c(isset($d['ip_address']) ? $d['ip_address'] : '')),
                tg_r('🔁', 'Hits', '<b>' . (int) (isset($d['count']) ? $d['count'] : 1) . '</b>'),
                !empty($d['user_agent']) ? tg_r('📱', 'Device', tg_e(tg_clip($d['user_agent'], 120))) : null,
            ]];

        case 'login_fail':
            return ['cat' => 'login', 'title' => '🔐 <b>LOGIN FAILED</b>', 'rows' => [
                tg_r('👤', 'Account', tg_c(isset($d['username']) ? $d['username'] : '')),
                tg_r('🌐', 'IP', tg_c(isset($d['ip']) ? $d['ip'] : '')),
                tg_r('🔁', 'Attempts', '<b>' . (int) (isset($d['count']) ? $d['count'] : 1) . '</b>'),
            ]];

        case 'page_view':
            $role = isset($d['role']) && $d['role'] === 'admin' ? '👑 admin' : 'member';
            return ['cat' => 'visit', 'title' => '🚪 <b>OPENED THE SITE</b>', 'throttle' => ['pv:' . $user, 120], 'rows' => [
                $userRow,
                tg_r('🏷', 'Role', tg_e($role)),
                tg_r('🔑', 'Plan', tg_c(isset($d['plan']) && $d['plan'] !== '' ? $d['plan'] : 'NONE') . ' · ' . (int) (isset($d['turns']) ? $d['turns'] : 0) . ' turn(s)'),
                $ipRow,
            ]];

        case 'member_action':
            $cat = (isset($d['cat']) && $d['cat'] === 'minor') ? 'minor' : 'activity';
            $thr = isset($d['throttle']) ? (int) $d['throttle'] : 0;
            $r   = ['cat' => $cat, 'title' => '👁 <b>MEMBER ACTION</b> · ' . tg_e(isset($d['action']) ? $d['action'] : ''), 'rows' => [
                $userRow,
                !empty($d['detail']) ? tg_r('📝', 'Detail', tg_e(tg_clip($d['detail'], 300))) : null,
                $ipRow,
            ]];
            if ($thr > 0) {
                $r['throttle'] = ['ma:' . $user . ':' . (isset($d['action']) ? $d['action'] : ''), $thr];
            }
            return $r;

        case 'ip_ban':
            $total = null;
            $c     = tg_db();
            if ($c !== null) {
                try {
                    $res   = mysqli_query($c, 'SELECT COUNT(*) AS n FROM ip_bans WHERE active = 1');
                    $row   = $res ? mysqli_fetch_assoc($res) : null;
                    $total = $row ? (int) $row['n'] : null;
                } catch (Throwable $e) {
                    $total = null;
                }
            }
            return ['cat' => 'ban', 'title' => '🚫 <b>IP BANNED FOREVER</b>', 'rows' => [
                tg_r('🌐', 'Banned IP', tg_c(isset($d['ip']) ? $d['ip'] : '')),
                $userRow,
                tg_r('📝', 'Reason', tg_e(tg_clip(isset($d['reason']) ? $d['reason'] : '', 200))),
                tg_r('👮', 'Banned by', tg_c('admin ' . (isset($d['by']) ? $d['by'] : '?'))),
                $total !== null ? tg_r('📊', 'Total banned IPs', '<b>' . $total . '</b>') : null,
                tg_r('👁', 'Visitor now sees', 'the "Access blocked" page'),
            ]];

        case 'ip_unban':
            return ['cat' => 'ban', 'title' => '✅ <b>IP UNBANNED</b>', 'rows' => [
                tg_r('🌐', 'IP', tg_c(isset($d['ip']) ? $d['ip'] : '')),
                tg_r('👮', 'Unbanned by', tg_c('admin ' . (isset($d['by']) ? $d['by'] : '?'))),
            ]];

        case 'ip_blocked_hit':
            return ['cat' => 'ban', 'title' => '⛔ <b>BANNED IP TRIED TO ENTER</b>', 'throttle' => ['bh:' . (isset($d['ip']) ? $d['ip'] : ''), 600], 'rows' => [
                tg_r('🌐', 'IP', tg_c(isset($d['ip']) ? $d['ip'] : '')),
                $userRow,
                tg_r('🔁', 'Blocked hits', '<b>' . (int) (isset($d['hits']) ? $d['hits'] : 1) . '</b>'),
                tg_r('📝', 'Ban reason', tg_e(tg_clip(isset($d['reason']) ? $d['reason'] : '', 120))),
                !empty($d['user_agent']) ? tg_r('📱', 'Device', tg_e(tg_clip($d['user_agent'], 120))) : null,
                tg_r('🔗', 'Request', tg_c(tg_page())),
            ]];

        case 'user_block':
            return ['cat' => 'ban', 'title' => '⛔ <b>USER BLOCKED</b>', 'rows' => [
                $userRow,
                tg_r('📝', 'Reason', tg_e(tg_clip(isset($d['reason']) ? $d['reason'] : '', 200))),
                tg_r('🔒', 'Account', 'login locked · key revoked'),
                tg_r('🌐', 'IPs banned', '<b>' . (int) (isset($d['ips']) ? $d['ips'] : 0) . '</b>'),
                tg_r('👮', 'Blocked by', tg_c('admin ' . (isset($d['by']) ? $d['by'] : '?'))),
            ]];

        case 'user_unblock':
            return ['cat' => 'ban', 'title' => '✅ <b>USER UNBLOCKED</b>', 'rows' => [
                $userRow,
                tg_r('🔓', 'Account', 'password and key given back'),
                tg_r('👮', 'Unblocked by', tg_c('admin ' . (isset($d['by']) ? $d['by'] : '?'))),
            ]];

        case 'bot_audit':
            return ['cat' => 'audit', 'title' => '🤖 <b>BOT ACTION</b>', 'except' => isset($d['admin']) ? (string) $d['admin'] : '', 'rows' => [
                tg_r('👤', 'By admin', tg_c(isset($d['admin']) ? $d['admin'] : '')),
                tg_r('⚡', 'Action', tg_c(isset($d['action']) ? $d['action'] : '')),
                tg_r('📝', 'Detail', tg_e(tg_clip(isset($d['detail']) ? $d['detail'] : '', 400))),
            ]];

        case 'test':
            return ['cat' => 'test', 'title' => '🔔 <b>TEST ALERT</b>', 'rows' => [
                tg_r('✅', 'Status', 'Telegram alerts are working'),
                tg_r('🌐', 'Server', tg_c(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : php_uname('n'))),
            ]];
    }
    return null;
}

/* ------------------------------------------------------------------
 * Queue + delivery. Messages are collected during the request and sent AFTER the page has
 * been delivered to the visitor (fastcgi_finish_request), so nobody waits for Telegram.
 * ------------------------------------------------------------------ */

function tg_queue($item = null)
{
    static $q = [];
    if ($item === null) {
        $out = $q;
        $q   = [];
        return $out;
    }
    $q[] = $item;
    return null;
}

/** Report something that happened. Never throws. */
function tg_event($type, array $d = [])
{
    static $busy = false;
    if ($busy || !tg_enabled()) {
        return;
    }
    $busy = true;
    try {
        if (isset($d['username']) && in_array($type, ['page_view', 'member_action', 'tool_use', 'key_ok', 'key_invalid', 'freekey_click'], true)) {
            tg_block_enforce((string) $d['username'], isset($d['role']) ? (string) $d['role'] : 'member'); // blocked member: ends the request here
        }
        $map = tg_activity_map($type, $d);
        if ($map !== null) {
            $logIt = true;
            if ($type === 'page_view' || $type === 'ip_blocked_hit' || ($type === 'member_action' && !empty($d['throttle']))) {
                $logIt = tg_throttle('al:' . $type . ':' . $map[0] . ':' . $map[2], 20); // very chatty events: at most 1 row / 20 s per member
            }
            if ($logIt) {
                tg_activity($map[0], $map[1], $map[2], $map[3], array_key_exists('ip', $d) && $d['ip'] !== '' ? (string) $d['ip'] : null);
            }
        }
        $r = tg_render($type, $d);
        if ($r !== null && tg_cat_on($r['cat'])) {
            if (empty($r['throttle']) || tg_throttle($r['throttle'][0], $r['throttle'][1])) {
                tg_queue([
                    'html'   => tg_card($r['title'], $r['rows'], isset($r['pre']) ? $r['pre'] : ''),
                    'except' => isset($r['except']) ? $r['except'] : '',
                ]);
            }
        }
    } catch (Throwable $e) {
        error_log('[telegram.php] event ' . $type . ': ' . $e->getMessage());
    }
    $busy = false;
}

function tg_flush($finish = true)
{
    $items = tg_queue(null);
    if (!$items || !tg_enabled()) {
        return;
    }
    $down = tg_store_read('down');
    if (!empty($down['until']) && $down['until'] > time()) {
        return; // Telegram was unreachable a moment ago: do not slow the site down again
    }

    if ($finish && PHP_SAPI !== 'cli') {
        ignore_user_abort(true);
        @set_time_limit(30);
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close(); // do not keep the visitor's session locked while we talk to Telegram
        }
        if (function_exists('fastcgi_finish_request')) {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @flush();
            @fastcgi_finish_request();
        }
    }

    // Flood guard: at most 60 alerts per minute, the rest is summarised in one line
    $allowed = 0;
    $dropped = 0;
    tg_store_update('rate', function ($s) use (&$allowed, &$dropped, $items) {
        $win = (int) floor(time() / 60);
        if ((int) (isset($s['w']) ? $s['w'] : 0) !== $win) {
            $s = ['w' => $win, 'n' => 0];
        }
        foreach ($items as $i) {
            if ($s['n'] < 60) {
                $s['n']++;
                $allowed++;
            } else {
                $dropped++;
            }
        }
        return $s;
    });
    $items = array_slice($items, 0, $allowed);
    if ($dropped > 0) {
        $items[] = ['html' => '⚠️ <b>' . $dropped . ' more alert(s) were suppressed</b> (rate limit: 60 per minute)', 'except' => ''];
    }

    foreach ($items as $item) {
        foreach (tg_admin_ids() as $adminId) {
            if ($item['except'] !== '' && (string) $item['except'] === (string) $adminId) {
                continue;
            }
            $params = [
                'chat_id' => $adminId,
                'text' => tg_clip($item['html'], 3800) . '  ' . tg_tag(),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ];
            $res = tg_api('sendMessage', $params, 4);
            if (empty($res['ok'])) {
                if (!isset($res['error_code'])) {
                    tg_store_update('down', function ($s) {
                        return ['until' => time() + 90];
                    });
                    return; // network problem: stop, try again later
                }
                if (stripos((string) (isset($res['description']) ? $res['description'] : ''), 'parse') !== false) {
                    $params['parse_mode'] = '';
                    unset($params['parse_mode']);
                    $params['text'] = html_entity_decode(strip_tags($params['text']), ENT_QUOTES, 'UTF-8');
                    tg_api('sendMessage', $params, 4);
                }
            }
        }
    }
}

/* ------------------------------------------------------------------
 * Registrations announced exactly once (by the hook in the register page OR by the scanner)
 * ------------------------------------------------------------------ */

function tg_claim_registration($id, $viaScan)
{
    $ok = false;
    tg_store_update('scan', function ($s) use ($id, $viaScan, &$ok) {
        $reg = (isset($s['reg']) && is_array($s['reg'])) ? $s['reg'] : [];
        if (in_array($id, $reg, true)) {
            return $s;
        }
        if (!$viaScan && isset($s['b']['users']) && (int) $s['b']['users'] >= $id) {
            return $s; // the scanner already walked past this account
        }
        $reg[]    = $id;
        $s['reg'] = array_slice($reg, -150);
        $ok       = true;
        return $s;
    });
    return $ok;
}

/* ------------------------------------------------------------------
 * Scanner: read-only look at tables the existing site already writes to.
 * New accounts, admin_cp actions (admin_logs), security_logs and failed logins become alerts,
 * without touching admin_cp / login / register files. The first run only remembers "now".
 * ------------------------------------------------------------------ */

function tg_scan_events($force = false)
{
    if (!tg_enabled()) {
        return;
    }
    $conn = tg_db();
    if ($conn === null) {
        return;
    }
    $run      = false;
    $interval = max(5, (int) tg_cfg('scan_interval', 10));
    tg_store_update('scanlock', function ($s) use (&$run, $interval, $force) {
        $now = time();
        if (!$force && isset($s['at']) && $s['at'] > $now - $interval) {
            return $s;
        }
        $run = true;
        return ['at' => $now];
    });
    if (!$run) {
        return;
    }

    $specs = [
        'users'          => 'SELECT id, username FROM users WHERE id > %d ORDER BY id ASC LIMIT 30',
        'admin_logs'     => 'SELECT id, username, action, detail, ip, created_at FROM admin_logs WHERE id > %d ORDER BY id ASC LIMIT 30',
        'security_logs'  => 'SELECT id, ip_address, user_agent, violation_type FROM security_logs WHERE id > %d ORDER BY id ASC LIMIT 100',
        'login_attempts' => 'SELECT id, username, ip FROM login_attempts WHERE id > %d ORDER BY id ASC LIMIT 100',
    ];
    foreach ($specs as $table => $sql) {
        try {
            tg_scan_table($conn, $table, $sql);
        } catch (Throwable $e) {
            error_log('[telegram.php] scan ' . $table . ': ' . $e->getMessage());
        }
    }
}

function tg_scan_table($conn, $table, $sqlTemplate)
{
    $state = tg_store_read('scan');
    $base  = isset($state['b'][$table]) ? (int) $state['b'][$table] : null;

    $res = mysqli_query($conn, 'SELECT COALESCE(MAX(id), 0) AS m FROM `' . $table . '`');
    $row = $res ? mysqli_fetch_assoc($res) : null;
    $max = $row ? (int) $row['m'] : 0;

    if ($base === null || $base > $max) { // first run (or table emptied): start from "now", never announce old rows
        tg_store_update('scan', function ($s) use ($table, $max) {
            $s['b'][$table] = $max;
            return $s;
        });
        return;
    }
    if ($max === $base) {
        return;
    }

    $res  = mysqli_query($conn, sprintf($sqlTemplate, $base));
    $rows = [];
    $last = $base;
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
        $last   = max($last, (int) $r['id']);
    }
    tg_store_update('scan', function ($s) use ($table, $last) {
        $s['b'][$table] = $last;
        return $s;
    });

    if ($table === 'users') {
        foreach ($rows as $r) {
            if (tg_claim_registration((int) $r['id'], true)) {
                tg_event('register', ['username' => $r['username'], 'id' => (int) $r['id'], 'ip' => '', 'claimed' => true]);
            }
        }
    } elseif ($table === 'admin_logs') {
        foreach ($rows as $r) {
            if (strpos((string) $r['username'], 'tg:') === 0) {
                continue; // written by the Telegram bot itself
            }
            tg_event('admin_log', $r);
        }
    } elseif ($table === 'security_logs') {
        $groups = [];
        foreach ($rows as $r) {
            $k = $r['ip_address'] . '|' . $r['violation_type'];
            if (!isset($groups[$k])) {
                $groups[$k] = $r + ['count' => 0];
            }
            $groups[$k]['count']++;
        }
        foreach ($groups as $g) {
            tg_event('security_log', $g);
        }
    } elseif ($table === 'login_attempts') {
        $groups = [];
        foreach ($rows as $r) {
            $k = $r['username'] . '|' . $r['ip'];
            if (!isset($groups[$k])) {
                $groups[$k] = $r + ['count' => 0];
            }
            $groups[$k]['count']++;
        }
        foreach ($groups as $g) {
            tg_event('login_fail', $g);
        }
    }
}

/* ------------------------------------------------------------------
 * Error / downtime watcher. Call tg_boot() once, BEFORE db.php is included,
 * so a dead database is reported too.
 * ------------------------------------------------------------------ */

function tg_php_error($no, $str, $file, $line)
{
    if (!(error_reporting() & $no)) {
        return false; // silenced with @
    }
    if (!in_array($no, [E_WARNING, E_USER_WARNING, E_RECOVERABLE_ERROR, E_USER_ERROR], true)) {
        return false;
    }
    tg_event('php_warning', ['message' => $str, 'file' => $file, 'line' => $line]);
    return false; // let PHP keep its normal handling / logging
}

function tg_shutdown()
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;
    try {
        $fatal = false;
        $err   = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
            $fatal = true;
            tg_event('site_fatal', ['message' => $err['message'], 'file' => $err['file'], 'line' => $err['line']]);
        }

        $dbOk = false;
        $c    = isset($GLOBALS['conn']) ? $GLOBALS['conn'] : null;
        if ($c instanceof mysqli) {
            try {
                $dbOk = !$c->connect_errno;
            } catch (Throwable $e) {
                $dbOk = false;
            }
        }
        if (!$dbOk && !$fatal) {
            tg_event('db_down', []);
        }

        tg_flush(true);
        if ($dbOk && !$fatal) {
            tg_scan_events();
            tg_flush(false);
        }
    } catch (Throwable $e) {
        error_log('[telegram.php] shutdown: ' . $e->getMessage());
    }
}

function tg_boot($ctx = 'web')
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $GLOBALS['TG_CTX'] = $ctx;
    if (!tg_enabled()) {
        return;
    }
    set_error_handler('tg_php_error');
    register_shutdown_function('tg_shutdown');
}
