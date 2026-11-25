<?php
/* ===== db.php (b?n n�ng c?p) =====
   Gi? nguy�n to�n b? code g?c: session, k?t n?i CSDL, $st, li�n k?t file b?o tr�.
   Ph?n TH�M m?i (kh�ng xo�, kh�ng s?a d? li?u cu): c?ng ho� session, header b?o m?t, ch?ng d� key,
   kho� m? tool theo gi�y (server), CSRF, v� c�c h�m cho trang security.php. */

// ---- C?ng ho� cookie phi�n (tru?c session_start) ----
if (is_file(__DIR__ . '/security_shield.php')) {
    require_once __DIR__ . '/security_shield.php';
    sh_early_guard();
}

$ds_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

// Tr�nh l?i Notice tr�ng session_start()
if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.gc_maxlifetime', '18000');
    @ini_set('session.cookie_lifetime', '18000');
    if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
        session_set_cookie_params([
            'lifetime' => 18000,
            'path'     => '/',
            'secure'   => $ds_https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

// ---- Header b?o m?t co b?n ----
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// K?t n?i CSDL
$conn = false;
try {
    if (is_file(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
$ds_db_host = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: 'localhost');
$ds_db_user = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: 'your_db_user');
$ds_db_pass = defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') ?: 'your_db_password');
$ds_db_name = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: 'your_db_name');
$conn = mysqli_connect($ds_db_host, $ds_db_user, $ds_db_pass, $ds_db_name);
} catch (Throwable $ds_e) {
    error_log('DB connect failed: ' . $ds_e->getMessage());
    $conn = false;
}
if (!$conn) {
    // kh�ng in chi ti?t l?i ra m�n h�nh (l? th�ng tin m�y ch?), ch? ghi v�o log
    error_log('DB connect error: ' . mysqli_connect_error());
    die("L?i k?t n?i co s? d? li?u.");
}
mysqli_set_charset($conn, "utf8mb4");
$st = [];
if (function_exists('sh_post_db_guard')) {
    sh_post_db_guard($conn);
}

/* ================= C?U H�NH (d?i n?u c?n) ================= */
// B?ng ngu?i d�ng d�ng cho trang security.php (d?i cho d�ng v?i database c?a b?n)
if (!defined('DS_USERS_TABLE')) define('DS_USERS_TABLE', 'users');
if (!defined('DS_COL_ID'))      define('DS_COL_ID', 'id');
if (!defined('DS_COL_USER'))    define('DS_COL_USER', 'username');
if (!defined('DS_COL_PASS'))    define('DS_COL_PASS', 'password');
// M? l?i tool trong bao nhi�u gi�y th� kh�ng m?t lu?t; qu� s? gi�y n�y ph?i nh?p key l?i
if (!defined('DS_REOPEN_SECONDS')) define('DS_REOPEN_SECONDS', 20);
// Ch?ng d� key: t?i da N l?n sai trong M gi�y cho m?i IP
if (!defined('DS_KEY_MAX_FAILS')) define('DS_KEY_MAX_FAILS', 8);
if (!defined('DS_KEY_WINDOW'))    define('DS_KEY_WINDOW', 600);
// Ch? b?t n?u site ch?y sau Cloudflare (d? l?y IP th?t c?a kh�ch): define('DS_TRUST_CF', true);

/* ================= H�M TI?N �CH (th�m m?i) ================= */

/** Ch?y c�u l?nh c� tham s? (prepared). SELECT tr? v? m?ng d�ng, INSERT/UPDATE/DELETE tr? v? true, l?i tr? v? false. */
if (!function_exists('ds_q')) {
    function ds_q($sql, $types = '', array $params = [])
    {
        global $conn;
        try {
            $stmt = mysqli_prepare($conn, $sql);
            if (!$stmt) { return false; }
            if ($types !== '' && $params) { mysqli_stmt_bind_param($stmt, $types, ...$params); }
            if (!mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); return false; }
            $res = mysqli_stmt_get_result($stmt);
            if ($res === false) { mysqli_stmt_close($stmt); return true; }
            $rows = [];
            while ($r = mysqli_fetch_assoc($res)) { $rows[] = $r; }
            mysqli_free_result($res);
            mysqli_stmt_close($stmt);
            return $rows;
        } catch (Throwable $e) {
            error_log('ds_q: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('ds_client_ip')) {
    function ds_client_ip()
    {
        if (function_exists('sh_client_ip')) { $sip = sh_client_ip(); if ($sip !== '') { return $sip; } }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if (defined('DS_TRUST_CF') && DS_TRUST_CF && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])
            && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)) {
            $ip = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        return substr($ip, 0, 45);
    }
}

/* ---------- CSRF ---------- */
if (!function_exists('ds_csrf_token')) {
    function ds_csrf_token()
    {
        if (empty($_SESSION['ds_csrf'])) { $_SESSION['ds_csrf'] = bin2hex(random_bytes(32)); }
        return $_SESSION['ds_csrf'];
    }
    function ds_csrf_check($t)
    {
        return !empty($_SESSION['ds_csrf']) && is_string($t) && hash_equals($_SESSION['ds_csrf'], $t);
    }
}

/* ---------- B?o m?t KEY ----------
   D�ng trong index.php (ch? x? l� nh?p key):
     $key = ds_key_clean($_POST['key'] ...);                 // l�m s?ch key nh?p v�o
     if (ds_key_throttle_blocked()) { ...b�o "th? l?i sau 10 ph�t"; }
     ... so kh?p key trong DB ...
     d�ng: ds_key_throttle_clear(); ds_open_gate_key_ok();
     sai:  ds_key_throttle_fail();
   So s�nh key n�n d�ng ds_key_equals() (ch?ng do�n qua th?i gian ph?n h?i). */
if (!function_exists('ds_key_clean')) {
    function ds_key_clean($k)
    {
        $k = (string) $k;
        $c = preg_replace('/[\x00-\x20\x7F\x{200B}-\x{200D}\x{FEFF}]+/u', '', $k);
        if ($c === null) { $c = preg_replace('/[^\x21-\x7E]+/', '', $k); }
        return substr((string) $c, 0, 200);
    }
    function ds_key_equals($a, $b)
    {
        return hash_equals((string) $a, (string) $b);
    }
    function ds_key_mask($k)
    {
        $k = (string) $k; $n = strlen($k);
        if ($n <= 6) { return str_repeat('*', $n); }
        return substr($k, 0, 3) . str_repeat('*', max(3, $n - 5)) . substr($k, -2);
    }
    /** Bam key d? luu DB (d�ng khi b?n mu?n chuy?n key sang d?ng bam). �?t define('DS_KEY_PEPPER', 'chuoi-bi-mat-dai') trong db.php */
    function ds_key_hash($k)
    {
        $pepper = defined('DS_KEY_PEPPER') ? (string) DS_KEY_PEPPER : '';
        return hash_hmac('sha256', ds_key_clean($k), $pepper);
    }
}

if (!function_exists('ds_key_throttle_ensure')) {
    function ds_key_throttle_ensure()
    {
        global $conn;
        if (!empty($_SESSION['ds_kt_tbl'])) { return true; }
        try {
            $ok = (bool) mysqli_query($conn, "CREATE TABLE IF NOT EXISTS ds_key_attempts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(45) NOT NULL,
                created_at INT UNSIGNED NOT NULL,
                KEY idx_ip_time (ip, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) { $ok = false; }
        if ($ok) { $_SESSION['ds_kt_tbl'] = 1; }
        return $ok;
    }
    function ds_key_throttle_blocked()
    {
        if (!ds_key_throttle_ensure()) { return false; }
        $r = ds_q('SELECT COUNT(*) AS c FROM ds_key_attempts WHERE ip = ? AND created_at > ?', 'si', [ds_client_ip(), time() - (int) DS_KEY_WINDOW]);
        return is_array($r) && isset($r[0]['c']) && (int) $r[0]['c'] >= (int) DS_KEY_MAX_FAILS;
    }
    function ds_key_throttle_fail()
    {
        if (!ds_key_throttle_ensure()) { return; }
        ds_q('INSERT INTO ds_key_attempts (ip, created_at) VALUES (?, ?)', 'si', [ds_client_ip(), time()]);
        if (mt_rand(1, 50) === 1) { // th?nh tho?ng d?n log cu c?a ch�nh b?ng n�y
            ds_q('DELETE FROM ds_key_attempts WHERE created_at < ?', 'i', [time() - 86400]);
        }
    }
    function ds_key_throttle_clear()
    {
        if (!ds_key_throttle_ensure()) { return; }
        ds_q('DELETE FROM ds_key_attempts WHERE ip = ?', 's', [ds_client_ip()]);
    }
}

/* ---------- Kho� m? tool theo gi�y (ch?n ? SERVER, g?n v?i phi�n dang nh?p) ----------
   Trong index.php, ch? x? l� b?m m? tool:
     if (!ds_open_gate_check($toolId)) { ...y�u c?u nh?p key, KH�NG m? tool; }
     else { ...m? tool nhu cu...; ds_open_gate_register($toolId); }
   Ch? c� s? "90" gi�y (m? l?i kh�ng m?t lu?t) thay b?ng DS_REOPEN_SECONDS.
   Khi key du?c ch?p nh?n: g?i ds_open_gate_key_ok(). */
if (!function_exists('ds_open_gate_check')) {
    function ds_open_gate_check($toolId = 0)
    {
        $o = (isset($_SESSION['ds_open']) && is_array($_SESSION['ds_open'])) ? $_SESSION['ds_open'] : [];
        if (empty($o['t'])) { return true; }                                   // chua m? l?n n�o
        if (!empty($o['k']) && (int) $o['k'] >= (int) $o['t']) { return true; } // d� nh?p key m?i sau l?n m? tru?c
        return (time() - (int) $o['t']) < (int) DS_REOPEN_SECONDS;             // c�n trong th?i gian m? l?i
    }
    function ds_open_gate_register($toolId = 0)
    {
        $o = (isset($_SESSION['ds_open']) && is_array($_SESSION['ds_open'])) ? $_SESSION['ds_open'] : [];
        $fresh = empty($o['t']) || (!empty($o['k']) && (int) $o['k'] >= (int) $o['t']);
        if ($fresh) { $_SESSION['ds_open'] = ['t' => time(), 'id' => (int) $toolId, 'k' => 0]; }
    }
    function ds_open_gate_key_ok()
    {
        if (!isset($_SESSION['ds_open']) || !is_array($_SESSION['ds_open'])) { $_SESSION['ds_open'] = ['t' => 0, 'id' => 0, 'k' => 0]; }
        $_SESSION['ds_open']['k'] = time();
    }
}

/* ---------- Ngu?i d�ng hi?n t?i + tr?ng th�i b?o m?t (cho security.php v� theme) ---------- */
if (!function_exists('ds_ident')) {
    function ds_ident($s)
    {
        return (is_string($s) && preg_match('/^[A-Za-z0-9_]{1,64}$/', $s)) ? $s : null;
    }
    /** Tr? v? d�ng ngu?i d�ng trong b?ng DS_USERS_TABLE theo phi�n dang nh?p, ho?c null n?u chua dang nh?p / kh�ng t�m th?y. */
    function ds_current_user()
    {
        static $cache = false;
        if ($cache !== false) { return $cache; }
        $cache = null;
        $tbl = ds_ident(DS_USERS_TABLE); $cid = ds_ident(DS_COL_ID); $cus = ds_ident(DS_COL_USER);
        if (!$tbl || !$cid || !$cus) { return $cache; }
        $idv = null; $un = null;
        foreach (['user_id', 'uid', 'id'] as $k) {
            if (isset($_SESSION[$k]) && is_scalar($_SESSION[$k]) && (string) $_SESSION[$k] !== '') { $idv = (string) $_SESSION[$k]; break; }
        }
        foreach (['username', 'user', 'name', 'login'] as $k) {
            if (isset($_SESSION[$k]) && is_scalar($_SESSION[$k]) && (string) $_SESSION[$k] !== '') { $un = (string) $_SESSION[$k]; break; }
        }
        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            if ($idv === null && isset($_SESSION['user'][DS_COL_ID]) && is_scalar($_SESSION['user'][DS_COL_ID])) { $idv = (string) $_SESSION['user'][DS_COL_ID]; }
            if ($un === null && isset($_SESSION['user'][DS_COL_USER]) && is_scalar($_SESSION['user'][DS_COL_USER])) { $un = (string) $_SESSION['user'][DS_COL_USER]; }
        }
        $rows = false;
        if ($idv !== null) { $rows = ds_q("SELECT * FROM `$tbl` WHERE `$cid` = ? LIMIT 1", 's', [$idv]); }
        if ((!$rows) && $un !== null) { $rows = ds_q("SELECT * FROM `$tbl` WHERE `$cus` = ? LIMIT 1", 's', [$un]); }
        if (is_array($rows) && isset($rows[0])) { $cache = $rows[0]; }
        return $cache;
    }
    function ds_uid($u)
    {
        if (!is_array($u)) { return ''; }
        if (isset($u[DS_COL_ID]) && $u[DS_COL_ID] !== '') { return substr('i' . $u[DS_COL_ID], 0, 100); }
        return isset($u[DS_COL_USER]) ? substr('u' . $u[DS_COL_USER], 0, 100) : '';
    }
}

if (!function_exists('ds_security_ensure')) {
    /** B?ng ri�ng user_security (CREATE TABLE IF NOT EXISTS): kh�ng d?ng t?i b?ng users hi?n c�. */
    function ds_security_ensure()
    {
        global $conn;
        if (!empty($_SESSION['ds_sec_tbl'])) { return true; }
        try {
            $ok = (bool) mysqli_query($conn, "CREATE TABLE IF NOT EXISTS user_security (
                uid VARCHAR(100) NOT NULL PRIMARY KEY,
                email VARCHAR(190) NULL,
                email_ok TINYINT(1) NOT NULL DEFAULT 0,
                phone VARCHAR(32) NULL,
                phone_ok TINYINT(1) NOT NULL DEFAULT 0,
                pend_kind VARCHAR(10) NULL,
                pend_val VARCHAR(190) NULL,
                pend_hash VARCHAR(64) NULL,
                pend_exp BIGINT NULL,
                pend_tries INT NOT NULL DEFAULT 0,
                updated_at BIGINT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) { $ok = false; }
        if ($ok) { $_SESSION['ds_sec_tbl'] = 1; }
        return $ok;
    }
    /** ['email'=>bool, 'phone'=>bool, 'email_masked'=>'', 'phone_masked'=>''] ho?c null n?u chua dang nh?p. */
    function ds_security_status()
    {
        $u = ds_current_user();
        if (!$u || !ds_security_ensure()) { return null; }
        $rows = ds_q('SELECT email, email_ok, phone, phone_ok FROM user_security WHERE uid = ? LIMIT 1', 's', [ds_uid($u)]);
        $r = (is_array($rows) && isset($rows[0])) ? $rows[0] : [];
        $em = !empty($r['email']) && !empty($r['email_ok']);
        $ph = !empty($r['phone']) && !empty($r['phone_ok']);
        return [
            'email' => $em, 'phone' => $ph,
            'email_masked' => $em ? $r['email'] : '', 'phone_masked' => $ph ? $r['phone'] : '',
        ];
    }
}

/* ================= GUI MA XAC MINH QUA GMAIL (them moi) =================
   security.php gui ma 6 so toi Gmail cua nguoi dung qua SMTP cua Gmail.
   Nguoi gui: your_email@example.com, ten hien thi: devnvios.io.vn
   QUAN TRONG: thay PASTE_APP_PASSWORD_HERE bang "Mat khau ung dung" (16 ky tu) cua tai khoan your_email@example.com.
   Cach lay: Tai khoan Google -> Bao mat -> bat Xac minh 2 buoc -> tim "Mat khau ung dung" (App passwords) -> tao mat khau cho "Mail".
   Khong dung mat khau Gmail thuong. Khong gui file db.php nay cho nguoi khac. */
if (!defined('DS_SMTP_HOST'))   define('DS_SMTP_HOST', 'smtp.gmail.com');
if (!defined('DS_SMTP_PORT'))   define('DS_SMTP_PORT', 465);
if (!defined('DS_SMTP_SECURE')) define('DS_SMTP_SECURE', 'ssl');   // ssl (cong 465) hoac tls (cong 587)
if (!defined('DS_SMTP_USER'))   define('DS_SMTP_USER', 'your_email@example.com');
if (!defined('DS_SMTP_PASS'))   define('DS_SMTP_PASS', 'YOUR_GMAIL_APP_PASSWORD');
if (!defined('DS_MAIL_FROM'))   define('DS_MAIL_FROM', 'your_email@example.com');
if (!defined('DS_MAIL_NAME'))   define('DS_MAIL_NAME', 'devnvios.io.vn');
// Chan doan loi gui mail: true = trang security.php hien ly do loi, va mo /security.php?mailtest=email@gmail.com de thu. Chay on dinh roi thi doi thanh false.
if (!defined('DS_MAIL_DEBUG'))  define('DS_MAIL_DEBUG', true);

// ================= LI�N K?T FILE B?O TR� ================= //
// T? d?ng ki?m tra b?o tr� cho m?i trang khi nh�ng db.php
if (file_exists(__DIR__ . '/check_maintenance.php')) {
    include_once __DIR__ . '/check_maintenance.php';
}
?>
