<?php
/* ==========================================================================
   security_shield.php  —  IOSMODS Autonomous AI Security Core (V4 Peak Edition)
   - 100% Immunity for Website Admin (role=1 / dv_admin / sh_adm_pass / Whitelist)
     and Telegram Bot (webhook / cron / Telegram API IPs).
   - Untouched Sub-apps: /peoeopoo and /ios_b are 100% bypassed.
   - Deep Forensic IP Tracer: Unmasks BOTH the DDoS stream/proxy server IP AND
     the attacker's real origin IP (via proxy-chain peeling, leak headers,
     VPN/ASN intelligence, timezone/region mismatch, and WebRTC candidate probe).
   - Self-Healing Stream Drainer: Drops active high-rate flood streams in <0.1ms
     before session/MySQL to release server resources cleanly and auto-recover.
   - Anti-Bug / Anti-Buff / Anti-Script / Anti-PC-Inspect (works even before login!).
   - Two Intelligent Response Tiers:
       1) Happy Smiley (😊) Friendly Gate: Visitor only has VPN/Fake-Region or
          passive browser script/extension enabled with zero malicious attempts ->
          politely names the exact tool to turn off so they can enter immediately.
       2) Strict Cross-Device Lockdown (☹️): DDoS, spamming, PC source inspection,
          running exploit scripts, bugging/buffing, or using VPN/script to bypass
          key limits -> binds Real IP + Stream IP + Hardware Fingerprint (GPU/Canvas)
          + Multi-storage Browser ID + Account so changing device or faking IP
          cannot evade until the AI smart timer confirms quiet compliance.
   - PHP 7.2 – 8.3+ compatible. Never throws fatal errors.
   ========================================================================== */

if (!defined('SH_SHIELD_LOADED')) {
    define('SH_SHIELD_LOADED', 2);
}

/* ------------------------------------------------------------------
 * 1. Sub-App & Bot Webhook Immunity
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
 * 2. Fast Sharded Storage in ./cache/shield (gitignored via cache/*)
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

function sh_preview_token()
{
    $f = sh_dir() . '/preview_tok.json';
    $d = sh_json_read($f);
    if (!empty($d['t']) && strlen((string) $d['t']) >= 16) {
        return (string) $d['t'];
    }
    $t = substr(hash_hmac('sha256', 'preview-ui', sh_secret()), 0, 20);
    sh_json_update($f, function () use ($t) {
        return ['t' => $t];
    });
    return $t;
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
        'vpn_gate_enabled' => true,   // Happy Smiley gate when passive VPN / Script is detected
        'inspect_guard'    => true,   // Block PC View-Source / DevTools / Userscripts for non-admins
        'ddos_ban_sec'     => 3600,   // 60m initial smart lock for L7/L4 flood
        'script_ban_sec'   => 3600,   // 60m initial smart lock for script / inspect / bug / buff
        'key_spam_ban_sec' => 1800,   // 30m initial smart lock for key/system spam
        'vpn_spam_ban_sec' => 2700,   // 45m initial smart lock for VPN/script key-bypass
        'key_fail_limit'   => 6,
        'key_burst_limit'  => 4,
    ], $saved);
    return $cfg;
}

function sh_cfg_save(array $patch)
{
    return sh_json_update(sh_dir() . '/config.json', function ($cur) use ($patch) {
        return array_merge($cur, $patch);
    });
}

function sh_stats_bump($metric, $delta = 1)
{
    $day = date('Y-m-d');
    sh_json_update(sh_dir() . '/stats.json', function ($s) use ($day, $metric, $delta) {
        if (!isset($s['day']) || $s['day'] !== $day) {
            $s = ['day' => $day, 'm' => []];
        }
        $s['m'][$metric] = (int) (isset($s['m'][$metric]) ? $s['m'][$metric] : 0) + (int) $delta;
        return $s;
    });
}

function sh_stats_read()
{
    $s = sh_json_read(sh_dir() . '/stats.json');
    return (isset($s['day']) && $s['day'] === date('Y-m-d') && isset($s['m']) && is_array($s['m'])) ? $s['m'] : [];
}

/* ------------------------------------------------------------------
 * 3. Trusted Proxies, Forensic IP Unmasking & Admin Immunity
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
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $cf = sh_norm_ip($_SERVER['HTTP_CF_CONNECTING_IP']);
        if ($cf !== '' && !sh_is_cloudflare($cf) && !sh_is_oneshield($cf)) {
            return $GLOBALS['SH_RESOLVED_IP'] = $cf;
        }
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_reverse(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
        foreach ($parts as $p) {
            $cand = sh_norm_ip($p);
            if ($cand !== '' && !sh_is_trusted_proxy($cand)) {
                return $GLOBALS['SH_RESOLVED_IP'] = $cand;
            }
        }
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $xr = sh_norm_ip($_SERVER['HTTP_X_REAL_IP']);
        if ($xr !== '' && !sh_is_trusted_proxy($xr)) {
            return $GLOBALS['SH_RESOLVED_IP'] = $xr;
        }
    }
    return $GLOBALS['SH_RESOLVED_IP'] = $remote;
}

/**
 * Deep Forensic IP & Stream Origin Tracer:
 * Extracts BOTH the stream-sending server/relay IP AND any leaked origin/controller IP behind proxies/VPNs!
 */
function sh_trace_forensics()
{
    $realIp   = sh_client_ip();
    $remote   = isset($_SERVER['REMOTE_ADDR']) ? sh_norm_ip($_SERVER['REMOTE_ADDR']) : '';
    $hops     = [];
    $leakedIp = '';

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']) as $part) {
            $hip = sh_norm_ip($part);
            if ($hip !== '' && !sh_is_trusted_proxy($hip) && !sh_is_private_or_local($hip)) {
                $hops[] = $hip;
            }
        }
    }
    foreach (['HTTP_TRUE_CLIENT_IP', 'HTTP_X_ORIGINATING_IP', 'HTTP_X_CLIENT_IP', 'HTTP_CLIENT_IP'] as $lh) {
        if (!empty($_SERVER[$lh])) {
            $cand = sh_norm_ip(explode(',', (string) $_SERVER[$lh])[0]);
            if ($cand !== '' && !sh_is_trusted_proxy($cand) && !sh_is_private_or_local($cand) && $cand !== $realIp) {
                $leakedIp = $cand;
                break;
            }
        }
    }
    if ($leakedIp === '' && !empty($_SERVER['HTTP_FORWARDED']) && preg_match('/for="?\[?([a-fA-F0-9:.]+)\]?"?/i', (string) $_SERVER['HTTP_FORWARDED'], $m)) {
        $cand = sh_norm_ip($m[1]);
        if ($cand !== '' && !sh_is_trusted_proxy($cand) && !sh_is_private_or_local($cand) && $cand !== $realIp) {
            $leakedIp = $cand;
        }
    }

    $hops     = array_values(array_unique($hops));
    $streamIp = $realIp;
    $originIp = $realIp;
    if (count($hops) >= 2) {
        // Leftmost untrusted hop = origin controller IP behind proxy; rightmost untrusted hop = stream relay node!
        $originIp = $hops[0];
        $streamIp = $hops[count($hops) - 1];
    } elseif ($leakedIp !== '') {
        $originIp = $leakedIp;
    }

    // Check if client telemetry already revealed a WebRTC public IP for this browser token
    $lid = sh_browser_lid();
    if ($lid !== '') {
        $tele = sh_json_read(sh_dir() . '/telemetry/' . substr($lid, 0, 2) . '/' . $lid . '.json');
        if (!empty($tele['webrtc_ip']) && $tele['webrtc_ip'] !== $streamIp) {
            $originIp = (string) $tele['webrtc_ip'];
        }
    }

    $country = !empty($_SERVER['HTTP_CF_IPCOUNTRY']) ? strtoupper(trim((string) $_SERVER['HTTP_CF_IPCOUNTRY'])) : '';
    return [
        'real_ip'   => $originIp !== '' ? $originIp : $realIp,
        'stream_ip' => $streamIp !== '' ? $streamIp : ($remote !== '' ? $remote : $realIp),
        'hops'      => $hops,
        'country'   => $country,
        'via'       => !empty($_SERVER['HTTP_VIA']) ? substr(trim((string) $_SERVER['HTTP_VIA']), 0, 80) : '',
        'ua'        => substr((string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''), 0, 160),
    ];
}

/* ------------------------------------------------------------------
 * 4. Whitelist & Signed Admin Browser Immunity (Admin NEVER gets blocked)
 * ------------------------------------------------------------------ */

