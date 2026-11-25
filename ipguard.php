<?php
/* ==========================================================================
   ipguard.php  —  Permanent & Timed Auto-Unban IP Guard for IOSMODS / DEVNVIOS
   - Supports both Permanent IP bans and Timed Auto-Unban IP isolations
     (Key-Spam Sad-Face page, DDoS shield, VPN/multi-account cooldown).
   - Never deletes ban history: Unban flips active = 0 and records unbanned_by
     ('auto-timer' or Admin ID).
   - Synchronizes seamlessly with security_shield.php pre-DB fast cache.
   - NEVER touches /peoeopoo or /ios_b, and NEVER bans safe/Cloudflare/OneShield/Admin IPs.
   - PHP 7.2 – 8.3+ compatible. Never throws fatal errors.
   ========================================================================== */

if (!defined('IPG_LOADED')) {
    define('IPG_LOADED', 1);
}

if (is_file(__DIR__ . '/security_shield.php')) {
    require_once __DIR__ . '/security_shield.php';
}

function ipg_contact()
{
    if (function_exists('sh_contact_url')) {
        return sh_contact_url();
    }
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
    if (function_exists('sh_in_cidr')) {
        return sh_in_cidr($ip, $cidr);
    }
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
    return function_exists('sh_is_cloudflare') ? sh_is_cloudflare($ip) : false;
}

function ipg_norm($ip)
{
    if (function_exists('sh_norm_ip')) {
        return sh_norm_ip($ip);
    }
    $ip = trim((string) $ip);
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return '';
    }
    $bin = @inet_pton($ip);
    $out = $bin === false ? false : @inet_ntop($bin);
    return $out === false ? $ip : substr((string) $out, 0, 45);
}

function ipg_ip()
{
    if (function_exists('sh_client_ip')) {
        return sh_client_ip();
    }
    $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    return ipg_norm($remote);
}

/** [true, ''] or [false, 'why not'] — zero-false-positive protection against banning proxies/Cloudflare/OneShield/Admins. */
function ipg_can_ban($ip)
{
    $ip = ipg_norm($ip);
    if ($ip === '') {
        return [false, 'Địa chỉ IP không hợp lệ.'];
    }
    if (function_exists('sh_is_safe_ip')) {
        list($safe, $why) = sh_is_safe_ip($ip);
        if ($safe) {
            return [false, $why];
        }
        return [true, ''];
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return [false, 'Địa chỉ nội bộ / cục bộ: không được phép chặn.'];
    }
    return [true, ''];
}

/* ------------------------------------------------------------------
 * Table & Seamless Schema Upgrade (adds ban_type + expires_at safely)
 * ------------------------------------------------------------------ */

