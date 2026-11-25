<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
include 'db.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Optional settings file (kept on the server, never overwritten by updates)
if (is_file(__DIR__ . '/fk_config.php')) { include_once __DIR__ . '/fk_config.php'; }

// Main site: every link and button points here. The visitor's own domain is used when it is in the allowed list.
if (!defined('FK_MAIN_WEB')) define('FK_MAIN_WEB', 'https://iosmods.xyz');
if (!defined('FK_ALLOWED_HOSTS')) define('FK_ALLOWED_HOSTS', 'iosmods.xyz,www.iosmods.xyz');
$main_web = rtrim(FK_MAIN_WEB, '/');
$fk_host  = strtolower(isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) : '');
if ($fk_host !== '' && in_array($fk_host, array_map('trim', explode(',', FK_ALLOWED_HOSTS)), true)) {
    $main_web = 'https://' . $fk_host;
}

/* =====================================================================
   CONFIG
   ===================================================================== */
// Secret string used to encrypt keys. CHANGE it to your own long random string.
if (!defined('FK_SECRET')) define('FK_SECRET',        'DOI_CHUOI_NAY_THANH_CHUOI_DAI_NGAU_NHIEN_CUA_RIENG_BAN');
// Link shortener API (the "bypass link"). {url} is replaced by the destination link. Example:
// 'https://link4m.co/api-shorten/v2?api=YOUR_TOKEN&url={url}'
if (!defined('FK_SHORTENER_API')) define('FK_SHORTENER_API', 'https://link4m.co/api-shorten/v2?api=YOUR_API_TOKEN_HERE&url={url}');
// Optional: shortener host (e.g. 'link4m.co'). If set, the return step must come from this host.
// Leave empty if your shortener does not send a Referer.
if (!defined('FK_SHORT_HOST')) define('FK_SHORT_HOST',    '');
if (!defined('FK_MIN_WAIT')) define('FK_MIN_WAIT',      25);   // minimum seconds between clicking "Bypass Link" and coming back
// Anti-cheat: finishing the link bypass faster than FK_FAST_WAIT seconds (or with a bot / script client) = cheating.
// Cheating only cancels the session (no key); the account is never locked. Override all three in fk_config.php.
if (!defined('FK_FAST_WAIT')) define('FK_FAST_WAIT',     60);
if (!defined('FK_CHEAT_MAX')) define('FK_CHEAT_MAX',     2);
if (!defined('FK_CHEAT_BAN')) define('FK_CHEAT_BAN',     86400);
if (!defined('FK_FLOW_TTL')) define('FK_FLOW_TTL',      900);  // a bypass session lives at most 15 minutes
if (!defined('FK_VIEW_WINDOW')) define('FK_VIEW_WINDOW',   28800);  // the key can be re-opened (reload / come back) for 8 hours, same browser
if (!defined('FK_KEY_TURNS')) define('FK_KEY_TURNS',     1);    // turns of a free key
// Anti-cheat (values live in fk_config.php)
if (!defined('FK_TRUST_CF')) define('FK_TRUST_CF', true);
if (!defined('FK_TRUSTED_PROXIES')) define('FK_TRUSTED_PROXIES', '103.56.163.10,103.216.118.10,103.166.183.10');   // OneShield (iNET) proxy IPs
if (!defined('FK_BLOCK_VPN')) define('FK_BLOCK_VPN', true);
if (!defined('FK_VPN_API')) define('FK_VPN_API', 'http://ip-api.com/json/{ip}?fields=status,proxy,hosting');
if (!defined('FK_VPN_FAIL_OPEN')) define('FK_VPN_FAIL_OPEN', true);
if (!defined('FK_VPN_CACHE_TTL')) define('FK_VPN_CACHE_TTL', 86400);
if (!defined('FK_MAX_STRIKES')) define('FK_MAX_STRIKES', 5);
if (!defined('FK_IP_WHITELIST')) define('FK_IP_WHITELIST', '');
if (!defined('FK_LOG_KEEP_DAYS')) define('FK_LOG_KEEP_DAYS', 90);
// 8-hour cycle: 1 key per account / device / network every FK_COOLDOWN seconds.
// Keep it equal to the cycle used by devnvios_theme.php so both sides reopen together.
if (!defined('FK_COOLDOWN')) define('FK_COOLDOWN', 28800);

/* =====================================================================
   HELPERS
   ===================================================================== */
// ---- IP helpers -------------------------------------------------------------
function fk_cidr_match($ip, $cidr) {
    if (strpos($cidr, '/') === false) { $cidr .= (strpos($cidr, ':') !== false ? '/128' : '/32'); }
    list($net, $bits) = explode('/', $cidr, 2);
    $a = @inet_pton($ip);
    $b = @inet_pton($net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
    $bits  = (int)$bits;
    $bytes = intdiv($bits, 8);
    if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) return false;
    $rem = $bits % 8;
    if ($rem === 0) return true;
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
}