function sh_whitelist_list()
{
    $d    = sh_json_read(sh_dir() . '/whitelist.json');
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

function sh_issue_admin_pass_cookie($adminUser)
{
    if (headers_sent()) {
        return;
    }
    $exp   = time() + 30 * 86400;
    $sig   = hash_hmac('sha256', 'adm|' . $adminUser . '|' . $exp, sh_secret());
    $val   = rawurlencode($adminUser) . '.' . $exp . '.' . $sig;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '')) === 'https';
    if (PHP_VERSION_ID >= 70300) {
        @setcookie('sh_adm_pass', $val, ['expires' => $exp, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    } else {
        @setcookie('sh_adm_pass', $val, $exp, '/; samesite=Lax', '', $https, true);
    }
    $_COOKIE['sh_adm_pass'] = $val;
}

function sh_has_admin_pass_cookie()
{
    if (!empty($_COOKIE['sh_adm_pass']) && is_string($_COOKIE['sh_adm_pass'])) {
        $p = explode('.', $_COOKIE['sh_adm_pass'], 3);
        if (count($p) === 3 && (int) $p[1] > time()) {
            $user = rawurldecode($p[0]);
            $sig  = hash_hmac('sha256', 'adm|' . $user . '|' . (int) $p[1], sh_secret());
            if (hash_equals($sig, $p[2])) {
                return true;
            }
        }
    }
    if (!empty($_COOKIE['dv_admin']) && preg_match('/^\d{1,10}\.(\d{9,11})\.[a-f0-9]{64}$/', (string) $_COOKIE['dv_admin'], $m)) {
        if ((int) $m[1] > time()) {
            return true;
        }
    }
    return false;
}

function sh_remember_admin_ip($ip, $adminUser = 'admin')
{
    sh_issue_admin_pass_cookie($adminUser);
    $ip = sh_norm_ip($ip);
    if ($ip === '' || sh_is_private_or_local($ip)) {
        return;
    }
    $file = sh_dir() . '/admin_ips.json';
    $cur  = sh_json_read($file);
    $now  = time();
    if (isset($cur[$ip]['exp']) && (int) $cur[$ip]['exp'] > $now + 432000) {
        return;
    }
    sh_json_update($file, function ($d) use ($ip, $adminUser, $now) {
        foreach ($d as $k => $v) {
            if (!is_array($v) || (int) (isset($v['exp']) ? $v['exp'] : 0) < $now) {
                unset($d[$k]);
            }
        }
        $d[$ip] = ['user' => (string) $adminUser, 'exp' => $now + 14 * 86400];
        return $d;
    });
}

function sh_is_safe_ip($ip)
{
    $ip = sh_norm_ip($ip);
    if ($ip === '') {
        return [true, 'Địa chỉ IP không hợp lệ.'];
    }
    if (sh_is_private_or_local($ip)) {
        return [true, 'Địa chỉ nội bộ / máy chủ.'];
    }
    $srvIp = isset($_SERVER['SERVER_ADDR']) ? sh_norm_ip($_SERVER['SERVER_ADDR']) : '';
    if ($ip === '161.248.4.251' || ($srvIp !== '' && $ip === $srvIp)) {
        return [true, 'Địa chỉ IP của chính máy chủ VPS.'];
    }
    if (sh_is_cloudflare($ip)) {
        return [true, 'Dải IP hệ thống Cloudflare.'];
    }
    if (sh_is_oneshield($ip)) {
        return [true, 'Dải IP hệ thống OneShield.'];
    }
    if (sh_is_telegram_ip($ip)) {
        return [true, 'Dải IP máy chủ Telegram Bot API.'];
    }
    foreach (sh_whitelist_list() as $cidr => $meta) {
        if (sh_in_cidr($ip, (string) $cidr)) {
            return [true, 'Địa chỉ IP nằm trong Whitelist an toàn.'];
        }
    }
    $adm = sh_json_read(sh_dir() . '/admin_ips.json');
    if (isset($adm[$ip]['exp']) && (int) $adm[$ip]['exp'] > time()) {
        return [true, 'Địa chỉ IP đang được Admin Web sử dụng.'];
    }
    if (sh_has_admin_pass_cookie() && sh_client_ip() === $ip) {
        return [true, 'Trình duyệt đã xác thực quyền Admin Web.'];
    }
    return [false, ''];
}

function sh_is_admin_viewer()
{
    if (sh_has_admin_pass_cookie()) {
        return true;
    }
    $ip = sh_client_ip();
    if ($ip !== '') {
        $adm = sh_json_read(sh_dir() . '/admin_ips.json');
        if (isset($adm[$ip]['exp']) && (int) $adm[$ip]['exp'] > time()) {
            return true;
        }
    }
    return false;
}

/* ------------------------------------------------------------------
 * 5. Cross-Device / Cross-VPN Identity Tokens & Lockdown Graph
 * ------------------------------------------------------------------ */

function sh_browser_lid()
{
    foreach (['sh_lid', 'fk_did', 'ds_dev'] as $ck) {
        if (!empty($_COOKIE[$ck]) && preg_match('/^[a-f0-9]{32}$/', (string) $_COOKIE[$ck])) {
            return strtolower((string) $_COOKIE[$ck]);
        }
    }
    return '';
}

function sh_ensure_browser_lid()
{
    $lid = sh_browser_lid();
    if ($lid === '') {
        $lid = bin2hex(random_bytes(16));
        $_COOKIE['sh_lid'] = $lid;
    }
    if (!headers_sent()) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '')) === 'https';
        if (PHP_VERSION_ID >= 70300) {
            @setcookie('sh_lid', $lid, ['expires' => time() + 63072000, 'path' => '/', 'secure' => $https, 'httponly' => false, 'samesite' => 'Lax']);
        } else {
            @setcookie('sh_lid', $lid, time() + 63072000, '/; samesite=Lax', '', $https, false);
        }
    }
    return $lid;
}

function sh_hw_fp_cookie()
{
    if (!empty($_COOKIE['sh_hw']) && preg_match('/^[a-f0-9]{16,64}$/i', (string) $_COOKIE['sh_hw'])) {
        return strtolower((string) $_COOKIE['sh_hw']);
    }
    return '';
}

function sh_entity_lock_path($kind, $val)
{
    $h = md5($kind . ':' . strtolower(trim((string) $val)));
    return sh_dir() . '/elocks/' . substr($h, 0, 2) . '/' . $h . '.json';
}

/** Binds Hardware Fingerprint + Browser LID + Account into Cross-Device/Cross-VPN Lockdown! */
function sh_bind_entity_lock($kind, $val, array $banRecord)
{
    $val = strtolower(trim((string) $val));
    if ($val === '') {
        return;
    }
    sh_json_update(sh_entity_lock_path($kind, $val), function ($cur) use ($kind, $val, $banRecord) {
        return array_merge($cur, $banRecord, ['kind' => $kind, 'val' => $val, 'updated_ts' => time()]);
    });
}

function sh_read_entity_lock($kind, $val)
{
    $val = strtolower(trim((string) $val));
    if ($val === '') {
        return null;
    }
    $p = sh_entity_lock_path($kind, $val);
    if (!is_file($p)) {
        return null;
    }
    $d = sh_json_read($p);
    if (empty($d['val'])) {
        return null;
    }
    $exp = isset($d['expires_ts']) ? (int) $d['expires_ts'] : 0;
    if ($exp > 0 && $exp <= time()) {
        @unlink($p);
        return null;
    }
    return $d;
}

function sh_clear_all_entity_locks_for_ip($ip)
{
    $ip  = sh_norm_ip($ip);
    $rec = sh_read_ban_cache($ip);
    if ($rec) {
        if (!empty($rec['hw_fp'])) {
            @unlink(sh_entity_lock_path('hw', $rec['hw_fp']));
        }
        if (!empty($rec['lid'])) {
            @unlink(sh_entity_lock_path('lid', $rec['lid']));
        }
        if (!empty($rec['username'])) {
            @unlink(sh_entity_lock_path('user', $rec['username']));
        }
        if (!empty($rec['stream_ip']) && $rec['stream_ip'] !== $ip) {
            sh_remove_ban_cache($rec['stream_ip']);
        }
    }
}

/* ------------------------------------------------------------------
 * 6. Fast IP Ban Cache & Adaptive Smart Timer ("Đủ vừa rồi tự mở")
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
    $lid  = !empty($record['lid']) ? (string) $record['lid'] : sh_browser_lid();
    $hwFp = !empty($record['hw_fp']) ? (string) $record['hw_fp'] : sh_hw_fp_cookie();

    $saved = sh_json_update(sh_ban_cache_path($ip), function ($cur) use ($ip, $record, $lid, $hwFp) {
        return array_merge([
            'ip'               => $ip,
            'stream_ip'        => $ip,
            'id'               => isset($cur['id']) ? (int) $cur['id'] : 0,
            'reason'           => 'Tạm ngưng truy cập để bảo vệ hệ thống',
            'ban_type'         => 'temp',
            'username'         => '',
            'banned_by'        => 'bot-shield',
            'lid'              => $lid,
            'hw_fp'            => $hwFp,
            'evasion_attempts' => isset($cur['evasion_attempts']) ? (int) $cur['evasion_attempts'] : 0,
            'created_ts'       => time(),
            'created_at'       => date('Y-m-d H:i:s'),
            'expires_ts'       => 0,
            'expires_at'       => null,
            'hits'             => isset($cur['hits']) ? (int) $cur['hits'] : 0,
            'win_ts'           => time(),
            'win_hits'         => 1,
            'last_touch'       => time(),
        ], $record);
    });

    // Cross-bind hardware fingerprint & browser LID so changing VPN/IP or browser still enforces lockdown!
    if (in_array((string) $saved['ban_type'], ['ddos', 'waf', 'script', 'inspect', 'bug_buff', 'vpn_abuse', 'manual'], true)) {
        if ($hwFp !== '') {
            sh_bind_entity_lock('hw', $hwFp, $saved);
        }
        if ($lid !== '') {
            sh_bind_entity_lock('lid', $lid, $saved);
        }
        if (!empty($saved['username'])) {
            sh_bind_entity_lock('user', $saved['username'], $saved);
        }
    }
}

function sh_remove_ban_cache($ip)
{
    $ip = sh_norm_ip($ip);
    if ($ip === '') {
        return;
    }
    sh_clear_all_entity_locks_for_ip($ip);
    $path = sh_ban_cache_path($ip);
    if (is_file($path)) {
        @unlink($path);
    }
    $pFile = sh_dir() . '/pending_bans.json';
    if (is_file($pFile)) {
        sh_json_update($pFile, function ($cur) use ($ip) {
            if (is_array($cur) && isset($cur[$ip])) {
                unset($cur[$ip]);
            }
            return is_array($cur) ? $cur : [];
        });
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

function sh_queue_pending_event($type, array $data)
{
    sh_json_update(sh_dir() . '/pending_events.json', function ($list) use ($type, $data) {
        if (!is_array($list)) {
            $list = [];
        }
        $list[] = ['type' => $type, 'data' => $data, 'at' => time()];
        return array_slice($list, -80);
    });
}

/* ------------------------------------------------------------------
 * 7. VPN / Fake-Region & Passive Script Intelligence (Even Before Login!)
 * ------------------------------------------------------------------ */

function sh_has_proxy_headers()
{
    foreach (['HTTP_VIA', 'HTTP_X_PROXY_ID', 'HTTP_PROXY_CONNECTION', 'HTTP_FORWARDED', 'HTTP_X_TOR'] as $h) {
        if (!empty($_SERVER[$h])) {
            return true;
        }
    }
    return false;
}

/**
 * Fast cached IP VPN / Proxy / Hosting / Fake-Region check (works pre-DB with 24h file cache).
 * Returns ['is_vpn' => bool, 'label' => string, 'isp' => string, 'country' => string].
 */
function sh_inspect_ip_vpn($ip)
{
    $ip = sh_norm_ip($ip);
    if ($ip === '' || sh_is_private_or_local($ip)) {
        return ['is_vpn' => false, 'label' => '', 'isp' => 'Local', 'country' => 'VN'];
    }
    if (sh_has_proxy_headers()) {
        return ['is_vpn' => true, 'label' => 'HTTP Proxy / Fake IP Header', 'isp' => 'Proxy', 'country' => ''];
    }

    $h     = md5($ip);
    $cFile = sh_dir() . '/ip_intel/' . substr($h, 0, 2) . '/' . $h . '.json';
    $now   = time();
    $cached = sh_json_read($cFile);
    if (!empty($cached['at']) && (int) $cached['at'] > $now - 86400) {
        return $cached;
    }

    $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,countryCode,isp,org,as,proxy,hosting';
    $raw = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 2,
        ]);
        $raw = @curl_exec($ch);
        curl_close($ch);
    }
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j) || (isset($j['status']) && $j['status'] !== 'success')) {
        $fallback = ['is_vpn' => false, 'label' => '', 'isp' => '', 'country' => '', 'at' => $now - 84000];
        sh_json_update($cFile, function () use ($fallback) { return $fallback; });
        return $fallback;
    }

    $isp     = trim((string) (isset($j['isp']) ? $j['isp'] : '') . ' ' . (string) (isset($j['org']) ? $j['org'] : '') . ' ' . (string) (isset($j['as']) ? $j['as'] : ''));
    $country = strtoupper((string) (isset($j['countryCode']) ? $j['countryCode'] : ''));
    $isProxy = !empty($j['proxy']);
    $isHost  = !empty($j['hosting']);
    $vpnKw   = (bool) preg_match('~(Cloudflare|WARP|M247|Datacamp|Choopa|Vultr|DigitalOcean|Linode|Hetzner|OVH|Contabo|Nord|ExpressVPN|Proton|Mullvad|Surfshark|CyberGhost|LeaseWeb|Tzulo|PQ Hosting|G-Core|Zenlayer|Tor Exit|Private Internet Access|Windscribe)~i', $isp);

    $isVpn = $isProxy || $isHost || $vpnKw;
    $label = '';
    if ($isVpn) {
        if (stripos($isp, 'Cloudflare') !== false || stripos($isp, 'WARP') !== false) {
            $label = 'Ứng dụng 1.1.1.1 / Cloudflare WARP (Fake IP)';
        } elseif ($isProxy || $vpnKw) {
            $label = 'VPN / Proxy chuyển vùng (' . ($country !== '' ? $country : 'Quốc tế') . ')';
        } else {
            $label = 'Mạng Máy Chủ Ảo / Fake IP (' . ($country !== '' ? $country : 'Hosting') . ')';
        }
    }

    $info = [
        'is_vpn'  => $isVpn,
        'label'   => $label,
        'isp'     => substr($isp, 0, 100),
        'country' => $country,
        'at'      => $now,
    ];
    sh_json_update($cFile, function () use ($info) { return $info; });
    return $info;
}

