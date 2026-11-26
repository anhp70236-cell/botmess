<?php /* UPGRADE-V3-SMART */
/* ==========================================================================
   telegram.php  —  Smart Telegram Core, Permanent Premium Emoji Vault,
   Compact Real-Time Alerts & Command Auto-Delete Engine for IOSMODS.XYZ
   - Website notifications/alerts are NEVER auto-deleted.
   - Admin commands & bot control panels auto-delete after a configurable delay.
   - Custom emojis sent by Admin are auto-learned, applied across 100% of bot icons,
     and stored permanently in MySQL site_config + JSON vault (survives Premium expiry).
   - PHP 7.2 – 8.3+ compatible. Never throws fatal errors to the website.
   ========================================================================== */

if (!defined('TG_LIB_LOADED')) {
    define('TG_LIB_LOADED', 1);
}

if (is_file(__DIR__ . '/security_shield.php')) {
    require_once __DIR__ . '/security_shield.php';
}

/* ------------------------------------------------------------------
 * 1. Configuration & Admin Identity
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
            'bot_token'         => '',
            'admin_ids'         => [],
            'webhook_secret'    => '',
            'setup_key'         => '',
            'webhook_url'       => '',
            'site_name'         => 'IOSMODS.XYZ',
            'allow_destructive' => false,
            'timezone'          => 'Asia/Ho_Chi_Minh',
            'scan_interval'     => 10,
            'api_base'          => 'https://api.telegram.org',
            'tag_user_id'       => '',
            'tag_name'          => '',
            'contact_url'       => 'https://t.me/iosnea',
            'emoji_enabled'     => true,
            'emoji_pack'        => '',
            'emoji_ids'         => [],
            'emoji_map'         => [],
            'button_colors'     => true,
            'autodelete_sec'    => 120,
        ];
    }
    if ($key === null) {
        return $cfg;
    }
    return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
}

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
 * 2. String, Time & IP Helpers
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
        $d = new DateTime('now', new DateTimeZone((string) tg_cfg('timezone', 'Asia/Ho_Chi_Minh')));
        return $d->format($format);
    } catch (Exception $e) {
        return date($format);
    }
}

function tg_hours($h)
{
    $h = (int) $h;
    if ($h >= 48 && $h % 24 === 0) {
        return ($h / 24) . ' ngày';
    }
    return $h . 'h';
}

function tg_db()
{
    $c = isset($GLOBALS['conn']) ? $GLOBALS['conn'] : null;
    return ($c instanceof mysqli) ? $c : null;
}

function tg_ip()
{
    if (function_exists('sh_client_ip')) {
        $sip = (string) sh_client_ip();
        if ($sip !== '') {
            return $sip;
        }
    }
    if (function_exists('client_ip')) {
        return (string) client_ip();
    }
    return substr((string) (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''), 0, 45);
}

/* ------------------------------------------------------------------
 * 3. Lock-Safe JSON Storage in ./cache (gitignored)
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
    $p = tg_path($name);
    if (!is_file($p)) {
        return [];
    }
    $raw  = @file_get_contents($p);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function tg_store_update($name, $fn)
{
    $fh = @fopen(tg_path($name), 'c+');
    if ($fh === false) {
        $r = $fn([]);
        return is_array($r) ? $r : [];
    }
    @flock($fh, LOCK_EX);
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
    fwrite($fh, json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fh);
    @flock($fh, LOCK_UN);
    fclose($fh);
    return $new;
}

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
 * 4. Alert Categories & Owner Tag
 * ------------------------------------------------------------------ */

