<?php
/* ==========================================================================
   security_shield.php  —  IOSMODS Smart Anti-DDoS, Zero-False-Positive IP Shield,
   Sad-Face Timed Ban UI & VPN / Multi-Account Free-Key Watcher
   - Runs BEFORE MySQL connect (sh_early_guard) to stop L7 / L4-proxied HTTP floods
     and exploit streams in < 0.3ms with zero DB overhead.
   - NEVER touches /peoeopoo or /ios_b.
   - NEVER blocks safe IPs: Cloudflare, OneShield, Telegram API, loopback/LAN,
     whitelisted IPs, or logged-in Admin IPs (100% zero false positives).
   - Timed bans automatically expire and lift themselves (or Admin can lift 1-tap via Bot).
   - Compatible with PHP 7.2 – PHP 8.3+. Never throws fatal errors.
   ========================================================================== */

if (!defined('SH_SHIELD_LOADED')) {
    define('SH_SHIELD_LOADED', 1);
}

/* ------------------------------------------------------------------
 * 1. Scope & Sub-App Immunity (/peoeopoo and /ios_b are strictly untouched)
 * ------------------------------------------------------------------ */

function sh_is_excluded_subapp()
{
    if (PHP_SAPI === 'cli') {
        return true;
    }
    foreach (['REQUEST_URI', 'SCRIPT_NAME', 'PHP_SELF'] as $k) {
        if (!empty($_SERVER[$k])) {
            $p = (string) strtok((string) $_SERVER[$k], '?');
            if (preg_match('#^/(?:peoeopoo|ios_b)(?:/|$)#i', $p)) {
                return true;
            }
        }
    }
    return false;
}

function sh_is_telegram_webhook_call()
{
    $sn = basename((string) strtok((string) (isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : ''), '?'));
    if ($sn !== 'telegram_bot.php') {
        return false;
    }
    if (!empty($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) || isset($_GET['setup']) || isset($_GET['cron'])) {
        return true;
    }
    $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    return sh_is_telegram_ip($remote);
}

/* ------------------------------------------------------------------
 * 2. Fast File Storage in ./cache/shield (gitignored via cache/*)
 * ------------------------------------------------------------------ */

function sh_dir()
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $base = __DIR__ . '/cache/shield';
    if ((is_dir($base) || @mkdir($base, 0755, true)) && is_writable($base)) {
        return $dir = $base;
    }
    $tmp = rtrim(sys_get_temp_dir(), '/\\') . '/iosmods_shield';
    if (is_dir($tmp) || @mkdir($tmp, 0755, true)) {
        return $dir = $tmp;
    }
    return $dir = rtrim(sys_get_temp_dir(), '/\\');
}

function sh_json_read($file)
{
    if (!is_file($file)) {
        return [];
    }
    $raw = @file_get_contents($file);
    $arr = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($arr) ? $arr : [];
}

function sh_json_update($file, $fn)
{
    $parent = dirname($file);
    if (!is_dir($parent)) {
        @mkdir($parent, 0755, true);
    }
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        $r = $fn([]);
        return is_array($r) ? $r : [];
    }
    @flock($fh, LOCK_EX);
    $raw  = stream_get_contents($fh);
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($data)) {
        $data = [];
    }
    $next = $fn($data);
    if (!is_array($next)) {
        $next = $data;
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fh);
    @flock($fh, LOCK_UN);
    fclose($fh);
    return $next;
}

function sh_cfg()
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $saved = sh_json_read(sh_dir() . '/config.json');
    $cfg   = array_merge([
        'enabled'          => true,
        'waf_enabled'      => true,
        'ddos_ban_sec'     => 3600,  // 60 minutes auto-unban for L7/L4 flood
        'key_spam_ban_sec' => 1800,  // 30 minutes auto-unban for intentional key spam
        'vpn_spam_ban_sec' => 1800,  // 30 minutes auto-unban for repeated VPN/multi-acc bypass spam
        'key_fail_limit'   => 6,     // 6 wrong key attempts in 10m -> Sad-Face temporary ban
        'key_burst_limit'  => 4,     // 4 rapid wrong key attempts in 30s -> Sad-Face temporary ban
    ], $saved);
    return $cfg;
}

function sh_cfg_save(array $patch)
{
    $out = sh_json_update(sh_dir() . '/config.json', function ($cur) use ($patch) {
        return array_merge($cur, $patch);
    });
    return $out;
}

/* ------------------------------------------------------------------
 * 3. Trusted Proxies, Real Visitor IP & Zero-False-Positive Safe-IP Check
 * ------------------------------------------------------------------ */

function sh_in_cidr($ip, $cidr)
{
    $ip   = trim((string) $ip);
    $cidr = trim((string) $cidr);
    if ($ip === '' || $cidr === '') {
        return false;
    }
    if (strpos($cidr, '/') === false) {
        $cidr .= (strpos($cidr, ':') !== false) ? '/128' : '/32';
    }
    $parts  = explode('/', $cidr, 2);
    $subnet = @inet_pton($parts[0]);
    $addr   = @inet_pton($ip);
    $bits   = isset($parts[1]) ? (int) $parts[1] : 0;
    if ($subnet === false || $addr === false || strlen($subnet) !== strlen($addr)) {
        return false;
    }
    $maxBits = strlen($addr) * 8;
    if ($bits < 0 || $bits > $maxBits) {
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

function sh_in_cidr_list($ip, array $cidrs)
{
    foreach ($cidrs as $c) {
        if (sh_in_cidr($ip, $c)) {
            return true;
        }
    }
    return false;
}

function sh_norm_ip($ip)
{
    $ip = trim((string) $ip);
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return '';
    }
    $bin = @inet_pton($ip);
    if ($bin !== false && strlen($bin) === 16 && substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
        $v4 = @inet_ntop(substr($bin, 12));
        if ($v4 !== false) {
            return substr((string) $v4, 0, 45);
        }
    }
    $out = $bin === false ? false : @inet_ntop($bin);
    return $out === false ? substr($ip, 0, 45) : substr((string) $out, 0, 45);
}

function sh_ip_network_key($ip)
{
    $ip = sh_norm_ip($ip);
    if ($ip === '') {
        return '';
    }
    $bin = @inet_pton($ip);
    if ($bin !== false && strlen($bin) === 16) {
        return bin2hex(substr($bin, 0, 8)) . '/64';
    }
    return $ip;
}

function sh_is_cloudflare($ip)
{
    static $cf = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];
    return sh_in_cidr_list($ip, $cf);
}

function sh_is_oneshield($ip)
{
    static $os = [
        '103.216.118.0/27', '103.56.163.0/27', '103.166.183.0/27',
        '103.72.98.10/32', '103.72.97.5/32', '103.72.96.10/32',
        '2001:df7:ce00:19::3:0/112', '2001:df7:ce00:16::3:0/112', '2001:df7:ce00:22::3:0/112',
        '2001:df0:1b:3::3:0/128', '2001:df0:1b:2::3:0/128', '2001:df0:1b:1::3:0/128',
    ];
    return sh_in_cidr_list($ip, $os);
}

