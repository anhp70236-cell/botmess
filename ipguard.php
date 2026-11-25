<?php
/* ==========================================================================
   ipguard.php  —  Permanent IP ban for DEVNVIOS
   - Banned IP = clean "access blocked" page (HTTP 403), nothing else loads.
   - Bans live in table `ip_bans` (created automatically). "Unban" only flips active = 0,
     so NOTHING is ever deleted (works even with allow_destructive = false).
   - Used by index.php and telegram_bot.php. To protect another page (login, register, admin_cp...)
     add these two lines right after its database connection:
         require_once __DIR__ . '/ipguard.php';
         ipg_run($conn);
   - PHP 7.2 compatible. Never throws: a guard problem can not break the website.
   ========================================================================== */

if (!defined('IPG_LOADED')) {
    define('IPG_LOADED', 1);
}

/** Contact link shown on the blocked page (the admin's Telegram). */
function ipg_contact()
{
    if (function_exists('tg_cfg')) {
        $c = (string) tg_cfg('contact_url', '');
        if ($c !== '') {
            return $c;
        }
    }
    return 'https://t.me/iosnea';
}

/* ------------------------------------------------------------------
 * IP helpers
 * ------------------------------------------------------------------ */

function ipg_in_cidr($ip, $cidr)
{
    $parts  = explode('/', $cidr, 2);
    $subnet = @inet_pton($parts[0]);
    $addr   = @inet_pton($ip);
    $bits   = isset($parts[1]) ? (int) $parts[1] : 0;
    if ($subnet === false || $addr === false || strlen($subnet) !== strlen($addr)) {
        return false;
    }
    $bytes = (int) floor($bits / 8);
    if ($bytes > 0 && strncmp($addr, $subnet, $bytes) !== 0) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return (ord($addr[$bytes]) & $mask) === (ord($subnet[$bytes]) & $mask);
}

function ipg_is_cloudflare($ip)
{
    static $ranges = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];
    foreach ($ranges as $r) {
        if (ipg_in_cidr($ip, $r)) {
            return true;
        }
    }
    return false;
}

/** The visitor's IP, same rules as index.php client_ip(). */
function ipg_ip()
{
    if (function_exists('client_ip') && defined('CLIENT_IP_HEADER')) {
        return ipg_norm(client_ip());
    }
    $ip = '';
    if (defined('CLIENT_IP_HEADER') && CLIENT_IP_HEADER !== '' && !empty($_SERVER[CLIENT_IP_HEADER])) {
        $ip = trim(explode(',', (string) $_SERVER[CLIENT_IP_HEADER])[0]);
    }
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        $remote = (string) (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
        $cf     = trim((string) (isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : ''));
        $ip     = ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) !== false && ipg_is_cloudflare($remote)) ? $cf : $remote;
    }
    return ipg_norm($ip);
}

/** Canonical text form (so 2001:db8::1 and 2001:0db8:0:0:0:0:0:1 are the same ban). '' if not an IP. */
function ipg_norm($ip)
{
    $ip = trim((string) $ip);
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return '';
    }
    $bin = @inet_pton($ip);
    $out = $bin === false ? false : @inet_ntop($bin);
    return $out === false ? $ip : substr((string) $out, 0, 45);
}

/** [true,''] or [false,'why not'] — refuses addresses that would lock out everybody (proxy, Cloudflare, local). */
function ipg_can_ban($ip)
{
    $ip = ipg_norm($ip);
    if ($ip === '') {
        return [false, 'That is not a valid IP address.'];
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return [false, 'Private / local address: banning it could block every visitor.'];
    }
    if (ipg_is_cloudflare($ip)) {
        return [false, 'This is a Cloudflare address: banning it would block every visitor.'];
    }
    return [true, ''];
}

/* ------------------------------------------------------------------
 * Table
 * ------------------------------------------------------------------ */

