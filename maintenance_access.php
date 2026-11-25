<?php
/**
 * maintenance_access.php: c?ng vào admin bí m?t khi dang b?o trì.
 *
 * Ch? ho?t d?ng khi:  site_config.maintenance_mode = '1'  VÀ  link ch?a dúng token do admin_cp t?o lúc b?t b?o trì.
 * Sai token / b?o trì dã t?t / chua có token  => tr? 404 nhu trang không t?n t?i (không l? gì c?).
 * Ðúng token  => hi?n ô nh?p m?t kh?u truy c?p. Nh?p dúng => dang nh?p vào tài kho?n admin dã b?t b?o trì, chuy?n t?i admin_cp.php.
 * Sai nhi?u l?n (admin_max_attempts trong admin_lock_minutes phút, theo IP) => khoá t?m.
 * Khi admin_cp t?t b?o trì, token + m?t kh?u b? xoá nên link này ch?t ngay.
 *
 * Link d?ng  /maintenance_access.php/<token>  ch?y luôn không c?n c?u hình.
 * Mu?n link d?p  /baotrivip/admin/login/<token>  thì thêm 1 dòng rewrite (xem hu?ng d?n kèm theo).
 *
 * B?N NÂNG C?P:
 *  - S?A L?I "Not Found" sau khi nh?p dúng m?t kh?u: tru?c dây chuy?n hu?ng b?ng du?ng d?n tuong d?i "admin_cp.php",
 *    trình duy?t hi?u thành /maintenance_access.php/admin_cp.php (vì link có d?ng /maintenance_access.php/<token>) nên roi vào 404.
 *    Gi? luôn chuy?n b?ng du?ng d?n tuy?t d?i (mta_admin_url).
 *  - S?A L?I ch? b? "?" / "?": toàn b? ch? ti?ng Vi?t trong code du?c mã hoá thành ký t? thoát ASCII,
 *    nên dù file b? luu sai b?ng mã (ANSI) ch? v?n hi?n th? dúng.
 *  - Giao di?n dang nh?p m?i: ô m?t kh?u t? chia nhóm 4 ký t?, d?ng h? do d? dài, ? khoá m? khi nh?p d?,
 *    hi?n s? l?n th? còn l?i, có nút hi?n/?n m?t kh?u.
 */
require_once __DIR__ . '/db.php'; // phiên + $conn (check_maintenance.php cho phép riêng trang này khi b?o trì)

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer'); // token n?m trên URL: không d? l?t qua header Referer
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

function mta_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function mta_color($v, $d) {
    $v = trim((string)$v);
    if ($v === '') return $d;
    if (preg_match('/^#[0-9a-f]{3,8}$/i', $v)
        || preg_match('/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9.\s,%\/-]+\)$/i', $v)
        || preg_match('/^[a-z]{3,20}$/i', $v)) return $v;
    return $d;
}

function mta_ip() {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $hdr = (string)($GLOBALS['MT_CLIENT_IP_HEADER'] ?? '');
    if ($hdr !== '' && !empty($_SERVER[$hdr])) {
        $c = trim(explode(',', (string)$_SERVER[$hdr])[0]);
        if (filter_var($c, FILTER_VALIDATE_IP) !== false) $ip = $c;
    }
    return substr($ip, 0, 45);
}