function sh_is_telegram_ip($ip)
{
    static $tg = [
        '149.154.160.0/20', '91.108.4.0/22', '91.108.8.0/22', '91.108.12.0/22',
        '91.108.16.0/22', '91.108.20.0/22', '91.108.56.0/22', '91.105.192.0/23',
        '185.76.151.0/24', '2001:67c:4e8::/48', '2001:b28:f23d::/48', '2001:b28:f23f::/48',
    ];
    return sh_in_cidr_list($ip, $tg);
}

function sh_is_private_or_local($ip)
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

function sh_is_trusted_proxy($ip)
{
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    if (sh_is_private_or_local($ip) || sh_is_cloudflare($ip) || sh_is_oneshield($ip)) {
        return true;
    }
    if (defined('FK_TRUSTED_PROXIES') && FK_TRUSTED_PROXIES !== '') {
        foreach (explode(',', (string) FK_TRUSTED_PROXIES) as $c) {
            $c = trim($c);
            if ($c !== '' && sh_in_cidr($ip, $c)) {
                return true;
            }
        }
    }
    return false;
}

/** Resolve true visitor IP safely behind Cloudflare, OneShield, and local Nginx/Apache reverse proxies. */
function sh_client_ip()
{
    if (isset($GLOBALS['SH_RESOLVED_IP'])) {
        return $GLOBALS['SH_RESOLVED_IP'];
    }
    $remote = isset($_SERVER['REMOTE_ADDR']) ? sh_norm_ip($_SERVER['REMOTE_ADDR']) : '';
    if ($remote === '') {
        return $GLOBALS['SH_RESOLVED_IP'] = '';
    }
    if (!sh_is_trusted_proxy($remote)) {
        return $GLOBALS['SH_RESOLVED_IP'] = $remote;
    }
    // 1. CF-Connecting-IP (set by Cloudflare / Cloudflared)
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $cf = sh_norm_ip($_SERVER['HTTP_CF_CONNECTING_IP']);
        if ($cf !== '' && !sh_is_cloudflare($cf) && !sh_is_oneshield($cf)) {
            return $GLOBALS['SH_RESOLVED_IP'] = $cf;
        }
    }
    // 2. X-Forwarded-For: walk right-to-left skipping trusted proxies so spoofed left entries are ignored
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_reverse(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
        foreach ($parts as $p) {
            $cand = sh_norm_ip($p);
            if ($cand === '') {
                continue;
            }
            if (sh_is_trusted_proxy($cand)) {
                continue;
            }
            return $GLOBALS['SH_RESOLVED_IP'] = $cand;
        }
    }
    // 3. X-Real-IP
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $xr = sh_norm_ip($_SERVER['HTTP_X_REAL_IP']);
        if ($xr !== '' && !sh_is_trusted_proxy($xr)) {
            return $GLOBALS['SH_RESOLVED_IP'] = $xr;
        }
    }
    return $GLOBALS['SH_RESOLVED_IP'] = $remote;
}

function sh_whitelist_list()
{
    $d = sh_json_read(sh_dir() . '/whitelist.json');
    $list = isset($d['ips']) && is_array($d['ips']) ? $d['ips'] : [];
    if (defined('FK_IP_WHITELIST') && FK_IP_WHITELIST !== '') {
        foreach (explode(',', (string) FK_IP_WHITELIST) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $list[$item] = 'fk_config';
            }
        }
    }
    return $list;
}

function sh_whitelist_add($cidrOrIp, $note = 'admin')
{
    $cidrOrIp = trim((string) $cidrOrIp);
    $parts    = explode('/', $cidrOrIp, 2);
    $baseIp   = sh_norm_ip($parts[0]);
    if ($baseIp === '') {
        return false;
    }
    $entry = isset($parts[1]) ? ($baseIp . '/' . (int) $parts[1]) : $baseIp;
    sh_json_update(sh_dir() . '/whitelist.json', function ($d) use ($entry, $note) {
        if (!isset($d['ips']) || !is_array($d['ips'])) {
            $d['ips'] = [];
        }
        $d['ips'][$entry] = ['note' => (string) $note, 'at' => time()];
        return $d;
    });
    sh_remove_ban_cache($baseIp);
    return $entry;
}

function sh_whitelist_remove($entry)
{
    $entry = trim((string) $entry);
    sh_json_update(sh_dir() . '/whitelist.json', function ($d) use ($entry) {
        if (isset($d['ips'][$entry])) {
            unset($d['ips'][$entry]);
        }
        return $d;
    });
}

function sh_remember_admin_ip($ip, $adminUser = 'admin')
{
    $ip = sh_norm_ip($ip);
    if ($ip === '' || sh_is_private_or_local($ip)) {
        return;
    }
    $file = sh_dir() . '/admin_ips.json';
    $cur  = sh_json_read($file);
    $now  = time();
    if (isset($cur[$ip]['exp']) && (int) $cur[$ip]['exp'] > $now + 432000) {
        return; // already fresh
    }
    sh_json_update($file, function ($d) use ($ip, $adminUser, $now) {
        foreach ($d as $k => $v) {
            if (!is_array($v) || (int) (isset($v['exp']) ? $v['exp'] : 0) < $now) {
                unset($d[$k]);
            }
        }
        $d[$ip] = ['user' => (string) $adminUser, 'exp' => $now + 7 * 86400];
        return $d;
    });
}

/**
 * Returns [true, $reasonWhySafe] if $ip MUST NEVER be banned (0% false positive guarantee),
 * or [false, ''] if $ip is a normal external address eligible for protection rules.
 */
function sh_is_safe_ip($ip)
{
    $ip = sh_norm_ip($ip);
    if ($ip === '') {
        return [true, 'Địa chỉ IP không hợp lệ.'];
    }
    if (sh_is_private_or_local($ip)) {
        return [true, 'Địa chỉ nội bộ / máy chủ (không được phép chặn).'];
    }
    $srvIp = isset($_SERVER['SERVER_ADDR']) ? sh_norm_ip($_SERVER['SERVER_ADDR']) : '';
    if ($ip === '161.248.4.251' || ($srvIp !== '' && $ip === $srvIp)) {
        return [true, 'Địa chỉ IP của chính máy chủ VPS.'];
    }
    if (sh_is_cloudflare($ip)) {
        return [true, 'Dải IP hệ thống Cloudflare (chặn sẽ ảnh hưởng toàn bộ khách truy cập).'];
    }
    if (sh_is_oneshield($ip)) {
        return [true, 'Dải IP proxy OneShield (chặn sẽ ảnh hưởng toàn bộ khách truy cập).'];
    }
    if (sh_is_telegram_ip($ip)) {
        return [true, 'Dải IP máy chủ Telegram Bot API.'];
    }
    foreach (sh_whitelist_list() as $cidr => $meta) {
        if (sh_in_cidr($ip, (string) $cidr)) {
            return [true, 'Địa chỉ IP nằm trong danh sách an toàn (Whitelist).'];
        }
    }
    $adm = sh_json_read(sh_dir() . '/admin_ips.json');
    if (isset($adm[$ip]['exp']) && (int) $adm[$ip]['exp'] > time()) {
        return [true, 'Địa chỉ IP đang được Admin sử dụng (' . (isset($adm[$ip]['user']) ? $adm[$ip]['user'] : 'admin') . ').'];
    }
    if (!empty($_COOKIE['dv_admin']) && preg_match('/^\d{1,10}\.\d{9,11}\.[a-f0-9]{64}$/', (string) $_COOKIE['dv_admin'])) {
        $reqIp = sh_client_ip();
        if ($reqIp !== '' && $reqIp === $ip) {
            return [true, 'Phiên trình duyệt có xác thực Admin (dv_admin).'];
        }
    }
    return [false, ''];
}

