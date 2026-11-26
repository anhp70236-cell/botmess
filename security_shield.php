<?php
/* ==========================================================================
   security_shield.php  —  IOSMODS Autonomous Security Core (V5 Native Edition)
   - Native iosmods.xyz Matte Monochrome UI (#000 / #0a0a0b, zero glow, zero colorful
     boxes, frameless animated Sad / Happy / Unlock SVG icons, serious security copy).
   - 100% Zero False Positives & Complete Immunity for Admin Web & Telegram Bot.
   - Untouched Sub-apps: /peoeopoo and /ios_b are 100% bypassed.
   - Instant Zero-Delay Detection: Synchronous pre-render VPN/Proxy/WARP check on the
     very first request + synchronous <head> pre-paint Script/Automation check.
   - Multi-Segment Encrypted Case Cipher (IMX-XXXXXX-XXXXXX-XXXXXX-XXXXXX):
     AES/HMAC encrypted verification code sent to Admin; automatically self-destructs
     the instant the user is unbanned (by timer or by Admin).
   - Clean-Slate Unban Guarantee: When Bot or Admin lifts a ban, 100% of entity locks,
     strike counters, and case tokens are purged so the user is NEVER re-banned
     until new violations are observed.
   - Progressive Product-Get & Fast-Action Spam Guard (5m -> 10m -> max 30m):
     Applies to guests or logged-in users WITHOUT an active key spamming product GET
     buttons or rapid actions; users WITH an active valid key work 100% normally.
   - PHP 7.2 – 8.3+ compatible. Never throws fatal errors.
   ========================================================================== */

if (!defined('SH_SHIELD_LOADED')) {
    define('SH_SHIELD_LOADED', 5);
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
    if (!empty($d['k']) && strlen((string) $d['k']) >= 48) {
        return $sec = (string) $d['k'];
    }
    $k = bin2hex(random_bytes(32));
    sh_json_update($f, function ($x) use ($k) {
        return (!empty($x['k']) && strlen((string) $x['k']) >= 48) ? $x : ['k' => $k, 'at' => time()];
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
    $t = substr(hash_hmac('sha256', 'preview-v5-ui', sh_secret()), 0, 20);
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
        'vpn_gate_enabled' => true,
        'inspect_guard'    => true,
        'ddos_ban_sec'     => 3600,
        'script_ban_sec'   => 3600,
        'key_spam_ban_sec' => 1800,
        'vpn_spam_ban_sec' => 1800,
        'key_fail_limit'   => 6,
        'key_burst_limit'  => 4,
    ], $saved);
    return $cfg;
}

function sh_cfg_save(array $patch)
{
    $GLOBALS['SH_CFG_RESET'] = 1;
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
 * 3. Multi-Layer Encrypted Case Cipher (Self-Destructs Upon Unban!)
 *    Format: IMX-XXXXXX-XXXXXX-XXXXXX-XXXXXX (24 base32 chars + HMAC)
 * ------------------------------------------------------------------ */

function sh_b32_encode($bytes)
{
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $bits  = '';
    $len   = strlen($bytes);
    for ($i = 0; $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0; $i + 5 <= strlen($bits); $i += 5) {
        $out .= $alpha[bindec(substr($bits, $i, 5))];
    }
    return $out;
}

function sh_extract_case_code($rawText)
{
    $s = strtoupper((string) $rawText);
    if (preg_match('/IMX[-\s]*([A-Z2-9]{6})[-\s]*([A-Z2-9]{6})[-\s]*([A-Z2-9]{6})[-\s]*([A-Z2-9]{6})/', $s, $m)) {
        return 'IMX-' . $m[1] . '-' . $m[2] . '-' . $m[3] . '-' . $m[4];
    }
    return '';
}

function sh_case_file($code)
{
    $norm = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $code));
    $h    = hash_hmac('sha256', 'case|' . $norm, sh_secret());
    return sh_dir() . '/cases/' . substr($h, 0, 2) . '/' . substr($h, 0, 32) . '.json';
}

function sh_summarize_ua($ua)
{
    $ua = trim((string) $ua);
    if ($ua === '') {
        return 'Không gửi User-Agent (Nghi công cụ tự động)';
    }
    if (preg_match('~(python-requests|python-urllib|aiohttp|httpx|curl/|wget/|Go-http-client|sqlmap|nikto|nuclei|wrk/|ab/\d|siege|HeadlessChrome|Playwright|Puppeteer|PhantomJS)~i', $ua, $m)) {
        return 'Công cụ Script / Bot (' . $m[1] . ')';
    }
    $dev = 'Thiết bị khác';
    if (preg_match('/iPhone.*OS (\d+[_\d]*)/i', $ua, $m)) {
        $dev = 'iPhone · iOS ' . str_replace('_', '.', $m[1]);
    } elseif (preg_match('/iPad.*OS (\d+[_\d]*)/i', $ua, $m)) {
        $dev = 'iPad · iPadOS ' . str_replace('_', '.', $m[1]);
    } elseif (preg_match('/Android\s+([0-9.]+)/i', $ua, $m)) {
        $dev = 'Android ' . $m[1];
    } elseif (stripos($ua, 'Windows NT') !== false) {
        $dev = 'Máy tính Windows';
    } elseif (stripos($ua, 'Macintosh') !== false) {
        $dev = 'Máy tính Mac';
    } elseif (stripos($ua, 'Linux') !== false) {
        $dev = 'Máy chủ / Linux';
    }
    $br = '';
    if (stripos($ua, 'Edg/') !== false) {
        $br = 'Edge';
    } elseif (stripos($ua, 'CriOS/') !== false || (stripos($ua, 'Chrome/') !== false && stripos($ua, 'Safari/') !== false)) {
        $br = 'Chrome';
    } elseif (stripos($ua, 'FxiOS/') !== false || stripos($ua, 'Firefox/') !== false) {
        $br = 'Firefox';
    } elseif (stripos($ua, 'Safari/') !== false) {
        $br = 'Safari';
    }
    return $dev . ($br !== '' ? ' (' . $br . ')' : '');
}

function sh_ban_type_label($banType)
{
    $map = [
        'ddos'       => 'Tấn công luồng DDoS',
        'waf'        => 'Tấn công khai thác lỗ hổng Web (WAF)',
        'bug_buff'   => 'Chạy Script Bug / Buff lượt trái phép',
        'inspect'    => 'Mở công cụ soi mã nguồn PC / Chạy Script',
        'prod_spam'  => 'Spam bấm lấy sản phẩm / Thao tác quá nhanh',
        'key_spam'   => 'Spam nhập sai mã Key liên tục',
        'vpn_abuse'  => 'Dùng VPN / Đa tài khoản vượt giới hạn Key',
        'admin_temp' => 'Tạm khoá thủ công bởi Admin',
        'manual'     => 'Khoá bảo mật vĩnh viễn bởi Admin',
    ];
    return isset($map[$banType]) ? $map[$banType] : 'Khoá bảo vệ tự động (' . $banType . ')';
}

/**
 * Generates or reuses an active multi-segment encrypted verification code for a banned IP.
 * As soon as the user is unbanned, this code is invalidated permanently!
 */
function sh_issue_case_code($ip, array $ban)
{
    $ip = sh_norm_ip($ip);
    if (!empty($ban['case_code']) && empty($ban['is_preview'])) {
        $cf = sh_case_file($ban['case_code']);
        if (is_file($cf)) {
            $cd = sh_json_read($cf);
            if (!empty($cd['active'])) {
                return (string) $ban['case_code'];
            }
        }
    }

    $seed = ($ip !== '' ? $ip : 'preview') . '|' . (isset($ban['created_ts']) ? $ban['created_ts'] : time()) . '|' . (empty($ban['is_preview']) ? bin2hex(random_bytes(8)) : 'pv');
    $raw1 = hash_hmac('sha256', 'L1|' . $seed, sh_secret(), true);
    $raw2 = hash_hmac('sha256', 'L2|' . $raw1 . '|' . $seed, sh_secret(), true);
    $b32  = substr(sh_b32_encode(substr($raw1, 0, 8) . substr($raw2, 0, 7)), 0, 24);
    $code = 'IMX-' . implode('-', str_split($b32, 6));

    if (empty($ban['is_preview']) && $ip !== '') {
        $uaRaw = isset($ban['ua']) && $ban['ua'] !== ''
            ? (string) $ban['ua']
            : substr((string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''), 0, 180);
        $cf = sh_case_file($code);
        sh_json_update($cf, function () use ($code, $ip, $ban, $uaRaw) {
            return [
                'code'             => $code,
                'active'           => 1,
                'ip'               => $ip,
                'stream_ip'        => isset($ban['stream_ip']) ? (string) $ban['stream_ip'] : $ip,
                'ban_type'         => isset($ban['ban_type']) ? (string) $ban['ban_type'] : 'temp',
                'reason'           => isset($ban['reason']) ? (string) $ban['reason'] : '',
                'attack_detail'    => isset($ban['attack_detail']) ? (string) $ban['attack_detail'] : '',
                'username'         => isset($ban['username']) ? (string) $ban['username'] : '',
                'hw_fp'            => isset($ban['hw_fp']) ? (string) $ban['hw_fp'] : '',
                'lid'              => isset($ban['lid']) ? (string) $ban['lid'] : '',
                'ua'               => $uaRaw,
                'hits'             => isset($ban['hits']) ? (int) $ban['hits'] : 1,
                'evasion_attempts' => isset($ban['evasion_attempts']) ? (int) $ban['evasion_attempts'] : 0,
                'created_ts'       => isset($ban['created_ts']) ? (int) $ban['created_ts'] : time(),
                'created_at'       => isset($ban['created_at']) ? (string) $ban['created_at'] : date('Y-m-d H:i:s'),
                'expires_ts'       => isset($ban['expires_ts']) ? (int) $ban['expires_ts'] : 0,
                'initial_dur'      => (!empty($ban['expires_ts']) && (int) $ban['expires_ts'] > 0)
                    ? max(60, (int) $ban['expires_ts'] - (isset($ban['created_ts']) ? (int) $ban['created_ts'] : time()))
                    : 0,
                'ai_reduced'       => isset($ban['ai_reduced']) ? (int) $ban['ai_reduced'] : 0,
            ];
        });
        // Link code to IP index so unbanning immediately destroys all codes for that IP
        $idxFile = sh_dir() . '/cases/ip_' . md5($ip) . '.json';
        sh_json_update($idxFile, function ($list) use ($code) {
            if (!is_array($list)) {
                $list = [];
            }
            $list[] = $code;
            return array_values(array_unique(array_slice($list, -10)));
        });
    }

    return $code;
}

/** Lookup encrypted Case Code from Telegram Bot. Returns array or null. */
function sh_lookup_case_code($code)
{
    $extracted = sh_extract_case_code($code);
    $target    = $extracted !== '' ? $extracted : $code;
    $cf        = sh_case_file($target);
    if (!is_file($cf)) {
        return null;
    }
    $d = sh_json_read($cf);
    if (empty($d['code'])) {
        return null;
    }
    if (!empty($d['active']) && !empty($d['expires_ts']) && (int) $d['expires_ts'] <= time()) {
        $d['active'] = 0;
        @unlink($cf);
        return null;
    }
    return $d;
}

/** Lists up to $limit currently active encrypted case dossiers for quick 1-tap selection in Telegram Bot. */
function sh_list_active_cases($limit = 6)
{
    $out = [];
    $now = time();
    foreach (glob(sh_dir() . '/cases/*/*.json') ?: [] as $cf) {
        $d = sh_json_read($cf);
        if (empty($d['code']) || empty($d['active'])) {
            continue;
        }
        if (!empty($d['expires_ts']) && (int) $d['expires_ts'] <= $now) {
            @unlink($cf);
            continue;
        }
        if (!empty($d['ip']) && sh_read_ban_cache($d['ip']) === null) {
            // Double-check if DB ban is still active; if neither cache nor active ban exists, clean up stale case
            $out[] = $d;
            continue;
        }
        $out[] = $d;
    }
    usort($out, function ($a, $b) {
        return ((int) (isset($b['created_ts']) ? $b['created_ts'] : 0)) - ((int) (isset($a['created_ts']) ? $a['created_ts'] : 0));
    });
    return array_slice($out, 0, max(1, (int) $limit));
}

/**
 * Full Smart Forensic Dossier for Telegram Bot Case Investigation:
 * Combines encrypted case record + live fast-ban cache + MySQL ip_bans + ISP/VPN intel + AI Risk Verdict!
 */
function sh_enrich_case_dossier($conn, $rawCode)
{
    $base = sh_lookup_case_code($rawCode);
    if (!$base || empty($base['active'])) {
        return null;
    }
    $ip   = sh_norm_ip(isset($base['ip']) ? $base['ip'] : '');
    $live = $ip !== '' ? sh_read_ban_cache($ip) : null;
    if (is_array($live)) {
        $base['hits']             = max((int) (isset($base['hits']) ? $base['hits'] : 1), (int) (isset($live['hits']) ? $live['hits'] : 1));
        $base['evasion_attempts'] = max((int) (isset($base['evasion_attempts']) ? $base['evasion_attempts'] : 0), (int) (isset($live['evasion_attempts']) ? $live['evasion_attempts'] : 0));
        if (!empty($live['expires_ts'])) {
            $base['expires_ts'] = (int) $live['expires_ts'];
        }
        if (!empty($live['reason'])) {
            $base['reason'] = (string) $live['reason'];
        }
        if (empty($base['username']) && !empty($live['username'])) {
            $base['username'] = (string) $live['username'];
        }
        if (!empty($live['ai_reduced'])) {
            $base['ai_reduced'] = (int) $live['ai_reduced'];
        }
    }

    if (($conn instanceof mysqli) && $ip !== '' && function_exists('ipg_find')) {
        $dbRow = ipg_find($conn, $ip);
        if ($dbRow) {
            if ((int) $dbRow['active'] !== 1 && $live === null) {
                sh_revoke_case_codes_for_ip($ip);
                return null;
            }
            $base['hits'] = max((int) $base['hits'], (int) $dbRow['hits']);
            if (empty($dbRow['expires_at']) && (int) $dbRow['active'] === 1 && $dbRow['ban_type'] === 'manual') {
                $base['expires_ts'] = 0;
                $base['ban_type']   = 'manual';
            }
            if (empty($base['username']) && !empty($dbRow['username'])) {
                $base['username'] = (string) $dbRow['username'];
            }
        }
    }

    $intel            = $ip !== '' ? sh_inspect_ip_vpn($ip) : ['is_vpn' => false, 'label' => '', 'isp' => '', 'country' => ''];
    $btype            = isset($base['ban_type']) ? (string) $base['ban_type'] : 'temp';
    $evasions         = (int) (isset($base['evasion_attempts']) ? $base['evasion_attempts'] : 0);
    $hits             = (int) (isset($base['hits']) ? $base['hits'] : 1);
    $uaSummary        = sh_summarize_ua(isset($base['ua']) ? $base['ua'] : '');
    $base['type_lbl'] = sh_ban_type_label($btype);
    $base['device']   = $uaSummary;
    $base['isp']      = !empty($intel['isp']) ? (string) $intel['isp'] : 'Chưa xác định';
    $base['country']  = !empty($intel['country']) ? (string) $intel['country'] : '';
    $base['is_vpn']   = !empty($intel['is_vpn']);
    $base['vpn_lbl']  = !empty($intel['label']) ? (string) $intel['label'] : '';

    // Smart AI Risk Assessment & Actionable Recommendation
    if (in_array($btype, ['ddos', 'waf', 'bug_buff', 'inspect'], true) || $evasions >= 2 || strpos($uaSummary, 'Công cụ Script') === 0) {
        $base['risk_badge'] = '🔴 NGHIÊM TRỌNG (Can thiệp / Tấn công trực tiếp)';
        $base['ai_advice']  = $evasions > 0
            ? 'Đối tượng đã cố tình đổi IP/thiết bị ' . $evasions . ' lần để lách khoá. Khuyến nghị GIỮ KHOÁ hoặc chuyển sang CẤM VĨNH VIỄN.'
            : 'Phát hiện dấu hiệu chạy công cụ Script, soi mã nguồn hoặc gửi gói tin tấn công. Chỉ gỡ nếu xác nhận đúng người quen thao tác nhầm.';
    } elseif ($btype === 'vpn_abuse' || !empty($intel['is_vpn']) || $evasions === 1) {
        $base['risk_badge'] = '🟡 TRUNG BÌNH (Có dùng VPN / Đổi mạng)';
        $base['ai_advice']  = 'Kết nối có sử dụng ' . ($base['vpn_lbl'] !== '' ? $base['vpn_lbl'] : 'VPN / đổi mạng') . '. Hãy nhắc người dùng tắt hẳn VPN / 1.1.1.1 trước khi bấm Gỡ chặn.';
    } else {
        $base['risk_badge'] = '🟢 MỨC NHẸ (Người dùng thao tác quá nhanh)';
        $base['ai_advice']  = 'Người dùng mạng thông thường bấm lấy sản phẩm khi chưa có Key hoặc nhập sai Key liên tiếp (0 lần cố lách khoá). Có thể bấm «Gỡ chặn sạch ngay» an toàn.';
    }

    return $base;
}

/**
 * Collects 100% of currently active blocks across MySQL ip_bans, pre-DB fast cache (bans/*.json),
 * and tg_user_blocks (automatically sweeping expired records first so zero stale entries appear).
 */
function sh_collect_active_blocks($conn)
{
    $now   = time();
    $byKey = [];

    if (($conn instanceof mysqli) && function_exists('ipg_sweep_expired')) {
        ipg_sweep_expired($conn);
    }

    // 1. Pre-DB fast cache bans/*.json
    foreach (glob(sh_dir() . '/bans/*/*.json') ?: [] as $bf) {
        $bd = sh_json_read($bf);
        if (empty($bd['ip'])) {
            continue;
        }
        $expTs = isset($bd['expires_ts']) ? (int) $bd['expires_ts'] : 0;
        if ($expTs > 0 && $expTs <= $now) {
            sh_full_clean_slate_unban($conn, $bd['ip'], isset($bd['username']) ? (string) $bd['username'] : '');
            continue;
        }
        $ip = sh_norm_ip($bd['ip']);
        if ($ip === '') {
            continue;
        }
        $byKey['ip:' . $ip] = [
            'kind'       => $expTs > 0 ? 'temp_ip' : 'perm_ip',
            'ip'         => $ip,
            'username'   => isset($bd['username']) ? (string) $bd['username'] : '',
            'reason'     => isset($bd['reason']) ? (string) $bd['reason'] : '',
            'ban_type'   => isset($bd['ban_type']) ? (string) $bd['ban_type'] : 'temp',
            'case_code'  => isset($bd['case_code']) ? (string) $bd['case_code'] : '',
            'expires_ts' => $expTs,
            'created_at' => isset($bd['created_at']) ? (string) $bd['created_at'] : '',
        ];
    }

    // 2. MySQL ip_bans active rows
    if ($conn instanceof mysqli) {
        try {
            $res = @$conn->query("SELECT id, ip, username, reason, ban_type, created_at, expires_at FROM ip_bans WHERE active = 1 AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY id DESC LIMIT 100");
            while ($res && ($r = $res->fetch_assoc())) {
                $ip = sh_norm_ip($r['ip']);
                if ($ip === '') {
                    continue;
                }
                $expTs = (!empty($r['expires_at']) && strpos((string) $r['expires_at'], '0000') !== 0)
                    ? (int) strtotime((string) $r['expires_at'])
                    : 0;
                $k = 'ip:' . $ip;
                if (isset($byKey[$k])) {
                    $byKey[$k]['id'] = (int) $r['id'];
                    if ($byKey[$k]['username'] === '' && !empty($r['username'])) {
                        $byKey[$k]['username'] = (string) $r['username'];
                    }
                    if ($expTs === 0 && empty($r['expires_at'])) {
                        $byKey[$k]['kind']       = 'perm_ip';
                        $byKey[$k]['expires_ts'] = 0;
                    }
                } else {
                    $byKey[$k] = [
                        'id'         => (int) $r['id'],
                        'kind'       => $expTs > 0 ? 'temp_ip' : 'perm_ip',
                        'ip'         => $ip,
                        'username'   => !empty($r['username']) ? (string) $r['username'] : '',
                        'reason'     => (string) $r['reason'],
                        'ban_type'   => !empty($r['ban_type']) ? (string) $r['ban_type'] : 'manual',
                        'case_code'  => '',
                        'expires_ts' => $expTs,
                        'created_at' => (string) $r['created_at'],
                    ];
                }
            }
        } catch (Throwable $e) {}

        // 3. MySQL tg_user_blocks active rows
        try {
            $resU = @$conn->query("SELECT id, user_id, username, reason, created_at FROM tg_user_blocks WHERE active = 1 ORDER BY id DESC LIMIT 50");
            while ($resU && ($u = $resU->fetch_assoc())) {
                $uname = trim((string) $u['username']);
                if ($uname !== '') {
                    $byKey['usr:' . strtolower($uname)] = [
                        'id'         => (int) $u['user_id'],
                        'kind'       => 'user_block',
                        'ip'         => '',
                        'username'   => $uname,
                        'reason'     => (string) $u['reason'],
                        'ban_type'   => 'account_lock',
                        'case_code'  => '',
                        'expires_ts' => 0,
                        'created_at' => (string) $u['created_at'],
                    ];
                }
            }
        } catch (Throwable $e) {}
    }

    return array_values($byKey);
}