function ipg_ensure($conn)
{
    static $checked = false;
    if ($checked || !($conn instanceof mysqli)) {
        return;
    }
    $checked = true;
    try {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `ip_bans` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ip` VARCHAR(45) NOT NULL,
            `reason` VARCHAR(255) NOT NULL DEFAULT '',
            `ban_type` VARCHAR(24) NOT NULL DEFAULT 'manual',
            `username` VARCHAR(100) DEFAULT NULL,
            `banned_by` VARCHAR(60) NOT NULL DEFAULT '',
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `hits` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_hit` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            `expires_at` DATETIME DEFAULT NULL,
            `unbanned_by` VARCHAR(60) DEFAULT NULL,
            `unbanned_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ip` (`ip`),
            KEY `idx_active` (`active`),
            KEY `idx_expires` (`active`, `expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $cols = [];
        $res  = @mysqli_query($conn, 'SHOW COLUMNS FROM `ip_bans`');
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $cols[$r['Field']] = true;
        }
        if ($cols) {
            if (!isset($cols['ban_type'])) {
                @mysqli_query($conn, "ALTER TABLE `ip_bans` ADD COLUMN `ban_type` VARCHAR(24) NOT NULL DEFAULT 'manual' AFTER `reason`");
            }
            if (!isset($cols['expires_at'])) {
                @mysqli_query($conn, "ALTER TABLE `ip_bans` ADD COLUMN `expires_at` DATETIME DEFAULT NULL AFTER `created_at`");
            }
        }
    } catch (Throwable $e) {
        error_log('[ipguard.php] ensure: ' . $e->getMessage());
    }
}

function ipg_q($conn, $sql, $types = '', array $params = [])
{
    ipg_ensure($conn);
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
            if ($try === 0) {
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
 * Auto-Sweep Expired Timed Bans
 * ------------------------------------------------------------------ */

function ipg_sweep_expired($conn, $priorityIp = '')
{
    if (!($conn instanceof mysqli)) {
        return 0;
    }
    ipg_ensure($conn);
    $lifted = 0;
    try {
        $res = @mysqli_query($conn, "SELECT id, ip, username, ban_type, reason FROM ip_bans WHERE active = 1 AND expires_at IS NOT NULL AND expires_at <= NOW() LIMIT 30");
        $rows = [];
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $rows[] = $r;
        }
        if (!$rows) {
            if ($priorityIp !== '' && function_exists('sh_remove_ban_cache')) {
                sh_remove_ban_cache($priorityIp);
            }
            return 0;
        }
        foreach ($rows as $r) {
            $st = ipg_q($conn, "UPDATE ip_bans SET active = 0, unbanned_by = 'auto-timer', unbanned_at = NOW() WHERE id = ? AND active = 1", 'i', [(int) $r['id']]);
            if ($st !== null) {
                if ($st->affected_rows > 0) {
                    $lifted++;
                    if (function_exists('sh_remove_ban_cache')) {
                        sh_remove_ban_cache($r['ip']);
                    }
                    if (function_exists('tg_event')) {
                        tg_event('ip_auto_unban', [
                            'ip'       => (string) $r['ip'],
                            'username' => (string) (isset($r['username']) ? $r['username'] : ''),
                            'ban_type' => (string) (isset($r['ban_type']) ? $r['ban_type'] : 'temp'),
                        ]);
                    }
                }
                $st->close();
            }
        }
    } catch (Throwable $e) {
        error_log('[ipguard.php] sweep: ' . $e->getMessage());
    }
    return $lifted;
}

/* ------------------------------------------------------------------
 * Ban / Temp-Ban / Unban / Lookup
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
    $rs  = $st->get_result();
    $row = $rs ? $rs->fetch_assoc() : null;
    $st->close();
    return $row ?: null;
}

function ipg_is_banned($conn, $ip)
{
    $row = ipg_find($conn, $ip);
    if (!$row || (int) $row['active'] !== 1) {
        return null;
    }
    if (!empty($row['expires_at']) && strpos((string) $row['expires_at'], '0000') !== 0) {
        $exp = strtotime((string) $row['expires_at']);
        if ($exp !== false && $exp <= time()) {
            ipg_sweep_expired($conn, $ip);
            return null;
        }
    }
    return $row;
}

/** Permanent IP ban (Admin action). */
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
    $already  = $existing && (int) $existing['active'] === 1 && empty($existing['expires_at']);
    $uname    = $username !== '' ? $username : null;

    $st = ipg_q(
        $conn,
        "INSERT INTO ip_bans (ip, reason, ban_type, username, banned_by, active, hits, created_at, expires_at)
         VALUES (?, ?, 'manual', ?, ?, 1, 0, NOW(), NULL)
         ON DUPLICATE KEY UPDATE created_at = IF(active = 1, created_at, NOW()), active = 1,
             reason = VALUES(reason), ban_type = 'manual', expires_at = NULL,
             username = COALESCE(VALUES(username), username), banned_by = VALUES(banned_by),
             unbanned_by = NULL, unbanned_at = NULL",
        'ssss',
        [$ip, $reason, $uname, (string) $by]
    );
    if ($st === null) {
        return ['ok' => false, 'msg' => 'Lỗi cơ sở dữ liệu khi lưu chặn IP.', 'row' => null, 'new' => false];
    }
    $st->close();
    $row = ipg_find($conn, $ip);
    if ($row && function_exists('sh_write_ban_cache')) {
        sh_write_ban_cache($ip, [
            'id'         => (int) $row['id'],
            'reason'     => (string) $row['reason'],
            'ban_type'   => 'manual',
            'username'   => (string) (isset($row['username']) ? $row['username'] : ''),
            'banned_by'  => (string) $by,
            'expires_ts' => 0,
            'expires_at' => null,
        ]);
    }
    return ['ok' => true, 'msg' => $already ? 'Đã cập nhật lý do chặn vĩnh viễn.' : 'Đã chặn IP vĩnh viễn.', 'row' => $row, 'new' => !$already];
}

/** Timed IP ban (auto-unbans when $seconds elapse; never overwrites an active permanent manual ban!). */
function ipg_temp_ban($conn, $ip, $seconds, $reason, $by = 'bot-shield', $username = '', $banType = 'temp')
{
    $ip = ipg_norm($ip);
    list($can, $why) = ipg_can_ban($ip);
    if (!$can) {
        return ['ok' => false, 'msg' => $why, 'row' => null, 'new' => false];
    }
    $seconds  = max(60, min(604800, (int) $seconds));
    $existing = ipg_find($conn, $ip);
    if ($existing && (int) $existing['active'] === 1 && empty($existing['expires_at'])) {
        // Already permanently banned by Admin -> keep permanent ban intact
        return ['ok' => true, 'msg' => 'IP đang bị chặn vĩnh viễn.', 'row' => $existing, 'new' => false];
    }

    $reason = trim((string) $reason);
    if ($reason === '') {
        $reason = 'Tạm khoá truy cập tự động để bảo vệ hệ thống.';
    }
    $reason = function_exists('mb_substr') ? mb_substr($reason, 0, 250, 'UTF-8') : substr($reason, 0, 250);
    $uname  = $username !== '' ? $username : null;
    $btype  = preg_replace('/[^a-z0-9_]/i', '', (string) $banType) ?: 'temp';
    $expTs  = time() + $seconds;
    $expStr = date('Y-m-d H:i:s', $expTs);

    $st = ipg_q(
        $conn,
        "INSERT INTO ip_bans (ip, reason, ban_type, username, banned_by, active, hits, created_at, expires_at)
         VALUES (?, ?, ?, ?, ?, 1, 1, NOW(), ?)
         ON DUPLICATE KEY UPDATE created_at = NOW(), active = 1, reason = VALUES(reason),
             ban_type = VALUES(ban_type), expires_at = VALUES(expires_at),
             username = COALESCE(VALUES(username), username), banned_by = VALUES(banned_by),
             unbanned_by = NULL, unbanned_at = NULL",
        'ssssss',
        [$ip, $reason, $btype, $uname, (string) $by, $expStr]
    );
    if ($st === null) {
        return ['ok' => false, 'msg' => 'Lỗi cơ sở dữ liệu khi lưu tạm khoá IP.', 'row' => null, 'new' => false];
    }
    $st->close();
    $row = ipg_find($conn, $ip);
    if (function_exists('sh_write_ban_cache')) {
        sh_write_ban_cache($ip, [
            'id'         => $row ? (int) $row['id'] : 0,
            'reason'     => $reason,
            'ban_type'   => $btype,
            'username'   => (string) $username,
            'banned_by'  => (string) $by,
            'expires_ts' => $expTs,
            'expires_at' => $expStr,
        ]);
    }
    return ['ok' => true, 'msg' => 'Đã tạm khoá IP (' . ceil($seconds / 60) . ' phút).', 'row' => $row, 'new' => true];
}

/** Unban = active 0 + clear fast cache + clear keyfail strikes so visitor enters immediately. */
function ipg_unban($conn, $ip, $by)
{
    $ip  = ipg_norm($ip);
    $row = ipg_find($conn, $ip);
    if (function_exists('sh_remove_ban_cache')) {
        sh_remove_ban_cache($ip);
        $h = md5($ip);
        @unlink(sh_dir() . '/keyfails/' . substr($h, 0, 2) . '/' . $h . '.json');
        @unlink(sh_dir() . '/fkabuse/' . substr($h, 0, 2) . '/' . $h . '.json');
        @unlink(sh_dir() . '/rates/' . substr($h, 0, 2) . '/' . $h . '.json');
    }
    if (!$row || (int) $row['active'] !== 1) {
        return ['ok' => (bool) $row, 'msg' => $row ? 'IP này đã được gỡ chặn.' : 'IP này hiện không bị chặn.', 'row' => $row];
    }
    $st = ipg_q($conn, 'UPDATE ip_bans SET active = 0, unbanned_by = ?, unbanned_at = NOW() WHERE ip = ?', 'ss', [(string) $by, $ip]);
    if ($st === null) {
        return ['ok' => false, 'msg' => 'Lỗi cơ sở dữ liệu khi gỡ chặn IP.', 'row' => $row];
    }
    $st->close();
    return ['ok' => true, 'msg' => 'Đã gỡ chặn IP thành công.', 'row' => ipg_find($conn, $ip)];
}

/* ------------------------------------------------------------------
 * Blocked Page Renderer (Sad-Face iOS UI)
 * ------------------------------------------------------------------ */

function ipg_wants_json()
{
    return function_exists('sh_wants_json') ? sh_wants_json() : isset($_GET['ajax']);
}

function ipg_render(array $ban, $ip)
{
    if (function_exists('sh_render_sad_page')) {
        $isTemp = !empty($ban['expires_at']) && strpos((string) $ban['expires_at'], '0000') !== 0;
        sh_render_sad_page($ban, $ip, $isTemp ? 429 : 403);
        return;
    }
    http_response_code(403);
    echo 'Truy cập bị giới hạn.';
}

/* ------------------------------------------------------------------
 * Runtime Guard
 * ------------------------------------------------------------------ */

function ipg_run($conn)
{
    if (defined('IPG_RAN_ONCE') || !($conn instanceof mysqli)) {
        return;
    }
    if (function_exists('sh_is_excluded_subapp') && (sh_is_excluded_subapp() || sh_is_telegram_webhook_call())) {
        return;
    }
    define('IPG_RAN_ONCE', 1);

    $ip  = '';
    $ban = null;
    try {
        $ip = ipg_ip();
        if ($ip === '') {
            return;
        }
        list($safe, ) = ipg_can_ban($ip);
        if (!$safe) {
            return; // Safe/Admin/Proxy IP -> never block even if stale DB row existed
        }
        $ban = ipg_is_banned($conn, $ip);
        if ($ban === null) {
            return;
        }
    } catch (Throwable $e) {
        error_log('[ipguard.php] run: ' . $e->getMessage());
        return;
    }

    try {
        $st = ipg_q($conn, 'UPDATE ip_bans SET hits = hits + 1, last_hit = NOW() WHERE id = ?', 'i', [(int) $ban['id']]);
        if ($st !== null) {
            $st->close();
        }
        if (function_exists('sh_write_ban_cache')) {
            $expTs = (!empty($ban['expires_at']) && strpos((string) $ban['expires_at'], '0000') !== 0)
                ? (int) strtotime((string) $ban['expires_at'])
                : 0;
            sh_write_ban_cache($ip, [
                'id'         => (int) $ban['id'],
                'reason'     => (string) $ban['reason'],
                'ban_type'   => isset($ban['ban_type']) ? (string) $ban['ban_type'] : 'manual',
                'username'   => isset($ban['username']) ? (string) $ban['username'] : '',
                'expires_ts' => max(0, $expTs),
                'expires_at' => $ban['expires_at'],
                'hits'       => (int) $ban['hits'] + 1,
            ]);
        }
    } catch (Throwable $e) {
        error_log('[ipguard.php] hit: ' . $e->getMessage());
    }

    ipg_render($ban, $ip);
    exit;
}