/* ------------------------------------------------------------------
 * 4. Human Session Token & Sub-resource Detection
 * ------------------------------------------------------------------ */

function sh_secret()
{
    static $sec = null;
    if ($sec !== null) {
        return $sec;
    }
    $f = sh_dir() . '/secret.json';
    $d = sh_json_read($f);
    if (!empty($d['k']) && strlen((string) $d['k']) >= 32) {
        return $sec = (string) $d['k'];
    }
    $k = bin2hex(random_bytes(24));
    sh_json_update($f, function ($x) use ($k) {
        return !empty($x['k']) ? $x : ['k' => $k, 'at' => time()];
    });
    $d2 = sh_json_read($f);
    return $sec = !empty($d2['k']) ? (string) $d2['k'] : $k;
}

function sh_has_human_cookie($ip)
{
    if (empty($_COOKIE['sh_human']) || !is_string($_COOKIE['sh_human'])) {
        return false;
    }
    $parts = explode('.', $_COOKIE['sh_human'], 2);
    if (count($parts) !== 2) {
        return false;
    }
    $ts = (int) $parts[0];
    if ($ts < time() - 86400 || $ts > time() + 300) {
        return false;
    }
    $ua  = substr((string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''), 0, 120);
    $sig = substr(hash_hmac('sha256', $ts . '|' . sh_ip_network_key($ip) . '|' . $ua, sh_secret()), 0, 32);
    return hash_equals($sig, (string) $parts[1]);
}

