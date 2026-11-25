<?php
/* security.php: d?i m?t kh?u, liên k?t Gmail, liên k?t s? di?n tho?i.
   Ð?t cùng thu m?c v?i db.php và index.php. M? b?ng /security.php
   - Không s?a b?ng users ngoài c?t m?t kh?u khi d?i m?t kh?u. Gmail / s? di?n tho?i luu ? b?ng riêng user_security (t? t?o).
   - M?t kh?u m?i du?c luu CÙNG Ð?NH D?NG v?i m?t kh?u hi?n t?i (bcrypt / md5 / sha1 / sha256 / thu?ng), d? trang dang nh?p hi?n có v?n dang nh?p du?c.
   - Gmail: g?i mã 6 s? b?ng mail() c?a host. T?t xác minh mã: define('DS_VERIFY_EMAIL', false); (trong db.php)
   - S? di?n tho?i: n?u b?n d?nh nghia hàm ds_send_sms($phone, $message): bool thì s? xác minh b?ng mã SMS; n?u không có thì ch? luu s? (chua xác minh). */

require_once __DIR__ . '/db.php';
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

if (!defined('DS_VERIFY_EMAIL')) { define('DS_VERIFY_EMAIL', true); }
/* Phone linking is no longer required: only Gmail is needed. The phone code below is kept as it was; set DS_SHOW_PHONE to true (in db.php) to show the phone card again. */
if (!defined('DS_SHOW_PHONE')) { define('DS_SHOW_PHONE', false); }
/* Gmail linking / email codes are switched off: this page is only for changing the password. The Gmail code is kept as it was; set DS_SHOW_EMAIL to true (in db.php) to bring the Gmail card back. */
if (!defined('DS_SHOW_EMAIL')) { define('DS_SHOW_EMAIL', false); }

$u = function_exists('ds_current_user') ? ds_current_user() : null;
if (!$u) { header('Location: /dang-nhap'); exit; }

function sec_h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function sec_secret() { return defined('DS_SEC_SECRET') ? (string) DS_SEC_SECRET : hash('sha256', __DIR__ . '|' . (defined('DS_KEY_PEPPER') ? DS_KEY_PEPPER : 'ds')); }
function sec_flash($type, $msg) { $_SESSION['sec_flash'] = [$type, $msg]; }
function sec_back($anchor) { header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#' . $anchor); exit; }

/* ----- M?t kh?u: nh?n di?n d?nh d?ng dang dùng và gi? nguyên d?nh d?ng dó ----- */
function sec_pw_kind($s)
{
    $s = (string) $s;
    if (preg_match('/^\$(2[abxy]|argon2id?)\$/', $s)) { return 'hash'; }
    if (preg_match('/^[a-f0-9]{32}$/i', $s)) { return 'md5'; }
    if (preg_match('/^[a-f0-9]{40}$/i', $s)) { return 'sha1'; }
    if (preg_match('/^[a-f0-9]{64}$/i', $s)) { return 'sha256'; }
    return 'plain';
}
function sec_pw_verify($stored, $in)
{
    $stored = (string) $stored; $in = (string) $in;
    switch (sec_pw_kind($stored)) {
        case 'hash':   return password_verify($in, $stored);
        case 'md5':    return hash_equals(strtolower($stored), md5($in));
        case 'sha1':   return hash_equals(strtolower($stored), sha1($in));
        case 'sha256': return hash_equals(strtolower($stored), hash('sha256', $in));
        default:       return hash_equals($stored, $in);
    }
}
function sec_pw_make($in, $stored)
{
    switch (sec_pw_kind($stored)) {
        case 'hash':   return password_hash($in, PASSWORD_DEFAULT);
        case 'md5':    return md5($in);
        case 'sha1':   return sha1($in);
        case 'sha256': return hash('sha256', $in);
        default:       return $in;
    }
}
function sec_mask_email($e)
{
    $p = explode('@', (string) $e, 2);
    if (count($p) !== 2) { return (string) $e; }
    return substr($p[0], 0, 2) . str_repeat('*', max(2, strlen($p[0]) - 2)) . '@' . $p[1];
}
function sec_mask_phone($p)
{
    $p = (string) $p; $n = strlen($p);
    return $n <= 4 ? $p : str_repeat('*', $n - 3) . substr($p, -3);
}
function sec_norm_phone($raw)
{
    $p = preg_replace('/[\s.\-()]+/', '', (string) $raw);
    return preg_match('/^\+?[0-9]{8,15}$/', (string) $p) ? $p : false;
}
function sec_save($uid, array $f)
{
    $allowed = ['email', 'email_ok', 'phone', 'phone_ok', 'pend_kind', 'pend_val', 'pend_hash', 'pend_exp', 'pend_tries'];
    $cols = ['uid']; $vals = [$uid]; $upd = [];
    foreach ($f as $k => $v) {
        if (!in_array($k, $allowed, true)) { continue; }
        $cols[] = $k; $vals[] = ($v === null) ? null : (string) $v; $upd[] = "`$k`=VALUES(`$k`)";
    }
    $cols[] = 'updated_at'; $vals[] = (string) time(); $upd[] = '`updated_at`=VALUES(`updated_at`)';
    $sql = 'INSERT INTO user_security (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ') ON DUPLICATE KEY UPDATE ' . implode(',', $upd);
    return ds_q($sql, str_repeat('s', count($cols)), $vals) !== false;
}
/* Remember why the last email failed (also written to the server error_log). Call with no argument to read it back. */
function sec_mail_note($note = null)
{
    static $last = '';
    if ($note !== null) { $last = (string) $note; error_log('[security.php] mail: ' . $last); }
    return $last;
}
/* Transcript of the last SMTP conversation (passwords are never logged). Used by ?mailtest=you@gmail.com */
function sec_mail_log($line = null)
{
    static $log = array();
    if ($line !== null) { $log[] = (string) $line; }
    return $log;
}
function sec_send_email($to, $code)
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) { return false; }
    $host = preg_replace('/[^a-z0-9.\-]/i', '', isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost');
    $user = defined('DS_SMTP_USER') ? (string) DS_SMTP_USER : '';
    $from = defined('DS_MAIL_FROM') ? (string) DS_MAIL_FROM : (($user !== '' && strpos($user, '@') !== false) ? $user : 'no-reply@' . $host);
    $name = defined('DS_MAIL_NAME') ? (string) DS_MAIL_NAME : 'devnvios.io.vn';
    if (preg_match('/[\r\n<>]/', $from . $name)) { return false; }
    $subject = $name . ' - Mã c?a b?n: ' . $code;
    $text = "Mã xác minh $name c?a b?n là: $code\r\nMã có hi?u l?c trong 10 phút. N?u b?n không yêu c?u, hãy b? qua email này.\r\n\r\n"
          . "Your $name verification code is $code\r\nIt expires in 10 minutes. If you did not request it, ignore this email.";
    $c = sec_h($code); $n = sec_h($name);
    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f3f4f6">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:28px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:440px;background:#ffffff;border-radius:18px;border:1px solid #e5e7eb;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif">'
        . '<tr><td style="padding:26px 28px 6px;font-size:15px;font-weight:800;color:#111827">' . $n . '</td></tr>'
        . '<tr><td style="padding:6px 28px 0;font-size:14px;line-height:1.55;color:#4b5563">Mã xác minh c?a b?n<br><span style="font-size:12.5px;color:#9ca3af">Your verification code</span></td></tr>'
        . '<tr><td align="center" style="padding:18px 28px"><div style="display:inline-block;padding:14px 22px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:14px;font-family:Consolas,Menlo,monospace;font-size:34px;font-weight:800;letter-spacing:10px;color:#1d4ed8">' . $c . '</div></td></tr>'
        . '<tr><td style="padding:0 28px 26px;font-size:12.5px;line-height:1.6;color:#6b7280">Mã có hi?u l?c trong 10 phút. N?u b?n không yêu c?u, hãy b? qua email này.<br>It expires in 10 minutes. If you did not request it, ignore this email.</td></tr>'
        . '</table></td></tr></table></body></html>';

    $b = '=_dv_' . bin2hex(random_bytes(8));
    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encFrom = '=?UTF-8?B?' . base64_encode($name) . '?= <' . $from . '>';
    $mimeHead = "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$b\"\r\n";
    $mimeBody = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n"
              . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n"
              . "--$b--\r\n";

    /* If SMTP is configured in db.php (DS_SMTP_HOST ...) send through it: far more reliable than mail(), which on a VPS usually has no mail server behind it.
       SMTP failing or not configured: fall back to the original mail() exactly as before. */
    if (defined('DS_SMTP_HOST') && DS_SMTP_HOST !== '') {
        $msg = 'Date: ' . date('r') . "\r\n"
             . 'From: ' . $encFrom . "\r\n"
             . 'To: <' . $to . ">\r\n"
             . 'Subject: ' . $encSubject . "\r\n"
             . 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . ((strrchr($from, '@') !== false) ? substr(strrchr($from, '@'), 1) : 'localhost') . ">\r\n"
             . $mimeHead . "\r\n" . $mimeBody;
        return sec_smtp_send($to, $from, $msg); // SMTP is configured: do not fall back to mail(), it often "succeeds" without ever delivering
    }
    $ok = @mail($to, $encSubject, $mimeBody, 'From: ' . $encFrom . "\r\n" . $mimeHead);
    if (!$ok && sec_mail_note() === '') { sec_mail_note('mail() returned false (this server has no mail service; set DS_SMTP_* in db.php)'); }
    return $ok;
}
/* Minimal SMTP client (no library needed). Settings in db.php:
   define('DS_SMTP_HOST', 'smtp.gmail.com'); define('DS_SMTP_PORT', 465); define('DS_SMTP_SECURE', 'ssl'); // ssl (465) | tls (587) | none
   define('DS_SMTP_USER', 'name@gmail.com'); define('DS_SMTP_PASS', 'app password'); optional: DS_MAIL_FROM, DS_MAIL_NAME */
