<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
include 'db.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Same settings file as get_key.php, so both pages always agree on the 8-hour cycle
if (is_file(__DIR__ . '/fk_config.php')) { include_once __DIR__ . '/fk_config.php'; }
if (!defined('FK_COOLDOWN'))        define('FK_COOLDOWN', 28800);
if (!defined('FK_VIEW_WINDOW'))     define('FK_VIEW_WINDOW', 28800);
if (!defined('FK_TRUST_CF'))        define('FK_TRUST_CF', true);
if (!defined('FK_TRUSTED_PROXIES')) define('FK_TRUSTED_PROXIES', '103.56.163.10,103.216.118.10,103.166.183.10');   // OneShield (iNET) proxy IPs
if (!defined('FK_IP_WHITELIST'))    define('FK_IP_WHITELIST', '');
if (!defined('FK_MAIN_WEB'))        define('FK_MAIN_WEB', 'https://iosmods.xyz');
if (!defined('FK_ALLOWED_HOSTS'))   define('FK_ALLOWED_HOSTS', 'iosmods.xyz,www.iosmods.xyz');
if (!defined('FK_CHEAT_MAX'))       define('FK_CHEAT_MAX', 2);       // same values as get_key.php
if (!defined('FK_CHEAT_BAN'))       define('FK_CHEAT_BAN', 86400);

// Main site: same rule as get_key.php
$main_web = rtrim(FK_MAIN_WEB, '/');
$fk_host  = strtolower(isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) : '');
if ($fk_host !== '' && in_array($fk_host, array_map('trim', explode(',', FK_ALLOWED_HOSTS)), true)) {
    $main_web = 'https://' . $fk_host;
}
$view_window = max((int)FK_VIEW_WINDOW, (int)FK_COOLDOWN);   // must match fk_view_ttl() in get_key.php

// Account name from the devnvios theme login
if (!function_exists('fk_theme_username')) {
    function fk_theme_username() {
        foreach (array('username', 'user', 'user_name') as $k) {
            if (isset($_SESSION[$k]) && is_string($_SESSION[$k]) && $_SESSION[$k] !== '') return $_SESSION[$k];
        }
        return '';
    }
}

// 1. Must be logged in
$username = fk_theme_username();
if ($username === '') {
    die("<script>alert('Vui lòng đăng nhập để tiếp tục!'); window.location='$main_web';</script>");
}