function fk_in_list($ip, $csv) {
    foreach (explode(',', (string)$csv) as $c) {
        $c = trim($c);
        if ($c !== '' && fk_cidr_match($ip, $c)) return true;
    }
    return false;
}

// Is the direct peer allowed to tell us the real client IP? (Cloudflare, local reverse proxy, or listed proxies)
function fk_proxy_trusted($remote) {
    $cf = '173.245.48.0/20,103.21.244.0/22,103.22.200.0/22,103.31.4.0/22,141.101.64.0/18,108.162.192.0/18,190.93.240.0/20,188.114.96.0/20,197.234.240.0/22,198.41.128.0/17,162.158.0.0/15,104.16.0.0/13,104.24.0.0/14,172.64.0.0/13,131.0.72.0/22,2400:cb00::/32,2606:4700::/32,2803:f800::/32,2405:b500::/32,2405:8100::/32,2a06:98c0::/29,2c0f:f248::/32';
    if (fk_in_list($remote, $cf) || fk_in_list($remote, FK_TRUSTED_PROXIES)) return true;
    // loopback / private peer = nginx or docker on the same machine sits in front
    return !filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

// Real client IP. Forwarding headers are only believed when the direct peer is a trusted proxy
// (Cloudflare, OneShield, local nginx), so a visitor cannot fake them.
function fk_raw_ip() {
    $remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $ip = filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    if (!fk_proxy_trusted($ip)) return $ip;
    if (FK_TRUST_CF && !empty($_SERVER['HTTP_CF_CONNECTING_IP']) && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // walk from the right and skip proxies we trust: the first other address is the real visitor
        $parts = array_reverse(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        foreach ($parts as $p) {
            $p = trim($p);
            if (!filter_var($p, FILTER_VALIDATE_IP)) continue;
            if (fk_proxy_trusted($p)) continue;
            return $p;
        }
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP']) && filter_var(trim($_SERVER['HTTP_X_REAL_IP']), FILTER_VALIDATE_IP)) {
        return trim($_SERVER['HTTP_X_REAL_IP']);
    }
    return $ip;
}

// Lock key: IPv4 as is, IPv4-mapped IPv6 -> IPv4, IPv6 reduced to its /64 so it cannot be rotated.
function fk_ip_key($ip) {
    $bin = @inet_pton($ip);
    if ($bin !== false && strlen($bin) === 16) {
        if (substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") return inet_ntop(substr($bin, 12));
        return bin2hex(substr($bin, 0, 8)) . '/64';
    }
    return $ip;
}

function fk_ip() { return fk_ip_key(fk_raw_ip()); }

function fk_is_whitelisted($ip_raw) {
    return FK_IP_WHITELIST !== '' && fk_in_list($ip_raw, FK_IP_WHITELIST);
}

// Long-lived device cookie. Survives logout / new accounts (not incognito, the IP lock covers that).
function fk_device() {
    $d = isset($_COOKIE['fk_did']) ? $_COOKIE['fk_did'] : '';
    if (!preg_match('/^[a-f0-9]{32}$/', $d)) {
        $d = bin2hex(random_bytes(16));
        $_COOKIE['fk_did'] = $d;
        if (!headers_sent()) {
            setcookie('fk_did', $d, array('expires' => time() + 31536000, 'path' => '/',
                'secure' => strpos($GLOBALS['main_web'], 'https://') === 0, 'httponly' => true, 'samesite' => 'Lax'));
        }
    }
    return $d;
}

function fk_ua() {
    return substr(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 0, 300);
}

function fk_url_token() {
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    if (preg_match('/[?&]=([A-Za-z0-9]{16,64})(?:&|$)/', $uri, $m)) {
        return $m[1];
    }
    return '';
}

// Account name coming from the devnvios theme login (the session the theme creates)
function fk_theme_username() {
    foreach (array('username', 'user', 'user_name') as $k) {
        if (isset($_SESSION[$k]) && is_string($_SESSION[$k]) && $_SESSION[$k] !== '') return $_SESSION[$k];
    }
    return '';
}

// How long a claimed key stays viewable (never shorter than the cooldown)
function fk_view_ttl() {
    return max((int)FK_VIEW_WINDOW, (int)FK_COOLDOWN);
}

// Window id used only by the unique indexes (race protection). The real lock is the rolling cooldown.
function fk_slot($now) {
    return (string)(int)floor(($now + 25200) / max(60, (int)FK_COOLDOWN));
}

function fk_cd_label() {
    $h = FK_COOLDOWN / 3600;
    return (floor($h) == $h ? (int)$h : round($h, 1)) . ' hours';
}

// Remember the key page in a cookie, so reloading never loses the key
function fk_set_view_cookie($tok) {
    if (headers_sent()) return;
    setcookie('fk_view', $tok, array('expires' => time() + fk_view_ttl(), 'path' => '/',
        'secure' => strpos($GLOBALS['main_web'], 'https://') === 0, 'httponly' => true, 'samesite' => 'Lax'));
}

function fk_exec($conn, $sql, $types = '', $params = array()) {
    try {
        $st = mysqli_prepare($conn, $sql);
        if (!$st) return false;
        if ($types !== '') mysqli_stmt_bind_param($st, $types, ...$params);
        $ok  = mysqli_stmt_execute($st);
        $aff = $ok ? mysqli_stmt_affected_rows($st) : false;
        mysqli_stmt_close($st);
        return $aff;
    } catch (Throwable $e) {
        return false;   // e.g. duplicate key: the unique indexes are the final lock
    }
}

function fk_row($conn, $sql, $types = '', $params = array()) {
    try {
        $st = mysqli_prepare($conn, $sql);
        if (!$st) return null;
        if ($types !== '') mysqli_stmt_bind_param($st, $types, ...$params);
        if (!mysqli_stmt_execute($st)) { mysqli_stmt_close($st); return null; }
        $res = mysqli_stmt_get_result($st);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($st);
        return $row ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function fk_q($conn, $sql) {
    try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; }
}

// Creates / upgrades every table by itself. No manual SQL needed.
function fk_install($conn) {
    if (isset($_SESSION['fk_schema']) && $_SESSION['fk_schema'] === 4) return;

    fk_q($conn, "CREATE TABLE IF NOT EXISTS free_key_claims (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL,
        day CHAR(10) NOT NULL,
        key_code VARCHAR(80) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        created_at INT UNSIGNED NOT NULL,
        UNIQUE KEY uq_user_day (username, day),
        UNIQUE KEY uq_ip_day (ip, day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    fk_q($conn, "CREATE TABLE IF NOT EXISTS free_key_flow (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL,
        step_token CHAR(48) NOT NULL,
        verify_token CHAR(48) NULL,
        view_token CHAR(48) NULL,
        short_url VARCHAR(500) NULL,
        sid_hash CHAR(64) NOT NULL,
        ua_hash CHAR(64) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        created_at INT UNSIGNED NOT NULL,
        clicked_at INT UNSIGNED NULL,
        verified_at INT UNSIGNED NULL,
        claim_id INT UNSIGNED NULL,
        UNIQUE KEY uq_step (step_token),
        UNIQUE KEY uq_verify (verify_token),
        UNIQUE KEY uq_view (view_token),
        KEY idx_user (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Added by newer versions (checked first, so it is safe to run again and again)
    $add = array(
        array('free_key_claims', 'col', 'device_id',     "ALTER TABLE free_key_claims ADD COLUMN device_id CHAR(32) NULL AFTER ip"),
        array('free_key_flow',   'col', 'device_id',     "ALTER TABLE free_key_flow ADD COLUMN device_id CHAR(32) NULL AFTER ip"),
        array('free_key_claims', 'idx', 'uq_ip_day',     "ALTER TABLE free_key_claims ADD UNIQUE KEY uq_ip_day (ip, day)"),
        array('free_key_claims', 'idx', 'uq_device_day', "ALTER TABLE free_key_claims ADD UNIQUE KEY uq_device_day (device_id, day)"),
    );
    foreach ($add as $a) {
        $r = fk_q($conn, $a[1] === 'col'
            ? "SHOW COLUMNS FROM `{$a[0]}` LIKE '{$a[2]}'"
            : "SHOW INDEX FROM `{$a[0]}` WHERE Key_name = '{$a[2]}'");
        if ($r && mysqli_num_rows($r) === 0) fk_q($conn, $a[3]);
    }

    // Permanent IP history (every start / claim / blocked attempt)
    fk_q($conn, "CREATE TABLE IF NOT EXISTS free_key_ip_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        ip_raw VARCHAR(45) NOT NULL,
        username VARCHAR(100) NOT NULL,
        device_id CHAR(32) NULL,
        event VARCHAR(24) NOT NULL,
        created_at INT UNSIGNED NOT NULL,
        KEY idx_ip_time (ip, created_at),
        KEY idx_user (username),
        KEY idx_time (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // VPN lookup cache
    fk_q($conn, "CREATE TABLE IF NOT EXISTS free_key_ip_cache (
        ip VARCHAR(45) NOT NULL PRIMARY KEY,
        is_vpn TINYINT(1) NOT NULL DEFAULT 0,
        checked_at INT UNSIGNED NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Every IP and device an account has been seen on. Links accounts that share a network or a device.
    fk_q($conn, "CREATE TABLE IF NOT EXISTS free_key_seen (
        username VARCHAR(100) NOT NULL,
        kind VARCHAR(3) NOT NULL,
        val VARCHAR(48) NOT NULL,
        first_seen INT UNSIGNED NOT NULL,
        last_seen INT UNSIGNED NOT NULL,
        PRIMARY KEY (username, kind, val),
        KEY idx_val (kind, val, last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $_SESSION['fk_schema'] = 4;
}

function fk_http_get($url, $timeout = 3) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        $b = curl_exec($ch);
        curl_close($ch);
        return $b;
    }
    return @file_get_contents($url, false, stream_context_create(array('http' => array('timeout' => $timeout))));
}

// true = this IP is a VPN / proxy / Tor / datacenter. Results are cached in the database.
function fk_is_vpn($conn, $ip_raw, $now) {
    if (!FK_BLOCK_VPN || FK_VPN_API === '') return false;
    if (!filter_var($ip_raw, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;

    $c = fk_row($conn, "SELECT is_vpn, checked_at FROM free_key_ip_cache WHERE ip = ? LIMIT 1", 's', array($ip_raw));
    if ($c && (int)$c['checked_at'] >= $now - FK_VPN_CACHE_TTL) return (int)$c['is_vpn'] === 1;

    $body = fk_http_get(str_replace('{ip}', urlencode($ip_raw), FK_VPN_API), 3);
    $j    = $body ? json_decode($body, true) : null;
    if (!is_array($j) || (isset($j['status']) && $j['status'] !== 'success')) {
        // API down: remember the guess for only 5 minutes
        $guess = FK_VPN_FAIL_OPEN ? 0 : 1;
        fk_exec($conn, "REPLACE INTO free_key_ip_cache (ip, is_vpn, checked_at) VALUES (?, ?, ?)",
            'sii', array($ip_raw, $guess, $now - FK_VPN_CACHE_TTL + 300));
        return $guess === 1;
    }
    $vpn = 0;
    foreach (array('proxy', 'hosting', 'vpn', 'tor') as $k) {
        if (!empty($j[$k]) && $j[$k] !== 'no') $vpn = 1;
    }
    fk_exec($conn, "REPLACE INTO free_key_ip_cache (ip, is_vpn, checked_at) VALUES (?, ?, ?)", 'sii', array($ip_raw, $vpn, $now));
    return $vpn === 1;
}

function fk_log($conn, $event, $user = '') {
    global $ip, $ip_raw, $device, $now;
    fk_exec($conn, "INSERT INTO free_key_ip_log (ip, ip_raw, username, device_id, event, created_at) VALUES (?, ?, ?, ?, ?, ?)",
        'sssssi', array($ip, $ip_raw, $user, $device, $event, $now));
}

// Saves the account together with the IP and the device it is using right now
function fk_seen($conn) {
    global $username, $ip, $device, $now, $wl;
    if ($wl || $username === '') return;
    $sql = "INSERT INTO free_key_seen (username, kind, val, first_seen, last_seen) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)";
    fk_exec($conn, $sql, 'sssii', array($username, 'ip', $ip, $now, $now));
    if ($device !== null) {
        fk_exec($conn, $sql, 'sssii', array($username, 'dev', $device, $now, $now));
    }
}

// When the limit reopens: $from (time of the claim / strike) + the 8-hour cooldown
function fk_reset_info($now, $from = null) {
    $t    = ($from !== null ? (int)$from : $now) + (int)FK_COOLDOWN;
    $left = max(0, $t - $now);
    return array('at' => date('H:i d/m/Y', $t), 'h' => (int)floor($left / 3600), 'm' => (int)floor(($left % 3600) / 60));
}

// Logs a "blocked_*" event (counts as a strike) and shows the red page
function fk_deny($conn, $event, $title, $text) {
    global $main_web, $username;
    fk_log($conn, $event, $username);
    fk_layout('fa-ban', $title, $text,
        '<div class="actions">' . fk_btn($main_web, 'Back to home', 'fa-house', false) . '</div>', 0, 'bad');
}

// Cheat detections (fast bypass / bot client) for this account or this network inside the lock window
function fk_cheat_info($conn) {
    global $username, $ip, $now;
    $r = fk_row($conn, "SELECT COUNT(*) AS n, MAX(created_at) AS last_at FROM free_key_ip_log WHERE event = 'blocked_cheat' AND created_at >= ? AND (username = ? OR ip = ?)",
        'iss', array($now - (int)FK_CHEAT_BAN, $username, $ip));
    return array('n' => $r ? (int)$r['n'] : 0, 'last' => ($r && $r['last_at']) ? (int)$r['last_at'] : 0);
}

// One key per device and per IP every 8 hours + VPN block + lock-out after too many blocked tries
function fk_guard($conn) {
    global $ip, $ip_raw, $device, $now, $wl, $main_web, $username;
    if ($wl) return;
    $since = $now - FK_COOLDOWN;
    $home  = '<div class="actions">' . fk_btn($main_web, 'Back to home', 'fa-house', false) . '</div>';
    // FKX-NOLOCK: gian lan chi mat key, khong khoa tai khoan / mang

    $s = fk_row($conn, "SELECT COUNT(*) AS n FROM free_key_ip_log WHERE ip = ? AND created_at >= ? AND event LIKE 'blocked%' AND event <> 'blocked_cheat'",
        'si', array($ip, $since));
    if ($s && (int)$s['n'] >= FK_MAX_STRIKES) {
        $o = fk_row($conn, "SELECT created_at FROM free_key_ip_log WHERE ip = ? AND created_at >= ? AND event LIKE 'blocked%' AND event <> 'blocked_cheat' ORDER BY created_at DESC LIMIT 1 OFFSET " . max(0, (int)FK_MAX_STRIKES - 1),
            'si', array($ip, $since));
        $r = fk_reset_info($now, $o ? $o['created_at'] : $now);
        fk_layout('fa-lock', 'Temporarily locked', 'Too many blocked attempts from this network. It reopens at ' . $r['at'] . '.', $home, 0, 'bad');
    }
    if ($device !== null) {
        $c = fk_row($conn, "SELECT created_at FROM free_key_claims WHERE device_id = ? AND created_at > ? ORDER BY id DESC LIMIT 1",
            'si', array($device, $since));
        if ($c) {
            $r = fk_reset_info($now, $c['created_at']);
            fk_deny($conn, 'blocked_device', 'Limit reached', 'A key was already claimed from this device in the last ' . fk_cd_label() . '. It reopens at ' . $r['at'] . '.');
        }
    }
    $c = fk_row($conn, "SELECT created_at FROM free_key_claims WHERE ip = ? AND created_at > ? ORDER BY id DESC LIMIT 1",
        'si', array($ip, $since));
    if ($c) {
        $r = fk_reset_info($now, $c['created_at']);
        fk_deny($conn, 'blocked_ip', 'Limit reached', 'A key was already claimed from this network in the last ' . fk_cd_label() . '. It reopens at ' . $r['at'] . '.');
    }
    // Any IP or device used in the last 8 hours by ANOTHER account that already claimed a key in the last 8 hours
    $lsql   = "SELECT c.created_at FROM free_key_seen s
               JOIN free_key_claims c ON c.username = s.username AND c.created_at > ?
               WHERE s.username <> ? AND s.last_seen > ? AND ((s.kind = 'ip' AND s.val = ?)";
    $ltypes = 'isis';
    $lpar   = array($since, $username, $since, $ip);
    if ($device !== null) {
        $lsql    .= " OR (s.kind = 'dev' AND s.val = ?)";
        $ltypes  .= 's';
        $lpar[]   = $device;
    }
    $lsql .= ") ORDER BY c.created_at DESC LIMIT 1";
    $lk = fk_row($conn, $lsql, $ltypes, $lpar);
    if ($lk) {
        $r = fk_reset_info($now, $lk['created_at']);
        fk_deny($conn, 'blocked_link', 'Limit reached', 'A key was already claimed from a device or network linked to this one. It reopens at ' . $r['at'] . '.');
    }
    if (fk_is_vpn($conn, $ip_raw, $now)) {
        fk_deny($conn, 'blocked_vpn', 'VPN / proxy detected', 'Turn off your VPN or proxy, then try again.');
    }
}

// Key format: 32 encoded characters + "-<N>turns"
// First 16 are random, the last 16 are an HMAC of the first 16 + account + window (cannot be forged without FK_SECRET)
function fk_make_key($username, $day) {
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';   // no look-alike characters (0 O 1 l I)
    $n   = strlen($abc);
    $g1  = '';
    for ($i = 0; $i < 16; $i++) { $g1 .= $abc[random_int(0, $n - 1)]; }
    $h  = hash_hmac('sha256', $g1 . '|' . $username . '|' . $day, FK_SECRET, true);
    $g2 = '';
    for ($i = 0; $i < 16; $i++) { $g2 .= $abc[ord($h[$i]) % $n]; }
    return $g1 . $g2 . '-' . FK_KEY_TURNS . 'turns';
}

function fk_shorten($dest) {
    if (FK_SHORTENER_API === '') return false;
    if (FK_SHORTENER_API === 'TEST') return $dest;   // test mode: skip the partner link
    $api  = str_replace('{url}', urlencode($dest), FK_SHORTENER_API);
    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($api);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $body = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx  = stream_context_create(array('http' => array('timeout' => 8)));
        $body = @file_get_contents($api, false, $ctx);
    }
    if (!$body) return false;
    $j = json_decode($body, true);
    if (!is_array($j)) return false;
    foreach (array('shortenedUrl', 'shortlink', 'short_url') as $k) {
        if (!empty($j[$k]) && preg_match('#^https?://#i', $j[$k])) return $j[$k];
    }
    return false;
}

function fk_owns($flow) {
    global $username, $sid_hash, $ua_hash, $ip, $device, $now;
    return $flow
        && $flow['username'] === $username
        && hash_equals($flow['sid_hash'], $sid_hash)
        && hash_equals($flow['ua_hash'], $ua_hash)
        && $flow['ip'] === $ip
        && (string)(isset($flow['device_id']) ? $flow['device_id'] : '') === (string)$device
        && empty($flow['verified_at'])
        && (int)$flow['created_at'] >= $now - FK_FLOW_TTL;
}

function fk_h($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function fk_btn($href, $label, $icon = '', $primary = true) {
    // $icon is kept only so older calls still work: this design draws no icons or arrows
    $cls = $primary ? 'btn-primary' : 'btn-ghost';
    return '<a href="' . fk_h($href) . '" class="btn ' . $cls . '">' . $label . '</a>';
}

// Shared page layout. $step: 0 = no step label, 1 = verify step, 2 = claim step
// $icon is kept only so older calls still work: this design draws no icons.
function fk_layout($icon, $title, $text, $body = '', $step = 0, $tone = 'info') {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    $labels = array('bad' => 'Blocked', 'wait' => 'Please wait', 'ok' => 'Done');
    $tag = '';
    if ($step > 0) { $tag = 'Step ' . (int)$step . ' of 2 · ' . ($step > 1 ? 'Claim' : 'Verify'); }
    elseif (isset($labels[$tone])) { $tag = $labels[$tone]; }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#000000">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo fk_h($title); ?> - IOSMODS</title>
    <style>
        :root { --bg:#000; --card:#0a0a0a; --line:#232323; --line2:#383838; --txt:#f2f2f2; --mute:#9b9b9b; --dim:#6a6a6a; }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html { background: var(--bg); }
        body { margin: 0; min-height: 100vh; min-height: 100dvh; display: flex; align-items: center; justify-content: center; background: var(--bg); color: var(--txt); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; }
        .no-select { user-select: none; -webkit-user-select: none; }
        .wrap { width: 100%; max-width: 380px; padding: 24px 16px calc(24px + env(safe-area-inset-bottom)); }
        .brand { text-align: center; margin-bottom: 18px; font-size: 13px; font-weight: 700; letter-spacing: .3em; color: var(--txt); }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 26px 22px 22px; }
        .tag { text-align: center; font-size: 11px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: var(--dim); }
        h1 { margin: 10px 0 0; text-align: center; font-size: 22px; font-weight: 700; letter-spacing: -.01em; color: #fff; }
        .sub { margin: 10px 0 0; text-align: center; font-size: 14px; line-height: 1.6; color: var(--mute); }
        .list { margin: 22px 0 0; padding: 0; list-style: none; border-top: 1px solid var(--line); }
        .list li { padding: 12px 2px; border-bottom: 1px solid var(--line); font-size: 13px; line-height: 1.5; color: #c4c4c4; }
        .actions { margin-top: 22px; }
        .btn { display: block; width: 100%; padding: 14px 16px; border-radius: 10px; text-align: center; font-size: 14px; font-weight: 600; letter-spacing: .02em; text-decoration: none; }
        .btn:active { opacity: .8; }
        .btn-primary { color: #000; background: #fff; border: 1px solid #fff; }
        .btn-ghost { color: #d0d0d0; background: transparent; border: 1px solid var(--line2); }
        .hint { margin: 14px 0 0; text-align: center; font-size: 12px; line-height: 1.55; color: var(--dim); }
        .foot { margin-top: 18px; text-align: center; font-size: 11px; color: #4a4a4a; }
    </style>
</head>
<body class="no-select" oncontextmenu="return false;">
    <main class="wrap">
        <div class="brand">IOSMODS</div>
        <div class="card">
            <?php if ($tag !== '') { ?><div class="tag"><?php echo fk_h($tag); ?></div><?php } ?>
            <h1><?php echo fk_h($title); ?></h1>
            <p class="sub"><?php echo fk_h($text); ?></p>
            <?php echo $body; ?>
        </div>
        <p class="foot">Protected by iosmods &middot; Do not share this link</p>
    </main>
</body>
</html>
<?php
    exit;
}

function fk_fail($msg) {
    global $main_web;
    fk_layout('fa-xmark', 'Unable to continue', $msg,
        '<div class="actions">' . fk_btn($main_web, 'Back to home', 'fa-house', false) . '</div>', 0, 'bad');
}

/* =====================================================================
   LOGIN CHECK
   ===================================================================== */
if (defined('FK_LIB_ONLY')) { return; }

if (fk_theme_username() === '') {
    die("<script>alert('Ngu như bò =))) mày đã đăng ký đăng nhập đâu mà đòi lấy key cu'); window.location='$main_web';</script>");
}

$username = fk_theme_username();
$action   = isset($_GET['a']) ? $_GET['a'] : 'start';
$now      = time();
$day      = fk_slot($now);   // window id for the unique indexes (the lock itself is a rolling 8h)
$ip_raw   = fk_raw_ip();
$ip       = fk_ip_key($ip_raw);
$wl       = fk_is_whitelisted($ip_raw);
$device   = $wl ? null : fk_device();
fk_install($conn);
$ua_hash  = hash('sha256', fk_ua());
$sid_hash = hash('sha256', session_id());
$base     = $main_web . '/gtjsdzxhflhv/get-free/' . rawurlencode($username);
$key_base = $main_web . '/hkvpnmzyqfhw/key-free/' . rawurlencode($username);
fk_seen($conn);   // save account + IP + device on every request of this flow

/* =====================================================================
   STEP 1: "GET KEY" PAGE
   ===================================================================== */
if ($action === 'start') {
    fk_install($conn);
    fk_exec($conn, "DELETE FROM free_key_flow WHERE created_at < ?", 'i', array($now - 86400));
    if (random_int(1, 40) === 1) {
        fk_exec($conn, "DELETE FROM free_key_ip_log WHERE created_at < ?", 'i', array($now - FK_LOG_KEEP_DAYS * 86400));
        fk_exec($conn, "DELETE FROM free_key_ip_cache WHERE checked_at < ?", 'i', array($now - 7 * 86400));
        fk_exec($conn, "DELETE FROM free_key_seen WHERE last_seen < ?", 'i', array($now - FK_LOG_KEEP_DAYS * 86400));
    }

    // Already claimed today with this account?
    $claim = fk_row($conn, "SELECT id, created_at FROM free_key_claims WHERE username = ? AND created_at > ? ORDER BY id DESC LIMIT 1", 'si', array($username, $now - FK_COOLDOWN));
    if ($claim) {
        $f = fk_row($conn,
            "SELECT view_token FROM free_key_flow WHERE claim_id = ? AND sid_hash = ? AND verified_at >= ? LIMIT 1",
            'isi', array((int)$claim['id'], $sid_hash, $now - fk_view_ttl()));
        if ($f && !empty($f['view_token'])) {
            fk_set_view_cookie($f['view_token']);
            header('Location: ' . $key_base . '?=' . $f['view_token']);
            exit();
        }
        $r = fk_reset_info($now, $claim['created_at']);
        fk_layout('fa-clock', 'Already claimed',
            'Each account can claim 1 key every ' . fk_cd_label() . '. It reopens at ' . $r['at'] . ' (in ' . $r['h'] . 'h ' . $r['m'] . 'm).',
            '<div class="actions">' . fk_btn($main_web, 'Back to home', 'fa-house', false) . '</div>', 0, 'wait');
    }

    // Device / IP / VPN checks (1 key per device and per IP, per day)
    fk_guard($conn);

    // Reuse an unfinished session or open a new one
    $flow = fk_row($conn,
        "SELECT step_token FROM free_key_flow WHERE username = ? AND sid_hash = ? AND verified_at IS NULL AND created_at >= ? ORDER BY id DESC LIMIT 1",
        'ssi', array($username, $sid_hash, $now - FK_FLOW_TTL));
    if ($flow) {
        $step_token = $flow['step_token'];
    } else {
        $step_token = bin2hex(random_bytes(24));
        $ok = fk_exec($conn,
            "INSERT INTO free_key_flow (username, step_token, sid_hash, ua_hash, ip, device_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            'ssssssi', array($username, $step_token, $sid_hash, $ua_hash, $ip, $device, $now));
        if ($ok !== 1) {
            fk_fail('System error. Please try again in a moment.');
        }
        fk_log($conn, 'start', $username);
    }

    $body = '<ul class="list">'
        . '<li>1 free key per account, every ' . fk_cd_label() . '</li>'
        . '<li>Same browser and network all the way. No VPN or proxy</li>'
        . '<li>Your account, IP address and device are recorded</li>'
        . '<li>Takes about 30 seconds</li>'
        . '</ul>'
        . '<div class="actions">' . fk_btn($base . '/go?=' . $step_token, 'Bypass Link') . '</div>'
        . '<p class="hint">You will be sent to a partner link, then brought back here automatically.</p>';

    fk_layout('fa-key', 'Get your free key',
        'Complete one quick link bypass to unlock your free key.', $body, 1);
}

/* =====================================================================
   GO TO THE BYPASS LINK
   ===================================================================== */
if ($action === 'go') {
    $tok  = fk_url_token();
    $flow = $tok !== '' ? fk_row($conn, "SELECT * FROM free_key_flow WHERE step_token = ? LIMIT 1", 's', array($tok)) : null;
    if (!fk_owns($flow)) {
        fk_fail('This session is invalid or has expired. Please go back and try again.');
    }
    fk_guard($conn);

    if (empty($flow['clicked_at']) || empty($flow['short_url'])) {
        $verify = bin2hex(random_bytes(24));
        $dest   = $base . '/verify?=' . $verify;
        $short  = fk_shorten($dest);
        if (!$short) {
            fk_fail('Could not create the bypass link. Please try again in a few minutes.');
        }
        $a = fk_exec($conn,
            "UPDATE free_key_flow SET verify_token = ?, short_url = ?, clicked_at = ? WHERE id = ? AND clicked_at IS NULL",
            'ssii', array($verify, $short, $now, (int)$flow['id']));
        if ($a !== 1) {
            $flow = fk_row($conn, "SELECT * FROM free_key_flow WHERE id = ? LIMIT 1", 'i', array((int)$flow['id']));
            if (!fk_owns($flow) || empty($flow['short_url'])) {
                fk_fail('This session is invalid or has expired. Please go back and try again.');
            }
            $short = $flow['short_url'];
        }
    } else {
        $short = $flow['short_url'];
    }

    header('Cache-Control: no-store');
    header('Location: ' . $short);
    exit();
}

/* =====================================================================
   RETURN FROM THE BYPASS LINK
   ===================================================================== */
if ($action === 'verify') {
    $tok  = fk_url_token();
    $flow = $tok !== '' ? fk_row($conn, "SELECT * FROM free_key_flow WHERE verify_token = ? LIMIT 1", 's', array($tok)) : null;
    if (!fk_owns($flow) || empty($flow['clicked_at'])) {
        fk_fail('This session is invalid or has expired. Please start again from the beginning.');
    }

    // Finished too fast, or by a script / bot client => cheating: cancel the session, count a strike, send to the warning page
    $elapsed = $now - (int)$flow['clicked_at'];
    $is_bot  = (bool)preg_match('~(HeadlessChrome|PhantomJS|python-requests|python-urllib|aiohttp|httpx|okhttp|Go-http-client|node-fetch|axios|libcurl|curl/|wget/|selenium|puppeteer|playwright|scrapy)~i', fk_ua());
    if ($elapsed < max((int)FK_MIN_WAIT, (int)FK_FAST_WAIT) || $is_bot) {
        fk_exec($conn, "DELETE FROM free_key_flow WHERE id = ?", 'i', array((int)$flow['id']));
        fk_log($conn, 'blocked_cheat', $username);
        header('Cache-Control: no-store');
        header('Location: ' . $key_base . '?=cheat&s=' . min(999, max(0, $elapsed)));
        exit();
    }

    // Referrer check (only if configured)
    if (FK_SHORT_HOST !== '') {
        $ref  = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        $host = (string)parse_url($ref, PHP_URL_HOST);
        if ($host === '' || stripos($host, FK_SHORT_HOST) === false) {
            fk_fail('Please complete the link bypass to get your key.');
        }
    }

    // One key per account and per network, per day
    if (fk_row($conn, "SELECT id FROM free_key_claims WHERE username = ? AND created_at > ? LIMIT 1", 'si', array($username, $now - FK_COOLDOWN))) {
        fk_fail('You have already claimed a key. Each account can claim 1 key every ' . fk_cd_label() . '.');
    }
    fk_guard($conn);
    $claim_ip = $wl ? 'wl:' . substr(md5($username), 0, 12) : $ip;   // whitelisted IPs never collide with each other

    // Issue the key (once, atomically)
    mysqli_begin_transaction($conn);
    $view = bin2hex(random_bytes(24));

    $a = fk_exec($conn, "UPDATE free_key_flow SET verified_at = ?, view_token = ? WHERE id = ? AND verified_at IS NULL",
        'isi', array($now, $view, (int)$flow['id']));
    if ($a !== 1) {
        mysqli_rollback($conn);
        fk_fail('This session has already been used.');
    }

    $key = null;
    for ($i = 0; $i < 8; $i++) {
        $k = fk_make_key($username, $day);
        if (!fk_row($conn, "SELECT id FROM keys_free WHERE key_code = ? LIMIT 1", 's', array($k))) { $key = $k; break; }
    }
    if ($key === null) {
        mysqli_rollback($conn);
        fk_fail('Could not generate a key. Please try again.');
    }

    $c = fk_exec($conn, "INSERT INTO free_key_claims (username, day, key_code, ip, device_id, created_at) VALUES (?, ?, ?, ?, ?, ?)",
        'sssssi', array($username, $day, $key, $claim_ip, $device, $now));
    if ($c !== 1) {
        mysqli_rollback($conn);
        if (fk_row($conn, "SELECT id FROM free_key_claims WHERE username = ? AND created_at > ? LIMIT 1", 'si', array($username, $now - FK_COOLDOWN))) {
            fk_fail('You have already claimed a key. Each account can claim 1 key every ' . fk_cd_label() . '.');
        }
        fk_deny($conn, 'blocked_race', 'Limit reached', 'A key was already claimed from this device or network recently.');
    }
    $claim_id = mysqli_insert_id($conn);

    $k2 = fk_exec($conn, "INSERT INTO keys_free (key_code, status) VALUES (?, 0)", 's', array($key));
    if ($k2 !== 1) {
        mysqli_rollback($conn);
        fk_fail('Could not save the key. Please try again.');
    }

    fk_exec($conn, "UPDATE free_key_flow SET claim_id = ? WHERE id = ?", 'ii', array((int)$claim_id, (int)$flow['id']));
    mysqli_commit($conn);
    fk_log($conn, 'claim', $username);

    fk_set_view_cookie($view);
    header('Cache-Control: no-store');
    header('Location: ' . $key_base . '?=' . $view);
    exit();
}

fk_fail('Invalid request.');