function sec_smtp_send($to, $from, $message)
{
    $host   = (string) DS_SMTP_HOST;
    $secure = defined('DS_SMTP_SECURE') ? strtolower((string) DS_SMTP_SECURE) : 'ssl';
    $port   = defined('DS_SMTP_PORT') ? (int) DS_SMTP_PORT : ($secure === 'tls' ? 587 : ($secure === 'none' ? 25 : 465));
    $user   = defined('DS_SMTP_USER') ? (string) DS_SMTP_USER : '';
    $pass   = defined('DS_SMTP_PASS') ? str_replace(' ', '', (string) DS_SMTP_PASS) : '';
    if ($user !== '' && ($pass === '' || strpos($pass, 'PASTE') === 0)) { sec_mail_note('SMTP password is not set: put the Gmail App Password in DS_SMTP_PASS (db.php)'); return false; }
    if (preg_match('/[\r\n<>]/', $from . $to)) { return false; }

    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT);
    sec_mail_log('connect ' . $host . ':' . $port . ' (' . $secure . ')');
    if (!$fp) { sec_mail_note('cannot connect to ' . $host . ':' . $port . ' (' . $errstr . ') - the VPS may block this port'); return false; }
    stream_set_timeout($fp, 10);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') { break; }
        }
        return $data;
    };
    $step = function ($cmd, $expect, $label) use ($fp, $read) {
        if ($cmd !== null) { fwrite($fp, $cmd . "\r\n"); sec_mail_log('C: ' . $label); }
        $r = $read();
        sec_mail_log('S: ' . trim(substr($r, 0, 300)));
        if (!preg_match('/^(' . $expect . ')/', $r)) { sec_mail_note('SMTP "' . $label . '" got: ' . trim(substr($r, 0, 200))); return false; }
        return true;
    };
    $ehlo = 'EHLO ' . ((isset($_SERVER['SERVER_NAME']) && preg_match('/^[a-z0-9.\-]+$/i', $_SERVER['SERVER_NAME'])) ? $_SERVER['SERVER_NAME'] : 'localhost');

    $ok = $step(null, '220', 'connect') && $step($ehlo, '250', 'EHLO');
    if ($ok && $secure === 'tls') {
        $ok = $step('STARTTLS', '220', 'STARTTLS');
        if ($ok && !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { sec_mail_note('TLS handshake failed'); $ok = false; }
        $ok = $ok && $step($ehlo, '250', 'EHLO (TLS)');
    }
    if ($ok && $user !== '') {
        $ok = $step('AUTH LOGIN', '334', 'AUTH') && $step(base64_encode($user), '334', 'username') && $step(base64_encode($pass), '235', 'password (wrong App Password?)');
    }
    $ok = $ok && $step('MAIL FROM:<' . $from . '>', '250', 'MAIL FROM') && $step('RCPT TO:<' . $to . '>', '25[01]', 'RCPT TO') && $step('DATA', '354', 'DATA');
    if ($ok) {
        $message = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $message));
        $message = preg_replace('/^\./m', '..', $message);
        fwrite($fp, $message . "\r\n.\r\n");
        $ok = $step(null, '250', 'send');
    }
    @fwrite($fp, "QUIT\r\n"); @fclose($fp);
    return $ok;
}