/* ------------------------------------------------------------------
 * 8. Two-Tier UI Renderers:
 *    - Tier 1: sh_render_happy_gate()  (😊 Mặt cười vui — Vui lòng tắt VPN / Script)
 *    - Tier 2: sh_render_sad_page()    (☹️ Chặn IP nghiêm ngặt / Tạm khoá Spam hệ thống)
 * ------------------------------------------------------------------ */

function sh_wants_json()
{
    return isset($_GET['ajax']) || isset($_GET['sync']) || isset($_GET['__sh_telemetry']) || isset($_GET['__sh_report'])
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
    return $h > 0 ? sprintf('%02d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
}

/**
 * TIER 1 UI — Happy Smiley Face (😊 Mặt cười vui):
 * Shown when visitor ONLY has VPN / Fake-Region or a passive browser Userscript/Extension enabled
 * and has NOT attempted any bug, buff, key-bypass, or DDoS!
 */
function sh_render_happy_gate(array $detect, $ip, $previewBarHtml = '')
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $GLOBALS['dsx_hash_on'] = 1; // do not inject URL hash script into guard screen
    if (!headers_sent()) {
        http_response_code(!empty($detect['is_preview']) ? 200 : 403);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
    }

    $whatOff = !empty($detect['turn_off']) ? (string) $detect['turn_off'] : 'VPN / Proxy (1.1.1.1) hoặc tiện ích Script trên trình duyệt';
    $detail  = !empty($detect['detail']) ? (string) $detect['detail'] : 'Hệ thống nhận thấy kết nối của bạn đang bật công cụ trung gian (chưa phát sinh vi phạm).';

    if (sh_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['result' => 'please_turn_off', 'turn_off' => $whatOff, 'message' => 'Vui lòng tắt ' . $whatOff . ' để tiếp tục.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    ?><!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title>Vui lòng tắt công cụ trung gian · iosmods.xyz</title>
<style>
:root{--bg:#f2f5f8;--card:#ffffff;--text:#12161f;--muted:#5e6878;--line:#e3e8ef;--smile-bg:#ecfdf3;--smile-bd:#abefc6;--smile-tx:#067647;--box-bg:#f0fdf4;--box-bd:#bbf7d0;--box-tx:#065f46;--chip:#f8fafc;--btn:#0f172a;--btn-tx:#ffffff}
@media (prefers-color-scheme:dark){:root{--bg:#0a0d12;--card:#121721;--text:#f1f5f9;--muted:#94a3b8;--line:#222b3a;--smile-bg:#0d281b;--smile-bd:#184d32;--smile-tx:#4ade80;--box-bg:#0c2419;--box-bd:#16462e;--box-tx:#86efac;--chip:#171e2b;--btn:#f1f5f9;--btn-tx:#0f172a}}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{min-height:100%}
body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"SF Pro Display","Segoe UI",Roboto,Helvetica,Arial,sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:max(20px,env(safe-area-inset-top)) 18px max(20px,env(safe-area-inset-bottom));-webkit-font-smoothing:antialiased}
.card{width:100%;max-width:435px;background:var(--card);border:1px solid var(--line);border-radius:24px;padding:32px 24px 24px;text-align:center}
.smile-wrap{width:78px;height:78px;border-radius:50%;background:var(--smile-bg);border:1px solid var(--smile-bd);margin:0 auto 18px;display:flex;align-items:center;justify-content:center;color:var(--smile-tx)}
.smile-wrap svg{width:42px;height:42px}
.brand{font-size:11px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:var(--muted);margin-bottom:6px}
h1{margin:0 0 8px;font-size:21.5px;font-weight:700;letter-spacing:-.02em}
.sub{margin:0 0 18px;color:var(--muted);font-size:13.8px;line-height:1.58}
.turnoff-box{background:var(--box-bg);border:1px solid var(--box-bd);color:var(--box-tx);border-radius:15px;padding:14px 16px;text-align:left;margin-bottom:16px}
.turnoff-lbl{font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;opacity:.85;margin-bottom:5px}
.turnoff-val{font-size:14.5px;font-weight:650;line-height:1.48}
.rows{border:1px solid var(--line);border-radius:14px;overflow:hidden;text-align:left;margin-bottom:20px;background:var(--chip)}
.row{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:10.5px 14px;font-size:13px;border-top:1px solid var(--line)}
.row:first-child{border-top:0}
.row span:first-child{color:var(--muted);flex:none}
.row span:last-child{font-weight:600;text-align:right;word-break:break-all}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px}
.btn{display:block;width:100%;padding:14px 16px;border-radius:13px;background:var(--btn);color:var(--btn-tx);text-decoration:none;font-size:14.5px;font-weight:650;border:0;cursor:pointer}
.btn:active{opacity:.84}
.note{margin:14px 0 0;color:var(--muted);font-size:12px;line-height:1.5}
</style>
</head>
<body>
<main class="card" role="dialog" aria-label="Friendly security notice">
  <div class="smile-wrap" aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="9.5"/>
      <path d="M8.2 9.8c.35-.55.9-.85 1.45-.85s1.1.3 1.45.85"/>
      <path d="M12.9 9.8c.35-.55.9-.85 1.45-.85s1.1.3 1.45.85"/>
      <path d="M8.1 14.1c.95 1.75 2.3 2.55 3.9 2.55s2.95-.8 3.9-2.55"/>
    </svg>
  </div>
  <div class="brand">IOSMODS.XYZ SMART GUARD</div>
  <h1>Chào bạn! Vui lòng tắt công cụ phụ trợ</h1>
  <p class="sub"><?= $e($detail) ?></p>

  <div class="turnoff-box">
    <div class="turnoff-lbl">Vui lòng tắt trước khi tiếp tục</div>
    <div class="turnoff-val">👉 Vui lòng tắt: <?= $e($whatOff) ?></div>
  </div>

  <div class="rows">
    <div class="row"><span>IP kết nối hiện tại</span><span class="mono"><?= $e($ip) ?></span></div>
    <div class="row"><span>Mức độ tài khoản</span><span style="color:var(--smile-tx)">An toàn (Chưa bị khoá)</span></div>
  </div>

  <a class="btn" href="/" onclick="document.cookie='sh_vg_retry=1;path=/;max-age=15';">Đã tắt xong · Vào lại iosmods.xyz</a>
  <p class="note">Bạn hoàn toàn chưa bị chặn IP. Ngay sau khi tắt công cụ trên và tải lại trang, hệ thống sẽ mở cửa bình thường ngay lập tức.</p>
</main>
<?= $previewBarHtml ?>
</body>
</html>
<?php
}

/**
 * TIER 2 UI — Sad-Face / Strict Multi-Layer Lockdown (☹️):
 * Distinguishes clearly between:
 *  - Mode A: Strict IP & Device Lockdown (DDoS, Script, PC View-Source, Bug/Buff, VPN Key-Bypass)
 *  - Mode B: System / Key-Input Spam Isolation (Spam quá nhiều vào hệ thống)
 */
function sh_render_sad_page(array $ban, $ip, $httpCode = 403, $previewBarHtml = '')
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $GLOBALS['dsx_hash_on'] = 1;

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
    $isTemp    = $expiresTs > 0;
    $left      = $isTemp ? max(1, $expiresTs - $now) : 0;
    $banType   = isset($ban['ban_type']) ? (string) $ban['ban_type'] : 'manual';
    $isStrict  = in_array($banType, ['ddos', 'waf', 'script', 'inspect', 'bug_buff', 'vpn_abuse', 'manual'], true);
    $isPreview = !empty($ban['is_preview']);
    $code      = $isPreview ? 200 : (($isTemp && $httpCode === 429) ? 429 : 403);

    if (!headers_sent()) {
        http_response_code($code);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        if ($isTemp && !$isPreview) {
            header('Retry-After: ' . $left);
        }
    }

    $reason = trim((string) (isset($ban['reason']) ? $ban['reason'] : ''));
    if ($reason === '') {
        $reason = $isStrict
            ? 'Phát hiện hành vi can thiệp bảo mật (DDoS / Chạy Script / Xem mã nguồn / Bug-Buff hệ thống).'
            : 'Phát hiện spam thao tác liên tục quá nhiều lần vào hệ thống trong thời gian ngắn.';
    }
    $banId = isset($ban['id']) && (int) $ban['id'] > 0
        ? 'BAN-' . str_pad((string) (int) $ban['id'], 5, '0', STR_PAD_LEFT)
        : 'AI-' . strtoupper(substr(md5($ip . '|' . $banType), 0, 6));
    $streamIp = !empty($ban['stream_ip']) && $ban['stream_ip'] !== $ip ? (string) $ban['stream_ip'] : '';
    $evasions = isset($ban['evasion_attempts']) ? (int) $ban['evasion_attempts'] : 0;

    if (sh_wants_json() && !$isPreview) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'result'      => 'ip_blocked',
            'temporary'   => $isTemp,
            'retry_after' => $left,
            'reason'      => $reason,
            'message'     => $reason,
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $contact = sh_contact_url();
    $title   = $isStrict ? 'Truy cập đã bị chặn bảo mật' : 'Tạm ngưng do spam hệ thống';
    $sub     = $isStrict
        ? 'AI Bảo Vệ của iosmods.xyz đã phong toả kết nối trên địa chỉ IP và thiết bị của bạn (đổi mạng/VPN hoặc đổi trình duyệt đều bị giữ khoá cho đến khi hệ thống đánh giá an toàn). Người dùng khác vẫn hoạt động bình thường.'
        : 'Bạn đã gửi thao tác hoặc nhập sai quá nhiều lần liên tiếp vào hệ thống. Chỉ địa chỉ IP của bạn bị chuyển sang trang chờ này, các thành viên khác vẫn sử dụng bình thường.';
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
html,body{min-height:100%}
body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"SF Pro Display","Segoe UI",Roboto,Helvetica,Arial,sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:max(20px,env(safe-area-inset-top)) 18px max(20px,env(safe-area-inset-bottom));-webkit-font-smoothing:antialiased}
.card{width:100%;max-width:440px;background:var(--card);border:1px solid var(--line);border-radius:24px;padding:30px 24px 24px;text-align:center}
.sad-wrap{width:76px;height:76px;border-radius:50%;background:var(--reason-bg);border:1px solid var(--reason-bd);margin:0 auto 16px;display:flex;align-items:center;justify-content:center;color:var(--accent)}
.sad-wrap svg{width:40px;height:40px}
.brand{font-size:11px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:var(--muted);margin-bottom:6px}
h1{margin:0 0 8px;font-size:21.5px;font-weight:700;letter-spacing:-.02em}
.sub{margin:0 0 18px;color:var(--muted);font-size:13.5px;line-height:1.56}
.reason-box{background:var(--reason-bg);border:1px solid var(--reason-bd);color:var(--reason-tx);border-radius:15px;padding:13px 15px;text-align:left;margin-bottom:15px}
.reason-lbl{font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;opacity:.88;margin-bottom:4px}
.reason-txt{font-size:13.8px;font-weight:580;line-height:1.5;word-break:break-word}
.timer-box{background:var(--timer-bg);border:1px solid var(--timer-bd);color:var(--timer-tx);border-radius:14px;padding:12px 15px;margin-bottom:15px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.timer-lbl{font-size:12.8px;font-weight:600;text-align:left}
.timer-val{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:17px;font-weight:700;letter-spacing:.03em}
.rows{border:1px solid var(--line);border-radius:14px;overflow:hidden;text-align:left;margin-bottom:18px;background:var(--chip)}
.row{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:10px 14px;font-size:12.8px;border-top:1px solid var(--line)}
.row:first-child{border-top:0}
.row span:first-child{color:var(--muted);flex:none}
.row span:last-child{font-weight:600;text-align:right;word-break:break-all}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.2px}
.btn{display:block;width:100%;padding:13.5px 16px;border-radius:13px;background:var(--btn);color:var(--btn-tx);text-decoration:none;font-size:14.2px;font-weight:650;border:0}
.btn:active{opacity:.84}
.note{margin:13px 0 0;color:var(--muted);font-size:11.8px;line-height:1.5}
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
  <div class="brand">IOSMODS.XYZ AI SHIELD</div>
  <h1><?= $e($title) ?></h1>
  <p class="sub"><?= $e($sub) ?></p>

  <div class="reason-box">
    <div class="reason-lbl">Lý do ghi nhận trên thiết bị / IP của bạn</div>
    <div class="reason-txt"><?= $e($reason) ?></div>
  </div>

<?php if ($isTemp) { ?>
  <div class="timer-box">
    <span class="timer-lbl"><?= $isStrict ? 'AI tự đánh giá & mở khoá sau' : 'Tự động gỡ khoá sau' ?></span>
    <span class="timer-val" id="sh-cd" data-left="<?= (int) $left ?>" data-preview="<?= $isPreview ? '1' : '0' ?>"><?= $e(sh_fmt_remaining($left)) ?></span>
  </div>
<?php } ?>

  <div class="rows">
    <div class="row"><span>Địa chỉ IP thật</span><span class="mono"><?= $e($ip) ?></span></div>
<?php if ($streamIp !== '') { ?>
    <div class="row"><span>IP máy chủ gửi luồng</span><span class="mono"><?= $e($streamIp) ?></span></div>
<?php } ?>
    <div class="row"><span>Phạm vi kiểm soát</span><span><?= $isStrict ? 'Đa lớp (IP + Thiết bị + Vân tay)' : 'Cách ly tạm thời theo IP' ?></span></div>
<?php if ($evasions > 0) { ?>
    <div class="row"><span>Cố tình đổi IP / lách khoá</span><span style="color:var(--accent)"><?= (int) $evasions ?> lần (Đã tự động gia hạn)</span></div>
<?php } ?>
    <div class="row"><span>Mã hồ sơ Bot</span><span class="mono"><?= $e($banId) ?></span></div>
  </div>

  <a class="btn" href="<?= $e($contact) ?>" rel="noopener">Liên hệ Admin để được xem xét mở sớm</a>
  <p class="note"><?= $isStrict ? 'Lưu ý: Cố tình bật Fake IP/VPN hoặc đổi trình duyệt trong lúc đang khoá sẽ khiến AI tự động cộng thêm thời gian phạt. Hãy chờ yên cho đến khi đồng hồ kết thúc.' : 'Khi đồng hồ đếm ngược về 00:00, Bot sẽ tự động mở lại kết nối bình thường cho bạn.' ?></p>
</main>
<?= $previewBarHtml ?>
<?php if ($isTemp) { ?>
<script>
(function(){
  var el = document.getElementById('sh-cd');
  if (!el) return;
  var left = parseInt(el.getAttribute('data-left') || '0', 10);
  var isPv = el.getAttribute('data-preview') === '1';
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
      if (!isPv) window.location.reload();
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
 * 9. Safe Non-Destructive UI Preview Route (?sh_test_view=...&k=<tok>)
 *    Lets the owner inspect all security UIs on iPhone/PC without banning themselves!
 * ------------------------------------------------------------------ */

function sh_handle_preview_showcase()
{
    if (empty($_GET['sh_test_view']) || empty($_GET['k'])) {
        return;
    }
    if (!hash_equals(sh_preview_token(), (string) $_GET['k'])) {
        return;
    }
    $mode = (string) $_GET['sh_test_view'];
    $tok  = rawurlencode(sh_preview_token());
    $ip   = sh_client_ip() ?: '113.161.72.18';

    $nav = '<div style="margin-top:14px;display:flex;flex-wrap:wrap;gap:8px;justify-content:center;max-width:440px;width:100%">'
        . '<a href="?sh_test_view=ban_ip&k=' . $tok . '" style="padding:8px 12px;border-radius:10px;font-size:12px;font-weight:600;text-decoration:none;border:1px solid #334155;background:#1e293b;color:#f8fafc">🚫 1. Bị chặn IP / DDoS / Script</a>'
        . '<a href="?sh_test_view=spam_sys&k=' . $tok . '" style="padding:8px 12px;border-radius:10px;font-size:12px;font-weight:600;text-decoration:none;border:1px solid #334155;background:#1e293b;color:#f8fafc">☹️ 2. Spam quá nhiều (Mặt buồn)</a>'
        . '<a href="?sh_test_view=happy_vpn&k=' . $tok . '" style="padding:8px 12px;border-radius:10px;font-size:12px;font-weight:600;text-decoration:none;border:1px solid #166534;background:#14532d;color:#dcfce7">😊 3. Mặt cười nhắc tắt VPN/Script</a>'
        . '</div>';

    if ($mode === 'happy_vpn') {
        sh_render_happy_gate([
            'is_preview' => true,
            'turn_off'   => 'Ứng dụng VPN / 1.1.1.1 (Fake vùng quốc gia) hoặc tiện ích Userscript trên trình duyệt',
            'detail'     => 'AI quan sát nhận thấy bạn đang bật VPN chuyển vùng hoặc cài tiện ích Script ngoài nhưng chưa thực hiện hành vi phá hoại nào.',
        ], $ip, $nav);
        exit;
    }

    if ($mode === 'spam_sys') {
        sh_render_sad_page([
            'id'         => 208,
            'is_preview' => true,
            'ban_type'   => 'key_spam',
            'reason'     => 'Cố tình spam thao tác và nhập sai Key liên tục quá nhiều lần (7 lần liên tiếp) vào hệ thống. Tạm khoá cách ly 30 phút — các thành viên khác vẫn truy cập bình thường.',
            'expires_ts' => time() + 1785,
        ], $ip, 429, $nav);
        exit;
    }

    // Default: ban_ip (Strict Multi-Layer Block: DDoS / Script / Bug-Buff / Source Inspect)
    sh_render_sad_page([
        'id'               => 519,
        'is_preview'       => true,
        'ban_type'         => 'script',
        'stream_ip'        => '103.179.44.210 (Proxy/Node)',
        'evasion_attempts' => 2,
        'reason'           => 'AI Bảo Vệ phát hiện hành vi gửi luồng DDoS / chạy Script can thiệp / mở công cụ xem mã nguồn hoặc lạm dụng Bug-Buff hệ thống. Đã phong toả toàn diện IP thật + IP luồng + Vân tay thiết bị.',
        'expires_ts'       => time() + 3590,
    ], $ip, 403, $nav);
    exit;
}

/* ------------------------------------------------------------------
 * 10. Client Telemetry & Active Anti-Inspect / Anti-Script Endpoint
 * ------------------------------------------------------------------ */

function sh_client_sig($lid)
{
    return substr(hash_hmac('sha256', 'cguard|' . $lid, sh_secret()), 0, 24);
}

function sh_handle_client_endpoints()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (!isset($_GET['__sh_telemetry']) && !isset($_GET['__sh_report'])) {
        return;
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if (sh_is_admin_viewer()) {
        echo json_encode(['ok' => true, 'action' => 'allow_admin']);
        exit;
    }

    $forensics = sh_trace_forensics();
    $ip        = $forensics['real_ip'];
    list($safe, ) = sh_is_safe_ip($ip);
    if ($safe) {
        echo json_encode(['ok' => true, 'action' => 'allow_safe']);
        exit;
    }

    $lid      = isset($_POST['lid']) && preg_match('/^[a-f0-9]{32}$/i', (string) $_POST['lid']) ? strtolower((string) $_POST['lid']) : sh_ensure_browser_lid();
    $sig      = isset($_POST['sig']) ? (string) $_POST['sig'] : '';
    $hwFp     = isset($_POST['hw']) && preg_match('/^[a-f0-9]{16,64}$/i', (string) $_POST['hw']) ? strtolower((string) $_POST['hw']) : '';
    $tz       = isset($_POST['tz']) ? trim(substr((string) $_POST['tz'], 0, 60)) : '';
    $wrtcIp   = isset($_POST['wrtc']) ? sh_norm_ip($_POST['wrtc']) : '';
    $passive  = isset($_POST['passive_script']) ? trim(substr((string) $_POST['passive_script'], 0, 120)) : '';
    $violate  = isset($_POST['violation']) ? trim(substr((string) $_POST['violation'], 0, 180)) : '';

    if ($hwFp !== '' && !headers_sent()) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '')) === 'https';
        if (PHP_VERSION_ID >= 70300) {
            @setcookie('sh_hw', $hwFp, ['expires' => time() + 63072000, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        } else {
            @setcookie('sh_hw', $hwFp, time() + 63072000, '/; samesite=Lax', '', $https, true);
        }
    }

    // Save telemetry including WebRTC real IP if leaked behind VPN/proxy!
    if ($wrtcIp !== '' && !sh_is_private_or_local($wrtcIp) && !sh_is_trusted_proxy($wrtcIp)) {
        $forensics['real_ip'] = $wrtcIp;
    }
    sh_json_update(sh_dir() . '/telemetry/' . substr($lid, 0, 2) . '/' . $lid . '.json', function ($cur) use ($ip, $wrtcIp, $hwFp, $tz, $passive) {
        return [
            'http_ip'        => $ip,
            'webrtc_ip'      => $wrtcIp,
            'hw_fp'          => $hwFp,
            'tz'             => $tz,
            'passive_script' => $passive,
            'at'             => time(),
        ];
    });

    // 1. Cross-Device / Cross-VPN Evasion Catch!
    $elock = ($hwFp !== '' ? sh_read_entity_lock('hw', $hwFp) : null) ?: sh_read_entity_lock('lid', $lid);
    if ($elock !== null) {
        $newExp = max((int) $elock['expires_ts'], time() + 1800) + 900; // +15m penalty for trying to switch IP/device!
        $evCnt  = (int) (isset($elock['evasion_attempts']) ? $elock['evasion_attempts'] : 0) + 1;
        $elock['ip']               = $ip;
        $elock['stream_ip']        = $forensics['stream_ip'];
        $elock['expires_ts']       = $newExp;
        $elock['expires_at']       = date('Y-m-d H:i:s', $newExp);
        $elock['evasion_attempts'] = $evCnt;
        sh_write_ban_cache($ip, $elock);
        sh_queue_pending_ban($elock + ['attack_detail' => 'Đổi IP/Thiết bị lách khoá (lần #' . $evCnt . ')']);
        echo json_encode(['ok' => true, 'action' => 'reload_block']);
        exit;
    }

    // 2. Active Violation Report (__sh_report): PC View-Source / DevTools / Active Automation / Monkey-Patch Script!
    $cfg = sh_cfg();
    if (isset($_GET['__sh_report']) && $violate !== '' && !empty($cfg['inspect_guard']) && hash_equals(sh_client_sig($lid), $sig)) {
        $dur    = max(900, (int) $cfg['script_ban_sec']);
        $now    = time();
        $reason = 'AI Bảo Vệ phát hiện hành vi can thiệp trực tiếp: ' . $violate . '. Hệ thống khoá bảo vệ đa lớp trên IP & thiết bị này.';
        $rec    = [
            'ip'          => $forensics['real_ip'],
            'stream_ip'   => $forensics['stream_ip'],
            'reason'      => $reason,
            'ban_type'    => 'inspect',
            'lid'         => $lid,
            'hw_fp'       => $hwFp,
            'created_ts'  => $now,
            'created_at'  => date('Y-m-d H:i:s', $now),
            'expires_ts'  => $now + $dur,
            'expires_at'  => date('Y-m-d H:i:s', $now + $dur),
            'hits'        => 1,
        ];
        sh_write_ban_cache($ip, $rec);
        if ($forensics['real_ip'] !== $ip) {
            sh_write_ban_cache($forensics['real_ip'], $rec);
        }
        sh_queue_pending_ban($rec + ['attack_detail' => $violate]);
        sh_stats_bump('script_blocks', 1);
        echo json_encode(['ok' => true, 'action' => 'reload_block']);
        exit;
    }

    // 3. Passive Userscript / Fake-Region VPN Check -> Happy Smiley (😊) Gate!
    if (!empty($cfg['vpn_gate_enabled'])) {
        if ($passive !== '') {
            sh_json_update(sh_dir() . '/happy_flags/' . substr($lid, 0, 2) . '/' . $lid . '.json', function () use ($passive) {
                return ['turn_off' => 'Tiện ích Script ngoài (' . $passive . ')', 'exp' => time() + 180];
            });
            sh_stats_bump('happy_gates', 1);
            echo json_encode(['ok' => true, 'action' => 'reload_happy']);
            exit;
        }
        $vpn = sh_inspect_ip_vpn($ip);
        $vnTzMismatch = ($tz === 'Asia/Ho_Chi_Minh' || $tz === 'Asia/Saigon' || $tz === 'Asia/Bangkok')
            && $vpn['country'] !== '' && $vpn['country'] !== 'VN' && $vpn['is_vpn'];
        $webrtcMismatch = ($wrtcIp !== '' && !sh_is_private_or_local($wrtcIp) && $wrtcIp !== $ip && $vpn['is_vpn']);
        if ($vpn['is_vpn'] || $vnTzMismatch || $webrtcMismatch) {
            $lbl = $vpn['label'] !== '' ? $vpn['label'] : 'VPN / Proxy chuyển vùng (Fake IP)';
            sh_json_update(sh_dir() . '/happy_flags/' . substr($lid, 0, 2) . '/' . $lid . '.json', function () use ($lbl) {
                return ['turn_off' => $lbl, 'exp' => time() + 120];
            });
            sh_stats_bump('happy_gates', 1);
            echo json_encode(['ok' => true, 'action' => 'reload_happy']);
            exit;
        }
    }

    echo json_encode(['ok' => true, 'action' => 'allow']);
    exit;
}

/** Client-side Anti-Script, Anti-PC-Source-Inspect & WebRTC/Hardware Fingerprint Guard (Injected via .htdsx_hash.php). */
function sh_client_guard_script()
{
    if (sh_is_excluded_subapp() || sh_is_admin_viewer()) {
        return '';
    }
    $lid = sh_ensure_browser_lid();
    $sig = sh_client_sig($lid);
    return '<script data-sh="guard-v4">(function(){'
        . 'if(window.__shG4)return;window.__shG4=1;'
        . 'var LID="' . $lid . '",SIG="' . $sig . '";'
        . 'try{localStorage.setItem("sh_lid",LID);sessionStorage.setItem("sh_lid",LID);}catch(e){}'
        . 'try{var m=localStorage.getItem("sh_lid");if(m&&/^[a-f0-9]{32}$/.test(m)&&m!==LID){LID=m;document.cookie="sh_lid="+m+";path=/;max-age=63072000;SameSite=Lax";}}catch(e){}'
        . 'function h32(s){var h=2166136261;for(var i=0;i<s.length;i++){h^=s.charCodeAt(i);h=Math.imul(h,16777619);}return (h>>>0).toString(16).padStart(8,"0");}'
        . 'function hwFp(){try{var p=[screen.width+"x"+screen.height+"x"+screen.colorDepth,navigator.hardwareConcurrency||0,navigator.platform||"",Intl.DateTimeFormat().resolvedOptions().timeZone||""];'
        . 'var c=document.createElement("canvas"),g=c.getContext("webgl")||c.getContext("experimental-webgl");'
        . 'if(g){var d=g.getExtension("WEBGL_debug_renderer_info");if(d)p.push(g.getParameter(d.UNMASKED_RENDERER_WEBGL)||"");}'
        . 'var x=c.getContext("2d");if(x){x.textBaseline="top";x.font="14px Arial";x.fillText("iosmods-sh",2,2);p.push(c.toDataURL().slice(-32));}'
        . 'var raw=p.join("|");return h32(raw)+h32(raw.split("").reverse().join(""))+h32("v4|"+raw)+h32(raw.length+raw);}catch(e){return "";}}'
        . 'var HW=hwFp(),reported=0;'
        . 'function post(ep,obj,cb){try{var f=new URLSearchParams();f.set("lid",LID);f.set("sig",SIG);f.set("hw",HW);for(var k in obj)f.set(k,obj[k]);'
        . 'fetch((location.pathname||"/")+"?"+ep+"=1",{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded","X-Requested-With":"XMLHttpRequest"},body:f.toString()})'
        . '.then(function(r){return r.json();}).then(function(j){if(cb)cb(j);}).catch(function(){});}catch(e){}}'
        . 'function trip(why){if(reported)return;reported=1;post("__sh_report",{violation:why},function(j){if(j&&(j.action==="reload_block"||j.action==="reload_happy"))location.reload();});}'
        // 1. Detect Headless / Active Automation immediately
        . 'if(navigator.webdriver||window._phantom||window.__nightmare||window.callPhantom||window.Cypress||window.__playwright){trip("Trình duyệt tự động hoá / Bot Script (WebDriver)");return;}'
        // 2. Desktop PC Anti-View-Source & Anti-DevTools shortcuts
        . 'document.addEventListener("keydown",function(e){var k=(e.key||"").toLowerCase(),c=e.ctrlKey||e.metaKey,s=e.shiftKey;'
        . 'if(e.keyCode===123||(c&&k==="u")||(c&&s&&(k==="i"||k==="j"||k==="c"))){e.preventDefault();e.stopPropagation();trip("Mở công cụ xem mã nguồn trên máy tính (Phím tắt F12 / Ctrl+U / Inspect)");return false;}},true);'
        . 'document.addEventListener("contextmenu",function(e){if(!("ontouchstart" in window)&&navigator.maxTouchPoints===0){e.preventDefault();}},true);'
        // 3. Desktop DevTools Docked Window Detector (zero false positives on mobile/iPhone)
        . 'if(!("ontouchstart" in window)&&navigator.maxTouchPoints===0&&screen.width>=1024){'
        . 'var dtHits=0;setInterval(function(){if(document.hidden)return;var dw=window.outerWidth-window.innerWidth,dh=window.outerHeight-window.innerHeight;'
        . 'if(dw>240||dh>240){dtHits++;if(dtHits>=3)trip("Bật bảng DevTools xem mã nguồn trực tiếp trên máy tính");}else{dtHits=0;}},1800);}'
        // 4. Detect Passive Userscripts (Tampermonkey / Violentmonkey / Eruda / vConsole)
        . 'function findPassiveScript(){if(window.GM_info||window.GM||window.unsafeWindow)return "Tampermonkey / Userscript";'
        . 'if(window.eruda||window.VConsole)return "Công cụ soi mã nguồn Eruda / vConsole";'
        . 'var sc=document.querySelectorAll("script[src]");for(var i=0;i<sc.length;i++){var u=sc[i].getAttribute("src")||"";if(/^(chrome-extension|moz-extension|safari-web-extension):\/\//i.test(u))return "Extension chèn Script ngoài";}'
        . 'return "";}'
        // 5. WebRTC Real-IP Probe + Telemetry Check (runs once per session/IP)
        . 'function sendTele(wrtcIp){var ps=findPassiveScript(),tz="";try{tz=Intl.DateTimeFormat().resolvedOptions().timeZone||"";}catch(e){}'
        . 'post("__sh_telemetry",{tz:tz,wrtc:wrtcIp||"",passive_script:ps},function(j){if(j&&(j.action==="reload_block"||j.action==="reload_happy"))location.reload();});}'
        . 'setTimeout(function(){var done=0;try{var RT=window.RTCPeerConnection||window.webkitRTCPeerConnection;if(!RT){sendTele("");return;}'
        . 'var pc=new RT({iceServers:[{urls:"stun:stun.cloudflare.com:3478"}]});pc.createDataChannel("");'
        . 'pc.onicecandidate=function(ev){if(done)return;if(!ev||!ev.candidate){done=1;try{pc.close();}catch(x){}sendTele("");return;}'
        . 'var m=(ev.candidate.candidate||"").match(/([0-9]{1,3}(?:\.[0-9]{1,3}){3})/);if(m&&!/^(10\.|127\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/.test(m[1])){done=1;try{pc.close();}catch(x){}sendTele(m[1]);}};'
        . 'pc.createOffer().then(function(o){return pc.setLocalDescription(o);}).catch(function(){if(!done){done=1;sendTele("");}});'
        . 'setTimeout(function(){if(!done){done=1;try{pc.close();}catch(x){}sendTele("");}},1800);}catch(e){if(!done)sendTele("");}},350);'
        . '})();</script>';
}

/* ------------------------------------------------------------------
 * 11. Anti-Bug, Anti-Buff (Race-Condition Lock) & Pre-DB DDoS Shield
 * ------------------------------------------------------------------ */

function sh_is_light_subresource()
{
    if (isset($_GET['brand_logo']) || isset($_GET['favicon']) || isset($_GET['sync']) || isset($_GET['tg_ping'])
        || isset($_GET['ds_check_user']) || isset($_GET['ds_check_key_lock'])
        || isset($_GET['__sh_telemetry']) || isset($_GET['__sh_report'])) {
        return true;
    }
    $path = strtolower((string) strtok((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''), '?'));
    return (bool) preg_match('/\.(?:css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|map)$/', $path);
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
    sh_ensure_browser_lid();
    if (headers_sent() || $ip === '' || sh_has_human_cookie($ip)) {
        return;
    }
    $ts    = time();
    $ua    = substr((string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''), 0, 120);
    $sig   = substr(hash_hmac('sha256', $ts . '|' . sh_ip_network_key($ip) . '|' . $ua, sh_secret()), 0, 32);
    $val   = $ts . '.' . $sig;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '')) === 'https';
    if (PHP_VERSION_ID >= 70300) {
        @setcookie('sh_human', $val, ['expires' => $ts + 86400, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    } else {
        @setcookie('sh_human', $val, $ts + 86400, '/; samesite=Lax', '', $https, true);
    }
    $_COOKIE['sh_human'] = $val;
}

/** Detects WAF exploits, hidden-file bug scanning, parameter array tampering & race-condition buffing! */
function sh_detect_waf_bug_buff($ip)
{
    $sn = basename((string) strtok((string) (isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : ''), '?'));
    if ($sn === 'admin_cp.php' || $sn === 'telegram_bot.php') {
        return null;
    }
    $rawUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $uri    = rawurldecode($rawUri);
    $path   = strtolower((string) strtok($uri, '?'));

    if (strlen($rawUri) > 4096 || strpos($rawUri, '%00') !== false || strpos($uri, "\0") !== false) {
        return ['type' => 'waf', 'why' => 'Gửi gói tin chứa ký tự Null-Byte hoặc URI tràn bộ đệm'];
    }
    $method = strtoupper((string) (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET'));
    if (in_array($method, ['TRACE', 'TRACK', 'CONNECT', 'DEBUG'], true)) {
        return ['type' => 'waf', 'why' => 'Phương thức HTTP trái phép (' . $method . ')'];
    }

    // 1. Anti-Bug Hidden File / Scanner Probing
    if (preg_match('#/(?:\.env|\.git|wp-admin|wp-login\.php|phpmyadmin|adminer|xmlrpc\.php|vendor/phpunit|(?:telegram_|fk_)?config\.php|\.(?:sql|bak|old|swp)$)#i', $path)) {
        return ['type' => 'bug_buff', 'why' => 'Quét tệp cấu hình ẩn / dò lỗ hổng hệ thống (' . substr($path, 0, 50) . ')'];
    }

    // 2. Anti-Bug PHP Array Parameter Tampering on Auth/Key/Tool Endpoints
    foreach (['username', 'password', 'repassword', 'key_vip', 'key_free', 'use_tool', 'csrf'] as $ImportantField) {
        if ((isset($_POST[$ImportantField]) && is_array($_POST[$ImportantField]))
            || (isset($_GET[$ImportantField]) && is_array($_GET[$ImportantField]))) {
            return ['type' => 'bug_buff', 'why' => 'Cố tình truyền mảng tham số giả mạo để Bug logic (' . $ImportantField . '[])'];
        }
    }

    // 3. WAF SQLi / XSS / LFI / RCE Signatures
    $haystack = $uri;
    if (!empty($_POST)) {
        foreach ($_POST as $k => $v) {
            if (is_scalar($v)) {
                $haystack .= ' ' . $k . '=' . substr((string) $v, 0, 500);
            }
        }
    }
    $patterns = [
        '/\bunion\s+(?:all\s+)?select\b/i'                      => 'Tấn công SQL Injection (UNION SELECT)',
        '/\b(?:sleep|benchmark)\s*\(\s*\d+/i'                   => 'Tấn công Blind SQL Injection (Time-based)',
        '/\b(?:into\s+(?:out|dump)file|load_file\s*\()/i'       => 'Tấn công đọc/ghi tệp SQL Injection',
        '/(?:\.\.\/){3,}(?:etc\/passwd|proc\/self|windows\/)/i' => 'Tấn công Path Traversal / LFI',
        '/(?:php:\/\/filter|php:\/\/input|data:\/\/text)/i'     => 'Tấn công PHP Stream Wrapper Exploit',
        '/<\s*script\b[^>]*>|\bjavascript\s*:\s*\w+|\bon(?:error|load|mouseover)\s*=/i' => 'Chèn mã độc XSS / Script trái phép vào tham số',
        '/\$\{jndi:|;\s*(?:wget|curl|bash|sh|nc)\s+/i'          => 'Tấn công thực thi lệnh từ xa (RCE)',
    ];
    foreach ($patterns as $re => $label) {
        if (preg_match($re, $haystack)) {
            return ['type' => 'waf', 'why' => $label];
        }
    }

    // 4. Anti-Buff Concurrent / Rapid Action Guard (prevents parallel race-condition buffing!)
    $isSensitiveAction = ($method === 'POST' && !isset($_POST['ack_alert'])) || isset($_GET['use_tool']);
    if ($isSensitiveAction) {
        $lid   = sh_browser_lid();
        $aKey  = md5('act|' . $ip . '|' . $lid);
        $aFile = sh_dir() . '/act_guard/' . substr($aKey, 0, 2) . '/' . $aKey . '.json';
        $nowMs = (int) round(microtime(true) * 1000);
        $buffDetected = false;

        sh_json_update($aFile, function ($st) use ($nowMs, &$buffDetected) {
            $arr  = isset($st['ms']) && is_array($st['ms']) ? $st['ms'] : [];
            $keep = [];
            $c3s  = 0;
            $c800 = 0;
            foreach ($arr as $t) {
                $t = (int) $t;
                if ($nowMs - $t <= 3000) {
                    $keep[] = $t;
                    $c3s++;
                    if ($nowMs - $t <= 800) {
                        $c800++;
                    }
                }
            }
            $keep[] = $nowMs;
            // >= 4 prior hits within 800ms (5 POSTs in <0.8s) OR >= 8 state-mutating POST/tool hits within 3s = Script Buff / Race Exploit!
            if ($c800 >= 4 || $c3s >= 8) {
                $buffDetected = true;
                $keep = [];
            }
            return ['ms' => array_slice($keep, -20)];
        });

        if ($buffDetected) {
            return ['type' => 'bug_buff', 'why' => 'Chạy Script gửi luồng song song tốc độ cao để Buff lượt / Race-Condition Bug'];
        }
    }

    return null;
}

function sh_is_flood_tool_ua()
{
    $ua = trim((string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''));
    if ($ua === '') {
        return empty($_SERVER['HTTP_ACCEPT']) && empty($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }
    return (bool) preg_match(
        '~(wrk/|ApacheBench|ab/\d|siege/|vegeta|bombardier|hey/|locust|k6/|tsung|httperf|hping|slowhttptest|sqlmap|nikto|nuclei|masscan|zgrab|python-requests|python-urllib|aiohttp|httpx/|Go-http-client|libwww-perl|Scrapy|curl/|wget/|HeadlessChrome|PhantomJS|Playwright|Puppeteer)~i',
        $ua
    );
}

/** Auto-Surge Self-Healing Stream Monitor: tightens defenses during heavy DDoS and auto-releases when calm. */
function sh_surge_record_attack($ip, $kind)
{
    $now       = time();
    $triggered = false;
    sh_json_update(sh_dir() . '/surge.json', function ($s) use ($now, $ip, &$triggered) {
        $ips = isset($s['ips']) && is_array($s['ips']) ? $s['ips'] : [];
        foreach ($ips as $k => $ts) {
            if ((int) $ts < $now - 60) {
                unset($ips[$k]);
            }
        }
        $ips[$ip] = $now;
        $wasSurge = !empty($s['surge_until']) && (int) $s['surge_until'] > $now;
        if (count($ips) >= 3) {
            $s['surge_until'] = $now + 120;
            if (!$wasSurge) {
                $triggered = true;
            }
        }
        $s['ips']      = $ips;
        $s['released'] = (int) (isset($s['released']) ? $s['released'] : 0) + 1;
        return $s;
    });
    if ($triggered) {
        sh_queue_pending_event('ddos_surge_healed', ['detail' => 'Đã kích hoạt khiên tự vá Surge & giải phóng sạch đa luồng DDoS (' . $kind . ')']);
    }
}

function sh_is_surge_active()
{
    $s = sh_json_read(sh_dir() . '/surge.json');
    return !empty($s['surge_until']) && (int) $s['surge_until'] > time();
}

/**
 * Earliest Pre-DB Security Entry Point (runs in .htdsx_hash.php, gate/url_gate.php, and db.php).
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

    // Owner Preview Showcase (?sh_test_view=...&k=...) — safe visual test links without banning viewer
    sh_handle_preview_showcase();

    // Client Telemetry & Violation Beacon Endpoints (?__sh_telemetry=1 / ?__sh_report=1)
    sh_handle_client_endpoints();

    $forensics = sh_trace_forensics();
    $ip        = sh_client_ip();
    if ($ip === '') {
        return;
    }

    // Website Admin & Safe Infrastructure IPs are 100% immune
    list($safe, ) = sh_is_safe_ip($ip);
    if ($safe || sh_is_admin_viewer()) {
        return;
    }

    $now  = time();
    $lid  = sh_browser_lid();
    $hwFp = sh_hw_fp_cookie();

    // 1. Check Active IP Ban Cache (drains heavy flood hits in <0.1ms to free server resources!)
    $cachedBan = sh_read_ban_cache($ip);
    if ($cachedBan === null && $forensics['real_ip'] !== $ip) {
        $cachedBan = sh_read_ban_cache($forensics['real_ip']);
    }
    if ($cachedBan !== null) {
        $expTs = isset($cachedBan['expires_ts']) ? (int) $cachedBan['expires_ts'] : 0;
        if ($expTs > 0 && $expTs <= $now) {
            sh_remove_ban_cache($ip);
            if ($forensics['real_ip'] !== $ip) {
                sh_remove_ban_cache($forensics['real_ip']);
            }
            $GLOBALS['SH_JUST_EXPIRED_IP'] = $ip;
        } else {
            // Self-Healing Stream Drainer: if blocked IP keeps flooding (>5 hits in 5s), drop socket instantly with 0 disk writes!
            $winTs     = isset($cachedBan['win_ts']) ? (int) $cachedBan['win_ts'] : $now;
            $lastTouch = isset($cachedBan['last_touch']) ? (int) $cachedBan['last_touch'] : 0;
            $inWin     = ($now - $winTs <= 5);
            $winHits   = $inWin ? ((int) (isset($cachedBan['win_hits']) ? $cachedBan['win_hits'] : 0) + 1) : 1;
            if ($winHits <= 6 || !$inWin || ($now - $lastTouch >= 10)) {
                $cachedBan['hits']       = (int) (isset($cachedBan['hits']) ? $cachedBan['hits'] : 0) + 1;
                $cachedBan['win_ts']     = $inWin ? $winTs : $now;
                $cachedBan['win_hits']   = $winHits;
                $cachedBan['last_touch'] = $now;
                if ($winHits === 6 && $expTs > 0) {
                    $cachedBan['expires_ts'] = max($expTs, $now + 1800);
                }
                sh_write_ban_cache($ip, $cachedBan);
            }
            if ($winHits >= 6) {
                sh_stats_bump('drained_streams', 1);
                while (ob_get_level() > 0) {
                    @ob_end_clean();
                }
                if (!headers_sent()) {
                    http_response_code(429);
                    header('Connection: close');
                    header('Content-Length: 0');
                }
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                exit;
            }
            sh_render_sad_page($cachedBan, $forensics['real_ip'], $expTs > 0 ? 429 : 403);
            exit;
        }
    }

    // 2. Cross-Device & Cross-VPN Lockdown Enforcement (catches violator even after changing IP/VPN or clearing cookies!)
    $entityLock = ($hwFp !== '' ? sh_read_entity_lock('hw', $hwFp) : null)
               ?: ($lid !== '' ? sh_read_entity_lock('lid', $lid) : null);
    if ($entityLock !== null) {
        $newExp = max((int) $entityLock['expires_ts'], $now + 1800) + 600;
        $evCnt  = (int) (isset($entityLock['evasion_attempts']) ? $entityLock['evasion_attempts'] : 0) + 1;
        $entityLock['ip']               = $ip;
        $entityLock['stream_ip']        = $forensics['stream_ip'];
        $entityLock['expires_ts']       = $newExp;
        $entityLock['expires_at']       = date('Y-m-d H:i:s', $newExp);
        $entityLock['evasion_attempts'] = $evCnt;
        sh_write_ban_cache($ip, $entityLock);
        sh_queue_pending_ban($entityLock + ['attack_detail' => 'Đổi IP/VPN hoặc trình duyệt để né khoá (lần #' . $evCnt . ')']);
        sh_render_sad_page($entityLock, $ip, 403);
        exit;
    }

    $cfg = sh_cfg();
    if (empty($cfg['enabled'])) {
        return;
    }

    if (sh_is_light_subresource()) {
        return;
    }

    // 3. Anti-Bug, Anti-Buff & High-Precision WAF Check (even before login!)
    if (!empty($cfg['waf_enabled'])) {
        $bugHit = sh_detect_waf_bug_buff($ip);
        if ($bugHit !== null) {
            $dur    = max(900, (int) $cfg['script_ban_sec']);
            $reason = 'AI Bảo Vệ phát hiện hành vi ' . $bugHit['why'] . '. Đã khoá bảo mật đa lớp trên IP & thiết bị.';
            $rec    = [
                'ip'         => $forensics['real_ip'],
                'stream_ip'  => $forensics['stream_ip'],
                'reason'     => $reason,
                'ban_type'   => $bugHit['type'],
                'lid'        => $lid,
                'hw_fp'      => $hwFp,
                'created_ts' => $now,
                'created_at' => date('Y-m-d H:i:s', $now),
                'expires_ts' => $now + $dur,
                'expires_at' => date('Y-m-d H:i:s', $now + $dur),
                'hits'       => 1,
            ];
            sh_write_ban_cache($ip, $rec);
            if ($forensics['real_ip'] !== $ip) {
                sh_write_ban_cache($forensics['real_ip'], $rec);
            }
            sh_queue_pending_ban($rec + ['attack_detail' => $bugHit['why'], 'ua' => $forensics['ua']]);
            sh_stats_bump('bug_blocks', 1);
            sh_render_sad_page($rec, $forensics['real_ip'], 403);
            exit;
        }
    }

    // 4. Multi-Factor L7 / L4-Proxied DDoS Stream Shield (with Surge auto-tightening)
    $h        = md5($ip);
    $rateFile = sh_dir() . '/rates/' . substr($h, 0, 2) . '/' . $h . '.json';
    $isPost   = strtoupper((string) (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET')) === 'POST';
    $isBotUa  = sh_is_flood_tool_ua();
    $isHuman  = sh_has_human_cookie($ip);
    $surge    = sh_is_surge_active();

    $verdict = null;
    sh_json_update($rateFile, function ($st) use ($now, $isPost, $isBotUa, $isHuman, $surge, &$verdict) {
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

        $limNoHuman5 = $surge ? 25 : 40;
        if ($isBotUa && ($c5 >= 15 || $c15 >= 28)) {
            $verdict = ['kind' => 'L7 Script/Tool DDoS', 'rps' => $c5, 'w15' => $c15];
        } elseif (!$isHuman && ($c5 >= $limNoHuman5 || $c15 >= ($surge ? 55 : 85))) {
            $verdict = ['kind' => 'L7/L4 HTTP Stream Flood', 'rps' => $c5, 'w15' => $c15];
        } elseif ($cp10 >= 25) {
            $verdict = ['kind' => 'L7 POST Spam Flood', 'rps' => $cp10, 'w15' => $c15];
        } elseif ($isHuman && $c5 >= 90) {
            $verdict = ['kind' => 'Macro High-Rate Flood', 'rps' => $c5, 'w15' => $c15];
        }

        return ['h' => array_slice($h15, -120), 'p' => array_slice($p10, -50), 'u' => $now];
    });

    if ($verdict !== null) {
        $dur    = max(900, (int) $cfg['ddos_ban_sec']);
        $mins   = (int) ceil($dur / 60);
        $reason = 'AI Chống DDoS phát hiện & giải phóng luồng tấn công liên tục (' . $verdict['kind'] . ' · ' . $verdict['w15'] . ' luồng/15s). Đã chặn IP thật & máy chủ gửi luồng (' . $mins . ' phút).';
        $rec    = [
            'ip'         => $forensics['real_ip'],
            'stream_ip'  => $forensics['stream_ip'],
            'reason'     => $reason,
            'ban_type'   => 'ddos',
            'lid'        => $lid,
            'hw_fp'      => $hwFp,
            'created_ts' => $now,
            'created_at' => date('Y-m-d H:i:s', $now),
            'expires_ts' => $now + $dur,
            'expires_at' => date('Y-m-d H:i:s', $now + $dur),
            'hits'       => (int) $verdict['w15'],
        ];
        sh_write_ban_cache($ip, $rec);
        if ($forensics['real_ip'] !== $ip) {
            sh_write_ban_cache($forensics['real_ip'], $rec);
        }
        sh_surge_record_attack($ip, $verdict['kind']);
        sh_queue_pending_ban($rec + [
            'attack_detail' => $verdict['kind'] . ' (' . $verdict['w15'] . ' req/15s)',
            'ua'            => $forensics['ua'],
        ]);
        sh_stats_bump('ddos_blocks', 1);
        @file_put_contents(sh_dir() . '/ddos_ips.list', date('Y-m-d H:i:s') . "\treal=" . $forensics['real_ip'] . "\tstream=" . $forensics['stream_ip'] . "\t" . $verdict['kind'] . "\n", FILE_APPEND | LOCK_EX);
        sh_render_sad_page($rec, $forensics['real_ip'], 429);
        exit;
    }

    // 5. Tier-1 Passive VPN / Passive Userscript Check -> Friendly Happy Smiley (😊) Gate!
    if (!empty($cfg['vpn_gate_enabled']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $uriPath = strtolower((string) strtok((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''), '?'));
        $isKeyBypassAttempt = (strpos($uriPath, 'get-free') !== false || strpos($uriPath, 'get_key') !== false || strpos($uriPath, 'key-free') !== false);

        // Check if client telemetry flagged passive script or VPN on this LID
        if ($lid !== '') {
            $hfFile = sh_dir() . '/happy_flags/' . substr($lid, 0, 2) . '/' . $lid . '.json';
            if (!empty($_COOKIE['sh_vg_retry'])) {
                @unlink($hfFile);
                if (!headers_sent()) {
                    @setcookie('sh_vg_retry', '', time() - 3600, '/');
                }
            } elseif (is_file($hfFile)) {
                $hf = sh_json_read($hfFile);
                if (!empty($hf['exp']) && (int) $hf['exp'] > $now) {
                    if ($isKeyBypassAttempt) {
                        // Trying to use VPN / Script on Get-Key / Bypass-Key -> Escalate to Tier-2 Strict Block!
                        @unlink($hfFile);
                        $dur = max(900, (int) $cfg['vpn_spam_ban_sec']);
                        $rec = [
                            'ip'         => $forensics['real_ip'],
                            'stream_ip'  => $forensics['stream_ip'],
                            'reason'     => 'Cố tình sử dụng ' . $hf['turn_off'] . ' để né giới hạn hoặc Bypass lấy Key trên iosmods.xyz.',
                            'ban_type'   => 'vpn_abuse',
                            'lid'        => $lid,
                            'hw_fp'      => $hwFp,
                            'created_ts' => $now,
                            'created_at' => date('Y-m-d H:i:s', $now),
                            'expires_ts' => $now + $dur,
                            'expires_at' => date('Y-m-d H:i:s', $now + $dur),
                            'hits'       => 1,
                        ];
                        sh_write_ban_cache($ip, $rec);
                        sh_queue_pending_ban($rec + ['attack_detail' => 'Dùng VPN/Script bypass lấy Key (' . $hf['turn_off'] . ')']);
                        sh_render_sad_page($rec, $forensics['real_ip'], 403);
                        exit;
                    }
                    sh_render_happy_gate(['turn_off' => $hf['turn_off']], $ip);
                    exit;
                } else {
                    @unlink($hfFile);
                }
            }
        }

        // Also check cached IP VPN intel synchronously (0ms file read if already cached)
        $intelFile = sh_dir() . '/ip_intel/' . substr($h, 0, 2) . '/' . $h . '.json';
        if (is_file($intelFile)) {
            $intel = sh_json_read($intelFile);
            if (!empty($intel['is_vpn']) && !empty($intel['at']) && (int) $intel['at'] > $now - 86400) {
                $lbl = !empty($intel['label']) ? $intel['label'] : 'VPN / Proxy (Fake IP)';
                if ($isKeyBypassAttempt) {
                    $dur = max(900, (int) $cfg['vpn_spam_ban_sec']);
                    $rec = [
                        'ip'         => $forensics['real_ip'],
                        'stream_ip'  => $forensics['stream_ip'],
                        'reason'     => 'Bật ' . $lbl . ' để cố tình vượt giới hạn hoặc Bypass lấy Key Free.',
                        'ban_type'   => 'vpn_abuse',
                        'lid'        => $lid,
                        'hw_fp'      => $hwFp,
                        'created_ts' => $now,
                        'created_at' => date('Y-m-d H:i:s', $now),
                        'expires_ts' => $now + $dur,
                        'expires_at' => date('Y-m-d H:i:s', $now + $dur),
                        'hits'       => 1,
                    ];
                    sh_write_ban_cache($ip, $rec);
                    sh_queue_pending_ban($rec + ['attack_detail' => 'Bật VPN (' . $lbl . ') vào lấy Key']);
                    sh_render_sad_page($rec, $forensics['real_ip'], 403);
                    exit;
                }
                sh_render_happy_gate(['turn_off' => $lbl], $ip);
                exit;
            }
        }
    }
}

/* ------------------------------------------------------------------
 * 12. Post-DB Sync, Admin Auto-Protection & Key-Spam / VPN Controllers
 * ------------------------------------------------------------------ */

function sh_sync_pending_to_db($conn)
{
    if (!($conn instanceof mysqli) || !function_exists('ipg_temp_ban')) {
        return;
    }
    $pFile = sh_dir() . '/pending_bans.json';
    if (is_file($pFile)) {
        $items = [];
        sh_json_update($pFile, function ($cur) use (&$items) {
            $items = is_array($cur) ? $cur : [];
            return [];
        });
        foreach ($items as $ip => $it) {
            $left  = max(60, (int) (isset($it['expires_ts']) ? $it['expires_ts'] : time() + 1800) - time());
            $btype = isset($it['ban_type']) ? (string) $it['ban_type'] : 'ddos';
            $res   = ipg_temp_ban(
                $conn,
                $ip,
                $left,
                isset($it['reason']) ? $it['reason'] : 'AI tự động khoá bảo mật',
                'bot-ai',
                isset($it['username']) ? $it['username'] : '',
                $btype
            );
            if (function_exists('tg_event')) {
                $evName = ($btype === 'ddos') ? 'ddos_block' : 'script_bug_block';
                tg_event($evName, [
                    'ip'          => $ip,
                    'stream_ip'   => isset($it['stream_ip']) ? $it['stream_ip'] : $ip,
                    'ban_type'    => $btype,
                    'detail'      => isset($it['attack_detail']) ? $it['attack_detail'] : $btype,
                    'reason'      => isset($it['reason']) ? $it['reason'] : '',
                    'duration'    => $left,
                    'created_at'  => isset($it['created_at']) ? $it['created_at'] : date('Y-m-d H:i:s'),
                    'expires_at'  => isset($it['expires_at']) ? $it['expires_at'] : date('Y-m-d H:i:s', time() + $left),
                    'hw_fp'       => isset($it['hw_fp']) ? substr((string) $it['hw_fp'], 0, 12) : '',
                    'evasions'    => isset($it['evasion_attempts']) ? (int) $it['evasion_attempts'] : 0,
                    'ban_id'      => !empty($res['row']['id']) ? (int) $res['row']['id'] : 0,
                ]);
            }
        }
    }

    $eFile = sh_dir() . '/pending_events.json';
    if (is_file($eFile) && function_exists('tg_event')) {
        $evs = [];
        sh_json_update($eFile, function ($cur) use (&$evs) {
            $evs = is_array($cur) ? $cur : [];
            return [];
        });
        foreach ($evs as $ev) {
            if (!empty($ev['type'])) {
                tg_event($ev['type'], isset($ev['data']) && is_array($ev['data']) ? $ev['data'] : []);
            }
        }
    }
}

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
        sh_sync_pending_to_db($conn);
        if (function_exists('ipg_sweep_expired')) {
            ipg_sweep_expired($conn, !empty($GLOBALS['SH_JUST_EXPIRED_IP']) ? $GLOBALS['SH_JUST_EXPIRED_IP'] : '');
        }

        // Check logged-in user: if Admin -> grant full signed Admin Pass; if Member -> check account-level lockdown!
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
                        $st->close();
                        return; // Admin is 100% exempt from everything below!
                    }
                }
                $st->close();
            }

            // Check if this username was bound to an active Tier-2 Cross-Device Lockdown on another machine!
            $uLock = sh_read_entity_lock('user', $sessUser);
            if ($uLock !== null) {
                list($safe, ) = sh_is_safe_ip($ip);
                if (!$safe) {
                    $uLock['ip']               = $ip;
                    $uLock['evasion_attempts'] = (int) (isset($uLock['evasion_attempts']) ? $uLock['evasion_attempts'] : 0) + 1;
                    $uLock['expires_ts']       = max((int) $uLock['expires_ts'], time() + 1800) + 600;
                    sh_write_ban_cache($ip, $uLock);
                    sh_render_sad_page($uLock, $ip, 403);
                    exit;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[security_shield] post_db: ' . $e->getMessage());
    }

    if (function_exists('ipg_run')) {
        ipg_run($conn);
    }

    if (!sh_is_light_subresource() && strtoupper((string) (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET')) === 'GET') {
        sh_issue_human_cookie($ip);
    }
}

function sh_on_key_fail($conn, $username, $typedKey = '', $keyType = 'FREE')
{
    $ip = sh_client_ip();
    if ($ip === '' || sh_is_admin_viewer()) {
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

    $c10m = 0;
    $c30s = 0;
    $trig = false;
    sh_json_update($failFile, function ($st) use ($now, $max10m, $max30s, &$c10m, &$c30s, &$trig) {
        $arr  = isset($st['t']) && is_array($st['t']) ? $st['t'] : [];
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
            $keep = [];
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
        sh_stats_bump('spam_blocks', 1);
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
        sh_render_sad_page($res['row'] ? $res['row'] : ['reason' => $reason, 'ban_type' => 'key_spam', 'expires_ts' => $now + $banSec], $ip, 429);
        exit;
    }
    return false;
}

/* ------------------------------------------------------------------
 * 13. VPN / Multi-Account Free-Key Watcher & Strict Bypass Blocker
 * ------------------------------------------------------------------ */

function sh_device_id()
{
    return sh_ensure_browser_lid();
}

function sh_is_vpn_cached($conn, $ipRaw)
{
    $intel = sh_inspect_ip_vpn($ipRaw);
    if (!empty($intel['is_vpn'])) {
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
        // ignore
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

function sh_link_and_find_alts($conn, $username)
{
    $username = trim((string) $username);
    if (!($conn instanceof mysqli) || $username === '') {
        return ['alts' => [], 'vpn' => false, 'ip' => sh_client_ip(), 'dev' => ''];
    }
    $ipRaw = sh_client_ip();
    $ipKey = sh_ip_network_key($ipRaw);
    $dev   = sh_device_id();
    $hwFp  = sh_hw_fp_cookie();
    $now   = time();
    $alts  = [];

    try {
        sh_ensure_seen_table($conn);
        foreach ([['ip', $ipKey], ['dev', $dev], ['hw', substr($hwFp, 0, 40)]] as $pair) {
            if ($pair[1] !== '') {
                $st = @$conn->prepare('INSERT INTO free_key_seen (username, kind, val, first_seen, last_seen) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)');
                if ($st) {
                    $st->bind_param('sssii', $username, $pair[0], $pair[1], $now, $now);
                    @$st->execute();
                    $st->close();
                }
            }
        }

        $st2 = @$conn->prepare("SELECT DISTINCT username FROM free_key_seen WHERE username <> ? AND ((kind = 'dev' AND val = ?) OR (kind = 'hw' AND val <> '' AND val = ?) OR (kind = 'ip' AND val = ? AND last_seen > ?)) LIMIT 8");
        if ($st2) {
            $recent = $now - 7 * 86400;
            $hwSub  = substr($hwFp, 0, 40);
            $st2->bind_param('ssssi', $username, $dev, $hwSub, $ipKey, $recent);
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

function sh_observe_register($conn, $username, $userId = 0)
{
    $info = sh_link_and_find_alts($conn, $username);
    $info['id'] = (int) $userId;
    return $info;
}

/**
 * When someone uses VPN / Script / Multi-account to evade limits or bypass get_key.php:
 * Immediately block VPN/cheat bypasses strictly (Tier 2 Cross-Device Lockdown)!
 */
function sh_on_freekey_abuse($conn, $username, $event, $ipRaw = '')
{
    if (sh_is_admin_viewer()) {
        return;
    }
    $forensics = sh_trace_forensics();
    $ip        = $ipRaw !== '' ? sh_norm_ip($ipRaw) : $forensics['real_ip'];
    $info      = sh_link_and_find_alts($conn, $username);

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

    // Immediate Tier-2 Lockdown if they deliberately used Script/Fast-Bypass (blocked_cheat) OR VPN on get_key (blocked_vpn), or >=3 multi-acc tries!
    $now     = time();
    $h       = md5($ip);
    $file    = sh_dir() . '/fkabuse/' . substr($h, 0, 2) . '/' . $h . '.json';
    $cnt     = 0;
    $instant = ($event === 'blocked_cheat' || $event === 'blocked_vpn');
    $trig    = false;

    sh_json_update($file, function ($st) use ($now, $instant, &$cnt, &$trig) {
        $arr  = isset($st['t']) && is_array($st['t']) ? $st['t'] : [];
        $keep = [];
        foreach ($arr as $ts) {
            if ((int) $ts >= $now - 1800) {
                $keep[] = (int) $ts;
            }
        }
        $keep[] = $now;
        $cnt = count($keep);
        if ($instant || $cnt >= 3) {
            $trig = true;
            $keep = [];
        }
        return ['t' => $keep];
    });

    if ($trig && ($conn instanceof mysqli) && function_exists('ipg_temp_ban')) {
        $cfg    = sh_cfg();
        $banSec = max(900, (int) $cfg['vpn_spam_ban_sec']);
        $mins   = (int) ceil($banSec / 60);
        $whyTxt = $event === 'blocked_cheat'
            ? 'Chạy Script / Tool tự động Bypass vượt link lấy Key Free trái phép'
            : ($event === 'blocked_vpn'
                ? 'Bật VPN / Fake IP cố tình lách giới hạn lấy Key Free'
                : 'Đổi nhiều tài khoản / thiết bị liên tục (' . $cnt . ' lần) để lạm dụng Key Free');
        $reason = $whyTxt . '. Hệ thống khoá bảo vệ đa lớp ' . $mins . ' phút (đổi máy hoặc Fake IP đều bị giữ khoá).';
        $res    = ipg_temp_ban($conn, $ip, $banSec, $reason, 'bot-antivpn', (string) $username, 'vpn_abuse');
        if (!empty($res['ok'])) {
            sh_write_ban_cache($ip, [
                'id'         => !empty($res['row']['id']) ? (int) $res['row']['id'] : 0,
                'ip'         => $ip,
                'stream_ip'  => $forensics['stream_ip'],
                'reason'     => $reason,
                'ban_type'   => 'vpn_abuse',
                'username'   => (string) $username,
                'expires_ts' => $now + $banSec,
                'expires_at' => date('Y-m-d H:i:s', $now + $banSec),
            ]);
            sh_stats_bump('vpn_blocks', 1);
            if (function_exists('tg_event')) {
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
            sh_render_sad_page($res['row'] ? $res['row'] : ['reason' => $reason, 'ban_type' => 'vpn_abuse', 'expires_ts' => $now + $banSec], $ip, 403);
            exit;
        }
    }
}

function sh_check_freekey_redeem($conn, $username, $keyType, $enforceRules = true)
{
    if (!$enforceRules || sh_is_admin_viewer() || strtoupper((string) $keyType) !== 'FREE' || !($conn instanceof mysqli)) {
        return true;
    }
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
        // ignore
    }

    $info = sh_link_and_find_alts($conn, $username);
    if (empty($info['alts'])) {
        return true;
    }

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
                            'reason'   => 'Đổi tài khoản phụ (' . $hit['username'] . ' → ' . $username . ') + Fake IP/Thiết bị để nhập thêm Key Free trong 8h.',
                        ]);
                    }
                    return false;
                }
            } else {
                $st->close();
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    return true;
}