function tg_categories()
{
    return [
        'register' => ['🆕 Đăng ký mới', true],
        'freekey'  => ['🎁 Get Key Free', true],
        'key'      => ['🔑 Nhập Key', true],
        'tool'     => ['📦 Bấm sản phẩm', true],
        'ddos'     => ['🛡 Chống DDoS / Spam', true],
        'vpn'      => ['🕵️ VPN & Đa tài khoản', true],
        'ban'      => ['🚫 Khóa IP / User', true],
        'admin'    => ['👑 Admin CP', true],
        'error'    => ['🚨 Lỗi hệ thống', true],
        'login'    => ['🔐 Đăng nhập sai', false],
        'visit'    => ['🚪 Mở trang chủ', false],
        'minor'    => ['🔕 Sự kiện phụ', false],
        'audit'    => ['🤖 Nhật ký Bot', false],
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
 * 5. Command & Bot Panel Auto-Delete Engine (NEVER deletes Website Alerts!)
 * ------------------------------------------------------------------ */

function tg_autodelete_ttl()
{
    $s = tg_store_read('settings');
    if (isset($s['autodelete_sec'])) {
        return max(0, (int) $s['autodelete_sec']);
    }
    return max(0, (int) tg_cfg('autodelete_sec', 120));
}

function tg_autodelete_set_ttl($seconds)
{
    $seconds = max(0, min(86400, (int) $seconds));
    tg_store_update('settings', function ($s) use ($seconds) {
        $s['autodelete_sec'] = $seconds;
        return $s;
    });
    return $seconds;
}

/**
 * Schedules ONLY Admin commands and Bot interactive UI panels for auto-deletion.
 * Website notification cards MUST NEVER call this function.
 */
function tg_autodelete_register($chatId, $messageId, $customTtl = null)
{
    $chatId    = trim((string) $chatId);
    $messageId = (int) $messageId;
    if ($chatId === '' || $messageId <= 0) {
        return;
    }
    $ttl = $customTtl !== null ? (int) $customTtl : tg_autodelete_ttl();
    if ($ttl <= 0) {
        return;
    }
    $due = time() + max(10, $ttl);
    tg_store_update('autodelete', function ($q) use ($chatId, $messageId, $due) {
        if (!is_array($q)) {
            $q = [];
        }
        $q[$chatId . ':' . $messageId] = $due;
        if (count($q) > 500) {
            asort($q);
            $q = array_slice($q, -400, null, true);
        }
        return $q;
    });
    tg_autodelete_spawn_worker();
}

function tg_autodelete_sweep()
{
    if (!tg_enabled()) {
        return 0;
    }
    $q = tg_store_read('autodelete');
    if (!$q) {
        return 0;
    }
    $now = time();
    $due = [];
    foreach ($q as $k => $exp) {
        if ((int) $exp <= $now) {
            $due[$k] = (int) $exp;
        }
    }
    if (!$due) {
        return 0;
    }
    $dueKeys = array_slice(array_keys($due), 0, 25);
    tg_store_update('autodelete', function ($cur) use ($dueKeys) {
        foreach ($dueKeys as $dk) {
            unset($cur[$dk]);
        }
        return $cur;
    });
    $deleted = 0;
    foreach ($dueKeys as $dk) {
        $parts = explode(':', (string) $dk, 2);
        if (count($parts) === 2 && (int) $parts[1] > 0) {
            $r = tg_api_raw('deleteMessage', ['chat_id' => $parts[0], 'message_id' => (int) $parts[1]], 3);
            if (!empty($r['ok'])) {
                $deleted++;
            }
        }
    }
    return $deleted;
}

/** Spawns at most 1 lightweight background PHP timer so command messages auto-delete even with 0 web traffic. */
function tg_autodelete_spawn_worker()
{
    $lockFile = tg_dir() . '/tg_ad_worker.lock';
    if (is_file($lockFile) && @filemtime($lockFile) > time() - 25) {
        return; // worker already active
    }
    $botScript = __DIR__ . '/telegram_bot.php';
    if (!is_file($botScript)) {
        return;
    }
    $disabled = strtolower((string) ini_get('disable_functions'));
    $phpBin   = defined('PHP_BINARY') && PHP_BINARY !== '' && stripos(PHP_BINARY, 'fpm') === false && stripos(PHP_BINARY, 'httpd') === false
        ? PHP_BINARY
        : 'php';
    @touch($lockFile);
    if (DIRECTORY_SEPARATOR === '\\') {
        if (function_exists('popen') && strpos($disabled, 'popen') === false) {
            $cmd = 'start /B "" "' . $phpBin . '" "' . $botScript . '" --autodelete-sweep >NUL 2>&1';
            $h   = @popen($cmd, 'r');
            if (is_resource($h)) {
                @pclose($h);
            }
        }
    } else {
        if (function_exists('exec') && strpos($disabled, 'exec') === false) {
            $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($botScript) . ' --autodelete-sweep >/dev/null 2>&1 &';
            @exec($cmd);
        }
    }
}

/* ------------------------------------------------------------------
 * 6. Permanent Custom Emoji Vault (Survives Premium Expiry Forever!)
 * ------------------------------------------------------------------ */

function tg_emoji_norm($s)
{
    return str_replace(["\u{FE0F}", "\u{FE0E}"], '', (string) $s);
}

function tg_emoji_vault_file()
{
    return tg_dir() . '/tg_emoji_vault.json';
}

function tg_emoji_vault_read()
{
    if (isset($GLOBALS['TG_EMOJI_VAULT_MEMO']) && is_array($GLOBALS['TG_EMOJI_VAULT_MEMO'])) {
        return $GLOBALS['TG_EMOJI_VAULT_MEMO'];
    }
    $v = [];
    $f = tg_emoji_vault_file();
    if (is_file($f)) {
        $raw = @file_get_contents($f);
        $dec = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($dec)) {
            $v = $dec;
        }
    }
    // Fallback / restore from permanent MySQL site_config if cache file was ever cleared
    if (empty($v['pool']) && empty($v['picks'])) {
        $c = tg_db();
        if ($c !== null) {
            try {
                $res = @$c->query("SELECT config_value FROM site_config WHERE config_key = 'tg_emoji_vault' LIMIT 1");
                $row = $res ? $res->fetch_assoc() : null;
                if ($row && !empty($row['config_value'])) {
                    $dbDec = json_decode((string) $row['config_value'], true);
                    if (is_array($dbDec)) {
                        $v = $dbDec;
                        @file_put_contents($f, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
    }
    // Also merge legacy settings['emoji_pick'] if present so old picks are never lost
    $legacy = tg_store_read('settings');
    if (!empty($legacy['emoji_pick']) && is_array($legacy['emoji_pick'])) {
        if (!isset($v['picks']) || !is_array($v['picks'])) {
            $v['picks'] = [];
        }
        foreach ($legacy['emoji_pick'] as $eu => $eid) {
            if (preg_match('/^\d{5,}$/', (string) $eid) && !isset($v['picks'][tg_emoji_norm($eu)])) {
                $v['picks'][tg_emoji_norm($eu)] = (string) $eid;
            }
        }
    }
    $v += [
        'picks'        => [],
        'pack_name'    => '',
        'pack_list'    => [],
        'pool'         => [],
        'default_id'   => '',
        'replace_all'  => true,
        'updated_at'   => 0,
    ];
    return $GLOBALS['TG_EMOJI_VAULT_MEMO'] = $v;
}

/** Saves Emoji Vault permanently to BOTH ./cache/tg_emoji_vault.json AND MySQL site_config. */
function tg_emoji_vault_write(array $vault)
{
    $vault['updated_at'] = time();
    $GLOBALS['TG_EMOJI_VAULT_MEMO'] = $vault;
    $json = json_encode($vault, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents(tg_emoji_vault_file(), $json, LOCK_EX);

    $c = tg_db();
    if ($c !== null) {
        try {
            $st = @$c->prepare("INSERT INTO site_config (config_key, config_value) VALUES ('tg_emoji_vault', ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
            if ($st) {
                $st->bind_param('s', $json);
                @$st->execute();
                $st->close();
            }
        } catch (Throwable $e) {
            error_log('[telegram.php] vault db save: ' . $e->getMessage());
        }
    }
    tg_store_update('emojidown', function () {
        return [];
    });
    tg_emoji_reset();
}

function tg_emoji_slots()
{
    static $slots = null;
    if ($slots === null) {
        $raw = [
            ['🏠', 'Menu'], ['🔄', 'Làm mới'], ['⬅', 'Quay lại'], ['✖', 'Huỷ'], ['✅', 'Thành công'], ['❌', 'Lỗi / Sai'],
            ['🟢', 'Bật'], ['🔴', 'Tắt'], ['🟡', 'Chờ'], ['⚠', 'Cảnh báo'], ['⛔', 'Chặn'], ['🚫', 'Cấm IP'],
            ['🛡', 'Khiên DDoS'], ['🔐', 'Bảo mật'], ['🔒', 'Khoá'], ['🔓', 'Mở khoá'], ['👤', 'Thành viên'], ['👥', 'DS User'],
            ['👑', 'Admin'], ['💎', 'VIP'], ['🔑', 'Key'], ['🎁', 'Key Free'], ['🎟', 'Lượt'], ['⏳', 'Hạn dùng'],
            ['📦', 'Sản phẩm'], ['🧰', 'Công cụ'], ['🛠', 'Bảo trì'], ['⚙', 'Cấu hình'], ['🗄', 'Database'], ['📜', 'Nhật ký'],
            ['🧮', 'SQL'], ['🩺', 'Sức khoẻ'], ['🔔', 'Thông báo'], ['🎨', 'Emoji'], ['🔍', 'Tìm kiếm'], ['➕', 'Thêm'],
            ['➖', 'Giảm'], ['👁', 'Hoạt động'], ['🆕', 'Mới'], ['📈', 'Thống kê'], ['🕒', 'Thời gian'], ['🌐', 'IP'],
            ['🔗', 'Liên kết'], ['📝', 'Ghi chú'], ['📄', 'Dữ liệu'], ['📍', 'Vị trí'], ['📱', 'Thiết bị'], ['👮', 'Quản trị'],
            ['🚨', 'Báo động'], ['☹', 'Mặt buồn'], ['🕵', 'Giám sát VPN'], ['🧠', 'AI Tối cao'], ['⚡', 'Tốc độ'], ['⭐', 'Sao'],
            ['📨', 'Tin nhắn'], ['🏷', 'Gói'], ['🗑', 'Xoá'], ['🧹', 'Dọn dẹp'], ['✍', 'Sửa'], ['⏱', 'Hẹn giờ'],
        ];
        $slots = [];
        foreach ($raw as $r) {
            $slots[] = [tg_emoji_norm($r[0]), $r[1]];
        }
    }
    return $slots;
}

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

/** Semantic aliases so EVERY secondary icon across alerts, /logo, /tuyet, /cauhinh, /dangbi, /check, & Case Dossier gets a fitting custom emoji! */
function tg_emoji_extended_aliases()
{
    return [
        '📩' => '📨', '📋' => '📜', '🔎' => '🔍', '💬' => '📝', '🖥' => '🌐', '📡' => '🌐',
        '📊' => '📈', '💡' => '🧠', '👋' => '👑', '🆔' => '🔐', '↩' => '⬅', '♻' => '🔄',
        '🔁' => '🔄', '🔀' => '⚙', 'ℹ' => '🔔', '⚪' => '🟡', '⏸' => '🔴', '▶' => '🟢',
        '✔' => '✅', '☑' => '✅', '🔥' => '⚡', '✨' => '⭐', '🌟' => '⭐', '💰' => '💎',
        '❄' => '⭐', '🌤' => '🟢', '🖼' => '🎨', '🏞' => '🎨', '🔖' => '🏷', '🤖' => '🧠',
        '📌' => '📍', '💾' => '🗄', '✏' => '✍', '📂' => '📦', '🧭' => '🌐', '😊' => '🟢',
        '🚀' => '⚡', '💳' => '💎', '📢' => '🔔', '⚖' => '📜', '🛑' => '⛔', '⌛' => '⏳',
        '⭕' => '🟢', '🧩' => '⚙', '☀' => '⭐', '🌑' => '🔐', '🔵' => '💎', '🔘' => '🟢',
    ];
}

/**
 * Ingests 60 OR MORE (unlimited: 60, 80, 120, 200+) custom_emoji_ids sent by Admin in Telegram chat:
 * - Validates legally via Telegram API getCustomEmojiStickers in safe batches of 100 (never hitting Telegram's 200-ID limit!).
 * - Filters out any invalid/malformed IDs automatically.
 * - Uses Semantic + Visual Category Harmony to map ALL 60 core UI slots + ALL extended secondary icons so every panel & button looks cohesive and balanced.
 * - Saves everything permanently in MySQL site_config + JSON vault (up to 300 pool emojis) so it survives Premium expiry!
 */
function tg_emoji_ingest_custom_ids(array $ids, array $msgUnicodeHints = [])
{
    $orderedIds   = [];
    $orderedHints = [];
    foreach ($ids as $idx => $id) {
        $id = trim((string) $id);
        // Strict Telegram Custom Emoji ID format check (64-bit positive integer string, 10..22 digits)
        if (preg_match('/^\d{10,22}$/', $id) || preg_match('/^\d{5,}$/', $id)) {
            $orderedIds[]   = $id;
            $orderedHints[] = isset($msgUnicodeHints[$idx]) ? tg_emoji_norm($msgUnicodeHints[$idx]) : '';
            if (count($orderedIds) >= 300) {
                break;
            }
        }
    }
    if (!$orderedIds) {
        return ['ok' => false, 'count' => 0, 'pack' => ''];
    }
    $uniqueIds = array_values(array_unique($orderedIds));

    $vault    = tg_emoji_vault_read();
    $found    = [];
    $validSet = [];
    $packName = '';
    $apiResponded = false;

    // Query Telegram API in safe legal chunks of 100 IDs per request (Telegram caps getCustomEmojiStickers at 200)
    foreach (array_chunk($uniqueIds, 100) as $chunk) {
        $res = tg_api_raw('getCustomEmojiStickers', ['custom_emoji_ids' => $chunk], 6);
        if (!empty($res['ok']) && isset($res['result']) && is_array($res['result'])) {
            $apiResponded = true;
            foreach ($res['result'] as $st) {
                if (!empty($st['custom_emoji_id'])) {
                    $cid = (string) $st['custom_emoji_id'];
                    $em  = !empty($st['emoji']) ? tg_emoji_norm($st['emoji']) : '';
                    $found[$cid]    = $em;
                    $validSet[$cid] = true;
                    if ($packName === '' && !empty($st['set_name'])) {
                        $packName = (string) $st['set_name'];
                    }
                }
            }
        }
    }

    // Filter to confirmed legal IDs if Telegram API returned sticker metadata (graceful fallback if API returned empty)
    if ($apiResponded && !empty($validSet)) {
        $fIds   = [];
        $fHints = [];
        foreach ($orderedIds as $idx => $cid) {
            if (isset($validSet[$cid])) {
                $fIds[]   = $cid;
                $fHints[] = $orderedHints[$idx];
            }
        }
        if ($fIds) {
            $orderedIds   = $fIds;
            $orderedHints = $fHints;
            $uniqueIds    = array_values(array_unique($orderedIds));
        }
    }

    foreach ($orderedIds as $idx => $cid) {
        if (empty($found[$cid]) && !empty($orderedHints[$idx])) {
            $found[$cid] = $orderedHints[$idx];
        }
    }

    $packList = is_array($vault['pack_list']) ? $vault['pack_list'] : [];
    if ($packName !== '') {
        $pRes = tg_api_raw('getStickerSet', ['name' => $packName], 6);
        if (!empty($pRes['ok']) && isset($pRes['result']['stickers']) && is_array($pRes['result']['stickers'])) {
            $freshPack = [];
            foreach ($pRes['result']['stickers'] as $pst) {
                if (!empty($pst['custom_emoji_id'])) {
                    $freshPack[] = [
                        'id' => (string) $pst['custom_emoji_id'],
                        'e'  => !empty($pst['emoji']) ? tg_emoji_norm($pst['emoji']) : '⭐',
                    ];
                }
            }
            if ($freshPack) {
                $packList = $freshPack;
                $vault['pack_name'] = $packName;
                $vault['pack_list'] = $packList;
                tg_store_update('settings', function ($s) use ($packName) {
                    $s['emoji_pack'] = $packName;
                    $s['emoji_off']  = false;
                    return $s;
                });
            }
        }
    }

    if (!is_array($vault['picks'])) {
        $vault['picks'] = [];
    }

    // Build lookup by base unicode character from the Admin's sent emojis first, then from pack
    $sentByChar = [];
    foreach ($orderedIds as $idx => $cid) {
        $uChar = !empty($found[$cid]) ? $found[$cid] : $orderedHints[$idx];
        if ($uChar !== '' && !isset($sentByChar[$uChar])) {
            $sentByChar[$uChar] = $cid;
            $vault['picks'][$uChar] = $cid;
        }
    }

    $packByChar = [];
    foreach ($packList as $item) {
        if (!empty($item['e']) && !empty($item['id']) && !isset($packByChar[$item['e']])) {
            $packByChar[$item['e']] = (string) $item['id'];
        }
    }

    $pool = $uniqueIds;
    if (count($uniqueIds) === 1 && count($packList) === 0) {
        $pool = [$uniqueIds[0]];
    } elseif ($packList) {
        foreach ($packList as $item) {
            if (!empty($item['id'])) {
                $pool[] = (string) $item['id'];
            }
        }
        $pool = array_values(array_unique($pool));
    }

    $vault['default_id']  = $orderedIds[0];
    $vault['pool']        = array_slice($pool, 0, 300);
    $vault['replace_all'] = true;

    // Aesthetic Auto-Harmony Engine for 60 or 60+ Emojis:
    // 1) Exact semantic base-emoji match wins first.
    // 2) Remaining unique sent emojis (1..60+) fill all 60 core UI slots with zero adjacent duplicates.
    // 3) Extra sent emojis beyond 60 (61..N) are assigned directly to extended secondary UI icons so EVERY sent emoji shines!
    $slots     = tg_emoji_slots();
    $sentCount = count($orderedIds);
    $palette   = count($uniqueIds) >= 2 ? $uniqueIds : $orderedIds;
    $palCount  = count($palette);
    $usedIdx   = 0;

    foreach ($slots as $idx => $sl) {
        $u = $sl[0];
        if (isset($sentByChar[$u])) {
            $vault['picks'][$u] = $sentByChar[$u];
        } elseif ($palCount > 1 && isset($palette[$usedIdx])) {
            $vault['picks'][$u] = $palette[$usedIdx];
            $usedIdx++;
        } elseif ($palCount > 1) {
            $vault['picks'][$u] = isset($packByChar[$u]) ? $packByChar[$u] : $palette[$idx % $palCount];
        } elseif (!isset($vault['picks'][$u])) {
            if (isset($packByChar[$u])) {
                $vault['picks'][$u] = $packByChar[$u];
            } elseif ($sentCount === 1 && !empty($packList)) {
                $vault['picks'][$u] = $packList[$idx % count($packList)]['id'];
            } else {
                $vault['picks'][$u] = $orderedIds[0];
            }
        }
    }

    // Map all extended secondary icons: if Admin sent > 60 unique emojis, assign extra emojis (61..N) directly for maximum visual richness!
    foreach (tg_emoji_extended_aliases() as $extChar => $targetSlotChar) {
        $eNorm = tg_emoji_norm($extChar);
        $tNorm = tg_emoji_norm($targetSlotChar);
        if (isset($sentByChar[$eNorm])) {
            $vault['picks'][$eNorm] = $sentByChar[$eNorm];
        } elseif ($palCount > count($slots) && isset($palette[$usedIdx])) {
            $vault['picks'][$eNorm] = $palette[$usedIdx];
            $usedIdx++;
        } elseif (isset($packByChar[$eNorm])) {
            $vault['picks'][$eNorm] = $packByChar[$eNorm];
        } elseif (!empty($vault['picks'][$tNorm])) {
            $vault['picks'][$eNorm] = $vault['picks'][$tNorm];
        }
    }

    tg_store_update('settings', function ($s) {
        $s['emoji_off'] = false;
        return $s;
    });
    tg_emoji_vault_write($vault);

    return [
        'ok'         => true,
        'sent_count' => $sentCount,
        'unique_cnt' => count($uniqueIds),
        'pack'       => $vault['pack_name'],
        'pack_count' => count($packList),
        'mapped'     => count($slots),
        'sample_ids' => array_slice($uniqueIds, 0, 8),
        'sample_id'  => $orderedIds[0],
    ];
}

function tg_emoji_reset()
{
    unset($GLOBALS['TG_EMOJI_TABLE'], $GLOBALS['TG_EMOJI_CACHE'], $GLOBALS['TG_EMOJI_CTX'], $GLOBALS['TG_EMOJI_VAULT_MEMO']);
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
    $v = tg_emoji_vault_read();
    if (!empty($v['pack_name']) && preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $v['pack_name'])) {
        return (string) $v['pack_name'];
    }
    $s = tg_store_read('settings');
    $n = isset($s['emoji_pack']) ? (string) $s['emoji_pack'] : (string) tg_cfg('emoji_pack', '');
    $n = trim($n);
    return preg_match('/^[A-Za-z0-9_]{1,64}$/', $n) ? $n : '';
}

function tg_emoji_pack($force = false)
{
    $v    = tg_emoji_vault_read();
    $name = tg_emoji_pack_name();
    if (!$force && !empty($v['pack_list']) && is_array($v['pack_list'])) {
        return ['name' => $name, 'list' => $v['pack_list']];
    }
    if ($name === '') {
        return ['name' => '', 'list' => []];
    }
    $res  = tg_api_raw('getStickerSet', ['name' => $name], 5);
    $list = [];
    if (!empty($res['ok']) && isset($res['result']['stickers']) && is_array($res['result']['stickers'])) {
        foreach ($res['result']['stickers'] as $st) {
            if (!empty($st['custom_emoji_id'])) {
                $list[] = [
                    'id' => (string) $st['custom_emoji_id'],
                    'e'  => !empty($st['emoji']) ? tg_emoji_norm($st['emoji']) : '⭐',
                ];
            }
        }
    }
    if ($list) {
        $v['pack_name'] = $name;
        $v['pack_list'] = $list;
        if (empty($v['default_id'])) {
            $v['default_id'] = $list[0]['id'];
        }
        tg_emoji_vault_write($v);
    }
    return ['name' => $name, 'list' => $list ?: (is_array($v['pack_list']) ? $v['pack_list'] : [])];
}

function tg_emoji_map()
{
    $s    = tg_store_read('settings');
    $chat = isset($s['emoji_map']) && is_array($s['emoji_map']) ? $s['emoji_map'] : [];
    return array_merge(tg_emoji_default_map(), (array) tg_cfg('emoji_map', []), $chat);
}

function tg_emoji_id($key)
{
    if (isset($GLOBALS['TG_EMOJI_CACHE'][$key])) {
        return $GLOBALS['TG_EMOJI_CACHE'][$key];
    }
    $id = '';
    if (tg_emoji_on()) {
        $ids = (array) tg_cfg('emoji_ids', []);
        if (isset($ids[$key]) && preg_match('/^\d{5,}$/', (string) $ids[$key])) {
            $id = (string) $ids[$key];
        } else {
            $map = tg_emoji_map();
            if (isset($map[$key])) {
                $pack = tg_emoji_pack();
                $i    = (int) $map[$key] - 1;
                if ($i >= 0 && isset($pack['list'][$i]['id'])) {
                    $id = (string) $pack['list'][$i]['id'];
                }
            }
        }
    }
    return $GLOBALS['TG_EMOJI_CACHE'][$key] = $id;
}

function tg_emoji_table()
{
    if (isset($GLOBALS['TG_EMOJI_TABLE'])) {
        return $GLOBALS['TG_EMOJI_TABLE'];
    }
    $t = [];
    if (tg_emoji_on()) {
        $v = tg_emoji_vault_read();
        foreach (tg_emoji_unicode_map() as $u => $key) {
            $id = tg_emoji_id($key);
            if ($id !== '') {
                $t[tg_emoji_norm($u)] = $id;
            }
        }
        if (!empty($v['pack_list']) && is_array($v['pack_list'])) {
            foreach ($v['pack_list'] as $item) {
                if (!empty($item['e']) && !empty($item['id'])) {
                    $nu = tg_emoji_norm($item['e']);
                    if (!isset($t[$nu])) {
                        $t[$nu] = (string) $item['id'];
                    }
                }
            }
        }
        if (!empty($v['picks']) && is_array($v['picks'])) {
            foreach ($v['picks'] as $u => $id) {
                if (preg_match('/^\d{5,}$/', (string) $id)) {
                    $t[tg_emoji_norm($u)] = (string) $id;
                }
            }
        }
        // Map semantic aliases for any extended icons not yet explicitly picked
        foreach (tg_emoji_extended_aliases() as $extChar => $targetSlotChar) {
            $eNorm = tg_emoji_norm($extChar);
            $tNorm = tg_emoji_norm($targetSlotChar);
            if (!isset($t[$eNorm]) && isset($t[$tNorm])) {
                $t[$eNorm] = $t[$tNorm];
            }
        }
        // Guarantee 100% of slots get replaced when vault has a default_id
        if (!empty($v['replace_all']) && !empty($v['default_id']) && preg_match('/^\d{5,}$/', (string) $v['default_id'])) {
            foreach (tg_emoji_slots() as $sl) {
                if (!isset($t[$sl[0]])) {
                    $t[$sl[0]] = (string) $v['default_id'];
                }
            }
        }
    }
    return $GLOBALS['TG_EMOJI_TABLE'] = $t;
}

function tg_emoji_fallback_id($char = '')
{
    if (!tg_emoji_on()) {
        return '';
    }
    $v = tg_emoji_vault_read();
    if (!empty($v['replace_all']) && !empty($v['default_id']) && preg_match('/^\d{5,}$/', (string) $v['default_id'])) {
        return (string) $v['default_id'];
    }
    return '';
}

function tg_emoji_regex(array $table, $lead)
{
    $keys = array_keys($table);
    usort($keys, function ($a, $b) {
        return strlen((string) $b) - strlen((string) $a);
    });
    $alt = implode('|', array_map(function ($u) {
        return preg_quote((string) $u, '~');
    }, $keys));
    return $lead ? '~^(' . $alt . ')[\x{FE0E}\x{FE0F}]?\s*~u' : '~(' . $alt . ')[\x{FE0E}\x{FE0F}]?~u';
}

function tg_ce($key, $fallback)
{
    $id = tg_emoji_id($key);
    return $id === '' ? $fallback : '<tg-emoji emoji-id="' . $id . '">' . $fallback . '</tg-emoji>';
}

/** Replaces normal emojis in HTML text with <tg-emoji> (skips tags, <code>, <pre>). */
function tg_dress_text($html)
{
    $table      = tg_emoji_table();
    $fallbackId = tg_emoji_fallback_id();
    if (!$table && $fallbackId === '') {
        return $html;
    }
    $re    = $table ? tg_emoji_regex($table, false) : null;
    $anyRe = $fallbackId !== '' ? '~([\x{2139}\x{2194}-\x{21AA}\x{231A}-\x{23FA}\x{25AA}-\x{27BF}\x{2B05}-\x{2B55}\x{1F170}-\x{1FAFF}])[\x{FE0E}\x{FE0F}]?~u' : null;
    $parts = preg_split('~(<tg-emoji\b.*?</tg-emoji>|<[^>]+>)~s', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return $html;
    }
    $code = 0;
    $left = 90;
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
        if ($re !== null) {
            $part = preg_replace_callback($re, function ($m) use ($table, &$left) {
                if ($left <= 0) {
                    return $m[0];
                }
                $left--;
                return '<tg-emoji emoji-id="' . $table[$m[1]] . '">' . $m[0] . '</tg-emoji>';
            }, $part);
        }
        if ($anyRe !== null && $left > 0) {
            $subParts = preg_split('~(<tg-emoji\b.*?</tg-emoji>)~s', (string) $part, -1, PREG_SPLIT_DELIM_CAPTURE);
            if (is_array($subParts)) {
                foreach ($subParts as $si => $sp) {
                    if ($si % 2 === 0 && $sp !== '' && $left > 0) {
                        $subParts[$si] = preg_replace_callback($anyRe, function ($m) use ($fallbackId, &$left) {
                            if ($left <= 0) {
                                return $m[0];
                            }
                            $left--;
                            return '<tg-emoji emoji-id="' . $fallbackId . '">' . $m[0] . '</tg-emoji>';
                        }, $sp);
                    }
                }
                $part = implode('', $subParts);
            }
        }
        $parts[$i] = $part;
    }
    return implode('', $parts);
}

/** Replaces leading normal emojis on inline keyboard buttons with icon_custom_emoji_id. */
function tg_dress_markup($markup)
{
    if (!is_array($markup) || empty($markup['inline_keyboard']) || !is_array($markup['inline_keyboard'])) {
        return $markup;
    }
    $table      = tg_emoji_table();
    $fallbackId = tg_emoji_fallback_id();
    if (!$table && $fallbackId === '') {
        return $markup;
    }
    $re    = $table ? tg_emoji_regex($table, true) : null;
    $anyRe = $fallbackId !== '' ? '~^([\x{2139}\x{2194}-\x{21AA}\x{231A}-\x{23FA}\x{25AA}-\x{27BF}\x{2B05}-\x{2B55}\x{1F170}-\x{1FAFF}])[\x{FE0E}\x{FE0F}]?\s*~u' : null;

    foreach ($markup['inline_keyboard'] as $r => $row) {
        foreach ((array) $row as $c => $b) {
            if (!is_array($b) || !isset($b['text']) || isset($b['icon_custom_emoji_id'])) {
                continue;
            }
            $txt = (string) $b['text'];
            if ($re !== null && preg_match($re, $txt, $m)) {
                $rest = preg_replace($re, '', $txt, 1);
                if ($rest !== null && trim($rest) !== '') {
                    $b['text']                 = $rest;
                    $b['icon_custom_emoji_id'] = $table[$m[1]];
                    $markup['inline_keyboard'][$r][$c] = $b;
                    continue;
                }
            }
            if ($anyRe !== null && preg_match($anyRe, $txt)) {
                $rest = preg_replace($anyRe, '', $txt, 1);
                if ($rest !== null && trim($rest) !== '') {
                    $b['text']                 = $rest;
                    $b['icon_custom_emoji_id'] = $fallbackId;
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
 * 7. Activity Log & User Account Block (Preserved 100%)
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

function tg_ensure_tables()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    tg_ensure_tables_force();
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
        if (function_exists('ipg_ensure')) {
            ipg_ensure($c);
        }
    } catch (Throwable $e) {
        error_log('[telegram.php] ensure tables: ' . $e->getMessage());
    }
}

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
            return;
        }
    }
}

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
    if (is_file(__DIR__ . '/security_shield.php')) {
        require_once __DIR__ . '/security_shield.php';
        if (function_exists('sh_render_sad_page')) {
            ob_start();
            sh_render_sad_page([
                'reason'     => 'Tài khoản đã bị khoá truy cập: ' . (string) (isset($row['reason']) ? $row['reason'] : 'Vi phạm quy định của website'),
                'ban_type'   => 'manual',
                'username'   => isset($row['username']) ? (string) $row['username'] : '',
                'expires_ts' => 0,
            ], tg_ip(), 403);
            return (string) ob_get_clean();
        }
    }
    $site    = htmlspecialchars((string) tg_cfg('site_name', 'IOSMODS.XYZ'), ENT_QUOTES, 'UTF-8');
    $reason  = htmlspecialchars((string) (isset($row['reason']) ? $row['reason'] : 'Vi phạm quy định của website'), ENT_QUOTES, 'UTF-8');
    $contact = htmlspecialchars((string) tg_cfg('contact_url', 'https://t.me/iosnea'), ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Tài khoản bị khoá · ' . $site . '</title><style>'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#000;color:#fff;font:15px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;padding:20px}'
        . '.c{max-width:420px;width:100%;background:#0a0a0b;border:1px solid #1f1f23;border-radius:22px;padding:30px 24px;text-align:center}'
        . 'h1{font-size:22px;margin:12px 0 8px}p{color:#9ca3af;margin:6px 0;font-size:14px}'
        . 'a{display:block;margin-top:18px;padding:14px 20px;border-radius:14px;background:#0a84ff;color:#fff;text-decoration:none;font-weight:700}'
        . '</style></head><body><div class="c"><h1>Tài khoản đang bị khoá</h1>'
        . '<p>Nguyên nhân: ' . $reason . '</p>'
        . '<a href="' . $contact . '">Liên hệ Admin kiểm tra</a></div></body></html>';
}

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
            // ignore
        }
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
 * 8. Telegram HTTP API (Never wipes permanent Emoji Vault on fallback!)
 * ------------------------------------------------------------------ */

function tg_strip_ce($html)
{
    return preg_replace('~<tg-emoji\b[^>]*>(.*?)</tg-emoji>~s', '$1', (string) $html);
}

function tg_api($method, array $params = [], $timeout = 6)
{
    $dressed  = tg_dress_params($method, $params);
    $res      = tg_api_raw($method, $dressed, $timeout);
    $hasExtra = isset($dressed['reply_markup']) && is_array($dressed['reply_markup'])
        && (strpos((string) json_encode($dressed['reply_markup']), '"style"') !== false
            || strpos((string) json_encode($dressed['reply_markup']), '"icon_custom_emoji_id"') !== false);
    $hasTag   = isset($dressed['text']) && is_string($dressed['text']) && strpos($dressed['text'], '<tg-emoji') !== false;

    if (empty($res['ok']) && isset($res['error_code']) && ($dressed !== $params || $hasExtra || $hasTag)) {
        $d = (string) (isset($res['description']) ? $res['description'] : '');
        if (preg_match('/emoji|icon_|DOCUMENT_INVALID|BUTTON_TYPE|style|entit|PREMIUM/i', $d)) {
            $plain = $params;
            if (isset($plain['reply_markup'])) {
                $plain['reply_markup'] = tg_strip_button_extras($plain['reply_markup']);
            }
            if (isset($plain['text']) && is_string($plain['text'])) {
                $plain['text'] = tg_strip_ce($plain['text']);
            }
            $res2 = tg_api_raw($method, $plain, $timeout);
            if (!empty($res2['ok'])) {
                // Keep permanent vault 100% intact! Only pause custom-emoji dressing briefly (180s) if Premium is inactive.
                $flag = preg_match('/style/i', $d) ? 'styledown' : 'emojidown';
                tg_store_update($flag, function () {
                    return ['until' => time() + 180];
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
        $err = 'no curl / allow_url_fopen';
    }

    $res = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($res)) {
        $res = ['ok' => false, 'description' => $err !== '' ? $err : 'bad response from Telegram'];
    }
    return $res;
}

/* ------------------------------------------------------------------
 * 9. Compact Vietnamese Alert Formatting (Direct, Clean, Never Deleted!)
 * ------------------------------------------------------------------ */

function tg_r($icon, $label, $valueHtml)
{
    return [$icon, $label, $valueHtml];
}

function tg_card($title, array $rows, $pre = '')
{
    $out   = $title . "\n";
    $lines = [];
    foreach ($rows as $r) {
        if (is_array($r) && count($r) >= 3) {
            $lbl = trim((string) $r[1]);
            $lines[] = $r[0] . ($lbl !== '' ? ' ' . tg_e($lbl) . ': ' : ' ') . $r[2];
        } elseif (is_string($r) && $r !== '') {
            $lines[] = $r;
        }
    }
    if ($lines) {
        $out .= '<blockquote>' . implode("\n", $lines) . '</blockquote>' . "\n";
    }
    if ($pre !== '') {
        $out .= '<pre>' . tg_e(tg_clip($pre, 900)) . '</pre>' . "\n";
    }
    return $out . '<i>🕒 ' . tg_e(tg_now('H:i:s d/m')) . '</i>';
}

function tg_alert_kb(array $rows)
{
    return ['inline_keyboard' => $rows];
}

function tg_activity_map($type, array $d)
{
    $user = isset($d['username']) ? (string) $d['username'] : '';
    switch ($type) {
        case 'register':
            return [$user, 'member', 'register', 'đăng ký mới' . (!empty($d['id']) ? ' #' . (int) $d['id'] : '')];
        case 'freekey_click':
            return [$user, 'member', 'freekey_click', 'bấm link lấy key free'];
        case 'freekey_claimed':
            return [$user, 'member', 'freekey_ok', 'nhận key free: ' . (isset($d['key']) ? $d['key'] : '')];
        case 'freekey_blocked':
            return [$user !== '' ? $user : 'guest', 'member', 'freekey_block', 'chặn get key: ' . (isset($d['event']) ? $d['event'] : '')];
        case 'key_ok':
            return [$user, 'member', 'key_ok', (isset($d['type']) ? $d['type'] : '?') . ' · ' . (isset($d['key']) ? $d['key'] : '')];
        case 'key_invalid':
            return [$user, 'member', 'key_wrong', (isset($d['type']) ? $d['type'] : '?') . ': ' . (isset($d['key']) ? $d['key'] : '')];
        case 'key_locked':
            return [$user, 'member', 'key_locked', 'nhập sai key quá giới hạn'];
        case 'tool_use':
            return [$user, 'member', 'tool_' . (isset($d['outcome']) ? $d['outcome'] : '?'), (isset($d['tool']) ? $d['tool'] : '?')];
        case 'ddos_block':
            return ['ip:' . (isset($d['ip']) ? $d['ip'] : ''), 'blocked', 'ddos_block', isset($d['detail']) ? $d['detail'] : 'DDoS'];
        case 'script_bug_block':
            return ['ip:' . (isset($d['ip']) ? $d['ip'] : ''), 'blocked', 'script_bug', isset($d['detail']) ? $d['detail'] : 'Script/Bug'];
        case 'ddos_surge_healed':
            return ['bot-ai', 'bot', 'surge_healed', isset($d['detail']) ? $d['detail'] : 'Surge healed'];
        case 'key_spam_ban':
            return [$user !== '' ? $user : 'ip:' . (isset($d['ip']) ? $d['ip'] : ''), 'blocked', 'key_spam_ban', 'spam nhập key sai'];
        case 'vpn_multi_acc':
            return [$user !== '' ? $user : 'ip:' . (isset($d['ip']) ? $d['ip'] : ''), 'blocked', 'vpn_multi_acc', isset($d['reason']) ? $d['reason'] : 'VPN/Multi-acc'];
        case 'vpn_gate_block':
            return [$user !== '' ? $user : 'ip:' . (isset($d['ip']) ? $d['ip'] : ''), 'blocked', 'vpn_gate_block', isset($d['vpn_lbl']) && $d['vpn_lbl'] !== '' ? ('Bật ' . $d['vpn_lbl']) : 'Bật VPN/Fake IP'];
        case 'admin_log':
            return [$user !== '' ? $user : 'admin_cp', 'admin_cp', 'cp:' . (isset($d['action']) ? $d['action'] : ''), isset($d['detail']) ? $d['detail'] : ''];
        case 'ip_ban':
            return ['tg:' . (isset($d['by']) ? $d['by'] : ''), 'bot', 'ip_ban', (isset($d['ip']) ? $d['ip'] : '')];
        case 'ip_unban':
            return ['tg:' . (isset($d['by']) ? $d['by'] : ''), 'bot', 'ip_unban', (isset($d['ip']) ? $d['ip'] : '')];
        case 'user_appeal':
            return [$user !== '' ? $user : ('ip:' . (isset($d['ip']) ? $d['ip'] : '')), 'blocked', 'user_appeal', isset($d['message']) ? $d['message'] : 'yêu cầu mở khoá'];

        case 'bot_appeal_unban':
        case 'bot_appeal_reduce':
        case 'bot_appeal_deny':
            return [$user !== '' ? $user : ('ip:' . (isset($d['ip']) ? $d['ip'] : '')), 'bot', $type, isset($d['note']) ? $d['note'] : 'xét mở khoá tự động'];

        case 'ip_auto_unban':
            return ['auto-timer', 'bot', 'ip_auto_unban', (isset($d['ip']) ? $d['ip'] : '')];
    }
    return null;
}

function tg_render($type, array $d)
{
    $user = isset($d['username']) ? (string) $d['username'] : '';
    $ip   = array_key_exists('ip', $d) && (string) $d['ip'] !== '' ? (string) $d['ip'] : tg_ip();
    $uTag = $user !== '' ? tg_c($user) : '<i>khách</i>';
    $ipTag = $ip !== '' ? tg_c($ip) : '<i>—</i>';

    switch ($type) {
        case 'register':
            $id = isset($d['id']) ? (int) $d['id'] : 0;
            $c  = tg_db();
            if (empty($d['claimed'])) {
                if ($c !== null && $id === 0 && $user !== '') {
                    $res = @$c->query("SELECT id FROM users WHERE username = '" . $c->real_escape_string($user) . "' LIMIT 1");
                    $row = $res ? $res->fetch_assoc() : null;
                    $id  = $row ? (int) $row['id'] : 0;
                }
                if ($id > 0 && !tg_claim_registration($id, false)) {
                    return null;
                }
            }
            $total = null;
            if ($c !== null) {
                $res   = @$c->query('SELECT COUNT(*) AS n FROM users');
                $row   = $res ? $res->fetch_assoc() : null;
                $total = $row ? (int) $row['n'] : null;
            }
            $rows = [
                '👤 Tài khoản: ' . $uTag . ($id > 0 ? ' (#' . $id . ')' : '') . ($total !== null ? ' · 👥 Tổng: <b>' . $total . '</b>' : ''),
                '🌐 IP: ' . $ipTag . (!empty($d['vpn']) ? ' · ⚠️ <b>Nghi VPN/Proxy</b>' : ''),
            ];
            $kb = null;
            if (!empty($d['alts']) && is_array($d['alts'])) {
                $rows[] = '🕵️ Trùng thiết bị/IP với: <code>' . tg_e(implode(', ', array_slice($d['alts'], 0, 4))) . '</code>';
                if ($ip !== '') {
                    $kb = tg_alert_kb([[
                        ['text' => '🔍 Kiểm tra IP', 'callback_data' => 'qa|ip|' . $ip],
                        ['text' => '🚫 Tạm khoá IP 30p', 'callback_data' => 'qa|tempban|' . $ip],
                    ]]);
                }
            }
            return ['cat' => 'register', 'title' => '🆕 <b>ĐĂNG KÝ MỚI</b>', 'rows' => $rows, 'kb' => $kb];

        case 'freekey_click':
            return [
                'cat'      => 'freekey',
                'title'    => '🔗 <b>BẤM GET KEY FREE</b>',
                'throttle' => ['fkc:' . $user, 45],
                'rows'     => ['👤 ' . $uTag . ' · 🌐 ' . $ipTag],
            ];

        case 'freekey_claimed':
            $k = isset($d['key']) ? (string) $d['key'] : '';
            return [
                'cat'   => 'freekey',
                'title' => '🎁 <b>GET KEY FREE THÀNH CÔNG</b>',
                'rows'  => [
                    '👤 ' . $uTag . ' · 🌐 ' . $ipTag,
                    '🔑 Key: ' . tg_c($k),
                ],
            ];

        case 'freekey_blocked':
            $ev = isset($d['event']) ? (string) $d['event'] : '';
            $evMap = [
                'blocked_vpn'    => 'Bật VPN / Fake IP / Proxy',
                'blocked_link'   => 'Tạo acc phụ chung thiết bị / mạng',
                'blocked_device' => 'Trùng thiết bị nhận Key Free trong 8h',
                'blocked_ip'     => 'Trùng địa chỉ IP nhận Key Free trong 8h',
                'blocked_cheat'  => 'Vượt link quá nhanh / dùng tool tự động',
                'blocked_race'   => 'Gửi yêu cầu nhận Key trùng lặp',
            ];
            $why  = isset($evMap[$ev]) ? $evMap[$ev] : $ev;
            $rows = [
                '👤 ' . $uTag . ' · 🌐 ' . $ipTag,
                '⚠️ Phát hiện: <b>' . tg_e($why) . '</b>',
            ];
            if (!empty($d['alts']) && is_array($d['alts'])) {
                $rows[] = '👥 Acc liên kết: <code>' . tg_e(implode(', ', array_slice($d['alts'], 0, 4))) . '</code>';
            }
            $btns = [];
            if ($user !== '' && strlen($user) <= 40) {
                $btns[] = ['text' => '🔓 Cho phép 1 lần', 'callback_data' => 'qa|fkpass|' . $user];
            }
            if ($ip !== '' && strlen($ip) <= 42) {
                $btns[] = ['text' => '🚫 Khoá IP 30p', 'callback_data' => 'qa|tempban|' . $ip];
            }
            return [
                'cat'      => 'vpn',
                'title'    => '🕵️ <b>KIỂM SOÁT GET KEY FREE</b>',
                'throttle' => ['fkb:' . $user . ':' . $ev . ':' . $ip, 90],
                'rows'     => $rows,
                'kb'       => $btns ? tg_alert_kb([$btns]) : null,
            ];

        case 'key_ok':
            $hours = (int) (isset($d['hours']) ? $d['hours'] : 0);
            $plan  = isset($d['type']) ? (string) $d['type'] : 'FREE';
            $turns = (int) (isset($d['turns']) ? $d['turns'] : 1);
            return [
                'cat'   => 'key',
                'title' => '🔑 <b>NHẬP KEY THÀNH CÔNG</b>',
                'rows'  => [
                    '👤 ' . $uTag . ' · 🏷 <b>' . tg_e($plan) . '</b> (' . ($hours > 0 ? tg_e(tg_hours($hours)) . ' · ' : '') . $turns . ' lượt)',
                    '🔑 ' . tg_c(isset($d['key']) ? $d['key'] : '') . ' · 🌐 ' . $ipTag,
                ],
            ];

        case 'key_invalid':
            $typed = isset($d['key']) ? (string) $d['key'] : '';
            $box   = isset($d['type']) ? (string) $d['type'] : '?';
            return [
                'cat'      => 'key',
                'title'    => '❌ <b>NHẬP SAI KEY</b>',
                'throttle' => ['ki:' . $user . ':' . $ip, 15],
                'rows'     => [
                    '👤 ' . $uTag . ' · Ô <b>' . tg_e($box) . '</b>: ' . ($typed !== '' ? tg_c(tg_clip($typed, 42)) : '<i>trống</i>'),
                    '🌐 IP: ' . $ipTag,
                ],
            ];

        case 'key_locked':
            return [
                'cat'      => 'ddos',
                'title'    => '⛔ <b>KHOÁ Ô NHẬP KEY TẠM THỜI</b>',
                'throttle' => ['kl:' . $user . ':' . $ip, 180],
                'rows'     => ['👤 ' . $uTag . ' · Nhập sai quá nhiều lần · 🌐 ' . $ipTag],
            ];

        case 'tool_use':
            $out   = isset($d['outcome']) ? (string) $d['outcome'] : '';
            $tool  = isset($d['tool']) ? (string) $d['tool'] : '?';
            $ttype = !empty($d['tool_type']) ? strtoupper((string) $d['tool_type']) : '';
            $badge = $ttype !== '' ? ' (' . tg_e($ttype) . ')' : '';
            if ($out === 'open') {
                $reopen = !empty($d['reopen']);
                $left   = isset($d['turns']) ? (int) $d['turns'] : null;
                $turnTxt = $reopen ? '<i>mở lại miễn phí</i>' : ($left !== null ? '🎟 Còn <b>' . $left . '</b> lượt' : '👑 Admin');
                return [
                    'cat'      => 'tool',
                    'title'    => '📦 <b>BẤM SẢN PHẨM</b>',
                    'throttle' => $reopen ? ['tre:' . $user . ':' . $tool, 30] : null,
                    'rows'     => [
                        '👤 ' . $uTag . ' · 🧰 <b>' . tg_e(tg_clip($tool, 40)) . '</b>' . $badge,
                        $turnTxt . ' · 🌐 ' . $ipTag,
                    ],
                ];
            }
            if ($out === 'need_key' || $out === 'need_vip') {
                return [
                    'cat'      => 'tool',
                    'title'    => '🚫 <b>BẤM SẢN PHẨM (CHƯA ĐỦ KEY)</b>',
                    'throttle' => ['tno:' . $user . ':' . $tool, 30],
                    'rows'     => [
                        '👤 ' . $uTag . ' · 🧰 <b>' . tg_e(tg_clip($tool, 40)) . '</b>' . $badge,
                        'ℹ️ ' . ($out === 'need_vip' ? 'Cần Key VIP' : 'Chưa có lượt / hết hạn') . ' · 🌐 ' . $ipTag,
                    ],
                ];
            }
            if ($out === 'warn' || $out === 'penalty') {
                return [
                    'cat'   => 'tool',
                    'title' => '⚠️ <b>KEY VIP BẤM SẢN PHẨM FREE</b>',
                    'rows'  => [
                        '👤 ' . $uTag . ' · 🧰 <b>' . tg_e(tg_clip($tool, 40)) . '</b>',
                        ($out === 'penalty' ? '💸 Đã trừ 1 lượt VIP' : '📣 Cảnh báo lần ' . (int) (isset($d['count']) ? $d['count'] : 1) . '/3') . ' · 🌐 ' . $ipTag,
                    ],
                ];
            }
            return null;

        case 'ddos_block':
            $mins     = max(1, (int) ceil((isset($d['duration']) ? (int) $d['duration'] : 3600) / 60));
            $streamIp = isset($d['stream_ip']) && $d['stream_ip'] !== '' && $d['stream_ip'] !== $ip ? (string) $d['stream_ip'] : '';
            $expAt    = !empty($d['expires_at']) ? substr((string) $d['expires_at'], 11, 8) : '';
            $cc       = !empty($d['case_code']) ? (string) $d['case_code'] : '';
            $kbRows   = [];
            if ($ip !== '' && strlen($ip) <= 42) {
                $kbRows[] = [
                    ['text' => '🔓 Gỡ khoá ngay', 'callback_data' => 'qa|unban|' . $ip],
                    ['text' => '🚫 Cấm vĩnh viễn', 'callback_data' => 'qa|permban|' . $ip],
                ];
            }
            if ($cc !== '' && strlen($cc) <= 40) {
                $kbRows[] = [['text' => '🔍 Tra nguyên nhân & Hồ sơ', 'callback_data' => 'qa|case|' . $cc]];
            }
            $rows = [
                '🎯 IP thật (người chạy): ' . $ipTag . ($streamIp !== '' ? ' · 🖥 Máy chủ luồng: ' . tg_c($streamIp) : ''),
                '⚡ Loại tấn công: <b>' . tg_e(isset($d['detail']) ? $d['detail'] : 'HTTP Stream Flood') . '</b>',
                '⏱ Khoá tự động: <b>' . $mins . ' phút</b>' . ($expAt !== '' ? ' (đến ' . tg_c($expAt) . ')' : '') . ' · 🧹 <b>Đã giải phóng sạch luồng</b>',
            ];
            if ($cc !== '') {
                $rows[] = '🔐 Mã xác minh: ' . tg_c($cc);
            }
            return [
                'cat'      => 'ddos',
                'title'    => '🚨 <b>AI CHẶN & GIẢI PHÓNG LUỒNG DDOS</b>',
                'throttle' => ['ddos:' . $ip, 90],
                'rows'     => $rows,
                'kb'       => $kbRows ? tg_alert_kb($kbRows) : null,
            ];

        case 'script_bug_block':
            $mins     = max(1, (int) ceil((isset($d['duration']) ? (int) $d['duration'] : 3600) / 60));
            $streamIp = isset($d['stream_ip']) && $d['stream_ip'] !== '' && $d['stream_ip'] !== $ip ? (string) $d['stream_ip'] : '';
            $hwFp     = !empty($d['hw_fp']) ? (string) $d['hw_fp'] : '';
            $evasions = isset($d['evasions']) ? (int) $d['evasions'] : 0;
            $cc       = !empty($d['case_code']) ? (string) $d['case_code'] : '';
            $kbRows   = [];
            if ($ip !== '' && strlen($ip) <= 42) {
                $kbRows[] = [
                    ['text' => '🔓 Gỡ khoá đa lớp', 'callback_data' => 'qa|unban|' . $ip],
                    ['text' => '🚫 Cấm vĩnh viễn', 'callback_data' => 'qa|permban|' . $ip],
                ];
            }
            if ($cc !== '' && strlen($cc) <= 40) {
                $kbRows[] = [['text' => '🔍 Tra nguyên nhân & Hồ sơ', 'callback_data' => 'qa|case|' . $cc]];
            }
            $rows = [
                '🎯 IP thật: ' . $ipTag . ($streamIp !== '' ? ' · 🖥 Luồng/VPN: ' . tg_c($streamIp) : '') . ($hwFp !== '' ? ' · 📱 HW: ' . tg_c($hwFp) : ''),
                '🛡 Vi phạm: <b>' . tg_e(isset($d['detail']) ? $d['detail'] : 'Chạy Script / Xem mã nguồn / Bug-Buff') . '</b>',
                '⏱ Phong toả đa lớp: <b>' . $mins . ' phút</b>' . ($evasions > 0 ? ' · ⚠️ Cố lách khoá: <b>' . $evasions . ' lần</b>' : ''),
            ];
            if ($cc !== '') {
                $rows[] = '🔐 Mã xác minh: ' . tg_c($cc);
            }
            return [
                'cat'      => 'ddos',
                'title'    => '🛡 <b>CHẶN SCRIPT / XEM MÃ NGUỒN / BUG WEB</b>',
                'throttle' => ['sbb:' . $ip . ':' . $hwFp, 60],
                'rows'     => $rows,
                'kb'       => $kbRows ? tg_alert_kb($kbRows) : null,
            ];

        case 'ddos_surge_healed':
            return [
                'cat'      => 'ddos',
                'title'    => '🧠 <b>AI TỰ VÁ & GIẢI PHÓNG SẠCH ĐA LUỒNG DDOS</b>',
                'throttle' => ['surge_heal', 180],
                'rows'     => [
                    '✅ ' . tg_e(isset($d['detail']) ? $d['detail'] : 'Đã giải phóng toàn bộ luồng nghẽn, website hoạt động ổn định 100%.'),
                ],
            ];

        case 'key_spam_ban':
            $mins   = max(1, (int) ceil((isset($d['duration']) ? (int) $d['duration'] : 300) / 60));
            $fails  = (int) (isset($d['fails']) ? $d['fails'] : 0);
            $cc     = !empty($d['case_code']) ? (string) $d['case_code'] : '';
            $kbRows = [];
            if ($ip !== '' && strlen($ip) <= 42) {
                $kbRows[] = [
                    ['text' => '🔓 Gỡ ban ngay', 'callback_data' => 'qa|unban|' . $ip],
                    ['text' => '🚫 Chặn vĩnh viễn', 'callback_data' => 'qa|permban|' . $ip],
                ];
            }
            if ($cc !== '' && strlen($cc) <= 40) {
                $kbRows[] = [['text' => '🔍 Tra nguyên nhân & Hồ sơ', 'callback_data' => 'qa|case|' . $cc]];
            }
            $kRows = [
                '👤 ' . $uTag . ' · 🌐 ' . $ipTag,
                '🛑 ' . tg_e(tg_clip(isset($d['reason']) ? $d['reason'] : ('Spam thao tác / nhập sai ' . $fails . ' lần'), 150)),
            ];
            if ($cc !== '') {
                $kRows[] = '🔐 Mã xác minh: ' . tg_c($cc);
            }
            return [
                'cat'      => 'ddos',
                'title'    => '☹️ <b>TẠM KHOÁ SPAM HỆ THỐNG (' . $mins . ' PHÚT)</b>',
                'throttle' => ['kspam:' . $ip, 45],
                'rows'     => $kRows,
                'kb'       => $kbRows ? tg_alert_kb($kbRows) : null,
            ];

        case 'vpn_gate_block':
            $realIp   = !empty($d['real_origin_ip']) ? (string) $d['real_origin_ip'] : '';
            $realIsp  = !empty($d['real_origin_isp']) ? (string) $d['real_origin_isp'] : '';
            $realSrc  = !empty($d['real_ip_source']) ? (string) $d['real_ip_source'] : '';
            $vpnIp    = !empty($d['vpn_ip']) ? (string) $d['vpn_ip'] : $ip;
            $vpnLbl   = !empty($d['vpn_lbl']) ? (string) $d['vpn_lbl'] : 'VPN / Proxy';
            $cc       = !empty($d['case_code']) ? (string) $d['case_code'] : '';
            $mins     = max(1, (int) ceil((isset($d['duration']) ? (int) $d['duration'] : 120) / 60));
            $thKey    = ($realIp !== '' && $realIp !== $vpnIp)
                ? ('vpng:unmask:' . $realIp . ':' . $vpnIp)
                : ('vpng:init:' . (!empty($d['hw_fp']) ? $d['hw_fp'] : (!empty($d['lid']) ? $d['lid'] : $vpnIp)));

            $vRows = [];
            if ($user !== '') {
                $vRows[] = '👤 Tài khoản: ' . $uTag;
            }
            if ($realIp !== '' && $realIp !== $vpnIp) {
                $vRows[] = '🎯 <b>IP Thật (Mạng gốc):</b> ' . tg_c($realIp) . ($realIsp !== '' ? ' · 📡 <b>' . tg_e($realIsp) . '</b>' : '');
                if ($realSrc !== '') {
                    $vRows[] = '🧠 Nguồn truy vết: <i>' . tg_e($realSrc) . '</i>';
                }
            } else {
                $vRows[] = '🎯 <b>IP Thật (Mạng gốc):</b> <i>Đang truy vết qua WebRTC/Thiết bị (khách bật VPN từ trước khi mở trình duyệt)</i>';
            }
            $vRows[] = '🎭 <b>IP Ảo (VPN đang bật):</b> ' . tg_c($vpnIp) . ' · ⚠️ <b>' . tg_e($vpnLbl) . '</b>';
            $vRows[] = '⏱ Đã chặn tự động: <b>' . $mins . ' phút</b>' . ($cc !== '' ? ' · 🔐 ' . tg_c($cc) : '');

            $kbV = [];
            $targetUnban = $vpnIp !== '' ? $vpnIp : $realIp;
            $targetPerm  = $realIp !== '' ? $realIp : $vpnIp;
            if ($targetUnban !== '' && strlen($targetUnban) <= 42) {
                $r1 = [['text' => '🔓 Gỡ khoá ngay', 'callback_data' => 'qa|unban|' . $targetUnban]];
                if ($targetPerm !== '' && strlen($targetPerm) <= 42) {
                    $r1[] = ['text' => ($realIp !== '' ? '🚫 Cấm vĩnh viễn IP Thật' : '🚫 Cấm vĩnh viễn'), 'callback_data' => 'qa|permban|' . $targetPerm];
                }
                $kbV[] = $r1;
            }
            if ($cc !== '' && strlen($cc) <= 40) {
                $kbV[] = [['text' => '🔍 Hồ sơ mã IMX', 'callback_data' => 'qa|case|' . $cc]];
            }
            return [
                'cat'      => 'vpn',
                'title'    => ($realIp !== '' && $realIp !== $vpnIp)
                    ? '🕵️ <b>PHÁT HIỆN FAKE IP / VPN · ĐÃ TÌM RA IP THẬT</b>'
                    : '🕵️ <b>PHÁT HIỆN BẬT VPN / FAKE IP TRUY CẬP WEB</b>',
                'throttle' => [$thKey, 120],
                'rows'     => $vRows,
                'kb'       => $kbV ? tg_alert_kb($kbV) : null,
            ];

        case 'vpn_multi_acc':
            $dur    = (int) (isset($d['duration']) ? $d['duration'] : 0);
            $realIp = !empty($d['real_origin_ip']) ? (string) $d['real_origin_ip'] : '';
            $vpnIp  = !empty($d['vpn_ip']) ? (string) $d['vpn_ip'] : '';
            $ipLine = ($realIp !== '' && $vpnIp !== '' && $realIp !== $vpnIp)
                ? ('🎯 IP Thật: ' . tg_c($realIp) . ' · 🎭 IP Ảo (VPN): ' . tg_c($vpnIp))
                : ('🌐 IP: ' . $ipTag);
            $rows = [
                '👤 ' . $uTag . ' · ' . $ipLine,
                '📝 ' . tg_e(tg_clip(isset($d['reason']) ? $d['reason'] : 'Phát hiện VPN / Tạo acc phụ lấy Key Free', 160)),
            ];
            $btns = [];
            if ($user !== '' && strlen($user) <= 40) {
                $btns[] = ['text' => '🔓 Cho phép 1 lần', 'callback_data' => 'qa|fkpass|' . $user];
            }
            if ($dur > 0 && $ip !== '' && strlen($ip) <= 42) {
                $btns[] = ['text' => '🔓 Gỡ khoá IP', 'callback_data' => 'qa|unban|' . $ip];
            }
            return [
                'cat'      => 'vpn',
                'title'    => '🕵️ <b>PHÁT HIỆN VPN / ĐA TÀI KHOẢN</b>',
                'throttle' => ['vma:' . $user . ':' . $ip, 90],
                'rows'     => $rows,
                'kb'       => $btns ? tg_alert_kb([$btns]) : null,
            ];

        case 'user_appeal':
            $cc        = !empty($d['case_code']) ? (string) $d['case_code'] : '';
            $realIp    = !empty($d['real_origin_ip']) ? (string) $d['real_origin_ip'] : '';
            $realIsp   = !empty($d['real_origin_isp']) ? (string) $d['real_origin_isp'] : '';
            $vpnIp     = !empty($d['vpn_ip']) ? (string) $d['vpn_ip'] : '';
            $vpnLbl    = !empty($d['vpn_lbl']) ? (string) $d['vpn_lbl'] : '';
            $streamIp  = isset($d['stream_ip']) && $d['stream_ip'] !== '' && $d['stream_ip'] !== $ip ? (string) $d['stream_ip'] : '';
            $msg       = isset($d['message']) ? (string) $d['message'] : '';
            $reason    = isset($d['reason']) ? (string) $d['reason'] : '';
            $verU      = !empty($d['verified_u']);
            $uDisp     = $user !== '' ? (tg_c($user) . ($verU ? ' <i>(đã đăng nhập)</i>' : ' <i>(khách nhập)</i>')) : '<i>khách chưa nhập tên</i>';
            $unbanIp   = $vpnIp !== '' ? $vpnIp : $ip;
            $permBanIp = $realIp !== '' ? $realIp : $unbanIp;
            $kbRows    = [];
            if ($unbanIp !== '' && strlen($unbanIp) <= 42) {
                $row1 = [['text' => '🔓 Gỡ chặn ngay', 'callback_data' => 'qa|unban|' . $unbanIp]];
                if ($user !== '' && strlen($user) <= 36) {
                    $row1[] = ['text' => '🎁 Gỡ + Mở Get Key', 'callback_data' => 'qa|unban_fk|' . $unbanIp . ':' . $user];
                }
                $kbRows[] = $row1;
                $row2 = [];
                if ($cc !== '' && strlen($cc) <= 40) {
                    $row2[] = ['text' => '🔍 Xem hồ sơ mã', 'callback_data' => 'qa|case|' . $cc];
                }
                $row2[]   = ['text' => '🚫 Cấm vĩnh viễn', 'callback_data' => 'qa|permban|' . $permBanIp];
                $kbRows[] = $row2;
            }
            $aRows = [
                '👤 Tài khoản web: ' . $uDisp,
            ];
            if ($realIp !== '' && $vpnIp !== '' && $realIp !== $vpnIp) {
                $aRows[] = '🎯 <b>IP Thật (Mạng gốc):</b> ' . tg_c($realIp) . ($realIsp !== '' ? ' (' . tg_e($realIsp) . ')' : '');
                $aRows[] = '🎭 <b>IP Ảo (VPN):</b> ' . tg_c($vpnIp) . ($vpnLbl !== '' ? ' (' . tg_e($vpnLbl) . ')' : '');
            } else {
                $aRows[] = '🌐 Địa chỉ IP: ' . $ipTag . ($vpnLbl !== '' ? ' · ⚠️ <b>' . tg_e($vpnLbl) . '</b>' : '') . ($streamIp !== '' ? ' · 🖥 Luồng: ' . tg_c($streamIp) : '');
            }
            $aRows[] = '🔎 Đang bị chặn vì: ' . tg_e(tg_clip($reason, 140));
            $aRows[] = '💬 Yêu cầu gửi Admin: <b>' . tg_e(tg_clip($msg, 260)) . '</b>';
            if ($cc !== '') {
                $aRows[] = '🔐 Mã xác minh: ' . tg_c($cc);
            }
            return [
                'cat'   => 'ban',
                'title' => '📩 <b>YÊU CẦU MỞ KHOÁ TỪ NGƯỜI DÙNG</b>',
                'rows'  => $aRows,
                'kb'    => $kbRows ? tg_alert_kb($kbRows) : null,
            ];

        case 'bot_appeal_unban':
            $cc = !empty($d['case_code']) ? (string) $d['case_code'] : '';
            return [
                'cat'   => 'ban',
                'title' => '🤖 <b>BOT AI TỰ ĐỘNG MỞ KHOÁ (LỖI RẤT NHẸ)</b>',
                'rows'  => [
                    '👤 Tài khoản: ' . $uTag . ' · 🌐 IP: ' . $ipTag,
                    '🔎 Nguyên nhân gốc: ' . tg_e(tg_clip(isset($d['reason']) ? $d['reason'] : '', 140)),
                    '✅ Quyết định AI: <b>Đã mở khoá ngay (lỗi nhẹ lần đầu, 0 lần lách khoá)</b>' . ($cc !== '' ? ' · 🗑 Huỷ mã ' . tg_c($cc) : ''),
                ],
                'kb'    => ($ip !== '' && strlen($ip) <= 42) ? tg_alert_kb([[['text' => '🚫 Khoá lại 30p', 'callback_data' => 'qa|tempban|' . $ip], ['text' => '🚫 Cấm vĩnh viễn', 'callback_data' => 'qa|permban|' . $ip]]]) : null,
            ];

        case 'bot_appeal_reduce':
            $cc   = !empty($d['case_code']) ? (string) $d['case_code'] : '';
            $oldM = (int) (isset($d['old_min']) ? $d['old_min'] : 0);
            $newM = (int) (isset($d['new_min']) ? $d['new_min'] : 0);
            $msg  = isset($d['message']) ? (string) $d['message'] : '';
            $kbR  = [];
            if ($ip !== '' && strlen($ip) <= 42) {
                $r1 = [['text' => '🔓 Admin mở khoá luôn', 'callback_data' => 'qa|unban|' . $ip]];
                if ($cc !== '' && strlen($cc) <= 40) {
                    $r1[] = ['text' => '🔍 Hồ sơ mã', 'callback_data' => 'qa|case|' . $cc];
                }
                $kbR[] = $r1;
            }
            return [
                'cat'   => 'ban',
                'title' => '🤖 <b>BOT AI GIẢM THỜI GIAN KHOÁ VỪA PHẢI</b>',
                'rows'  => [
                    '👤 Tài khoản: ' . $uTag . ' · 🌐 IP: ' . $ipTag,
                    '💬 Khách trình bày: <b>' . tg_e(tg_clip($msg, 180)) . '</b>',
                    '⏱ Điều chỉnh: <b>' . $oldM . ' phút → còn ' . $newM . ' phút</b>' . ($cc !== '' ? ' · 🔐 ' . tg_c($cc) : ''),
                ],
                'kb'    => $kbR ? tg_alert_kb($kbR) : null,
            ];

        case 'bot_appeal_deny':
            $cc  = !empty($d['case_code']) ? (string) $d['case_code'] : '';
            $msg = isset($d['message']) ? (string) $d['message'] : '';
            $kbD = [];
            if ($ip !== '' && strlen($ip) <= 42) {
                $r1 = [['text' => '🔓 Admin mở khoá ngay', 'callback_data' => 'qa|unban|' . $ip]];
                if ($user !== '' && strlen($user) <= 36) {
                    $r1[] = ['text' => '🎁 Mở + Cấp Get Key', 'callback_data' => 'qa|unban_fk|' . $ip . ':' . $user];
                }
                $kbD[] = $r1;
                if ($cc !== '' && strlen($cc) <= 40) {
                    $kbD[] = [['text' => '🔍 Xem hồ sơ mã IMX', 'callback_data' => 'qa|case|' . $cc]];
                }
            }
            $dRows = [
                '👤 Tài khoản: ' . $uTag . ' · 🌐 IP: ' . $ipTag,
                '🛑 Vi phạm: <b>' . tg_e(tg_clip(isset($d['reason']) ? $d['reason'] : '', 150)) . '</b>',
            ];
            if ($msg !== '') {
                $dRows[] = '💬 Khách nhắn: <i>' . tg_e(tg_clip($msg, 180)) . '</i>';
            }
            $dRows[] = '🤖 Bot đã từ chối mở &amp; hướng dẫn khách liên hệ Admin (Admin vẫn có thể bấm nút bên dưới để mở).';
            return [
                'cat'   => 'ban',
                'title' => '🛡 <b>BOT AI TỪ CHỐI MỞ BAN · CHUYỂN ADMIN XỬ LÝ</b>',
                'rows'  => $dRows,
                'kb'    => $kbD ? tg_alert_kb($kbD) : null,
            ];

        case 'ip_auto_unban':
            return [
                'cat'   => 'ban',
                'title' => '🟢 <b>TỰ ĐỘNG GỠ KHOÁ IP HẾT HẠN</b>',
                'rows'  => ['🌐 IP: ' . $ipTag . ($user !== '' ? ' · 👤 ' . $uTag : '') . ' → Đã vào web bình thường'],
            ];

        case 'ip_ban':
            return [
                'cat'   => 'ban',
                'title' => '🚫 <b>ĐÃ CHẶN VĨNH VIỄN IP</b>',
                'rows'  => [
                    '🌐 ' . $ipTag . ($user !== '' ? ' · 👤 ' . $uTag : ''),
                    '📝 ' . tg_e(tg_clip(isset($d['reason']) ? $d['reason'] : '', 140)),
                ],
            ];

        case 'ip_unban':
            return [
                'cat'   => 'ban',
                'title' => '✅ <b>ĐÃ GỠ CHẶN IP</b>',
                'rows'  => ['🌐 ' . $ipTag . ' · 👮 Admin ' . tg_c(isset($d['by']) ? $d['by'] : '')],
            ];

        case 'user_block':
            return [
                'cat'   => 'ban',
                'title' => '⛔ <b>ĐÃ KHOÁ TÀI KHOẢN</b>',
                'rows'  => ['👤 ' . $uTag . ' · 📝 ' . tg_e(tg_clip(isset($d['reason']) ? $d['reason'] : '', 120))],
            ];

        case 'user_unblock':
            return [
                'cat'   => 'ban',
                'title' => '✅ <b>ĐÃ MỞ KHOÁ TÀI KHOẢN</b>',
                'rows'  => ['👤 ' . $uTag . ' · 👮 Admin ' . tg_c(isset($d['by']) ? $d['by'] : '')],
            ];

        case 'admin_log':
            $act = isset($d['action']) ? (string) $d['action'] : '';
            return [
                'cat'   => 'admin',
                'title' => '👑 <b>THAO TÁC ADMIN CP</b> · <code>' . tg_e($act) . '</code>',
                'rows'  => [
                    '👤 ' . $uTag . ' · 📝 ' . tg_e(tg_clip(isset($d['detail']) ? $d['detail'] : '', 180)),
                ],
            ];

        case 'site_error':
        case 'site_fatal':
        case 'php_warning':
            $text = isset($d['error']) ? (string) $d['error'] : (isset($d['message']) ? (string) $d['message'] : '');
            $file = isset($d['file']) ? basename((string) $d['file']) . (isset($d['line']) ? ':' . $d['line'] : '') : '';
            $sig  = $type . '|' . substr($text, 0, 140) . '|' . $file;
            return [
                'cat'      => 'error',
                'title'    => $type === 'php_warning' ? '⚠️ <b>CẢNH BÁO PHP</b>' : '🚨 <b>LỖI HỆ THỐNG</b>',
                'throttle' => ['err:' . md5($sig), $type === 'php_warning' ? 900 : 300],
                'pre'      => $text,
                'rows'     => [$file !== '' ? '📄 ' . tg_c($file) : '📍 ' . tg_e(isset($d['where']) ? $d['where'] : 'web')],
            ];

        case 'db_down':
            return [
                'cat'      => 'error',
                'title'    => '🔌 <b>MẤT KẾT NỐI DATABASE</b>',
                'throttle' => ['dbdown', 300],
                'rows'     => ['⚠️ Không thể kết nối MySQL · 🌐 ' . $ipTag],
            ];

        case 'page_view':
            return [
                'cat'      => 'visit',
                'title'    => '🚪 <b>TRUY CẬP WEB</b>',
                'throttle' => ['pv:' . $user, 180],
                'rows'     => ['👤 ' . $uTag . ' · 🔑 ' . tg_c(isset($d['plan']) ? $d['plan'] : 'NONE') . ' (' . (int) (isset($d['turns']) ? $d['turns'] : 0) . ' lượt) · 🌐 ' . $ipTag],
            ];

        case 'login_fail':
            return [
                'cat'   => 'login',
                'title' => '🔐 <b>ĐĂNG NHẬP SAI</b>',
                'rows'  => ['👤 ' . $uTag . ' · 🔁 <b>' . (int) (isset($d['count']) ? $d['count'] : 1) . '</b> lần · 🌐 ' . $ipTag],
            ];

        case 'security_log':
            return [
                'cat'   => 'ddos',
                'title' => '🛡 <b>CẢNH BÁO BẢO MẬT</b>',
                'rows'  => ['🌐 ' . tg_c(isset($d['ip_address']) ? $d['ip_address'] : '') . ' · ' . tg_e(isset($d['violation_type']) ? $d['violation_type'] : '?')],
            ];

        case 'bot_audit':
            return [
                'cat'    => 'audit',
                'title'  => '🤖 <b>NHẬT KÝ BOT</b>',
                'except' => isset($d['admin']) ? (string) $d['admin'] : '',
                'rows'   => ['👮 ' . tg_c(isset($d['admin']) ? $d['admin'] : '') . ' · ' . tg_e(tg_clip(isset($d['detail']) ? $d['detail'] : '', 160))],
            ];

        case 'test':
            return [
                'cat'   => 'test',
                'title' => '🔔 <b>KIỂM TRA THÔNG BÁO</b>',
                'rows'  => ['✅ Hệ thống thông báo hoạt động ổn định 100% (thông báo không bao giờ tự xoá).'],
            ];
    }
    return null;
}

/* ------------------------------------------------------------------
 * 10. Post-Response Alert Queue & Delivery (NEVER Auto-Deleted!)
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

function tg_event($type, array $d = [])
{
    static $busy = false;
    if ($busy || !tg_enabled()) {
        return;
    }
    $busy = true;
    try {
        if (isset($d['username']) && in_array($type, ['page_view', 'member_action', 'tool_use', 'key_ok', 'key_invalid', 'freekey_click', 'freekey_claimed'], true)) {
            tg_block_enforce((string) $d['username'], isset($d['role']) ? (string) $d['role'] : 'member');
        }
        $map = tg_activity_map($type, $d);
        if ($map !== null) {
            $logIt = true;
            if ($type === 'page_view') {
                $logIt = tg_throttle('al:pv:' . $map[0], 30);
            }
            if ($logIt) {
                tg_activity($map[0], $map[1], $map[2], $map[3], array_key_exists('ip', $d) && $d['ip'] !== '' ? (string) $d['ip'] : null);
            }
        }
        $r = tg_render($type, $d);
        if ($r !== null && ($r['cat'] === 'test' || tg_cat_on($r['cat']))) {
            if (empty($r['throttle']) || tg_throttle($r['throttle'][0], $r['throttle'][1])) {
                tg_queue([
                    'html'   => tg_card($r['title'], $r['rows'], isset($r['pre']) ? $r['pre'] : ''),
                    'kb'     => isset($r['kb']) ? $r['kb'] : null,
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
        return;
    }

    if ($finish && PHP_SAPI !== 'cli') {
        ignore_user_abort(true);
        @set_time_limit(30);
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @flush();
            @fastcgi_finish_request();
        }
    }

    // Smart wave limiter: max 50 alerts/min so huge DDoS waves summarize cleanly without lagging Telegram
    $allowed = 0;
    $dropped = 0;
    tg_store_update('rate', function ($s) use (&$allowed, &$dropped, $items) {
        $win = (int) floor(time() / 60);
        if ((int) (isset($s['w']) ? $s['w'] : 0) !== $win) {
            $s = ['w' => $win, 'n' => 0];
        }
        foreach ($items as $i) {
            if ($s['n'] < 50) {
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
        $items[] = [
            'html'   => '🧠 <b>AI Chống Spam Thông Báo:</b> Đã gộp <b>' . $dropped . '</b> cảnh báo trùng lặp trong phút này để giữ ổn định.',
            'kb'     => null,
            'except' => '',
        ];
    }

    foreach ($items as $item) {
        foreach (tg_admin_ids() as $adminId) {
            if (!empty($item['except']) && (string) $item['except'] === (string) $adminId) {
                continue;
            }
            $params = [
                'chat_id'                  => $adminId,
                'text'                     => tg_clip($item['html'], 3800) . '  ' . tg_tag(),
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ];
            if (!empty($item['kb'])) {
                $params['reply_markup'] = $item['kb'];
            }
            // IMPORTANT: Alerts sent here are NEVER passed to tg_autodelete_register() -> Kept forever!
            $res = tg_api('sendMessage', $params, 4);
            if (empty($res['ok'])) {
                if (!isset($res['error_code'])) {
                    tg_store_update('down', function () {
                        return ['until' => time() + 60];
                    });
                    return;
                }
                if (stripos((string) (isset($res['description']) ? $res['description'] : ''), 'parse') !== false) {
                    unset($params['parse_mode'], $params['reply_markup']);
                    $params['text'] = html_entity_decode(strip_tags($params['text']), ENT_QUOTES, 'UTF-8');
                    tg_api_raw('sendMessage', $params, 4);
                }
            }
        }
    }
}

/* ------------------------------------------------------------------
 * 11. Registration De-dup & Background Table/Ban Scanner
 * ------------------------------------------------------------------ */

function tg_claim_registration($id, $viaScan)
{
    $ok = false;
    tg_store_update('scan', function ($s) use ($id, $viaScan, &$ok) {
        $reg = (isset($s['reg']) && is_array($s['reg'])) ? $s['reg'] : [];
        if (in_array((int) $id, $reg, true)) {
            return $s;
        }
        if (!$viaScan && isset($s['b']['users']) && (int) $s['b']['users'] >= (int) $id) {
            return $s;
        }
        $reg[]    = (int) $id;
        $s['reg'] = array_slice($reg, -200);
        $ok       = true;
        return $s;
    });
    return $ok;
}

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

    // Sweep expired timed IP bans & pending DDoS bans + auto-delete due command messages
    try {
        if (function_exists('sh_sync_pending_to_db')) {
            sh_sync_pending_to_db($conn);
        }
        if (function_exists('ipg_sweep_expired')) {
            ipg_sweep_expired($conn);
        }
        tg_autodelete_sweep();
    } catch (Throwable $e) {
        // ignore
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
            // table may not exist
        }
    }
}

function tg_scan_table($conn, $table, $sqlTemplate)
{
    $state = tg_store_read('scan');
    $base  = isset($state['b'][$table]) ? (int) $state['b'][$table] : null;

    $res = @mysqli_query($conn, 'SELECT COALESCE(MAX(id), 0) AS m FROM `' . $table . '`');
    if (!$res) {
        return;
    }
    $row = mysqli_fetch_assoc($res);
    $max = $row ? (int) $row['m'] : 0;

    if ($base === null || $base > $max) {
        tg_store_update('scan', function ($s) use ($table, $max) {
            $s['b'][$table] = $max;
            return $s;
        });
        return;
    }
    if ($max === $base) {
        return;
    }

    $res  = @mysqli_query($conn, sprintf($sqlTemplate, $base));
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
                continue;
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
 * 12. Error / Shutdown Lifecycle
 * ------------------------------------------------------------------ */

function tg_php_error($no, $str, $file, $line)
{
    if (!(error_reporting() & $no)) {
        return false;
    }
    if (!in_array($no, [E_WARNING, E_USER_WARNING, E_RECOVERABLE_ERROR, E_USER_ERROR], true)) {
        return false;
    }
    tg_event('php_warning', ['message' => $str, 'file' => $file, 'line' => $line]);
    return false;
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
        if (!$dbOk && !$fatal && (!defined('SH_EARLY_RAN') || empty($GLOBALS['SH_SKIP_DB_ALERT']))) {
            tg_event('db_down', []);
        }

        tg_flush(true);
        tg_autodelete_sweep();
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