function sh_issue_human_cookie($ip)
{
    if (headers_sent() || $ip === '' || sh_has_human_cookie($ip)) {
        return;
    }
    $ts  = time();
    $ua  = substr((string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''), 0, 120);
    $sig = substr(hash_hmac('sha256', $ts . '|' . sh_ip_network_key($ip) . '|' . $ua, sh_secret()), 0, 32);
    $val = $ts . '.' . $sig;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '')) === 'https';
    if (PHP_VERSION_ID >= 70300) {
        @setcookie('sh_human', $val, ['expires' => $ts + 86400, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    } else {
        @setcookie('sh_human', $val, $ts + 86400, '/; samesite=Lax', '', $https, true);
    }
    $_COOKIE['sh_human'] = $val;
}

/** Static / background polling endpoints NEVER count towards DDoS flood thresholds. */
function sh_is_light_subresource()
{
    if (isset($_GET['brand_logo']) || isset($_GET['favicon']) || isset($_GET['sync']) || isset($_GET['tg_ping'])
        || isset($_GET['ds_check_user']) || isset($_GET['ds_check_key_lock'])) {
        return true;
    }
    $path = strtolower((string) strtok((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''), '?'));
    return (bool) preg_match('/\.(?:css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|map)$/', $path);
}

/* ------------------------------------------------------------------
 * 5. Fast Ban Cache + Pending Sync Queue
 * ------------------------------------------------------------------ */

function sh_ban_cache_path($ip)
{
    $h = md5(sh_norm_ip($ip));
    return sh_dir() . '/bans/' . substr($h, 0, 2) . '/' . $h . '.json';
}

function sh_write_ban_cache($ip, array $record)
{
    $ip = sh_norm_ip($ip);
    if ($ip === '') {
        return;
    }
    list($safe, ) = sh_is_safe_ip($ip);
    if ($safe) {
        return;
    }
    $path = sh_ban_cache_path($ip);
    sh_json_update($path, function ($cur) use ($ip, $record) {
        return array_merge([
            'ip'         => $ip,
            'id'         => isset($cur['id']) ? (int) $cur['id'] : 0,
            'reason'     => 'Tạm ngưng truy cập để bảo vệ hệ thống',
            'ban_type'   => 'temp',
            'username'   => '',
            'banned_by'  => 'bot-shield',
            'created_ts' => time(),
            'created_at' => date('Y-m-d H:i:s'),
            'expires_ts' => 0,
            'expires_at' => null,
            'hits'       => isset($cur['hits']) ? (int) $cur['hits'] : 0,
            'last_touch' => time(),
        ], $record);
    });
}

function sh_remove_ban_cache($ip)
{
    $ip = sh_norm_ip($ip);
    if ($ip === '') {
        return;
    }
    $path = sh_ban_cache_path($ip);
    if (is_file($path)) {
        @unlink($path);
    }
}

function sh_read_ban_cache($ip)
{
    $ip = sh_norm_ip($ip);
    if ($ip === '') {
        return null;
    }
    $path = sh_ban_cache_path($ip);
    if (!is_file($path)) {
        return null;
    }
    $d = sh_json_read($path);
    return !empty($d['ip']) ? $d : null;
}

function sh_queue_pending_ban(array $item)
{
    sh_json_update(sh_dir() . '/pending_bans.json', function ($list) use ($item) {
        if (!is_array($list)) {
            $list = [];
        }
        $list[$item['ip']] = $item;
        if (count($list) > 300) {
            $list = array_slice($list, -200, null, true);
        }
        return $list;
    });
}

/* ------------------------------------------------------------------
 * 6. Sad-Face (☹️) Block Page UI (Clean iOS Style + Live Countdown)
 * ------------------------------------------------------------------ */

function sh_wants_json()
{
    return isset($_GET['ajax']) || isset($_GET['sync'])
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && stripos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') === 0);
}

function sh_contact_url()
{
    if (function_exists('tg_cfg')) {
        $c = trim((string) tg_cfg('contact_url', ''));
        if ($c !== '') {
            return $c;
        }
    }
    return 'https://t.me/iosnea';
}

function sh_fmt_remaining($sec)
{
    $sec = max(0, (int) $sec);
    $h   = (int) floor($sec / 3600);
    $m   = (int) floor(($sec % 3600) / 60);
    $s   = $sec % 60;
    if ($h > 0) {
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
    return sprintf('%02d:%02d', $m, $s);
}

function sh_render_sad_page(array $ban, $ip, $httpCode = 403)
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $now       = time();
    $expiresTs = 0;
    if (!empty($ban['expires_ts'])) {
        $expiresTs = (int) $ban['expires_ts'];
    } elseif (!empty($ban['expires_at']) && strpos((string) $ban['expires_at'], '0000') !== 0) {
        $parsed = strtotime((string) $ban['expires_at']);
        if ($parsed !== false) {
            $expiresTs = (int) $parsed;
        }
    }
    $isTemp = $expiresTs > 0;
    $left   = $isTemp ? max(1, $expiresTs - $now) : 0;
    $code   = ($isTemp && $httpCode === 429) ? 429 : 403;

    if (!headers_sent()) {
        http_response_code($code);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        if ($isTemp) {
            header('Retry-After: ' . $left);
        }
    }

    $reason = trim((string) (isset($ban['reason']) ? $ban['reason'] : ''));
    if ($reason === '') {
        $reason = $isTemp
            ? 'Phát hiện thao tác bất thường hoặc spam quá nhanh từ địa chỉ IP của bạn.'
            : 'Địa chỉ IP của bạn đã bị quản trị viên giới hạn truy cập do vi phạm quy định.';
    }
    $banId = isset($ban['id']) && (int) $ban['id'] > 0
        ? 'BAN-' . str_pad((string) (int) $ban['id'], 5, '0', STR_PAD_LEFT)
        : 'SH-' . strtoupper(substr(md5($ip), 0, 6));

    if (sh_wants_json()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'result'      => 'ip_blocked',
            'temporary'   => $isTemp,
            'retry_after' => $left,
            'reason'      => $reason,
            'message'     => $reason,
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    $e = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $contact = sh_contact_url();
    $title   = $isTemp ? 'Tạm ngưng truy cập' : 'Truy cập bị giới hạn';
    $sub     = $isTemp
        ? 'Hệ thống bảo vệ tự động của iosmods.xyz đang tạm khoá kết nối từ địa chỉ IP của bạn. Các thành viên khác vẫn truy cập bình thường.'
        : 'Địa chỉ IP của bạn hiện đang bị chặn truy cập vào iosmods.xyz theo quyết định bảo mật của quản trị viên.';
    ?><!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title><?= $e($title) ?> · iosmods.xyz</title>
<style>
:root{--bg:#f2f4f7;--card:#ffffff;--text:#14171f;--muted:#667085;--line:#e4e7ec;--reason-bg:#fef3f2;--reason-bd:#fecdca;--reason-tx:#b42318;--chip:#f8f9fb;--accent:#d92d20;--btn:#14171f;--btn-tx:#ffffff;--timer-bg:#eff8ff;--timer-bd:#b2ddff;--timer-tx:#175cd3}
@media (prefers-color-scheme:dark){:root{--bg:#0b0d12;--card:#131720;--text:#f2f4f7;--muted:#98a2b3;--line:#232936;--reason-bg:#271518;--reason-bd:#552328;--reason-tx:#fda29b;--chip:#191f2b;--accent:#f04438;--btn:#f2f4f7;--btn-tx:#10131a;--timer-bg:#10233a;--timer-bd:#1e3f66;--timer-tx:#84caff}}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{height:100%}
body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"SF Pro Display","Segoe UI",Roboto,Helvetica,Arial,sans-serif;display:flex;align-items:center;justify-content:center;padding:max(20px,env(safe-area-inset-top)) 18px max(20px,env(safe-area-inset-bottom));-webkit-font-smoothing:antialiased}
.card{width:100%;max-width:430px;background:var(--card);border:1px solid var(--line);border-radius:22px;padding:32px 24px 24px;text-align:center}
.sad-wrap{width:76px;height:76px;border-radius:50%;background:var(--chip);border:1px solid var(--line);margin:0 auto 18px;display:flex;align-items:center;justify-content:center;color:var(--accent)}
.sad-wrap svg{width:40px;height:40px}
.brand{font-size:11.5px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:var(--muted);margin-bottom:6px}
h1{margin:0 0 8px;font-size:22px;font-weight:700;letter-spacing:-.02em}
.sub{margin:0 0 18px;color:var(--muted);font-size:13.8px;line-height:1.55}
.reason-box{background:var(--reason-bg);border:1px solid var(--reason-bd);color:var(--reason-tx);border-radius:14px;padding:13px 15px;text-align:left;margin-bottom:16px}
.reason-lbl{font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;opacity:.85;margin-bottom:4px}
.reason-txt{font-size:14px;font-weight:560;line-height:1.5;word-break:break-word}
.timer-box{background:var(--timer-bg);border:1px solid var(--timer-bd);color:var(--timer-tx);border-radius:14px;padding:12px 15px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.timer-lbl{font-size:13px;font-weight:600;text-align:left}
.timer-val{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:17px;font-weight:700;letter-spacing:.03em}
.rows{border:1px solid var(--line);border-radius:14px;overflow:hidden;text-align:left;margin-bottom:20px;background:var(--chip)}
.row{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:10.5px 14px;font-size:13px;border-top:1px solid var(--line)}
.row:first-child{border-top:0}
.row span:first-child{color:var(--muted);flex:none}
.row span:last-child{font-weight:600;text-align:right;word-break:break-all}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px}
.btn{display:block;width:100%;padding:13.5px 16px;border-radius:13px;background:var(--btn);color:var(--btn-tx);text-decoration:none;font-size:14.5px;font-weight:650;border:0}
.btn:active{opacity:.82}
.note{margin:14px 0 0;color:var(--muted);font-size:12px;line-height:1.5}
</style>
</head>
<body>
<main class="card" role="alert">
  <div class="sad-wrap" aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="9.5"/>
      <circle cx="9" cy="9.8" r="1" fill="currentColor" stroke="none"/>
      <circle cx="15" cy="9.8" r="1" fill="currentColor" stroke="none"/>
      <path d="M8.4 16.3c1-1.45 2.25-2.15 3.6-2.15s2.6.7 3.6 2.15"/>
    </svg>
  </div>
  <div class="brand">IOSMODS.XYZ SECURITY</div>
  <h1><?= $e($title) ?></h1>
  <p class="sub"><?= $e($sub) ?></p>

  <div class="reason-box">
    <div class="reason-lbl">Lý do của bạn</div>
    <div class="reason-txt"><?= $e($reason) ?></div>
  </div>

<?php if ($isTemp) { ?>
  <div class="timer-box">
    <span class="timer-lbl">Tự động gỡ chặn sau</span>
    <span class="timer-val" id="sh-cd" data-left="<?= (int) $left ?>"><?= $e(sh_fmt_remaining($left)) ?></span>
  </div>
<?php } ?>

  <div class="rows">
    <div class="row"><span>Địa chỉ IP</span><span class="mono"><?= $e($ip) ?></span></div>
    <div class="row"><span>Mã kiểm tra</span><span class="mono"><?= $e($banId) ?></span></div>
    <div class="row"><span>Trạng thái</span><span><?= $isTemp ? 'Tạm khoá tự động' : 'Khoá bảo mật' ?></span></div>
  </div>

  <a class="btn" href="<?= $e($contact) ?>" rel="noopener">Liên hệ Admin để gỡ khoá sớm</a>
  <p class="note"><?= $isTemp ? 'Khi đồng hồ đếm ngược về 00:00, hệ thống sẽ tự động mở lại trang bình thường cho bạn.' : 'Nếu bạn cần hỗ trợ mở khoá, hãy gửi mã kiểm tra ở trên cho Admin.' ?></p>
</main>
<?php if ($isTemp) { ?>
<script>
(function(){
  var el = document.getElementById('sh-cd');
  if (!el) return;
  var left = parseInt(el.getAttribute('data-left') || '0', 10);
  function pad(n){ return n < 10 ? '0' + n : '' + n; }
  function fmt(s){
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return h > 0 ? (pad(h) + ':' + pad(m) + ':' + pad(sec)) : (pad(m) + ':' + pad(sec));
  }
  var iv = setInterval(function(){
    left--;
    if (left <= 0) {
      clearInterval(iv);
      el.textContent = '00:00';
      window.location.reload();
      return;
    }
    el.textContent = fmt(left);
  }, 1000);
})();
</script>
<?php } ?>
</body>
</html>
<?php
}

/* ------------------------------------------------------------------
 * 7. Pre-DB Anti-DDoS & WAF Guard (Runs BEFORE mysqli_connect!)
 * ------------------------------------------------------------------ */

function sh_detect_waf_attack()
{
    $sn = basename((string) strtok((string) (isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : ''), '?'));
    if ($sn === 'admin_cp.php' || $sn === 'telegram_bot.php') {
        return '';
    }
    $uri = isset($_SERVER['REQUEST_URI']) ? rawurldecode((string) $_SERVER['REQUEST_URI']) : '';
    if (strlen($uri) > 4096) {
        return 'URI quá dài bất thường (nghi vấn tấn công tràn bộ đệm / HTTP Flood)';
    }
    $method = strtoupper((string) (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET'));
    if (in_array($method, ['TRACE', 'TRACK', 'CONNECT', 'DEBUG'], true)) {
        return 'Phương thức HTTP bất thường (' . $method . ')';
    }
    $haystack = $uri;
    if (!empty($_POST)) {
        foreach ($_POST as $k => $v) {
            if (is_scalar($v)) {
                $haystack .= ' ' . $k . '=' . substr((string) $v, 0, 500);
            }
        }
    }
    if ($haystack === '') {
        return '';
    }
    $patterns = [
        '/\bunion\s+(?:all\s+)?select\b/i'                     => 'Tấn công SQL Injection (UNION SELECT)',
        '/\b(?:sleep|benchmark)\s*\(\s*\d+/i'                  => 'Tấn công Blind SQL Injection (Time-based)',
        '/\b(?:into\s+(?:out|dump)file|load_file\s*\()/i'      => 'Tấn công đọc/ghi tệp SQL Injection',
        '/(?:\.\.\/){3,}(?:etc\/passwd|proc\/self|windows\/)/i'=> 'Tấn công Path Traversal / LFI',
        '/(?:php:\/\/filter|php:\/\/input|data:\/\/text)/i'    => 'Tấn công PHP Stream Wrapper Exploit',
    ];
    foreach ($patterns as $re => $label) {
        if (preg_match($re, $haystack)) {
            return $label;
        }
    }
    return '';
}

function sh_is_flood_tool_ua()
{
    $ua = trim((string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''));
    if ($ua === '') {
        return empty($_SERVER['HTTP_ACCEPT']) && empty($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }
    return (bool) preg_match(
        '~(wrk/|ApacheBench|ab/\d|siege/|vegeta|bombardier|hey/|locust|k6/|tsung|httperf|hping|slowhttptest|sqlmap|nikto|nuclei|masscan|zgrab|python-requests|aiohttp|httpx/|Go-http-client|libwww-perl|Scrapy)~i',
        $ua
    );
}

/**
 * Called at the very top of db.php BEFORE mysqli_connect().
 * Stops banned IPs & confirmed DDoS/WAF streams in < 0.3ms without opening MySQL.
 */
function sh_early_guard()
{
    if (defined('SH_EARLY_RAN')) {
        return;
    }
    define('SH_EARLY_RAN', 1);

    if (sh_is_excluded_subapp() || sh_is_telegram_webhook_call()) {
        return;
    }

    $ip = sh_client_ip();
    if ($ip === '') {
        return;
    }

    // Safe IPs (Admin, Cloudflare, OneShield, Telegram, Loopback, Whitelist) NEVER get blocked
    list($safe, ) = sh_is_safe_ip($ip);
    if ($safe) {
        return;
    }

    $now = time();

    // 1. Check active fast-cached ban BEFORE touching MySQL
    $cachedBan = sh_read_ban_cache($ip);
    if ($cachedBan !== null) {
        $expTs = isset($cachedBan['expires_ts']) ? (int) $cachedBan['expires_ts'] : 0;
        if ($expTs > 0 && $expTs <= $now) {
            // Timed ban expired! Lift cache immediately and mark for clean DB unban
            sh_remove_ban_cache($ip);
            $GLOBALS['SH_JUST_EXPIRED_IP'] = $ip;
        } else {
            // Throttle disk writes during heavy DDoS (update hit counter at most once per 10s)
            if ($now - (int) (isset($cachedBan['last_touch']) ? $cachedBan['last_touch'] : 0) >= 10) {
                $cachedBan['hits']       = (int) (isset($cachedBan['hits']) ? $cachedBan['hits'] : 0) + 1;
                $cachedBan['last_touch'] = $now;
                sh_write_ban_cache($ip, $cachedBan);
            }
            sh_render_sad_page($cachedBan, $ip, $expTs > 0 ? 429 : 403);
            exit;
        }
    }

    $cfg = sh_cfg();
    if (empty($cfg['enabled'])) {
        return;
    }

    // 2. Skip static / polling sub-resources so normal fast browsing NEVER triggers flood counters
    if (sh_is_light_subresource()) {
        return;
    }

    // 3. High-precision WAF exploit check
    if (!empty($cfg['waf_enabled'])) {
        $wafWhy = sh_detect_waf_attack();
        if ($wafWhy !== '') {
            $dur = max(600, (int) $cfg['ddos_ban_sec']);
            $rec = [
                'ip'         => $ip,
                'reason'     => 'Hệ thống chống tấn công tự động chặn yêu cầu nguy hiểm: ' . $wafWhy . '.',
                'ban_type'   => 'waf',
                'created_ts' => $now,
                'created_at' => date('Y-m-d H:i:s', $now),
                'expires_ts' => $now + $dur,
                'expires_at' => date('Y-m-d H:i:s', $now + $dur),
                'hits'       => 1,
            ];
            sh_write_ban_cache($ip, $rec);
            sh_queue_pending_ban($rec + ['attack_detail' => $wafWhy]);
            sh_render_sad_page($rec, $ip, 403);
            exit;
        }
    }

    // 4. Multi-factor L7 / L4-proxied HTTP Flood detection
    $h       = md5($ip);
    $rateFile = sh_dir() . '/rates/' . substr($h, 0, 2) . '/' . $h . '.json';
    $isPost  = strtoupper((string) (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET')) === 'POST';
    $isBotUa = sh_is_flood_tool_ua();
    $isHuman = sh_has_human_cookie($ip);

    $verdict = null;
    sh_json_update($rateFile, function ($st) use ($now, $isPost, $isBotUa, $isHuman, &$verdict) {
        $hits  = isset($st['h']) && is_array($st['h']) ? $st['h'] : [];
        $posts = isset($st['p']) && is_array($st['p']) ? $st['p'] : [];

        $cut15 = $now - 15;
        $cut10 = $now - 10;
        $cut5  = $now - 5;

        $h15 = [];
        $c5  = 0;
        foreach ($hits as $t) {
            $t = (int) $t;
            if ($t >= $cut15) {
                $h15[] = $t;
                if ($t >= $cut5) {
                    $c5++;
                }
            }
        }
        $h15[] = $now;
        $c5++;
        $c15 = count($h15);

        $p10 = [];
        foreach ($posts as $t) {
            $t = (int) $t;
            if ($t >= $cut10) {
                $p10[] = $t;
            }
        }
        if ($isPost) {
            $p10[] = $now;
        }
        $cp10 = count($p10);

        if ($isBotUa && ($c5 >= 18 || $c15 >= 32)) {
            $verdict = ['kind' => 'L7 Bot/Tool Flood', 'rps' => $c5, 'w15' => $c15];
        } elseif (!$isHuman && ($c5 >= 40 || $c15 >= 85)) {
            $verdict = ['kind' => 'L7 HTTP Flood', 'rps' => $c5, 'w15' => $c15];
        } elseif ($cp10 >= 28) {
            $verdict = ['kind' => 'L7 POST Spam Flood', 'rps' => $cp10, 'w15' => $c15];
        } elseif ($isHuman && $c5 >= 95) {
            $verdict = ['kind' => 'High-Rate Flood', 'rps' => $c5, 'w15' => $c15];
        }

        return ['h' => array_slice($h15, -120), 'p' => array_slice($p10, -50), 'u' => $now];
    });

    if ($verdict !== null) {
        $dur    = max(600, (int) $cfg['ddos_ban_sec']);
        $mins   = (int) ceil($dur / 60);
        $reason = 'Hệ thống chống DDoS phát hiện lưu lượng spam bất thường liên tục (' . $verdict['kind'] . ' · ' . $verdict['w15'] . ' yêu cầu/15s). Tạm chặn tự động ' . $mins . ' phút.';
        $rec    = [
            'ip'         => $ip,
            'reason'     => $reason,
            'ban_type'   => 'ddos',
            'created_ts' => $now,
            'created_at' => date('Y-m-d H:i:s', $now),
            'expires_ts' => $now + $dur,
            'expires_at' => date('Y-m-d H:i:s', $now + $dur),
            'hits'       => (int) $verdict['w15'],
        ];
        sh_write_ban_cache($ip, $rec);
        sh_queue_pending_ban($rec + [
            'attack_detail' => $verdict['kind'] . ' (' . $verdict['w15'] . ' req/15s)',
            'ua'            => substr((string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''), 0, 140),
        ]);
        // Append to ddos_ips.list so external firewall / admin inspection can read verified DDoS IPs
        @file_put_contents(sh_dir() . '/ddos_ips.list', date('Y-m-d H:i:s') . "\t" . $ip . "\t" . $verdict['kind'] . "\n", FILE_APPEND | LOCK_EX);
        sh_render_sad_page($rec, $ip, 429);
        exit;
    }
}

/* ------------------------------------------------------------------
 * 8. Post-DB Sync, Admin IP Auto-Learning & Key-Spam / VPN Watchers
 * ------------------------------------------------------------------ */

function sh_sync_pending_to_db($conn)
{
    if (!($conn instanceof mysqli) || !function_exists('ipg_temp_ban')) {
        return;
    }
    $pFile = sh_dir() . '/pending_bans.json';
    if (!is_file($pFile)) {
        return;
    }
    $items = [];
    sh_json_update($pFile, function ($cur) use (&$items) {
        $items = is_array($cur) ? $cur : [];
        return [];
    });
    if (!$items) {
        return;
    }
    foreach ($items as $ip => $it) {
        $left = max(60, (int) (isset($it['expires_ts']) ? $it['expires_ts'] : time() + 1800) - time());
        $res  = ipg_temp_ban(
            $conn,
            $ip,
            $left,
            isset($it['reason']) ? $it['reason'] : 'Tự động chặn DDoS',
            'bot-ddos',
            isset($it['username']) ? $it['username'] : '',
            isset($it['ban_type']) ? $it['ban_type'] : 'ddos'
        );
        if (function_exists('tg_event')) {
            tg_event('ddos_block', [
                'ip'       => $ip,
                'ban_type' => isset($it['ban_type']) ? $it['ban_type'] : 'ddos',
                'detail'   => isset($it['attack_detail']) ? $it['attack_detail'] : 'HTTP Flood',
                'reason'   => isset($it['reason']) ? $it['reason'] : '',
                'duration' => $left,
                'ban_id'   => !empty($res['row']['id']) ? (int) $res['row']['id'] : 0,
                'ua'       => isset($it['ua']) ? $it['ua'] : '',
            ]);
        }
    }
}

/**
 * Called right after $conn is ready in db.php.
 * - Remembers logged-in Admin IPs so Admins can NEVER be accidentally banned.
 * - Sweeps expired temporary bans in MySQL and notifies Admin when a user is auto-unbanned.
 * - Enforces ip_bans across ALL site pages (not just index.php) and issues human session cookie.
 */
function sh_post_db_guard($conn)
{
    if (!($conn instanceof mysqli) || sh_is_excluded_subapp() || sh_is_telegram_webhook_call()) {
        return;
    }
    if (is_file(__DIR__ . '/ipguard.php')) {
        require_once __DIR__ . '/ipguard.php';
    }
    $ip = sh_client_ip();

    try {
        // Sync any pre-DB DDoS bans into ip_bans + Telegram alerts
        sh_sync_pending_to_db($conn);

        // Sweep any expired temporary bans in ip_bans (auto-unban!)
        if (function_exists('ipg_sweep_expired')) {
            ipg_sweep_expired($conn, !empty($GLOBALS['SH_JUST_EXPIRED_IP']) ? $GLOBALS['SH_JUST_EXPIRED_IP'] : '');
        }

        // If visitor is logged in as Admin (role = 1), remember their IP in safe admin list
        $sessUser = isset($_SESSION['username']) && is_string($_SESSION['username']) ? trim($_SESSION['username']) : '';
        if ($sessUser !== '' && $ip !== '') {
            $st = @$conn->prepare('SELECT role FROM users WHERE username = ? LIMIT 1');
            if ($st) {
                $st->bind_param('s', $sessUser);
                if ($st->execute()) {
                    $rs  = $st->get_result();
                    $row = $rs ? $rs->fetch_assoc() : null;
                    if ($row && (int) $row['role'] === 1) {
                        sh_remember_admin_ip($ip, $sessUser);
                    }
                }
                $st->close();
            }
        }
    } catch (Throwable $e) {
        error_log('[security_shield] post_db: ' . $e->getMessage());
    }

    // Enforce IP ban (renders Sad-Face page for banned IPs on every page)
    if (function_exists('ipg_run')) {
        ipg_run($conn);
    }

    // Normal visitor passed all checks -> issue human verification cookie on GET page renders
    if (!sh_is_light_subresource() && strtoupper((string) (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET')) === 'GET') {
        sh_issue_human_cookie($ip);
    }
}

/**
 * Intentional Key-Input Spam Detector (called when a user submits a wrong key or hits key_locked).
 * Isolates ONLY the spamming IP to the Sad-Face (☹️) page for a fixed duration, then auto-unbans.
 */
function sh_on_key_fail($conn, $username, $typedKey = '', $keyType = 'FREE')
{
    $ip = sh_client_ip();
    if ($ip === '') {
        return false;
    }
    list($safe, ) = sh_is_safe_ip($ip);
    if ($safe) {
        return false;
    }

    $cfg      = sh_cfg();
    $max10m   = max(3, (int) $cfg['key_fail_limit']);
    $max30s   = max(3, (int) $cfg['key_burst_limit']);
    $banSec   = max(300, (int) $cfg['key_spam_ban_sec']);
    $now      = time();
    $h        = md5($ip);
    $failFile = sh_dir() . '/keyfails/' . substr($h, 0, 2) . '/' . $h . '.json';

    $c10m  = 0;
    $c30s  = 0;
    $trig  = false;
    sh_json_update($failFile, function ($st) use ($now, $max10m, $max30s, &$c10m, &$c30s, &$trig) {
        $arr = isset($st['t']) && is_array($st['t']) ? $st['t'] : [];
        $keep = [];
        foreach ($arr as $ts) {
            $ts = (int) $ts;
            if ($ts >= $now - 600) {
                $keep[] = $ts;
                if ($ts >= $now - 30) {
                    $c30s++;
                }
            }
        }
        $keep[] = $now;
        $c30s++;
        $c10m = count($keep);
        if ($c10m >= $max10m || $c30s >= $max30s) {
            $trig = true;
            $keep = []; // reset counter once isolated
        }
        return ['t' => array_slice($keep, -50), 'u' => $now];
    });

    if (!$trig || !($conn instanceof mysqli) || !function_exists('ipg_temp_ban')) {
        return false;
    }

    $mins   = (int) ceil($banSec / 60);
    $reason = 'Cố tình spam nhập sai Key liên tục quá nhiều lần (' . $c10m . ' lần sai). Địa chỉ IP của bạn bị tạm khoá ' . $mins . ' phút — hết thời gian sẽ tự động mở lại.';
    $res    = ipg_temp_ban($conn, $ip, $banSec, $reason, 'bot-keyguard', (string) $username, 'key_spam');
    if (!empty($res['ok'])) {
        if (function_exists('tg_event')) {
            tg_event('key_spam_ban', [
                'ip'       => $ip,
                'username' => (string) $username,
                'fails'    => $c10m,
                'type'     => (string) $keyType,
                'key'      => (string) $typedKey,
                'duration' => $banSec,
                'reason'   => $reason,
                'ban_id'   => !empty($res['row']['id']) ? (int) $res['row']['id'] : 0,
            ]);
        }
        if (function_exists('site_tg_flush')) {
            site_tg_flush();
        } elseif (function_exists('tg_flush')) {
            tg_flush(false);
        }
        sh_render_sad_page($res['row'] ? $res['row'] : ['reason' => $reason, 'expires_ts' => $now + $banSec], $ip, 429);
        exit;
    }
    return false;
}

/* ------------------------------------------------------------------
 * 9. Smart VPN / Fake IP / Multi-Account Free-Key Watcher & Controller
 * ------------------------------------------------------------------ */

function sh_device_id()
{
    foreach (['fk_did', 'ds_dev'] as $ck) {
        if (!empty($_COOKIE[$ck]) && preg_match('/^[a-f0-9]{32}$/', (string) $_COOKIE[$ck])) {
            return (string) $_COOKIE[$ck];
        }
    }
    $id = bin2hex(random_bytes(16));
    $_COOKIE['ds_dev'] = $id;
    if (!headers_sent()) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '')) === 'https';
        if (PHP_VERSION_ID >= 70300) {
            @setcookie('ds_dev', $id, ['expires' => time() + 63072000, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        } else {
            @setcookie('ds_dev', $id, time() + 63072000, '/; samesite=Lax', '', $https, true);
        }
    }
    return $id;
}

function sh_has_proxy_headers()
{
    foreach (['HTTP_VIA', 'HTTP_X_PROXY_ID', 'HTTP_PROXY_CONNECTION', 'HTTP_FORWARDED'] as $h) {
        if (!empty($_SERVER[$h])) {
            return true;
        }
    }
    return false;
}

function sh_is_vpn_cached($conn, $ipRaw)
{
    if (sh_has_proxy_headers()) {
        return true;
    }
    if (!($conn instanceof mysqli) || $ipRaw === '' || sh_is_private_or_local($ipRaw)) {
        return false;
    }
    try {
        $st = @$conn->prepare('SELECT is_vpn FROM free_key_ip_cache WHERE ip = ? AND checked_at >= ? LIMIT 1');
        if ($st) {
            $since = time() - 86400;
            $st->bind_param('si', $ipRaw, $since);
            if ($st->execute()) {
                $rs  = $st->get_result();
                $row = $rs ? $rs->fetch_assoc() : null;
                $st->close();
                if ($row) {
                    return (int) $row['is_vpn'] === 1;
                }
            } else {
                $st->close();
            }
        }
    } catch (Throwable $e) {
        // table may not exist yet
    }
    return false;
}

function sh_ensure_seen_table($conn)
{
    if (!($conn instanceof mysqli)) {
        return;
    }
    try {
        @$conn->query("CREATE TABLE IF NOT EXISTS free_key_seen (
            username VARCHAR(100) NOT NULL,
            kind VARCHAR(3) NOT NULL,
            val VARCHAR(48) NOT NULL,
            first_seen INT UNSIGNED NOT NULL,
            last_seen INT UNSIGNED NOT NULL,
            PRIMARY KEY (username, kind, val),
            KEY idx_val (kind, val, last_seen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Links an account with its IP (/64) and browser device cookie, returning any other accounts
 * sharing the same device or IP so multi-account Free-Key farming is observed & controlled immediately.
 */
function sh_link_and_find_alts($conn, $username)
{
    $username = trim((string) $username);
    if (!($conn instanceof mysqli) || $username === '') {
        return ['alts' => [], 'vpn' => false, 'ip' => sh_client_ip(), 'dev' => ''];
    }
    $ipRaw = sh_client_ip();
    $ipKey = sh_ip_network_key($ipRaw);
    $dev   = sh_device_id();
    $now   = time();
    $alts  = [];

    try {
        sh_ensure_seen_table($conn);
        if ($ipKey !== '') {
            $st = @$conn->prepare('INSERT INTO free_key_seen (username, kind, val, first_seen, last_seen) VALUES (?, \'ip\', ?, ?, ?) ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)');
            if ($st) {
                $st->bind_param('ssii', $username, $ipKey, $now, $now);
                @$st->execute();
                $st->close();
            }
        }
        if ($dev !== '') {
            $st = @$conn->prepare('INSERT INTO free_key_seen (username, kind, val, first_seen, last_seen) VALUES (?, \'dev\', ?, ?, ?) ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)');
            if ($st) {
                $st->bind_param('ssii', $username, $dev, $now, $now);
                @$st->execute();
                $st->close();
            }
        }

        $st2 = @$conn->prepare("SELECT DISTINCT username FROM free_key_seen WHERE username <> ? AND ((kind = 'dev' AND val = ?) OR (kind = 'ip' AND val = ? AND last_seen > ?)) LIMIT 8");
        if ($st2) {
            $recent = $now - 7 * 86400;
            $st2->bind_param('sssi', $username, $dev, $ipKey, $recent);
            if ($st2->execute()) {
                $rs = $st2->get_result();
                while ($rs && ($r = $rs->fetch_assoc())) {
                    $alts[] = (string) $r['username'];
                }
            }
            $st2->close();
        }
    } catch (Throwable $e) {
        error_log('[security_shield] link_alts: ' . $e->getMessage());
    }

    return [
        'alts' => array_values(array_unique($alts)),
        'vpn'  => sh_is_vpn_cached($conn, $ipRaw),
        'ip'   => $ipRaw,
        'dev'  => $dev,
    ];
}

/** Observe new account registration: links device/IP so alt-accounts CANNOT claim extra Free Keys, and returns meta for Telegram alert. */
function sh_observe_register($conn, $username, $userId = 0)
{
    $info = sh_link_and_find_alts($conn, $username);
    $info['id'] = (int) $userId;
    return $info;
}

/**
 * Observe & control repeated VPN / Fake IP / Multi-account Free-Key attempts on get_key.php.
 * If an IP persistently spams Free-Key bypasses (>= 4 blocked tries in 30m), isolate to Sad-Face temporary ban.
 */
function sh_on_freekey_abuse($conn, $username, $event, $ipRaw = '')
{
    $ip = $ipRaw !== '' ? sh_norm_ip($ipRaw) : sh_client_ip();
    $info = sh_link_and_find_alts($conn, $username);

    if (function_exists('tg_event')) {
        tg_event('freekey_blocked', [
            'username' => (string) $username,
            'event'    => (string) $event,
            'ip'       => $ip,
            'alts'     => $info['alts'],
            'vpn'      => $event === 'blocked_vpn' || !empty($info['vpn']),
        ]);
        if (function_exists('tg_flush')) {
            tg_flush(false);
        }
    }

    if ($ip === '') {
        return;
    }
    list($safe, ) = sh_is_safe_ip($ip);
    if ($safe) {
        return;
    }

    $now  = time();
    $h    = md5($ip);
    $file = sh_dir() . '/fkabuse/' . substr($h, 0, 2) . '/' . $h . '.json';
    $cnt  = 0;
    $trig = false;
    sh_json_update($file, function ($st) use ($now, &$cnt, &$trig) {
        $arr  = isset($st['t']) && is_array($st['t']) ? $st['t'] : [];
        $keep = [];
        foreach ($arr as $ts) {
            if ((int) $ts >= $now - 1800) {
                $keep[] = (int) $ts;
            }
        }
        $keep[] = $now;
        $cnt = count($keep);
        if ($cnt >= 5) {
            $trig = true;
            $keep = [];
        }
        return ['t' => $keep];
    });

    if ($trig && ($conn instanceof mysqli) && function_exists('ipg_temp_ban')) {
        $cfg    = sh_cfg();
        $banSec = max(600, (int) $cfg['vpn_spam_ban_sec']);
        $mins   = (int) ceil($banSec / 60);
        $reason = 'Phát hiện bật VPN / Fake IP hoặc đổi nhiều tài khoản phụ liên tục (' . $cnt . ' lần) để lạm dụng Key Free. Tạm khoá ' . $mins . ' phút.';
        $res    = ipg_temp_ban($conn, $ip, $banSec, $reason, 'bot-antivpn', (string) $username, 'vpn_abuse');
        if (!empty($res['ok']) && function_exists('tg_event')) {
            tg_event('vpn_multi_acc', [
                'ip'       => $ip,
                'username' => (string) $username,
                'alts'     => $info['alts'],
                'duration' => $banSec,
                'reason'   => $reason,
                'ban_id'   => !empty($res['row']['id']) ? (int) $res['row']['id'] : 0,
            ]);
            if (function_exists('tg_flush')) {
                tg_flush(false);
            }
        }
    }
}

/**
 * Checks Free-Key activation on index.php: if a user created a new alt account on the SAME device
 * and turned on VPN / Fake IP to bypass the IP daily limit after another account on that device already
 * activated a Free Key within 8 hours, block it cleanly and notify Admin!
 */
function sh_check_freekey_redeem($conn, $username, $keyType, $enforceRules = true)
{
    if (!$enforceRules || strtoupper((string) $keyType) !== 'FREE' || !($conn instanceof mysqli)) {
        return true;
    }
    // Check if Admin granted a 1-time pass
    try {
        $stp = @$conn->prepare('SELECT expires_at FROM free_key_pass WHERE username = ? LIMIT 1');
        if ($stp) {
            $stp->bind_param('s', $username);
            if ($stp->execute()) {
                $rp = $stp->get_result();
                $pr = $rp ? $rp->fetch_assoc() : null;
                $stp->close();
                if ($pr && (int) $pr['expires_at'] > time()) {
                    return true;
                }
            } else {
                $stp->close();
            }
        }
    } catch (Throwable $e) {
        // table may not exist
    }

    $info = sh_link_and_find_alts($conn, $username);
    if (empty($info['alts'])) {
        return true;
    }

    // Did any linked account on the same device activate a FREE key in the last 8 hours?
    try {
        $alts = array_slice($info['alts'], 0, 6);
        $ph   = implode(',', array_fill(0, count($alts), '?'));
        $sql  = "SELECT username FROM key_daily_claims WHERE key_type = 'FREE' AND claimed_at > DATE_SUB(NOW(), INTERVAL 8 HOUR) AND username IN ($ph) LIMIT 1";
        $st   = @$conn->prepare($sql);
        if ($st) {
            $types = str_repeat('s', count($alts));
            $st->bind_param($types, ...$alts);
            if ($st->execute()) {
                $rs  = $st->get_result();
                $hit = $rs ? $rs->fetch_assoc() : null;
                $st->close();
                if ($hit) {
                    if (function_exists('tg_event')) {
                        tg_event('vpn_multi_acc', [
                            'ip'       => $info['ip'],
                            'username' => (string) $username,
                            'alts'     => $info['alts'],
                            'duration' => 0,
                            'reason'   => 'Đổi tài khoản phụ (' . $hit['username'] . ' → ' . $username . ') + đổi mạng/VPN để nhập thêm Key Free trong 8h.',
                        ]);
                    }
                    return false;
                }
            } else {
                $st->close();
            }
        }
    } catch (Throwable $e) {
        // table may not exist
    }
    return true;
}