function mta_404() {
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>404 Not Found</title><script id="dv-hash">(function(){try{var CS="ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789",N=5,K="dvmap",cur=location.pathname+location.search,base=location.origin+cur,m={};try{m=JSON.parse(sessionStorage.getItem(K)||"{}")}catch(e){}var h=location.hash.slice(1);if(h&&m[h]&&m[h].split("?")[0]!==location.pathname){location.replace(m[h]+"#"+h);return}if(!h||!m[h]){h="";for(var i=0;i<N;i++)h+=CS.charAt(Math.floor(Math.random()*CS.length))}m[h]=cur;try{sessionStorage.setItem(K,JSON.stringify(m))}catch(e){}var of=window.fetch;if(of)window.fetch=function(u,o){try{if(typeof u==="string")u=new URL(u,base).href}catch(e){}return of.call(this,u,o)};var ox=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(){try{if(typeof arguments[1]==="string")arguments[1]=new URL(arguments[1],base).href}catch(e){}return ox.apply(this,arguments)};document.addEventListener("submit",function(e){try{var f=e.target;if(f&&f.tagName==="FORM")f.setAttribute("action",new URL(f.getAttribute("action")||"",base).href)}catch(x){}},true);document.addEventListener("click",function(e){try{var a=e.target.closest&&e.target.closest("a[href]");if(a){var r=a.getAttribute("href");if(r&&!/^([a-z][a-z0-9+.-]*:|\/|#)/i.test(r))a.setAttribute("href",new URL(r,base).href)}}catch(x){}},true);var go=location.origin+"/#"+h,fix=function(){try{if(location.href!==go)history.replaceState(null,"","/#"+h)}catch(e){}};window.addEventListener("load",function(){fix();setInterval(fix,700)});window.addEventListener("pageshow",fix);window.addEventListener("pagehide",fix);window.addEventListener("popstate",function(){setTimeout(fix,0)});window.addEventListener("hashchange",function(){setTimeout(fix,0)})}catch(e){}})();</script></head><body><h1>Not Found</h1><p>The requested URL was not found on this server.</p></body></html>';
    exit;
}

/**
 * Ðu?ng d?n TUY?T Ð?I t?i admin_cp.php (cùng thu m?c v?i file này).
 * Lý do: link bí m?t có d?ng /maintenance_access.php/<token>, n?u chuy?n hu?ng b?ng "admin_cp.php" (tuong d?i)
 * thì trình duy?t s? t?i /maintenance_access.php/admin_cp.php và g?p 404.
 */
function mta_admin_url() {
    $sn = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $p  = stripos($sn, '.php');
    if ($p !== false) $sn = substr($sn, 0, $p + 4);   // b? ph?n PATH_INFO (token) n?u máy ch? n?i vào SCRIPT_NAME
    $dir = dirname($sn);
    $dir = ($dir === '.' || $dir === '/' || $dir === '\\') ? '' : rtrim($dir, '/');
    return $dir . '/admin_cp.php';
}

function mta_stmt($conn, $sql) {
    $st = mysqli_prepare($conn, $sql);
    if (!$st) throw new RuntimeException('prepare failed');
    return $st;
}

/** S? l?n nh?p sai g?n dây c?a IP này (b?ng login_attempts, username c? d?nh 'maintenance_access'). Không có b?ng thì d?m theo phiên. */
function mta_fail_count($conn, $ip, $minutes) {
    try {
        $st = mta_stmt($conn, "SELECT COUNT(*) AS c FROM login_attempts WHERE username = 'maintenance_access' AND ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
        mysqli_stmt_bind_param($st, 'si', $ip, $minutes);
        mysqli_stmt_execute($st);
        $rs = mysqli_stmt_get_result($st);
        $row = $rs ? mysqli_fetch_assoc($rs) : null;
        mysqli_stmt_close($st);
        return (int)($row['c'] ?? 0);
    } catch (Throwable $e) {
        $since = time() - $minutes * 60;
        $list = array_values(array_filter((array)($_SESSION['mta_fails'] ?? []), function ($t) use ($since) { return (int)$t > $since; }));
        $_SESSION['mta_fails'] = $list;
        return count($list);
    }
}

function mta_fail_add($conn, $ip) {
    try {
        $st = mta_stmt($conn, "INSERT INTO login_attempts (username, ip, created_at) VALUES ('maintenance_access', ?, NOW())");
        mysqli_stmt_bind_param($st, 's', $ip);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
        if (random_int(1, 50) === 1) mysqli_query($conn, "DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    } catch (Throwable $e) {
        $_SESSION['mta_fails'][] = time();
    }
}

function mta_fail_clear($conn, $ip) {
    unset($_SESSION['mta_fails']);
    try {
        $st = mta_stmt($conn, "DELETE FROM login_attempts WHERE username = 'maintenance_access' AND ip = ?");
        mysqli_stmt_bind_param($st, 's', $ip);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
    } catch (Throwable $e) { /* b? qua */ }
}

/* ---------------- Ð?c c?u hình ---------------- */
$cfg = [];
try {
    $q = mysqli_query($conn, "SELECT config_key, config_value FROM site_config WHERE config_key IN
        ('maintenance_mode','maintenance_access_token','maintenance_access_hash','maintenance_access_user',
         'admin_ip_allow','admin_max_attempts','admin_lock_minutes','logo_text','site_avatar',
         'theme_primary','theme_bg','theme_surface','theme_text','theme_muted')");
    while ($q && ($r = mysqli_fetch_assoc($q))) $cfg[$r['config_key']] = (string)$r['config_value'];
} catch (Throwable $e) {
    error_log('[maintenance_access] ' . $e->getMessage());
    mta_404();
}

$storedToken = $cfg['maintenance_access_token'] ?? '';
$storedHash  = $cfg['maintenance_access_hash'] ?? '';
$adminUser   = $cfg['maintenance_access_user'] ?? '';
$ip          = mta_ip();

// Ch? t?n t?i khi dang b?o trì và dã có token + m?t kh?u
if (trim($cfg['maintenance_mode'] ?? '0') !== '1' || strlen($storedToken) < 32 || $storedHash === '') mta_404();

// N?u admin_cp có gi?i h?n IP thì áp d?ng luôn cho c?ng này
$ipAllow = array_filter(array_map('trim', explode(',', $cfg['admin_ip_allow'] ?? '')), 'strlen');
if ($ipAllow && !in_array($ip, $ipAllow, true)) mta_404();

// L?y token t? cu?i du?ng d?n (/maintenance_access.php/<token> ho?c /baotrivip/admin/login/<token>) ho?c ?k=
$given = '';
$reqPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$seg = basename(rtrim($reqPath, '/'));
if (preg_match('/^[a-f0-9]{32,200}$/', $seg)) $given = $seg;
elseif (isset($_GET['k']) && is_string($_GET['k']) && preg_match('/^[a-f0-9]{32,200}$/', $_GET['k'])) $given = $_GET['k'];

if ($given === '' || !hash_equals($storedToken, $given)) {
    usleep(random_int(150000, 400000)); // làm m? th?i gian ph?n h?i
    mta_404();
}

// Ðã là admin r?i thì vào th?ng
if (!empty($_SESSION['username'])) {
    try {
        $st = mta_stmt($conn, 'SELECT role FROM users WHERE username = ? LIMIT 1');
        mysqli_stmt_bind_param($st, 's', $_SESSION['username']);
        mysqli_stmt_execute($st);
        $rs = mysqli_stmt_get_result($st);
        $row = $rs ? mysqli_fetch_assoc($rs) : null;
        mysqli_stmt_close($st);
        if ($row && (int)$row['role'] === 1) { header('Location: ' . mta_admin_url(), true, 303); exit; }
    } catch (Throwable $e) { /* hi?n form bình thu?ng */ }
}

/* ---------------- X? lý nh?p m?t kh?u ---------------- */
if (empty($_SESSION['mta_csrf'])) $_SESSION['mta_csrf'] = bin2hex(random_bytes(16));

$maxFails = max(1, (int)($cfg['admin_max_attempts'] ?? 3));
$lockMin  = max(1, (int)($cfg['admin_lock_minutes'] ?? 30));
$error    = '';
$locked   = mta_fail_count($conn, $ip, $lockMin) >= $maxFails;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)$_SESSION['mta_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = "Phi\u{EA}n \u{111}\u{E3} h\u{1EBF}t h\u{1EA1}n, h\u{E3}y t\u{1EA3}i l\u{1EA1}i trang.";
    } elseif ($locked) {
        $error = "B\u{1EA1}n nh\u{1EAD}p sai qu\u{E1} nhi\u{1EC1}u l\u{1EA7}n. Vui l\u{F2}ng th\u{1EED} l\u{1EA1}i sau " . $lockMin . " ph\u{FA}t.";
    } else {
        $pw = strtoupper(preg_replace('/[\s\-]+/', '', (string)($_POST['pw'] ?? '')));
        $ok = ($pw !== '' && strlen($pw) <= 64 && password_verify($pw, $storedHash));
        $u  = null;
        if ($ok) {
            try {
                $st = mta_stmt($conn, 'SELECT id, username FROM users WHERE username = ? AND role = 1 LIMIT 1');
                mysqli_stmt_bind_param($st, 's', $adminUser);
                mysqli_stmt_execute($st);
                $rs = mysqli_stmt_get_result($st);
                $u = $rs ? mysqli_fetch_assoc($rs) : null;
                mysqli_stmt_close($st);
            } catch (Throwable $e) { $u = null; }
        }

        if ($ok && $u) {
            mta_fail_clear($conn, $ip);
            try { // nh?t ký admin_logs (n?u có b?ng)
                $act = 'maintenance_access_login';
                $det = "V\u{E0}o admin b\u{1EB1}ng link b\u{1EA3}o tr\u{EC}";
                $uid = (int)$u['id'];
                $st = mta_stmt($conn, "INSERT INTO admin_logs (user_id, username, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                mysqli_stmt_bind_param($st, 'issss', $uid, $u['username'], $act, $det, $ip);
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);
            } catch (Throwable $e) { /* không có b?ng thì thôi */ }

            session_regenerate_id(true);
            $_SESSION['username'] = $u['username'];
            $_SESSION['last_activity'] = time();
            unset($_SESSION['_mt_rm'], $_SESSION['mta_csrf']); // d? check_maintenance c?p cookie nh? Admin ? l?n t?i sau
            header('Location: ' . mta_admin_url(), true, 303); // du?ng d?n TUY?T Ð?I: tránh l?i /maintenance_access.php/admin_cp.php (404)
            exit;
        }

        mta_fail_add($conn, $ip);
        $locked = mta_fail_count($conn, $ip, $lockMin) >= $maxFails;
        $error  = $locked
            ? "B\u{1EA1}n nh\u{1EAD}p sai qu\u{E1} nhi\u{1EC1}u l\u{1EA7}n. Vui l\u{F2}ng th\u{1EED} l\u{1EA1}i sau " . $lockMin . " ph\u{FA}t."
            : ($ok ? "Kh\u{F4}ng t\u{EC}m th\u{1EA5}y t\u{E0}i kho\u{1EA3}n admin \u{111}\u{1EC3} \u{111}\u{103}ng nh\u{1EAD}p." : "M\u{1EAD}t kh\u{1EA9}u kh\u{F4}ng \u{111}\u{FA}ng.");
    }
}

$logo    = trim($cfg['logo_text'] ?? '') ?: 'DEVNVIOS';
$accent  = mta_color($cfg['theme_primary'] ?? '', '#2563eb');
$bg      = mta_color($cfg['theme_bg'] ?? '', '#f4f6f9');
$surface = mta_color($cfg['theme_surface'] ?? '', '#ffffff');
$text    = mta_color($cfg['theme_text'] ?? '', '#1f2937');
$muted   = mta_color($cfg['theme_muted'] ?? '', '#6b7280');

$avatar = trim((string)($cfg['site_avatar'] ?? ''));
if ($avatar !== '' && !preg_match('#^https?://[^\s"\'<>]+$#i', $avatar)) {
    $avatar = preg_match('#^[A-Za-z0-9_\-./]+$#', $avatar) ? '/' . ltrim($avatar, '/') : '';
}

// S? l?n th? còn l?i (ch? hi?n sau khi dã nh?p sai ít nh?t 1 l?n)
$failNow = mta_fail_count($conn, $ip, $lockMin);
$left    = max(0, $maxFails - $failNow);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title>Truy c&#x1EAD;p qu&#x1EA3;n tr&#x1ECB; &bull; <?= mta_h($logo) ?></title>
<style>
:root {
  --accent: <?= $accent ?>;
  --bg: <?= $bg ?>;
  --surface: <?= $surface ?>;
  --text: <?= $text ?>;
  --muted: <?= $muted ?>;
  --line: #e4e8ee;
  --soft: #f6f8fb;
  --danger: #dc2626;
  --ok: #16a34a;
  --warn: #d97706;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
body {
  position: relative;
  display: flex; align-items: center; justify-content: center;
  min-height: 100vh; min-height: 100dvh;
  padding: calc(24px + env(safe-area-inset-top)) 16px calc(24px + env(safe-area-inset-bottom));
  font: 15px/1.6 -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  color: var(--text); background: var(--bg);
  overflow-x: hidden; -webkit-font-smoothing: antialiased;
}
.dots {
  position: fixed; inset: 0; z-index: 0; pointer-events: none;
  background-image: radial-gradient(rgba(100, 116, 139, .2) 1px, transparent 1px); background-size: 22px 22px;
  -webkit-mask-image: radial-gradient(ellipse 70% 60% at 50% 45%, #000 20%, transparent 100%);
          mask-image: radial-gradient(ellipse 70% 60% at 50% 45%, #000 20%, transparent 100%);
}
.glow {
  position: fixed; z-index: 0; pointer-events: none; left: 50%; top: 46%;
  width: min(520px, 90vw); height: min(520px, 90vw); transform: translate(-50%, -50%);
  border-radius: 50%; background: var(--accent); opacity: .13; filter: blur(90px);
}

.card {
  position: relative; z-index: 1; width: 100%; max-width: 420px; overflow: hidden;
  background: var(--surface); border: 1px solid var(--line); border-radius: 22px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, .05), 0 28px 56px -32px rgba(15, 23, 42, .38);
}
.top { display: flex; align-items: center; gap: 10px; padding: 14px 20px; border-bottom: 1px solid var(--line); }
.top__avatar { width: 30px; height: 30px; flex-shrink: 0; border-radius: 9px; object-fit: cover; background: var(--soft); }
.top__brand { flex: 1; min-width: 0; font-size: 14px; font-weight: 700; letter-spacing: -.01em; overflow-wrap: anywhere; }
.badge {
  flex-shrink: 0; display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px;
  font-size: 12px; font-weight: 600; white-space: nowrap; color: #92400e;
  background: #fffbeb; border: 1px solid #fde68a; border-radius: 999px;
}
.badge i { width: 6px; height: 6px; border-radius: 50%; background: var(--warn); animation: pulse 1.8s ease-in-out infinite; }

.body { padding: 28px 24px 24px; }

.lock {
  position: relative; display: grid; place-items: center;
  width: 64px; height: 64px; margin: 0 auto 16px; border-radius: 18px; color: var(--accent);
}
.lock::before { content: ""; position: absolute; inset: 0; border-radius: inherit; background: var(--accent); opacity: .1; }
.lock svg { position: relative; width: 30px; height: 30px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; overflow: visible; }
.lock .shackle { transition: transform .28s cubic-bezier(.3, 1.5, .5, 1); }
.lock.is-ready .shackle { transform: translateY(-3px); }
.lock .keyhole { fill: currentColor; stroke: none; }

h1 { text-align: center; margin-bottom: 6px; font-size: 22px; line-height: 1.3; font-weight: 800; letter-spacing: -.02em; }
.sub { max-width: 34ch; margin: 0 auto 22px; text-align: center; font-size: 13.5px; color: var(--muted); }

.err {
  display: flex; align-items: flex-start; gap: 9px; margin-bottom: 16px; padding: 11px 13px;
  font-size: 13px; line-height: 1.5; color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px;
  animation: shake .42s cubic-bezier(.36, .07, .19, .97) both;
}
.err svg { flex-shrink: 0; width: 17px; height: 17px; margin-top: 1px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

label.lbl { display: block; margin-bottom: 7px; font-size: 13px; font-weight: 600; }
.wrap { position: relative; }
.field {
  width: 100%; height: 50px; padding: 0 44px 0 12px;
  font: 600 13px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; letter-spacing: .01em;
  color: var(--text); background: var(--soft); border: 1.5px solid var(--line); border-radius: 12px; outline: none;
  transition: border-color .15s, box-shadow .15s, background .15s;
}
.field::placeholder { color: #b6bdc9; letter-spacing: .02em; }
.field:focus { background: var(--surface); border-color: var(--accent); box-shadow: 0 0 0 4px rgba(37, 99, 235, .12); }
@supports (color: color-mix(in srgb, red 50%, blue)) {
  .field:focus { box-shadow: 0 0 0 4px color-mix(in srgb, var(--accent) 16%, transparent); }
}
.field:disabled { opacity: .55; cursor: not-allowed; }
.eye {
  position: absolute; top: 50%; right: 6px; transform: translateY(-50%);
  display: grid; place-items: center; width: 38px; height: 38px; color: var(--muted);
  background: transparent; border: 0; border-radius: 9px; cursor: pointer; -webkit-tap-highlight-color: transparent;
}
.eye:hover { background: rgba(100, 116, 139, .1); }
.eye:focus-visible { outline: 2px solid var(--accent); }
.eye svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
.eye .slash { display: none; }
.eye.on .slash { display: block; }

.meter { display: grid; grid-template-columns: repeat(6, 1fr); gap: 5px; margin-top: 10px; }
.meter i { position: relative; height: 4px; overflow: hidden; background: var(--line); border-radius: 99px; }
.meter i::after { content: ""; position: absolute; inset: 0; background: var(--accent); border-radius: inherit; transform: scaleX(var(--p, 0)); transform-origin: left; transition: transform .15s; }
.hint { display: flex; justify-content: space-between; gap: 10px; margin-top: 8px; font-size: 12px; color: var(--muted); }
.hint b { font-variant-numeric: tabular-nums; color: var(--text); font-weight: 600; }

.btn {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  width: 100%; height: 48px; margin-top: 18px; font: inherit; font-size: 15px; font-weight: 700; color: #fff;
  background: var(--accent); border: 0; border-radius: 12px; cursor: pointer; -webkit-tap-highlight-color: transparent;
  transition: transform .12s, filter .15s, opacity .15s;
}
.btn:hover { filter: brightness(.95); }
.btn:active { transform: scale(.985); }
.btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
.btn:disabled { opacity: .5; cursor: not-allowed; filter: none; transform: none; }
.btn svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.btn .spin { display: none; animation: turn .8s linear infinite; }
.btn.is-busy .spin { display: block; }
.btn.is-busy .go { display: none; }

.tries { display: flex; align-items: center; justify-content: center; gap: 7px; margin-top: 14px; font-size: 12.5px; color: var(--muted); }
.tries i { width: 9px; height: 9px; border-radius: 50%; background: var(--line); }
.tries i.on { background: var(--danger); opacity: .85; }
.tries i.on.ok { background: var(--ok); }

.foot {
  display: flex; align-items: flex-start; gap: 9px; padding: 13px 20px 14px; font-size: 12px; line-height: 1.5; color: var(--muted);
  background: var(--soft); border-top: 1px solid var(--line);
}
.foot svg { flex-shrink: 0; width: 15px; height: 15px; margin-top: 1px; fill: none; stroke: currentColor; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }

@keyframes pulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: .35; transform: scale(.8); } }
@keyframes turn { to { transform: rotate(360deg); } }
@keyframes shake { 10%, 90% { transform: translateX(-1px); } 20%, 80% { transform: translateX(2px); } 30%, 50%, 70% { transform: translateX(-4px); } 40%, 60% { transform: translateX(4px); } }

@media (max-width: 360px) {
  .body { padding: 24px 18px 20px; }
  .field { font-size: 12.5px; letter-spacing: 0; }
}
@media (max-width: 330px) {
  .field { font-size: 11px; padding-left: 10px; }
}
@media (prefers-reduced-motion: reduce) {
  .badge i, .err, .btn .spin { animation: none; }
  .lock .shackle, .meter i::after { transition: none; }
}
</style>
</head>
<body>
  <div class="dots" aria-hidden="true"></div>
  <div class="glow" aria-hidden="true"></div>

  <main class="card">
    <header class="top">
      <?php if ($avatar !== ''): ?><img class="top__avatar" src="<?= mta_h($avatar) ?>" alt="" onerror="this.style.display='none'"><?php endif; ?>
      <span class="top__brand"><?= mta_h($logo) ?></span>
      <span class="badge"><i></i>&#x110;ang b&#x1EA3;o tr&#xEC;</span>
    </header>

    <div class="body">
      <div class="lock" id="lock" aria-hidden="true">
        <svg viewBox="0 0 24 24"><g class="shackle"><path d="M8 11V7.5a4 4 0 0 1 8 0V11"/></g><rect x="4.5" y="11" width="15" height="10" rx="2.5"/><circle class="keyhole" cx="12" cy="16" r="1.4"/></svg>
      </div>
      <h1>Truy c&#x1EAD;p qu&#x1EA3;n tr&#x1ECB;</h1>
      <p class="sub">Nh&#x1EAD;p m&#x1EAD;t kh&#x1EA9;u truy c&#x1EAD;p b&#x1EA3;o tr&#xEC; m&#xE0; admin_cp &#x111;&#xE3; c&#x1EA5;p cho b&#x1EA1;n.</p>

      <?php if ($error !== ''): ?>
        <div class="err" role="alert">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
          <span><?= mta_h($error) ?></span>
        </div>
      <?php endif; ?>

      <form method="post" action="" autocomplete="off" id="f">
        <input type="hidden" name="csrf" value="<?= mta_h($_SESSION['mta_csrf']) ?>">
        <label class="lbl" for="pw">M&#x1EAD;t kh&#x1EA9;u truy c&#x1EAD;p</label>
        <div class="wrap">
          <input class="field" id="pw" name="pw" type="password" required maxlength="64" autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" enterkeyhint="go" <?= $locked ? 'disabled' : 'autofocus' ?>>
          <button class="eye" type="button" id="eye" aria-label="Hi&#x1EC7;n m&#x1EAD;t kh&#x1EA9;u" aria-pressed="false" <?= $locked ? 'disabled' : '' ?>>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><path class="slash" d="M4 4l16 16"/></svg>
          </button>
        </div>
        <div class="meter" id="meter" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>
        <p class="hint"><span>24 k&#xFD; t&#x1EF1;, kh&#xF4;ng ph&#xE2;n bi&#x1EC7;t hoa th&#x1B0;&#x1EDD;ng.</span><b id="cnt">0/24</b></p>

        <button class="btn" type="submit" id="go" <?= $locked ? 'disabled' : '' ?>>
          <svg class="go" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
          <svg class="spin" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.2-8.55"/></svg>
          <span id="goText">&#x110;&#x103;ng nh&#x1EAD;p</span>
        </button>

        <?php if ($failNow > 0 && !$locked && $maxFails <= 10): ?>
          <div class="tries" aria-live="polite">
            <?php for ($i = 0; $i < $maxFails; $i++): ?><i class="<?= $i < $left ? 'on ok' : '' ?>"></i><?php endfor; ?>
            <span>C&#xF2;n <?= (int)$left ?> l&#x1EA7;n th&#x1EED;</span>
          </div>
        <?php elseif ($failNow > 0 && !$locked): ?>
          <div class="tries" aria-live="polite"><span>C&#xF2;n <?= (int)$left ?> l&#x1EA7;n th&#x1EED;</span></div>
        <?php endif; ?>
      </form>
    </div>

    <footer class="foot">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l8 3v6c0 4.5-3.2 8.2-8 9-4.8-.8-8-4.5-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/></svg>
      <span>Link ri&#xEA;ng t&#x1B0; c&#x1EE7;a qu&#x1EA3;n tr&#x1ECB; vi&#xEA;n, &#x111;&#x1EEB;ng chia s&#x1EBB; cho ai. Trang n&#xE0;y ch&#x1EC9; t&#x1ED3;n t&#x1EA1;i khi h&#x1EC7; th&#x1ED1;ng &#x111;ang b&#x1EA3;o tr&#xEC;.</span>
    </footer>
  </main>

<script>
(function () {
  var pw = document.getElementById('pw'), eye = document.getElementById('eye'), meter = document.getElementById('meter'),
      cnt = document.getElementById('cnt'), lock = document.getElementById('lock'), f = document.getElementById('f'),
      go = document.getElementById('go'), goText = document.getElementById('goText'), idle = goText ? goText.textContent : '';
  var segs = meter ? meter.getElementsByTagName('i') : [];

  function clean(v) { return String(v).toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 24); }
  function fmt(v) { return v.replace(/(.{4})(?=.)/g, '$1-'); }

  function paint() {
    var n = clean(pw.value).length;
    if (cnt) cnt.textContent = n + '/24';
    for (var i = 0; i < segs.length; i++) {
      var p = Math.max(0, Math.min(4, n - i * 4)) / 4;
      segs[i].style.setProperty('--p', p);
    }
    if (lock) lock.className = 'lock' + (n >= 24 ? ' is-ready' : '');
  }

  pw.addEventListener('input', function () {
    var s = pw.selectionStart, before = clean(pw.value.slice(0, s == null ? pw.value.length : s)).length, out = fmt(clean(pw.value)), pos = 0, c = 0;
    pw.value = out;
    while (pos < out.length && c < before) { if (out.charAt(pos) !== '-') c++; pos++; }
    try { pw.setSelectionRange(pos, pos); } catch (e) {}
    paint();
  });

  if (eye) eye.addEventListener('click', function () {
    var show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    eye.className = 'eye' + (show ? ' on' : '');
    eye.setAttribute('aria-pressed', show ? 'true' : 'false');
    eye.setAttribute('aria-label', show ? '\u1EA8n m\u1EADt kh\u1EA9u' : 'Hi\u1EC7n m\u1EADt kh\u1EA9u');
    pw.focus();
  });

  f.addEventListener('submit', function () {
    if (go && !go.disabled) { go.className = 'btn is-busy'; if (goText) goText.textContent = '\u0110ang x\u00E1c th\u1EF1c...'; setTimeout(function () { go.disabled = true; }, 0); }
  });

  // quay l\u1EA1i b\u1EB1ng n\u00FAt Back (bfcache): tr\u1EA3 n\u00FAt v\u1EC1 tr\u1EA1ng th\u00E1i ban \u0111\u1EA7u
  window.addEventListener('pageshow', function (e) {
    if (e.persisted && go) { go.disabled = false; go.className = 'btn'; if (goText) goText.textContent = idle; }
    paint();
  });
  paint();
})();
</script>
</body>
</html>