function ipg_ensure($conn)
{
    try {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `ip_bans` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ip` VARCHAR(45) NOT NULL,
            `reason` VARCHAR(255) NOT NULL DEFAULT '',
            `username` VARCHAR(100) DEFAULT NULL,
            `banned_by` VARCHAR(60) NOT NULL DEFAULT '',
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `hits` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_hit` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            `unbanned_by` VARCHAR(60) DEFAULT NULL,
            `unbanned_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ip` (`ip`),
            KEY `idx_active` (`active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[ipguard.php] ensure: ' . $e->getMessage());
    }
}

/** Run a prepared statement; creates the table once and retries if it is missing. Returns mysqli_stmt or null. */
function ipg_q($conn, $sql, $types = '', array $params = [])
{
    for ($try = 0; $try < 2; $try++) {
        try {
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                throw new RuntimeException($conn->error);
            }
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new RuntimeException($err);
            }
            return $stmt;
        } catch (Throwable $e) {
            if ($try === 0 && stripos($e->getMessage(), "doesn't exist") !== false) {
                ipg_ensure($conn);
                continue;
            }
            error_log('[ipguard.php] ' . $e->getMessage());
            return null;
        }
    }
    return null;
}

/* ------------------------------------------------------------------
 * Ban / unban / lookup
 * ------------------------------------------------------------------ */

function ipg_find($conn, $ip)
{
    $ip = ipg_norm($ip);
    if ($ip === '') {
        return null;
    }
    $st = ipg_q($conn, 'SELECT * FROM ip_bans WHERE ip = ? LIMIT 1', 's', [$ip]);
    if ($st === null) {
        return null;
    }
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

function ipg_is_banned($conn, $ip)
{
    $row = ipg_find($conn, $ip);
    return ($row && (int) $row['active'] === 1) ? $row : null;
}

/** Permanent ban. Returns ['ok'=>bool, 'msg'=>string, 'row'=>array|null, 'new'=>bool]. */
function ipg_ban($conn, $ip, $reason, $by, $username = '')
{
    $ip = ipg_norm($ip);
    list($can, $why) = ipg_can_ban($ip);
    if (!$can) {
        return ['ok' => false, 'msg' => $why, 'row' => null, 'new' => false];
    }
    $reason = trim((string) $reason);
    if ($reason === '') {
        $reason = 'Vi phạm quy định của website';
    }
    $reason   = function_exists('mb_substr') ? mb_substr($reason, 0, 250, 'UTF-8') : substr($reason, 0, 250);
    $existing = ipg_find($conn, $ip);
    $already  = $existing && (int) $existing['active'] === 1;
    $uname    = $username !== '' ? $username : null;
    $st = ipg_q(
        $conn,
        'INSERT INTO ip_bans (ip, reason, username, banned_by, active, hits, created_at) VALUES (?, ?, ?, ?, 1, 0, NOW())
         ON DUPLICATE KEY UPDATE created_at = IF(active = 1, created_at, NOW()), active = 1, reason = VALUES(reason),
             username = COALESCE(VALUES(username), username), banned_by = VALUES(banned_by), unbanned_by = NULL, unbanned_at = NULL',
        'ssss',
        [$ip, $reason, $uname, (string) $by]
    );
    if ($st === null) {
        return ['ok' => false, 'msg' => 'Database error while saving the ban.', 'row' => null, 'new' => false];
    }
    $st->close();
    return ['ok' => true, 'msg' => $already ? 'Already banned (reason updated).' : 'Banned forever.', 'row' => ipg_find($conn, $ip), 'new' => !$already];
}

/** "Unban" = active 0 (the row stays as history). */
function ipg_unban($conn, $ip, $by)
{
    $ip  = ipg_norm($ip);
    $row = ipg_find($conn, $ip);
    if (!$row || (int) $row['active'] !== 1) {
        return ['ok' => false, 'msg' => 'This IP is not banned.', 'row' => $row];
    }
    $st = ipg_q($conn, 'UPDATE ip_bans SET active = 0, unbanned_by = ?, unbanned_at = NOW() WHERE ip = ?', 'ss', [(string) $by, $ip]);
    if ($st === null) {
        return ['ok' => false, 'msg' => 'Database error while unbanning.', 'row' => $row];
    }
    $st->close();
    return ['ok' => true, 'msg' => 'Unbanned.', 'row' => ipg_find($conn, $ip)];
}

/* ------------------------------------------------------------------
 * The blocked page (flat, matte, no glow)
 * ------------------------------------------------------------------ */

function ipg_wants_json()
{
    return isset($_GET['ajax']) || isset($_GET['sync'])
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && stripos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') === 0);
}

function ipg_render(array $ban, $ip)
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code(403);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');

    if (ipg_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['result' => 'ip_blocked', 'message' => 'Access blocked']);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    $e      = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $ref    = 'BAN-' . str_pad((string) (int) $ban['id'], 5, '0', STR_PAD_LEFT);
    $reason = trim((string) $ban['reason']) !== '' ? (string) $ban['reason'] : 'Vi phạm quy định của website';
    $since  = !empty($ban['created_at']) ? (string) $ban['created_at'] : '';
    $contact = ipg_contact();
    ?><!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title>Truy cập bị chặn</title>
<style>
:root{--bg:#eef0f3;--card:#ffffff;--text:#1b1e24;--muted:#6a707c;--line:#e1e4e9;--accent:#b4372d;--chip:#f1f3f6;--btn:#1b1e24;--btn-text:#ffffff}
@media (prefers-color-scheme:dark){:root{--bg:#0f1115;--card:#171a20;--text:#e9ebef;--muted:#8d94a2;--line:#262b34;--accent:#d8605a;--chip:#1e232b;--btn:#e9ebef;--btn-text:#14161b}}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;display:flex;align-items:center;justify-content:center;padding:max(20px,env(safe-area-inset-top)) 20px max(20px,env(safe-area-inset-bottom));-webkit-font-smoothing:antialiased}
.card{width:100%;max-width:420px;background:var(--card);border:1px solid var(--line);border-radius:18px;padding:34px 28px 26px;text-align:center}
.icon{width:68px;height:68px;border-radius:50%;background:var(--chip);border:1px solid var(--line);margin:0 auto 20px;display:flex;align-items:center;justify-content:center;color:var(--accent)}
.icon svg{width:30px;height:30px}
h1{margin:0 0 6px;font-size:22px;font-weight:650;letter-spacing:-.01em}
.sub{margin:0 0 22px;color:var(--muted);font-size:14px;line-height:1.55}
.rows{border:1px solid var(--line);border-radius:12px;overflow:hidden;text-align:left;margin-bottom:22px}
.row{display:flex;justify-content:space-between;gap:16px;padding:11px 14px;font-size:13.5px;border-top:1px solid var(--line)}
.row:first-child{border-top:0}
.row span:first-child{color:var(--muted);flex:none}
.row span:last-child{font-weight:560;text-align:right;word-break:break-word;min-width:0}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px}
.btn{display:block;width:100%;padding:13px 16px;border-radius:12px;background:var(--btn);color:var(--btn-text);text-decoration:none;font-size:14.5px;font-weight:600;border:0;transition:opacity .15s}
.btn:hover{opacity:.88}
.note{margin:16px 0 0;color:var(--muted);font-size:12.5px;line-height:1.5}
</style>
</head>
<body>
<main class="card" role="alert">
  <div class="icon" aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="10.5" width="15" height="10" rx="2.2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/><path d="M12 14.6v2.2"/></svg>
  </div>
  <h1>Truy cập bị chặn</h1>
  <p class="sub">Địa chỉ IP của bạn đã bị quản trị viên chặn vĩnh viễn khỏi website này.<br>Access from your IP address has been permanently blocked.</p>
  <div class="rows">
    <div class="row"><span>Lý do</span><span><?= $e($reason) ?></span></div>
    <div class="row"><span>IP của bạn</span><span class="mono"><?= $e($ip) ?></span></div>
    <div class="row"><span>Mã chặn</span><span class="mono"><?= $e($ref) ?></span></div>
<?php if ($since !== '') { ?>
    <div class="row"><span>Thời gian</span><span><?= $e($since) ?></span></div>
<?php } ?>
  </div>
  <a class="btn" href="<?= $e($contact) ?>" rel="noopener">Liên hệ admin để khiếu nại</a>
  <p class="note">Nếu bạn cho rằng đây là nhầm lẫn, hãy gửi cho admin mã chặn ở trên.</p>
</main>
</body>
</html>
<?php
}

/* ------------------------------------------------------------------
 * Guard: call once per request, right after the database connection.
 * Banned IP => alert (once per 10 minutes) + blocked page + exit.
 * ------------------------------------------------------------------ */

function ipg_run($conn)
{
    if (!($conn instanceof mysqli)) {
        return;
    }
    try {
        $ip = ipg_ip();
        if ($ip === '') {
            return;
        }
        $ban = ipg_is_banned($conn, $ip);
        if ($ban === null) {
            return;
        }
    } catch (Throwable $e) {
        error_log('[ipguard.php] run: ' . $e->getMessage());
        return; // never lock the site because of a guard error
    }

    try {
        $st = ipg_q($conn, 'UPDATE ip_bans SET hits = hits + 1, last_hit = NOW() WHERE id = ?', 'i', [(int) $ban['id']]);
        if ($st !== null) {
            $st->close();
        }
        if (function_exists('tg_event')) {
            tg_event('ip_blocked_hit', [
                'ip' => $ip, 'username' => (string) (isset($ban['username']) ? $ban['username'] : ''),
                'hits' => (int) $ban['hits'] + 1, 'reason' => (string) $ban['reason'], 'ban_id' => (int) $ban['id'],
                'user_agent' => (string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''),
            ]);
        }
    } catch (Throwable $e) {
        error_log('[ipguard.php] hit: ' . $e->getMessage());
    }
    ipg_render($ban, $ip);
    exit;
}