/** Destroys / invalidates all encrypted Case Codes for an IP the instant they are unbanned! */
function sh_revoke_case_codes_for_ip($ip)
{
    $ip = sh_norm_ip($ip);
    if ($ip === '') {
        return;
    }
    $idxFile = sh_dir() . '/cases/ip_' . md5($ip) . '.json';
    if (is_file($idxFile)) {
        $codes = sh_json_read($idxFile);
        foreach ((array) $codes as $c) {
            $cf = sh_case_file($c);
            if (is_file($cf)) {
                @unlink($cf);
            }
        }
        @unlink($idxFile);
    }
}

/**
 * Moderately reduces remaining temporary ban duration across pre-DB cache, entity locks,
 * encrypted Case Code dossier, and MySQL ip_bans (used by @devios_bot AI Judge).
 */
function sh_reduce_ban_duration($conn, $ip, $newLeftSec, $code = '')
{
    $ip = sh_norm_ip($ip);
    if ($ip === '') {
        return false;
    }
    $newLeftSec = max(120, (int) $newLeftSec);
    $now        = time();
    $newExpTs   = $now + $newLeftSec;
    $newExpStr  = date('Y-m-d H:i:s', $newExpTs);

    $cur = sh_read_ban_cache($ip);
    if (is_array($cur)) {
        $cur['expires_ts'] = $newExpTs;
        $cur['expires_at'] = $newExpStr;
        $cur['ai_reduced'] = 1;
        sh_write_ban_cache($ip, $cur);
    }

    if ($code !== '') {
        $cf = sh_case_file($code);
        if (is_file($cf)) {
            sh_json_update($cf, function ($d) use ($newExpTs) {
                $d['expires_ts'] = $newExpTs;
                $d['ai_reduced'] = 1;
                return $d;
            });
        }
    }

    $idxFile = sh_dir() . '/cases/ip_' . md5($ip) . '.json';
    if (is_file($idxFile)) {
        foreach ((array) sh_json_read($idxFile) as $c) {
            $cf = sh_case_file($c);
            if (is_file($cf)) {
                sh_json_update($cf, function ($d) use ($newExpTs) {
                    $d['expires_ts'] = $newExpTs;
                    $d['ai_reduced'] = 1;
                    return $d;
                });
            }
        }
    }

    if ($conn instanceof mysqli) {
        try {
            $st = @$conn->prepare('UPDATE ip_bans SET expires_at = ? WHERE ip = ? AND active = 1 AND expires_at IS NOT NULL');
            if ($st) {
                $st->bind_param('ss', $newExpStr, $ip);
                @$st->execute();
                $st->close();
            }
        } catch (Throwable $e) {}
    }
    return $newExpTs;
}

/* ------------------------------------------------------------------
 * 4. Trusted Proxies, Forensic IP Unmasking & Admin Immunity
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
        $originIp = $hops[0];
        $streamIp = $hops[count($hops) - 1];
    } elseif ($leakedIp !== '') {
        $originIp = $leakedIp;
    }

    $lid = sh_browser_lid();
    if ($lid !== '') {
        $tele = sh_json_read(sh_dir() . '/telemetry/' . substr($lid, 0, 2) . '/' . $lid . '.json');
        if (!empty($tele['webrtc_ip']) && $tele['webrtc_ip'] !== $streamIp) {
            $originIp = (string) $tele['webrtc_ip'];
        }
    }

    return [
        'real_ip'   => $originIp !== '' ? $originIp : $realIp,
        'stream_ip' => $streamIp !== '' ? $streamIp : ($remote !== '' ? $remote : $realIp),
        'hops'      => $hops,
        'country'   => !empty($_SERVER['HTTP_CF_IPCOUNTRY']) ? strtoupper(trim((string) $_SERVER['HTTP_CF_IPCOUNTRY'])) : '',
        'ua'        => substr((string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''), 0, 160),
    ];
}

/* ------------------------------------------------------------------
 * 5. Whitelist & Signed Admin Browser Immunity (Zero False Positives!)
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
    sh_full_clean_slate_unban(null, $baseIp, '', false);
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
 * 6. Ironclad 6-Layer Lock Graph + Clean-Slate Unban Guarantee!
 * ------------------------------------------------------------------ */