// Real client IP. Must give exactly the same result as get_key.php (same helpers, same trusted proxies).
if (!function_exists('fk_cidr_match')) {
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
}
if (!function_exists('fk_in_list')) {
    function fk_in_list($ip, $csv) {
        foreach (explode(',', (string)$csv) as $c) {
            $c = trim($c);
            if ($c !== '' && fk_cidr_match($ip, $c)) return true;
        }
        return false;
    }
}
if (!function_exists('fk_proxy_trusted')) {
    function fk_proxy_trusted($remote) {
        $cf = '173.245.48.0/20,103.21.244.0/22,103.22.200.0/22,103.31.4.0/22,141.101.64.0/18,108.162.192.0/18,190.93.240.0/20,188.114.96.0/20,197.234.240.0/22,198.41.128.0/17,162.158.0.0/15,104.16.0.0/13,104.24.0.0/14,172.64.0.0/13,131.0.72.0/22,2400:cb00::/32,2606:4700::/32,2803:f800::/32,2405:b500::/32,2405:8100::/32,2a06:98c0::/29,2c0f:f248::/32';
        if (fk_in_list($remote, $cf) || fk_in_list($remote, FK_TRUSTED_PROXIES)) return true;
        return !filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
if (!function_exists('fk_raw_ip')) {
    function fk_raw_ip() {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $ip = filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
        if (!fk_proxy_trusted($ip)) return $ip;
        if (FK_TRUST_CF && !empty($_SERVER['HTTP_CF_CONNECTING_IP']) && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
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
}
if (!function_exists('fk_ip_key')) {
    function fk_ip_key($ip) {
        $bin = @inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16) {
            if (substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") return inet_ntop(substr($bin, 12));
            return bin2hex(substr($bin, 0, 8)) . '/64';
        }
        return $ip;
    }
}
if (!function_exists('fk_ip')) {
    function fk_ip() { return fk_ip_key(fk_raw_ip()); }
}

function deny($main_web) {
    header('Location: ' . $main_web);
    exit();
}

// 2. Read the token: from the URL (/hkvpnmzyqfhw/key-free/<user>?=xxxx) or, after a reload, from the cookie
$uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
$tok = '';
/*FKX-CHEAT-BEGIN*/
// get_key.php sends the visitor here when the link bypass was finished too fast or by a script / bot.
// The page only shows when a cheat detection really exists for this account / network, so it cannot be faked by editing the URL.
if (preg_match('/[?&]=cheat(?:&|$)/', $uri)) {
    $ch_n = 0; $ch_last = 0;
    $ch_ip = fk_ip();
    $ch_q = mysqli_prepare($conn, "SELECT COUNT(*) AS n, MAX(created_at) AS last_at FROM free_key_ip_log WHERE event = 'blocked_cheat' AND created_at >= ? AND (username = ? OR ip = ?)");
    if ($ch_q) {
        $ch_since = time() - (int)FK_CHEAT_BAN;
        mysqli_stmt_bind_param($ch_q, 'iss', $ch_since, $username, $ch_ip);
        mysqli_stmt_execute($ch_q);
        $ch_r = mysqli_stmt_get_result($ch_q);
        $ch_w = $ch_r ? mysqli_fetch_assoc($ch_r) : null;
        mysqli_stmt_close($ch_q);
        if ($ch_w) { $ch_n = (int)$ch_w['n']; $ch_last = (int)$ch_w['last_at']; }
    }
    if ($ch_n < 1) { deny($main_web); }
    $ch_secs   = (isset($_GET['s']) && ctype_digit((string)$_GET['s'])) ? min(999, (int)$_GET['s']) : null;
    if ($ch_secs === null && preg_match('/[?&]s=(\d{1,3})(?:&|$)/', $uri, $ch_m)) { $ch_secs = (int)$ch_m[1]; }
    $ch_locked = false;   /*FKX-NOLOCK*/ // khong khoa: gian lan chi mat key
    $ch_open   = date('H:i d/m/Y', $ch_last + (int)FK_CHEAT_BAN);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#000000">
    <meta name="robots" content="noindex, nofollow">
    <title>Cheating detected - IOSMODS</title>
    <style>
        :root { --bg:#000; --card:#0a0a0a; --line:#232323; --line2:#383838; --txt:#f2f2f2; --mute:#9b9b9b; --dim:#6a6a6a; --red:#ff5a52; }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html { background: var(--bg); }
        body { margin: 0; min-height: 100vh; min-height: 100dvh; display: flex; align-items: center; justify-content: center; background: var(--bg); color: var(--txt); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; }
        .no-select { user-select: none; -webkit-user-select: none; }
        .wrap { width: 100%; max-width: 390px; padding: 24px 16px calc(24px + env(safe-area-inset-bottom)); }
        .brand { text-align: center; margin-bottom: 18px; font-size: 13px; font-weight: 700; letter-spacing: .3em; color: var(--txt); }
        .card { background: var(--card); border: 1px solid #3a1d1b; border-radius: 14px; padding: 26px 20px 22px; }
        .tag { text-align: center; font-size: 11px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--red); }
        h1 { margin: 10px 0 0; text-align: center; font-size: 22px; font-weight: 700; letter-spacing: -.01em; color: #fff; }
        .sub { margin: 10px 0 0; text-align: center; font-size: 14px; line-height: 1.6; color: var(--mute); }
        .sub b { color: var(--red); font-weight: 700; }
        .list { margin: 20px 0 0; padding: 0; list-style: none; border-top: 1px solid var(--line); }
        .list li { padding: 12px 2px; border-bottom: 1px solid var(--line); font-size: 13px; line-height: 1.5; color: #c4c4c4; }
        .btn { display: block; width: 100%; margin-top: 20px; padding: 14px 16px; border: 1px solid #fff; border-radius: 10px; background: #fff; color: #000; font-size: 14px; font-weight: 600; letter-spacing: .02em; text-align: center; text-decoration: none; }
        .btn:active { opacity: .8; }
        .vi { margin: 16px 0 0; padding-top: 14px; border-top: 1px solid var(--line); text-align: center; font-size: 12px; line-height: 1.6; color: var(--dim); }
        .foot { margin-top: 16px; text-align: center; font-size: 11px; color: #4a4a4a; }
    </style>
</head>
<body class="no-select" oncontextmenu="return false;">
    <main class="wrap">
        <div class="brand">IOSMODS</div>
        <div class="card">
            <div class="tag">Warning</div>
            <h1>Cheating detected</h1>
            <p class="sub">
                <?php if ($ch_secs !== null) { ?>
                    The link bypass was finished in <b><?php echo (int)$ch_secs; ?> seconds</b>, too fast for a real person, so no key was issued.
                <?php } else { ?>
                    The link bypass was finished by a script or bot, so no key was issued.
                <?php } ?>
            </p>
            <ul class="list">
                <li>Scripts, bots and bypass tools are not allowed.</li>
                <?php if ($ch_locked) { ?>
                    <li>Free keys are locked for this account and network until <b style="color:#fff"><?php echo htmlspecialchars($ch_open, ENT_QUOTES, 'UTF-8'); ?></b>.</li>
                <?php } else { ?>
                    <li>No key was issued this time. Your account is not locked, you can try again.</li>
                    <li>To get a key, complete the link bypass yourself.</li>
                <?php } ?>
            </ul>
            <a class="btn" href="<?php echo htmlspecialchars($main_web, ENT_QUOTES, 'UTF-8'); ?>">Back to home</a>
            <p class="vi">Phát hiện dùng script / tool để vượt link nhanh bất thường nên không nhận được key. Tài khoản không bị khóa, hãy tự vượt link để nhận key.</p>
        </div>
        <p class="foot">Protected by iosmods</p>
    </main>
</body>
</html>
<?php
    exit();
}
/*FKX-CHEAT-END*/
if (preg_match('/[?&]=([A-Za-z0-9]{16,64})(?:&|$)/', $uri, $m)) {
    $tok = $m[1];
} elseif (!empty($_COOKIE['fk_view']) && preg_match('/^[A-Za-z0-9]{16,64}$/', $_COOKIE['fk_view'])) {
    $tok = $_COOKIE['fk_view'];
}
/*FKX-PENDING-BEGIN*/
// Admin vua mo lai key.php cho tai khoan nay: khong can link, dang nhap dung tai khoan la mo duoc (chi khi chua co token nao).
if ($tok === '') {
    $fkx_pz = str_repeat('0', 64);
    $fkx_pm = time() - $view_window;
    $fkx_pq = mysqli_prepare($conn, "SELECT view_token FROM free_key_flow WHERE username = ? AND sid_hash = ? AND view_token IS NOT NULL AND verified_at >= ? ORDER BY id DESC LIMIT 1");
    if ($fkx_pq) {
        mysqli_stmt_bind_param($fkx_pq, 'ssi', $username, $fkx_pz, $fkx_pm);
        mysqli_stmt_execute($fkx_pq);
        $fkx_pr = mysqli_stmt_get_result($fkx_pq);
        $fkx_pw = $fkx_pr ? mysqli_fetch_assoc($fkx_pr) : null;
        mysqli_stmt_close($fkx_pq);
        if ($fkx_pw && preg_match('/^[A-Za-z0-9]{16,64}$/', (string)$fkx_pw['view_token'])) { $tok = $fkx_pw['view_token']; }
    }
}
/*FKX-PENDING-END*/
if ($tok === '') { deny($main_web); }

// 3. Check the token: same account, same browser/device, same network, still valid
$st = mysqli_prepare($conn,
    "SELECT f.username, f.sid_hash, f.ua_hash, f.ip, f.verified_at, c.key_code, c.created_at
     FROM free_key_flow f
     JOIN free_key_claims c ON c.id = f.claim_id
     WHERE f.view_token = ? LIMIT 1");
if (!$st) { deny($main_web); }
mysqli_stmt_bind_param($st, 's', $tok);
mysqli_stmt_execute($st);
$res  = mysqli_stmt_get_result($st);
$flow = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($st);

$ua  = substr(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 0, 300);
$now = time();
/*FKX-REBIND-BEGIN*/
// Admin (admin_cp.php) vua mo lai trang key nay: gan lai vao trinh duyet / mang HIEN TAI cua dung tai khoan do.
// Khong co dau hieu mo lai thi doan nay khong lam gi.
if ($flow && $flow['username'] === $username && $flow['sid_hash'] === str_repeat('0', 64)
    && (int)$flow['verified_at'] >= $now - $view_window) {
    $fkx_sid  = hash('sha256', session_id());
    $fkx_ua   = hash('sha256', $ua);
    $fkx_ip   = fk_ip();
    $fkx_zero = str_repeat('0', 64);
    $fkx_st   = mysqli_prepare($conn, "UPDATE free_key_flow SET sid_hash = ?, ua_hash = ?, ip = ? WHERE view_token = ? AND sid_hash = ?");
    if ($fkx_st) {
        mysqli_stmt_bind_param($fkx_st, 'sssss', $fkx_sid, $fkx_ua, $fkx_ip, $tok, $fkx_zero);
        mysqli_stmt_execute($fkx_st);
        if (mysqli_stmt_affected_rows($fkx_st) === 1) {
            $flow['sid_hash'] = $fkx_sid;
            $flow['ua_hash']  = $fkx_ua;
            $flow['ip']       = $fkx_ip;
        }
        mysqli_stmt_close($fkx_st);
    }
}
/*FKX-REBIND-END*/

if (
    !$flow
    || $flow['username'] !== $username
    || !hash_equals($flow['sid_hash'], hash('sha256', session_id()))
    || !hash_equals($flow['ua_hash'], hash('sha256', $ua))
    || $flow['ip'] !== fk_ip()
    || (int)$flow['verified_at'] < $now - $view_window
) {
    deny($main_web);
}

$my_key = trim($flow['key_code']);

// Save the account together with the IP and the device that opened the key (same table as get_key.php)
$fk_raw = fk_raw_ip();
if (!(FK_IP_WHITELIST !== '' && fk_in_list($fk_raw, FK_IP_WHITELIST))) {
    $fk_dev = (!empty($_COOKIE['fk_did']) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE['fk_did'])) ? $_COOKIE['fk_did'] : null;
    $fk_ins = array(array('ip', fk_ip_key($fk_raw)));
    if ($fk_dev !== null) { $fk_ins[] = array('dev', $fk_dev); }
    foreach ($fk_ins as $fk_p) {
        try {
            $fk_q = mysqli_prepare($conn, "INSERT INTO free_key_seen (username, kind, val, first_seen, last_seen) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)");
            if ($fk_q) {
                mysqli_stmt_bind_param($fk_q, 'sssii', $username, $fk_p[0], $fk_p[1], $now, $now);
                mysqli_stmt_execute($fk_q);
                mysqli_stmt_close($fk_q);
            }
        } catch (Throwable $e) { /* table is created by get_key.php; never block the key page */ }
    }
}

// Split the key for display (copy still returns the full key)
$turns = 1;
$body  = $my_key;
if (preg_match('/^(.+)-(\d+)turns$/', $my_key, $tm)) { $body = $tm[1]; $turns = (int)$tm[2]; }
$chunks = str_split($body, 4);

// When the next key can be claimed
$reopen_ts = (int)$flow['created_at'] + (int)FK_COOLDOWN;
$left      = max(0, $reopen_ts - $now);
$reopen_at = date('H:i d/m/Y', $reopen_ts);
$left_txt  = floor($left / 3600) . 'h ' . floor(($left % 3600) / 60) . 'm';

// Keep the page reloadable: refresh the cookie with the same token
setcookie('fk_view', $tok, array('expires' => $now + $view_window, 'path' => '/',
    'secure' => strpos($main_web, 'https://') === 0, 'httponly' => true, 'samesite' => 'Lax'));

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#000000">
    <meta name="robots" content="noindex, nofollow">
    <title>Your Key - IOSMODS</title>
    <style>
        :root { --bg:#000; --card:#0a0a0a; --line:#232323; --line2:#383838; --txt:#f2f2f2; --mute:#9b9b9b; --dim:#6a6a6a; }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html { background: var(--bg); }
        body { margin: 0; min-height: 100vh; min-height: 100dvh; display: flex; align-items: center; justify-content: center; background: var(--bg); color: var(--txt); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; }
        .no-select { user-select: none; -webkit-user-select: none; }
        .wrap { width: 100%; max-width: 390px; padding: 24px 16px calc(24px + env(safe-area-inset-bottom)); }
        .brand { text-align: center; margin-bottom: 18px; font-size: 13px; font-weight: 700; letter-spacing: .3em; color: var(--txt); }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 26px 20px 22px; }
        .tag { text-align: center; font-size: 11px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: var(--dim); }
        h1 { margin: 10px 0 0; text-align: center; font-size: 22px; font-weight: 700; letter-spacing: -.01em; color: #fff; }
        .sub { margin: 10px 0 0; text-align: center; font-size: 14px; color: var(--mute); }
        .sub b { color: var(--txt); font-weight: 600; }
        .keybox { margin-top: 22px; border: 1px solid var(--line); border-radius: 10px; padding: 14px 12px; }
        .keyhead { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; padding: 0 2px; font-size: 11px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: var(--dim); }
        #keyBox { display: block; white-space: nowrap; overflow: hidden; text-align: center; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; font-size: 16px; font-weight: 700; letter-spacing: .02em; color: #fff; padding: 12px 6px; background: #000; border: 1px solid var(--line); border-radius: 8px; }
        #keyBox .g { margin-right: .42em; }
        #keyBox .g:last-of-type { margin-right: 0; }
        #keyBox .sep { color: #555; margin: 0 .1em; }
        #keyBox .turn { color: var(--mute); }
        .meta { margin-top: 12px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .meta div { text-align: left; border: 1px solid var(--line); border-radius: 8px; padding: 9px 11px; }
        .meta small { display: block; font-size: 10px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: var(--dim); }
        .meta span { display: block; margin-top: 3px; font-size: 13px; font-weight: 600; color: #ddd; }
        .btn { display: block; width: 100%; margin-top: 16px; padding: 14px 16px; border: 1px solid #fff; border-radius: 10px; background: #fff; color: #000; font-size: 14px; font-weight: 600; letter-spacing: .02em; text-align: center; cursor: pointer; font-family: inherit; }
        .btn:active { opacity: .8; }
        .btn.ok { background: #1a1a1a; border-color: var(--line2); color: #fff; }
        .note { margin: 16px 0 0; padding-top: 14px; border-top: 1px solid var(--line); text-align: center; font-size: 12px; line-height: 1.6; color: var(--dim); }
        .foot { margin-top: 16px; text-align: center; font-size: 11px; color: #4a4a4a; }
    </style>
</head>
<body class="no-select" oncontextmenu="return false;">

    <main class="wrap">
        <div class="brand">IOSMODS</div>

        <div class="card">
            <div class="tag">Step 2 of 2 · Claim</div>
            <h1>Key claimed</h1>
            <p class="sub">Account: <b><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></b></p>

            <div class="keybox">
                <div class="keyhead">
                    <span>Your key</span>
                    <span><?php echo $turns; ?> <?php echo $turns === 1 ? 'turn' : 'turns'; ?></span>
                </div>
                <div id="keyBox" data-key="<?php echo htmlspecialchars($my_key, ENT_QUOTES, 'UTF-8'); ?>"><?php
                    $out = array();
                    foreach ($chunks as $c) {
                        $out[] = '<span class="g">' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') . '</span>';
                    }
                    echo implode('', $out);
                    echo '<span class="sep">-</span><span class="turn">' . (int)$turns . 'turns</span>';
                ?></div>
                <div class="meta">
                    <div><small>Type</small><span>Free key</span></div>
                    <div><small>Next key</small><span><?php echo htmlspecialchars($left > 0 ? 'in ' . $left_txt : 'available now', ENT_QUOTES, 'UTF-8'); ?></span></div>
                </div>
            </div>

            <button id="copyBtn" onclick="copyKey()" class="btn" type="button">Copy key</button>

            <p class="note">
                You can reload this page, your key stays here on this browser for
                <?php echo (int)floor($view_window / 3600); ?> hours.
                Next free key opens at <?php echo htmlspecialchars($reopen_at, ENT_QUOTES, 'UTF-8'); ?>.
            </p>
        </div>

        <p class="foot">Protected by iosmods &middot; One key per account every <?php echo (int)floor(FK_COOLDOWN / 3600); ?> hours</p>
    </main>

    <script>
        // Keep the whole key on a single line: shrink the font until it fits
        function fitKey() {
            var el = document.getElementById("keyBox");
            var s = 16;
            el.style.fontSize = s + "px";
            while (el.scrollWidth > el.clientWidth && s > 8) {
                s -= 0.5;
                el.style.fontSize = s + "px";
            }
        }
        fitKey();
        window.addEventListener("resize", fitKey);
        window.addEventListener("load", fitKey);
        if (document.fonts && document.fonts.ready) { document.fonts.ready.then(fitKey); }

        function copyKey() {
            var text = document.getElementById("keyBox").getAttribute("data-key");
            var btn  = document.getElementById("copyBtn");

            function done() {
                btn.classList.add("ok");
                btn.textContent = "Copied";
                setTimeout(function () {
                    btn.classList.remove("ok");
                    btn.textContent = "Copy key";
                }, 2000);
            }

            function fallback() {
                var ta = document.createElement("textarea");
                ta.value = text;
                ta.setAttribute("readonly", "");
                ta.style.cssText = "position:fixed;top:0;left:0;opacity:0;font-size:16px;user-select:text;-webkit-user-select:text;";
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                ta.setSelectionRange(0, text.length);
                try { document.execCommand("copy"); done(); } catch (e) {}
                document.body.removeChild(ta);
            }

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done).catch(fallback);
            } else {
                fallback();
            }
        }

        // Hide the token from the address bar. Reloading still works (the key is remembered by a cookie)
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.pathname);
        }

        // Block F12 / right click
        document.onkeydown = function(e) {
            if (e.keyCode == 123 || (e.ctrlKey && e.shiftKey && e.keyCode == 'I'.charCodeAt(0))) return false;
            if (e.ctrlKey && e.keyCode == 'U'.charCodeAt(0)) return false;
        };
    </script>
</body>
</html>