/* Diagnostics: open /security.php?mailtest=someone@gmail.com while logged in as Admin (or set DS_MAIL_DEBUG to true in db.php).
   It tries to send a test email and prints exactly what happened, so you can see why mail is not arriving. */
if (isset($_GET['mailtest'])) {
    $isAdm = isset($u['role']) && (int) $u['role'] === 1;
    if (!$isAdm && !(defined('DS_MAIL_DEBUG') && DS_MAIL_DEBUG)) { http_response_code(404); exit; }
    header('Content-Type: text/plain; charset=UTF-8');
    $to = strtolower(trim((string) $_GET['mailtest']));
    echo "PHP " . PHP_VERSION . " | openssl: " . (extension_loaded('openssl') ? 'yes' : 'NO') . " | time: " . date('Y-m-d H:i:s') . "\n";
    echo "SMTP configured: " . ((defined('DS_SMTP_HOST') && DS_SMTP_HOST !== '') ? 'yes (' . DS_SMTP_HOST . ':' . (defined('DS_SMTP_PORT') ? DS_SMTP_PORT : '?') . ', user ' . (defined('DS_SMTP_USER') ? DS_SMTP_USER : '-') . ', password ' . ((defined('DS_SMTP_PASS') && DS_SMTP_PASS !== '' && strpos((string) DS_SMTP_PASS, 'PASTE') !== 0) ? 'set' : 'NOT SET') . ')' : 'NO (using mail())') . "\n";
    foreach (array(465, 587) as $pt) {
        $t0 = microtime(true);
        $c = @stream_socket_client('tcp://smtp.gmail.com:' . $pt, $en, $es, 6);
        echo "TCP smtp.gmail.com:$pt -> " . ($c ? 'reachable' : "BLOCKED/FAILED ($es)") . ' (' . round(microtime(true) - $t0, 2) . "s)\n";
        if ($c) { fclose($c); }
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { echo "\nUsage: /security.php?mailtest=someone@gmail.com\n"; exit; }
    $ok = sec_send_email($to, '123456');
    echo "\nResult: " . ($ok ? 'ACCEPTED for delivery to ' . $to : 'FAILED') . "\n";
    if (sec_mail_note() !== '') { echo 'Reason: ' . sec_mail_note() . "\n"; }
    echo "\n--- SMTP conversation ---\n" . implode("\n", sec_mail_log()) . "\n";
    exit;
}

if ((DS_SHOW_EMAIL || DS_SHOW_PHONE) && !ds_security_ensure()) { http_response_code(500); exit('Security storage is unavailable. Check the database connection.'); }
$uid = ds_uid($u);
$smsOn = function_exists('ds_send_sms');

function sec_row($uid)
{
    $r = ds_q('SELECT * FROM user_security WHERE uid = ? LIMIT 1', 's', [$uid]);
    return (is_array($r) && isset($r[0])) ? $r[0] : [];
}

/* ===================== X? lý form ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = isset($_POST['act']) ? (string) $_POST['act'] : '';
    if (!ds_csrf_check(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        sec_flash('err', 'Session expired. Reload the page and try again.');
        sec_back('top');
    }
    $row = sec_row($uid);

    if (!DS_SHOW_PHONE && ($act === 'phone_send' || $act === 'phone_verify')) { sec_back('email'); }
    if (!DS_SHOW_EMAIL && ($act === 'email_send' || $act === 'email_verify')) { sec_back('password'); }

    if ($act === 'email_send' || $act === 'phone_send') {
        $isMail = $act === 'email_send';
        $anchor = $isMail ? 'email' : 'phone';
        if ($isMail) {
            $val = strtolower(trim(isset($_POST['email']) ? (string) $_POST['email'] : ''));
            if (!filter_var($val, FILTER_VALIDATE_EMAIL) || strlen($val) > 190) { sec_flash('err', 'Enter a valid email address.'); sec_back($anchor); }
        } else {
            $val = sec_norm_phone(isset($_POST['phone']) ? $_POST['phone'] : '');
            if ($val === false) { sec_flash('err', 'Enter a valid phone number (8 to 15 digits).'); sec_back($anchor); }
        }
        $needCode = $isMail ? (bool) DS_VERIFY_EMAIL : $smsOn;
        if (!$needCode) {
            // không có cách g?i mã: luu th?ng (s? di?n tho?i s? không du?c xác minh)
            sec_save($uid, $isMail
                ? ['email' => $val, 'email_ok' => 1, 'pend_kind' => null, 'pend_val' => null, 'pend_hash' => null, 'pend_exp' => null, 'pend_tries' => 0]
                : ['phone' => $val, 'phone_ok' => 1, 'pend_kind' => null, 'pend_val' => null, 'pend_hash' => null, 'pend_exp' => null, 'pend_tries' => 0]);
            sec_flash('ok', ($isMail ? 'Gmail' : 'Phone number') . ' linked.');
            sec_back($anchor);
        }
        if (!empty($_SESSION['sec_last_send']) && time() - (int) $_SESSION['sec_last_send'] < 60) {
            sec_flash('err', 'Please wait a minute before requesting another code.');
            sec_back($anchor);
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        sec_save($uid, ['pend_kind' => $isMail ? 'email' : 'phone', 'pend_val' => $val, 'pend_hash' => hash_hmac('sha256', $code, sec_secret()), 'pend_exp' => time() + 600, 'pend_tries' => 0]);
        $sent = $isMail ? sec_send_email($val, $code) : (bool) ds_send_sms($val, "DEVNVIOS code: $code");
        if (!$sent) {
            sec_save($uid, ['pend_kind' => null, 'pend_val' => null, 'pend_hash' => null, 'pend_exp' => null, 'pend_tries' => 0]);
            error_log('[security.php] could not send the ' . ($isMail ? 'email' : 'SMS') . ' code to ' . ($isMail ? sec_mask_email($val) : sec_mask_phone($val)) . ' (mail()/SMTP failed: check DS_SMTP_* in db.php)');
            $_SESSION['sec_old'] = $val; // keep what the person typed so they do not have to type it again
            $why = (((isset($u['role']) && (int) $u['role'] === 1) || (defined('DS_MAIL_DEBUG') && DS_MAIL_DEBUG)) && sec_mail_note() !== '') ? ' [Debug] ' . sec_mail_note() : '';
            sec_flash('err', 'Could not send the code right now. Please try again in a moment.' . $why);
            sec_back($anchor);
        }
        $_SESSION['sec_last_send'] = time();
        sec_flash('ok', $isMail
            ? 'Code sent to ' . sec_mask_email($val) . '. Enter it below to finish linking. It expires in 10 minutes.'
            : 'We sent a 6-digit code to ' . sec_mask_phone($val) . '. It expires in 10 minutes.');
        sec_back($anchor);
    }

    if ($act === 'email_verify' || $act === 'phone_verify') {
        $isMail = $act === 'email_verify';
        $anchor = $isMail ? 'email' : 'phone';
        $kind = $isMail ? 'email' : 'phone';
        $code = preg_replace('/\D+/', '', isset($_POST['code']) ? (string) $_POST['code'] : '');
        if (($row['pend_kind'] ?? '') !== $kind || empty($row['pend_hash']) || (int) ($row['pend_exp'] ?? 0) < time()) {
            sec_flash('err', 'The code expired. Request a new one.'); sec_back($anchor);
        }
        if ((int) ($row['pend_tries'] ?? 0) >= 5) {
            sec_save($uid, ['pend_kind' => null, 'pend_val' => null, 'pend_hash' => null, 'pend_exp' => null, 'pend_tries' => 0]);
            sec_flash('err', 'Too many wrong codes. Request a new one.'); sec_back($anchor);
        }
        if (strlen($code) !== 6 || !hash_equals($row['pend_hash'], hash_hmac('sha256', $code, sec_secret()))) {
            sec_save($uid, ['pend_tries' => (int) ($row['pend_tries'] ?? 0) + 1]);
            sec_flash('err', 'Wrong code. Check it and try again.'); sec_back($anchor);
        }
        $fields = ['pend_kind' => null, 'pend_val' => null, 'pend_hash' => null, 'pend_exp' => null, 'pend_tries' => 0];
        if ($isMail) { $fields['email'] = $row['pend_val']; $fields['email_ok'] = 1; }
        else { $fields['phone'] = $row['pend_val']; $fields['phone_ok'] = 1; }
        sec_save($uid, $fields);
        sec_flash('ok', ($isMail ? 'Gmail' : 'Phone number') . ' linked.');
        sec_back($anchor);
    }

    if ($act === 'password') {
        $cur = isset($_POST['cur']) ? (string) $_POST['cur'] : '';
        $new = isset($_POST['new']) ? (string) $_POST['new'] : '';
        $cf  = isset($_POST['cf'])  ? (string) $_POST['cf']  : '';
        $lock = isset($_SESSION['sec_pw']) && is_array($_SESSION['sec_pw']) ? $_SESSION['sec_pw'] : ['n' => 0, 't' => 0];
        if ($lock['n'] >= 5 && time() - $lock['t'] < 600) { sec_flash('err', 'Too many attempts. Try again in a few minutes.'); sec_back('password'); }
        $pc = ds_ident(DS_COL_PASS);
        if (!$pc || !array_key_exists(DS_COL_PASS, $u)) { sec_flash('err', 'Password column not found. Set DS_COL_PASS in db.php.'); sec_back('password'); }
        if (!sec_pw_verify($u[DS_COL_PASS], $cur)) {
            $_SESSION['sec_pw'] = ['n' => $lock['n'] + 1, 't' => time()];
            sec_flash('err', 'Your current password is wrong.'); sec_back('password');
        }
        if ($new !== $cf)                         { sec_flash('err', 'The new passwords do not match.'); sec_back('password'); }
        if (strlen($new) < 8 || strlen($new) > 64) { sec_flash('err', 'Use 8 to 64 characters for the new password.'); sec_back('password'); }
        if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) { sec_flash('err', 'Use at least one letter and one number.'); sec_back('password'); }
        if ($new === $cur)                        { sec_flash('err', 'The new password must be different.'); sec_back('password'); }
        $tbl = ds_ident(DS_USERS_TABLE); $ci = ds_ident(DS_COL_ID); $cu = ds_ident(DS_COL_USER);
        $newStored = sec_pw_make($new, $u[DS_COL_PASS]);
        if (isset($u[DS_COL_ID]) && $ci) { $ok = ds_q("UPDATE `$tbl` SET `$pc` = ? WHERE `$ci` = ? LIMIT 1", 'ss', [$newStored, (string) $u[DS_COL_ID]]); }
        else { $ok = ds_q("UPDATE `$tbl` SET `$pc` = ? WHERE `$cu` = ? LIMIT 1", 'ss', [$newStored, (string) $u[DS_COL_USER]]); }
        if ($ok === false) { sec_flash('err', 'Could not save the new password.'); sec_back('password'); }
        unset($_SESSION['sec_pw']);
        session_regenerate_id(true);
        sec_flash('ok', 'Password changed.');
        sec_back('password');
    }
    sec_back('top');
}

/* ===================== Hi?n th? ===================== */
$row = sec_row($uid);
$emailOk = !empty($row['email']) && !empty($row['email_ok']);
$phoneOk = !empty($row['phone']) && !empty($row['phone_ok']);
$pendMail  = (($row['pend_kind'] ?? '') === 'email') && (int) ($row['pend_exp'] ?? 0) > time();
$pendPhone = (($row['pend_kind'] ?? '') === 'phone') && (int) ($row['pend_exp'] ?? 0) > time();
$flash = isset($_SESSION['sec_flash']) ? $_SESSION['sec_flash'] : null;
unset($_SESSION['sec_flash']);
$oldEmail = isset($_SESSION['sec_old']) ? (string) $_SESSION['sec_old'] : '';
unset($_SESSION['sec_old']);
$csrf = ds_csrf_token();
$name = isset($u[DS_COL_USER]) ? $u[DS_COL_USER] : 'Member';
$allOk = DS_SHOW_PHONE ? ($emailOk && $phoneOk) : $emailOk; // only Gmail is required now
$tag = function ($ok, $okTxt = 'Linked') { return $ok ? '<span class="tag ok">' . $okTxt . '</span>' : '<span class="tag warn">?? Not linked</span>'; };
$coolLeft = max(0, 60 - (time() - (int) (isset($_SESSION['sec_last_send']) ? $_SESSION['sec_last_send'] : 0)));
$expLeft  = $pendMail ? max(0, (int) $row['pend_exp'] - time()) : 0;
$emailStep = $pendMail ? 2 : ($emailOk ? 3 : 1);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>Security - DEVNVIOS</title>
<script>try{var t=localStorage.getItem('ds-theme');if(t==='light'||(t==='auto'&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches))document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
<style>
:root{color-scheme:dark;--bg:#050506;--card:#0d0d10;--field:#15151a;--line:#202026;--line2:#2a2a31;--h:#fff;--p:#9ca3af;--link:#3b9eff;--ok:#34c759;--okb:rgba(52,199,89,.14);--red:#ff6961;--redb:rgba(255,105,97,.14);--warn:#ff9f0a;--warnb:rgba(255,159,10,.15);--blue:#0a84ff;--blue2:#2563eb;--ring:rgba(10,132,255,.28);--shadow:0 10px 30px rgba(0,0,0,.35);--hd:50px;--font:-apple-system,BlinkMacSystemFont,"SF Pro Text","Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif}
:root[data-theme="light"]{color-scheme:light;--bg:#f3f5f9;--card:#fff;--field:#f3f4f6;--line:#e5e7eb;--line2:#d1d5db;--h:#111827;--p:#4b5563;--link:#0a6cdc;--ok:#16a34a;--okb:rgba(22,163,74,.12);--red:#dc2626;--redb:rgba(220,38,38,.1);--warn:#b45309;--warnb:rgba(217,119,6,.13);--ring:rgba(37,99,235,.22);--shadow:0 10px 30px rgba(17,24,39,.07)}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{-webkit-text-size-adjust:100%}
body{margin:0;padding:calc(var(--hd) + 18px) 16px calc(36px + env(safe-area-inset-bottom,0px));font:14px/1.5 var(--font);color:var(--h);background:var(--bg);-webkit-font-smoothing:antialiased}
.hd{position:fixed;top:0;left:0;right:0;z-index:10;display:flex;align-items:center;height:var(--hd);padding:0 14px;background:var(--bg);border-bottom:1px solid var(--line)}
.hd a{display:inline-flex;align-items:center;gap:2px;font-size:15px;font-weight:600;color:var(--link);text-decoration:none}
.hd svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
.hd b{position:absolute;left:0;right:0;text-align:center;font-size:16px;font-weight:800;pointer-events:none}
.wrap{max-width:34rem;margin:0 auto}
.top{display:flex;align-items:center;gap:12px;margin:0 0 16px}
.top .ico{flex:none;width:46px;height:46px;display:grid;place-items:center;border-radius:14px;color:#fff;background:linear-gradient(135deg,#3b82f6,#7c3aed)}
.top .ico svg{width:24px;height:24px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
h1{margin:0;font-size:22px;font-weight:800;line-height:1.2}
.sub{margin:2px 0 0;font-size:13px;color:var(--p)}
.msg{display:flex;gap:10px;margin:0 0 14px;padding:12px 14px;font-size:13px;font-weight:600;line-height:1.45;border-radius:14px;overflow-wrap:anywhere}
.msg.ok{color:var(--ok);background:var(--okb)}.msg.err{color:var(--red);background:var(--redb)}
.banner{display:flex;gap:10px;margin:0 0 16px;padding:12px 14px;font-size:13px;font-weight:600;line-height:1.45;border-radius:14px}
.banner.warn{color:var(--warn);background:var(--warnb)}.banner.ok{color:var(--ok);background:var(--okb)}
.card{margin:0 0 16px;padding:18px;background:var(--card);border:1px solid var(--line);border-radius:22px;box-shadow:var(--shadow);scroll-margin-top:calc(var(--hd) + 12px)}
.card h2{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 0 4px;font-size:16px;font-weight:800}
.card p{margin:0 0 12px;font-size:12.5px;color:var(--p)}
.card p b{color:var(--h)}
.cur{display:flex;align-items:center;gap:8px;margin:0 0 12px;padding:11px 13px;font-size:13px;font-weight:700;background:var(--field);border-radius:12px;overflow-wrap:anywhere}
.cur svg{flex:none;width:18px;height:18px;fill:none;stroke:var(--ok);stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round}
label{display:block;margin:12px 0 6px;font-size:12px;font-weight:700;color:var(--p)}
input{display:block;width:100%;height:48px;padding:0 14px;font:16px var(--font);color:var(--h);background:var(--field);border:1px solid var(--line2);border-radius:14px;outline:0;-webkit-appearance:none;appearance:none;transition:border-color .15s,box-shadow .15s}
input:focus{border-color:var(--blue);box-shadow:0 0 0 4px var(--ring)}
input.code{text-align:center;font-size:24px;letter-spacing:.4em}
.btn,button.btn{position:relative;display:flex;align-items:center;justify-content:center;gap:8px;width:100%;height:48px;margin-top:14px;font:700 15px var(--font);color:#fff;background:linear-gradient(180deg,#2f8cff,#0a6cf0);border:0;border-radius:14px;cursor:pointer;transition:transform .08s,opacity .15s}
.btn:active{transform:scale(.985)}
.btn:disabled{opacity:.45;cursor:not-allowed;transform:none}
.btn.busy{pointer-events:none;opacity:.8}
.btn.busy::after{content:"";width:16px;height:16px;border:2.5px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:sp .7s linear infinite}
@keyframes sp{to{transform:rotate(360deg)}}
.tag{display:inline-block;height:22px;padding:0 9px;font-size:11.5px;font-weight:700;line-height:22px;white-space:nowrap;border-radius:999px}
.tag.ok{color:var(--ok);background:var(--okb)}.tag.warn{color:var(--warn);background:var(--warnb)}
.hint{margin:10px 0 0;font-size:12px;color:var(--p)}
/* steps */
.steps{display:flex;align-items:center;gap:6px;margin:0 0 16px;font-size:11.5px;font-weight:700;color:var(--p)}
.steps .s{display:flex;align-items:center;gap:6px;white-space:nowrap}
.steps .n{display:grid;place-items:center;width:22px;height:22px;font-size:11px;border-radius:50%;background:var(--field);border:1px solid var(--line2)}
.steps .s.on{color:var(--h)}.steps .s.on .n{color:#fff;background:var(--blue);border-color:var(--blue)}
.steps .s.done{color:var(--ok)}.steps .s.done .n{color:#fff;background:var(--ok);border-color:var(--ok)}
.steps i{flex:1;height:2px;min-width:12px;background:var(--line2);border-radius:2px}
/* code entry */
.sent{display:flex;flex-direction:column;align-items:center;text-align:center;padding:4px 0 2px}
.sent .env{display:grid;place-items:center;width:58px;height:58px;margin:0 0 10px;color:var(--blue);background:var(--ring);border-radius:50%}
.sent .env svg{width:28px;height:28px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
.sent h3{margin:0 0 4px;font-size:17px;font-weight:800}
.sent p{margin:0 0 4px}
.otp{display:flex;justify-content:center;gap:8px;margin:14px 0 2px}
.otp input{width:46px;height:56px;padding:0;font-size:24px;font-weight:800;text-align:center;border-radius:12px}
.otp.bad input{border-color:var(--red);animation:shake .35s}
@keyframes shake{25%{transform:translateX(-4px)}75%{transform:translateX(4px)}}
.meta{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:14px 0 0;font-size:12.5px;color:var(--p)}
.meta form{margin:0}
.link{display:inline;width:auto;height:auto;margin:0;padding:0;font:700 12.5px var(--font);color:var(--link);background:none;border:0;cursor:pointer}
.link:disabled{color:var(--p);opacity:.8;cursor:default}
details{margin:14px 0 0;padding-top:12px;border-top:1px dashed var(--line2)}
summary{cursor:pointer;font-size:12.5px;font-weight:700;color:var(--link);list-style:none}
summary::-webkit-details-marker{display:none}
/* password */
.pw{position:relative}
.pw input{padding-right:48px}
.eye{position:absolute;top:0;right:0;display:grid;place-items:center;width:48px;height:48px;margin:0;padding:0;color:var(--p);background:none;border:0;cursor:pointer}
.eye svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.eye .off{display:none}.eye.on .off{display:block}.eye.on .see{display:none}
.meter{height:6px;margin:10px 0 0;background:var(--field);border-radius:6px;overflow:hidden}
.meter i{display:block;width:0;height:100%;border-radius:6px;background:var(--red);transition:width .2s,background .2s}
.meter i.s2{background:var(--warn)}.meter i.s3{background:var(--ok)}
.rules{display:grid;gap:6px;margin:12px 0 0;padding:0;list-style:none;font-size:12.5px;color:var(--p)}
.rules li{display:flex;align-items:center;gap:8px}
.rules li::before{content:"";flex:none;width:16px;height:16px;border-radius:50%;border:2px solid var(--line2);box-sizing:border-box}
.rules li.ok{color:var(--ok)}
.rules li.ok::before{border-color:var(--ok);background:var(--ok) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath d='m4 8.2 2.6 2.6L12 5.4' fill='none' stroke='white' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") center/contain no-repeat}
@media (max-width:380px){.otp{gap:6px}.otp input{width:40px;height:52px}}
</style>
</head>
<body id="top">
<div class="hd"><a href="/"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>Home</a><b>Security</b></div>
<div class="wrap">
    <div class="top">
        <div class="ico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.5 3 8.3 7 10 4-1.7 7-5.5 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg></div>
        <div><h1>Account security</h1><p class="sub">Signed in as <b><?= sec_h($name) ?></b></p></div>
    </div>

<?php if ($flash): ?>
    <div class="msg <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>" role="status"><span><?= $flash[0] === 'ok' ? '?' : '??' ?></span><span><?= sec_h($flash[1]) ?></span></div>
<?php endif; ?>
<?php if (DS_SHOW_EMAIL || DS_SHOW_PHONE): ?>
<?php if ($allOk): ?>
    <div class="banner ok"><span>?</span><span><?= DS_SHOW_PHONE ? 'Gmail and phone number are linked.' : 'Your Gmail is linked.' ?> Your account can be recovered.</span></div>
<?php else: ?>
    <div class="banner warn"><span>??</span><span><?= DS_SHOW_PHONE ? 'Your account is not fully protected. Link your Gmail and phone number so you can recover it if you lose access.' : 'Your account is not fully protected. Link your Gmail so you can recover it if you lose access.' ?></span></div>
<?php endif; ?>
<?php endif; ?>

<?php if (DS_SHOW_EMAIL): ?>
    <section class="card" id="email">
        <h2>Gmail <?= $tag($emailOk) ?></h2>
        <div class="steps" aria-hidden="true">
            <span class="s <?= $emailStep > 1 ? 'done' : 'on' ?>"><span class="n"><?= $emailStep > 1 ? '?' : '1' ?></span>Enter Gmail</span><i></i>
            <span class="s <?= $emailStep > 2 ? 'done' : ($emailStep === 2 ? 'on' : '') ?>"><span class="n"><?= $emailStep > 2 ? '?' : '2' ?></span>Enter code</span><i></i>
            <span class="s <?= $emailStep === 3 ? 'done' : '' ?>"><span class="n"><?= $emailStep === 3 ? '?' : '3' ?></span>Linked</span>
        </div>

<?php if ($pendMail): ?>
        <div class="sent">
            <div class="env"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="m4 8 8 6 8-6"/></svg></div>
            <h3>Check your inbox</h3>
            <p>We sent a 6-digit code to <b><?= sec_h(sec_mask_email($row['pend_val'])) ?></b></p>
            <p>Can't see it? Look in your Spam folder.</p>
        </div>
        <form method="post" autocomplete="off" id="otpform">
            <input type="hidden" name="csrf" value="<?= sec_h($csrf) ?>"><input type="hidden" name="act" value="email_verify">
            <div class="otp" id="otp" aria-label="6-digit code"></div>
            <noscript><input class="code" type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="000000" autocomplete="one-time-code" required></noscript>
            <button class="btn" type="submit" id="otpbtn">Verify code</button>
        </form>
        <div class="meta">
            <span id="expiry" data-left="<?= (int) $expLeft ?>">Code expires in <b id="exptxt"></b></span>
            <form method="post" autocomplete="off" class="js-busy">
                <input type="hidden" name="csrf" value="<?= sec_h($csrf) ?>"><input type="hidden" name="act" value="email_send"><input type="hidden" name="email" value="<?= sec_h($row['pend_val']) ?>">
                <button class="link" type="submit" id="resend" data-cool="<?= (int) $coolLeft ?>">Resend code</button>
            </form>
        </div>
        <details>
            <summary>Wrong address? Use a different Gmail</summary>
            <form method="post" autocomplete="off" class="js-busy">
                <input type="hidden" name="csrf" value="<?= sec_h($csrf) ?>"><input type="hidden" name="act" value="email_send">
                <label for="em2">Gmail address</label>
                <input id="em2" type="email" name="email" inputmode="email" autocapitalize="off" placeholder="name@gmail.com" required>
                <button class="btn" type="submit">Send new code</button>
            </form>
        </details>
<?php else: ?>
<?php if ($emailOk): ?>
        <div class="cur"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg><?= sec_h(sec_mask_email($row['email'])) ?></div>
        <details>
            <summary>Use a different Gmail</summary>
<?php endif; ?>
        <p><?= $emailOk ? 'We will email a 6-digit code to the new address to confirm it is yours.' : 'Enter your Gmail and press Verify. We will email you a 6-digit code to confirm it is yours, so you can recover your account later.' ?></p>
        <form method="post" autocomplete="off" class="js-busy">
            <input type="hidden" name="csrf" value="<?= sec_h($csrf) ?>"><input type="hidden" name="act" value="email_send">
            <label for="em">Gmail address</label>
            <input id="em" type="email" name="email" inputmode="email" autocapitalize="off" placeholder="name@gmail.com" value="<?= sec_h($oldEmail) ?>" required>
            <button class="btn" type="submit"><?= DS_VERIFY_EMAIL ? 'Verify Gmail' : 'Link Gmail' ?></button>
        </form>
<?php if ($emailOk): ?>
        </details>
<?php endif; ?>
<?php endif; ?>
    </section>
<?php endif; ?>

<?php if (DS_SHOW_PHONE): ?>
    <section class="card" id="phone">
        <h2>Phone number <?= $tag($phoneOk) ?></h2>
<?php if ($phoneOk): ?>
        <div class="cur"><?= sec_h(sec_mask_phone($row['phone'])) ?></div>
<?php endif; ?>
<?php if ($pendPhone): ?>
        <p>Enter the 6-digit code we sent to <?= sec_h(sec_mask_phone($row['pend_val'])) ?>.</p>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= sec_h($csrf) ?>"><input type="hidden" name="act" value="phone_verify">
            <input class="code" type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="000000" autocomplete="one-time-code" required>
            <button class="btn" type="submit">Verify code</button>
        </form>
<?php else: ?>
        <p><?= $phoneOk ? 'Want to use a different number? Enter it below.' : 'Link a phone number as a second way to recover your account.' ?></p>
<?php endif; ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= sec_h($csrf) ?>"><input type="hidden" name="act" value="phone_send">
            <label for="ph">Phone number</label>
            <input id="ph" type="tel" name="phone" inputmode="tel" placeholder="0901234567 or +84901234567" required>
            <button class="btn" type="submit"><?= $smsOn ? 'Send code' : 'Link phone number' ?></button>
        </form>
<?php if (!$smsOn): ?>
        <p class="hint">SMS verification is not set up yet, so this number is saved without a code check.</p>
<?php endif; ?>
    </section>
<?php endif; ?>

    <section class="card" id="password">
        <h2>Change password</h2>
        <p>Pick a password you do not use anywhere else.</p>
        <form method="post" autocomplete="off" id="pwform" class="js-busy">
            <input type="hidden" name="csrf" value="<?= sec_h($csrf) ?>"><input type="hidden" name="act" value="password">
            <label for="cur">Current password</label>
            <div class="pw"><input id="cur" type="password" name="cur" autocomplete="current-password" required>
                <button class="eye" type="button" data-eye="cur" aria-label="Show password"><svg class="see" viewBox="0 0 24 24"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="off" viewBox="0 0 24 24"><path d="M3 3l18 18M10.6 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4.1M6.5 6.6C3.7 8.4 2 12 2 12s3.6 7 10 7c1.7 0 3.2-.4 4.5-1.1M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg></button></div>
            <label for="new">New password</label>
            <div class="pw"><input id="new" type="password" name="new" autocomplete="new-password" minlength="8" maxlength="64" required>
                <button class="eye" type="button" data-eye="new" aria-label="Show password"><svg class="see" viewBox="0 0 24 24"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="off" viewBox="0 0 24 24"><path d="M3 3l18 18M10.6 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4.1M6.5 6.6C3.7 8.4 2 12 2 12s3.6 7 10 7c1.7 0 3.2-.4 4.5-1.1M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg></button></div>
            <div class="meter" aria-hidden="true"><i id="meter"></i></div>
            <label for="cf">Confirm new password</label>
            <div class="pw"><input id="cf" type="password" name="cf" autocomplete="new-password" minlength="8" maxlength="64" required>
                <button class="eye" type="button" data-eye="cf" aria-label="Show password"><svg class="see" viewBox="0 0 24 24"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="off" viewBox="0 0 24 24"><path d="M3 3l18 18M10.6 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4.1M6.5 6.6C3.7 8.4 2 12 2 12s3.6 7 10 7c1.7 0 3.2-.4 4.5-1.1M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg></button></div>
            <ul class="rules">
                <li id="r1">8 to 64 characters</li>
                <li id="r2">At least one letter and one number</li>
                <li id="r3">Both new passwords match</li>
            </ul>
            <button class="btn" type="submit" id="pwbtn">Update password</button>
        </form>
    </section>
</div>
<script>
(function(){
    var $=function(id){return document.getElementById(id);};

    /* show / hide password */
    var eyes=document.querySelectorAll('[data-eye]');
    for(var i=0;i<eyes.length;i++){(function(b){b.addEventListener('click',function(){var f=$(b.getAttribute('data-eye'));var show=f.type==='password';f.type=show?'text':'password';b.classList.toggle('on',show);b.setAttribute('aria-label',show?'Hide password':'Show password');});})(eyes[i]);}

    /* password strength + checklist; the button only unlocks when everything is fine (the server checks again anyway) */
    var cur=$('cur'),nw=$('new'),cf=$('cf'),btn=$('pwbtn'),meter=$('meter');
    if(nw&&cf&&btn){
        var mark=function(id,ok){$(id).className=ok?'ok':'';};
        var check=function(){
            var v=nw.value,c=cf.value;
            var r1=v.length>=8&&v.length<=64,r2=/[A-Za-z]/.test(v)&&/[0-9]/.test(v),r3=v!==''&&v===c;
            mark('r1',r1);mark('r2',r2);mark('r3',r3);
            var score=(r1?1:0)+(r2?1:0)+(v.length>=12?1:0)+(/[A-Z]/.test(v)&&/[a-z]/.test(v)?1:0)+(/[^A-Za-z0-9]/.test(v)?1:0);
            meter.style.width=(v?Math.max(12,score*20):0)+'%';
            meter.className=score>=4?'s3':(score>=3?'s2':'');
            btn.disabled=!(r1&&r2&&r3&&cur.value!=='');
        };
        btn.disabled=true;
        cur.addEventListener('input',check);nw.addEventListener('input',check);cf.addEventListener('input',check);check();
    }

    /* 6-box code entry */
    var box=$('otp');
    if(box){
        var form=$('otpform'),sub=$('otpbtn'),hid=document.createElement('input'),cells=[],sent=false;
        hid.type='hidden';hid.name='code';form.appendChild(hid);
        var value=function(){var s='';for(var k=0;k<cells.length;k++){s+=cells[k].value;}return s;};
        var go=function(){
            hid.value=value();
            if(hid.value.length===6&&!sent){sent=true;sub.classList.add('busy');setTimeout(function(){form.submit();},120);}
        };
        var fill=function(from,digits){
            for(var k=from;k<6&&k-from<digits.length;k++){cells[k].value=digits.charAt(k-from);}
            var last=Math.min(5,from+digits.length-1);cells[Math.max(last,0)].focus();go();
        };
        for(var n=0;n<6;n++){(function(n){
            var c=document.createElement('input');
            c.type='text';c.inputMode='numeric';c.autocomplete=n===0?'one-time-code':'off';c.setAttribute('aria-label','Digit '+(n+1));
            c.addEventListener('input',function(){
                var d=c.value.replace(/\D+/g,'');
                c.value='';
                if(d==='')return;
                fill(n,d);
            });
            c.addEventListener('keydown',function(e){
                if(e.key==='Backspace'&&c.value===''&&n>0){cells[n-1].value='';cells[n-1].focus();e.preventDefault();}
                else if(e.key==='ArrowLeft'&&n>0){cells[n-1].focus();e.preventDefault();}
                else if(e.key==='ArrowRight'&&n<5){cells[n+1].focus();e.preventDefault();}
            });
            c.addEventListener('paste',function(e){
                var t=(e.clipboardData||window.clipboardData).getData('text').replace(/\D+/g,'');
                if(t!==''){e.preventDefault();fill(0,t.substr(0,6));}
            });
            c.addEventListener('focus',function(){c.select();});
            cells.push(c);box.appendChild(c);
        })(n);}
        form.addEventListener('submit',function(e){
            hid.value=value();
            if(hid.value.length!==6){e.preventDefault();box.classList.remove('bad');void box.offsetWidth;box.classList.add('bad');for(var k=0;k<6;k++){if(cells[k].value===''){cells[k].focus();break;}}}
            else{sub.classList.add('busy');}
        });
        cells[0].focus();
    }

    /* code expiry + resend countdown */
    var ex=$('expiry'),et=$('exptxt'),rs=$('resend');
    if(ex&&et){
        var left=parseInt(ex.getAttribute('data-left'),10)||0;
        var cool=rs?(parseInt(rs.getAttribute('data-cool'),10)||0):0;
        var tick=function(){
            if(left>0){var m=Math.floor(left/60),s=left%60;et.textContent=m+':'+(s<10?'0':'')+s;}
            else{ex.textContent='This code has expired. Request a new one.';}
            if(rs){if(cool>0){rs.disabled=true;rs.textContent='Resend in '+cool+'s';}else{rs.disabled=false;rs.textContent='Resend code';}}
            if(left>0)left--;if(cool>0)cool--;
        };
        tick();setInterval(tick,1000);
    }

    /* "sending..." state on forms that email something, so people do not press twice */
    var fs=document.querySelectorAll('form.js-busy');
    for(var j=0;j<fs.length;j++){(function(f){f.addEventListener('submit',function(){
        var b=f.querySelector('button[type=submit]');
        if(b){setTimeout(function(){b.classList.add('busy');if(!b.classList.contains('link')){b.disabled=false;}},0);}
    });})(fs[j]);}
})();
</script>
</body>
</html>