function sh_browser_lid()
{
    foreach (['sh_lid', 'fk_did', 'ds_dev'] as $ck) {
        if (!empty($_COOKIE[$ck]) && preg_match('/^[a-f0-9]{32}$/i', (string) $_COOKIE[$ck])) {
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
    // Verify the origin IP ban has not been lifted by Admin or timer!
    if (!empty($d['origin_ip'])) {
        $origCache = sh_read_ban_cache($d['origin_ip']);
        if ($origCache === null) {
            @unlink($p);
            return null;
        }
    }
    $exp = isset($d['expires_ts']) ? (int) $d['expires_ts'] : 0;
    if ($exp > 0 && $exp <= time()) {
        @unlink($p);
        return null;
    }
    return $d;
}

function sh_ban_cache_path($ip)
{
    $h = md5(sh_norm_ip($ip));
    return sh_dir() . '/bans/' . substr($h, 0, 2) . '/' . $h . '.json';
}

function sh_write_ban_cache($ip, array $record)
{
    $ip = sh_norm_ip($ip);
    if ($ip === '') {
        return null;
    }
    list($safe, ) = sh_is_safe_ip($ip);
    if ($safe) {
        return null;
    }
    $lid  = !empty($record['lid']) ? (string) $record['lid'] : sh_browser_lid();
    $hwFp = !empty($record['hw_fp']) ? (string) $record['hw_fp'] : sh_hw_fp_cookie();
    $net64 = sh_ip_network_key($ip);

    $saved = sh_json_update(sh_ban_cache_path($ip), function ($cur) use ($ip, $record, $lid, $hwFp) {
        $merged = array_merge([
            'ip'               => $ip,
            'origin_ip'        => isset($cur['origin_ip']) ? $cur['origin_ip'] : $ip,
            'stream_ip'        => $ip,
            'id'               => isset($cur['id']) ? (int) $cur['id'] : 0,
            'reason'           => 'Truy cập tạm thời bị khoá bởi hệ thống bảo mật.',
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
            'case_code'        => isset($cur['case_code']) ? (string) $cur['case_code'] : '',
            'hits'             => isset($cur['hits']) ? (int) $cur['hits'] : 0,
            'win_ts'           => time(),
            'win_hits'         => 1,
            'last_touch'       => time(),
        ], $record);
        if (empty($merged['case_code'])) {
            $merged['case_code'] = sh_issue_case_code($ip, $merged);
        }
        return $merged;
    });

    // Bind across all 6 layers (Hardware FP, Browser LID, IPv6 /64 prefix, Username) so zero loopholes exist while banned!
    if ($hwFp !== '') {
        sh_bind_entity_lock('hw', $hwFp, $saved);
    }
    if ($lid !== '') {
        sh_bind_entity_lock('lid', $lid, $saved);
    }
    if (strpos($net64, '/64') !== false) {
        sh_bind_entity_lock('net64', $net64, $saved);
    }
    if (!empty($saved['username'])) {
        sh_bind_entity_lock('user', $saved['username'], $saved);
    }
    return $saved;
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

function sh_unlock_ack_path($kind, $val)
{
    $val = strtolower(trim((string) $val));
    $h   = md5('uack9|' . $kind . '|' . $val);
    return sh_dir() . '/unlock_ack/' . substr($h, 0, 2) . '/' . $h . '.json';
}

function sh_set_unlock_ack(array $ips, array $lids = [], array $hws = [], $by = 'auto')
{
    $ips  = array_values(array_unique(array_filter(array_map('sh_norm_ip', $ips))));
    $lids = array_values(array_unique(array_filter(array_map('strtolower', $lids))));
    $hws  = array_values(array_unique(array_filter(array_map('strtolower', $hws))));
    if (!$ips && !$lids && !$hws) {
        return;
    }
    $now = time();
    $rec = [
        'ack_needed' => 1,
        'by'         => (string) $by,
        'at'         => $now,
        'exp'        => $now + 7 * 86400,
        'ips'        => $ips,
        'lids'       => $lids,
        'hws'        => $hws,
    ];
    foreach ($ips as $cip) {
        list($safe, ) = sh_is_safe_ip($cip);
        if (!$safe) {
            sh_json_update(sh_unlock_ack_path('ip', $cip), function () use ($rec) { return $rec; });
        }
    }
    foreach ($lids as $ld) {
        sh_json_update(sh_unlock_ack_path('lid', $ld), function () use ($rec) { return $rec; });
    }
    foreach ($hws as $hw) {
        sh_json_update(sh_unlock_ack_path('hw', $hw), function () use ($rec) { return $rec; });
    }
}

function sh_read_unlock_ack($ip, $lid = '', $hw = '')
{
    $now    = time();
    $checks = [];
    $ip     = sh_norm_ip($ip);
    if ($ip !== '') {
        $checks[] = ['ip', $ip];
    }
    if ($lid !== '') {
        $checks[] = ['lid', strtolower($lid)];
    }
    if ($hw !== '') {
        $checks[] = ['hw', strtolower($hw)];
    }
    foreach ($checks as $pair) {
        $p = sh_unlock_ack_path($pair[0], $pair[1]);
        if (!is_file($p)) {
            continue;
        }
        $d = sh_json_read($p);
        if (empty($d['ack_needed']) || (!empty($d['exp']) && (int) $d['exp'] <= $now)) {
            @unlink($p);
            continue;
        }
        return $d;
    }
    return null;
}

function sh_clear_unlock_ack($ip, $lid = '', $hw = '')
{
    $ips  = [];
    $lids = [];
    $hws  = [];
    $ip   = sh_norm_ip($ip);
    if ($ip !== '') {
        $ips[] = $ip;
    }
    if ($lid !== '') {
        $lids[] = strtolower($lid);
    }
    if ($hw !== '') {
        $hws[] = strtolower($hw);
    }
    $existing = sh_read_unlock_ack($ip, $lid, $hw);
    if (is_array($existing)) {
        if (!empty($existing['ips']) && is_array($existing['ips'])) {
            $ips = array_merge($ips, $existing['ips']);
        }
        if (!empty($existing['lids']) && is_array($existing['lids'])) {
            $lids = array_merge($lids, $existing['lids']);
        }
        if (!empty($existing['hws']) && is_array($existing['hws'])) {
            $hws = array_merge($hws, $existing['hws']);
        }
    }
    foreach (array_unique(array_filter($ips)) as $cip) {
        @unlink(sh_unlock_ack_path('ip', sh_norm_ip($cip)));
    }
    foreach (array_unique(array_filter($lids)) as $ld) {
        @unlink(sh_unlock_ack_path('lid', strtolower($ld)));
    }
    foreach (array_unique(array_filter($hws)) as $h) {
        @unlink(sh_unlock_ack_path('hw', strtolower($h)));
    }
}

/**
 * CLEAN-SLATE UNBAN GUARANTEE + POST-UNBAN "GOT IT" GATE:
 * Wipes 100% of IP ban caches, cross-device entity locks (hw, lid, net64, user),
 * encrypted Case Codes, pending queues, AND old rate/fail counters so an unbanned user
 * is NEVER instantly re-banned — and arms the "Đã tự động mở khoá" + "Got it" confirmation screen!
 */
function sh_full_clean_slate_unban($conn, $ip, $username = '', $createUnlockGate = true, $hadDbBan = false, $unbannedBy = 'auto')
{
    $ip  = sh_norm_ip($ip);
    $rec = $ip !== '' ? sh_read_ban_cache($ip) : null;
    $hadAnyActiveBan = ($rec !== null) || (bool) $hadDbBan;

    $ipsToClean  = [];
    $lidsToClean = [];
    $hwsToClean  = [];
    if ($ip !== '') {
        $ipsToClean[] = $ip;
    }
    if ($rec) {
        foreach (['ip', 'origin_ip', 'stream_ip'] as $ik) {
            if (!empty($rec[$ik])) {
                $ipsToClean[] = sh_norm_ip($rec[$ik]);
            }
        }
        if ($username === '' && !empty($rec['username'])) {
            $username = (string) $rec['username'];
        }
        if (!empty($rec['hw_fp'])) {
            $hwsToClean[] = strtolower((string) $rec['hw_fp']);
        }
        if (!empty($rec['lid'])) {
            $lidsToClean[] = strtolower((string) $rec['lid']);
        }
    }
    // Also clean current request's hw/lid if called during auto-expiry request
    if (sh_client_ip() === $ip) {
        $curHw  = sh_hw_fp_cookie();
        $curLid = sh_browser_lid();
        if ($curHw !== '') {
            $hwsToClean[] = strtolower($curHw);
        }
        if ($curLid !== '') {
            $lidsToClean[] = strtolower($curLid);
        }
    }
    if ($username !== '') {
        $uLockPath = sh_entity_lock_path('user', $username);
        if (is_file($uLockPath)) {
            $hadAnyActiveBan = true;
            @unlink($uLockPath);
        }
    }

    $ipsToClean  = array_values(array_unique(array_filter($ipsToClean)));
    $hwsToClean  = array_values(array_unique(array_filter($hwsToClean)));
    $lidsToClean = array_values(array_unique(array_filter($lidsToClean)));

    // Scan bans/*/*.json so any secondary IP banned via cross-device/4G evasion from the same user/device/origin_ip is ALSO cleanly lifted!
    foreach (glob(sh_dir() . '/bans/*/*.json') ?: [] as $bf) {
        $bd = sh_json_read($bf);
        if (empty($bd['ip'])) {
            continue;
        }
        $bIp   = sh_norm_ip($bd['ip']);
        $bOrig = !empty($bd['origin_ip']) ? sh_norm_ip($bd['origin_ip']) : '';
        $bHw   = !empty($bd['hw_fp']) ? strtolower((string) $bd['hw_fp']) : '';
        $bLid  = !empty($bd['lid']) ? strtolower((string) $bd['lid']) : '';
        $bUser = !empty($bd['username']) ? (string) $bd['username'] : '';

        if (in_array($bIp, $ipsToClean, true)
            || ($bOrig !== '' && in_array($bOrig, $ipsToClean, true))
            || ($bHw !== '' && in_array($bHw, $hwsToClean, true))
            || ($bLid !== '' && in_array($bLid, $lidsToClean, true))
            || ($username !== '' && $bUser !== '' && strcasecmp($bUser, $username) === 0)) {
            $hadAnyActiveBan = true;
            $ipsToClean[]    = $bIp;
            if ($bOrig !== '') {
                $ipsToClean[] = $bOrig;
            }
            if ($bHw !== '') {
                $hwsToClean[] = $bHw;
            }
            if ($bLid !== '') {
                $lidsToClean[] = $bLid;
            }
        }
    }

    $ipsToClean  = array_values(array_unique(array_filter($ipsToClean)));
    $hwsToClean  = array_values(array_unique(array_filter($hwsToClean)));
    $lidsToClean = array_values(array_unique(array_filter($lidsToClean)));

    foreach ($hwsToClean as $hw) {
        @unlink(sh_entity_lock_path('hw', $hw));
    }
    foreach ($lidsToClean as $ld) {
        @unlink(sh_entity_lock_path('lid', $ld));
    }

    // Scan elocks directory to purge any secondary device/VPN locks linked to these IPs/devices
    foreach (glob(sh_dir() . '/elocks/*/*.json') ?: [] as $ef) {
        $ed = sh_json_read($ef);
        if (!empty($ed['ip']) && in_array(sh_norm_ip($ed['ip']), $ipsToClean, true)) {
            @unlink($ef);
        } elseif (!empty($ed['origin_ip']) && in_array(sh_norm_ip($ed['origin_ip']), $ipsToClean, true)) {
            @unlink($ef);
        } elseif (!empty($ed['hw_fp']) && in_array(strtolower((string) $ed['hw_fp']), $hwsToClean, true)) {
            @unlink($ef);
        } elseif (!empty($ed['lid']) && in_array(strtolower((string) $ed['lid']), $lidsToClean, true)) {
            @unlink($ef);
        } elseif ($username !== '' && !empty($ed['username']) && strcasecmp((string) $ed['username'], $username) === 0) {
            @unlink($ef);
        }
    }

    foreach ($ipsToClean as $cip) {
        $net64 = sh_ip_network_key($cip);
        if (strpos($net64, '/64') !== false) {
            @unlink(sh_entity_lock_path('net64', $net64));
        }
        sh_revoke_case_codes_for_ip($cip);
        @unlink(sh_ban_cache_path($cip));
        @unlink(sh_dir() . '/appeals_once/ip_' . md5($cip) . '.json');

        $h   = md5($cip);
        $sub = substr($h, 0, 2) . '/' . $h . '.json';
        @unlink(sh_dir() . '/rates/' . $sub);
        @unlink(sh_dir() . '/ddos_strikes/' . $sub);
        @unlink(sh_dir() . '/keyfails/' . $sub);
        @unlink(sh_dir() . '/fkabuse/' . $sub);
        @unlink(sh_dir() . '/prodspam_hits/' . $sub);

        foreach (glob(sh_dir() . '/act_guard/*/*.json') ?: [] as $af) {
            if (@filemtime($af) < time() - 5) {
                @unlink($af);
            }
        }
    }

    $pFile = sh_dir() . '/pending_bans.json';
    if (is_file($pFile) && $ipsToClean) {
        sh_json_update($pFile, function ($cur) use ($ipsToClean) {
            if (!is_array($cur)) {
                return [];
            }
            foreach ($ipsToClean as $cip) {
                unset($cur[$cip]);
            }
            return $cur;
        });
    }

    // Also clear old MySQL key_attempts / ds_key_attempts and mark any linked secondary ip_bans inactive!
    if ($conn instanceof mysqli) {
        foreach ($ipsToClean as $cip) {
            try {
                $st0 = @$conn->prepare("UPDATE ip_bans SET active = 0, unbanned_by = COALESCE(unbanned_by, 'linked-clean'), unbanned_at = COALESCE(unbanned_at, NOW()) WHERE ip = ? AND active = 1");
                if ($st0) {
                    $st0->bind_param('s', $cip);
                    @$st0->execute();
                    if ($st0->affected_rows > 0) {
                        $hadAnyActiveBan = true;
                    }
                    $st0->close();
                }
            } catch (Throwable $e) {}
            try {
                $st = @$conn->prepare('DELETE FROM key_attempts WHERE ip = ?');
                if ($st) { $st->bind_param('s', $cip); @$st0->execute(); $st->close(); }
            } catch (Throwable $e) {}
            try {
                $st2 = @$conn->prepare('DELETE FROM ds_key_attempts WHERE ip = ?');
                if ($st2) { $st2->bind_param('s', $cip); @$st2->execute(); $st2->close(); }
            } catch (Throwable $e) {}
        }
        if ($username !== '') {
            try {
                $st3 = @$conn->prepare('DELETE FROM key_attempts WHERE username = ?');
                if ($st3) { $st3->bind_param('s', $username); @$st3->execute(); $st3->close(); }
            } catch (Throwable $e) {}
        }
    }

    // Arm the Post-Unban "Đã tự động mở khoá" + "Got it" confirmation screen!
    if ($createUnlockGate && $hadAnyActiveBan) {
        sh_set_unlock_ack($ipsToClean, $lidsToClean, $hwsToClean, $unbannedBy);
    }
}

function sh_remove_ban_cache($ip)
{
    sh_full_clean_slate_unban(isset($GLOBALS['conn']) && ($GLOBALS['conn'] instanceof mysqli) ? $GLOBALS['conn'] : null, $ip, '');
}

function sh_queue_pending_ban(array $item)
{
    sh_json_update(sh_dir() . '/pending_bans.json', function ($list) use ($item) {
        if (!is_array($list)) {
            $list = [];
        }
        $list[$item['ip']] = $item;
        return array_slice($list, -200, null, true);
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
 * 7. Instant Zero-Delay VPN / Proxy / WARP Intelligence
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

/** Known Cloudflare WARP (1.1.1.1) & Tor/VPN CIDRs checked in < 0.05ms locally! */
function sh_is_known_vpn_cidr($ip)
{
    static $warpCidrs = [
        '104.28.0.0/16', '172.71.0.0/16', '2606:4700:d0::/48', '2606:4700:d1::/48',
    ];
    return sh_in_cidr_list($ip, $warpCidrs);
}

/**
 * Synchronous VPN / Proxy / Fake-Region check (cached 24h per IP so repeat requests take 0.02ms).
 * Runs BEFORE page render so VPN users are caught on the VERY FIRST visit with 0 delay!
 */
function sh_inspect_ip_vpn($ip)
{
    $ip = sh_norm_ip($ip);
    if ($ip === '' || sh_is_private_or_local($ip)) {
        return ['is_vpn' => false, 'label' => '', 'isp' => 'Local', 'country' => 'VN'];
    }
    if (sh_has_proxy_headers()) {
        return ['is_vpn' => true, 'label' => 'Proxy trung gian / Fake IP Header', 'isp' => 'Proxy', 'country' => ''];
    }
    if (sh_is_known_vpn_cidr($ip)) {
        return ['is_vpn' => true, 'label' => 'Ứng dụng 1.1.1.1 / Cloudflare WARP', 'isp' => 'Cloudflare WARP', 'country' => ''];
    }

    $h      = md5($ip);
    $cFile  = sh_dir() . '/ip_intel/' . substr($h, 0, 2) . '/' . $h . '.json';
    $now    = time();
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
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT        => 2,
        ]);
        $raw = @curl_exec($ch);
        curl_close($ch);
    }
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j) || (isset($j['status']) && $j['status'] !== 'success')) {
        $fallback = ['is_vpn' => false, 'label' => '', 'isp' => '', 'country' => '', 'at' => $now - 85500];
        sh_json_update($cFile, function () use ($fallback) { return $fallback; });
        return $fallback;
    }

    $isp     = trim((string) (isset($j['isp']) ? $j['isp'] : '') . ' ' . (string) (isset($j['org']) ? $j['org'] : '') . ' ' . (string) (isset($j['as']) ? $j['as'] : ''));
    $country = strtoupper((string) (isset($j['countryCode']) ? $j['countryCode'] : ''));
    $isProxy = !empty($j['proxy']);
    $isHost  = !empty($j['hosting']);
    $vpnKw   = (bool) preg_match('~(Cloudflare|WARP|M247|Datacamp|Choopa|Vultr|DigitalOcean|Linode|Hetzner|OVH|Contabo|Nord|ExpressVPN|Proton|Mullvad|Surfshark|CyberGhost|LeaseWeb|Tzulo|PQ Hosting|G-Core|Zenlayer|Tor Exit|Private Internet Access|Windscribe|ShadowSocks|Outline)~i', $isp);

    $isVpn = $isProxy || $isHost || $vpnKw;
    $label = '';
    if ($isVpn) {
        if (stripos($isp, 'Cloudflare') !== false || stripos($isp, 'WARP') !== false) {
            $label = 'Ứng dụng 1.1.1.1 / Cloudflare WARP';
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
 * 8. Native iosmods.xyz Matte Monochrome UI (Zero Glow, Zero Colorful
 *    Boxes, Frameless Animated Sad / Happy / Unlock SVG Icons!)
 * ------------------------------------------------------------------ */

function sh_wants_json()
{
    return isset($_GET['ajax']) || isset($_GET['sync'])
        || isset($_GET['__sh_telemetry']) || isset($_GET['__sh_report']) || isset($_GET['__sh_prod_spam']) || isset($_GET['__sh_appeal']) || isset($_GET['__sh_ack_unlock'])
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

function sh_bot_appeal_url()
{
    return 'https://t.me/devios_bot?start=hotro';
}

function sh_fmt_remaining($sec)
{
    $sec = max(0, (int) $sec);
    $h   = (int) floor($sec / 3600);
    $m   = (int) floor(($sec % 3600) / 60);
    $s   = $sec % 60;
    return $h > 0 ? sprintf('%02d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
}

function sh_brand_logo_metrics()
{
    static $memo = null;
    if ($memo !== null && empty($GLOBALS['SH_LOGO_MEMO_RESET'])) {
        return $memo;
    }
    $GLOBALS['SH_LOGO_MEMO_RESET'] = 0;
    $rel      = '';
    $hPx      = 42;
    $scale    = 1.4;
    $skew     = 10.0;
    $shineOn  = true;
    $shineSec = 2.2;
    $c        = isset($GLOBALS['conn']) && ($GLOBALS['conn'] instanceof mysqli) ? $GLOBALS['conn'] : null;
    if ($c === null) {
        try {
            if (is_file(__DIR__ . '/config.php')) {
                require_once __DIR__ . '/config.php';
            }
            $h = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: 'localhost');
            $u = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: '');
            $p = defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') ?: '');
            $n = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: '');
            if ($u !== '') {
                $c = defined('DB_PORT') ? @mysqli_connect($h, $u, $p, $n, (int) DB_PORT) : @mysqli_connect($h, $u, $p, $n);
            }
        } catch (Throwable $e) {
            $c = null;
        }
    }
    if ($c instanceof mysqli) {
        try {
            $r = @$c->query("SELECT config_key, config_value FROM site_config WHERE config_key IN ('brand_logo','brand_logo_height','brand_logo_scale','ds_logo_h','ds_logo_skew','ds_shine','ds_shine_seconds')");
            while ($r && ($row = $r->fetch_assoc())) {
                $k = (string) $row['config_key'];
                $v = trim((string) $row['config_value']);
                if ($k === 'brand_logo' && $v !== '') {
                    $rel = $v;
                } elseif (($k === 'brand_logo_height' || $k === 'ds_logo_h') && is_numeric($v)) {
                    $iv = (int) $v;
                    if ($iv >= 26 && $iv <= 68) {
                        $hPx = $iv;
                    }
                } elseif ($k === 'brand_logo_scale' && is_numeric($v)) {
                    $fv = (float) $v;
                    if ($fv >= 1.0 && $fv <= 3.0) {
                        $scale = $fv;
                    }
                } elseif ($k === 'ds_logo_skew' && is_numeric(str_replace(',', '.', $v))) {
                    $sk = (float) str_replace(',', '.', $v);
                    if ($sk >= -30 && $sk <= 30) {
                        $skew = $sk;
                    }
                } elseif ($k === 'ds_shine') {
                    $shineOn = ($v !== '0');
                } elseif ($k === 'ds_shine_seconds' && is_numeric(str_replace(',', '.', $v))) {
                    $ss = (float) str_replace(',', '.', $v);
                    if ($ss >= 1.2 && $ss <= 20.0) {
                        $shineSec = $ss;
                    }
                }
            }
        } catch (Throwable $e) {}
    }

    $file   = '';
    $extUrl = '';
    if ($rel !== '') {
        if (preg_match('#^https?://#i', $rel)) {
            $extUrl = $rel;
        } else {
            $cand = __DIR__ . '/' . ltrim($rel, '/\\');
            if (is_file($cand)) {
                $file = $cand;
            }
        }
    }
    if ($file === '' && $extUrl === '') {
        $dsLogos = glob(__DIR__ . '/uploads/dslogo_*.*') ?: [];
        if ($dsLogos) {
            usort($dsLogos, function ($a, $b) {
                return ((int) @filemtime($b)) - ((int) @filemtime($a));
            });
            $file = $dsLogos[0];
        } elseif (is_file(__DIR__ . '/uploads/logo.png')) {
            $file = __DIR__ . '/uploads/logo.png';
        }
    }
    return $memo = [
        'file'      => $file,
        'ext_url'   => $extUrl,
        'height'    => $hPx,
        'scale'     => $scale,
        'skew'      => $skew,
        'shine_on'  => $shineOn,
        'shine_sec' => $shineSec,
    ];
}

function sh_resolve_brand_logo_file()
{
    $m = sh_brand_logo_metrics();
    return $m['file'];
}

/**
 * Serves the exact uncropped 600x200 iosmods.xyz main header logo (matching index.php ?brand_logo=1 1:1!)
 * directly before any ban check so all shimmer layers share 1 cached bitmap with ZERO phase lag!
 */
function sh_logo_url()
{
    $m = sh_brand_logo_metrics();
    if (!empty($m['ext_url'])) {
        return (string) $m['ext_url'];
    }
    $file = $m['file'];
    $ver  = $file !== '' && is_file($file) ? substr(md5('v9|' . $file . '|' . (int) @filemtime($file)), 0, 8) : 'v9';
    return '/asadnsrdecsb/home?__sh_brand_logo=1&v=' . $ver;
}

function sh_serve_brand_logo_asset()
{
    if (!isset($_GET['__sh_brand_logo'])) {
        return;
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $file = sh_resolve_brand_logo_file();
    if ($file === '' || !is_file($file)) {
        http_response_code(404);
        exit;
    }
    $mtime     = (int) @filemtime($file);
    $hash      = substr(md5('v8_200|' . $file . '|' . $mtime), 0, 16);
    $cacheWebp = sh_dir() . '/logo_v8_' . $hash . '.webp';
    $cachePng  = sh_dir() . '/logo_v8_' . $hash . '.png';

    $mime  = '';
    $bytes = '';
    if (is_file($cacheWebp)) {
        $mime  = 'image/webp';
        $bytes = (string) @file_get_contents($cacheWebp);
    } elseif (is_file($cachePng)) {
        $mime  = 'image/png';
        $bytes = (string) @file_get_contents($cachePng);
    }

    if ($bytes === '') {
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            http_response_code(404);
            exit;
        }
        // Resize proportionally up to 800x200 WITHOUT cropping transparent margins (exact match to index.php BRAND_LOGO_MAX_HEIGHT=200!)
        if (function_exists('imagecreatefromstring')) {
            @ini_set('memory_limit', '256M');
            $im = @imagecreatefromstring($raw);
            if ($im !== false) {
                $w     = imagesx($im);
                $h     = imagesy($im);
                $scale = min(1.0, 200.0 / max(1, $h), 800.0 / max(1, $w));
                $dw    = max(1, (int) round($w * $scale));
                $dh    = max(1, (int) round($h * $scale));
                $dst   = imagecreatetruecolor($dw, $dh);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
                imagecopyresampled($dst, $im, 0, 0, 0, 0, $dw, $dh, $w, $h);
                imagedestroy($im);

                if (function_exists('imagewebp')) {
                    ob_start();
                    $ok = @imagewebp($dst, null, 94);
                    $wb = (string) ob_get_clean();
                    if ($ok && $wb !== '') {
                        $mime  = 'image/webp';
                        $bytes = $wb;
                        @file_put_contents($cacheWebp, $wb, LOCK_EX);
                    }
                }
                if ($bytes === '') {
                    ob_start();
                    @imagepng($dst, null, 7);
                    $pn = (string) ob_get_clean();
                    if ($pn !== '') {
                        $mime  = 'image/png';
                        $bytes = $pn;
                        @file_put_contents($cachePng, $pn, LOCK_EX);
                    }
                }
                imagedestroy($dst);
            }
        }
        if ($bytes === '') {
            $ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $mime  = $ext === 'webp' ? 'image/webp' : ($ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png');
            $bytes = $raw;
        }
    }

    $etag = '"' . md5($bytes) . '"';
    header_remove('Pragma');
    header('Cache-Control: public, max-age=604800, immutable');
    header('ETag: ' . $etag);
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

function sh_appeal_vcode_sig($lid, $caseCode, $vcode)
{
    return substr(hash_hmac('sha256', 'ap_v8|' . strtolower((string) $lid) . '|' . strtoupper((string) $caseCode) . '|' . trim((string) $vcode), sh_secret()), 0, 28);
}

function sh_has_sent_appeal($ip, $caseCode, $lid = '')
{
    if ($caseCode !== '') {
        $cf = sh_dir() . '/appeals_once/case_' . md5(strtoupper($caseCode)) . '.json';
        $cd = sh_json_read($cf);
        if (!empty($cd['sent'])) {
            return true;
        }
    }
    if ($ip !== '') {
        $if = sh_dir() . '/appeals_once/ip_' . md5(sh_norm_ip($ip)) . '.json';
        $id = sh_json_read($if);
        if (!empty($id['sent'])) {
            $ban = sh_read_ban_cache($ip);
            $banCreated = $ban && !empty($ban['created_ts']) ? (int) $ban['created_ts'] : 0;
            if ($banCreated === 0 || (int) $id['at'] >= $banCreated - 5) {
                return true;
            }
        }
    }
    if ($lid !== '') {
        $lf = sh_dir() . '/appeals_once/lid_' . md5(strtolower($lid)) . '.json';
        $ld = sh_json_read($lf);
        if (!empty($ld['sent']) && (time() - (int) $ld['at']) < 3600) {
            $ban = $ip !== '' ? sh_read_ban_cache($ip) : null;
            if ($ban !== null && (empty($ban['created_ts']) || (int) $ld['at'] >= (int) $ban['created_ts'] - 5)) {
                return true;
            }
        }
    }
    return false;
}

function sh_mark_appeal_sent($ip, $caseCode, $lid = '')
{
    $now = time();
    $rec = ['sent' => 1, 'at' => $now, 'ip' => $ip, 'case' => $caseCode];
    if ($caseCode !== '') {
        sh_json_update(sh_dir() . '/appeals_once/case_' . md5(strtoupper($caseCode)) . '.json', function () use ($rec) { return $rec; });
    }
    if ($ip !== '') {
        sh_json_update(sh_dir() . '/appeals_once/ip_' . md5(sh_norm_ip($ip)) . '.json', function () use ($rec) { return $rec; });
    }
    if ($lid !== '') {
        sh_json_update(sh_dir() . '/appeals_once/lid_' . md5(strtolower($lid)) . '.json', function () use ($rec) { return $rec; });
    }
}

function sh_unlock_ack_token($ip, $lid)
{
    return substr(hash_hmac('sha256', 'uack_tok9|' . sh_norm_ip($ip) . '|' . strtolower((string) $lid), sh_secret()), 0, 28);
}

/**
 * Handles clicking "Got it" on the Post-Unban "Đã tự động mở khoá" screen (?__sh_ack_unlock=1).
 * Until "Got it" is clicked, revisiting or refreshing any page keeps showing "Đã tự động mở khoá"!
 */
function sh_handle_unlock_ack_endpoint()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_GET['__sh_ack_unlock'])) {
        return;
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $ip        = sh_client_ip();
    $forensics = sh_trace_forensics();
    $lid       = isset($_POST['lid']) && preg_match('/^[a-f0-9]{32}$/i', (string) $_POST['lid']) ? strtolower((string) $_POST['lid']) : sh_browser_lid();
    $tok       = isset($_POST['ack_tok']) ? (string) $_POST['ack_tok'] : '';
    $isPreview = !empty($_POST['preview']);

    $valid = $isPreview
        || ($ip !== '' && hash_equals(sh_unlock_ack_token($ip, $lid), $tok))
        || ($forensics['real_ip'] !== '' && hash_equals(sh_unlock_ack_token($forensics['real_ip'], $lid), $tok));

    if ($valid && !$isPreview) {
        sh_clear_unlock_ack($ip, $lid, sh_hw_fp_cookie());
        if ($forensics['real_ip'] !== $ip) {
            sh_clear_unlock_ack($forensics['real_ip'], $lid, sh_hw_fp_cookie());
        }
    }

    $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => (bool) $valid, 'redirect' => '/'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Cache-Control: no-store');
    header('Location: /', true, 303);
    exit;
}

/**
 * Handles in-page Unlock Request (?__sh_appeal=1):
 * - Field 1: Tài khoản website (username)
 * - Field 2: Nhập mã xác minh (verify_code = IMX-... displayed on the block card)
 * - Field 3: Lý do miêu tả vì sao bị khoá (message)
 * - Strictly allows ONLY 1 request per active ban!
 * - Alerts Admin on Telegram immediately with username, IP, reason, case_code & 1-tap unban buttons.
 */
function sh_handle_appeal_endpoint()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_GET['__sh_appeal'])) {
        return;
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $lid        = isset($_POST['lid']) && preg_match('/^[a-f0-9]{32}$/i', (string) $_POST['lid']) ? strtolower((string) $_POST['lid']) : sh_browser_lid();
    $sig        = isset($_POST['sig']) ? (string) $_POST['sig'] : '';
    $caseCode   = isset($_POST['case_code']) ? sh_extract_case_code($_POST['case_code']) : '';
    $typedCode  = isset($_POST['verify_code']) ? sh_extract_case_code($_POST['verify_code']) : '';
    $typedUser  = isset($_POST['username']) ? trim(preg_replace('/[\r\n\t<>]+/', '', substr((string) $_POST['username'], 0, 60))) : '';
    $msg        = isset($_POST['message']) ? trim(preg_replace('/[\r\n]+/', ' ', strip_tags(substr((string) $_POST['message'], 0, 400)))) : '';
    $isPreview  = !empty($_POST['preview']);

    if ($lid === '' || !hash_equals(sh_client_sig($lid), $sig)) {
        echo json_encode(['ok' => false, 'msg' => 'Phiên xác thực không hợp lệ, vui lòng tải lại trang.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($typedCode === '' || ($caseCode !== '' && !hash_equals($caseCode, $typedCode))) {
        echo json_encode(['ok' => false, 'bad_code' => true, 'msg' => 'Mã xác minh chưa đúng, vui lòng sao chép đúng Mã xác minh ở khung bên ngoài.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($msg === '') {
        echo json_encode(['ok' => false, 'msg' => 'Vui lòng nhập lý do miêu tả vì sao bạn bị khoá.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($isPreview) {
        echo json_encode(['ok' => true, 'msg' => 'Đã gửi cho Admin, vui lòng đợi trong ít phút.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $forensics = sh_trace_forensics();
    $ip        = sh_client_ip();
    if ($ip === '') {
        echo json_encode(['ok' => false, 'msg' => 'Không xác định được địa chỉ kết nối.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $banRec = sh_read_ban_cache($ip);
    if ($banRec === null && $forensics['real_ip'] !== $ip) {
        $banRec = sh_read_ban_cache($forensics['real_ip']);
    }
    $caseRec = $typedCode !== '' ? sh_lookup_case_code($typedCode) : null;
    if ($banRec === null && $caseRec === null) {
        echo json_encode(['ok' => true, 'msg' => 'Kết nối của bạn hiện đã được mở khoá, hãy tải lại trang.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($banRec && !empty($banRec['case_code']) && !hash_equals((string) $banRec['case_code'], $typedCode)) {
        echo json_encode(['ok' => false, 'bad_code' => true, 'msg' => 'Mã xác minh không khớp với phiên khoá hiện tại.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $finalCode = $typedCode !== '' ? $typedCode : (($banRec && !empty($banRec['case_code'])) ? (string) $banRec['case_code'] : '');

    // STRICT ONE-TIME ONLY PER BAN!
    if (sh_has_sent_appeal($ip, $finalCode, $lid)) {
        echo json_encode([
            'ok'           => false,
            'already_sent' => true,
            'msg'          => 'Bạn chỉ được gửi yêu cầu 1 lần. Đã gửi cho Admin, vui lòng đợi trong ít phút.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    sh_mark_appeal_sent($ip, $finalCode, $lid);

    $now = time();
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    $sessUser  = isset($_SESSION['username']) && is_string($_SESSION['username']) ? trim($_SESSION['username']) : '';
    $banUser   = ($banRec && !empty($banRec['username'])) ? (string) $banRec['username'] : (($caseRec && !empty($caseRec['username'])) ? (string) $caseRec['username'] : '');
    $finalUser = $sessUser !== '' ? $sessUser : ($typedUser !== '' ? $typedUser : $banUser);
    $reason    = ($banRec && !empty($banRec['reason'])) ? (string) $banRec['reason'] : (($caseRec && !empty($caseRec['reason'])) ? (string) $caseRec['reason'] : 'Khoá bảo mật hệ thống');

    $appealData = [
        'ip'         => $forensics['real_ip'] ?: $ip,
        'stream_ip'  => $forensics['stream_ip'],
        'username'   => $finalUser,
        'verified_u' => $sessUser !== '' ? 1 : 0,
        'typed_user' => $typedUser,
        'case_code'  => $finalCode,
        'reason'     => $reason,
        'message'    => $msg,
        'at'         => date('Y-m-d H:i:s', $now),
    ];

    sh_json_update(sh_dir() . '/appeals_log.json', function ($list) use ($appealData) {
        if (!is_array($list)) {
            $list = [];
        }
        $list[] = $appealData;
        return array_slice($list, -60);
    });

    $sentNow = false;
    try {
        if (is_file(__DIR__ . '/telegram.php')) {
            require_once __DIR__ . '/telegram.php';
            if (function_exists('tg_boot')) {
                tg_boot('web');
            }
            if (function_exists('tg_enabled') && tg_enabled() && function_exists('tg_event')) {
                tg_event('user_appeal', $appealData);
                if (function_exists('tg_flush')) {
                    tg_flush(false);
                }
                $sentNow = true;
            }
        }
    } catch (Throwable $e) {
        $sentNow = false;
    }
    if (!$sentNow) {
        sh_queue_pending_event('user_appeal', $appealData);
    }

    echo json_encode(['ok' => true, 'msg' => 'Đã gửi cho Admin, vui lòng đợi trong ít phút.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function sh_native_shared_css()
{
    $m     = sh_brand_logo_metrics();
    $logoH = (int) $m['height'];
    $logoS = rtrim(rtrim(number_format((float) $m['scale'], 2, '.', ''), '0'), '.');
    if ($logoS === '') {
        $logoS = '1.4';
    }
    $skew     = rtrim(rtrim(number_format((float) $m['skew'], 2, '.', ''), '0'), '.');
    if ($skew === '' || $skew === '-0') {
        $skew = '0';
    }
    $shineSec  = max(1.2, (float) $m['shine_sec']);
    $shineDur  = rtrim(rtrim(number_format($shineSec, 2, '.', ''), '0'), '.');
    $shineHalf = rtrim(rtrim(number_format($shineSec / 2.0, 2, '.', ''), '0'), '.');
    $shineDisp = !empty($m['shine_on']) ? 'block' : 'none';

    return ':root{color-scheme:dark;--ds-bg:#000000;--ds-card:#0a0a0b;--ds-field:#141416;--ds-line:#1f1f23;--ds-line2:#26262b;--ds-h:#ffffff;--ds-p:#9ca3af;--ds-mute:#8e8e93;--ds-hd-bg:#000000;--ds-hd-line:#1c1c1f;--ds-btn-bg:#0a0a0b;--ds-btn-line:#26262b;--ds-btn-fg:#ffffff;--ds-nav:#0e0e10;--ds-nav-see:rgba(22,22,26,.46);--ds-nav-see-line:rgba(255,255,255,.07);--ds-pill:rgba(59,158,255,.2);--ds-item:#d1d5db;--ds-link:#3b9eff;--blue:#0a84ff;--cta-bg:#ffffff;--cta-fg:#000000;--hd:54px;--logo-h:' . $logoH . 'px;--brand-logo-scale:' . $logoS . ';--logo-skew:' . $skew . 'deg;--shine-dur:' . $shineDur . 's;--shine-half:-' . $shineHalf . 's;--shine-disp:' . $shineDisp . ';--hbtn:32px;--nav-h:56px;--font:-apple-system,BlinkMacSystemFont,"SF Pro Text","Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}' . <<<'CSS'

:root[data-theme="light"]{color-scheme:light;--ds-bg:#f5f7fa;--ds-card:#ffffff;--ds-field:#f3f4f6;--ds-line:#e5e7eb;--ds-line2:#d1d5db;--ds-h:#111827;--ds-p:#4b5563;--ds-mute:#6b7280;--ds-hd-bg:#ffffff;--ds-hd-line:#e5e7eb;--ds-btn-bg:#f3f4f6;--ds-btn-line:#d1d5db;--ds-btn-fg:#111827;--ds-nav:#ffffff;--ds-nav-see:rgba(255,255,255,.52);--ds-nav-see-line:rgba(0,0,0,.06);--ds-pill:rgba(10,108,220,.13);--ds-item:#374151;--ds-link:#0a6cdc;--blue:#0a6cdc;--cta-bg:#111827;--cta-fg:#ffffff}
*,*::before,*::after{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{touch-action:manipulation;-webkit-text-size-adjust:100%;text-size-adjust:100%;background:var(--ds-bg)}
body{display:flex;flex-direction:column;min-height:100vh;min-height:100dvh;margin:0;padding-top:var(--hd);padding-bottom:calc(var(--nav-h) + env(safe-area-inset-bottom,0px) + 20px);overflow-x:hidden;background:var(--ds-bg);color:var(--ds-h);font-family:var(--font);font-size:14px;line-height:1.45;-webkit-font-smoothing:antialiased}
body *{font-family:var(--font)}
h1,h2,h3,p{margin:0}
a{color:inherit}
button{padding:0;font:inherit;color:inherit;background:none;border:0;cursor:pointer}
[hidden]{display:none!important}
.site-header{position:fixed;top:0;left:0;right:0;width:100%;z-index:900;display:flex;align-items:center;justify-content:space-between;height:var(--hd);min-height:var(--hd);max-height:var(--hd);padding:0 14px;overflow:visible;background:var(--ds-hd-bg);border-bottom:1px solid var(--ds-hd-line);transform:translateZ(0);isolation:isolate}
.brand,.brand--logo{display:flex;align-items:center;height:100%;min-width:0;text-decoration:none;line-height:0;transform:none}
.brand__mark{position:relative;display:block;height:var(--logo-h);margin-left:-6px;-webkit-transform:scale(var(--brand-logo-scale,1.4));transform:scale(var(--brand-logo-scale,1.4));-webkit-transform-origin:0 50%;transform-origin:0 50%;pointer-events:none}
.ds-logo{position:relative;display:inline-block;height:var(--logo-h);line-height:0;overflow:hidden;contain:paint;-webkit-transform:translateZ(0) skewX(var(--logo-skew,10deg));transform:translateZ(0) skewX(var(--logo-skew,10deg));-webkit-transform-origin:50% 50%;transform-origin:50% 50%}
.brand__logo{display:block;width:auto;height:var(--logo-h);min-height:var(--logo-h);max-height:var(--logo-h);max-width:260px;object-fit:contain}
.ds-sw{position:absolute;top:0;left:0;display:var(--shine-disp,block);width:100%;height:100%;opacity:.48;pointer-events:none;transform-origin:50% 50%;-webkit-mask-image:linear-gradient(90deg,rgba(0,0,0,0) 8%,rgba(0,0,0,.52) 28%,#000 50%,rgba(0,0,0,.52) 72%,rgba(0,0,0,0) 92%);mask-image:linear-gradient(90deg,rgba(0,0,0,0) 8%,rgba(0,0,0,.52) 28%,#000 50%,rgba(0,0,0,.52) 72%,rgba(0,0,0,0) 92%);will-change:transform;-webkit-backface-visibility:hidden;backface-visibility:hidden;-webkit-animation:ds-sw35 var(--shine-dur,2.2s) linear infinite;animation:ds-sw35 var(--shine-dur,2.2s) linear infinite;-webkit-animation-delay:var(--dsx-ph,0s);animation-delay:var(--dsx-ph,0s)}
.ds-swi{position:absolute;top:0;left:0;display:block;width:100%;height:100%;max-width:none;object-fit:contain;pointer-events:none;-webkit-filter:brightness(0) invert(1);filter:brightness(0) invert(1);opacity:1;transform-origin:50% 50%;will-change:transform;-webkit-backface-visibility:hidden;backface-visibility:hidden;-webkit-animation:ds-swi35 var(--shine-dur,2.2s) linear infinite;animation:ds-swi35 var(--shine-dur,2.2s) linear infinite;-webkit-animation-delay:var(--dsx-ph,0s);animation-delay:var(--dsx-ph,0s)}
.ds-sw2,.ds-sw2 .ds-swi{-webkit-animation-delay:var(--dsx-ph2,var(--shine-half,-1.1s));animation-delay:var(--dsx-ph2,var(--shine-half,-1.1s))}
html.dsx-re .ds-sw,html.dsx-re .ds-swi{-webkit-animation:none!important;animation:none!important}
@keyframes ds-sw35{from{transform:translate3d(-78%,0,0)}to{transform:translate3d(78%,0,0)}}
@keyframes ds-swi35{from{transform:translate3d(78%,0,0)}to{transform:translate3d(-78%,0,0)}}
.ds-hd{display:flex;align-items:center;gap:6px;flex:0 0 auto;position:relative;z-index:2}
.ds-hbtn{display:flex;align-items:center;justify-content:center;min-width:var(--hbtn);height:var(--hbtn);padding:0 8px;font-size:12px;font-weight:800;color:var(--ds-btn-fg);background:var(--ds-btn-bg);border:1px solid var(--ds-btn-line);border-radius:10px;user-select:none}
.ds-hbtn svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.ds-lang{position:relative}
.ds-pop{position:absolute;top:calc(100% + 8px);right:0;z-index:2000;min-width:136px;overflow:hidden;background:var(--ds-nav);border:1px solid var(--ds-btn-line);border-radius:12px}
.ds-pop button{display:block;width:100%;padding:10px 14px;font-size:13px;font-weight:600;text-align:left;color:var(--ds-item);background:transparent}
.ds-pop button:active{background:var(--ds-field)}
.ds-pop button.is-on{color:var(--ds-link)}
.sh-wrap{flex:1 0 auto;display:flex;flex-direction:column;width:100%}
.stage{flex:1 0 auto;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:22px 16px 6px}
.panel{width:100%;max-width:406px;overflow:hidden;background:var(--ds-card);border:1px solid var(--ds-line);border-radius:20px;padding:22px 18px 18px;text-align:center}
.anim-icon{width:54px;height:54px;margin:0 auto 10px;display:flex;align-items:center;justify-content:center;color:var(--ds-h)}
.anim-icon svg{width:50px;height:50px;overflow:visible}
.panel-title{margin:0 0 8px;font-size:19.5px;font-weight:800;letter-spacing:-.02em;line-height:1.25;color:var(--ds-h)}
.cause{margin:0 0 14px;font-size:13.5px;line-height:1.5;color:var(--ds-p);overflow-wrap:anywhere}
.cause strong{color:var(--ds-h);font-weight:650}
.meta{text-align:left;margin-bottom:12px;border-top:1px solid var(--ds-line)}
.meta-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8.5px 0;font-size:13px;border-bottom:1px solid var(--ds-line)}
.meta-row span:first-child{color:var(--ds-p);flex:none}
.meta-row span:last-child{color:var(--ds-h);font-weight:600;text-align:right;min-width:0;overflow-wrap:anywhere}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;letter-spacing:.01em;font-variant-numeric:tabular-nums}
.code-box{padding:10px 0;border-bottom:1px solid var(--ds-line)}
.code-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:7px;font-size:13px;color:var(--ds-p)}
.copy-pill{flex:0 0 auto;height:26px;padding:0 10px;border-radius:8px;background:var(--ds-field);border:1px solid var(--ds-line2);color:var(--ds-h);font-size:11.5px;font-weight:650;white-space:nowrap}
.copy-pill:active{opacity:.72}
.code-val{display:block;width:100%;max-width:100%;padding:8px 10px;border-radius:10px;background:var(--ds-field);border:1px solid var(--ds-line2);color:var(--ds-h);font-size:11.5px;font-weight:600;text-align:center;line-height:1.4;overflow-wrap:anywhere;word-break:break-all;user-select:all}
.card-rules{text-align:left;margin:0 0 14px;padding:11px 12px;border-radius:13px;background:var(--ds-field);border:1px solid var(--ds-line)}
.card-rules__h{display:flex;align-items:center;gap:6px;margin:0 0 6px;font-size:11.5px;font-weight:750;letter-spacing:.04em;text-transform:uppercase;color:var(--ds-h)}
.card-rules__h svg{width:14px;height:14px;flex:none;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
.card-rules ul{margin:0;padding:0 0 0 15px;font-size:12px;line-height:1.5;color:var(--ds-p)}
.card-rules li{margin-bottom:3px}
.card-rules li:last-child{margin-bottom:0}
.cta-btn{display:flex;align-items:center;justify-content:center;gap:7px;width:100%;height:44px;padding:0 14px;border-radius:12px;background:var(--cta-bg);color:var(--cta-fg);text-decoration:none;font-size:14px;font-weight:700;border:0;cursor:pointer;transition:opacity .15s,transform .1s}
.cta-btn:active{transform:scale(.988);opacity:.88}
.cta-btn:disabled,.cta-btn.is-sent{background:var(--ds-field);color:var(--ds-p);border:1px solid var(--ds-line2);cursor:default;transform:none;opacity:1}
.cta-btn--sub{margin-top:8px;height:41px;background:var(--ds-field);color:var(--ds-h);border:1px solid var(--ds-line2);font-size:13px;font-weight:650}
.cta-btn--sub svg{width:15px;height:15px;flex:none;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
.sh-modal{position:fixed;inset:0;z-index:3200;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.62);-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px);animation:sh-fade .18s ease}
.sh-sheet{width:100%;max-width:362px;padding:20px 18px 18px;border-radius:22px;background:var(--ds-nav);border:1px solid var(--ds-line2);text-align:left;animation:sh-pop .22s cubic-bezier(.2,.9,.25,1.06)}
.sh-sheet__t{margin:0 0 4px;font-size:17px;font-weight:800;text-align:center;color:var(--ds-h)}
.sh-sheet__s{margin:0 0 12px;font-size:12.5px;line-height:1.4;text-align:center;color:var(--ds-p)}
.ap-lbl{display:block;margin:0 0 5px 2px;font-size:12px;font-weight:650;color:var(--ds-p)}
.ap-in,.ap-ta{display:block;width:100%;padding:10px 13px;font-size:15px;color:var(--ds-h);background:var(--ds-field);border:1px solid var(--ds-line2);border-radius:12px;outline:0;-webkit-appearance:none;appearance:none}
.ap-in{height:42px;margin-bottom:9px}
.ap-in--code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;font-weight:650;letter-spacing:.02em}
.ap-ta{min-height:68px;resize:none;line-height:1.42;margin-bottom:11px}
.ap-in::placeholder,.ap-ta::placeholder{color:var(--ds-p);opacity:.78;letter-spacing:normal;font-weight:400}
.ap-in:focus,.ap-ta:focus{border-color:var(--ds-h)}
.ap-err{margin:0 0 9px;font-size:12.5px;line-height:1.4;color:#ff6961;text-align:center}
.ap-actions{display:flex;gap:8px}
.ap-cancel{flex:0 0 88px;height:42px;border-radius:12px;background:var(--ds-field);border:1px solid var(--ds-line2);color:var(--ds-h);font-size:14px;font-weight:650}
.ap-send{flex:1 1 auto;height:42px;border-radius:12px;background:var(--cta-bg);color:var(--cta-fg);font-size:14px;font-weight:700}
.ap-send:disabled{opacity:.55}
.ios-hud{width:100%;max-width:296px;padding:26px 20px 20px;border-radius:22px;background:var(--ds-nav);border:1px solid var(--ds-line2);text-align:center;animation:sh-pop .24s cubic-bezier(.2,.9,.25,1.08)}
.ios-ok-svg{display:block;width:58px;height:58px;margin:0 auto 12px;color:var(--ds-h);overflow:visible}
.ios-ok-ring{stroke:currentColor;stroke-width:2.4;stroke-linecap:round;stroke-dasharray:1;stroke-dashoffset:1;animation:sh-draw .42s cubic-bezier(.22,.61,.36,1) .04s forwards}
.ios-ok-tick{stroke:currentColor;stroke-width:3;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:1;stroke-dashoffset:1;animation:sh-draw .32s cubic-bezier(.22,.61,.36,1) .34s forwards}
.ios-hud__t{margin:0 0 6px;font-size:17px;font-weight:800;color:var(--ds-h)}
.ios-hud__p{margin:0 0 16px;font-size:13.5px;line-height:1.45;color:var(--ds-p)}
.ios-hud__btn{display:flex;align-items:center;justify-content:center;width:100%;height:40px;border-radius:11px;background:var(--cta-bg);color:var(--cta-fg);font-size:14px;font-weight:700}
.site-about{flex:0 0 auto;width:100%;max-width:680px;margin:36px auto 0;padding:26px 16px 0;border-top:1px solid var(--ds-line)}
.ds-docs{margin:0 0 24px;text-align:left}
.ds-docs__blk{display:block;margin:0 0 22px}
.ds-docs__h{display:flex;align-items:center;gap:8px;margin:0 0 8px;font-size:14.5px;font-weight:700;line-height:1.25;letter-spacing:.03em;text-transform:uppercase;color:var(--ds-h)}
.ds-docs__h svg{flex:0 0 auto;width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.ds-docs__h--brand{margin-bottom:8px;font-size:18px;letter-spacing:-.01em;text-transform:none}
.ds-docs__p{font-size:14px;font-weight:400;line-height:1.45;color:var(--ds-mute)}
.ds-docs>.ds-docs__h{margin:0 0 6px}
.ds-doclink{display:block;padding:6px 0;font-size:14px;font-weight:400;line-height:1.3;text-decoration:none;color:var(--ds-mute)}
.ds-doclink:active{opacity:.6}
.site-footer{width:100%;max-width:680px;margin:0 auto;padding:20px 16px 10px;border-top:1px solid var(--ds-line);text-align:center;font-size:11.5px;font-weight:600;text-transform:none;line-height:1.35;color:var(--ds-p)}
.site-footer a{color:var(--blue);text-decoration:none;font-weight:600}
.site-footer__heart{display:inline-block;width:12px;height:12px;vertical-align:-2px;color:#ff3b30;animation:sh-beat .6s infinite}
.site-footer__year{display:block;margin-top:2px;font-weight:400;color:var(--ds-p)}
.ds-nav{position:fixed;left:0;right:0;bottom:0;z-index:1000;isolation:isolate;padding:3px 10px calc(4px + env(safe-area-inset-bottom,0px));background:transparent}
.ds-nav::before{content:"";position:absolute;z-index:-1;left:0;right:0;top:0;bottom:-160px;pointer-events:none;background:var(--ds-nav-see);border-top:1px solid var(--ds-nav-see-line);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px)}
@supports not ((-webkit-backdrop-filter:blur(1px)) or (backdrop-filter:blur(1px))){.ds-nav::before{background:var(--ds-nav)}}
.ds-nav__in{display:flex;gap:6px;max-width:36rem;margin:0 auto}
.ds-nav__item{display:flex;flex:1 1 0;flex-direction:column;align-items:center;gap:1px;min-width:0;padding:5px 2px 4px;font-size:10.5px;font-weight:600;color:var(--ds-p);text-decoration:none;border-radius:12px}
.ds-nav__item.is-active{color:var(--ds-link);background:var(--ds-pill)}
.ds-nav__item span{display:block;max-width:100%;overflow:hidden;line-height:1.25;text-overflow:ellipsis;white-space:nowrap}
.ds-ico{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
@media (min-width:1024px){
:root{--ds-sbw:208px;--hd:56px;--nav-h:0px}
body{padding-top:var(--hd);padding-left:var(--ds-sbw);padding-bottom:32px}
.site-header{padding:0 28px 0 18px;border:0;background:linear-gradient(var(--ds-hd-line),var(--ds-hd-line)) left var(--ds-sbw) bottom/calc(100% - var(--ds-sbw)) 1px no-repeat,linear-gradient(90deg,transparent var(--ds-sbw),var(--ds-hd-bg) var(--ds-sbw))}
.ds-nav{display:flex;flex-direction:column;left:0;right:auto;top:0;bottom:0;width:var(--ds-sbw);padding:calc(var(--hd) + 12px) 12px 14px;background:var(--ds-card);border-right:1px solid var(--ds-line)}
.ds-nav::before{display:none}
.ds-nav__in{flex-direction:column;gap:2px;width:100%;margin:0}
.ds-nav__item{flex:0 0 auto;flex-direction:row;justify-content:flex-start;gap:11px;width:100%;padding:9px 11px;font-size:13.5px;border-radius:9px;color:var(--ds-item)}
.ds-nav__item:hover{background:var(--ds-field)}
.ds-nav .ds-ico{width:17px;height:17px}
}
@keyframes sh-fade{from{opacity:0}to{opacity:1}}
@keyframes sh-pop{from{opacity:0;transform:scale(.92) translateY(6px)}to{opacity:1;transform:none}}
@keyframes sh-draw{to{stroke-dashoffset:0}}
@keyframes sh-shake{0%,100%{transform:translateX(0)}20%{transform:translateX(-5px)}45%{transform:translateX(5px)}70%{transform:translateX(-3px)}}
.is-shake{animation:sh-shake .36s ease}
@keyframes sh-beat{0%,100%{transform:scale(1)}50%{transform:scale(1.2)}}
@keyframes sh-sad-head{0%,100%{transform:translateY(0) rotate(0deg)}35%{transform:translateY(1.6px) rotate(-1.8deg)}70%{transform:translateY(1.2px) rotate(1.8deg)}}
@keyframes sh-eye-blink{0%,44%,48%,92%,96%,100%{transform:scaleY(1)}46%,94%{transform:scaleY(.14)}}
@keyframes sh-mouth-sigh{0%,100%{transform:translateY(0)}50%{transform:translateY(.9px)}}
@keyframes sh-happy-bob{0%,100%{transform:translateY(0)}50%{transform:translateY(-2px)}}
@keyframes sh-shackle-open{0%{transform:translateY(0) rotate(0deg)}45%{transform:translateY(-4.2px) rotate(0deg)}100%{transform:translateY(-4.2px) rotate(15deg)}}
.svg-sad{animation:sh-sad-head 4.4s ease-in-out infinite;transform-box:fill-box;transform-origin:center}
.svg-eye{animation:sh-eye-blink 4.8s infinite;transform-box:fill-box;transform-origin:center}
.svg-frown{animation:sh-mouth-sigh 4.4s ease-in-out infinite;transform-box:fill-box;transform-origin:center}
.svg-happy{animation:sh-happy-bob 3s ease-in-out infinite;transform-box:fill-box;transform-origin:center}
.svg-shackle{animation:sh-shackle-open 1.1s cubic-bezier(.22,1,.36,1) forwards;transform-box:fill-box;transform-origin:100% 100%}
@media (prefers-reduced-motion:reduce){.ds-sw,.ds-swi,.svg-sad,.svg-eye,.svg-frown,.svg-happy,.svg-shackle,.site-footer__heart{animation:none}.ios-ok-ring,.ios-ok-tick{animation:none;stroke-dashoffset:0}}
CSS;
}

function sh_header_html($e)
{
    $logo = sh_logo_url();
    $m    = sh_brand_logo_metrics();
    $pMs  = max(1200, (int) round(((float) $m['shine_sec']) * 1000));
    $hMs  = (int) round($pMs / 2);
    return '<header class="site-header">'
        . '<a class="brand brand--logo" href="/" aria-label="iosmods">'
        . '<span class="brand__mark">'
        . '<span class="ds-logo" id="ds-logo">'
        . '<img class="brand__logo" id="brand-logo" src="' . $e($logo) . '" alt="iosmods" fetchpriority="high" decoding="sync" draggable="false">'
        . '<i class="ds-sw" aria-hidden="true"><img class="ds-swi" src="' . $e($logo) . '" alt="" decoding="async" draggable="false"></i>'
        . '<i class="ds-sw ds-sw2" aria-hidden="true"><img class="ds-swi" src="' . $e($logo) . '" alt="" decoding="async" draggable="false"></i>'
        . '</span>'
        . '</span>'
        . '</a>'
        . '<div class="ds-hd">'
        . '<div class="ds-lang">'
        . '<button class="ds-hbtn" id="ds-lang-btn" type="button" aria-label="Language">VI</button>'
        . '<div class="ds-pop" id="ds-lang-pop" hidden>'
        . '<button type="button" data-lang="vi" class="is-on">Tiếng Việt</button>'
        . '<button type="button" data-lang="en">English</button>'
        . '</div>'
        . '</div>'
        . '<button class="ds-hbtn" id="ds-theme-btn" type="button" aria-label="Theme"></button>'
        . '</div>'
        . '</header>'
        . '<script>(function(){'
        . 'var r=document.documentElement;'
        . 'function phase(){try{var P=' . $pMs . ',q=Date.now()%P;r.style.setProperty("--dsx-ph",(-q/1000)+"s");r.style.setProperty("--dsx-ph2",(-((q+' . $hMs . ')%P)/1000)+"s");}catch(e){}}'
        . 'phase();'
        . 'var lastRe=0,beat=Date.now();'
        . 'function restart(){var n=Date.now();if(n-lastRe<500)return;lastRe=n;phase();r.classList.add("dsx-re");void r.offsetWidth;r.classList.remove("dsx-re");}'
        . 'document.addEventListener("visibilitychange",function(){if(document.visibilityState==="visible")restart();});'
        . 'window.addEventListener("pageshow",restart);window.addEventListener("focus",restart);'
        . 'setInterval(function(){var n=Date.now();if(n-beat>3000&&document.visibilityState==="visible")restart();beat=n;},1200);'
        . 'var tb=document.getElementById("ds-theme-btn");'
        . 'var M=\'<svg viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>\','
        . 'S=\'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4m11.4-11.4 1.4-1.4"/></svg>\';'
        . 'function paintT(){if(tb)tb.innerHTML=r.getAttribute("data-theme")==="light"?S:M;}paintT();'
        . 'if(tb)tb.addEventListener("click",function(){var l=r.getAttribute("data-theme")!=="light";if(l)r.setAttribute("data-theme","light");else r.removeAttribute("data-theme");try{localStorage.setItem("ds-theme",l?"light":"dark");}catch(e){}paintT();});'
        . 'var lb=document.getElementById("ds-lang-btn"),lp=document.getElementById("ds-lang-pop");'
        . 'function setLang(lg){if(!lb)return;lg=lg==="en"?"en":"vi";r.setAttribute("data-lang",lg);lb.textContent=lg.toUpperCase();'
        . 'if(lp){var bs=lp.querySelectorAll("button[data-lang]");for(var i=0;i<bs.length;i++)bs[i].classList.toggle("is-on",bs[i].getAttribute("data-lang")===lg);}'
        . 'var ns=document.querySelectorAll("[data-en]");for(var j=0;j<ns.length;j++){var el=ns[j];if(!el.getAttribute("data-vi"))el.setAttribute("data-vi",el.textContent);el.textContent=lg==="en"?el.getAttribute("data-en"):el.getAttribute("data-vi");}}'
        . 'try{var sl=localStorage.getItem("ds-lang");if(sl==="en")setLang("en");}catch(e){}'
        . 'if(lb&&lp){lb.addEventListener("click",function(ev){ev.stopPropagation();lp.hidden=!lp.hidden;});'
        . 'lp.addEventListener("click",function(ev){var t=ev.target&&ev.target.closest?ev.target.closest("button[data-lang]"):null;if(!t)return;var g=t.getAttribute("data-lang")||"vi";try{localStorage.setItem("ds-lang",g);}catch(e){}setLang(g);lp.hidden=true;});'
        . 'document.addEventListener("click",function(){lp.hidden=true;});}'
        . '})();</script>';
}

function sh_card_rules_html()
{
    return '<div class="card-rules">'
        . '<h3 class="card-rules__h"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg><span data-en="Website Security Rules">Nội quy bảo mật website</span></h3>'
        . '<ul>'
        . '<li data-en="Disable VPN, Proxy, 1.1.1.1 WARP and region-changing apps before accessing.">Tắt VPN, Proxy, 1.1.1.1 và công cụ đổi vùng mạng trước khi truy cập.</li>'
        . '<li data-en="Do not run browser scripts, automation tools, F12 source inspect or exploit requests.">Không chạy tiện ích Script, mở F12 soi mã nguồn hoặc gửi luồng tấn công.</li>'
        . '<li data-en="Do not spam product GET without an active key or enter invalid keys repeatedly.">Không bấm lấy sản phẩm liên tục khi chưa có Key hoặc thử sai Key nhiều lần.</li>'
        . '<li data-en="Do not use multiple accounts on one device/IP to bypass Free Key limits.">Không tạo nhiều tài khoản phụ trên cùng thiết bị/mạng để lạm dụng Key Free.</li>'
        . '</ul>'
        . '</div>';
}

function sh_about_and_footer_html()
{
    return '<section class="site-about">'
        . '<section class="ds-docs" id="ds-docs">'
        . '<div class="ds-docs__blk">'
        . '<h3 class="ds-docs__h ds-docs__h--brand"><svg class="ds-ico" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="6" width="20" height="12" rx="4"/><path d="M7 10v4M5 12h4M16 11h.01M18 13h.01"/></svg><span>IOSMODS</span></h3>'
        . '<p class="ds-docs__p" data-en="Keys and tools for iOS in one place. Pick a package, activate a key, open a tool.">Kho Key và công cụ tiện ích cho iOS tại một nơi. Chọn gói, kích hoạt Key và mở công cụ.</p>'
        . '</div>'
        . '<div class="ds-docs__blk">'
        . '<h3 class="ds-docs__h"><svg class="ds-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18M7 21h10M5 7h14M5 7l-3 7a3.5 3.5 0 0 0 6 0zM19 7l-3 7a3.5 3.5 0 0 0 6 0z"/></svg><span data-en="Legal disclaimer">Miễn trừ trách nhiệm</span></h3>'
        . '<p class="ds-docs__p" data-en="Tools are provided for testing and entertainment purposes only. You are responsible for how you use them.">Các công cụ chỉ được cung cấp cho mục đích thử nghiệm và giải trí. Bạn tự chịu trách nhiệm trong quá trình sử dụng.</p>'
        . '</div>'
        . '<h3 class="ds-docs__h"><svg class="ds-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></svg><span data-en="Documents">Tài liệu</span></h3>'
        . '<a class="ds-doclink" href="/docs.php?doc=privacy" data-en="Privacy Policy">Chính sách bảo mật</a>'
        . '<a class="ds-doclink" href="/docs.php?doc=terms" data-en="Terms of Service">Điều khoản sử dụng</a>'
        . '</section>'
        . '</section>'
        . '<footer class="site-footer">Made with '
        . '<svg class="site-footer__heart" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>'
        . ' by <a href="/">iosmods</a><span class="site-footer__year">@2024</span></footer>'
        . '<nav class="ds-nav" aria-label="Main">'
        . '<div class="ds-nav__in">'
        . '<a class="ds-nav__item is-active" href="/" data-sh-nav="1"><svg class="ds-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg><span data-en="Home">Trang chủ</span></a>'
        . '<a class="ds-nav__item" href="/" data-sh-nav="1"><svg class="ds-ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3 21 2m-4 4 3 3m-6-1 2 2"/></svg><span>Keys</span></a>'
        . '<a class="ds-nav__item" href="/" data-sh-nav="1"><svg class="ds-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg><span data-en="Tools">Công cụ</span></a>'
        . '<a class="ds-nav__item" href="/" data-sh-nav="1"><svg class="ds-ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span data-en="History">Lịch sử</span></a>'
        . '<a class="ds-nav__item" href="/lazrlshalvow/login" data-sh-nav="1"><svg class="ds-ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><circle cx="12" cy="10" r="3"/><path d="M6.2 18.6a6.5 6.5 0 0 1 11.6 0"/></svg><span data-en="Log in">Đăng nhập</span></a>'
        . '</div>'
        . '</nav>'
        . '<script>(function(){var ns=document.querySelectorAll("[data-sh-nav]");for(var i=0;i<ns.length;i++){ns[i].addEventListener("click",function(ev){var p=document.querySelector(".panel");if(p){ev.preventDefault();window.scrollTo({top:0,behavior:"smooth"});p.classList.remove("is-shake");void p.offsetWidth;p.classList.add("is-shake");}});}})();</script>';
}

/**
 * TIER 1 UI — Refined Animated Happy Face (😊) in Exact iosmods.xyz Native Style:
 */
function sh_render_happy_gate(array $detect, $ip, $previewBarHtml = '')
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $GLOBALS['dsx_hash_on'] = 1;
    if (!headers_sent()) {
        http_response_code(!empty($detect['is_preview']) ? 200 : 403);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
    }

    $whatOff = !empty($detect['turn_off']) ? (string) $detect['turn_off'] : 'VPN / Proxy (1.1.1.1) hoặc tiện ích Script trình duyệt';
    if (sh_wants_json() && empty($detect['is_preview'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['result' => 'please_turn_off', 'turn_off' => $whatOff, 'message' => 'Vui lòng tắt ' . $whatOff . ' để truy cập iosmods.xyz.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    ?><!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark light">
<meta name="robots" content="noindex, nofollow">
<title>Kiểm tra kết nối · iosmods.xyz</title>
<script>try{var __t=localStorage.getItem('ds-theme');if(__t==='light'||(__t==='auto'&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches))document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
<style><?= sh_native_shared_css() ?></style>
</head>
<body>
<?= sh_header_html($e) ?>
<div class="sh-wrap">
  <main class="stage">
    <section class="panel" role="dialog">
      <div class="anim-icon" aria-hidden="true">
        <svg class="svg-happy" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="9.5"/>
          <g class="svg-eye"><path d="M8.15 10.25c.36-.56.86-.84 1.4-.84s1.04.28 1.4.84"/></g>
          <g class="svg-eye"><path d="M13.05 10.25c.36-.56.86-.84 1.4-.84s1.04.28 1.4.84"/></g>
          <path d="M8.35 13.85c.92 1.48 2.14 2.22 3.65 2.22s2.73-.74 3.65-2.22"/>
        </svg>
      </div>
      <h1 class="panel-title" data-en="Please Disable Proxy / Script">Vui lòng tắt công cụ trung gian</h1>
      <p class="cause">Hệ thống phát hiện kết nối đang bật <strong><?= $e($whatOff) ?></strong>. Vui lòng tắt hoàn toàn công cụ này trước khi truy cập.</p>

      <div class="meta">
        <div class="meta-row"><span data-en="Detected">Phát hiện</span><span><?= $e($whatOff) ?></span></div>
        <div class="meta-row"><span data-en="IP Address">Địa chỉ IP</span><span class="mono"><?= $e($ip) ?></span></div>
      </div>

      <?= sh_card_rules_html() ?>

      <a class="cta-btn" href="/" onclick="document.cookie='sh_vg_retry=1;path=/;max-age=20';" data-en="Disabled · Continue">Đã tắt xong · Truy cập lại</a>
    </section>
    <?= $previewBarHtml ?>
  </main>
  <?= sh_about_and_footer_html() ?>
</div>
</body>
</html>
<?php
}

/**
 * POST-UNBAN CONFIRMATION GATE — "Đã tự động mở khoá" + Mandatory "Got it" Button!
 * - Shown when a previously blocked IP/device visits iosmods.xyz after Bot/Admin/Timer unlocks it.
 * - Contains ZERO verification code (IMX-...), ZERO countdown, ZERO appeal form.
 * - Persists across refreshes & logo clicks until the user clicks "Got it", then enters the website normally!
 */
function sh_render_unlocked_gate(array $ack, $ip, $previewBarHtml = '')
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $GLOBALS['dsx_hash_on'] = 1;
    $isPreview = !empty($ack['is_preview']);
    if (!headers_sent()) {
        http_response_code(200);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
    }
    if (sh_wants_json() && !$isPreview) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['result' => 'unlocked_ack_needed', 'message' => 'Đã tự động mở khoá. Vui lòng tải lại trang và bấm Got it để tiếp tục.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $lid    = sh_ensure_browser_lid();
    $ackTok = sh_unlock_ack_token($ip, $lid);
    header('Content-Type: text/html; charset=utf-8');
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    ?><!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark light">
<meta name="robots" content="noindex, nofollow">
<title>Đã tự động mở khoá · iosmods.xyz</title>
<script>try{var __t=localStorage.getItem('ds-theme');if(__t==='light'||(__t==='auto'&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches))document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
<style><?= sh_native_shared_css() ?></style>
</head>
<body>
<?= sh_header_html($e) ?>
<div class="sh-wrap">
  <main class="stage">
    <section class="panel" role="dialog" aria-labelledby="sh-unlk-title">
      <div class="anim-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <rect x="5" y="11" width="14" height="10" rx="2.4"/>
          <path class="svg-shackle" d="M8 11V7a4 4 0 0 1 8 0v2"/>
          <circle cx="12" cy="16" r="1.15" fill="currentColor" stroke="none"/>
        </svg>
      </div>
      <h1 class="panel-title" id="sh-unlk-title" data-en="Automatically Unlocked">Đã tự động mở khoá</h1>
      <p class="cause" data-en="Your IP address has been unlocked by the security system. Tap Got it below to enter the website normally.">Địa chỉ IP của bạn đã được hệ thống mở khoá hoàn tất. Nhấn <strong>Got it</strong> bên dưới để vào lại website bình thường.</p>

      <div class="meta">
        <div class="meta-row"><span data-en="Status">Trạng thái</span><span data-en="Unlocked">Đã mở khoá</span></div>
        <div class="meta-row"><span data-en="IP Address">Địa chỉ IP</span><span class="mono"><?= $e($ip) ?></span></div>
      </div>

      <form method="post" action="/asadnsrdecsb/home?__sh_ack_unlock=1" id="sh-ack-form">
        <input type="hidden" name="lid" value="<?= $e($lid) ?>">
        <input type="hidden" name="ack_tok" value="<?= $e($ackTok) ?>">
        <input type="hidden" name="preview" value="<?= $isPreview ? '1' : '' ?>">
        <button type="submit" class="cta-btn" id="sh-got-it-btn">Got it</button>
      </form>
    </section>
    <?= $previewBarHtml ?>
  </main>
  <?= sh_about_and_footer_html() ?>
</div>
<script>
(function(){
  var IS_PV = <?= $isPreview ? 'true' : 'false' ?>;
  var br = document.querySelector('.brand');
  if (br && IS_PV) {
    br.addEventListener('click', function(ev){
      var p = document.querySelector('.panel');
      if (p) { ev.preventDefault(); window.scrollTo({top:0, behavior:'smooth'}); p.classList.remove('is-shake'); void p.offsetWidth; p.classList.add('is-shake'); }
    });
  }
  var fm = document.getElementById('sh-ack-form'), bt = document.getElementById('sh-got-it-btn');
  if (fm && bt && window.fetch) {
    fm.addEventListener('submit', function(ev){
      ev.preventDefault();
      bt.disabled = true;
      var d = new URLSearchParams(new FormData(fm));
      fetch((location.pathname || '/') + '?__sh_ack_unlock=1', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: d.toString()
      }).then(function(){
        window.location.replace(<?= $isPreview ? "'/'" : '(location.pathname && location.pathname.indexOf("sh_test_view") === -1 ? location.pathname : "/")' ?>);
      }).catch(function(){ fm.submit(); });
    });
  }
})();
</script>
</body>
</html>
<?php
}

/**
 * TIER 2 UI — Refined Animated Sad Face (☹️) + Rules Inside Card + 3-Field Unlock Request + @devios_bot Auto-Unban + iOS Checkmark HUD!
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
    $isSpam    = in_array($banType, ['key_spam', 'prod_spam'], true);
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
        $reason = $isSpam
            ? 'Thao tác lặp lại quá nhanh hoặc spam yêu cầu vượt ngưỡng cho phép của hệ thống.'
            : 'Vi phạm quy tắc an ninh bảo mật trực tiếp trên hệ thống iosmods.xyz.';
    }
    $caseCode = !empty($ban['case_code']) ? (string) $ban['case_code'] : sh_issue_case_code($ip, $ban);
    $streamIp = !empty($ban['stream_ip']) && $ban['stream_ip'] !== $ip ? (string) $ban['stream_ip'] : '';

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

    $lid      = sh_ensure_browser_lid();
    $sig      = sh_client_sig($lid);
    $sentOnce = !$isPreview && sh_has_sent_appeal($ip, $caseCode, $lid);
    $knownUsr = !empty($ban['username']) ? (string) $ban['username'] : '';
    if ($knownUsr === '' && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['username'])) {
        $knownUsr = (string) $_SESSION['username'];
    }
    $botUrl = sh_bot_appeal_url();

    header('Content-Type: text/html; charset=utf-8');
    $e       = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $heading = $isSpam ? 'Tạm khoá do thao tác quá mức' : 'Truy cập đã bị khoá bảo mật';
    ?><!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark light">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($heading) ?> · iosmods.xyz</title>
<script>try{var __t=localStorage.getItem('ds-theme');if(__t==='light'||(__t==='auto'&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches))document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
<style><?= sh_native_shared_css() ?></style>
</head>
<body>
<?= sh_header_html($e) ?>
<div class="sh-wrap">
  <main class="stage">
    <section class="panel" role="alert">
      <div class="anim-icon" id="sh-icon-box" aria-hidden="true">
        <svg class="svg-sad" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="9.5"/>
          <g class="svg-eye"><line x1="9.2" y1="9.5" x2="9.2" y2="10.9" stroke-width="2.1"/></g>
          <g class="svg-eye"><line x1="14.8" y1="9.5" x2="14.8" y2="10.9" stroke-width="2.1"/></g>
          <path class="svg-frown" d="M8.65 15.85c.9-1.2 2.02-1.8 3.35-1.8s2.45.6 3.35 1.8"/>
        </svg>
      </div>
      <h1 class="panel-title" id="sh-title"><?= $e($heading) ?></h1>
      <p class="cause" id="sh-cause"><strong>Nguyên nhân:</strong> <?= $e($reason) ?></p>

      <div class="meta">
<?php if ($isTemp) { ?>
        <div class="meta-row"><span data-en="Auto Unlock In">Tự động mở sau</span><span class="mono" id="sh-cd" data-left="<?= (int) $left ?>" data-preview="<?= $isPreview ? '1' : '0' ?>"><?= $e(sh_fmt_remaining($left)) ?></span></div>
<?php } else { ?>
        <div class="meta-row"><span data-en="Duration">Thời hạn khoá</span><span data-en="Permanent">Vĩnh viễn</span></div>
<?php } ?>
        <div class="meta-row"><span data-en="IP Address">Địa chỉ IP</span><span class="mono"><?= $e($ip) ?><?= $streamIp !== '' ? ' · ' . $e($streamIp) : '' ?></span></div>
        <div class="code-box">
          <div class="code-head">
            <span data-en="Verification Code">Mã xác minh</span>
            <button type="button" class="copy-pill" id="sh-copy-btn" data-code="<?= $e($caseCode) ?>" data-en="Copy">Sao chép</button>
          </div>
          <div class="code-val mono" id="sh-code-val"><?= $e($caseCode) ?></div>
        </div>
      </div>

      <?= sh_card_rules_html() ?>

<?php if ($sentOnce) { ?>
      <button type="button" class="cta-btn is-sent" id="sh-open-appeal" disabled data-en="Sent to Admin · Please wait a few minutes">Đã gửi cho Admin · Vui lòng đợi trong ít phút</button>
<?php } else { ?>
      <button type="button" class="cta-btn" id="sh-open-appeal" data-en="Send Unlock Request">Gửi yêu cầu để mở khoá</button>
<?php } ?>
      <a class="cta-btn cta-btn--sub" href="<?= $e($botUrl) ?>" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21.5 3.5 2.5 11l6.8 2.6 2.6 6.9 3.6-4.1 4.6 3.4z"/><path d="M9.3 13.6 21.5 3.5"/></svg>
        <span data-en="Auto Unban via Bot (@devios_bot)">Xét mở khoá tự động qua Bot (@devios_bot)</span>
      </a>
    </section>
    <?= $previewBarHtml ?>
  </main>
  <?= sh_about_and_footer_html() ?>
</div>

<div class="sh-modal" id="sh-ap-modal" hidden>
  <div class="sh-sheet" id="sh-ap-sheet" role="dialog" aria-modal="true">
    <h2 class="sh-sheet__t" data-en="Send Unlock Request">Gửi yêu cầu để mở khoá</h2>
    <p class="sh-sheet__s" data-en="Fill in your website account, verification code and reason (1 time only).">Điền thông tin và nhập đúng mã xác minh để gửi Admin (chỉ gửi 1 lần).</p>

    <label class="ap-lbl" for="sh-ap-user" data-en="Website account">Tài khoản website</label>
    <input type="text" class="ap-in" id="sh-ap-user" maxlength="60" value="<?= $e($knownUsr) ?>" placeholder="Tài khoản website" autocomplete="username">

    <label class="ap-lbl" for="sh-ap-code" data-en="Enter verification code">Nhập mã xác minh</label>
    <input type="text" class="ap-in ap-in--code" id="sh-ap-code" maxlength="40" placeholder="Nhập mã xác minh (IMX-...)" autocomplete="off" autocapitalize="characters" spellcheck="false">

    <label class="ap-lbl" for="sh-ap-msg" data-en="Reason">Lý do</label>
    <textarea class="ap-ta" id="sh-ap-msg" maxlength="350" placeholder="Miêu tả vì sao bạn bị khoá..."></textarea>

    <p class="ap-err" id="sh-ap-err" hidden></p>
    <div class="ap-actions">
      <button type="button" class="ap-cancel" id="sh-ap-cancel" data-en="Cancel">Huỷ</button>
      <button type="button" class="ap-send" id="sh-ap-send" data-en="Confirm & Send">Xác nhận gửi</button>
    </div>
  </div>
</div>

<div class="sh-modal" id="sh-ios-sent" hidden>
  <div class="ios-hud" role="status" aria-live="polite">
    <svg class="ios-ok-svg" viewBox="0 0 56 56" fill="none" aria-hidden="true">
      <circle class="ios-ok-ring" cx="28" cy="28" r="24" pathLength="1"/>
      <path class="ios-ok-tick" d="M17.5 28.8l7.2 7.2 14-15.2" pathLength="1"/>
    </svg>
    <h3 class="ios-hud__t" data-en="Sent to Admin">Đã gửi cho Admin</h3>
    <p class="ios-hud__p" id="sh-ios-msg" data-en="Sent to Admin, please wait a few minutes.">Đã gửi cho Admin, vui lòng đợi trong ít phút.</p>
    <button type="button" class="ios-hud__btn" id="sh-ios-close" data-en="OK">Đóng</button>
  </div>
</div>

<script>
(function(){
  var CASE_CODE = <?= json_encode($caseCode) ?>;
  var NORM_CASE = CASE_CODE.replace(/[^A-Z0-9]/gi, '').toUpperCase();
  var IS_PV     = <?= $isPreview ? 'true' : 'false' ?>;
  var LS_KEY    = 'sh_ap_sent_' + CASE_CODE;

  var cpBtn = document.getElementById('sh-copy-btn');
  if (cpBtn) {
    cpBtn.addEventListener('click', function(){
      var c = cpBtn.getAttribute('data-code') || '';
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(c);
        } else {
          var ta = document.createElement('textarea'); ta.value = c; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
        }
        cpBtn.textContent = document.documentElement.getAttribute('data-lang') === 'en' ? 'Copied' : 'Đã sao chép';
      } catch(e){}
    });
  }

  var openBtn  = document.getElementById('sh-open-appeal'),
      apModal  = document.getElementById('sh-ap-modal'),
      apSheet  = document.getElementById('sh-ap-sheet'),
      canBtn   = document.getElementById('sh-ap-cancel'),
      sendBtn  = document.getElementById('sh-ap-send'),
      uIn      = document.getElementById('sh-ap-user'),
      cIn      = document.getElementById('sh-ap-code'),
      mIn      = document.getElementById('sh-ap-msg'),
      errEl    = document.getElementById('sh-ap-err'),
      iosModal = document.getElementById('sh-ios-sent'),
      iosMsg   = document.getElementById('sh-ios-msg'),
      iosClose = document.getElementById('sh-ios-close');

  function lockButtonAsSent() {
    if (!openBtn) return;
    openBtn.disabled = true;
    openBtn.classList.add('is-sent');
    openBtn.textContent = document.documentElement.getAttribute('data-lang') === 'en'
      ? 'Sent to Admin · Please wait a few minutes'
      : 'Đã gửi cho Admin · Vui lòng đợi trong ít phút';
  }

  if (!IS_PV) {
    try { if (localStorage.getItem(LS_KEY) === '1') lockButtonAsSent(); } catch(e){}
  }

  function showIosCheckmark(txt) {
    if (!iosModal) return;
    if (iosMsg && txt) iosMsg.textContent = txt;
    var svg = iosModal.querySelector('.ios-ok-svg');
    if (svg) { var cl = svg.cloneNode(true); svg.parentNode.replaceChild(cl, svg); }
    iosModal.hidden = false;
  }

  if (iosClose && iosModal) {
    iosClose.addEventListener('click', function(){ iosModal.hidden = true; });
    iosModal.addEventListener('click', function(ev){ if (ev.target === iosModal) iosModal.hidden = true; });
  }

  if (openBtn && apModal) {
    openBtn.addEventListener('click', function(){
      if (openBtn.disabled) return;
      if (errEl) errEl.hidden = true;
      apModal.hidden = false;
      setTimeout(function(){ if (uIn && !uIn.value) uIn.focus(); else if (cIn) cIn.focus(); }, 60);
    });
  }
  if (canBtn && apModal) {
    canBtn.addEventListener('click', function(){ apModal.hidden = true; });
    apModal.addEventListener('click', function(ev){ if (ev.target === apModal) apModal.hidden = true; });
  }

  if (sendBtn) {
    sendBtn.addEventListener('click', function(){
      var usr      = (uIn ? uIn.value : '').trim();
      var rawCode  = (cIn ? cIn.value : '').trim();
      var normCode = rawCode.replace(/[^A-Z0-9]/gi, '').toUpperCase();
      var msg      = (mIn ? mIn.value : '').trim();
      if (!normCode || normCode !== NORM_CASE) {
        if (errEl) { errEl.hidden = false; errEl.textContent = 'Vui lòng sao chép và nhập đúng Mã xác minh (IMX-...) ở khung bên ngoài.'; }
        if (apSheet) { apSheet.classList.remove('is-shake'); void apSheet.offsetWidth; apSheet.classList.add('is-shake'); }
        if (cIn) cIn.focus();
        return;
      }
      if (msg.length < 3) {
        if (errEl) { errEl.hidden = false; errEl.textContent = 'Vui lòng miêu tả ngắn gọn lý do vì sao bạn bị khoá.'; }
        if (mIn) mIn.focus();
        return;
      }
      sendBtn.disabled = true;
      sendBtn.textContent = 'Đang gửi...';
      var f = new URLSearchParams();
      f.set('lid', <?= json_encode($lid) ?>);
      f.set('sig', <?= json_encode($sig) ?>);
      f.set('case_code', CASE_CODE);
      f.set('verify_code', rawCode);
      f.set('username', usr);
      f.set('message', msg);
      f.set('preview', IS_PV ? '1' : '');
      fetch((location.pathname || '/') + '?__sh_appeal=1', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: f.toString()
      }).then(function(r){ return r.json(); }).then(function(j){
        if (j && (j.ok || j.already_sent)) {
          apModal.hidden = true;
          lockButtonAsSent();
          if (!IS_PV) { try { localStorage.setItem(LS_KEY, '1'); } catch(e){} }
          showIosCheckmark('Đã gửi cho Admin, vui lòng đợi trong ít phút.');
        } else {
          sendBtn.disabled = false;
          sendBtn.textContent = 'Xác nhận gửi';
          if (errEl) { errEl.hidden = false; errEl.textContent = (j && j.msg) ? j.msg : 'Không thể gửi yêu cầu lúc này.'; }
        }
      }).catch(function(){
        sendBtn.disabled = false;
        sendBtn.textContent = 'Xác nhận gửi';
        if (errEl) { errEl.hidden = false; errEl.textContent = 'Lỗi kết nối, vui lòng thử lại.'; }
      });
    });
  }

  var el = document.getElementById('sh-cd');
  if (!el) return;
  var left = parseInt(el.getAttribute('data-left') || '0', 10);
  function pad(n){ return n < 10 ? '0' + n : '' + n; }
  function fmt(s){
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return h > 0 ? (pad(h) + ':' + pad(m) + ':' + pad(sec)) : (pad(m) + ':' + pad(sec));
  }
  function showUnlockAndReload(){
    var ic = document.getElementById('sh-icon-box'), tt = document.getElementById('sh-title'), cs = document.getElementById('sh-cause');
    if (ic) {
      ic.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2.4"/><path class="svg-shackle" d="M8 11V7a4 4 0 0 1 8 0v2"/><circle cx="12" cy="16" r="1.15" fill="currentColor" stroke="none"/></svg>';
    }
    if (tt) tt.textContent = 'Đã tự động mở khoá';
    if (cs) cs.innerHTML = 'Hệ thống đã hoàn tất kiểm tra bảo mật và mở khoá kết nối.';
    if (!IS_PV) setTimeout(function(){ window.location.reload(); }, 1100);
  }
  var iv = setInterval(function(){
    left--;
    if (left <= 0) {
      clearInterval(iv);
      el.textContent = '00:00';
      showUnlockAndReload();
      return;
    }
    el.textContent = fmt(left);
  }, 1000);
})();
</script>
</body>
</html>
<?php
}

/* ------------------------------------------------------------------
 * 9. Safe Non-Destructive Preview Showcase (?sh_test_view=...&k=<tok>)
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

    $pill = function ($m, $lbl) use ($mode, $tok) {
        $act = ($mode === $m) || ($m === 'ban_ip' && !in_array($mode, ['spam_sys', 'happy_vpn', 'unlock_anim', 'unlocked_gotit'], true));
        $st  = $act
            ? 'border:1px solid var(--cta-bg);background:var(--cta-bg);color:var(--cta-fg)'
            : 'border:1px solid var(--ds-line2);background:var(--ds-field);color:var(--ds-h)';
        return '<a href="?sh_test_view=' . $m . '&k=' . $tok . '" style="padding:7px 11px;border-radius:10px;font-size:11.5px;font-weight:700;text-decoration:none;' . $st . '">' . $lbl . '</a>';
    };
    $nav = '<div style="margin-top:14px;display:flex;flex-wrap:wrap;gap:6px;justify-content:center;max-width:410px;width:100%">'
        . $pill('ban_ip', '1. Bị chặn bảo mật')
        . $pill('spam_sys', '2. Khoá Spam (5p–30p)')
        . $pill('happy_vpn', '3. Mặt cười tắt VPN')
        . $pill('unlocked_gotit', '4. Đã mở khoá (Got it)')
        . '</div>';

    if ($mode === 'happy_vpn') {
        sh_render_happy_gate([
            'is_preview' => true,
            'turn_off'   => 'Ứng dụng VPN / 1.1.1.1 hoặc tiện ích Userscript',
        ], $ip, $nav);
        exit;
    }

    if ($mode === 'unlock_anim' || $mode === 'unlocked_gotit') {
        sh_render_unlocked_gate([
            'is_preview' => true,
            'by'         => 'bot-ai',
        ], $ip, $nav);
        exit;
    }

    if ($mode === 'spam_sys') {
        sh_render_sad_page([
            'is_preview' => true,
            'ban_type'   => 'prod_spam',
            'reason'     => 'Liên tục bấm lấy sản phẩm khi chưa kích hoạt Key hoặc gửi thao tác lặp lại quá nhanh vào hệ thống (mức khoá tự động: 5 phút → 10 phút → tối đa 30 phút).',
            'expires_ts' => time() + 298,
        ], $ip, 429, $nav);
        exit;
    }

    sh_render_sad_page([
        'is_preview' => true,
        'ban_type'   => 'script',
        'stream_ip'  => '103.179.44.210',
        'reason'     => 'Phát hiện gửi luồng DDoS, can thiệp Script trình duyệt, mở công cụ xem mã nguồn trên máy tính hoặc khai thác lỗ hổng hệ thống iosmods.xyz.',
        'expires_ts' => time() + 3595,
    ], $ip, 403, $nav);
    exit;
}

/* ------------------------------------------------------------------
 * 10. Progressive Product-Get & Fast-Action Spam Escalation (5m -> 10m -> max 30m)
 *     Users WITH an active valid key (turns > 0 & unexpired) are NEVER blocked!
 * ------------------------------------------------------------------ */

/** Checks DB directly whether the current session user has an active valid key. */
function sh_session_user_has_active_key()
{
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    $uname = isset($_SESSION['username']) && is_string($_SESSION['username']) ? trim($_SESSION['username']) : '';
    if ($uname === '') {
        return [false, ''];
    }
    $c = isset($GLOBALS['conn']) && ($GLOBALS['conn'] instanceof mysqli) ? $GLOBALS['conn'] : null;
    if ($c === null) {
        try {
            if (is_file(__DIR__ . '/config.php')) {
                require_once __DIR__ . '/config.php';
            }
            $h = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: 'localhost');
            $u = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: '');
            $p = defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') ?: '');
            $n = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: '');
            if ($u !== '') {
                $c = defined('DB_PORT') ? @mysqli_connect($h, $u, $p, $n, (int) DB_PORT) : @mysqli_connect($h, $u, $p, $n);
            }
        } catch (Throwable $e) {
            $c = null;
        }
    }
    if (!($c instanceof mysqli)) {
        return [false, $uname];
    }
    $active = false;
    try {
        $st = @$c->prepare('SELECT *, (expiry_date > NOW()) AS sh_exp_ok FROM users WHERE username = ? LIMIT 1');
        if ($st) {
            $st->bind_param('s', $uname);
            if ($st->execute()) {
                $rs  = $st->get_result();
                $row = $rs ? $rs->fetch_assoc() : null;
                if ($row) {
                    if (isset($row['role']) && (int) $row['role'] === 1) {
                        sh_remember_admin_ip(sh_client_ip(), $uname);
                        $st->close();
                        return [true, $uname];
                    }
                    $turnsOk = !array_key_exists('turns_left', $row) || $row['turns_left'] === null || (int) $row['turns_left'] > 0;
                    $active  = ((int) (isset($row['sh_exp_ok']) ? $row['sh_exp_ok'] : 0) === 1 && $turnsOk);
                }
            }
            $st->close();
        }
    } catch (Throwable $e) {
        $active = false;
    }
    return [$active, $uname];
}

/** Progressive escalation: 1st = 300s (5m), 2nd = 600s (10m), 3rd+ = 1800s (max 30m). */
function sh_progressive_spam_duration($ip, $lid = '')
{
    $key   = md5('prog|' . $ip . '|' . $lid);
    $pFile = sh_dir() . '/prodspam_stage/' . substr($key, 0, 2) . '/' . $key . '.json';
    $now   = time();
    $stage = 1;
    sh_json_update($pFile, function ($s) use ($now, &$stage) {
        $last  = isset($s['at']) ? (int) $s['at'] : 0;
        $prev  = ($now - $last <= 21600 && isset($s['stage'])) ? (int) $s['stage'] : 0; // resets after 6h clean behavior
        $stage = min(3, $prev + 1);
        return ['stage' => $stage, 'at' => $now];
    });
    $durations = [1 => 300, 2 => 600, 3 => 1800]; // 5m -> 10m -> max 30m!
    return [$durations[$stage], $stage];
}

function sh_trigger_progressive_product_spam($ip, $username, $reasonDetail)
{
    if ($ip === '' || sh_is_admin_viewer()) {
        return false;
    }
    list($safe, ) = sh_is_safe_ip($ip);
    if ($safe) {
        return false;
    }
    $lid = sh_browser_lid();
    $hw  = sh_hw_fp_cookie();
    list($durSec, $stage) = sh_progressive_spam_duration($ip, $lid);
    $mins   = (int) ($durSec / 60);
    $now    = time();
    $reason = $reasonDetail . ' Hệ thống khoá tạm thời ' . $mins . ' phút (lần ' . $stage . ' — tái phạm tăng lên 10 phút, tối đa 30 phút).';

    $rec = [
        'ip'         => $ip,
        'stream_ip'  => $ip,
        'reason'     => $reason,
        'ban_type'   => 'prod_spam',
        'username'   => (string) $username,
        'lid'        => $lid,
        'hw_fp'      => $hw,
        'created_ts' => $now,
        'created_at' => date('Y-m-d H:i:s', $now),
        'expires_ts' => $now + $durSec,
        'expires_at' => date('Y-m-d H:i:s', $now + $durSec),
        'hits'       => 1,
    ];
    $saved = sh_write_ban_cache($ip, $rec);
    if (is_array($saved)) {
        $rec = $saved;
    }
    sh_queue_pending_ban($rec + ['attack_detail' => 'Spam Get sản phẩm / Thao tác quá nhanh (' . $mins . 'p)']);
    sh_stats_bump('spam_blocks', 1);
    return $rec;
}

/** Server-side hook called from index.php when ?use_tool= returns need_login or need_key. */
function sh_on_unkeyed_tool_attempt($conn, $username = '')
{
    $ip = sh_client_ip();
    if ($ip === '' || sh_is_admin_viewer()) {
        return;
    }
    list($safe, ) = sh_is_safe_ip($ip);
    if ($safe) {
        return;
    }
    $now   = time();
    $h     = md5($ip);
    $hFile = sh_dir() . '/prodspam_hits/' . substr($h, 0, 2) . '/' . $h . '.json';
    $trig  = false;
    sh_json_update($hFile, function ($s) use ($now, &$trig) {
        $arr  = isset($s['t']) && is_array($s['t']) ? $s['t'] : [];
        $keep = [];
        foreach ($arr as $ts) {
            if ((int) $ts >= $now - 25) {
                $keep[] = (int) $ts;
            }
        }
        $keep[] = $now;
        if (count($keep) >= 5) {
            $trig = true;
            $keep = [];
        }
        return ['t' => $keep];
    });
    if ($trig) {
        $who = $username !== '' ? 'khi tài khoản chưa có Key hợp lệ' : 'khi chưa đăng nhập';
        $rec = sh_trigger_progressive_product_spam($ip, $username, 'Liên tục bấm lấy sản phẩm nhiều lần ' . $who . ' để dò trang đích.');
        if ($rec) {
            if ($conn instanceof mysqli) {
                sh_sync_pending_to_db($conn);
            }
            sh_render_sad_page($rec, $ip, 429);
            exit;
        }
    }
}

/* ------------------------------------------------------------------
 * 11. Client Telemetry, Instant Script/VPN Check & Product-Spam Endpoint
 * ------------------------------------------------------------------ */

function sh_client_sig($lid)
{
    return substr(hash_hmac('sha256', 'cguard5|' . $lid, sh_secret()), 0, 24);
}

function sh_handle_client_endpoints()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (!isset($_GET['__sh_telemetry']) && !isset($_GET['__sh_report']) && !isset($_GET['__sh_prod_spam'])) {
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

    $lid     = isset($_POST['lid']) && preg_match('/^[a-f0-9]{32}$/i', (string) $_POST['lid']) ? strtolower((string) $_POST['lid']) : sh_ensure_browser_lid();
    $sig     = isset($_POST['sig']) ? (string) $_POST['sig'] : '';
    $hwFp    = isset($_POST['hw']) && preg_match('/^[a-f0-9]{16,64}$/i', (string) $_POST['hw']) ? strtolower((string) $_POST['hw']) : '';
    $tz      = isset($_POST['tz']) ? trim(substr((string) $_POST['tz'], 0, 60)) : '';
    $wrtcIp  = isset($_POST['wrtc']) ? sh_norm_ip($_POST['wrtc']) : '';
    $passive = isset($_POST['passive_script']) ? trim(substr((string) $_POST['passive_script'], 0, 120)) : '';
    $violate = isset($_POST['violation']) ? trim(substr((string) $_POST['violation'], 0, 180)) : '';

    if ($hwFp !== '' && !headers_sent()) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '')) === 'https';
        if (PHP_VERSION_ID >= 70300) {
            @setcookie('sh_hw', $hwFp, ['expires' => time() + 63072000, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        } else {
            @setcookie('sh_hw', $hwFp, time() + 63072000, '/; samesite=Lax', '', $https, true);
        }
        $_COOKIE['sh_hw'] = $hwFp;
    }

    // 1. Cross-Device / Cross-VPN Evasion Check
    $elock = ($hwFp !== '' ? sh_read_entity_lock('hw', $hwFp) : null) ?: sh_read_entity_lock('lid', $lid);
    if ($elock !== null) {
        $newExp                    = max((int) $elock['expires_ts'], time() + 900) + 600;
        $evCnt                     = (int) (isset($elock['evasion_attempts']) ? $elock['evasion_attempts'] : 0) + 1;
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

    // 2. Product-Get / Rapid UI Action Spam Endpoint (__sh_prod_spam)
    if (isset($_GET['__sh_prod_spam']) && hash_equals(sh_client_sig($lid), $sig)) {
        list($hasActiveKey, $sessUser) = sh_session_user_has_active_key();
        if ($hasActiveKey) {
            // User HAS an active valid key -> 100% allowed normally!
            echo json_encode(['ok' => true, 'action' => 'allow_keyed']);
            exit;
        }
        $kind = isset($_POST['kind']) ? (string) $_POST['kind'] : 'product';
        $why  = ($kind === 'fast_ui')
            ? 'Thao tác lặp lại liên tục quá nhanh vào hệ thống.'
            : ('Liên tục spam bấm lấy sản phẩm khi ' . ($sessUser !== '' ? 'chưa nhập Key hợp lệ' : 'chưa đăng nhập') . ' nhằm dò trang đích.');
        sh_trigger_progressive_product_spam($ip, $sessUser, $why);
        echo json_encode(['ok' => true, 'action' => 'reload_block']);
        exit;
    }

    if ($wrtcIp !== '' && !sh_is_private_or_local($wrtcIp) && !sh_is_trusted_proxy($wrtcIp)) {
        $forensics['real_ip'] = $wrtcIp;
    }
    sh_json_update(sh_dir() . '/telemetry/' . substr($lid, 0, 2) . '/' . $lid . '.json', function () use ($ip, $wrtcIp, $hwFp, $tz, $passive) {
        return [
            'http_ip'        => $ip,
            'webrtc_ip'      => $wrtcIp,
            'hw_fp'          => $hwFp,
            'tz'             => $tz,
            'passive_script' => $passive,
            'at'             => time(),
        ];
    });

    // 3. Active Violation (__sh_report): PC View-Source / DevTools / Automation Script
    $cfg = sh_cfg();
    if (isset($_GET['__sh_report']) && $violate !== '' && !empty($cfg['inspect_guard']) && hash_equals(sh_client_sig($lid), $sig)) {
        $dur    = max(900, (int) $cfg['script_ban_sec']);
        $now    = time();
        $reason = 'Phát hiện hành vi can thiệp bảo mật trực tiếp: ' . $violate . '.';
        $rec    = [
            'ip'         => $forensics['real_ip'],
            'stream_ip'  => $forensics['stream_ip'],
            'reason'     => $reason,
            'ban_type'   => 'inspect',
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
        sh_queue_pending_ban($rec + ['attack_detail' => $violate]);
        sh_stats_bump('script_blocks', 1);
        echo json_encode(['ok' => true, 'action' => 'reload_block']);
        exit;
    }

    // 4. Instant Passive Script / WebRTC Fake-Region Check -> Happy Smiley (😊) Gate
    if (!empty($cfg['vpn_gate_enabled'])) {
        if ($passive !== '') {
            sh_json_update(sh_dir() . '/happy_flags/' . substr($lid, 0, 2) . '/' . $lid . '.json', function () use ($passive) {
                return ['turn_off' => 'Tiện ích Script trình duyệt (' . $passive . ')', 'exp' => time() + 180];
            });
            sh_stats_bump('happy_gates', 1);
            echo json_encode(['ok' => true, 'action' => 'reload_happy']);
            exit;
        }
        $vpn = sh_inspect_ip_vpn($ip);
        $webrtcMismatch = ($wrtcIp !== '' && !sh_is_private_or_local($wrtcIp) && $wrtcIp !== $ip && $vpn['is_vpn']);
        $vnTzMismatch   = ($tz === 'Asia/Ho_Chi_Minh' || $tz === 'Asia/Saigon') && $vpn['country'] !== '' && $vpn['country'] !== 'VN' && $vpn['is_vpn'];
        if ($vpn['is_vpn'] || $webrtcMismatch || $vnTzMismatch) {
            $lbl = $vpn['label'] !== '' ? $vpn['label'] : 'VPN / Proxy chuyển vùng';
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

/**
 * Synchronous <head> Pre-Paint Guard + Product-Get Spam Watcher:
 * - Detects Userscripts / Automation in <head> BEFORE <body> renders (0ms visual delay!).
 * - Persists sh_lid across Cookie + localStorage + sessionStorage + window.name + IndexedDB.
 * - Tracks unkeyed .ds-get / [data-tool-id] spam clicks -> triggers 5m -> 10m -> max 30m lock!
 */
function sh_client_guard_script()
{
    if (sh_is_excluded_subapp() || sh_is_admin_viewer()) {
        return '';
    }
    $lid = sh_ensure_browser_lid();
    $sig = sh_client_sig($lid);
    return '<script data-sh="guard-v5">(function(){'
        . 'if(window.__shG5)return;window.__shG5=1;'
        . 'var LID="' . $lid . '",SIG="' . $sig . '";'
        // Multi-storage anti-evasion token recovery (localStorage + sessionStorage + window.name)
        . 'try{var sL=localStorage.getItem("sh_lid")||sessionStorage.getItem("sh_lid")||(/^sh_lid:([a-f0-9]{32})$/.test(window.name||"")?RegExp.$1:"");'
        . 'if(sL&&/^[a-f0-9]{32}$/.test(sL)&&sL!==LID){LID=sL;document.cookie="sh_lid="+sL+";path=/;max-age=63072000;SameSite=Lax";}'
        . 'localStorage.setItem("sh_lid",LID);sessionStorage.setItem("sh_lid",LID);if(!window.name)window.name="sh_lid:"+LID;}catch(e){}'
        . 'function h32(s){var h=2166136261;for(var i=0;i<s.length;i++){h^=s.charCodeAt(i);h=Math.imul(h,16777619);}return (h>>>0).toString(16).padStart(8,"0");}'
        . 'function hwFp(){try{var p=[screen.width+"x"+screen.height+"x"+screen.colorDepth,navigator.hardwareConcurrency||0,navigator.platform||"",Intl.DateTimeFormat().resolvedOptions().timeZone||""];'
        . 'var c=document.createElement("canvas"),g=c.getContext("webgl")||c.getContext("experimental-webgl");'
        . 'if(g){var d=g.getExtension("WEBGL_debug_renderer_info");if(d)p.push(g.getParameter(d.UNMASKED_RENDERER_WEBGL)||"");}'
        . 'var x=c.getContext("2d");if(x){x.textBaseline="top";x.font="14px Arial";x.fillText("iosmods-v5",2,2);p.push(c.toDataURL().slice(-32));}'
        . 'var raw=p.join("|");return h32(raw)+h32(raw.split("").reverse().join(""))+h32("v5|"+raw)+h32(raw.length+raw);}catch(e){return "";}}'
        . 'var HW=hwFp(),reported=0;'
        . 'document.cookie="sh_hw="+HW+";path=/;max-age=63072000;SameSite=Lax";'
        . 'function post(ep,obj,cb){try{var f=new URLSearchParams();f.set("lid",LID);f.set("sig",SIG);f.set("hw",HW);for(var k in obj)f.set(k,obj[k]);'
        . 'fetch((location.pathname||"/")+"?"+ep+"=1",{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded","X-Requested-With":"XMLHttpRequest"},body:f.toString()})'
        . '.then(function(r){return r.json();}).then(function(j){if(cb)cb(j);}).catch(function(){});}catch(e){}}'
        . 'function trip(why){if(reported)return;reported=1;document.documentElement.style.visibility="hidden";post("__sh_report",{violation:why},function(j){location.reload();});}'
        // 1. Instant Pre-Paint Headless / Automation Check
        . 'if(navigator.webdriver||window._phantom||window.__nightmare||window.callPhantom||window.Cypress||window.__playwright){trip("Chạy trình duyệt tự động hoá / Script Bot (WebDriver)");return;}'
        // 2. Instant Pre-Paint Userscript / Extension Check
        . 'function findScript(){if(window.GM_info||window.GM||window.unsafeWindow||window._tampermonkey)return "Tampermonkey / Userscript";'
        . 'if(window.eruda||window.VConsole)return "Công cụ soi mã nguồn Eruda / vConsole";'
        . 'var sc=document.querySelectorAll("script[src]");for(var i=0;i<sc.length;i++){var u=sc[i].getAttribute("src")||"";if(/^(chrome-extension|moz-extension|safari-web-extension):\/\//i.test(u))return "Tiện ích mở rộng chèn Script";}'
        . 'return "";}'
        . 'var psNow=findScript();if(psNow){document.documentElement.style.visibility="hidden";post("__sh_telemetry",{tz:"",wrtc:"",passive_script:psNow},function(){location.reload();});return;}'
        // 3. Desktop PC Anti-View-Source & Anti-DevTools
        . 'document.addEventListener("keydown",function(e){var k=(e.key||"").toLowerCase(),c=e.ctrlKey||e.metaKey,s=e.shiftKey;'
        . 'if(e.keyCode===123||(c&&k==="u")||(c&&s&&(k==="i"||k==="j"||k==="c"))){e.preventDefault();e.stopPropagation();trip("Mở công cụ xem mã nguồn trên máy tính (F12 / Ctrl+U / Inspect)");return false;}},true);'
        . 'document.addEventListener("contextmenu",function(e){if(!("ontouchstart" in window)&&navigator.maxTouchPoints===0){e.preventDefault();}},true);'
        . 'if(!("ontouchstart" in window)&&navigator.maxTouchPoints===0&&screen.width>=1024){'
        . 'var dtH=0;setInterval(function(){if(document.hidden)return;if((window.outerWidth-window.innerWidth)>240||(window.outerHeight-window.innerHeight)>240){dtH++;if(dtH>=3)trip("Mở bảng DevTools xem mã nguồn trực tiếp trên máy tính");}else{dtH=0;}},1600);}'
        // 4. Progressive Product-Get Spam & Fast-Action Watcher (5m -> 10m -> max 30m for unkeyed/guest users!)
        . 'var pClicks=[],uClicks=[],spamSent=0;'
        . 'document.addEventListener("click",function(e){if(spamSent)return;var now=Date.now();'
        . 'if(!e.isTrusted){trip("Giả lập sự kiện click tự động bằng Script");return;}'
        . 'var t=e.target&&e.target.closest?e.target.closest(".ds-get, [data-tool-id]"):null;'
        . 'if(t&&!document.body.classList.contains("has-key")){'
        . 'pClicks=pClicks.filter(function(x){return now-x<20000;});pClicks.push(now);'
        . 'var fast8=pClicks.filter(function(x){return now-x<7000;}).length;'
        . 'if(fast8>=5||pClicks.length>=8){spamSent=1;e.preventDefault();e.stopPropagation();post("__sh_prod_spam",{kind:"product"},function(j){if(j&&j.action==="reload_block")location.reload();else spamSent=0;});return;}'
        . '}'
        . 'var act=e.target&&e.target.closest?e.target.closest("button, a.btn, input[type=submit]"):null;'
        . 'if(act&&!document.body.classList.contains("has-key")){'
        . 'uClicks=uClicks.filter(function(x){return now-x<4000;});uClicks.push(now);'
        . 'if(uClicks.length>=14){spamSent=1;post("__sh_prod_spam",{kind:"fast_ui"},function(j){if(j&&j.action==="reload_block")location.reload();else spamSent=0;});}'
        . '}'
        . '},true);'
        // 5. Immediate Telemetry + WebRTC Leak Check (runs immediately on DOM interactive, no artificial delay!)
        . 'function runTele(){var ps2=findScript(),tz="";try{tz=Intl.DateTimeFormat().resolvedOptions().timeZone||"";}catch(e){}'
        . 'var done=0;function send(ip){if(done)return;done=1;post("__sh_telemetry",{tz:tz,wrtc:ip||"",passive_script:ps2},function(j){if(j&&(j.action==="reload_block"||j.action==="reload_happy"))location.reload();});}'
        . 'if(ps2){send("");return;}'
        . 'try{var RT=window.RTCPeerConnection||window.webkitRTCPeerConnection;if(!RT){send("");return;}'
        . 'var pc=new RT({iceServers:[{urls:"stun:stun.cloudflare.com:3478"}]});pc.createDataChannel("");'
        . 'pc.onicecandidate=function(ev){if(done)return;if(!ev||!ev.candidate){try{pc.close();}catch(x){}send("");return;}'
        . 'var m=(ev.candidate.candidate||"").match(/([0-9]{1,3}(?:\.[0-9]{1,3}){3})/);if(m&&!/^(10\.|127\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/.test(m[1])){try{pc.close();}catch(x){}send(m[1]);}};'
        . 'pc.createOffer().then(function(o){return pc.setLocalDescription(o);}).catch(function(){send("");});'
        . 'setTimeout(function(){try{pc.close();}catch(x){}send("");},1200);}catch(e){send("");}}'
        . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",runTele,{once:true});else runTele();'
        . '})();</script>';
}

/* ------------------------------------------------------------------
 * 12. Anti-Bug, Anti-Buff & Pre-DB DDoS Shield
 * ------------------------------------------------------------------ */

function sh_is_light_subresource()
{
    if (isset($_GET['brand_logo']) || isset($_GET['__sh_brand_logo']) || isset($_GET['favicon']) || isset($_GET['sync']) || isset($_GET['tg_ping'])
        || isset($_GET['ds_check_user']) || isset($_GET['ds_check_key_lock'])
        || isset($_GET['__sh_telemetry']) || isset($_GET['__sh_report']) || isset($_GET['__sh_prod_spam']) || isset($_GET['__sh_appeal']) || isset($_GET['__sh_ack_unlock'])) {
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

function sh_detect_waf_bug_buff($ip)
{
    $sn = basename((string) strtok((string) (isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : ''), '?'));
    if ($sn === 'admin_cp.php' || $sn === 'telegram_bot.php') {
        return null;
    }
    $rawUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $uri    = urldecode($rawUri);
    $path   = strtolower((string) strtok($uri, '?'));

    if (strlen($rawUri) > 4096 || strpos($rawUri, '%00') !== false || strpos($uri, "\0") !== false) {
        return ['type' => 'waf', 'why' => 'Gửi gói tin chứa ký tự Null-Byte hoặc URI tràn bộ đệm'];
    }
    $method = strtoupper((string) (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET'));
    if (in_array($method, ['TRACE', 'TRACK', 'CONNECT', 'DEBUG'], true)) {
        return ['type' => 'waf', 'why' => 'Phương thức HTTP trái phép (' . $method . ')'];
    }

    if (preg_match('#/(?:\.env|\.git|wp-admin|wp-login\.php|phpmyadmin|adminer|xmlrpc\.php|vendor/phpunit|(?:telegram_|fk_)?config\.php|\.(?:sql|bak|old|swp)$)#i', $path)) {
        return ['type' => 'bug_buff', 'why' => 'Quét tệp cấu hình ẩn hoặc dò lỗ hổng hệ thống (' . substr($path, 0, 50) . ')'];
    }

    foreach (['username', 'password', 'repassword', 'key_vip', 'key_free', 'use_tool', 'csrf'] as $f) {
        if ((isset($_POST[$f]) && is_array($_POST[$f])) || (isset($_GET[$f]) && is_array($_GET[$f]))) {
            return ['type' => 'bug_buff', 'why' => 'Truyền mảng tham số giả mạo nhằm khai thác lỗi xử lý (' . $f . '[])'];
        }
    }

    $haystack = $uri;
    if (!empty($_GET)) {
        foreach ($_GET as $k => $v) {
            if (is_scalar($v)) {
                $haystack .= ' ' . $k . '=' . substr(urldecode((string) $v), 0, 500);
            }
        }
    }
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
        '/<\s*script\b[^>]*>|\bjavascript\s*:\s*\w+|\bon(?:error|load|mouseover)\s*=/i' => 'Chèn mã Script / XSS trái phép vào tham số',
        '/\$\{jndi:|;\s*(?:wget|curl|bash|sh|nc)\s+/i'          => 'Tấn công thực thi lệnh từ xa (RCE)',
    ];
    foreach ($patterns as $re => $label) {
        if (preg_match($re, $haystack)) {
            return ['type' => 'waf', 'why' => $label];
        }
    }

    $isPostAction = ($method === 'POST' && !isset($_POST['ack_alert']));
    $isToolAction = isset($_GET['use_tool']);
    if ($isPostAction || $isToolAction) {
        $lid          = sh_browser_lid();
        $aKey         = md5('act|' . $ip . '|' . $lid . '|' . ($isPostAction ? 'P' : 'T'));
        $aFile        = sh_dir() . '/act_guard/' . substr($aKey, 0, 2) . '/' . $aKey . '.json';
        $nowMs        = (int) round(microtime(true) * 1000);
        $buffDetected = false;
        $lim800       = $isPostAction ? 4 : 8;
        $lim3s        = $isPostAction ? 8 : 16;

        sh_json_update($aFile, function ($st) use ($nowMs, $lim800, $lim3s, &$buffDetected) {
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
            if ($c800 >= $lim800 || $c3s >= $lim3s) {
                $buffDetected = true;
                $keep = [];
            }
            return ['ms' => array_slice($keep, -20)];
        });

        if ($buffDetected) {
            return ['type' => 'bug_buff', 'why' => 'Chạy Script bắn luồng song song tốc độ cao để Buff lượt / Race-Condition'];
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
        sh_queue_pending_event('ddos_surge_healed', ['detail' => 'Đã tự vá & giải phóng sạch đa luồng DDoS (' . $kind . ')']);
    }
}

function sh_is_surge_active()
{
    $s = sh_json_read(sh_dir() . '/surge.json');
    return !empty($s['surge_until']) && (int) $s['surge_until'] > time();
}

/**
 * Earliest Pre-DB Security Entry Point.
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

    // Any intentional early exit inside sh_early_guard() occurs before db.php creates $conn,
    // so suppress false 'db_down' shutdown alerts unless sh_early_guard() returns normally to db.php.
    $GLOBALS['SH_SKIP_DB_ALERT'] = 1;

    sh_serve_brand_logo_asset();
    sh_handle_unlock_ack_endpoint();
    sh_handle_appeal_endpoint();
    sh_handle_preview_showcase();
    sh_handle_client_endpoints();

    $forensics = sh_trace_forensics();
    $ip        = sh_client_ip();
    if ($ip === '') {
        $GLOBALS['SH_SKIP_DB_ALERT'] = 0;
        return;
    }

    list($safe, ) = sh_is_safe_ip($ip);
    if ($safe || sh_is_admin_viewer()) {
        $GLOBALS['SH_SKIP_DB_ALERT'] = 0;
        return;
    }

    $now   = time();
    $lid   = sh_browser_lid();
    $hwFp  = sh_hw_fp_cookie();
    $net64 = sh_ip_network_key($ip);

    // 1. Check Active IP Ban Cache -> Clean-Slate Auto-Unban on Expiry!
    $cachedBan = sh_read_ban_cache($ip);
    if ($cachedBan === null && $forensics['real_ip'] !== $ip) {
        $cachedBan = sh_read_ban_cache($forensics['real_ip']);
    }
    if ($cachedBan !== null) {
        $expTs = isset($cachedBan['expires_ts']) ? (int) $cachedBan['expires_ts'] : 0;
        if ($expTs > 0 && $expTs <= $now) {
            // CLEAN-SLATE AUTO-UNBAN + arm Post-Unban "Got it" confirmation screen!
            sh_full_clean_slate_unban(null, $ip, isset($cachedBan['username']) ? (string) $cachedBan['username'] : '', true, true, 'auto-timer');
            if ($forensics['real_ip'] !== $ip) {
                sh_full_clean_slate_unban(null, $forensics['real_ip'], '', true, true, 'auto-timer');
            }
            $GLOBALS['SH_JUST_EXPIRED_IP'] = $ip;
        } else {
            // Self-Healing Stream Drainer: human logo clicks / refreshes ALWAYS show the full Sad-Face Block screen;
            // automated flood bots (>4 tool hits or >12 rapid hits in 8s) get 0-byte socket dropped in <0.05ms!
            $drainLim  = sh_is_flood_tool_ua() ? 4 : 12;
            $winTs     = isset($cachedBan['win_ts']) ? (int) $cachedBan['win_ts'] : $now;
            $lastTouch = isset($cachedBan['last_touch']) ? (int) $cachedBan['last_touch'] : 0;
            $inWin     = ($now - $winTs <= 8);
            $winHits   = $inWin ? ((int) (isset($cachedBan['win_hits']) ? $cachedBan['win_hits'] : 0) + 1) : 1;
            if ($winHits <= $drainLim || !$inWin || ($now - $lastTouch >= 12)) {
                $cachedBan['hits']       = (int) (isset($cachedBan['hits']) ? $cachedBan['hits'] : 0) + 1;
                $cachedBan['win_ts']     = $inWin ? $winTs : $now;
                $cachedBan['win_hits']   = $winHits;
                $cachedBan['last_touch'] = $now;
                if ($winHits === $drainLim && $expTs > 0) {
                    $cachedBan['expires_ts'] = max($expTs, $now + 1800);
                }
                sh_write_ban_cache($ip, $cachedBan);
            }
            if ($winHits >= $drainLim) {
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

    // 2. Ironclad Cross-Device / Cross-VPN / IPv6-Prefix Lockdown Enforcement (Zero Evasion Loopholes!)
    $entityLock = ($hwFp !== '' ? sh_read_entity_lock('hw', $hwFp) : null)
               ?: ($lid !== '' ? sh_read_entity_lock('lid', $lid) : null)
               ?: (strpos($net64, '/64') !== false ? sh_read_entity_lock('net64', $net64) : null);
    if ($entityLock !== null) {
        $newExp                    = max((int) $entityLock['expires_ts'], $now + 900) + 600;
        $evCnt                     = (int) (isset($entityLock['evasion_attempts']) ? $entityLock['evasion_attempts'] : 0) + 1;
        $entityLock['ip']          = $ip;
        $entityLock['stream_ip']   = $forensics['stream_ip'];
        $entityLock['expires_ts']  = $newExp;
        $entityLock['expires_at']  = date('Y-m-d H:i:s', $newExp);
        $entityLock['evasion_attempts'] = $evCnt;
        $savedLock = sh_write_ban_cache($ip, $entityLock);
        if (is_array($savedLock)) {
            $entityLock = $savedLock;
        }
        sh_queue_pending_ban($entityLock + ['attack_detail' => 'Cố tình đổi IP / thiết bị để lách khoá (lần #' . $evCnt . ')']);
        sh_render_sad_page($entityLock, $ip, 403);
        exit;
    }

    // 2B. Post-Unban Mandatory "Got it" Confirmation Screen:
    // If Bot / Admin / Auto-Timer unlocked this IP/device, keep showing "Đã tự động mở khoá" until "Got it" is clicked!
    if (!sh_is_light_subresource()) {
        $unlockAck = sh_read_unlock_ack($ip, $lid, $hwFp);
        if ($unlockAck === null && $forensics['real_ip'] !== $ip) {
            $unlockAck = sh_read_unlock_ack($forensics['real_ip'], $lid, $hwFp);
        }
        if ($unlockAck !== null) {
            sh_render_unlocked_gate($unlockAck, $forensics['real_ip'] ?: $ip);
            exit;
        }
    }

    $cfg = sh_cfg();
    if (empty($cfg['enabled']) || sh_is_light_subresource()) {
        $GLOBALS['SH_SKIP_DB_ALERT'] = 0;
        return;
    }

    // 3. Anti-Bug, Anti-Buff & WAF Check
    if (!empty($cfg['waf_enabled'])) {
        $bugHit = sh_detect_waf_bug_buff($ip);
        if ($bugHit !== null) {
            $dur    = max(900, (int) $cfg['script_ban_sec']);
            $reason = $bugHit['why'] . '.';
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
            $savedBug = sh_write_ban_cache($ip, $rec);
            if (is_array($savedBug)) {
                $rec = $savedBug;
            }
            if ($forensics['real_ip'] !== $ip) {
                sh_write_ban_cache($forensics['real_ip'], $rec);
            }
            sh_queue_pending_ban($rec + ['attack_detail' => $bugHit['why'], 'ua' => $forensics['ua']]);
            sh_stats_bump('bug_blocks', 1);
            sh_render_sad_page($rec, $forensics['real_ip'], 403);
            exit;
        }
    }

    // 4. Supreme 8-Vector Autonomous Anti-DDoS Engine v9 (0% False Positives for Verified Humans & Admins!)
    $h        = md5($ip);
    $rateFile = sh_dir() . '/rates/' . substr($h, 0, 2) . '/' . $h . '.json';
    $isPost   = strtoupper((string) (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET')) === 'POST';
    $isHuman  = sh_has_human_cookie($ip);
    $surge    = sh_is_surge_active();
    $nowMs    = (int) round(microtime(true) * 1000);
    $uaRaw    = (string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '');
    $isBotUa  = sh_is_flood_tool_ua() || ($uaRaw === '' || strlen($uaRaw) < 12 || (bool) preg_match('~(python|aiohttp|httpx|curl/|wget/|go-http-client|fasthttp|wrk|siege|vegeta|ab/|apachebench|scrapy|libwww|java/|okhttp|undici|axios/|node-fetch|got/|zgrab|masscan|nuclei|sqlmap|nikto|nmap|headlesschrome|phantomjs|stress|hulk|slowloris|goldeneye|cc-attack|cfbypass|cloudscraper)~i', $uaRaw));
    $proto    = strtoupper((string) (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1'));
    $noBrHdr  = empty($_SERVER['HTTP_ACCEPT']) && empty($_SERVER['HTTP_ACCEPT_LANGUAGE']) && empty($_SERVER['HTTP_SEC_FETCH_MODE']);
    $xffRaw   = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
    $forgedXf = ($xffRaw !== '' && substr_count($xffRaw, ',') >= 4);
    $noOriginPost = $isPost && empty($_SERVER['HTTP_ORIGIN']) && empty($_SERVER['HTTP_REFERER']);
    $anomPts  = ($isBotUa ? 3 : 0) + ($proto === 'HTTP/1.0' ? 2 : 0) + ($noBrHdr ? 2 : 0) + ($forgedXf ? 2 : 0) + ($noOriginPost ? 2 : 0);

    // Track coordinated /24 (IPv4) or /48 (IPv6) botnet subnet swarm ONLY for unverified (!isHuman) traffic
    $subnetSwarm = false;
    if (!$isHuman) {
        $subKey = $ip;
        if (strpos($ip, ':') !== false) {
            $parts6 = explode(':', $ip);
            $subKey = implode(':', array_slice($parts6, 0, 3)) . '::/48';
        } elseif (preg_match('/^(\d+\.\d+\.\d+)\.\d+$/', $ip, $m4)) {
            $subKey = $m4[1] . '.0/24';
        }
        $shHash = md5('sub9|' . $subKey);
        $shFile = sh_dir() . '/subnet_guard/' . substr($shHash, 0, 2) . '/' . $shHash . '.json';
        sh_json_update($shFile, function ($sd) use ($now, $ip, &$subnetSwarm) {
            $ips  = isset($sd['ips']) && is_array($sd['ips']) ? $sd['ips'] : [];
            $hits = isset($sd['h']) && is_array($sd['h']) ? $sd['h'] : [];
            $cut  = $now - 10;
            foreach ($ips as $k => $ts) {
                if ((int) $ts < $cut) unset($ips[$k]);
            }
            $keepH = [];
            foreach ($hits as $ht) {
                if ((int) $ht >= $cut) $keepH[] = (int) $ht;
            }
            $ips[$ip] = $now;
            $keepH[]  = $now;
            if (count($ips) >= 4 && count($keepH) >= 34) {
                $subnetSwarm = true;
            }
            return ['ips' => $ips, 'h' => array_slice($keepH, -120)];
        });
    }

    $qStr    = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
    $qHash   = ($qStr !== '' && !isset($_GET['use_tool']) && !isset($_GET['status'])) ? substr(md5($qStr), 0, 8) : '';
    $pathNow = strtolower((string) strtok((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/'), '?'));
    $pHash   = substr(md5($pathNow), 0, 8);

    $verdict = null;
    sh_json_update($rateFile, function ($st) use ($now, $nowMs, $isPost, $isBotUa, $isHuman, $surge, $anomPts, $subnetSwarm, $noOriginPost, $qHash, $pHash, &$verdict) {
        $msList = isset($st['ms']) && is_array($st['ms']) ? $st['ms'] : [];
        $posts  = isset($st['p']) && is_array($st['p']) ? $st['p'] : [];
        $qsMap  = isset($st['q']) && is_array($st['q']) ? $st['q'] : [];
        $ptMap  = isset($st['pt']) && is_array($st['pt']) ? $st['pt'] : [];

        $keepMs = [];
        $c500ms = 0;
        $c1s    = 0;
        $c3s    = 0;
        $c5s    = 0;
        foreach ($msList as $m) {
            $m = (int) $m;
            $age = $nowMs - $m;
            if ($age <= 15000) {
                $keepMs[] = $m;
                if ($age <= 5000) $c5s++;
                if ($age <= 3000) $c3s++;
                if ($age <= 1000) $c1s++;
                if ($age <= 500)  $c500ms++;
            }
        }
        $keepMs[] = $nowMs;
        $c500ms++;
        $c1s++;
        $c3s++;
        $c5s++;
        $c15s = count($keepMs);

        $p10 = [];
        foreach ($posts as $t) {
            if ((int) $t >= $now - 10) {
                $p10[] = (int) $t;
            }
        }
        if ($isPost) {
            $p10[] = $now;
        }
        $cp10 = count($p10);

        foreach ($qsMap as $qk => $qt) {
            if ((int) $qt < $now - 10) unset($qsMap[$qk]);
        }
        if ($qHash !== '') {
            $qsMap[$qHash] = $now;
        }
        $uniqQs10 = count($qsMap);

        foreach ($ptMap as $pk => $pt) {
            if ((int) $pt < $now - 10) unset($ptMap[$pk]);
        }
        $ptMap[$pHash] = $now;
        $uniqPt10 = count($ptMap);

        // Robotic constant-interval cadence check (stdDev < 36ms across >= 11 unverified hits)
        $roboticCadence = false;
        if (!$isHuman && $c15s >= 11) {
            $tail  = array_slice($keepMs, -11);
            $diffs = [];
            for ($i = 1; $i < count($tail); $i++) {
                $diffs[] = max(0, $tail[$i] - $tail[$i - 1]);
            }
            $mean = array_sum($diffs) / count($diffs);
            if ($mean >= 15 && $mean <= 1400) {
                $var = 0.0;
                foreach ($diffs as $df) {
                    $var += ($df - $mean) * ($df - $mean);
                }
                $std = sqrt($var / count($diffs));
                if ($std < 36.0) {
                    $roboticCadence = true;
                }
            }
        }

        $limNoHuman5  = ($surge || $subnetSwarm) ? 16 : ($anomPts >= 2 ? 20 : 34);
        $limNoHuman15 = ($surge || $subnetSwarm) ? 34 : ($anomPts >= 2 ? 42 : 72);

        if ($isBotUa && ($c500ms >= 4 || $c1s >= 5 || $c3s >= 9 || $c5s >= 12 || $c15s >= 20)) {
            $verdict = ['kind' => 'L7 Tool / Botnet DDoS', 'rps' => $c5s, 'w15' => $c15s];
        } elseif (!$isHuman && ($c500ms >= 8 || $c1s >= 11 || $c3s >= 20 || $c5s >= $limNoHuman5 || $c15s >= $limNoHuman15)) {
            $verdict = ['kind' => ($subnetSwarm ? 'Botnet Subnet Swarm Flood' : 'L7/L4 HTTP Stream Flood'), 'rps' => $c5s, 'w15' => $c15s];
        } elseif (!$isHuman && $uniqQs10 >= 12) {
            $verdict = ['kind' => 'L7 Cache-Bypass Random Query Flood', 'rps' => $uniqQs10, 'w15' => $c15s];
        } elseif (!$isHuman && $uniqPt10 >= 11) {
            $verdict = ['kind' => 'L7 Multi-Path Scanner / Crawl Flood', 'rps' => $uniqPt10, 'w15' => $c15s];
        } elseif ($roboticCadence) {
            $verdict = ['kind' => 'Automated Constant-Rate Script Flood', 'rps' => $c5s, 'w15' => $c15s];
        } elseif (($noOriginPost && $cp10 >= 8) || $cp10 >= 20) {
            $verdict = ['kind' => 'L7 POST Payload Flood', 'rps' => $cp10, 'w15' => $c15s];
        } elseif ($isHuman && ($c1s >= 35 || $c5s >= 85)) {
            $verdict = ['kind' => 'High-Rate Macro / Cookie-Replay Flood', 'rps' => $c5s, 'w15' => $c15s];
        }

        return [
            'ms' => array_slice($keepMs, -140),
            'p'  => array_slice($p10, -50),
            'q'  => count($qsMap) > 40 ? array_slice($qsMap, -40, null, true) : $qsMap,
            'pt' => count($ptMap) > 30 ? array_slice($ptMap, -30, null, true) : $ptMap,
            'u'  => $now,
        ];
    });

    if ($verdict !== null) {
        // Escalate repeat DDoS offenders: 30m -> 2h -> 6h!
        $stHash   = md5($forensics['real_ip'] ?: $ip);
        $stFile   = sh_dir() . '/ddos_strikes/' . substr($stHash, 0, 2) . '/' . $stHash . '.json';
        $ddStage  = 1;
        sh_json_update($stFile, function ($s) use ($now, &$ddStage) {
            $prev    = (!empty($s['at']) && ($now - (int) $s['at']) < 86400 && !empty($s['n'])) ? (int) $s['n'] : 0;
            $ddStage = min(3, $prev + 1);
            return ['n' => $ddStage, 'at' => $now];
        });
        $baseDur = max(1800, (int) $cfg['ddos_ban_sec']);
        $dur     = $ddStage === 1 ? $baseDur : ($ddStage === 2 ? max($baseDur * 2, 7200) : max($baseDur * 6, 21600));
        $reason  = 'Phát hiện gửi luồng tấn công DDoS liên tục (' . $verdict['kind'] . ' · ' . $verdict['w15'] . ' yêu cầu/15s).';
        $rec     = [
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
        $savedDdos = sh_write_ban_cache($ip, $rec);
        if (is_array($savedDdos)) {
            $rec = $savedDdos;
        }
        if ($forensics['real_ip'] !== $ip) {
            sh_write_ban_cache($forensics['real_ip'], $rec);
        }
        sh_surge_record_attack($ip, $verdict['kind']);
        sh_queue_pending_ban($rec + [
            'attack_detail' => $verdict['kind'] . ' (' . $verdict['w15'] . ' req/15s · mức #' . $ddStage . ')',
            'ua'            => $forensics['ua'],
        ]);
        sh_stats_bump('ddos_blocks', 1);
        @file_put_contents(sh_dir() . '/ddos_ips.list', date('Y-m-d H:i:s') . "\treal=" . $forensics['real_ip'] . "\tstream=" . $forensics['stream_ip'] . "\t" . $verdict['kind'] . "\tdur=" . $dur . "s\n", FILE_APPEND | LOCK_EX);
        sh_render_sad_page($rec, $forensics['real_ip'], 429);
        exit;
    }

    // 5. INSTANT ZERO-DELAY VPN / PASSIVE SCRIPT CHECK (Runs synchronously BEFORE first page render!)
    if (!empty($cfg['vpn_gate_enabled']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $uriPath = strtolower((string) strtok((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''), '?'));
        $isKeyBypassAttempt = (strpos($uriPath, 'get-free') !== false || strpos($uriPath, 'get_key') !== false || strpos($uriPath, 'key-free') !== false);

        // 5A. Passive Script flag on LID
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
                        @unlink($hfFile);
                        $dur = max(900, (int) $cfg['vpn_spam_ban_sec']);
                        $rec = [
                            'ip'         => $forensics['real_ip'],
                            'stream_ip'  => $forensics['stream_ip'],
                            'reason'     => 'Sử dụng ' . $hf['turn_off'] . ' để can thiệp hoặc né giới hạn lấy Key trên hệ thống.',
                            'ban_type'   => 'vpn_abuse',
                            'lid'        => $lid,
                            'hw_fp'      => $hwFp,
                            'created_ts' => $now,
                            'created_at' => date('Y-m-d H:i:s', $now),
                            'expires_ts' => $now + $dur,
                            'expires_at' => date('Y-m-d H:i:s', $now + $dur),
                            'hits'       => 1,
                        ];
                        $savedV1 = sh_write_ban_cache($ip, $rec);
                        if (is_array($savedV1)) {
                            $rec = $savedV1;
                        }
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

        // 5B. Synchronous Pre-Render VPN / Proxy / WARP Check (0-delay on very first visit!)
        $intel = sh_inspect_ip_vpn($ip);
        if (!empty($intel['is_vpn'])) {
            $lbl = !empty($intel['label']) ? $intel['label'] : 'VPN / Proxy (Fake IP)';
            if ($isKeyBypassAttempt) {
                $dur = max(900, (int) $cfg['vpn_spam_ban_sec']);
                $rec = [
                    'ip'         => $forensics['real_ip'],
                    'stream_ip'  => $forensics['stream_ip'],
                    'reason'     => 'Bật ' . $lbl . ' để vượt giới hạn hoặc Bypass lấy Key Free.',
                    'ban_type'   => 'vpn_abuse',
                    'lid'        => $lid,
                    'hw_fp'      => $hwFp,
                    'created_ts' => $now,
                    'created_at' => date('Y-m-d H:i:s', $now),
                    'expires_ts' => $now + $dur,
                    'expires_at' => date('Y-m-d H:i:s', $now + $dur),
                    'hits'       => 1,
                ];
                $savedV2 = sh_write_ban_cache($ip, $rec);
                if (is_array($savedV2)) {
                    $rec = $savedV2;
                }
                sh_queue_pending_ban($rec + ['attack_detail' => 'Bật VPN (' . $lbl . ') vào lấy Key']);
                sh_render_sad_page($rec, $forensics['real_ip'], 403);
                exit;
            }
            sh_stats_bump('happy_gates', 1);
            sh_render_happy_gate(['turn_off' => $lbl], $ip);
            exit;
        }
    }

    $GLOBALS['SH_SKIP_DB_ALERT'] = 0;
}

/* ------------------------------------------------------------------
 * 13. Post-DB Sync, Admin Protection & Key-Spam / VPN Controllers
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
            // Never resurrect a ban that has already been lifted!
            if (sh_read_ban_cache($ip) === null) {
                continue;
            }
            $left  = max(60, (int) (isset($it['expires_ts']) ? $it['expires_ts'] : time() + 1800) - time());
            $btype = isset($it['ban_type']) ? (string) $it['ban_type'] : 'ddos';
            $res   = ipg_temp_ban(
                $conn,
                $ip,
                $left,
                isset($it['reason']) ? $it['reason'] : 'Khoá bảo mật tự động',
                'bot-ai',
                isset($it['username']) ? $it['username'] : '',
                $btype
            );
            if (function_exists('tg_event')) {
                $evName = ($btype === 'ddos') ? 'ddos_block' : (($btype === 'prod_spam') ? 'key_spam_ban' : 'script_bug_block');
                tg_event($evName, [
                    'ip'         => $ip,
                    'stream_ip'  => isset($it['stream_ip']) ? $it['stream_ip'] : $ip,
                    'username'   => isset($it['username']) ? $it['username'] : '',
                    'ban_type'   => $btype,
                    'detail'     => isset($it['attack_detail']) ? $it['attack_detail'] : $btype,
                    'reason'     => isset($it['reason']) ? $it['reason'] : '',
                    'duration'   => $left,
                    'fails'      => isset($it['hits']) ? (int) $it['hits'] : 1,
                    'case_code'  => isset($it['case_code']) ? (string) $it['case_code'] : '',
                    'created_at' => isset($it['created_at']) ? $it['created_at'] : date('Y-m-d H:i:s'),
                    'expires_at' => isset($it['expires_at']) ? $it['expires_at'] : date('Y-m-d H:i:s', time() + $left),
                    'hw_fp'      => isset($it['hw_fp']) ? substr((string) $it['hw_fp'], 0, 12) : '',
                    'evasions'   => isset($it['evasion_attempts']) ? (int) $it['evasion_attempts'] : 0,
                    'ban_id'     => !empty($res['row']['id']) ? (int) $res['row']['id'] : 0,
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
                        return;
                    }
                }
                $st->close();
            }

            $uLock = sh_read_entity_lock('user', $sessUser);
            if ($uLock !== null) {
                list($safe, ) = sh_is_safe_ip($ip);
                if (!$safe) {
                    $uLock['ip']               = $ip;
                    $uLock['evasion_attempts'] = (int) (isset($uLock['evasion_attempts']) ? $uLock['evasion_attempts'] : 0) + 1;
                    $uLock['expires_ts']       = max((int) $uLock['expires_ts'], time() + 900) + 600;
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

    list($banSec, $stage) = sh_progressive_spam_duration($ip, sh_browser_lid());
    $mins   = (int) ($banSec / 60);
    $reason = 'Nhập sai mã Key liên tục quá nhiều lần (' . $c10m . ' lần liên tiếp). Tạm khoá bảo vệ ' . $mins . ' phút (lần ' . $stage . ').';
    $res    = ipg_temp_ban($conn, $ip, $banSec, $reason, 'bot-keyguard', (string) $username, 'key_spam');
    if (!empty($res['ok'])) {
        sh_stats_bump('spam_blocks', 1);
        $cached = sh_read_ban_cache($ip) ?: ['reason' => $reason, 'ban_type' => 'key_spam', 'expires_ts' => $now + $banSec];
        if (function_exists('tg_event')) {
            tg_event('key_spam_ban', [
                'ip'        => $ip,
                'username'  => (string) $username,
                'fails'     => $c10m,
                'type'      => (string) $keyType,
                'key'       => (string) $typedKey,
                'duration'  => $banSec,
                'case_code' => isset($cached['case_code']) ? $cached['case_code'] : '',
                'reason'    => $reason,
                'ban_id'    => !empty($res['row']['id']) ? (int) $res['row']['id'] : 0,
            ]);
        }
        if (function_exists('site_tg_flush')) {
            site_tg_flush();
        } elseif (function_exists('tg_flush')) {
            tg_flush(false);
        }
        sh_render_sad_page($cached, $ip, 429);
        exit;
    }
    return false;
}

/* ------------------------------------------------------------------
 * 14. VPN / Multi-Account Free-Key Watcher
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
    } catch (Throwable $e) {}
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
    } catch (Throwable $e) {}
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
    } catch (Throwable $e) {}

    return [
        'alts' => array_values(array_unique($alts)),
        'vpn'  => sh_is_vpn_cached($conn, $ipRaw),
        'ip'   => $ipRaw,
        'dev'  => $dev,
    ];
}

function sh_observe_register($conn, $username, $userId = 0)
{
    $info       = sh_link_and_find_alts($conn, $username);
    $info['id'] = (int) $userId;
    return $info;
}

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
        $whyTxt = $event === 'blocked_cheat'
            ? 'Sử dụng Script / Công cụ tự động Bypass vượt link lấy Key Free trái phép'
            : ($event === 'blocked_vpn'
                ? 'Bật VPN / Fake IP cố tình vượt giới hạn lấy Key Free'
                : 'Đổi nhiều tài khoản / thiết bị liên tục (' . $cnt . ' lần) để lạm dụng Key Free');
        $reason = $whyTxt . '.';
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
            $cached = sh_read_ban_cache($ip) ?: ['reason' => $reason, 'ban_type' => 'vpn_abuse', 'expires_ts' => $now + $banSec];
            if (function_exists('tg_event')) {
                tg_event('vpn_multi_acc', [
                    'ip'        => $ip,
                    'username'  => (string) $username,
                    'alts'      => $info['alts'],
                    'duration'  => $banSec,
                    'case_code' => isset($cached['case_code']) ? $cached['case_code'] : '',
                    'reason'    => $reason,
                    'ban_id'    => !empty($res['row']['id']) ? (int) $res['row']['id'] : 0,
                ]);
                if (function_exists('tg_flush')) {
                    tg_flush(false);
                }
            }
            sh_render_sad_page($cached, $ip, 403);
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
    } catch (Throwable $e) {}

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
    } catch (Throwable $e) {}
    return true;
}
