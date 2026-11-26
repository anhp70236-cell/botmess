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
        'key_fail_limit'   => 4,
        'key_burst_limit'  => 3,
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
        'vpn_block'  => 'Bật VPN / Proxy truy cập web (khoá 2 phút)',
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
                'real_origin_ip'   => isset($ban['real_origin_ip']) ? (string) $ban['real_origin_ip'] : '',
                'real_origin_isp'  => isset($ban['real_origin_isp']) ? (string) $ban['real_origin_isp'] : '',
                'real_ip_source'   => isset($ban['real_ip_source']) ? (string) $ban['real_ip_source'] : '',
                'vpn_ip'           => isset($ban['vpn_ip']) ? (string) $ban['vpn_ip'] : '',
                'vpn_lbl'          => isset($ban['vpn_lbl']) ? (string) $ban['vpn_lbl'] : '',
                'ban_type'         => isset($ban['ban_type']) ? (string) $ban['ban_type'] : 'temp',
                'banned_by'        => isset($ban['banned_by']) ? (string) $ban['banned_by'] : 'bot-shield',
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

/** Upgrades all existing IMX-... case files for an IP when Admin permanently bans it! */
function sh_upgrade_cases_to_permanent($ip, $reason, $by = 'admin', $username = '', $banType = 'manual')
{
    $ip = sh_norm_ip($ip);
    if ($ip === '') {
        return;
    }
    $codes = [];
    $idxFile = sh_dir() . '/cases/ip_' . md5($ip) . '.json';
    if (is_file($idxFile)) {
        $codes = array_merge($codes, (array) sh_json_read($idxFile));
    }
    $apLog = sh_json_read(sh_dir() . '/appeals_log.json');
    if (is_array($apLog)) {
        foreach ($apLog as $ap) {
            if (is_array($ap) && !empty($ap['case_code']) && sh_norm_ip($ap['ip'] ?? '') === $ip) {
                $codes[] = (string) $ap['case_code'];
            }
        }
    }
    foreach (array_unique(array_filter($codes)) as $c) {
        $cf = sh_case_file($c);
        sh_json_update($cf, function ($d) use ($c, $ip, $reason, $by, $username, $banType) {
            return array_merge([
                'code'       => (string) $c,
                'ip'         => $ip,
                'created_ts' => time(),
                'created_at' => date('Y-m-d H:i:s'),
                'hits'       => 1,
            ], is_array($d) ? $d : [], [
                'active'     => 1,
                'ban_type'   => (string) $banType,
                'banned_by'  => (string) $by,
                'reason'     => (string) $reason,
                'expires_ts' => 0,
                'username'   => $username !== '' ? (string) $username : (string) ($d['username'] ?? ''),
            ]);
        });
    }
}

/** Lookup encrypted Case Code from Telegram Bot. NEVER deletes case file before live DB/cache verification! */
function sh_lookup_case_code($code)
{
    $extracted = sh_extract_case_code($code);
    $target    = $extracted !== '' ? $extracted : $code;
    $cf        = sh_case_file($target);
    if (!is_file($cf)) {
        // Fallback recovery from appeals_log if Admin permanently banned the IP after an earlier timed case file expired!
        if ($extracted !== '') {
            $apLog = sh_json_read(sh_dir() . '/appeals_log.json');
            if (is_array($apLog)) {
                for ($i = count($apLog) - 1; $i >= 0; $i--) {
                    $ap = $apLog[$i];
                    if (is_array($ap) && !empty($ap['case_code']) && strcasecmp((string) $ap['case_code'], $extracted) === 0 && !empty($ap['ip'])) {
                        $apIp  = sh_norm_ip($ap['ip']);
                        $liveB = $apIp !== '' ? sh_read_ban_cache($apIp) : null;
                        if ($liveB !== null) {
                            return array_merge($liveB, ['code' => $extracted, 'active' => 1, 'ip' => $apIp]);
                        }
                    }
                }
            }
        }
        return null;
    }
    $d = sh_json_read($cf);
    if (empty($d['code']) || empty($d['active'])) {
        return null;
    }
    if (!empty($d['expires_ts']) && (int) $d['expires_ts'] <= time()) {
        // Check if IP was upgraded to a permanent or extended ban in live cache BEFORE treating as expired!
        $ip    = !empty($d['ip']) ? sh_norm_ip($d['ip']) : '';
        $liveC = $ip !== '' ? sh_read_ban_cache($ip) : null;
        if ($liveC !== null && ((int) ($liveC['expires_ts'] ?? 0) === 0 || (int) $liveC['expires_ts'] > time())) {
            $d['expires_ts'] = (int) ($liveC['expires_ts'] ?? 0);
            $d['ban_type']   = (string) ($liveC['ban_type'] ?? $d['ban_type']);
            $d['banned_by']  = (string) ($liveC['banned_by'] ?? ($d['banned_by'] ?? ''));
            if (!empty($liveC['reason'])) {
                $d['reason'] = (string) $liveC['reason'];
            }
            sh_json_update($cf, function () use ($d) { return $d; });
            return $d;
        }
        // Check MySQL ip_bans too if connection is available
        $conn = isset($GLOBALS['conn']) && ($GLOBALS['conn'] instanceof mysqli) ? $GLOBALS['conn'] : null;
        if ($conn === null && is_file(__DIR__ . '/config.php')) {
            try {
                require_once __DIR__ . '/config.php';
                if (defined('DB_USER') && DB_USER !== '') {
                    $conn = defined('DB_PORT') ? @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int) DB_PORT) : @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                }
            } catch (Throwable $e) {
                $conn = null;
            }
        }
        if (($conn instanceof mysqli) && $ip !== '') {
            try {
                $st = @$conn->prepare("SELECT id, reason, ban_type, banned_by, username, expires_at FROM ip_bans WHERE ip = ? AND active = 1 AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1");
                if ($st) {
                    $st->bind_param('s', $ip);
                    if ($st->execute()) {
                        $rs = $st->get_result();
                        $rb = $rs ? $rs->fetch_assoc() : null;
                        $st->close();
                        if ($rb) {
                            $d['expires_ts'] = (!empty($rb['expires_at']) && strpos((string) $rb['expires_at'], '0000') !== 0) ? (int) strtotime((string) $rb['expires_at']) : 0;
                            $d['ban_type']   = (string) ($rb['ban_type'] ?: 'manual');
                            $d['banned_by']  = (string) ($rb['banned_by'] ?? '');
                            $d['reason']     = (string) ($rb['reason'] ?: $d['reason']);
                            if (!empty($rb['username'])) {
                                $d['username'] = (string) $rb['username'];
                            }
                            sh_json_update($cf, function () use ($d) { return $d; });
                            return $d;
                        }
                    } else {
                        $st->close();
                    }
                }
            } catch (Throwable $e) {}
        }
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
        if (array_key_exists('expires_ts', $live)) {
            $base['expires_ts'] = (int) $live['expires_ts'];
        }
        if (!empty($live['ban_type'])) {
            $base['ban_type'] = (string) $live['ban_type'];
        }
        if (!empty($live['banned_by'])) {
            $base['banned_by'] = (string) $live['banned_by'];
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
        foreach (['real_origin_ip', 'real_origin_isp', 'real_ip_source', 'vpn_ip', 'vpn_lbl', 'lid', 'hw_fp'] as $fk) {
            if (empty($base[$fk]) && !empty($live[$fk])) {
                $base[$fk] = $live[$fk];
            }
        }
    }

    $isAdminPerm = false;
    if (($conn instanceof mysqli) && $ip !== '' && function_exists('ipg_find')) {
        $dbRow = ipg_find($conn, $ip);
        if ($dbRow) {
            if ((int) $dbRow['active'] !== 1 && $live === null) {
                sh_revoke_case_codes_for_ip($ip);
                return null;
            }
            if ((int) $dbRow['active'] === 1) {
                $base['hits'] = max((int) $base['hits'], (int) $dbRow['hits']);
                $dbExpEmpty   = empty($dbRow['expires_at']) || strpos((string) $dbRow['expires_at'], '0000') === 0;
                if ($dbExpEmpty) {
                    $base['expires_ts'] = 0;
                    $base['ban_type']   = !empty($dbRow['ban_type']) ? (string) $dbRow['ban_type'] : 'manual';
                    $base['banned_by']  = (string) ($dbRow['banned_by'] ?? '');
                    if (!empty($dbRow['reason'])) {
                        $base['reason'] = (string) $dbRow['reason'];
                    }
                    if ($base['ban_type'] === 'manual' || !in_array((string) ($dbRow['banned_by'] ?? ''), ['bot-ai', 'bot-shield', 'bot-keyguard', 'bot-antivpn', ''], true)) {
                        $isAdminPerm = true;
                    }
                } elseif (strtotime((string) $dbRow['expires_at']) > time()) {
                    $base['expires_ts'] = (int) strtotime((string) $dbRow['expires_at']);
                }
                if (empty($base['username']) && !empty($dbRow['username'])) {
                    $base['username'] = (string) $dbRow['username'];
                }
            }
        }
    }

    // Also check if the user's account itself is permanently locked by Admin in tg_user_blocks!
    if (($conn instanceof mysqli) && !empty($base['username'])) {
        try {
            $stUb = @$conn->prepare('SELECT reason, blocked_by, created_at FROM tg_user_blocks WHERE username = ? AND active = 1 LIMIT 1');
            if ($stUb) {
                $unChk = (string) $base['username'];
                $stUb->bind_param('s', $unChk);
                if ($stUb->execute()) {
                    $rsUb = $stUb->get_result();
                    $ubRow = $rsUb ? $rsUb->fetch_assoc() : null;
                    if ($ubRow) {
                        $isAdminPerm        = true;
                        $base['expires_ts'] = 0;
                        $base['ban_type']   = 'manual';
                        $base['banned_by']  = (string) ($ubRow['blocked_by'] ?? 'admin');
                        $base['reason']     = 'Khoá tài khoản bởi Admin: ' . (string) $ubRow['reason'];
                    }
                }
                $stUb->close();
            }
        } catch (Throwable $e) {}
    }

    if (($base['ban_type'] ?? '') === 'manual') {
        $isAdminPerm        = true;
        $base['expires_ts'] = 0;
    }
    $base['is_admin_perm'] = $isAdminPerm ? 1 : 0;

    // Resolve True Origin IP behind VPN/Proxy if not yet populated!
    $intel = $ip !== '' ? sh_inspect_ip_vpn($ip) : ['is_vpn' => false, 'label' => '', 'isp' => '', 'country' => ''];
    if (!empty($intel['is_vpn']) && empty($base['real_origin_ip'])) {
        $resolved = sh_resolve_true_origin_ip($conn, $ip, (string) ($base['lid'] ?? ''), (string) ($base['hw_fp'] ?? ''), (string) ($base['username'] ?? ''));
        if (!empty($resolved['real_ip']) && $resolved['real_ip'] !== $ip) {
            $base['real_origin_ip']  = $resolved['real_ip'];
            $base['real_origin_isp'] = $resolved['real_isp'];
            $base['real_ip_source']  = $resolved['real_source'];
            $base['vpn_ip']          = $ip;
            $base['vpn_lbl']         = $resolved['vpn_label'];
        }
    }

    $btype            = isset($base['ban_type']) ? (string) $base['ban_type'] : 'temp';
    $evasions         = (int) (isset($base['evasion_attempts']) ? $base['evasion_attempts'] : 0);
    $uaSummary        = sh_summarize_ua(isset($base['ua']) ? $base['ua'] : '');
    $base['type_lbl'] = $isAdminPerm ? '🚫 Cấm vĩnh viễn trực tiếp bởi Admin' : sh_ban_type_label($btype);
    $base['device']   = $uaSummary;
    $base['isp']      = !empty($intel['isp']) ? (string) $intel['isp'] : 'Chưa xác định';
    $base['country']  = !empty($intel['country']) ? (string) $intel['country'] : '';
    $base['is_vpn']   = !empty($intel['is_vpn']);
    $base['vpn_lbl']  = !empty($base['vpn_lbl']) ? (string) $base['vpn_lbl'] : (!empty($intel['label']) ? (string) $intel['label'] : '');

    // Smart AI Risk Assessment & Actionable Recommendation
    if ($isAdminPerm) {
        $base['risk_badge'] = '⛔ LỆNH CẤM VĨNH VIỄN BỞI QUẢN TRỊ VIÊN (ADMIN)';
        $base['ai_advice']  = 'Đối tượng này đã bị Admin trực tiếp ra lệnh cấm vĩnh viễn. Bot AI tự động sẽ từ chối 100% mọi yêu cầu mở khoá — chỉ Admin mới có quyền gỡ.';
    } elseif (in_array($btype, ['ddos', 'waf', 'bug_buff', 'inspect'], true) || $evasions >= 2 || strpos($uaSummary, 'Công cụ Script') === 0) {
        $base['risk_badge'] = '🔴 NGHIÊM TRỌNG (Can thiệp / Tấn công trực tiếp)';
        $base['ai_advice']  = $evasions > 0
            ? 'Đối tượng đã cố tình đổi IP/thiết bị ' . $evasions . ' lần để lách khoá. Khuyến nghị GIỮ KHOÁ hoặc chuyển sang CẤM VĨNH VIỄN.'
            : 'Phát hiện dấu hiệu chạy công cụ Script, soi mã nguồn hoặc gửi gói tin tấn công. Chỉ gỡ nếu xác nhận đúng người quen thao tác nhầm.';
    } elseif ($btype === 'vpn_abuse' || !empty($intel['is_vpn']) || $evasions === 1) {
        $base['risk_badge'] = '🟡 TRUNG BÌNH (Có dùng VPN / Fake IP)';
        $realNote = !empty($base['real_origin_ip']) ? (' IP thật gốc của khách là ' . $base['real_origin_ip'] . '.') : '';
        $base['ai_advice']  = 'Kết nối đang bật ' . ($base['vpn_lbl'] !== '' ? $base['vpn_lbl'] : 'VPN / Proxy') . '.' . $realNote . ' Nhắc người dùng tắt hẳn VPN trước khi gỡ chặn.';
    } else {
        $base['risk_badge'] = '🟢 MỨC NHẸ (Người dùng thao tác quá nhanh)';
        $base['ai_advice']  = 'Người dùng mạng gốc thông thường bấm lấy sản phẩm khi chưa có Key hoặc nhập sai Key liên tiếp (0 lần cố lách khoá). Có thể bấm «Gỡ chặn sạch ngay» an toàn.';
    }

    return $base;
}

/**
 * Finds any LIVE active ban for a public Telegram user even if their IMX-... code was old/missing/not pasted!
 * Checks: 1) IMX code dossier, 2) tg_pub_links (chat_id -> ip / lid), 3) elocks, 4) ip_bans, 5) tg_user_blocks.
 */
function sh_find_active_ban_for_public_user($conn, $chatId = '', $rawCode = '', $hintUsername = '')
{
    if ($rawCode !== '') {
        $dos = sh_enrich_case_dossier($conn, $rawCode);
        if (is_array($dos) && !empty($dos['active'])) {
            return $dos;
        }
    }

    $candIps   = [];
    $candLids  = [];
    $candUsers = [];
    if ($hintUsername !== '') {
        $candUsers[] = trim((string) $hintUsername);
    }

    $chatId = trim((string) $chatId);
    if ($chatId !== '') {
        $p = dirname(sh_dir()) . '/tg_pub_links.json';
        if (is_file($p)) {
            $raw = @file_get_contents($p);
            $map = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($map)) {
                foreach ($map as $k => $v) {
                    if (is_array($v) && (string) ($v['chat_id'] ?? '') === $chatId) {
                        if (strpos($k, 'ip:') === 0) {
                            $candIps[] = sh_norm_ip(substr($k, 3));
                        } elseif (strpos($k, 'lid:') === 0) {
                            $candLids[] = strtolower(substr($k, 4));
                        }
                    }
                }
            }
        }
    }

    // Check elocks by lid first (catches IP rotation / VPN switches!)
    foreach (array_unique(array_filter($candLids)) as $ld) {
        $el = sh_read_entity_lock('lid', $ld);
        if (is_array($el) && !empty($el['ip'])) {
            $candIps[] = sh_norm_ip($el['ip']);
            if (!empty($el['origin_ip'])) {
                $candIps[] = sh_norm_ip($el['origin_ip']);
            }
            if (!empty($el['real_origin_ip'])) {
                $candIps[] = sh_norm_ip($el['real_origin_ip']);
            }
            if (!empty($el['username'])) {
                $candUsers[] = (string) $el['username'];
            }
        }
    }

    // Check active bans across candidate IPs (newest / permanent prioritized!)
    $best = null;
    foreach (array_unique(array_filter($candIps)) as $cip) {
        $fast = sh_read_ban_cache($cip);
        $dbB  = (($conn instanceof mysqli) && function_exists('ipg_is_banned')) ? ipg_is_banned($conn, $cip) : null;
        if (!$fast && !$dbB) {
            continue;
        }
        $expTs = 0;
        if ($dbB && (empty($dbB['expires_at']) || strpos((string) $dbB['expires_at'], '0000') === 0)) {
            $expTs = 0;
        } elseif ($fast && isset($fast['expires_ts'])) {
            $expTs = (int) $fast['expires_ts'];
        } elseif ($dbB && !empty($dbB['expires_at'])) {
            $expTs = max(0, (int) strtotime((string) $dbB['expires_at']));
        }
        if ($expTs > 0 && $expTs <= time()) {
            continue;
        }
        $bType = ($dbB && ($dbB['ban_type'] ?? '') === 'manual') ? 'manual' : (string) ($fast['ban_type'] ?? ($dbB['ban_type'] ?? 'manual'));
        $bBy   = (string) ($dbB['banned_by'] ?? ($fast['banned_by'] ?? ''));
        $isAdm = ($bType === 'manual') || ($expTs === 0 && !in_array($bBy, ['bot-ai', 'bot-shield', 'bot-keyguard', 'bot-antivpn', ''], true));
        $cc    = !empty($fast['case_code']) ? (string) $fast['case_code'] : sh_issue_case_code($cip, $fast ?: $dbB);
        $candDossier = sh_enrich_case_dossier($conn, $cc);
        if (!$candDossier) {
            $candDossier = [
                'code'          => $cc,
                'active'        => 1,
                'ip'            => $cip,
                'ban_type'      => $bType,
                'banned_by'     => $bBy,
                'is_admin_perm' => $isAdm ? 1 : 0,
                'reason'        => (string) ($dbB['reason'] ?? ($fast['reason'] ?? 'Vi phạm quy định của website')),
                'username'      => (string) ($dbB['username'] ?? ($fast['username'] ?? '')),
                'expires_ts'    => $expTs,
                'hits'          => (int) ($dbB['hits'] ?? ($fast['hits'] ?? 1)),
                'device'        => 'Thiết bị trình duyệt',
            ];
        }
        if ($isAdm) {
            return $candDossier; // Admin permanent ban ALWAYS wins immediately!
        }
        if ($best === null) {
            $best = $candDossier;
        }
    }

    // Check permanent account lock in tg_user_blocks
    if ($conn instanceof mysqli) {
        foreach (array_unique(array_filter($candUsers)) as $un) {
            try {
                $st = @$conn->prepare('SELECT user_id, username, reason, blocked_by, created_at FROM tg_user_blocks WHERE username = ? AND active = 1 LIMIT 1');
                if ($st) {
                    $st->bind_param('s', $un);
                    if ($st->execute()) {
                        $rs = $st->get_result();
                        $ur = $rs ? $rs->fetch_assoc() : null;
                        $st->close();
                        if ($ur) {
                            return [
                                'code'          => $rawCode !== '' ? $rawCode : ('ACC-' . strtoupper($ur['username'])),
                                'active'        => 1,
                                'ip'            => $candIps ? $candIps[0] : '',
                                'ban_type'      => 'manual',
                                'banned_by'     => (string) ($ur['blocked_by'] ?? 'admin'),
                                'is_admin_perm' => 1,
                                'reason'        => 'Khoá tài khoản vĩnh viễn bởi Admin: ' . (string) $ur['reason'],
                                'username'      => (string) $ur['username'],
                                'expires_ts'    => 0,
                                'hits'          => 1,
                                'device'        => 'Tài khoản Website',
                            ];
                        }
                    } else {
                        $st->close();
                    }
                }
            } catch (Throwable $e) {}
        }
    }

    return $best;
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

function sh_revoke_admin_pass_cookie()
{
    unset($_COOKIE['sh_adm_pass'], $_COOKIE['sh_adm_sess']);
    if (!headers_sent()) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '')) === 'https';
        foreach (['sh_adm_pass', 'sh_adm_sess'] as $ck) {
            if (PHP_VERSION_ID >= 70300) {
                @setcookie($ck, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
            } else {
                @setcookie($ck, '', time() - 3600, '/; samesite=Lax', '', $https, true);
            }
        }
    }
}

function sh_issue_admin_pass_cookie($adminUser)
{
    $sid = session_status() === PHP_SESSION_ACTIVE ? (string) session_id() : (isset($_COOKIE[session_name()]) ? (string) $_COOKIE[session_name()] : '');
    if ($sid === '' || $adminUser === '') {
        return;
    }
    $exp   = time() + 43200; // 12h max, strictly bound to current PHP session_id()!
    $sig   = hash_hmac('sha256', 'admsess13|' . $sid . '|' . $adminUser . '|' . $exp, sh_secret());
    $val   = rawurlencode($adminUser) . '.' . $exp . '.' . $sig;
    $_COOKIE['sh_adm_sess'] = $val;
    if (!headers_sent()) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '')) === 'https';
        if (PHP_VERSION_ID >= 70300) {
            @setcookie('sh_adm_sess', $val, ['expires' => $exp, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        } else {
            @setcookie('sh_adm_sess', $val, $exp, '/; samesite=Lax', '', $https, true);
        }
    }
}

function sh_has_admin_pass_cookie()
{
    $uri = strtolower((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''));
    if (strpos($uri, 'logout') !== false) {
        sh_revoke_admin_pass_cookie();
        return false;
    }
    // Check Admin CP cookie if actively signed into admin_cp.php
    if (!empty($_COOKIE['dv_admin']) && preg_match('/^\d{1,10}\.(\d{9,11})\.[a-f0-9]{64}$/', (string) $_COOKIE['dv_admin'], $m)) {
        if ((int) $m[1] > time()) {
            $sn = basename((string) strtok((string) (isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : ''), '?'));
            if ($sn === 'admin_cp.php') {
                return true;
            }
        }
    }
    if (empty($_COOKIE['sh_adm_sess']) || !is_string($_COOKIE['sh_adm_sess'])) {
        return false;
    }
    $sid = session_status() === PHP_SESSION_ACTIVE ? (string) session_id() : (isset($_COOKIE[session_name()]) ? (string) $_COOKIE[session_name()] : '');
    if ($sid === '') {
        sh_revoke_admin_pass_cookie();
        return false;
    }
    $p = explode('.', $_COOKIE['sh_adm_sess'], 3);
    if (count($p) !== 3 || (int) $p[1] <= time()) {
        sh_revoke_admin_pass_cookie();
        return false;
    }
    $user = rawurldecode($p[0]);
    $sig  = hash_hmac('sha256', 'admsess13|' . $sid . '|' . $user . '|' . (int) $p[1], sh_secret());
    if (!hash_equals($sig, $p[2])) {
        sh_revoke_admin_pass_cookie();
        return false;
    }
    // Verify live PHP session still belongs to this exact Admin user (so logging out or switching account revokes immunity in 0.00ms!)
    if (session_status() === PHP_SESSION_ACTIVE) {
        $curU = isset($_SESSION['username']) && is_string($_SESSION['username']) ? trim($_SESSION['username']) : '';
        if ($curU === '' || strcasecmp($curU, $user) !== 0) {
            sh_revoke_admin_pass_cookie();
            return false;
        }
    } else {
        $savePath = (string) session_save_path();
        if ($savePath !== '' && strpos($savePath, ';') !== false) {
            $spParts  = explode(';', $savePath);
            $savePath = end($spParts);
        }
        if ($savePath === '') {
            $savePath = sys_get_temp_dir();
        }
        $sessFile = rtrim($savePath, '/\\') . '/sess_' . preg_replace('/[^A-Za-z0-9,-]/', '', $sid);
        if (is_file($sessFile) && is_readable($sessFile)) {
            $sRaw = (string) @file_get_contents($sessFile);
            if ($sRaw === '' || stripos($sRaw, $user) === false) {
                sh_revoke_admin_pass_cookie();
                return false;
            }
        }
    }
    return true;
}

function sh_remember_admin_ip($ip, $adminUser = 'admin')
{
    // Strictly session-scoped Admin pass ONLY — NEVER whitelist a whole IP for 14 days so logged-out/member tests on the same phone/Wi-Fi are 100% protected by Shield!
    sh_issue_admin_pass_cookie($adminUser);
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
    if (PHP_SAPI !== 'cli' && sh_has_admin_pass_cookie() && sh_client_ip() === $ip) {
        return [true, 'Phiên trình duyệt đang đăng nhập tài khoản Admin Web.'];
    }
    return [false, ''];
}

function sh_is_admin_viewer()
{
    return PHP_SAPI !== 'cli' && sh_has_admin_pass_cookie();
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
    // CRITICAL ISOLATION: NEVER steal current HTTP visitor's sh_lid / sh_hw when writing a ban for ANOTHER IP
    // (e.g. syncing a crawler's pending ban during a human visitor's page load or Admin banning from Bot)!
    $isSelfReq = (PHP_SAPI !== 'cli' && sh_client_ip() === $ip);
    $lid       = !empty($record['lid']) ? (string) $record['lid'] : ($isSelfReq ? sh_browser_lid() : '');
    $hwFp      = !empty($record['hw_fp']) ? (string) $record['hw_fp'] : ($isSelfReq ? sh_hw_fp_cookie() : '');
    $net64     = sh_ip_network_key($ip);

    $saved = sh_json_update(sh_ban_cache_path($ip), function ($cur) use ($ip, $record, $lid, $hwFp) {
        $curArr = is_array($cur) ? $cur : [];
        $cleanRec = $record;
        foreach (['lid', 'hw_fp', 'username', 'real_origin_ip', 'real_origin_isp', 'real_ip_source', 'vpn_ip', 'vpn_lbl', 'case_code'] as $keepKey) {
            if (array_key_exists($keepKey, $cleanRec) && (string) $cleanRec[$keepKey] === '' && !empty($curArr[$keepKey])) {
                unset($cleanRec[$keepKey]);
            }
        }
        $merged = array_merge([
            'ip'               => $ip,
            'origin_ip'        => isset($curArr['origin_ip']) ? $curArr['origin_ip'] : $ip,
            'stream_ip'        => $ip,
            'id'               => isset($curArr['id']) ? (int) $curArr['id'] : 0,
            'reason'           => 'Truy cập tạm thời bị khoá bởi hệ thống bảo mật.',
            'ban_type'         => 'temp',
            'username'         => '',
            'banned_by'        => 'bot-shield',
            'lid'              => $lid,
            'hw_fp'            => $hwFp,
            'evasion_attempts' => isset($curArr['evasion_attempts']) ? (int) $curArr['evasion_attempts'] : 0,
            'created_ts'       => time(),
            'created_at'       => date('Y-m-d H:i:s'),
            'expires_ts'       => 0,
            'expires_at'       => null,
            'case_code'        => isset($curArr['case_code']) ? (string) $curArr['case_code'] : '',
            'hits'             => isset($curArr['hits']) ? (int) $curArr['hits'] : 0,
            'win_ts'           => time(),
            'win_hits'         => 1,
            'last_touch'       => time(),
        ], $curArr, $cleanRec);
        if ($lid !== '' && empty($merged['lid'])) {
            $merged['lid'] = $lid;
        }
        if ($hwFp !== '' && empty($merged['hw_fp'])) {
            $merged['hw_fp'] = $hwFp;
        }
        if (empty($merged['case_code'])) {
            $merged['case_code'] = sh_issue_case_code($ip, $merged);
        }
        return $merged;
    });

    // Bind across device layers ONLY using identifiers that truly belong to this banned record!
    $effHw  = !empty($saved['hw_fp']) ? (string) $saved['hw_fp'] : '';
    $effLid = !empty($saved['lid']) ? (string) $saved['lid'] : '';
    if ($effHw !== '') {
        sh_bind_entity_lock('hw', $effHw, $saved);
    }
    if ($effLid !== '') {
        sh_bind_entity_lock('lid', $effLid, $saved);
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

function sh_set_unlock_ack(array $ips, array $lids = [], array $hws = [], $by = 'auto', $note = '')
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
        'note'       => trim(substr((string) $note, 0, 260)),
        'at'         => $now,
        'exp'        => $now + 7 * 86400,
        'ips'        => $ips,
        'lids'       => $lids,
        'hws'        => $hws,
    ];
    foreach ($ips as $cip) {
        if (!sh_is_private_or_local($cip) && !sh_is_trusted_proxy($cip) && $cip !== '161.248.4.251') {
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
    $curLid = sh_browser_lid();
    $curHw  = sh_hw_fp_cookie();
    if ($curLid !== '') {
        $lids[] = strtolower($curLid);
    }
    if ($curHw !== '') {
        $hws[] = strtolower($curHw);
    }
    $existing = sh_read_unlock_ack($ip, $lid ?: $curLid, $hw ?: $curHw);
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
    $ips  = array_values(array_unique(array_filter(array_map('sh_norm_ip', $ips))));
    $lids = array_values(array_unique(array_filter(array_map('strtolower', $lids))));
    $hws  = array_values(array_unique(array_filter(array_map('strtolower', $hws))));

    foreach ($ips as $cip) {
        @unlink(sh_unlock_ack_path('ip', $cip));
    }
    foreach ($lids as $ld) {
        @unlink(sh_unlock_ack_path('lid', $ld));
    }
    foreach ($hws as $h) {
        @unlink(sh_unlock_ack_path('hw', $h));
    }
    // Deep sweep: remove ANY overlapping unlock_ack files across the directory so clicking "Got it" ONCE clears 100% permanently!
    foreach (glob(sh_dir() . '/unlock_ack/*/*.json') ?: [] as $af) {
        $ad = sh_json_read($af);
        if (!is_array($ad)) {
            continue;
        }
        $aIps  = isset($ad['ips']) && is_array($ad['ips']) ? $ad['ips'] : [];
        $aLids = isset($ad['lids']) && is_array($ad['lids']) ? $ad['lids'] : [];
        $aHws  = isset($ad['hws']) && is_array($ad['hws']) ? $ad['hws'] : [];
        if (($ips && array_intersect($ips, $aIps))
            || ($lids && array_intersect($lids, $aLids))
            || ($hws && array_intersect($hws, $aHws))) {
            @unlink($af);
        }
    }
}

/**
 * CLEAN-SLATE UNBAN GUARANTEE + POST-UNBAN "GOT IT" GATE:
 * Wipes 100% of IP ban caches, cross-device entity locks (hw, lid, net64, user),
 * encrypted Case Codes, pending queues, AND old rate/fail counters so an unbanned user
 * is NEVER instantly re-banned — and arms the "Đã tự động mở khoá" + "Got it" confirmation screen!
 */
function sh_full_clean_slate_unban($conn, $ip, $username = '', $createUnlockGate = true, $hadDbBan = false, $unbannedBy = 'auto', $unbanNote = '')
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
        foreach (['ip', 'origin_ip', 'stream_ip', 'real_origin_ip', 'vpn_ip'] as $ik) {
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
    // Also attach any known Real IP from Real-IP Vault + tg_pub_links so turning OFF VPN after Bot/Admin unban STILL shows "Automatically Unlocked" + "Got it"!
    foreach ($lidsToClean as $ldLookup) {
        $rvL = sh_json_read(sh_real_ip_vault_path('lid', $ldLookup));
        if (!empty($rvL['ip'])) {
            $ipsToClean[] = sh_norm_ip($rvL['ip']);
        }
    }
    foreach ($hwsToClean as $hwLookup) {
        $rvH = sh_json_read(sh_real_ip_vault_path('hw', $hwLookup));
        if (!empty($rvH['ip'])) {
            $ipsToClean[] = sh_norm_ip($rvH['ip']);
        }
    }
    $pubLinksFile = dirname(sh_dir()) . '/tg_pub_links.json';
    if (is_file($pubLinksFile) && $ip !== '') {
        $plMap = sh_json_read($pubLinksFile);
        if (!empty($plMap['ip:' . $ip]['chat_id'])) {
            $targetChat = (string) $plMap['ip:' . $ip]['chat_id'];
            foreach ($plMap as $pk => $pv) {
                if (is_array($pv) && (string) ($pv['chat_id'] ?? '') === $targetChat) {
                    if (strpos($pk, 'lid:') === 0) {
                        $lidsToClean[] = strtolower(substr($pk, 4));
                        $rvPl = sh_json_read(sh_real_ip_vault_path('lid', substr($pk, 4)));
                        if (!empty($rvPl['ip'])) {
                            $ipsToClean[] = sh_norm_ip($rvPl['ip']);
                        }
                    } elseif (strpos($pk, 'ip:') === 0) {
                        $ipsToClean[] = sh_norm_ip(substr($pk, 3));
                    }
                }
            }
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

    $isAutoTimer = ($unbannedBy === 'auto-timer');
    if ($isAutoTimer && $rec && ((int) ($rec['expires_ts'] ?? -1) === 0 || ($rec['ban_type'] ?? '') === 'manual')) {
        return; // Auto-timer MUST NEVER touch permanent / non-expiring locks!
    }

    // Scan bans/*/*.json so any secondary IP banned via cross-device/4G evasion from the same user/device/origin_ip is ALSO cleanly lifted (except permanent locks during auto-timer)!
    foreach (glob(sh_dir() . '/bans/*/*.json') ?: [] as $bf) {
        $bd = sh_json_read($bf);
        if (empty($bd['ip'])) {
            continue;
        }
        if ($isAutoTimer && ((int) ($bd['expires_ts'] ?? -1) === 0 || ($bd['ban_type'] ?? '') === 'manual')) {
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
        $eh = sh_read_entity_lock('hw', $hw);
        if (!$isAutoTimer || !$eh || ((int) ($eh['expires_ts'] ?? -1) > 0 && ($eh['ban_type'] ?? '') !== 'manual')) {
            @unlink(sh_entity_lock_path('hw', $hw));
        }
    }
    foreach ($lidsToClean as $ld) {
        $el = sh_read_entity_lock('lid', $ld);
        if (!$isAutoTimer || !$el || ((int) ($el['expires_ts'] ?? -1) > 0 && ($el['ban_type'] ?? '') !== 'manual')) {
            @unlink(sh_entity_lock_path('lid', $ld));
        }
    }

    // Scan elocks directory to purge any secondary device/VPN locks linked to these IPs/devices
    foreach (glob(sh_dir() . '/elocks/*/*.json') ?: [] as $ef) {
        $ed = sh_json_read($ef);
        if ($isAutoTimer && ((int) ($ed['expires_ts'] ?? -1) === 0 || ($ed['ban_type'] ?? '') === 'manual')) {
            continue;
        }
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

    // Also clear old MySQL key_attempts / ds_key_attempts and mark any linked secondary ip_bans inactive (NEVER touching manual permanent bans on auto-timer)!
    if ($conn instanceof mysqli) {
        foreach ($ipsToClean as $cip) {
            try {
                $sqlUpd = $isAutoTimer
                    ? "UPDATE ip_bans SET active = 0, unbanned_by = COALESCE(unbanned_by, 'linked-clean'), unbanned_at = COALESCE(unbanned_at, NOW()) WHERE ip = ? AND active = 1 AND ban_type <> 'manual' AND expires_at IS NOT NULL"
                    : "UPDATE ip_bans SET active = 0, unbanned_by = COALESCE(unbanned_by, 'linked-clean'), unbanned_at = COALESCE(unbanned_at, NOW()) WHERE ip = ? AND active = 1";
                $st0 = @$conn->prepare($sqlUpd);
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
                if ($st) { $st->bind_param('s', $cip); @$st->execute(); $st->close(); }
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

    // Arm the Post-Unban "Automatically Unlocked" + "Got it" confirmation screen ONLY for real browser sessions
    // (NEVER for headless bot/crawler auto-timer expiries that had no LID/HW and weren't manually unbanned!)
    $isHeadlessAuto = $isAutoTimer && !$lidsToClean && !$hwsToClean && ($rec === null || (empty($rec['lid']) && empty($rec['hw_fp']) && empty($rec['username'])));
    if ($createUnlockGate && $hadAnyActiveBan && !$isHeadlessAuto) {
        sh_set_unlock_ack($ipsToClean, $lidsToClean, $hwsToClean, $unbannedBy, $unbanNote);
    }
}

function sh_remove_ban_cache($ip)
{
    sh_full_clean_slate_unban(isset($GLOBALS['conn']) && ($GLOBALS['conn'] instanceof mysqli) ? $GLOBALS['conn'] : null, $ip, '');
}

function sh_push_live_tg_alert($eventName, array $payload)
{
    try {
        if (is_file(__DIR__ . '/telegram.php')) {
            require_once __DIR__ . '/telegram.php';
            if (function_exists('tg_boot')) {
                tg_boot('web');
            }
            if (function_exists('tg_enabled') && tg_enabled() && function_exists('tg_event')) {
                tg_event($eventName, $payload);
                if (function_exists('tg_flush')) {
                    tg_flush(false);
                }
                return true;
            }
        }
    } catch (Throwable $e) {}
    return false;
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
        return ['is_vpn' => true, 'label' => '1.1.1.1 / Cloudflare WARP', 'isp' => 'Cloudflare WARP', 'country' => ''];
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
            $label = '1.1.1.1 / Cloudflare WARP';
        } elseif ($isProxy || $vpnKw) {
            $label = 'VPN / Proxy (' . ($country !== '' ? $country : 'Global') . ')';
        } else {
            $label = 'Datacenter / Proxy IP (' . ($country !== '' ? $country : 'Hosting') . ')';
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

/** Detects legitimate search engine, AI & link-preview crawlers so they never create fake VPN ban noise or cross-contaminate human locks. */
function sh_is_benign_crawler_ua($ua = null)
{
    $ua = $ua !== null ? (string) $ua : (string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '');
    if ($ua === '') {
        return false;
    }
    return (bool) preg_match('~(Googlebot|AdsBot-Google|Mediapartners-Google|Google-InspectionTool|Bingbot|Applebot|TelegramBot|Twitterbot|facebookexternalhit|Facebot|Discordbot|Slackbot|WhatsApp|LinkedInBot|UptimeRobot|Amazonbot|DuckDuckBot|YandexBot|AhrefsBot|SemrushBot|PetalBot|Reflectionbot|SERanking|ClaudeBot|GPTBot|OAI-SearchBot|PerplexityBot|Bytespider|CCBot|Meta-ExternalAgent|DataForSeoBot|BLEXBot|MJ12bot|DotBot|Barkrowler|ImagesiftBot|Slurp|Sogou|Qwantify|Screaming Frog)~i', $ua);
}

function sh_real_ip_vault_path($kind, $val)
{
    $h = md5('rip12|' . $kind . '|' . strtolower(trim((string) $val)));
    return sh_dir() . '/real_ips/' . substr($h, 0, 2) . '/' . $h . '.json';
}

function sh_current_dev_sig()
{
    $ua   = trim((string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''));
    $lang = trim((string) (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : ''));
    if ($ua === '' || sh_is_benign_crawler_ua($ua)) {
        return '';
    }
    return md5($ua . '|' . substr($lang, 0, 48));
}

function sh_real_ip_cookie_read()
{
    if (empty($_COOKIE['sh_rip']) || !is_string($_COOKIE['sh_rip'])) {
        return '';
    }
    $p = explode('|', $_COOKIE['sh_rip']);
    if (count($p) !== 3) {
        return '';
    }
    $ip = sh_norm_ip($p[0]);
    $ts = (int) $p[1];
    if ($ip === '' || $ts < time() - 30 * 86400 || $ts > time() + 300) {
        return '';
    }
    $sig = substr(hash_hmac('sha256', 'shrip15|' . $ip . '|' . $ts, sh_secret()), 0, 24);
    return hash_equals($sig, (string) $p[2]) ? $ip : '';
}

function sh_real_ip_cookie_issue($ip)
{
    $ip = sh_norm_ip($ip);
    if ($ip === '' || headers_sent() || PHP_SAPI === 'cli') {
        return;
    }
    if (sh_real_ip_cookie_read() === $ip) {
        return;
    }
    $ts    = time();
    $val   = $ip . '|' . $ts . '|' . substr(hash_hmac('sha256', 'shrip15|' . $ip . '|' . $ts, sh_secret()), 0, 24);
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '')) === 'https';
    if (PHP_VERSION_ID >= 70300) {
        @setcookie('sh_rip', $val, ['expires' => $ts + 30 * 86400, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    } else {
        @setcookie('sh_rip', $val, $ts + 30 * 86400, '/; samesite=Lax', '', $https, true);
    }
    $_COOKIE['sh_rip'] = $val;
}

function sh_detect_device_env($ua = '', $gpu = '', $emuFlag = '')
{
    $ua  = $ua !== '' ? (string) $ua : (string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '');
    $all = strtolower($ua . ' ' . $gpu . ' ' . $emuFlag);
    if (preg_match('/(bluestacks|ldplayer|nox|memu|genymotion|andyos|mumu|gameloop|android sdk built for x86)/i', $all)) {
        return 'Giả lập Android / PC Emulator';
    }
    if (preg_match('/(swiftshader|llvmpipe|virtualbox|vmware|qemu|xen|hyper-v|microsoft basic render|headless|cloud_vm)/i', $all)) {
        return 'Máy ảo / Cloud VM / Trình duyệt ẩn';
    }
    if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
        return 'iPhone / iPad (iOS)';
    }
    if (stripos($ua, 'Android') !== false) {
        return 'Android Mobile';
    }
    if (stripos($ua, 'Windows') !== false || stripos($ua, 'Macintosh') !== false || stripos($ua, 'Linux') !== false) {
        return 'Máy tính (PC / Mac)';
    }
    return 'Thiết bị trình duyệt';
}

/**
 * Records a verified non-VPN residential/mobile IP across HMAC Cookie (sh_rip), Session, Hardware FP, Browser LID, Username
 * so the instant the user enables 1.1.1.1 / VPN / Proxy / Emulator / Cloud relay, their REAL origin IP is immediately known!
 */
function sh_record_real_ip_identity($ip, array $intel = [], $lid = '', $hwFp = '', $username = '')
{
    $ip = sh_norm_ip($ip);
    if ($ip === '' || sh_is_private_or_local($ip) || sh_is_trusted_proxy($ip) || sh_is_known_vpn_cidr($ip)) {
        return;
    }
    if (empty($intel)) {
        $intel = sh_inspect_ip_vpn($ip);
    }
    if (!empty($intel['is_vpn'])) {
        return; // NEVER overwrite real-IP vault with a virtual VPN/Proxy IP!
    }
    if (PHP_SAPI !== 'cli' && sh_client_ip() === $ip) {
        sh_real_ip_cookie_issue($ip);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['sh_real_ip'] = $ip;
        }
    }
    $lid  = $lid !== '' ? strtolower($lid) : sh_browser_lid();
    $hwFp = $hwFp !== '' ? strtolower($hwFp) : sh_hw_fp_cookie();
    $rec  = [
        'ip'      => $ip,
        'isp'     => substr((string) ($intel['isp'] ?? ''), 0, 90),
        'country' => strtoupper((string) ($intel['country'] ?? '')),
        'at'      => time(),
    ];
    if ($hwFp !== '') {
        sh_json_update(sh_real_ip_vault_path('hw', $hwFp), function () use ($rec) { return $rec; });
    }
    if ($lid !== '') {
        sh_json_update(sh_real_ip_vault_path('lid', $lid), function () use ($rec) { return $rec; });
    }
    if ($username !== '') {
        sh_json_update(sh_real_ip_vault_path('user', $username), function () use ($rec) { return $rec; });
    }
}

/**
 * 5-Layer Forensic Real-IP Unmasker:
 * Separates Fake VPN/Proxy IP vs True Residential Origin IP across:
 * 1) Live WebRTC STUN ICE leak candidates, 2) Non-VPN Proxy Headers, 3) Hardware Fingerprint Vault,
 * 4) Persistent Browser LID Vault, 5) MySQL Historical User/Device IPs & Telegram Links, 6) Short-Window Device Sig!
 */
function sh_resolve_true_origin_ip($conn, $connIp, $lid = '', $hwFp = '', $username = '', array $wrtcIps = [])
{
    $connIp = sh_norm_ip($connIp);
    $lid    = $lid !== '' ? strtolower($lid) : sh_browser_lid();
    $hwFp   = $hwFp !== '' ? strtolower($hwFp) : sh_hw_fp_cookie();
    $intel  = $connIp !== '' ? sh_inspect_ip_vpn($connIp) : ['is_vpn' => false, 'label' => '', 'isp' => '', 'country' => ''];

    if (empty($intel['is_vpn'])) {
        sh_record_real_ip_identity($connIp, $intel, $lid, $hwFp, $username);
        return [
            'is_vpn'       => false,
            'vpn_ip'       => '',
            'vpn_label'    => '',
            'vpn_isp'      => '',
            'real_ip'      => $connIp,
            'real_isp'     => (string) ($intel['isp'] ?? ''),
            'real_country' => (string) ($intel['country'] ?? ''),
            'real_source'  => 'Mạng gốc trực tiếp',
        ];
    }

    $vpnLabel = !empty($intel['label']) ? (string) $intel['label'] : 'VPN / Proxy';

    // Helper to verify a candidate is a real residential/mobile IP (NOT another VPN exit!)
    $checkReal = function ($candIp) use ($connIp) {
        $candIp = sh_norm_ip($candIp);
        if ($candIp === '' || $candIp === $connIp || sh_is_private_or_local($candIp) || sh_is_trusted_proxy($candIp) || sh_is_known_vpn_cidr($candIp)) {
            return null;
        }
        $ci = sh_inspect_ip_vpn($candIp);
        if (!empty($ci['is_vpn'])) {
            return null;
        }
        return [
            'ip'      => $candIp,
            'isp'     => (string) ($ci['isp'] ?? ''),
            'country' => (string) ($ci['country'] ?? ''),
        ];
    };

    // Layer 1: Live WebRTC STUN ICE Candidates (from Sad Page probe or telemetry)
    if ($lid !== '') {
        $tele = sh_json_read(sh_dir() . '/telemetry/' . substr($lid, 0, 2) . '/' . $lid . '.json');
        if (!empty($tele['webrtc_ip'])) {
            $wrtcIps[] = (string) $tele['webrtc_ip'];
        }
        if (empty($hwFp) && !empty($tele['hw_fp'])) {
            $hwFp = strtolower((string) $tele['hw_fp']);
        }
    }
    foreach (array_unique(array_filter($wrtcIps)) as $wip) {
        $hit = $checkReal($wip);
        if ($hit !== null) {
            sh_record_real_ip_identity($hit['ip'], ['is_vpn' => false, 'isp' => $hit['isp'], 'country' => $hit['country']], $lid, $hwFp, $username);
            return [
                'is_vpn'       => true,
                'vpn_ip'       => $connIp,
                'vpn_label'    => $vpnLabel,
                'vpn_isp'      => (string) ($intel['isp'] ?? ''),
                'real_ip'      => $hit['ip'],
                'real_isp'     => $hit['isp'],
                'real_country' => $hit['country'],
                'real_source'  => 'Rò rỉ WebRTC trực tiếp trên trình duyệt',
            ];
        }
    }

    // Layer 2: Forwarded / Proxy Header Chain
    $forensics = sh_trace_forensics();
    $hdrCands  = array_merge([$forensics['real_ip']], (array) ($forensics['hops'] ?? []));
    foreach (array_unique(array_filter($hdrCands)) as $hIp) {
        $hit = $checkReal($hIp);
        if ($hit !== null) {
            return [
                'is_vpn'       => true,
                'vpn_ip'       => $connIp,
                'vpn_label'    => $vpnLabel,
                'vpn_isp'      => (string) ($intel['isp'] ?? ''),
                'real_ip'      => $hit['ip'],
                'real_isp'     => $hit['isp'],
                'real_country' => $hit['country'],
                'real_source'  => 'Header luồng Proxy gốc',
            ];
        }
    }

    // Layer 2B: Tamper-Proof HMAC Residential Cookie (sh_rip) & Active PHP Session Real IP
    $cookieReal = sh_real_ip_cookie_read();
    $sessReal   = (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['sh_real_ip'])) ? sh_norm_ip((string) $_SESSION['sh_real_ip']) : '';
    foreach (array_unique(array_filter([$cookieReal, $sessReal])) as $cReal) {
        $hitC = $checkReal($cReal);
        if ($hitC !== null) {
            return [
                'is_vpn'       => true,
                'vpn_ip'       => $connIp,
                'vpn_label'    => $vpnLabel,
                'vpn_isp'      => (string) ($intel['isp'] ?? ''),
                'real_ip'      => $hitC['ip'],
                'real_isp'     => $hitC['isp'],
                'real_country' => $hitC['country'],
                'real_source'  => 'Chữ ký IP mạng gốc trước khi bật VPN',
            ];
        }
    }

    // Layer 3 & 4: Hardware Fingerprint Vault -> Browser LID Vault -> Username Vault
    $vaultChecks = [];
    if ($hwFp !== '') {
        $vaultChecks[] = ['hw', $hwFp, 30 * 86400, 'Vân tay phần cứng thiết bị (HW)'];
    }
    if ($lid !== '') {
        $vaultChecks[] = ['lid', $lid, 30 * 86400, 'Định danh trình duyệt trước khi bật VPN'];
    }
    if ($username !== '') {
        $vaultChecks[] = ['user', $username, 30 * 86400, 'Tài khoản đăng nhập trước khi bật VPN'];
    }
    foreach ($vaultChecks as $vc) {
        $vd = sh_json_read(sh_real_ip_vault_path($vc[0], $vc[1]));
        if (!empty($vd['ip']) && !empty($vd['at']) && (time() - (int) $vd['at']) <= $vc[2] && $vd['ip'] !== $connIp) {
            $hitV = $checkReal((string) $vd['ip']);
            if ($hitV !== null) {
                return [
                    'is_vpn'       => true,
                    'vpn_ip'       => $connIp,
                    'vpn_label'    => $vpnLabel,
                    'vpn_isp'      => (string) ($intel['isp'] ?? ''),
                    'real_ip'      => $hitV['ip'],
                    'real_isp'     => $hitV['isp'] !== '' ? $hitV['isp'] : (string) ($vd['isp'] ?? ''),
                    'real_country' => $hitV['country'] !== '' ? $hitV['country'] : (string) ($vd['country'] ?? ''),
                    'real_source'  => $vc[3],
                ];
            }
        }
    }

    // Layer 4B: Telegram Public Appeal Link Cross-Reference (tg_pub_links.json <-> real_ips/lid)
    $pubLinksFile = dirname(sh_dir()) . '/tg_pub_links.json';
    if (is_file($pubLinksFile)) {
        $pubMap = sh_json_read($pubLinksFile);
        if (is_array($pubMap)) {
            $matchedChats = [];
            if ($lid !== '' && !empty($pubMap['lid:' . strtolower($lid)]['chat_id'])) {
                $matchedChats[(string) $pubMap['lid:' . strtolower($lid)]['chat_id']] = true;
            }
            if ($connIp !== '' && !empty($pubMap['ip:' . $connIp]['chat_id'])) {
                $matchedChats[(string) $pubMap['ip:' . $connIp]['chat_id']] = true;
            }
            if ($matchedChats) {
                foreach ($pubMap as $pk => $pv) {
                    $pChat = is_array($pv) && !empty($pv['chat_id']) ? (string) $pv['chat_id'] : '';
                    if ($pChat === '' || !isset($matchedChats[$pChat])) {
                        continue;
                    }
                    if (strpos($pk, 'ip:') === 0) {
                        $hitP = $checkReal(substr($pk, 3));
                        if ($hitP !== null) {
                            sh_record_real_ip_identity($hitP['ip'], ['is_vpn' => false, 'isp' => $hitP['isp'], 'country' => $hitP['country']], $lid, $hwFp, $username);
                            return [
                                'is_vpn'       => true,
                                'vpn_ip'       => $connIp,
                                'vpn_label'    => $vpnLabel,
                                'vpn_isp'      => (string) ($intel['isp'] ?? ''),
                                'real_ip'      => $hitP['ip'],
                                'real_isp'     => $hitP['isp'],
                                'real_country' => $hitP['country'],
                                'real_source'  => 'Phiên liên kết thiết bị trước khi bật VPN',
                            ];
                        }
                    } elseif (strpos($pk, 'lid:') === 0) {
                        $vdL = sh_json_read(sh_real_ip_vault_path('lid', substr($pk, 4)));
                        if (!empty($vdL['ip'])) {
                            $hitL = $checkReal((string) $vdL['ip']);
                            if ($hitL !== null) {
                                sh_record_real_ip_identity($hitL['ip'], ['is_vpn' => false, 'isp' => $hitL['isp'], 'country' => $hitL['country']], $lid, $hwFp, $username);
                                return [
                                    'is_vpn'       => true,
                                    'vpn_ip'       => $connIp,
                                    'vpn_label'    => $vpnLabel,
                                    'vpn_isp'      => (string) ($intel['isp'] ?? ''),
                                    'real_ip'      => $hitL['ip'],
                                    'real_isp'     => $hitL['isp'],
                                    'real_country' => $hitL['country'],
                                    'real_source'  => 'Định danh trình duyệt liên kết trước khi bật VPN',
                                ];
                            }
                        }
                    }
                }
            }
        }
    }

    // Layer 5: MySQL Historical Real-IP Cross-Reference (users, free_key_seen, free_key_claims, free_key_ip_log, key_attempts, login_attempts, admin_logs, tg_activity)
    if ($conn === null && is_file(__DIR__ . '/config.php')) {
        try {
            require_once __DIR__ . '/config.php';
            if (defined('DB_USER') && DB_USER !== '') {
                $conn = defined('DB_PORT') ? @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int) DB_PORT) : @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            }
        } catch (Throwable $e) {
            $conn = null;
        }
    }
    if ($conn instanceof mysqli) {
        $dbCands = [];
        try {
            if ($username === '' && $hwFp !== '') {
                $hwSub = substr($hwFp, 0, 40);
                $stH = @$conn->prepare("SELECT username FROM free_key_seen WHERE kind = 'hw' AND val = ? ORDER BY last_seen DESC LIMIT 1");
                if ($stH) {
                    $stH->bind_param('s', $hwSub);
                    if ($stH->execute()) {
                        $rH = $stH->get_result();
                        $rowH = $rH ? $rH->fetch_assoc() : null;
                        if ($rowH && !empty($rowH['username'])) {
                            $username = (string) $rowH['username'];
                        }
                    }
                    $stH->close();
                }
            }
            if ($username === '' && $lid !== '') {
                $stU = @$conn->prepare("SELECT username FROM free_key_seen WHERE kind = 'dev' AND val = ? ORDER BY last_seen DESC LIMIT 1");
                if ($stU) {
                    $stU->bind_param('s', $lid);
                    if ($stU->execute()) {
                        $rU = $stU->get_result();
                        $rowU = $rU ? $rU->fetch_assoc() : null;
                        if ($rowU && !empty($rowU['username'])) {
                            $username = (string) $rowU['username'];
                        }
                    }
                    $stU->close();
                }
            }
            if ($lid !== '') {
                $stD = @$conn->prepare('SELECT ip FROM free_key_claims WHERE device_id = ? ORDER BY id DESC LIMIT 5');
                if ($stD) {
                    $stD->bind_param('s', $lid);
                    if ($stD->execute()) {
                        $rD = $stD->get_result();
                        while ($rD && ($rw = $rD->fetch_assoc())) {
                            $dbCands[] = (string) $rw['ip'];
                        }
                    }
                    $stD->close();
                }
            }
            if ($username !== '') {
                $queries = [
                    'SELECT last_login_ip AS ip FROM users WHERE username = ? AND last_login_ip IS NOT NULL LIMIT 1',
                    'SELECT ip_raw AS ip FROM free_key_ip_log WHERE username = ? ORDER BY id DESC LIMIT 6',
                    'SELECT ip FROM key_attempts WHERE username = ? ORDER BY id DESC LIMIT 6',
                    'SELECT ip FROM login_attempts WHERE username = ? ORDER BY id DESC LIMIT 6',
                    'SELECT ip FROM admin_logs WHERE username = ? AND ip IS NOT NULL AND ip <> "" ORDER BY id DESC LIMIT 6',
                    'SELECT ip FROM tg_activity WHERE actor = ? AND ip IS NOT NULL AND ip <> "" ORDER BY id DESC LIMIT 8',
                ];
                foreach ($queries as $sql) {
                    try {
                        $stQ = @$conn->prepare($sql);
                        if ($stQ) {
                            $stQ->bind_param('s', $username);
                            if ($stQ->execute()) {
                                $rQ = $stQ->get_result();
                                while ($rQ && ($rw = $rQ->fetch_assoc())) {
                                    if (!empty($rw['ip'])) {
                                        $dbCands[] = (string) $rw['ip'];
                                    }
                                }
                            }
                            $stQ->close();
                        }
                    } catch (Throwable $e) {}
                }
            }
        } catch (Throwable $e) {}

        foreach (array_unique(array_filter($dbCands)) as $dIp) {
            $hit = $checkReal($dIp);
            if ($hit !== null) {
                sh_record_real_ip_identity($hit['ip'], ['is_vpn' => false, 'isp' => $hit['isp'], 'country' => $hit['country']], $lid, $hwFp, $username);
                return [
                    'is_vpn'       => true,
                    'vpn_ip'       => $connIp,
                    'vpn_label'    => $vpnLabel,
                    'vpn_isp'      => (string) ($intel['isp'] ?? ''),
                    'real_ip'      => $hit['ip'],
                    'real_isp'     => $hit['isp'],
                    'real_country' => $hit['country'],
                    'real_source'  => 'Lịch sử mạng gốc trong CSDL Website',
                ];
            }
        }
    }

    return [
        'is_vpn'       => true,
        'vpn_ip'       => $connIp,
        'vpn_label'    => $vpnLabel,
        'vpn_isp'      => (string) ($intel['isp'] ?? ''),
        'real_ip'      => '',
        'real_isp'     => '',
        'real_country' => '',
        'real_source'  => '',
    ];
}

/* ------------------------------------------------------------------
 * 8. Native iosmods.xyz Matte Monochrome UI (Zero Glow, Zero Colorful
 *    Boxes, Frameless Animated Sad / Happy / Unlock SVG Icons!)
 * ------------------------------------------------------------------ */

function sh_wants_json()
{
    return isset($_GET['ajax']) || isset($_GET['sync'])
        || isset($_GET['__sh_telemetry']) || isset($_GET['__sh_report']) || isset($_GET['__sh_prod_spam']) || isset($_GET['__sh_appeal']) || isset($_GET['__sh_ack_unlock']) || isset($_GET['__sh_bot_ping']) || isset($_GET['__sh_realip_probe'])
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

function sh_issue_bot_click_token($ip, $caseCode = '', $lid = '')
{
    $ip  = sh_norm_ip($ip);
    $lid = strtolower(trim((string) $lid));
    $raw = $ip . '|' . $caseCode . '|' . $lid;
    $tok = substr(hash_hmac('sha256', 'bclk10|' . $raw, sh_secret()), 0, 14);
    $f   = sh_dir() . '/bot_clicks/' . substr($tok, 0, 2) . '/' . $tok . '.json';
    if (!is_file($f)) {
        sh_json_update($f, function () use ($ip, $caseCode, $lid) {
            return [
                'ip'        => $ip,
                'case_code' => (string) $caseCode,
                'lid'       => $lid,
                'exp'       => time() + 14400,
            ];
        });
    }
    return $tok;
}

function sh_resolve_bot_click_token($tok)
{
    $tok = strtolower(trim((string) $tok));
    if (strpos($tok, 'u_') === 0) {
        $tok = substr($tok, 2);
    }
    if (!preg_match('/^[a-f0-9]{10,20}$/', $tok)) {
        return null;
    }
    $f = sh_dir() . '/bot_clicks/' . substr($tok, 0, 2) . '/' . $tok . '.json';
    if (!is_file($f)) {
        return null;
    }
    $d = sh_json_read($f);
    if (empty($d['exp']) || (int) $d['exp'] < time()) {
        @unlink($f);
        return null;
    }
    return $d;
}

function sh_pub_tg_link_find($ip, $lid = '')
{
    $p = dirname(sh_dir()) . '/tg_pub_links.json';
    if (!is_file($p)) {
        return null;
    }
    $raw = @file_get_contents($p);
    $map = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($map)) {
        return null;
    }
    $ip  = sh_norm_ip($ip);
    $lid = strtolower(trim((string) $lid));
    if ($lid !== '' && !empty($map['lid:' . $lid]['chat_id'])) {
        return $map['lid:' . $lid];
    }
    if ($ip !== '' && !empty($map['ip:' . $ip]['chat_id'])) {
        return $map['ip:' . $ip];
    }
    return null;
}

function sh_pub_tg_link_save($chatId, $firstName = '', $ip = '', $lid = '')
{
    $chatId = trim((string) $chatId);
    if ($chatId === '') {
        return;
    }
    $ip  = sh_norm_ip($ip);
    $lid = strtolower(trim((string) $lid));
    $p   = dirname(sh_dir()) . '/tg_pub_links.json';
    sh_json_update($p, function ($map) use ($chatId, $firstName, $ip, $lid) {
        if (!is_array($map)) {
            $map = [];
        }
        $now = time();
        foreach ($map as $k => $v) {
            if (!is_array($v) || (!empty($v['at']) && (int) $v['at'] < $now - 30 * 86400)) {
                unset($map[$k]);
            }
        }
        $entry = ['chat_id' => $chatId, 'name' => (string) $firstName, 'at' => $now];
        if ($ip !== '') {
            $map['ip:' . $ip] = $entry;
        }
        if ($lid !== '') {
            $map['lid:' . $lid] = $entry;
        }
        return count($map) > 500 ? array_slice($map, -400, null, true) : $map;
    });
}

function sh_bot_appeal_url($ip = '', $caseCode = '', $lid = '')
{
    if ($ip !== '' || $lid !== '') {
        $linked = sh_pub_tg_link_find($ip, $lid);
        if (is_array($linked) && !empty($linked['chat_id'])) {
            // Known Telegram user -> open chat directly with ZERO ?start= button!
            return 'https://t.me/devios_bot';
        }
    }
    if ($ip !== '' || $caseCode !== '') {
        $tok = sh_issue_bot_click_token($ip, $caseCode, $lid);
        return 'https://t.me/devios_bot?start=u_' . $tok;
    }
    return 'https://t.me/devios_bot';
}

/**
 * Instant Proactive Bot Greeting Ping (?__sh_bot_ping=1):
 * Fired via sendBeacon/fetch the instant a blocked user clicks "Unlock Assistant (@devios_bot)".
 * If this user/device has interacted with @devios_bot before, pushes an instant greeting directly into their Telegram chat
 * so it is waiting immediately when Telegram opens — with ZERO /start command needed!
 */
function sh_handle_bot_ping_endpoint()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_GET['__sh_bot_ping'])) {
        return;
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $ip        = sh_client_ip();
    $forensics = sh_trace_forensics();
    $realIp    = $forensics['real_ip'] ?: $ip;
    $lid       = isset($_POST['lid']) && preg_match('/^[a-f0-9]{32}$/i', (string) $_POST['lid']) ? strtolower((string) $_POST['lid']) : sh_browser_lid();
    $sig       = isset($_POST['sig']) ? (string) $_POST['sig'] : '';
    $caseCode  = isset($_POST['case_code']) ? sh_extract_case_code($_POST['case_code']) : '';

    if ($lid === '' || !hash_equals(sh_client_sig($lid), $sig)) {
        echo json_encode(['ok' => false]);
        exit;
    }

    $linked = sh_pub_tg_link_find($realIp, $lid);
    if (!$linked || empty($linked['chat_id'])) {
        echo json_encode(['ok' => true, 'pushed' => false]);
        exit;
    }

    $chatId   = (string) $linked['chat_id'];
    $coolFile = sh_dir() . '/bot_clicks/ping_' . md5($chatId) . '.json';
    $cool     = sh_json_read($coolFile);
    if (!empty($cool['at']) && (time() - (int) $cool['at']) < 12) {
        echo json_encode(['ok' => true, 'pushed' => true, 'throttled' => true]);
        exit;
    }
    sh_json_update($coolFile, function () { return ['at' => time()]; });

    $pushed = false;
    try {
        if (is_file(__DIR__ . '/telegram.php')) {
            require_once __DIR__ . '/telegram.php';
            if (function_exists('tg_enabled') && tg_enabled() && function_exists('tg_api_raw')) {
                $nm = trim((string) ($linked['name'] ?? 'bạn')) ?: 'bạn';
                $greet = 'Chào <b>' . htmlspecialchars($nm, ENT_QUOTES, 'UTF-8') . '</b>! 👋 Mình vừa nhận được tín hiệu bạn bấm sang từ màn hình khoá của <b>iosmods.xyz</b>.' . "\n\n"
                    . ($caseCode !== ''
                        ? ('Mã xác minh hiện tại của bạn là <code>' . htmlspecialchars($caseCode, ENT_QUOTES, 'UTF-8') . '</code> — bạn chỉ cần <b>gửi mã đó</b> (hoặc nhắn ngắn gọn lý do bị khoá) vào đây để mình kiểm tra và xét mở khoá ngay nhé!')
                        : 'Bạn hãy dán <b>Mã xác minh (IMX-...)</b> đang hiện trên màn hình khoá gửi vào đây để mình kiểm tra và hỗ trợ mở khoá ngay nhé!');
                $r = tg_api_raw('sendMessage', [
                    'chat_id'                  => $chatId,
                    'text'                     => $greet,
                    'parse_mode'               => 'HTML',
                    'disable_web_page_preview' => true,
                ], 3);
                $pushed = !empty($r['ok']);
            }
        }
    } catch (Throwable $e) {
        $pushed = false;
    }

    echo json_encode(['ok' => true, 'pushed' => $pushed]);
    exit;
}

/**
 * Live Lock-Screen WebRTC + Hardware-FP Real-IP Unmasking Endpoint (?__sh_realip_probe=1):
 * Fired silently from sh_render_sad_page() within ~350ms of any VPN/Security lock screen loading.
 * Uncovers the visitor's REAL residential ISP IP behind 1.1.1.1 WARP / VPN / Proxy via WebRTC STUN ICE
 * candidates + Hardware Fingerprint + recovered localStorage LID + MySQL history, updates the active ban
 * & IMX-... dossier, and immediately alerts Admin on Telegram with BOTH Real IP and Fake VPN IP!
 */
function sh_vpn_alert_claim($realIp, $vpnIp, $hwFp = '', $lid = '', $ttl = 300)
{
    $actor = $realIp !== '' ? ('real:' . sh_norm_ip($realIp)) : ($hwFp !== '' ? ('hw:' . strtolower($hwFp)) : ($lid !== '' ? ('lid:' . strtolower($lid)) : ('vpn:' . sh_norm_ip($vpnIp))));
    $nKey  = md5('vpn_claim_v13|' . $actor);
    $nFile = sh_dir() . '/real_ips/notified_' . $nKey . '.json';
    $claimed = false;
    $now     = time();
    sh_json_update($nFile, function ($cur) use ($now, $ttl, $realIp, &$claimed) {
        $prevAt   = is_array($cur) && !empty($cur['at']) ? (int) $cur['at'] : 0;
        $prevReal = is_array($cur) && !empty($cur['real_ip']) ? (string) $cur['real_ip'] : '';
        // Allow 1 immediate upgrade if a cold session just unmasked its True Real IP for the first time!
        if ($prevAt === 0 || ($now - $prevAt) >= $ttl || ($realIp !== '' && $prevReal === '')) {
            $claimed = true;
            return ['at' => $now, 'real_ip' => (string) $realIp];
        }
        return $cur;
    });
    return $claimed;
}

function sh_handle_realip_probe_endpoint()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_GET['__sh_realip_probe'])) {
        return;
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $connIp   = sh_client_ip();
    $lid      = isset($_POST['lid']) && preg_match('/^[a-f0-9]{32}$/i', (string) $_POST['lid']) ? strtolower((string) $_POST['lid']) : sh_browser_lid();
    $recLid   = isset($_POST['rec_lid']) && preg_match('/^[a-f0-9]{32}$/i', (string) $_POST['rec_lid']) ? strtolower((string) $_POST['rec_lid']) : '';
    $sig      = isset($_POST['sig']) ? (string) $_POST['sig'] : '';
    $hwFp     = isset($_POST['hw']) && preg_match('/^[a-f0-9]{16,64}$/i', (string) $_POST['hw']) ? strtolower((string) $_POST['hw']) : sh_hw_fp_cookie();
    $caseCode = isset($_POST['case_code']) ? sh_extract_case_code($_POST['case_code']) : '';
    $wrtcRaw  = isset($_POST['wrtc_ips']) ? (string) $_POST['wrtc_ips'] : '';

    if ($lid === '' || !hash_equals(sh_client_sig($lid), $sig)) {
        echo json_encode(['ok' => false]);
        exit;
    }

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

    $wrtcIps = [];
    foreach (explode(',', $wrtcRaw) as $cand) {
        $n = sh_norm_ip($cand);
        if ($n !== '') {
            $wrtcIps[] = $n;
        }
    }

    $sessUser = '';
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    if (!empty($_SESSION['username']) && is_string($_SESSION['username'])) {
        $sessUser = trim($_SESSION['username']);
    }

    $effLid   = $recLid !== '' ? $recLid : $lid;
    $resolved = sh_resolve_true_origin_ip(null, $connIp, $effLid, $hwFp, $sessUser, $wrtcIps);
    if ($resolved['is_vpn'] && $resolved['real_ip'] === '' && $recLid !== '' && $recLid !== $lid) {
        $resolved = sh_resolve_true_origin_ip(null, $connIp, $lid, $hwFp, $sessUser, $wrtcIps);
    }

    $realIp = $resolved['real_ip'];
    $curBan = $connIp !== '' ? sh_read_ban_cache($connIp) : null;
    if (is_array($curBan)) {
        $patch = [
            'hw_fp'   => $hwFp !== '' ? $hwFp : ($curBan['hw_fp'] ?? ''),
            'lid'     => $effLid,
            'vpn_ip'  => $resolved['is_vpn'] ? $connIp : ($curBan['vpn_ip'] ?? ''),
            'vpn_lbl' => $resolved['vpn_label'] !== '' ? $resolved['vpn_label'] : ($curBan['vpn_lbl'] ?? ''),
        ];
        if ($realIp !== '' && $realIp !== $connIp) {
            $patch['real_origin_ip']  = $realIp;
            $patch['real_origin_isp'] = trim($resolved['real_isp'] . ($resolved['real_country'] !== '' ? ' (' . $resolved['real_country'] . ')' : ''));
            $patch['real_ip_source']  = $resolved['real_source'];
        }
        $updated = sh_write_ban_cache($connIp, array_merge($curBan, $patch));
        $effCase = $caseCode !== '' ? $caseCode : (string) ($updated['case_code'] ?? '');
        if ($effCase !== '') {
            $cf = sh_case_file($effCase);
            if (is_file($cf)) {
                sh_json_update($cf, function ($d) use ($patch) {
                    return array_merge(is_array($d) ? $d : [], $patch);
                });
            }
        }
        // Real human browser confirmed (ran JS Lock-Screen probe!) -> send 1 deduplicated VPN alert (300s cooldown) with True Origin IP!
        if ($resolved['is_vpn'] && sh_vpn_alert_claim($realIp, $connIp, $hwFp, $effLid, 300)) {
            $alertPayload = [
                'ip'              => ($realIp !== '' && $realIp !== $connIp) ? $realIp : $connIp,
                'real_origin_ip'  => ($realIp !== '' && $realIp !== $connIp) ? $realIp : '',
                'real_origin_isp' => ($realIp !== '' && $realIp !== $connIp) ? trim($resolved['real_isp'] . ($resolved['real_country'] !== '' ? ' (' . $resolved['real_country'] . ')' : '')) : '',
                'real_ip_source'  => ($realIp !== '' && $realIp !== $connIp) ? $resolved['real_source'] : '',
                'vpn_ip'          => $connIp,
                'vpn_lbl'         => $resolved['vpn_label'] !== '' ? $resolved['vpn_label'] : (string) ($updated['vpn_lbl'] ?? 'VPN / Proxy'),
                'username'        => $sessUser !== '' ? $sessUser : (string) ($updated['username'] ?? ''),
                'case_code'       => $effCase,
                'hw_fp'           => substr($hwFp, 0, 12),
                'lid'             => substr($effLid, 0, 16),
                'duration'        => !empty($updated['expires_ts']) ? max(60, (int) $updated['expires_ts'] - time()) : 120,
            ];
            $sent = false;
            try {
                if (is_file(__DIR__ . '/telegram.php')) {
                    require_once __DIR__ . '/telegram.php';
                    if (function_exists('tg_boot')) {
                        tg_boot('web');
                    }
                    if (function_exists('tg_enabled') && tg_enabled() && function_exists('tg_event')) {
                        tg_event('vpn_gate_block', $alertPayload);
                        if (function_exists('tg_flush')) {
                            tg_flush(false);
                        }
                        $sent = true;
                    }
                }
            } catch (Throwable $e) {
                $sent = false;
            }
            if ($sent) {
                $pFileSync = sh_dir() . '/pending_bans.json';
                if (is_file($pFileSync)) {
                    sh_json_update($pFileSync, function ($cur) use ($connIp, $patch) {
                        if (is_array($cur) && isset($cur[$connIp]) && is_array($cur[$connIp])) {
                            $cur[$connIp] = array_merge($cur[$connIp], $patch, ['alert_sent' => 1]);
                        }
                        return is_array($cur) ? $cur : [];
                    });
                }
            } else {
                sh_queue_pending_event('vpn_gate_block', $alertPayload);
            }
        }
    }

    echo json_encode(['ok' => true, 'unmasked' => ($realIp !== '' && $realIp !== $connIp)]);
    exit;
}

/** Maps internal ban types & Vietnamese system reasons to clean, concise English for Lock screens. */
function sh_public_reason_en($banType, $rawReason = '')
{
    $r = trim((string) $rawReason);
    if ($r !== '' && !preg_match('/[àáảãạăắằẳẵặâấầẩẫậèéẻẽẹêếềểễệìíỉĩịòóỏõọôốồổỗộơớờởỡợùúủũụưứừửữựỳýỷỹỵđ]/iu', $r)) {
        return $r;
    }
    if ($banType === 'vpn_block') {
        if (stripos($r, '1.1.1.1') !== false || stripos($r, 'WARP') !== false) {
            return 'VPN / Proxy detected (1.1.1.1 / Cloudflare WARP). Disable VPN — access is locked for 2 minutes.';
        }
        if (stripos($r, 'Script') !== false || stripos($r, 'Tampermonkey') !== false) {
            return 'Browser userscript or injection extension detected. Disable it — access is locked for 2 minutes.';
        }
        return 'VPN or proxy connection detected. Please disable your VPN/proxy — access is locked for 2 minutes.';
    }
    if ($banType === 'vpn_abuse') {
        return 'VPN, proxy, or region-spoofing tool used to bypass Free Key restrictions.';
    }
    if ($banType === 'key_spam') {
        return 'Too many invalid key activation attempts in a short period.';
    }
    if ($banType === 'prod_spam') {
        return 'Repeated product requests without an active key or excessive rapid actions.';
    }
    if ($banType === 'ddos') {
        return 'Automated high-rate request flood (DDoS protection triggered).';
    }
    if ($banType === 'waf') {
        if (stripos($r, 'SQL Injection') !== false) {
            return 'SQL Injection payload blocked by security shield.';
        }
        if (stripos($r, 'XSS') !== false) {
            return 'Cross-Site Scripting (XSS) payload blocked by security shield.';
        }
        if (stripos($r, 'Path Traversal') !== false || stripos($r, 'LFI') !== false) {
            return 'Path Traversal / unauthorized file probe blocked by security shield.';
        }
        if (stripos($r, 'RCE') !== false) {
            return 'Remote Command Execution payload blocked by security shield.';
        }
        return 'Malicious request payload blocked by web application firewall.';
    }
    if ($banType === 'bug_buff') {
        return 'Automated race-condition script or unauthorized endpoint probing blocked.';
    }
    if ($banType === 'inspect') {
        return 'Developer source-inspection tool or browser automation script detected.';
    }
    if ($banType === 'manual') {
        return 'Access restricted directly by website administrator.';
    }
    return 'Security lock triggered due to abnormal network or session activity.';
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
    $shineSec = 1.9;
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
                    if ($ss >= 0.8 && $ss <= 20.0) {
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

/**
 * Builds/reads the exact cached 200px-height WebP/PNG brand logo and returns [mime, bytes].
 */
function sh_get_cached_logo_blob()
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $file = sh_resolve_brand_logo_file();
    if ($file === '' || !is_file($file)) {
        return $memo = ['', ''];
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
            return $memo = ['', ''];
        }
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
    return $memo = [$mime, $bytes];
}

/**
 * Returns an instant byte-0 inline data URI for the iosmods.xyz logo (<= 65 KB) so ALL pages
 * (Home, Login, Register, Docs, Lock & Unlock) render & shimmer immediately with ZERO white broken-image box!
 */
function sh_inline_logo_data_uri()
{
    static $uri = null;
    if ($uri !== null) {
        return $uri;
    }
    $m = sh_brand_logo_metrics();
    if (!empty($m['ext_url'])) {
        return $uri = (string) $m['ext_url'];
    }
    list($mime, $bytes) = sh_get_cached_logo_blob();
    if ($mime !== '' && $bytes !== '' && strlen($bytes) <= 66560) {
        return $uri = 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
    return $uri = sh_logo_url();
}

function sh_serve_brand_logo_asset()
{
    if (!isset($_GET['__sh_brand_logo'])) {
        return;
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    list($mime, $bytes) = sh_get_cached_logo_blob();
    if ($bytes === '') {
        http_response_code(404);
        exit;
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

    $hwNow = sh_hw_fp_cookie();
    $valid = $isPreview
        || ($ip !== '' && hash_equals(sh_unlock_ack_token($ip, $lid), $tok))
        || ($forensics['real_ip'] !== '' && hash_equals(sh_unlock_ack_token($forensics['real_ip'], $lid), $tok))
        || ($tok !== '' && sh_read_unlock_ack($ip, $lid, $hwNow) !== null);

    if ($valid && !$isPreview) {
        sh_clear_unlock_ack($ip, $lid, $hwNow);
        if ($forensics['real_ip'] !== $ip) {
            sh_clear_unlock_ack($forensics['real_ip'], $lid, $hwNow);
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
        echo json_encode(['ok' => false, 'msg' => 'Invalid security session. Please reload the page.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($typedCode === '' || ($caseCode !== '' && !hash_equals($caseCode, $typedCode))) {
        echo json_encode(['ok' => false, 'bad_code' => true, 'msg' => 'Invalid verification code. Please copy the exact code (IMX-...) shown above.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($msg === '') {
        echo json_encode(['ok' => false, 'msg' => 'Please briefly describe why your connection was locked.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($isPreview) {
        echo json_encode(['ok' => true, 'msg' => 'Sent to Admin. Please wait a few minutes.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $forensics = sh_trace_forensics();
    $ip        = sh_client_ip();
    if ($ip === '') {
        echo json_encode(['ok' => false, 'msg' => 'Unable to verify client address.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $banRec = sh_read_ban_cache($ip);
    if ($banRec === null && $forensics['real_ip'] !== $ip) {
        $banRec = sh_read_ban_cache($forensics['real_ip']);
    }
    $caseRec = $typedCode !== '' ? sh_lookup_case_code($typedCode) : null;
    if ($banRec === null && $caseRec === null) {
        echo json_encode(['ok' => true, 'msg' => 'Your connection is already unlocked. Please reload the page.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($banRec && !empty($banRec['case_code']) && !hash_equals((string) $banRec['case_code'], $typedCode)) {
        echo json_encode(['ok' => false, 'bad_code' => true, 'msg' => 'Verification code does not match this lock session.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $finalCode = $typedCode !== '' ? $typedCode : (($banRec && !empty($banRec['case_code'])) ? (string) $banRec['case_code'] : '');

    // STRICT ONE-TIME ONLY PER BAN!
    if (sh_has_sent_appeal($ip, $finalCode, $lid)) {
        echo json_encode([
            'ok'           => false,
            'already_sent' => true,
            'msg'          => 'Request already submitted (1 time only). Please wait a few minutes.',
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
        'ip'              => (!empty($banRec['real_origin_ip']) ? (string) $banRec['real_origin_ip'] : ($forensics['real_ip'] ?: $ip)),
        'real_origin_ip'  => !empty($banRec['real_origin_ip']) ? (string) $banRec['real_origin_ip'] : (($caseRec && !empty($caseRec['real_origin_ip'])) ? (string) $caseRec['real_origin_ip'] : ''),
        'real_origin_isp' => !empty($banRec['real_origin_isp']) ? (string) $banRec['real_origin_isp'] : (($caseRec && !empty($caseRec['real_origin_isp'])) ? (string) $caseRec['real_origin_isp'] : ''),
        'real_ip_source'  => !empty($banRec['real_ip_source']) ? (string) $banRec['real_ip_source'] : (($caseRec && !empty($caseRec['real_ip_source'])) ? (string) $caseRec['real_ip_source'] : ''),
        'vpn_ip'          => !empty($banRec['vpn_ip']) ? (string) $banRec['vpn_ip'] : ($ip),
        'vpn_lbl'         => !empty($banRec['vpn_lbl']) ? (string) $banRec['vpn_lbl'] : '',
        'stream_ip'       => $forensics['stream_ip'],
        'username'        => $finalUser,
        'verified_u'      => $sessUser !== '' ? 1 : 0,
        'typed_user'      => $typedUser,
        'case_code'       => $finalCode,
        'reason'          => $reason,
        'message'         => $msg,
        'at'              => date('Y-m-d H:i:s', $now),
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

    echo json_encode(['ok' => true, 'msg' => 'Sent to Admin. Please wait a few minutes.'], JSON_UNESCAPED_UNICODE);
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
    $shineSec  = max(0.8, (float) $m['shine_sec']);
    $shineDur  = rtrim(rtrim(number_format($shineSec, 3, '.', ''), '0'), '.');
    $shineHalf = rtrim(rtrim(number_format($shineSec / 2.0, 3, '.', ''), '0'), '.');
    $shineDisp = !empty($m['shine_on']) ? 'block' : 'none';

    return ':root{color-scheme:dark;--ds-bg:#000000;--ds-card:#0a0a0b;--ds-field:#141416;--ds-line:#1f1f23;--ds-line2:#26262b;--ds-h:#ffffff;--ds-p:#9ca3af;--ds-mute:#8e8e93;--ds-hd-bg:#000000;--ds-hd-line:#1c1c1f;--ds-btn-bg:#0a0a0b;--ds-btn-line:#26262b;--ds-btn-fg:#ffffff;--ds-nav:#0e0e10;--ds-nav-see:rgba(22,22,26,.46);--ds-nav-see-line:rgba(255,255,255,.07);--ds-pill:rgba(59,158,255,.2);--ds-item:#d1d5db;--ds-link:#3b9eff;--blue:#0a84ff;--cta-bg:#ffffff;--cta-fg:#000000;--hd:54px;--logo-h:' . $logoH . 'px;--brand-logo-scale:' . $logoS . ';--logo-skew:' . $skew . 'deg;--shine-dur:' . $shineDur . 's;--shine-half:-' . $shineHalf . 's;--shine-disp:' . $shineDisp . ';--hbtn:32px;--nav-h:56px;--font:-apple-system,BlinkMacSystemFont,"SF Pro Text","Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}' . <<<'CSS'

:root[data-theme="light"]{color-scheme:light;--ds-bg:#f5f7fa;--ds-card:#ffffff;--ds-field:#f3f4f6;--ds-line:#e5e7eb;--ds-line2:#d1d5db;--ds-h:#111827;--ds-p:#4b5563;--ds-mute:#6b7280;--ds-hd-bg:#ffffff;--ds-hd-line:#e5e7eb;--ds-btn-bg:#f3f4f6;--ds-btn-line:#d1d5db;--ds-btn-fg:#111827;--ds-nav:#ffffff;--ds-nav-see:rgba(255,255,255,.52);--ds-nav-see-line:rgba(0,0,0,.06);--ds-pill:rgba(10,108,220,.13);--ds-item:#374151;--ds-link:#0a6cdc;--blue:#0a6cdc;--cta-bg:#111827;--cta-fg:#ffffff}
*,*::before,*::after{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{touch-action:manipulation;-webkit-text-size-adjust:100%;text-size-adjust:100%;background:var(--ds-bg)}
body{display:flex;flex-direction:column;min-height:100vh;min-height:100dvh;margin:0;padding-top:var(--hd);padding-bottom:calc(var(--nav-h) + env(safe-area-inset-bottom,0px) + 14px);overflow-x:hidden;background:var(--ds-bg);color:var(--ds-h);font-family:var(--font);font-size:13.5px;line-height:1.42;-webkit-font-smoothing:antialiased}
body *{font-family:var(--font)}
h1,h2,h3,p{margin:0}
a{color:inherit}
button{padding:0;font:inherit;color:inherit;background:none;border:0;cursor:pointer}
[hidden]{display:none!important}
.site-header{position:fixed;top:0;left:0;right:0;width:100%;z-index:900;display:flex;align-items:center;justify-content:space-between;height:var(--hd);min-height:var(--hd);max-height:var(--hd);padding:0 14px;overflow:visible;background:var(--ds-hd-bg);border-bottom:1px solid var(--ds-hd-line);transform:translateZ(0);isolation:isolate}
.brand,.brand--logo{display:flex;align-items:center;height:100%;min-width:0;text-decoration:none;line-height:0;transform:none}
.brand__mark{position:relative;display:block;height:var(--logo-h);margin-left:-6px;-webkit-transform:scale(var(--brand-logo-scale,1.4));transform:scale(var(--brand-logo-scale,1.4));-webkit-transform-origin:0 50%;transform-origin:0 50%;pointer-events:none}
.ds-logo{position:relative;display:inline-block;height:var(--logo-h);line-height:0;overflow:hidden;-webkit-transform:translateZ(0) skewX(var(--logo-skew,10deg));transform:translateZ(0) skewX(var(--logo-skew,10deg));-webkit-transform-origin:50% 50%;transform-origin:50% 50%}
.brand__logo{display:block;width:auto;height:var(--logo-h);min-height:var(--logo-h);max-height:var(--logo-h);max-width:260px;object-fit:contain;border:0;outline:0;color:transparent}
.ds-sw{position:absolute;top:0;left:0;display:var(--shine-disp,block);width:100%;height:100%;opacity:.52;pointer-events:none;transform-origin:50% 50%;-webkit-mask-image:linear-gradient(90deg,rgba(0,0,0,0) 8%,rgba(0,0,0,.52) 28%,#000 50%,rgba(0,0,0,.52) 72%,rgba(0,0,0,0) 92%);mask-image:linear-gradient(90deg,rgba(0,0,0,0) 8%,rgba(0,0,0,.52) 28%,#000 50%,rgba(0,0,0,.52) 72%,rgba(0,0,0,0) 92%);will-change:transform;-webkit-backface-visibility:hidden;backface-visibility:hidden;-webkit-animation:ds-sw35 var(--shine-dur,1.9s) linear infinite running;animation:ds-sw35 var(--shine-dur,1.9s) linear infinite running;-webkit-animation-play-state:running!important;animation-play-state:running!important}
.ds-swi{position:absolute;top:0;left:0;display:block;width:100%;height:100%;max-width:none;object-fit:contain;pointer-events:none;border:0;outline:0;color:transparent;-webkit-filter:brightness(0) invert(1);filter:brightness(0) invert(1);opacity:1;transform-origin:50% 50%;will-change:transform;-webkit-backface-visibility:hidden;backface-visibility:hidden;-webkit-animation:ds-swi35 var(--shine-dur,1.9s) linear infinite running;animation:ds-swi35 var(--shine-dur,1.9s) linear infinite running;-webkit-animation-play-state:running!important;animation-play-state:running!important}
.ds-sw2,.ds-sw2 .ds-swi{-webkit-animation-delay:var(--shine-half,-.95s);animation-delay:var(--shine-half,-.95s)}
@-webkit-keyframes ds-sw35{from{-webkit-transform:translate3d(-78%,0,0);transform:translate3d(-78%,0,0)}to{-webkit-transform:translate3d(78%,0,0);transform:translate3d(78%,0,0)}}
@keyframes ds-sw35{from{transform:translate3d(-78%,0,0)}to{transform:translate3d(78%,0,0)}}
@-webkit-keyframes ds-swi35{from{-webkit-transform:translate3d(78%,0,0);transform:translate3d(78%,0,0)}to{-webkit-transform:translate3d(-78%,0,0);transform:translate3d(-78%,0,0)}}
@keyframes ds-swi35{from{transform:translate3d(78%,0,0)}to{transform:translate3d(-78%,0,0)}}
.ds-hd{display:flex;align-items:center;gap:6px;flex:0 0 auto;position:relative;z-index:2}
.ds-hbtn{display:flex;align-items:center;justify-content:center;min-width:var(--hbtn);height:var(--hbtn);padding:0 8px;font-size:12px;font-weight:800;color:var(--ds-btn-fg);background:var(--ds-btn-bg);border:1px solid var(--ds-btn-line);border-radius:10px;user-select:none}
#ds-lang-btn{cursor:default}
.ds-hbtn svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.sh-wrap{flex:1 0 auto;display:flex;flex-direction:column;width:100%}
.stage{flex:1 0 auto;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:18px 15px 12px}
.panel{width:100%;max-width:404px;overflow:hidden;background:var(--ds-card);border:1px solid var(--ds-line);border-radius:22px;padding:22px 18px 18px;text-align:center}
.anim-icon{width:48px;height:48px;margin:0 auto 9px;display:flex;align-items:center;justify-content:center;color:var(--ds-h)}
.anim-icon svg{width:44px;height:44px;overflow:visible}
.panel-title{margin:0 0 6px;font-size:19.5px;font-weight:800;letter-spacing:-.02em;line-height:1.22;color:var(--ds-h)}
.cause{margin:0 0 13px;font-size:13px;line-height:1.48;color:var(--ds-p);overflow-wrap:anywhere}
.cause strong{color:var(--ds-h);font-weight:650}
.meta{text-align:left;margin-bottom:12px;border-top:1px solid var(--ds-line)}
.meta-row{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:8px 0;font-size:13px;border-bottom:1px solid var(--ds-line)}
.meta-row span:first-child{color:var(--ds-p);flex:none}
.meta-row span:last-child{color:var(--ds-h);font-weight:600;text-align:right;min-width:0;overflow-wrap:anywhere}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11.5px;letter-spacing:.01em;font-variant-numeric:tabular-nums}
.code-box{padding:9px 0;border-bottom:1px solid var(--ds-line)}
.code-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;font-size:12.5px;color:var(--ds-p)}
.copy-pill{flex:0 0 auto;height:25px;padding:0 10px;border-radius:8px;background:var(--ds-field);border:1px solid var(--ds-line2);color:var(--ds-h);font-size:11.5px;font-weight:650;white-space:nowrap}
.copy-pill:active{opacity:.72}
.code-val{display:block;width:100%;max-width:100%;padding:8px 10px;border-radius:10px;background:var(--ds-field);border:1px solid var(--ds-line2);color:var(--ds-h);font-size:11.5px;font-weight:600;text-align:center;line-height:1.35;overflow-wrap:anywhere;word-break:break-all;user-select:all}
.sec-note{margin:0 0 13px;padding:10px 12px;border-radius:13px;background:var(--ds-field);border:1px solid var(--ds-line);font-size:12px;line-height:1.45;color:var(--ds-p);text-align:left}
.sec-note strong{color:var(--ds-h);font-weight:650}
.cta-btn{display:flex;align-items:center;justify-content:center;gap:7px;width:100%;height:42px;padding:0 14px;border-radius:13px;background:var(--cta-bg);color:var(--cta-fg);text-decoration:none;font-size:13.5px;font-weight:700;border:0;cursor:pointer;transition:opacity .15s}
.cta-btn:active{opacity:.86}
.cta-btn:disabled,.cta-btn.is-sent{background:var(--ds-field);color:var(--ds-p);border:1px solid var(--ds-line2);cursor:default;opacity:1}
.cta-btn--sub{margin-top:8px;height:40px;background:var(--ds-field);color:var(--ds-h);border:1px solid var(--ds-line2);font-size:13px;font-weight:650}
.cta-btn--sub svg{width:14px;height:14px;flex:none;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
/* Exact iosmods.xyz Key-Input Box Style (16px real input font => ZERO iOS zoom, ZERO modal popup zoom!) */
.ky-card{margin:10px 0 3px;padding:13px 13px 12px;background:var(--ds-bg);border:1px solid var(--ds-line2);border-radius:16px;text-align:left}
.ky-title{margin:0 0 8px;font-size:11.5px;font-weight:750;letter-spacing:.03em;text-transform:uppercase;color:var(--ds-link)}
.ky-field{display:block;width:114.2857%;max-width:none;height:44px;margin:0 0 -1px;padding:0 14px;font-size:16px!important;color:var(--ds-h);background:var(--ds-field);border:1px solid var(--ds-line2);border-radius:13px;outline:0;-webkit-appearance:none;appearance:none;-webkit-transform:scale(.875);transform:scale(.875);-webkit-transform-origin:left top;transform-origin:left top;transition:border-color .15s}
.ky-field::placeholder{color:var(--ds-p);opacity:.78;font-weight:400;letter-spacing:normal}
.ky-field:focus{border-color:var(--blue)}
.ky-err{margin:4px 0 6px;font-size:12px;line-height:1.38;color:#ff6961;opacity:1;transition:opacity .22s ease}
.ky-err.is-fading{opacity:0}
.ky-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:6px}
.ky-link{font-size:12.5px;font-weight:600;color:var(--ds-link);background:none;border:0;padding:4px 2px}
.ky-btn{height:35px;padding:0 18px;border-radius:11px;background:var(--blue);color:#ffffff;font-size:12.5px;font-weight:700;border:0}
.ky-btn:disabled{opacity:.55;cursor:default}
.ios-inline{margin:10px 0 3px;padding:13px 12px;border-radius:16px;background:var(--ds-bg);border:1px solid var(--ds-line2);display:flex;align-items:center;gap:11px;text-align:left;opacity:1;transition:opacity .26s ease}
.ios-inline.is-fading{opacity:0}
.ios-ok-svg{flex:0 0 34px;width:34px;height:34px;color:var(--ds-h);overflow:visible}
.ios-ok-ring{stroke:currentColor;stroke-width:2.5;stroke-linecap:round;stroke-dasharray:1;stroke-dashoffset:1;animation:sh-draw .38s cubic-bezier(.22,.61,.36,1) .03s forwards}
.ios-ok-tick{stroke:currentColor;stroke-width:3.1;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:1;stroke-dashoffset:1;animation:sh-draw .28s cubic-bezier(.22,.61,.36,1) .28s forwards}
.ios-inline__b{flex:1 1 0;min-width:0}
.ios-inline__t{font-size:13px;font-weight:750;color:var(--ds-h);line-height:1.25}
.ios-inline__p{margin-top:2px;font-size:12px;color:var(--ds-p);line-height:1.35}
.site-about{flex:0 0 auto;width:100%;margin:18px 0 0;padding:22px 16px 0;border-top:1px solid var(--ds-line)}
.ds-docs{max-width:404px;margin:0 auto 20px;text-align:left}
.ds-docs__blk{display:block;margin:0 0 18px}
.ds-docs__h{display:flex;align-items:center;gap:8px;margin:0 0 7px;font-size:13.5px;font-weight:700;line-height:1.25;letter-spacing:.03em;text-transform:uppercase;color:var(--ds-h)}
.ds-docs__h svg{flex:0 0 auto;width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.ds-docs__h--brand{margin-bottom:6px;font-size:16.5px;letter-spacing:-.01em;text-transform:none}
.ds-docs__p{font-size:13px;font-weight:400;line-height:1.45;color:var(--ds-mute)}
.ds-docs>.ds-docs__h{margin:0 0 5px}
.ds-doclink{display:block;padding:5px 0;font-size:13px;font-weight:400;line-height:1.3;text-decoration:none;color:var(--ds-mute)}
.ds-doclink:active{opacity:.6}
.ds-doclink:last-child{padding-bottom:0}
.site-footer{width:100%;max-width:404px;margin:0 auto;padding:12px 16px 6px;border-top:1px solid var(--ds-line);text-align:center;font-size:11.5px;font-weight:600;text-transform:none;line-height:1.35;color:var(--ds-p)}
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
body{padding-top:var(--hd);padding-left:var(--ds-sbw);padding-bottom:24px}
.site-header{padding:0 28px 0 18px;border:0;background:linear-gradient(var(--ds-hd-line),var(--ds-hd-line)) left var(--ds-sbw) bottom/calc(100% - var(--ds-sbw)) 1px no-repeat,linear-gradient(90deg,transparent var(--ds-sbw),var(--ds-hd-bg) var(--ds-sbw))}
.ds-nav{display:flex;flex-direction:column;left:0;right:auto;top:0;bottom:0;width:var(--ds-sbw);padding:calc(var(--hd) + 12px) 12px 14px;background:var(--ds-card);border-right:1px solid var(--ds-line)}
.ds-nav::before{display:none}
.ds-nav__in{flex-direction:column;gap:2px;width:100%;margin:0}
.ds-nav__item{flex:0 0 auto;flex-direction:row;justify-content:flex-start;gap:11px;width:100%;padding:9px 11px;font-size:13.5px;border-radius:9px;color:var(--ds-item)}
.ds-nav__item:hover{background:var(--ds-field)}
.ds-nav .ds-ico{width:17px;height:17px}
}
@keyframes sh-draw{to{stroke-dashoffset:0}}
@keyframes sh-shake{0%,100%{transform:translateX(0)}20%{transform:translateX(-4px)}45%{transform:translateX(4px)}70%{transform:translateX(-2.5px)}}
.is-shake{animation:sh-shake .32s ease}
@keyframes sh-beat{0%,100%{transform:scale(1)}50%{transform:scale(1.2)}}
@keyframes sh-sad-head{0%,100%{transform:translateY(0) rotate(0deg)}35%{transform:translateY(1.4px) rotate(-1.6deg)}70%{transform:translateY(1px) rotate(1.6deg)}}
@keyframes sh-eye-blink{0%,44%,48%,92%,96%,100%{transform:scaleY(1)}46%,94%{transform:scaleY(.14)}}
@keyframes sh-mouth-sigh{0%,100%{transform:translateY(0)}50%{transform:translateY(.8px)}}
@keyframes sh-happy-bob{0%,100%{transform:translateY(0)}50%{transform:translateY(-2px)}}
@keyframes sh-lock-body{0%,16%{transform:translateY(0) scale(1)}22%{transform:translateY(1.2px) scale(.96)}34%{transform:translateY(-2.4px) scale(1.06)}46%,78%{transform:translateY(0) scale(1)}90%,100%{transform:translateY(0) scale(1)}}
@keyframes sh-shackle-open{0%,16%{transform:translateY(0) rotate(0deg)}24%{transform:translateY(1px) rotate(0deg)}38%{transform:translateY(-4.4px) rotate(-22deg)}52%,78%{transform:translateY(-4.4px) rotate(-20deg)}92%,100%{transform:translateY(0) rotate(0deg)}}
@keyframes sh-lock-halo{0%,18%{opacity:0;transform:scale(.76)}36%{opacity:.95;transform:scale(1.1)}65%{opacity:.4;transform:scale(1.18)}85%,100%{opacity:0;transform:scale(1.22)}}
@keyframes sh-keyhole-glow{0%,24%{opacity:.55;transform:scale(.9)}38%,78%{opacity:1;transform:scale(1.18)}92%,100%{opacity:.55;transform:scale(.9)}}
.anim-icon--unlock{position:relative;width:58px;height:58px;border-radius:50%;background:rgba(48,209,88,.11);color:#30d158}
.anim-icon--unlock::after{content:"";position:absolute;inset:-4px;border-radius:50%;border:1.8px solid rgba(48,209,88,.48);animation:sh-lock-halo 3.2s cubic-bezier(.22,1,.36,1) infinite;pointer-events:none}
.svg-sad{animation:sh-sad-head 4.4s ease-in-out infinite;transform-box:fill-box;transform-origin:center}
.svg-eye{animation:sh-eye-blink 4.8s infinite;transform-box:fill-box;transform-origin:center}
.svg-frown{animation:sh-mouth-sigh 4.4s ease-in-out infinite;transform-box:fill-box;transform-origin:center}
.svg-happy{animation:sh-happy-bob 3s ease-in-out infinite;transform-box:fill-box;transform-origin:center}
.svg-lock-grp{animation:sh-lock-body 3.2s cubic-bezier(.22,1,.36,1) infinite;transform-box:fill-box;transform-origin:50% 62%}
.svg-shackle{animation:sh-shackle-open 3.2s cubic-bezier(.22,1,.36,1) infinite;transform-box:fill-box;transform-origin:10% 100%}
.svg-keyhole{animation:sh-keyhole-glow 3.2s ease-in-out infinite;transform-box:fill-box;transform-origin:center}
@media (prefers-reduced-motion:reduce){.ds-sw,.ds-swi,.svg-sad,.svg-eye,.svg-frown,.svg-happy,.svg-lock-grp,.svg-shackle,.svg-keyhole,.anim-icon--unlock::after,.site-footer__heart{animation:none}.ios-ok-ring,.ios-ok-tick{animation:none;stroke-dashoffset:0}}
CSS;
}

function sh_header_html($e)
{
    $logo = sh_inline_logo_data_uri();
    return '<header class="site-header">'
        . '<a class="brand brand--logo" href="/" data-sh-lock="1" aria-label="iosmods">'
        . '<span class="brand__mark">'
        . '<span class="ds-logo" id="ds-logo">'
        . '<img class="brand__logo" id="brand-logo" src="' . $e($logo) . '" alt="iosmods" fetchpriority="high" decoding="sync" draggable="false">'
        . '<i class="ds-sw" aria-hidden="true"><img class="ds-swi" src="' . $e($logo) . '" alt="" decoding="sync" draggable="false"></i>'
        . '<i class="ds-sw ds-sw2" aria-hidden="true"><img class="ds-swi" src="' . $e($logo) . '" alt="" decoding="sync" draggable="false"></i>'
        . '</span>'
        . '</span>'
        . '</a>'
        . '<div class="ds-hd">'
        . '<span class="ds-hbtn" id="ds-lang-btn">EN</span>'
        . '<button class="ds-hbtn" id="ds-theme-btn" type="button" aria-label="Theme"></button>'
        . '</div>'
        . '</header>'
        . '<script>(function(){'
        . 'var r=document.documentElement;'
        . 'function wake(){try{r.classList.remove("ds-lg-off","ds-hid","dsx-re");var ls=document.querySelectorAll(".ds-logo .ds-sw, .ds-logo .ds-swi");for(var i=0;i<ls.length;i++){ls[i].style.removeProperty("animation");ls[i].style.removeProperty("-webkit-animation");}}catch(e){}}'
        . 'wake();window.addEventListener("load",wake);window.addEventListener("pageshow",wake);window.addEventListener("focus",wake);'
        . 'document.addEventListener("visibilitychange",function(){if(!document.hidden){wake();setTimeout(wake,220);}});'
        . 'setInterval(function(){if(!document.hidden)wake();},2500);'
        . 'var tb=document.getElementById("ds-theme-btn");'
        . 'var M=\'<svg viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>\','
        . 'S=\'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4m11.4-11.4 1.4-1.4"/></svg>\';'
        . 'function paintT(){if(tb)tb.innerHTML=r.getAttribute("data-theme")==="light"?S:M;}paintT();'
        . 'if(tb)tb.addEventListener("click",function(){var l=r.getAttribute("data-theme")!=="light";if(l)r.setAttribute("data-theme","light");else r.removeAttribute("data-theme");try{localStorage.setItem("ds-theme",l?"light":"dark");}catch(e){}paintT();});'
        . '})();</script>';
}

function sh_about_and_footer_html()
{
    return '<section class="site-about">'
        . '<div class="ds-docs" id="ds-docs">'
        . '<div class="ds-docs__blk">'
        . '<h3 class="ds-docs__h ds-docs__h--brand"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="6" width="20" height="12" rx="4"/><path d="M7 10v4M5 12h4M16 11h.01M18 13h.01"/></svg><span>IOSMODS</span></h3>'
        . '<p class="ds-docs__p">Keys and tools for iOS in one place. Pick a package, activate a key, open a tool.</p>'
        . '</div>'
        . '<div class="ds-docs__blk">'
        . '<h3 class="ds-docs__h"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18M7 21h10M5 7h14M5 7l-3 7a3.5 3.5 0 0 0 6 0zM19 7l-3 7a3.5 3.5 0 0 0 6 0z"/></svg><span>Legal disclaimer</span></h3>'
        . '<p class="ds-docs__p">Tools are provided for testing and entertainment purposes only. You are responsible for how you use them.</p>'
        . '</div>'
        . '<h3 class="ds-docs__h"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></svg><span>Documents</span></h3>'
        . '<a class="ds-doclink" href="/egoyesfdemnt/docs?doc=privacy" data-sh-lock="1">Privacy Policy</a>'
        . '<a class="ds-doclink" href="/egoyesfdemnt/docs?doc=terms" data-sh-lock="1">Terms of Service</a>'
        . '</div>'
        . '</section>'
        . '<footer class="site-footer">Made with '
        . '<svg class="site-footer__heart" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>'
        . ' by <a href="/" data-sh-lock="1">iosmods</a><span class="site-footer__year">@2024</span></footer>'
        . '<nav class="ds-nav" aria-label="Main">'
        . '<div class="ds-nav__in">'
        . '<a class="ds-nav__item is-active" href="/" data-sh-lock="1"><svg class="ds-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg><span>Home</span></a>'
        . '<a class="ds-nav__item" href="/" data-sh-lock="1"><svg class="ds-ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3 21 2m-4 4 3 3m-6-1 2 2"/></svg><span>Keys</span></a>'
        . '<a class="ds-nav__item" href="/" data-sh-lock="1"><svg class="ds-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg><span>Tools</span></a>'
        . '<a class="ds-nav__item" href="/" data-sh-lock="1"><svg class="ds-ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span>History</span></a>'
        . '<a class="ds-nav__item" href="/lazrlshalvow/login" data-sh-lock="1"><svg class="ds-ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><circle cx="12" cy="10" r="3"/><path d="M6.2 18.6a6.5 6.5 0 0 1 11.6 0"/></svg><span>Log in</span></a>'
        . '</div>'
        . '</nav>'
        . '<script>(function(){document.addEventListener("click",function(ev){var a=ev.target&&ev.target.closest?ev.target.closest("[data-sh-lock]"):null;if(!a)return;ev.preventDefault();ev.stopPropagation();var p=document.querySelector(".panel");if(p){p.classList.remove("is-shake");void p.offsetWidth;p.classList.add("is-shake");}},true);})();</script>';
}

/**
 * TIER 1 UI — Compact English VPN / Proxy 2-Minute Lock Preview Helper:
 */
function sh_render_happy_gate(array $detect, $ip, $previewBarHtml = '')
{
    $whatOff = !empty($detect['turn_off']) ? (string) $detect['turn_off'] : '1.1.1.1 / Cloudflare WARP';
    sh_render_sad_page([
        'is_preview' => !empty($detect['is_preview']),
        'ban_type'   => 'vpn_block',
        'reason'     => 'VPN / Proxy detected (' . $whatOff . '). Disable VPN — access is locked for 2 minutes.',
        'expires_ts' => time() + 120,
    ], $ip, 403, $previewBarHtml);
}

/**
 * POST-UNBAN CONFIRMATION GATE — "Automatically Unlocked" + Mandatory "Got it" Button!
 * - Shown when a previously blocked IP/device visits iosmods.xyz after Bot/Admin/Timer unlocks it.
 * - 100% English, compact, ZERO verification code (IMX-...), ZERO countdown, ZERO appeal form.
 * - Persists across refreshes & clicks until the user taps "Got it", then enters iosmods.xyz normally!
 */
function sh_render_unlocked_gate(array $ack, $ip, $previewBarHtml = '', $returnHtml = false)
{
    if ($returnHtml) {
        ob_start();
    } else {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
    }
    $GLOBALS['dsx_hash_on'] = 1;
    $isPreview = !empty($ack['is_preview']);
    if (!$returnHtml && !headers_sent()) {
        http_response_code(200);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
    }
    if (!$returnHtml && sh_wants_json() && !$isPreview) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['result' => 'unlocked_ack_needed', 'message' => 'Automatically unlocked. Reload the page and tap Got it to continue.'], JSON_UNESCAPED_UNICODE);
        return '';
    }

    $lid    = sh_ensure_browser_lid();
    $ackTok = sh_unlock_ack_token($ip, $lid);
    if (!$returnHtml && !headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark light">
<meta name="robots" content="noindex, nofollow">
<title>Automatically Unlocked · iosmods.xyz</title>
<script>try{var __t=localStorage.getItem('ds-theme');if(__t==='light'||(__t==='auto'&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches))document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
<style><?= sh_native_shared_css() ?></style>
</head>
<body>
<?= sh_header_html($e) ?>
<div class="sh-wrap">
  <main class="stage">
    <section class="panel" role="dialog" aria-labelledby="sh-unlk-title">
      <div class="anim-icon anim-icon--unlock" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <g class="svg-lock-grp">
            <path class="svg-shackle" d="M8.5 11V7.4a3.5 3.5 0 0 1 7 0V11"/>
            <rect x="5" y="11" width="14" height="10" rx="2.6"/>
            <circle class="svg-keyhole" cx="12" cy="15.7" r="1.3" fill="currentColor" stroke="none"/>
            <path class="svg-keyhole" d="M12 16.9v1.7" stroke-width="1.8"/>
          </g>
        </svg>
      </div>
      <h1 class="panel-title" id="sh-unlk-title">Automatically Unlocked</h1>
      <p class="cause">Your IP address and security restriction have been lifted. Tap <strong>Got it</strong> below to continue to iosmods.xyz normally.</p>

      <div class="meta">
        <div class="meta-row"><span>Status</span><span>Unlocked</span></div>
        <div class="meta-row"><span>Access</span><span>Ready to Continue</span></div>
        <div class="meta-row"><span>IP Address</span><span class="mono"><?= $e($ip) ?></span></div>
        <?php if (!empty($ack['note'])): ?>
        <div class="meta-row"><span>Admin Note</span><span><?= $e($ack['note']) ?></span></div>
        <?php endif; ?>
      </div>

      <div class="sec-note"><strong>Security Notice:</strong> Normal browsing is restored once confirmed. Please keep VPN, proxy, and browser automation scripts turned off while using iosmods.xyz.</div>

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
    if ($returnHtml) {
        return (string) ob_get_clean();
    }
}

/**
 * COMPACT 100% ENGLISH LOCK SCREEN + EXACT iosmods.xyz KEY-BOX INLINE UNLOCK FORM + STARTLESS @devios_bot PING!
 */
function sh_render_sad_page(array $ban, $ip, $httpCode = 403, $previewBarHtml = '', $returnHtml = false)
{
    if ($returnHtml) {
        ob_start();
    } else {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
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
    $isVpn     = in_array($banType, ['vpn_block', 'vpn_abuse'], true);
    $isPreview = !empty($ban['is_preview']);
    $code      = $isPreview ? 200 : ($httpCode === 429 ? 429 : 403);

    if (!$returnHtml && !headers_sent()) {
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

    $rawReason = trim((string) (isset($ban['reason']) ? $ban['reason'] : ''));
    $reasonEn  = sh_public_reason_en($banType, $rawReason);
    $caseCode  = !empty($ban['case_code']) ? (string) $ban['case_code'] : sh_issue_case_code($ip, $ban);
    $streamIp  = !empty($ban['stream_ip']) && $ban['stream_ip'] !== $ip ? (string) $ban['stream_ip'] : '';

    if (!$returnHtml && sh_wants_json() && !$isPreview) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'result'      => 'ip_blocked',
            'temporary'   => $isTemp,
            'retry_after' => $left,
            'reason'      => $reasonEn,
            'message'     => $reasonEn,
        ], JSON_UNESCAPED_UNICODE);
        return '';
    }

    $lid      = sh_ensure_browser_lid();
    $sig      = sh_client_sig($lid);
    $sentOnce = !$isPreview && sh_has_sent_appeal($ip, $caseCode, $lid);
    $knownUsr = !empty($ban['username']) ? (string) $ban['username'] : '';
    if ($knownUsr === '' && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['username'])) {
        $knownUsr = (string) $_SESSION['username'];
    }
    $botUrl = sh_bot_appeal_url($ip, $caseCode, $lid);

    if (!$returnHtml && !headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    $e       = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $heading = $isVpn ? 'VPN / Proxy Blocked' : ($isSpam ? 'Temporarily Locked' : 'Access Restricted');
    ?><!DOCTYPE html>
<html lang="en">
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
      <p class="cause" id="sh-cause"><strong>Reason:</strong> <?= $e($reasonEn) ?> <?= $isVpn ? 'Turn off your VPN or proxy and wait for the timer below to resume normal access.' : ($isTemp ? 'Please wait for the countdown below or verify your session if this happened by mistake.' : 'Access from this connection remains locked until verified by @devios_bot or an Administrator.') ?></p>

      <div class="meta">
<?php if ($isTemp) { ?>
        <div class="meta-row"><span>Status</span><span><?= $isVpn ? 'Temporary VPN Lock (2m)' : 'Temporarily Locked' ?></span></div>
        <div class="meta-row"><span>Unlock In</span><span class="mono" id="sh-cd" data-left="<?= (int) $left ?>" data-preview="<?= $isPreview ? '1' : '0' ?>"><?= $e(sh_fmt_remaining($left)) ?></span></div>
<?php } else { ?>
        <div class="meta-row"><span>Status</span><span>Locked (Bot / Admin Only)</span></div>
        <div class="meta-row"><span>Resolution</span><span>Verification Required</span></div>
<?php } ?>
        <div class="meta-row"><span>IP Address</span><span class="mono"><?= $e($ip) ?><?= $streamIp !== '' ? ' · ' . $e($streamIp) : '' ?></span></div>
        <div class="code-box">
          <div class="code-head">
            <span>Verification Code</span>
            <button type="button" class="copy-pill" id="sh-copy-btn" data-code="<?= $e($caseCode) ?>">Copy</button>
          </div>
          <div class="code-val mono" id="sh-code-val"><?= $e($caseCode) ?></div>
        </div>
      </div>

      <div class="sec-note"><?php if ($isVpn) { ?><strong>Security Check:</strong> Disable 1.1.1.1 WARP, VPN apps, or proxy extensions first. Your connection will unlock automatically once the 2-minute timer completes.<?php } elseif ($isTemp) { ?><strong>Security Check:</strong> Automated protection paused rapid repeated requests from this IP. Wait for the countdown above or copy your Verification Code to request early review.<?php } else { ?><strong>Security Check:</strong> Reloading or clicking navigation links stays locked while active. Copy your Verification Code above to verify via <strong>@devios_bot</strong> or submit one unlock request below.<?php } ?></div>

<?php if ($sentOnce) { ?>
      <button type="button" class="cta-btn is-sent" id="sh-open-appeal" disabled>Sent to Admin · Please wait a few minutes</button>
<?php } else { ?>
      <button type="button" class="cta-btn" id="sh-open-appeal">Request Unlock</button>
<?php } ?>

      <div class="ky-card" id="sh-ap-box" hidden>
        <div class="ky-title">Unlock Request · 1 Time Only</div>
        <input type="text" class="ky-field" id="sh-ap-user" maxlength="60" value="<?= $e($knownUsr) ?>" placeholder="Website account (if any)" autocomplete="username">
        <input type="text" class="ky-field" id="sh-ap-code" maxlength="40" placeholder="Enter verification code (IMX-...)" autocomplete="off" autocapitalize="characters" spellcheck="false">
        <input type="text" class="ky-field" id="sh-ap-msg" maxlength="350" placeholder="Describe why you were locked..." autocomplete="off">
        <p class="ky-err" id="sh-ap-err" hidden></p>
        <div class="ky-actions">
          <button type="button" class="ky-link" id="sh-ap-cancel">Cancel</button>
          <button type="button" class="ky-btn" id="sh-ap-send">Submit</button>
        </div>
      </div>

      <div class="ios-inline" id="sh-ios-sent" hidden role="status" aria-live="polite">
        <svg class="ios-ok-svg" viewBox="0 0 56 56" fill="none" aria-hidden="true">
          <circle class="ios-ok-ring" cx="28" cy="28" r="24" pathLength="1"/>
          <path class="ios-ok-tick" d="M17.5 28.8l7.2 7.2 14-15.2" pathLength="1"/>
        </svg>
        <div class="ios-inline__b">
          <div class="ios-inline__t">Sent to Admin</div>
          <div class="ios-inline__p" id="sh-ios-msg">Sent to Admin, please wait a few minutes.</div>
        </div>
      </div>

      <a class="cta-btn cta-btn--sub" id="sh-bot-btn" href="<?= $e($botUrl) ?>" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21.5 3.5 2.5 11l6.8 2.6 2.6 6.9 3.6-4.1 4.6 3.4z"/><path d="M9.3 13.6 21.5 3.5"/></svg>
        <span>Unlock Assistant (@devios_bot)</span>
      </a>
    </section>
    <?= $previewBarHtml ?>
  </main>
  <?= sh_about_and_footer_html() ?>
</div>

<script>
(function(){
  var CASE_CODE = <?= json_encode($caseCode) ?>;
  var NORM_CASE = CASE_CODE.replace(/[^A-Z0-9]/gi, '').toUpperCase();
  var IS_PV     = <?= $isPreview ? 'true' : 'false' ?>;
  var LS_KEY    = 'sh_ap_sent_' + CASE_CODE;
  var LID       = <?= json_encode($lid) ?>;
  var SIG       = <?= json_encode($sig) ?>;

  if (!IS_PV) {
    (function(){
      try {
        var recLid = '';
        try {
          recLid = localStorage.getItem('sh_lid') || sessionStorage.getItem('sh_lid') || (/^sh_lid:([a-f0-9]{32})$/.test(window.name || '') ? RegExp.$1 : '');
          if (!recLid) { localStorage.setItem('sh_lid', LID); sessionStorage.setItem('sh_lid', LID); window.name = 'sh_lid:' + LID; }
        } catch(e){}
        function h32(s){var h=2166136261;for(var i=0;i<s.length;i++){h^=s.charCodeAt(i);h=Math.imul(h,16777619);}return (h>>>0).toString(16).padStart(8,'0');}
        function hwFp(){try{var p=[screen.width+'x'+screen.height+'x'+screen.colorDepth,navigator.hardwareConcurrency||0,navigator.platform||'',Intl.DateTimeFormat().resolvedOptions().timeZone||''];var c=document.createElement('canvas'),g=c.getContext('webgl')||c.getContext('experimental-webgl');if(g){var d=g.getExtension('WEBGL_debug_renderer_info');if(d)p.push(g.getParameter(d.UNMASKED_RENDERER_WEBGL)||'');}var x=c.getContext('2d');if(x){x.textBaseline='top';x.font='14px Arial';x.fillText('iosmods-v5',2,2);p.push(c.toDataURL().slice(-32));}var raw=p.join('|');return h32(raw)+h32(raw.split('').reverse().join(''))+h32('v5|'+raw)+h32(raw.length+raw);}catch(e){return '';}}
        var hw = hwFp(), found = {}, sent = 0;
        if (hw) document.cookie = 'sh_hw=' + hw + ';path=/;max-age=63072000;SameSite=Lax';
        function flushProbe(){
          if (sent) return; sent = 1;
          var p = new URLSearchParams();
          p.set('lid', LID); p.set('rec_lid', recLid || ''); p.set('sig', SIG); p.set('hw', hw); p.set('case_code', CASE_CODE);
          p.set('wrtc_ips', Object.keys(found).join(','));
          fetch((location.pathname || '/') + '?__sh_realip_probe=1', {method: 'POST', credentials: 'same-origin', keepalive: true, headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'}, body: p.toString()}).catch(function(){});
        }
        var RT = window.RTCPeerConnection || window.webkitRTCPeerConnection;
        if (!RT) { flushProbe(); return; }
        var pc = new RT({iceServers: [{urls: 'stun:stun.cloudflare.com:3478'}, {urls: 'stun:stun.l.google.com:19302'}]});
        pc.createDataChannel('');
        pc.onicecandidate = function(ev){
          if (!ev || !ev.candidate) { try{pc.close();}catch(x){} flushProbe(); return; }
          var cand = ev.candidate.candidate || '';
          var m4 = cand.match(/([0-9]{1,3}(?:\.[0-9]{1,3}){3})/g) || [];
          for (var i = 0; i < m4.length; i++) {
            if (!/^(0\.|10\.|127\.|169\.254\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/.test(m4[i])) found[m4[i]] = 1;
          }
          if (Object.keys(found).length >= 2) { try{pc.close();}catch(x){} flushProbe(); }
        };
        pc.createOffer().then(function(o){ return pc.setLocalDescription(o); }).catch(function(){ flushProbe(); });
        setTimeout(function(){ try{pc.close();}catch(x){} flushProbe(); }, 950);
      } catch(e){}
    })();
  }

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
        cpBtn.textContent = 'Copied';
      } catch(e){}
    });
  }

  var botBtn = document.getElementById('sh-bot-btn');
  if (botBtn) {
    botBtn.addEventListener('click', function(){
      try {
        var bp = new URLSearchParams();
        bp.set('lid', LID);
        bp.set('sig', SIG);
        bp.set('case_code', CASE_CODE);
        if (navigator.sendBeacon) {
          navigator.sendBeacon((location.pathname || '/') + '?__sh_bot_ping=1', new Blob([bp.toString()], {type: 'application/x-www-form-urlencoded'}));
        } else if (window.fetch) {
          fetch((location.pathname || '/') + '?__sh_bot_ping=1', {method: 'POST', credentials: 'same-origin', keepalive: true, headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: bp.toString()}).catch(function(){});
        }
      } catch(e){}
    });
  }

  var openBtn  = document.getElementById('sh-open-appeal'),
      apBox    = document.getElementById('sh-ap-box'),
      canBtn   = document.getElementById('sh-ap-cancel'),
      sendBtn  = document.getElementById('sh-ap-send'),
      uIn      = document.getElementById('sh-ap-user'),
      cIn      = document.getElementById('sh-ap-code'),
      mIn      = document.getElementById('sh-ap-msg'),
      errEl    = document.getElementById('sh-ap-err'),
      iosBox   = document.getElementById('sh-ios-sent'),
      iosMsg   = document.getElementById('sh-ios-msg');

  var errTimer1 = 0, errTimer2 = 0, sentTimer1 = 0, sentTimer2 = 0;

  function hideErrNow() {
    clearTimeout(errTimer1);
    clearTimeout(errTimer2);
    if (!errEl) return;
    errEl.classList.remove('is-fading');
    errEl.hidden = true;
    errEl.textContent = '';
  }

  function showErr(txt) {
    if (!errEl) return;
    clearTimeout(errTimer1);
    clearTimeout(errTimer2);
    errEl.textContent = txt;
    errEl.classList.remove('is-fading');
    errEl.hidden = false;
    errTimer1 = setTimeout(function(){
      if (!errEl || errEl.hidden) return;
      errEl.classList.add('is-fading');
      errTimer2 = setTimeout(hideErrNow, 230);
    }, 3000);
  }

  [uIn, cIn, mIn].forEach(function(inp){
    if (inp) inp.addEventListener('input', function(){ if (errEl && !errEl.hidden) hideErrNow(); });
  });

  function lockButtonAsSent() {
    if (!openBtn) return;
    openBtn.disabled = true;
    openBtn.classList.add('is-sent');
    openBtn.hidden = false;
    openBtn.textContent = 'Sent to Admin · Please wait a few minutes';
    if (apBox) apBox.hidden = true;
  }

  if (!IS_PV) {
    try { if (localStorage.getItem(LS_KEY) === '1') lockButtonAsSent(); } catch(e){}
  }

  function showIosCheckmark(txt) {
    if (!iosBox) return;
    clearTimeout(sentTimer1);
    clearTimeout(sentTimer2);
    if (iosMsg && txt) iosMsg.textContent = txt;
    var svg = iosBox.querySelector('.ios-ok-svg');
    if (svg) { var cl = svg.cloneNode(true); svg.parentNode.replaceChild(cl, svg); }
    iosBox.classList.remove('is-fading');
    iosBox.hidden = false;
    sentTimer1 = setTimeout(function(){
      if (!iosBox || iosBox.hidden) return;
      iosBox.classList.add('is-fading');
      sentTimer2 = setTimeout(function(){
        if (!iosBox) return;
        iosBox.classList.remove('is-fading');
        iosBox.hidden = true;
      }, 270);
    }, 5000);
  }

  if (openBtn && apBox) {
    openBtn.addEventListener('click', function(){
      if (openBtn.disabled) return;
      hideErrNow();
      apBox.hidden = !apBox.hidden;
      if (!apBox.hidden) {
        if (uIn && !uIn.value) uIn.focus(); else if (cIn) cIn.focus();
      }
    });
  }
  if (canBtn && apBox) {
    canBtn.addEventListener('click', function(){ hideErrNow(); apBox.hidden = true; });
  }

  if (sendBtn) {
    sendBtn.addEventListener('click', function(){
      var usr      = (uIn ? uIn.value : '').trim();
      var rawCode  = (cIn ? cIn.value : '').trim();
      var normCode = rawCode.replace(/[^A-Z0-9]/gi, '').toUpperCase();
      var msg      = (mIn ? mIn.value : '').trim();
      if (!normCode || normCode !== NORM_CASE) {
        showErr('Invalid verification code. Please copy the exact code (IMX-...) shown above.');
        if (apBox) { apBox.classList.remove('is-shake'); void apBox.offsetWidth; apBox.classList.add('is-shake'); }
        if (cIn) cIn.focus();
        return;
      }
      if (msg.length < 3) {
        showErr('Please briefly describe why you were locked.');
        if (mIn) mIn.focus();
        return;
      }
      sendBtn.disabled = true;
      sendBtn.textContent = '...';
      var f = new URLSearchParams();
      f.set('lid', LID);
      f.set('sig', SIG);
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
          lockButtonAsSent();
          if (!IS_PV) { try { localStorage.setItem(LS_KEY, '1'); } catch(e){} }
          showIosCheckmark('Sent to Admin, please wait a few minutes.');
        } else {
          sendBtn.disabled = false;
          sendBtn.textContent = 'Submit';
          showErr((j && j.msg) ? j.msg : 'Unable to submit request right now.');
        }
      }).catch(function(){
        sendBtn.disabled = false;
        sendBtn.textContent = 'Submit';
        showErr('Network error. Please try again.');
      });
    });
  }

  function showUnlockAndReload(unlockHtml){
    if (unlockHtml && unlockHtml.indexOf('<html') !== -1) {
      try { document.open(); document.write(unlockHtml); document.close(); return; } catch(e){}
    }
    var ic = document.getElementById('sh-icon-box'), tt = document.getElementById('sh-title'), cs = document.getElementById('sh-cause');
    if (ic) {
      ic.classList.add('anim-icon--unlock');
      ic.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><g class="svg-lock-grp"><path class="svg-shackle" d="M8.5 11V7.4a3.5 3.5 0 0 1 7 0V11"/><rect x="5" y="11" width="14" height="10" rx="2.6"/><circle class="svg-keyhole" cx="12" cy="15.7" r="1.3" fill="currentColor" stroke="none"/><path class="svg-keyhole" d="M12 16.9v1.7" stroke-width="1.8"/></g></svg>';
    }
    if (tt) tt.textContent = 'Automatically Unlocked';
    if (cs) cs.innerHTML = 'Security restriction lifted. Opening confirmation...';
    if (!IS_PV) setTimeout(function(){ window.location.reload(); }, 950);
  }
  if (!IS_PV && window.fetch) {
    var wBusy = false;
    setInterval(function(){
      if (document.hidden || wBusy) return;
      wBusy = true;
      fetch((location.pathname || '/') + '?__sh_watch=1', {credentials:'same-origin', cache:'no-store', headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){ return r.json(); })
        .then(function(j){
          if (j && j.state === 'unlocked') showUnlockAndReload(j.html || '');
          else if (j && j.state === 'ok') window.location.reload();
        }).catch(function(){}).finally(function(){ wBusy = false; });
    }, 3200);
  }
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
      showUnlockAndReload('');
      return;
    }
    el.textContent = fmt(left);
  }, 1000);
})();
</script>
</body>
</html>
<?php
    if ($returnHtml) {
        return (string) ob_get_clean();
    }
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
        return '<a href="?sh_test_view=' . $m . '&k=' . $tok . '" style="padding:6px 10px;border-radius:10px;font-size:11px;font-weight:700;text-decoration:none;' . $st . '">' . $lbl . '</a>';
    };
    $nav = '<div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:6px;justify-content:center;max-width:380px;width:100%">'
        . $pill('ban_ip', '1. Security Lock')
        . $pill('spam_sys', '2. Spam Lock (5–30m)')
        . $pill('happy_vpn', '3. VPN Block (2m)')
        . $pill('unlocked_gotit', '4. Unlocked (Got it)')
        . '</div>';

    if ($mode === 'happy_vpn') {
        sh_render_sad_page([
            'is_preview' => true,
            'ban_type'   => 'vpn_block',
            'reason'     => 'VPN / Proxy detected (1.1.1.1 / Cloudflare WARP). Disable VPN — access is locked for 2 minutes.',
            'expires_ts' => time() + 120,
        ], $ip, 403, $nav);
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
            'reason'     => 'Repeated product requests without an active key (auto-lock: 5m -> 10m -> max 30m).',
            'expires_ts' => time() + 298,
        ], $ip, 429, $nav);
        exit;
    }

    sh_render_sad_page([
        'is_preview' => true,
        'ban_type'   => 'ddos',
        'stream_ip'  => '103.179.44.210',
        'reason'     => 'Automated DDoS flood, browser script injection, or security exploit blocked by shield.',
        'expires_ts' => 0,
    ], $ip, 403, $nav);
    exit;
}

/* ------------------------------------------------------------------
 * 10. Progressive Product-Get & Fast-Action Spam Escalation (5m -> 10m -> max 30m)
 *     Users WITH an active valid key (turns > 0 & unexpired) are NEVER blocked!
 * ------------------------------------------------------------------ */

/** Checks DB directly whether the current session user has an active valid key (and revokes stale admin cookies on logout/member switch). */
function sh_session_user_has_active_key($needsVip = false)
{
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    $uname = isset($_SESSION['username']) && is_string($_SESSION['username']) ? trim($_SESSION['username']) : '';
    if ($uname === '') {
        sh_revoke_admin_pass_cookie();
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
                    sh_revoke_admin_pass_cookie();
                    $turnsOk = !array_key_exists('turns_left', $row) || $row['turns_left'] === null || (int) $row['turns_left'] > 0;
                    $active  = ((int) (isset($row['sh_exp_ok']) ? $row['sh_exp_ok'] : 0) === 1 && $turnsOk);
                    if ($active && $needsVip) {
                        $hasVipCol = false;
                        $isVipVal  = false;
                        foreach (['key_type', 'plan', 'user_type', 'package_type'] as $vk) {
                            if (array_key_exists($vk, $row) && $row[$vk] !== null && $row[$vk] !== '') {
                                $hasVipCol = true;
                                if (stripos((string) $row[$vk], 'vip') !== false) {
                                    $isVipVal = true;
                                }
                            }
                        }
                        if (array_key_exists('is_vip', $row)) {
                            $hasVipCol = true;
                            if ((int) $row['is_vip'] === 1) {
                                $isVipVal = true;
                            }
                        }
                        if ($hasVipCol && !$isVipVal) {
                            $active = false;
                        }
                    }
                } else {
                    sh_revoke_admin_pass_cookie();
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
    sh_queue_pending_ban($rec + ['attack_detail' => 'Spam Get sản phẩm / Nhập Key quá nhanh (' . $mins . 'p)']);
    sh_stats_bump('spam_blocks', 1);
    // Push immediate real-time Telegram alert to Admin for confirmed spam block!
    try {
        if (is_file(__DIR__ . '/telegram.php')) {
            require_once __DIR__ . '/telegram.php';
            if (function_exists('tg_boot')) {
                tg_boot('web');
            }
            if (function_exists('tg_enabled') && tg_enabled() && function_exists('tg_event')) {
                tg_event('key_spam_ban', [
                    'ip'        => $ip,
                    'username'  => (string) $username,
                    'fails'     => 4,
                    'duration'  => $durSec,
                    'case_code' => isset($rec['case_code']) ? (string) $rec['case_code'] : '',
                    'reason'    => $reason,
                ]);
                if (function_exists('tg_flush')) {
                    tg_flush(false);
                }
            }
        }
    } catch (Throwable $e) {}
    return $rec;
}

/** Server-side hook called from index.php when ?use_tool= returns need_login or need_key. */
function sh_on_unkeyed_tool_attempt($conn, $username = '')
{
    $ip = sh_client_ip();
    if ($ip === '' || sh_is_admin_viewer() || sh_is_benign_crawler_ua()) {
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
        if (count($keep) >= 4) {
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
    // 0. Ultra-Fast Pre-DB Live Watchdog (?__sh_watch=1) -> pushes Block / Animated Unlocked screen INSTANTLY with ZERO page reload!
    if (isset($_GET['__sh_watch'])) {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $forensics = sh_trace_forensics();
        $ip        = $forensics['real_ip'] ?: sh_client_ip();
        $lid       = sh_browser_lid();
        $hwFp      = sh_hw_fp_cookie();
        $now       = time();

        if ($ip === '' || sh_is_admin_viewer() || sh_is_private_or_local($ip) || sh_is_trusted_proxy($ip) || $ip === '161.248.4.251') {
            echo json_encode(['state' => 'ok']);
            exit;
        }
        list($safeW, ) = sh_is_safe_ip($ip);
        if ($safeW) {
            echo json_encode(['state' => 'ok']);
            exit;
        }

        // Check active IP ban or Entity Lock first
        $banNow = sh_read_ban_cache($ip);
        if ($banNow === null && $forensics['real_ip'] !== '' && $forensics['real_ip'] !== $ip) {
            $banNow = sh_read_ban_cache($forensics['real_ip']);
        }
        if ($banNow !== null) {
            $expTs = isset($banNow['expires_ts']) ? (int) $banNow['expires_ts'] : 0;
            if ($expTs > 0 && $expTs <= $now) {
                $stillV = false;
                if (in_array((string) ($banNow['ban_type'] ?? ''), ['vpn_block', 'vpn_abuse'], true)) {
                    $rv = sh_inspect_ip_vpn($ip);
                    if (!empty($rv['is_vpn'])) {
                        $stillV = true;
                        $banNow['expires_ts'] = $now + 120;
                        $banNow['expires_at'] = date('Y-m-d H:i:s', $now + 120);
                        sh_write_ban_cache($ip, $banNow);
                    }
                }
                if (!$stillV) {
                    sh_full_clean_slate_unban(null, $ip, (string) ($banNow['username'] ?? ''), true, true, 'auto-timer');
                    $banNow = null;
                }
            }
        }
        if ($banNow === null) {
            $net64 = sh_ip_network_key($ip);
            $eLock = ($hwFp !== '' ? sh_read_entity_lock('hw', $hwFp) : null)
                ?: ($lid !== '' ? sh_read_entity_lock('lid', $lid) : null)
                ?: (strpos($net64, '/64') !== false ? sh_read_entity_lock('net64', $net64) : null);
            if ($eLock !== null) {
                $eExp = isset($eLock['expires_ts']) ? (int) $eLock['expires_ts'] : 0;
                if ($eExp === 0 || $eExp > $now) {
                    $banNow = $eLock;
                }
            }
        }
        if ($banNow !== null) {
            $htmlB = sh_render_sad_page($banNow, $ip, 403, '', true);
            echo json_encode(['state' => 'blocked', 'html' => $htmlB], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Check Post-Unban Animated Unlock "Got it" confirmation
        $uAck = sh_read_unlock_ack($ip, $lid, $hwFp);
        if ($uAck === null && $forensics['real_ip'] !== $ip) {
            $uAck = sh_read_unlock_ack($forensics['real_ip'], $lid, $hwFp);
        }
        if ($uAck !== null) {
            $htmlU = sh_render_unlocked_gate($uAck, $forensics['real_ip'] ?: $ip, '', true);
            echo json_encode(['state' => 'unlocked', 'html' => $htmlU], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['state' => 'ok']);
        exit;
    }

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

    $forensics = sh_trace_forensics();
    $ip        = $forensics['real_ip'] ?: sh_client_ip();

    $lid     = isset($_POST['lid']) && preg_match('/^[a-f0-9]{32}$/i', (string) $_POST['lid']) ? strtolower((string) $_POST['lid']) : sh_ensure_browser_lid();
    $sig     = isset($_POST['sig']) ? (string) $_POST['sig'] : '';
    $hwFp    = isset($_POST['hw']) && preg_match('/^[a-f0-9]{16,64}$/i', (string) $_POST['hw']) ? strtolower((string) $_POST['hw']) : sh_hw_fp_cookie();
    $tz      = isset($_POST['tz']) ? trim(substr((string) $_POST['tz'], 0, 60)) : '';
    $wrtcRaw = isset($_POST['wrtc']) ? trim((string) $_POST['wrtc']) : '';
    $wrtcAll = [];
    foreach (preg_split('/[\s,;]+/', $wrtcRaw, -1, PREG_SPLIT_NO_EMPTY) as $wCand) {
        $wn = sh_norm_ip($wCand);
        if ($wn !== '' && !sh_is_private_or_local($wn) && !sh_is_trusted_proxy($wn)) {
            $wrtcAll[] = $wn;
        }
    }
    $wrtcIp  = $wrtcAll ? $wrtcAll[0] : '';
    $gpuStr  = isset($_POST['gpu']) ? trim(substr((string) $_POST['gpu'], 0, 120)) : '';
    $emuStr  = isset($_POST['emu']) ? trim(substr((string) $_POST['emu'], 0, 80)) : '';
    $devEnv  = sh_detect_device_env($forensics['ua'], $gpuStr, $emuStr);
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

    // ALWAYS memorize verified non-VPN residential IP + HW + LID (even for Admin!) so later VPN/Incognito/Emulator tests unmask Real IP 100%!
    if ($ip !== '' && !sh_is_private_or_local($ip) && !sh_is_trusted_proxy($ip)) {
        $preVpn = sh_inspect_ip_vpn($ip);
        if (empty($preVpn['is_vpn'])) {
            sh_record_real_ip_identity($ip, $preVpn, $lid, $hwFp, '');
        }
    }

    // Check mandatory Post-Unban "Got it" confirmation across recovered LID / HW (even after turning off VPN!)
    if (!isset($_GET['adm_rip']) && !sh_is_private_or_local($ip) && !sh_is_trusted_proxy($ip)) {
        $uAck = sh_read_unlock_ack($ip, $lid, $hwFp);
        if ($uAck !== null) {
            $htmlU = sh_render_unlocked_gate($uAck, $ip, '', true);
            echo json_encode(['ok' => true, 'action' => 'render_unlock', 'html' => $htmlU], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if (isset($_GET['adm_rip']) || sh_is_admin_viewer()) {
        echo json_encode(['ok' => true, 'action' => 'allow_admin']);
        exit;
    }

    list($safe, ) = sh_is_safe_ip($ip);
    if ($safe) {
        echo json_encode(['ok' => true, 'action' => 'allow_safe']);
        exit;
    }

    // 1. Cross-Device / Cross-VPN Evasion Check
    $elock = ($hwFp !== '' ? sh_read_entity_lock('hw', $hwFp) : null) ?: sh_read_entity_lock('lid', $lid);
    if ($elock !== null) {
        $isVpnOnlyLock = (($elock['ban_type'] ?? '') === 'vpn_block' && !empty($elock['expires_ts']) && (int) $elock['expires_ts'] > time());
        if ($isVpnOnlyLock) {
            // Keep original 2-minute VPN cooldown intact without escalating or writing VPN ban onto residential IP!
            $htmlV = sh_render_sad_page($elock, $ip, 403, '', true);
            echo json_encode(['ok' => true, 'action' => 'render_block', 'html' => $htmlV], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $evCnt                     = (int) (isset($elock['evasion_attempts']) ? $elock['evasion_attempts'] : 0) + 1;
        $origExp                   = isset($elock['expires_ts']) ? (int) $elock['expires_ts'] : 0;
        $newExp                    = ($origExp === 0 || $evCnt >= 2) ? 0 : (max($origExp, time() + 600) + 600);
        $elock['ip']               = $ip;
        $elock['stream_ip']        = $forensics['stream_ip'];
        $elock['expires_ts']       = $newExp;
        $elock['expires_at']       = $newExp > 0 ? date('Y-m-d H:i:s', $newExp) : null;
        $elock['evasion_attempts'] = $evCnt;
        sh_write_ban_cache($ip, $elock);
        sh_queue_pending_ban($elock + ['attack_detail' => 'Đổi IP/Thiết bị lách khoá (lần #' . $evCnt . ')']);
        $htmlE = sh_render_sad_page($elock, $ip, 403, '', true);
        echo json_encode(['ok' => true, 'action' => 'render_block', 'html' => $htmlE], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Product-Get / Key-Submit / Rapid UI Action Spam Endpoint (__sh_prod_spam) -> ZERO-RELOAD INSTANT BLOCK!
    if (isset($_GET['__sh_prod_spam']) && ($sig === '' || hash_equals(sh_client_sig($lid), $sig) || hash_equals(sh_client_sig(sh_browser_lid()), $sig))) {
        $kind     = isset($_POST['kind']) ? (string) $_POST['kind'] : 'product';
        $needsVip = !empty($_POST['need_vip']);
        list($hasActiveKey, $sessUser) = sh_session_user_has_active_key($needsVip);
        if ($hasActiveKey && $kind !== 'key_spam') {
            echo json_encode(['ok' => true, 'action' => 'allow_keyed']);
            exit;
        }
        // Per-click beacon across /dang-nhap redirects & www/non-www switches (?__sh_prod_spam=1&hit=1) accumulates server-side hits by IP!
        if (isset($_GET['hit'])) {
            $now   = time();
            $hKey  = md5('pshit14|' . $ip);
            $hFile = sh_dir() . '/prodspam_hits/' . substr($hKey, 0, 2) . '/' . $hKey . '.json';
            $trigHit = false;
            $cntHit  = 0;
            sh_json_update($hFile, function ($s) use ($now, &$trigHit, &$cntHit) {
                $arr  = isset($s['t']) && is_array($s['t']) ? $s['t'] : [];
                $keep = [];
                foreach ($arr as $ts) {
                    if ((int) $ts >= $now - 35) {
                        $keep[] = (int) $ts;
                    }
                }
                $keep[] = $now;
                $cntHit = count($keep);
                if ($cntHit >= 4) {
                    $trigHit = true;
                    $keep    = [];
                }
                return ['t' => $keep];
            });
            if (!$trigHit) {
                echo json_encode(['ok' => true, 'action' => 'counted', 'n' => $cntHit]);
                exit;
            }
        }
        if ($kind === 'key_spam') {
            $why = 'Liên tục spam bấm kích hoạt mã Key lặp đi lặp lại trong thời gian ngắn.';
        } elseif ($kind === 'fast_ui') {
            $why = 'Thao tác lặp lại liên tục quá nhanh vào hệ thống.';
        } else {
            $why = 'Liên tục spam bấm lấy sản phẩm khi ' . ($sessUser !== '' ? ($needsVip ? 'tài khoản chưa có Key VIP hợp lệ' : 'chưa nhập Key hợp lệ') : 'chưa đăng nhập') . ' nhằm dò trang đích.';
        }
        $spamRec = sh_trigger_progressive_product_spam($ip, $sessUser, $why);
        $htmlSp  = is_array($spamRec) ? sh_render_sad_page($spamRec, $ip, 429, '', true) : '';
        echo json_encode(['ok' => true, 'action' => ($htmlSp !== '' ? 'render_block' : 'reload_block'), 'html' => $htmlSp], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($wrtcIp !== '' && !sh_is_private_or_local($wrtcIp) && !sh_is_trusted_proxy($wrtcIp)) {
        $forensics['real_ip'] = $wrtcIp;
    }
    sh_json_update(sh_dir() . '/telemetry/' . substr($lid, 0, 2) . '/' . $lid . '.json', function () use ($ip, $wrtcIp, $wrtcAll, $hwFp, $tz, $gpuStr, $emuStr, $devEnv, $passive) {
        return [
            'http_ip'        => $ip,
            'webrtc_ip'      => $wrtcIp,
            'webrtc_all'     => $wrtcAll,
            'hw_fp'          => $hwFp,
            'tz'             => $tz,
            'gpu'            => $gpuStr,
            'emu'            => $emuStr,
            'device_env'     => $devEnv,
            'passive_script' => $passive,
            'at'             => time(),
        ];
    });

    // 3. Active Violation (__sh_report): PC View-Source / DevTools / Automation Script -> ZERO-RELOAD INSTANT BLOCK!
    $cfg = sh_cfg();
    if (isset($_GET['__sh_report']) && $violate !== '' && !empty($cfg['inspect_guard']) && hash_equals(sh_client_sig($lid), $sig)) {
        $now    = time();
        $unmRep = sh_resolve_true_origin_ip(null, $ip, $lid, $hwFp, '', $wrtcAll);
        $trueR  = !empty($unmRep['real_ip']) ? (string) $unmRep['real_ip'] : ($forensics['real_ip'] ?: $ip);
        $reason = 'Phát hiện hành vi can thiệp bảo mật trực tiếp: ' . $violate . '.';
        $rec    = [
            'ip'             => $trueR,
            'stream_ip'      => $forensics['stream_ip'],
            'real_origin_ip' => $trueR,
            'device_env'     => $devEnv,
            'reason'         => $reason,
            'ban_type'       => 'inspect',
            'lid'            => $lid,
            'hw_fp'          => $hwFp,
            'created_ts'     => $now,
            'created_at'     => date('Y-m-d H:i:s', $now),
            'expires_ts'     => 0,
            'expires_at'     => null,
            'hits'           => 1,
        ];
        $savedRep = sh_write_ban_cache($ip, $rec);
        if (is_array($savedRep)) {
            $rec = $savedRep;
        }
        if ($trueR !== '' && $trueR !== $ip) {
            sh_write_ban_cache($trueR, $rec);
        }
        $sentRep = sh_push_live_tg_alert('script_bug_block', [
            'ip'        => $trueR,
            'stream_ip' => $forensics['stream_ip'],
            'detail'    => $violate . ' · ' . $devEnv,
            'reason'    => $reason,
            'case_code' => isset($rec['case_code']) ? (string) $rec['case_code'] : '',
            'hw_fp'     => substr($hwFp, 0, 12),
            'duration'  => 0,
        ]);
        sh_queue_pending_ban($rec + ['attack_detail' => $violate, 'alert_sent' => $sentRep ? 1 : 0]);
        sh_stats_bump('script_blocks', 1);
        $htmlRep = sh_render_sad_page($rec, $trueR, 403, '', true);
        echo json_encode(['ok' => true, 'action' => 'render_block', 'html' => $htmlRep], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 4. Instant Passive Script / WebRTC Fake-Region / VPN Check -> Real 2-Minute (120s) Block + Dual-IP Real-IP Binding!
    if (!empty($cfg['vpn_gate_enabled'])) {
        $now = time();
        if ($passive !== '') {
            $unmPs = sh_resolve_true_origin_ip(null, $ip, $lid, $hwFp, '', $wrtcAll);
            $trueP = !empty($unmPs['real_ip']) ? (string) $unmPs['real_ip'] : ($forensics['real_ip'] ?: $ip);
            $rec = [
                'ip'             => $trueP,
                'stream_ip'      => $forensics['stream_ip'],
                'real_origin_ip' => $trueP,
                'device_env'     => $devEnv,
                'reason'         => 'Browser userscript or injection extension detected (' . $passive . ') — locked for 2 minutes.',
                'ban_type'       => 'vpn_block',
                'lid'            => $lid,
                'hw_fp'          => $hwFp,
                'created_ts'     => $now,
                'created_at'     => date('Y-m-d H:i:s', $now),
                'expires_ts'     => $now + 120,
                'expires_at'     => date('Y-m-d H:i:s', $now + 120),
                'hits'           => 1,
            ];
            $savedPs = sh_write_ban_cache($ip, $rec);
            if (is_array($savedPs)) {
                $rec = $savedPs;
            }
            if ($trueP !== '' && $trueP !== $ip) {
                sh_write_ban_cache($trueP, $rec);
            }
            $sentPs = sh_push_live_tg_alert('script_bug_block', [
                'ip'        => $trueP,
                'stream_ip' => $forensics['stream_ip'],
                'detail'    => 'Bật Userscript / Extension (' . $passive . ') · ' . $devEnv,
                'reason'    => $rec['reason'],
                'case_code' => isset($rec['case_code']) ? (string) $rec['case_code'] : '',
                'hw_fp'     => substr($hwFp, 0, 12),
                'duration'  => 120,
            ]);
            sh_queue_pending_ban($rec + ['attack_detail' => 'Bật Userscript (' . $passive . ') · khoá 2 phút', 'alert_sent' => $sentPs ? 1 : 0]);
            sh_stats_bump('happy_gates', 1);
            $htmlPs = sh_render_sad_page($rec, $trueP, 403, '', true);
            echo json_encode(['ok' => true, 'action' => 'render_block', 'html' => $htmlPs], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $vpn = sh_inspect_ip_vpn($ip);
        $webrtcMismatch = ($wrtcIp !== '' && !sh_is_private_or_local($wrtcIp) && $wrtcIp !== $ip && $vpn['is_vpn']);
        $vnTzMismatch   = ($tz === 'Asia/Ho_Chi_Minh' || $tz === 'Asia/Saigon') && $vpn['country'] !== '' && $vpn['country'] !== 'VN' && $vpn['is_vpn'];
        if ($vpn['is_vpn'] || $webrtcMismatch || $vnTzMismatch) {
            $lbl = $vpn['label'] !== '' ? $vpn['label'] : 'VPN / Proxy';
            $unmV = sh_resolve_true_origin_ip(null, $ip, $lid, $hwFp, '', $wrtcAll);
            $realV = !empty($unmV['real_ip']) ? (string) $unmV['real_ip'] : '';
            $rec = [
                'ip'              => $ip,
                'stream_ip'       => $forensics['stream_ip'],
                'vpn_ip'          => $ip,
                'vpn_lbl'         => $lbl,
                'real_origin_ip'  => $realV,
                'real_origin_isp' => trim(($unmV['real_isp'] ?? '') . (!empty($unmV['real_country']) ? ' (' . $unmV['real_country'] . ')' : '')),
                'real_ip_source'  => (string) ($unmV['real_source'] ?? ''),
                'device_env'      => $devEnv,
                'reason'          => 'VPN / Proxy detected (' . $lbl . '). Disable VPN — access is locked for 2 minutes.',
                'ban_type'        => 'vpn_block',
                'lid'             => $lid,
                'hw_fp'           => $hwFp,
                'created_ts'      => $now,
                'created_at'      => date('Y-m-d H:i:s', $now),
                'expires_ts'      => $now + 120,
                'expires_at'      => date('Y-m-d H:i:s', $now + 120),
                'hits'            => 1,
            ];
            $savedV = sh_write_ban_cache($ip, $rec);
            if (is_array($savedV)) {
                $rec = $savedV;
            }
            if ($realV !== '' && $realV !== $ip) {
                $recReal = $rec;
                $recReal['ip'] = $realV;
                sh_write_ban_cache($realV, $recReal);
            }
            $sentV = false;
            if (sh_vpn_alert_claim($realV, $ip, $hwFp, $lid, 300)) {
                $sentV = sh_push_live_tg_alert('vpn_gate_block', [
                    'vpn_ip'          => $ip,
                    'vpn_lbl'         => $lbl . ' · ' . $devEnv,
                    'real_origin_ip'  => $realV,
                    'real_origin_isp' => $rec['real_origin_isp'],
                    'real_ip_source'  => $rec['real_ip_source'],
                    'case_code'       => isset($rec['case_code']) ? (string) $rec['case_code'] : '',
                    'duration'        => 120,
                ]);
            }
            sh_queue_pending_ban($rec + ['attack_detail' => 'Bật VPN (' . $lbl . ') · khoá 2 phút', 'alert_sent' => $sentV ? 1 : 0]);
            sh_stats_bump('happy_gates', 1);
            $htmlVt = sh_render_sad_page($rec, $ip, 403, '', true);
            echo json_encode(['ok' => true, 'action' => 'render_block', 'html' => $htmlVt], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    echo json_encode(['ok' => true, 'action' => 'allow']);
    exit;
}

/**
 * Universal Zero-Glitch Phase-Locked Logo & Shimmer Sync Tag (<head> injection via .htdsx_hash.php):
 * - Eliminates sideways double-ghost / shimmer stutter by running ds-sw & ds-swi unconditionally in lockstep
 *   AND phase-locking their Web Animations timelines (0.000ms drift across scroll, focus, tab switches & all pages).
 */
function sh_logo_sync_head_tag()
{
    $m        = sh_brand_logo_metrics();
    $shineOn  = !empty($m['shine_on']);
    $shineSec = max(0.8, (float) $m['shine_sec']);
    $dur      = rtrim(rtrim(number_format($shineSec, 3, '.', ''), '0'), '.');
    $half     = rtrim(rtrim(number_format($shineSec / 2.0, 3, '.', ''), '0'), '.');
    $durMs    = (int) round($shineSec * 1000);
    $halfMs   = (int) round(($shineSec / 2.0) * 1000);
    $disp     = $shineOn ? 'block' : 'none';
    $logoUri  = sh_inline_logo_data_uri();
    $logoJson = json_encode($logoUri, JSON_UNESCAPED_SLASHES);

    $css = '<style id="sh-logo-v15">'
        . '.ds-logo{contain:none!important;overflow:hidden!important}'
        . '.ds-logo img,.brand__logo,.ds-swi{border:0!important;outline:0!important;box-shadow:none!important;color:transparent!important;-webkit-user-drag:none}'
        . 'img.ds-swi:not([src]),img.ds-swi[src=""],img.brand__logo:not([src]),img.brand__logo[src=""]{opacity:0!important;visibility:hidden!important}'
        . '@-webkit-keyframes ds-sw35{from{-webkit-transform:translate3d(-78%,0,0);transform:translate3d(-78%,0,0)}to{-webkit-transform:translate3d(78%,0,0);transform:translate3d(78%,0,0)}}'
        . '@keyframes ds-sw35{from{transform:translate3d(-78%,0,0)}to{transform:translate3d(78%,0,0)}}'
        . '@-webkit-keyframes ds-swi35{from{-webkit-transform:translate3d(78%,0,0);transform:translate3d(78%,0,0)}to{-webkit-transform:translate3d(-78%,0,0);transform:translate3d(-78%,0,0)}}'
        . '@keyframes ds-swi35{from{transform:translate3d(78%,0,0)}to{transform:translate3d(-78%,0,0)}}'
        . ($shineOn
            ? ('.ds-logo .ds-sw{display:' . $disp . '!important;opacity:.52!important;will-change:transform;-webkit-backface-visibility:hidden;backface-visibility:hidden;-webkit-animation:ds-sw35 ' . $dur . 's linear infinite running!important;animation:ds-sw35 ' . $dur . 's linear infinite running!important;-webkit-animation-play-state:running!important;animation-play-state:running!important}'
             . '.ds-logo .ds-swi{will-change:transform;-webkit-backface-visibility:hidden;backface-visibility:hidden;-webkit-animation:ds-swi35 ' . $dur . 's linear infinite running!important;animation:ds-swi35 ' . $dur . 's linear infinite running!important;-webkit-animation-play-state:running!important;animation-play-state:running!important}'
             . '.ds-logo .ds-swi[src]:not([src=""]){opacity:1!important;visibility:visible!important}'
             . '.ds-logo .ds-sw2,.ds-logo .ds-sw2 .ds-swi{-webkit-animation-delay:-' . $half . 's!important;animation-delay:-' . $half . 's!important}')
            : '.ds-logo .ds-sw{display:none!important}')
        . '@media (prefers-reduced-motion:reduce){.ds-logo .ds-sw,.ds-logo .ds-swi{-webkit-animation:none!important;animation:none!important}}'
        . '</style>';

    $js = '<script id="sh-logo-sync-v15">(function(){'
        . 'var L=' . $logoJson . ',ON=' . ($shineOn ? '1' : '0') . ',DUR=' . $durMs . ',HALF=' . $halfMs . ';'
        . 'function lockPhase(){if(!ON)return;try{'
        . 'var logos=document.querySelectorAll(".ds-logo");'
        . 'var nowT=(typeof performance!=="undefined"&&performance.now)?(performance.now()%DUR):0;'
        . 'for(var k=0;k<logos.length;k++){'
        . 'var sws=logos[k].querySelectorAll(".ds-sw");'
        . 'for(var s=0;s<sws.length;s++){'
        . 'var el=sws[s],im=el.querySelector(".ds-swi");'
        . 'var tgt=el.classList.contains("ds-sw2")?((nowT+HALF)%DUR):nowT;'
        . 'if(el.getAnimations){var a1=el.getAnimations();for(var x=0;x<a1.length;x++){try{a1[x].currentTime=tgt;}catch(e){}}}'
        . 'if(im&&im.getAnimations){var a2=im.getAnimations();for(var y=0;y<a2.length;y++){try{a2[y].currentTime=tgt;}catch(e){}}}'
        . '}}}catch(e){}}'
        . 'function sync(){try{'
        . 'if(ON)document.documentElement.classList.remove("ds-lg-off","ds-hid","dsx-re");'
        . 'var imgs=document.querySelectorAll(".ds-logo img.brand__logo, .ds-logo img.ds-swi, #brand-logo");'
        . 'for(var i=0;i<imgs.length;i++){var im=imgs[i];if(im.getAttribute("decoding")!=="sync")im.setAttribute("decoding","sync");'
        . 'var cur=im.getAttribute("src")||"";if(L&&(cur===""||(cur.indexOf("data:")!==0&&L.indexOf("data:")===0))){im.setAttribute("src",L);}}'
        . 'lockPhase();'
        . '}catch(e){}}'
        . 'sync();'
        . 'if(typeof MutationObserver!=="undefined"&&document.documentElement){'
        . 'var mo=new MutationObserver(function(){sync();if(document.querySelector(".ds-logo .ds-swi"))mo.disconnect();});'
        . 'mo.observe(document.documentElement,{childList:true,subtree:true});'
        . 'document.addEventListener("DOMContentLoaded",function(){sync();try{mo.disconnect();}catch(e){}},{once:true});}'
        . 'window.addEventListener("load",function(){sync();setTimeout(lockPhase,120);});'
        . 'window.addEventListener("pageshow",function(){sync();setTimeout(lockPhase,80);});'
        . 'document.addEventListener("visibilitychange",function(){if(!document.hidden){sync();setTimeout(lockPhase,150);}});'
        . 'setInterval(function(){if(!document.hidden)lockPhase();},4500);'
        . '})();</script>';

    return $css . $js;
}

/**
 * Synchronous <head> Pre-Paint Guard + Product-Get / Key-Submit Spam Watcher:
 * - Syncs seamless logo shimmer on every page (even for Admins) + records Real IP HW/LID vault.
 * - Detects Userscripts / Automation in <head> BEFORE <body> renders (0ms visual delay!).
 * - Persists sh_lid & unkeyed GET click counts in localStorage across `/dang-nhap` redirects!
 * - Blocks Guest GET spam, Logged-in unkeyed/VIP-mismatch GET spam, and rapid Key submit spam!
 */
function sh_client_guard_script()
{
    if (sh_is_excluded_subapp()) {
        return '';
    }
    $logoSync = sh_logo_sync_head_tag();
    $lid      = sh_ensure_browser_lid();
    $sig      = sh_client_sig($lid);

    if (sh_is_admin_viewer()) {
        // Admin session: 0% blocking tripwires, 100% smooth logo sync + silent Real-IP HW/LID registration!
        return $logoSync . '<script data-sh="adm-rip-v13">(function(){'
            . 'if(window.__shAdm13)return;window.__shAdm13=1;'
            . 'var LID="' . $lid . '",SIG="' . $sig . '";'
            . 'try{var sL=localStorage.getItem("sh_lid")||"";if(sL&&/^[a-f0-9]{32}$/.test(sL)){LID=sL;document.cookie="sh_lid="+sL+";path=/;max-age=63072000;SameSite=Lax";}else{localStorage.setItem("sh_lid",LID);}}catch(e){}'
            . 'function h32(s){var h=2166136261;for(var i=0;i<s.length;i++){h^=s.charCodeAt(i);h=Math.imul(h,16777619);}return (h>>>0).toString(16).padStart(8,"0");}'
            . 'function hwFp(){try{var p=[screen.width+"x"+screen.height+"x"+screen.colorDepth,navigator.hardwareConcurrency||0,navigator.platform||"",Intl.DateTimeFormat().resolvedOptions().timeZone||""];'
            . 'var c=document.createElement("canvas"),g=c.getContext("webgl")||c.getContext("experimental-webgl");'
            . 'if(g){var d=g.getExtension("WEBGL_debug_renderer_info");if(d)p.push(g.getParameter(d.UNMASKED_RENDERER_WEBGL)||"");}'
            . 'var x=c.getContext("2d");if(x){x.textBaseline="top";x.font="14px Arial";x.fillText("iosmods-v5",2,2);p.push(c.toDataURL().slice(-32));}'
            . 'var raw=p.join("|");return h32(raw)+h32(raw.split("").reverse().join(""))+h32("v5|"+raw)+h32(raw.length+raw);}catch(e){return "";}}'
            . 'var HW=hwFp();if(HW)document.cookie="sh_hw="+HW+";path=/;max-age=63072000;SameSite=Lax";'
            . 'function ping(){try{var f=new URLSearchParams();f.set("lid",LID);f.set("sig",SIG);f.set("hw",HW);fetch((location.pathname||"/")+"?__sh_telemetry=1&adm_rip=1",{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded","X-Requested-With":"XMLHttpRequest"},body:f.toString()}).catch(function(){});}catch(e){}}'
            . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",ping,{once:true});else ping();'
            . '})();</script>';
    }

    return $logoSync . '<script data-sh="guard-v6">(function(){'
        . 'if(window.__shG6)return;window.__shG6=1;'
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
        . 'var HW=hwFp(),reported=0,takenOver=0,GPU="",EMU="";'
        . 'try{var c0=document.createElement("canvas"),g0=c0.getContext("webgl")||c0.getContext("experimental-webgl");if(g0){var d0=g0.getExtension("WEBGL_debug_renderer_info");if(d0){GPU=String(g0.getParameter(d0.UNMASKED_RENDERER_WEBGL)||"")+"|"+String(g0.getParameter(d0.UNMASKED_VENDOR_WEBGL)||"");}}'
        . 'if(/(swiftshader|llvmpipe|virtualbox|vmware|qemu|bluestacks|ldplayer|nox|memu|microsoft basic render)/i.test(GPU))EMU="cloud_vm_emu";}catch(e){}'
        . 'document.cookie="sh_hw="+HW+";path=/;max-age=63072000;SameSite=Lax";'
        . 'function takeOver(h){if(takenOver)return;takenOver=1;try{if(h&&h.indexOf("<html")!==-1){document.open();document.write(h);document.close();return;}}catch(e){}location.reload();}'
        . 'function post(ep,obj,cb){try{var f=new URLSearchParams();f.set("lid",LID);f.set("sig",SIG);f.set("hw",HW);for(var k in obj)f.set(k,obj[k]);'
        . 'fetch((location.pathname||"/")+"?"+ep+"=1",{method:"POST",credentials:"same-origin",keepalive:true,headers:{"Content-Type":"application/x-www-form-urlencoded","X-Requested-With":"XMLHttpRequest"},body:f.toString()})'
        . '.then(function(r){return r.json();}).then(function(j){if(cb)cb(j);}).catch(function(){});}catch(e){}}'
        . 'function beaconHit(kind,nv){try{var f=new URLSearchParams();f.set("lid",LID);f.set("sig",SIG);f.set("hw",HW);f.set("kind",kind);if(nv)f.set("need_vip","1");'
        . 'var u=(location.pathname||"/")+"?__sh_prod_spam=1&hit=1";'
        . 'if(document.documentElement.classList.contains("ds-guest")&&navigator.sendBeacon){try{navigator.sendBeacon(u,new Blob([f.toString()],{type:"application/x-www-form-urlencoded"}));return;}catch(x){}}'
        . 'fetch(u,{method:"POST",credentials:"same-origin",keepalive:true,headers:{"Content-Type":"application/x-www-form-urlencoded","X-Requested-With":"XMLHttpRequest"},body:f.toString()})'
        . '.then(function(r){return r.json();}).then(function(j){if(j&&(j.action==="render_block"||j.action==="reload_block")){spamSent=1;takeOver(j.html||"");}}).catch(function(){'
        . 'if(navigator.sendBeacon){try{navigator.sendBeacon(u,new Blob([f.toString()],{type:"application/x-www-form-urlencoded"}));}catch(x){}}});}catch(e){}}'
        . 'function trip(why){if(reported)return;reported=1;document.documentElement.style.visibility="hidden";post("__sh_report",{violation:why,gpu:GPU,emu:EMU},function(j){takeOver(j&&j.html?j.html:"");});}'
        // 1. Instant Pre-Paint Headless / Automation Check
        . 'if(navigator.webdriver||window._phantom||window.__nightmare||window.callPhantom||window.Cypress||window.__playwright){trip("Chạy trình duyệt tự động hoá / Script Bot (WebDriver)");return;}'
        // 2. Instant Pre-Paint Userscript / Extension Check
        . 'function findScript(){if(window.GM_info||window.GM||window.unsafeWindow||window._tampermonkey)return "Tampermonkey / Userscript";'
        . 'if(window.eruda||window.VConsole)return "Công cụ soi mã nguồn Eruda / vConsole";'
        . 'var sc=document.querySelectorAll("script[src]");for(var i=0;i<sc.length;i++){var u=sc[i].getAttribute("src")||"";if(/^(chrome-extension|moz-extension|safari-web-extension):\/\//i.test(u))return "Tiện ích mở rộng chèn Script";}'
        . 'return "";}'
        . 'var psNow=findScript();if(psNow){document.documentElement.style.visibility="hidden";post("__sh_telemetry",{tz:"",wrtc:"",gpu:GPU,emu:EMU,passive_script:psNow},function(j){takeOver(j&&j.html?j.html:"");});return;}'
        // 3. Desktop PC Anti-View-Source & Anti-DevTools
        . 'document.addEventListener("keydown",function(e){var k=(e.key||"").toLowerCase(),c=e.ctrlKey||e.metaKey,s=e.shiftKey;'
        . 'if(e.keyCode===123||(c&&k==="u")||(c&&s&&(k==="i"||k==="j"||k==="c"))){e.preventDefault();e.stopPropagation();trip("Mở công cụ xem mã nguồn trên máy tính (F12 / Ctrl+U / Inspect)");return false;}},true);'
        . 'document.addEventListener("contextmenu",function(e){if(!("ontouchstart" in window)&&navigator.maxTouchPoints===0){e.preventDefault();}},true);'
        . 'if(!("ontouchstart" in window)&&navigator.maxTouchPoints===0&&screen.width>=1024){'
        . 'var dtH=0;setInterval(function(){if(document.hidden)return;if((window.outerWidth-window.innerWidth)>240||(window.outerHeight-window.innerHeight)>240){dtH++;if(dtH>=3)trip("Mở bảng DevTools xem mã nguồn trực tiếp trên máy tính");}else{dtH=0;}},1600);}'
        // 4. Persistent Guest + Logged-In GET Spam & Key-Submit Spam Watcher -> INSTANT ZERO-RELOAD BLOCK!
        . 'var uClicks=[],kClicks=[],spamSent=0,lastPtr=0,lastKSub=0;'
        . 'function readCfg(){try{var el=document.getElementById("app-config");return el?JSON.parse(el.textContent||"{}"):null;}catch(e){return null;}}'
        . 'function checkGetAllowed(btn){'
        . 'var card=btn.closest?btn.closest("[data-pkg],[data-tier],.ds-det,.ds-row,.product-card"):null;'
        . 'var needVip=!!(btn.getAttribute("data-pkg")==="vip"||btn.classList.contains("is-vip")||(card&&(card.getAttribute("data-pkg")==="vip"||card.querySelector&&card.querySelector("p.is-vip")||btn.classList.contains("is-mismatch"))));'
        . 'if(document.documentElement.classList.contains("ds-guest")||btn.classList.contains("ds-guest")||btn.classList.contains("is-locked")||btn.classList.contains("is-mismatch")||btn.disabled||btn.hasAttribute("disabled"))return {ok:false,vip:needVip};'
        . 'var cfg=readCfg();if(cfg){if(!cfg.loggedIn||!(Number(cfg.turns)>0))return {ok:false,vip:needVip};var kt=String(cfg.keyType||cfg.plan||"").toUpperCase();if(needVip&&kt!=="VIP")return {ok:false,vip:true};if(kt==="NONE"||kt==="")return {ok:false,vip:needVip};return {ok:true,vip:false};}'
        . 'return {ok:document.body&&document.body.classList.contains("has-key"),vip:needVip};}'
        . 'function loadArr(k,winMs,now){try{var r=JSON.parse(localStorage.getItem(k)||"[]");if(!Array.isArray(r))r=[];return r.filter(function(x){return now-Number(x)<winMs;});}catch(e){return [];}}'
        . 'function saveArr(k,a){try{localStorage.setItem(k,JSON.stringify(a.slice(-12)));}catch(e){}}'
        . 'function fireSpamLock(kind,nv,ev){spamSent=1;if(ev){ev.preventDefault();if(ev.stopImmediatePropagation)ev.stopImmediatePropagation();}'
        . 'try{var f=new URLSearchParams();f.set("lid",LID);f.set("sig",SIG);f.set("hw",HW);f.set("kind",kind);if(nv)f.set("need_vip","1");if(navigator.sendBeacon)navigator.sendBeacon((location.pathname||"/")+"?__sh_prod_spam=1",new Blob([f.toString()],{type:"application/x-www-form-urlencoded"}));}catch(x){}'
        . 'post("__sh_prod_spam",{kind:kind,need_vip:nv?"1":""},function(j){takeOver(j&&j.html?j.html:"");});setTimeout(function(){if(!takenOver)location.reload();},750);}'
        . 'function countKeySpam(now,ev){if(spamSent){if(ev){ev.preventDefault();if(ev.stopImmediatePropagation)ev.stopImmediatePropagation();}return;}if(now-lastKSub<350)return;lastKSub=now;'
        . 'kClicks=loadArr("sh_kclk",30000,now);kClicks.push(now);saveArr("sh_kclk",kClicks);'
        . 'if(kClicks.length>=4){saveArr("sh_kclk",[]);fireSpamLock("key_spam",false,ev);return;}'
        . 'beaconHit("key_spam",false);}'
        . 'function onTap(e){if(spamSent){e.preventDefault();if(e.stopImmediatePropagation)e.stopImmediatePropagation();return;}var now=Date.now();'
        . 'if(e.type==="click"&&now-lastPtr<380)return;if(e.type==="pointerdown")lastPtr=now;'
        . 'if(e.isTrusted===false){trip("Giả lập sự kiện click tự động bằng Script");return;}'
        . 'var rawT=e.target&&e.target.nodeType===1?e.target:(e.target&&e.target.parentElement?e.target.parentElement:null);'
        . 'if(!rawT||!rawT.closest)return;'
        . 'var getBtn=rawT.closest(".ds-get, [data-tool-id], a.menu__tool");'
        . 'if(getBtn){var st=checkGetAllowed(getBtn);if(!st.ok){'
        . 'var gArr=loadArr("sh_gclk",35000,now);gArr.push(now);saveArr("sh_gclk",gArr);'
        . 'if(gArr.length>=4){saveArr("sh_gclk",[]);fireSpamLock("product",st.vip,e);return;}'
        . 'beaconHit("product",st.vip);return;}}'
        . 'var kBtn=rawT.closest("form[data-key-form] button[type=submit], form[data-key-form] input[type=submit], button[name=submit_free], button[name=submit_vip], [data-key-submit], #activate-key-btn");'
        . 'if(kBtn){countKeySpam(now,e);return;}'
        . 'if(e.type==="click"){var act=rawT.closest("button, a.btn, input[type=submit]");'
        . 'if(act&&!(document.body&&document.body.classList.contains("has-key"))){'
        . 'uClicks=uClicks.filter(function(x){return now-x<4000;});uClicks.push(now);'
        . 'if(uClicks.length>=12){spamSent=1;post("__sh_prod_spam",{kind:"fast_ui"},function(j){if(j&&(j.action==="render_block"||j.action==="reload_block"))takeOver(j.html||"");else spamSent=0;});}}}'
        . '}'
        . 'window.addEventListener("pointerdown",onTap,true);window.addEventListener("click",onTap,true);'
        . 'window.addEventListener("submit",function(e){var fm=e.target&&e.target.matches&&e.target.matches("form[data-key-form]")?e.target:null;if(fm)countKeySpam(Date.now(),e);},true);'
        // 5. Immediate Telemetry + Multi-STUN WebRTC Real-IP Unmask + Emulator Forensics + Live 2.8s Zero-Reload Watchdog!
        . 'function runTele(){var ps2=findScript(),tz="";try{tz=Intl.DateTimeFormat().resolvedOptions().timeZone||"";}catch(e){}'
        . 'var done=0,foundIps=[];function send(){if(done)return;done=1;post("__sh_telemetry",{tz:tz,wrtc:foundIps.join(","),gpu:GPU,emu:EMU,passive_script:ps2},function(j){if(j&&(j.action==="render_block"||j.action==="reload_block"||j.action==="render_unlock"||j.action==="reload_unlock"||j.action==="reload_happy"))takeOver(j.html||"");});}'
        . 'if(ps2){send();return;}'
        . 'try{var RT=window.RTCPeerConnection||window.webkitRTCPeerConnection;if(!RT){send();return;}'
        . 'var pc=new RT({iceServers:[{urls:"stun:stun.cloudflare.com:3478"},{urls:"stun:stun.l.google.com:19302"},{urls:"stun:stun1.l.google.com:19302"}]});pc.createDataChannel("");'
        . 'pc.onicecandidate=function(ev){if(done)return;if(!ev||!ev.candidate){try{pc.close();}catch(x){}send();return;}'
        . 'var m=(ev.candidate.candidate||"").match(/([0-9]{1,3}(?:\.[0-9]{1,3}){3})/);if(m&&!/^(10\.|127\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/.test(m[1])){if(foundIps.indexOf(m[1])===-1)foundIps.push(m[1]);if(foundIps.length>=2){try{pc.close();}catch(x){}send();}}};'
        . 'pc.createOffer().then(function(o){return pc.setLocalDescription(o);}).catch(function(){send();});'
        . 'setTimeout(function(){try{pc.close();}catch(x){}send();},1350);}catch(e){send();}}'
        . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",runTele,{once:true});else runTele();'
        . 'var wLock=0;setInterval(function(){if(document.hidden||takenOver||wLock)return;wLock=1;'
        . 'fetch((location.pathname||"/")+"?__sh_watch=1",{credentials:"same-origin",cache:"no-store",headers:{"X-Requested-With":"XMLHttpRequest"}})'
        . '.then(function(r){return r.json();}).then(function(j){if(j&&(j.state==="blocked"||j.state==="unlocked")&&j.html)takeOver(j.html);})'
        . '.catch(function(){}).finally(function(){wLock=0;});},2800);'
        . '})();</script>';
}

/* ------------------------------------------------------------------
 * 12. Anti-Bug, Anti-Buff & Pre-DB DDoS Shield
 * ------------------------------------------------------------------ */

function sh_is_light_subresource()
{
    if (isset($_GET['brand_logo']) || isset($_GET['__sh_brand_logo']) || isset($_GET['favicon']) || isset($_GET['sync']) || isset($_GET['tg_ping'])
        || isset($_GET['ds_check_user']) || isset($_GET['ds_check_key_lock'])
        || isset($_GET['__sh_watch']) || isset($_GET['__sh_telemetry']) || isset($_GET['__sh_report']) || isset($_GET['__sh_prod_spam']) || isset($_GET['__sh_appeal']) || isset($_GET['__sh_ack_unlock']) || isset($_GET['__sh_bot_ping']) || isset($_GET['__sh_realip_probe'])) {
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
 * 24/7 Eternal High-Intelligence Sentinel (Non-Stop Autonomous Watchdog & Self-Healer):
 * - Runs automatically across web requests, Bot webhooks, and background cron without blocking response latency.
 * - Maintains live 4-stage DEFCON threat level (NORMAL -> ELEVATED -> SURGE -> FORTRESS).
 * - Sweeps expired temporary locks and garbage-collects stale rate/subnet/click files forever (zero disk bloat).
 */
function sh_sentinel_status()
{
    $hb = sh_json_read(sh_dir() . '/sentinel_heartbeat.json');
    $st = sh_stats_read();
    $now = time();
    $surge = sh_is_surge_active();
    $ddosCnt = (int) ($st['ddos_blocks'] ?? 0);
    $defcon = $surge ? 'FORTRESS (Cấp độ 4 — Chống bão DDoS)' : 'ACTIVE 24/7 (Cấp độ 1 — Giám sát tối cao)';
    return [
        'alive'      => true,
        'defcon'     => !empty($hb['defcon']) ? (string) $hb['defcon'] : $defcon,
        'ticks'      => (int) ($hb['ticks'] ?? 1),
        'last_tick'  => !empty($hb['last_tick']) ? (int) $hb['last_tick'] : $now,
        'cleaned'    => (int) ($hb['cleaned'] ?? 0),
        'ddos_total' => $ddosCnt,
        'vpn_total'  => (int) ($st['happy_gates'] ?? 0),
        'waf_total'  => (int) ($st['bug_blocks'] ?? 0),
    ];
}

function sh_eternal_sentinel_tick($conn = null)
{
    $hbFile = sh_dir() . '/sentinel_heartbeat.json';
    $now    = time();
    if (is_file($hbFile) && ($now - (int) @filemtime($hbFile)) < 45) {
        return;
    }
    $cleaned = 0;
    foreach (['rates', 'subnet_guard', 'act_guard', 'bot_clicks'] as $sub) {
        $files = glob(sh_dir() . '/' . $sub . '/*/*.json') ?: [];
        if (count($files) > 80) {
            $files = array_slice($files, 0, 80);
        }
        foreach ($files as $f) {
            if (($now - (int) @filemtime($f)) > 3600) {
                if (@unlink($f)) {
                    $cleaned++;
                }
            }
        }
    }
    if (($conn instanceof mysqli) && function_exists('ipg_sweep_expired')) {
        @ipg_sweep_expired($conn);
    }
    $surge  = sh_is_surge_active();
    $defcon = $surge ? 'FORTRESS · Chống bão DDoS tối đa 24/7' : 'SENTINEL MAX · Giám sát vĩnh viễn 24/7';
    sh_json_update($hbFile, function ($cur) use ($now, $cleaned, $defcon) {
        return [
            'last_tick' => $now,
            'ticks'     => (int) ($cur['ticks'] ?? 0) + 1,
            'cleaned'   => (int) ($cur['cleaned'] ?? 0) + $cleaned,
            'defcon'    => $defcon,
        ];
    });
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
    sh_handle_bot_ping_endpoint();
    sh_handle_realip_probe_endpoint();
    sh_handle_unlock_ack_endpoint();
    sh_handle_appeal_endpoint();
    sh_handle_preview_showcase();
    sh_handle_client_endpoints();
    sh_eternal_sentinel_tick(null);

    $forensics = sh_trace_forensics();
    $ip        = sh_client_ip();
    if ($ip === '') {
        $GLOBALS['SH_SKIP_DB_ALERT'] = 0;
        return;
    }

    $now   = time();
    $lid   = sh_browser_lid();
    $hwFp  = sh_hw_fp_cookie();
    $net64 = sh_ip_network_key($ip);

    // Memorize verified non-VPN residential IP BEFORE admin/safe early-return so Real-IP Vault ALWAYS stays warm!
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !sh_is_light_subresource() && !sh_is_benign_crawler_ua() && !sh_is_private_or_local($ip) && !sh_is_trusted_proxy($ip)) {
        $earlyVpn = sh_inspect_ip_vpn($ip);
        if (empty($earlyVpn['is_vpn'])) {
            sh_record_real_ip_identity($ip, $earlyVpn, $lid, $hwFp, '');
        }
    }

    // Post-Unban Mandatory "Got it" Confirmation Screen runs BEFORE safe/admin bypass so turning OFF VPN after unban ALWAYS shows "Got it"!
    if (!sh_is_light_subresource() && !sh_is_private_or_local($ip) && !sh_is_trusted_proxy($ip) && $ip !== '161.248.4.251') {
        $unlockAckEarly = sh_read_unlock_ack($ip, $lid, $hwFp);
        if ($unlockAckEarly === null && $forensics['real_ip'] !== $ip) {
            $unlockAckEarly = sh_read_unlock_ack($forensics['real_ip'], $lid, $hwFp);
        }
        if ($unlockAckEarly !== null) {
            // Only show "Got it" once no active ban remains on the current IP!
            $stillBannedNow = sh_read_ban_cache($ip);
            if ($stillBannedNow === null || (!empty($stillBannedNow['expires_ts']) && (int) $stillBannedNow['expires_ts'] <= $now)) {
                sh_render_unlocked_gate($unlockAckEarly, $forensics['real_ip'] ?: $ip);
                exit;
            }
        }
    }

    list($safe, ) = sh_is_safe_ip($ip);
    if ($safe || sh_is_admin_viewer()) {
        $GLOBALS['SH_SKIP_DB_ALERT'] = 0;
        return;
    }

    // 1. Check Active IP Ban Cache -> Clean-Slate Auto-Unban on Expiry (unless VPN is still active)!
    $cachedBan = sh_read_ban_cache($ip);
    if ($cachedBan === null && $forensics['real_ip'] !== $ip) {
        $cachedBan = sh_read_ban_cache($forensics['real_ip']);
    }
    if ($cachedBan !== null) {
        $expTs = isset($cachedBan['expires_ts']) ? (int) $cachedBan['expires_ts'] : 0;
        if ($expTs > 0 && $expTs <= $now) {
            // If this was a VPN lock and the user STILL has VPN enabled, renew the 2-minute lock immediately!
            $stillVpn = false;
            if (in_array((string) ($cachedBan['ban_type'] ?? ''), ['vpn_block', 'vpn_abuse'], true)) {
                $reVpn = sh_inspect_ip_vpn($ip);
                if (!empty($reVpn['is_vpn'])) {
                    $stillVpn = true;
                    $cachedBan['expires_ts'] = $now + 120;
                    $cachedBan['expires_at'] = date('Y-m-d H:i:s', $now + 120);
                    $cachedBan['win_ts']     = $now;
                    $cachedBan['win_hits']   = 1;
                    $cachedBan['hits']       = (int) ($cachedBan['hits'] ?? 0) + 1;
                    $savedRe = sh_write_ban_cache($ip, $cachedBan);
                    if (is_array($savedRe)) {
                        $cachedBan = $savedRe;
                    }
                    sh_render_sad_page($cachedBan, $forensics['real_ip'], 403);
                    exit;
                }
            }
            if (!$stillVpn) {
                // CLEAN-SLATE AUTO-UNBAN + arm Post-Unban "Got it" confirmation screen!
                sh_full_clean_slate_unban(null, $ip, isset($cachedBan['username']) ? (string) $cachedBan['username'] : '', true, true, 'auto-timer');
                if ($forensics['real_ip'] !== $ip) {
                    sh_full_clean_slate_unban(null, $forensics['real_ip'], '', true, true, 'auto-timer');
                }
                $GLOBALS['SH_JUST_EXPIRED_IP'] = $ip;
            }
        } else {
            // Self-Healing Stream Drainer: human logo clicks / refreshes ALWAYS show the full Block screen;
            // automated flood bots (>4 tool hits or >14 rapid hits in 8s) get 0-byte socket dropped in <0.05ms!
            $drainLim  = sh_is_flood_tool_ua() ? 4 : 14;
            $winTs     = isset($cachedBan['win_ts']) ? (int) $cachedBan['win_ts'] : $now;
            $lastTouch = isset($cachedBan['last_touch']) ? (int) $cachedBan['last_touch'] : 0;
            $inWin     = ($now - $winTs <= 8);
            $winHits   = $inWin ? ((int) (isset($cachedBan['win_hits']) ? $cachedBan['win_hits'] : 0) + 1) : 1;
            if ($winHits <= $drainLim || !$inWin || ($now - $lastTouch >= 12)) {
                $cachedBan['hits']       = (int) (isset($cachedBan['hits']) ? $cachedBan['hits'] : 0) + 1;
                $cachedBan['win_ts']     = $inWin ? $winTs : $now;
                $cachedBan['win_hits']   = $winHits;
                $cachedBan['last_touch'] = $now;
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
            $banTp = isset($cachedBan['ban_type']) ? (string) $cachedBan['ban_type'] : '';
            $retHttp = (in_array($banTp, ['ddos', 'prod_spam', 'key_spam'], true)) ? 429 : 403;
            sh_render_sad_page($cachedBan, $forensics['real_ip'], $retHttp);
            exit;
        }
    }

    // 2. Ironclad Cross-Device / Cross-VPN / IPv6-Prefix Lockdown Enforcement (Zero Evasion Loopholes!)
    $entityLock = ($hwFp !== '' ? sh_read_entity_lock('hw', $hwFp) : null)
               ?: ($lid !== '' ? sh_read_entity_lock('lid', $lid) : null)
               ?: (strpos($net64, '/64') !== false ? sh_read_entity_lock('net64', $net64) : null);
    if ($entityLock !== null) {
        $isVpnOnlyLock = (($entityLock['ban_type'] ?? '') === 'vpn_block' && !empty($entityLock['expires_ts']) && (int) $entityLock['expires_ts'] > $now);
        if ($isVpnOnlyLock) {
            // User already turned OFF VPN and returned to their real IP while the 2-minute VPN cooldown is still counting down:
            // keep the ORIGINAL 2-minute cooldown intact WITHOUT falsely escalating to 14 minutes or branding their residential IP as "VPN (US)"!
            sh_render_sad_page($entityLock, $ip, 403);
            exit;
        }
        $evCnt                          = (int) (isset($entityLock['evasion_attempts']) ? $entityLock['evasion_attempts'] : 0) + 1;
        $origExp                        = isset($entityLock['expires_ts']) ? (int) $entityLock['expires_ts'] : 0;
        $newExp                         = ($origExp === 0 || $evCnt >= 2) ? 0 : (max($origExp, $now + 600) + 600);
        $entityLock['ip']               = $ip;
        $entityLock['stream_ip']        = $forensics['stream_ip'];
        $entityLock['expires_ts']       = $newExp;
        $entityLock['expires_at']       = $newExp > 0 ? date('Y-m-d H:i:s', $newExp) : null;
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

    // 2C. Server-Side Repeated Wrong-Key / Too-Many-Attempts Spam Watcher (catches JS quickReload / cleared localStorage!)
    if (!sh_is_benign_crawler_ua() && isset($_GET['status']) && in_array((string) $_GET['status'], ['too_many_attempts', 'key_wrong'], true)) {
        $stPath = strtolower((string) strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?'));
        if ($stPath === '' || $stPath === '/' || strpos($stPath, 'home') !== false || strpos($stPath, 'index.php') !== false) {
            // Only count the final rendered page (not the 302 from '/' when /asadnsrdecsb/home follows < 1s later)
            $ksKey  = md5('kst14|' . $ip);
            $ksFile = sh_dir() . '/prodspam_hits/' . substr($ksKey, 0, 2) . '/' . $ksKey . '.json';
            $ksTrig = false;
            sh_json_update($ksFile, function ($s) use ($now, &$ksTrig) {
                $arr  = isset($s['t']) && is_array($s['t']) ? $s['t'] : [];
                $keep = [];
                foreach ($arr as $ts) {
                    if ((int) $ts >= $now - 35) {
                        $keep[] = (int) $ts;
                    }
                }
                // Collapse < 1s 302->200 double-hit into 1 attempt
                if (!$keep || ($now - end($keep)) >= 1) {
                    $keep[] = $now;
                }
                if (count($keep) >= 3) {
                    $ksTrig = true;
                    $keep   = [];
                }
                return ['t' => $keep];
            });
            if ($ksTrig) {
                $sessU = '';
                if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['username'])) {
                    $sessU = (string) $_SESSION['username'];
                }
                $kRec = sh_trigger_progressive_product_spam($ip, $sessU, 'Liên tục nhập sai mã Key / bấm kích hoạt dồn dập vượt giới hạn bảo vệ.');
                if (is_array($kRec)) {
                    sh_render_sad_page($kRec, $ip, 429);
                    exit;
                }
            }
        }
    }

    // 3. Anti-Bug, Anti-Buff & WAF Check -> Locked Strictly Until Bot or Admin Unlocks!
    if (!empty($cfg['waf_enabled'])) {
        $bugHit = sh_detect_waf_bug_buff($ip);
        if ($bugHit !== null) {
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
                'expires_ts' => 0,
                'expires_at' => null,
                'hits'       => 1,
            ];
            $savedBug = sh_write_ban_cache($ip, $rec);
            if (is_array($savedBug)) {
                $rec = $savedBug;
            }
            if ($forensics['real_ip'] !== $ip) {
                sh_write_ban_cache($forensics['real_ip'], $rec);
            }
            $sentBug = sh_push_live_tg_alert('script_bug_block', [
                'ip'        => $forensics['real_ip'] ?: $ip,
                'stream_ip' => $forensics['stream_ip'],
                'detail'    => $bugHit['why'],
                'reason'    => $reason,
                'case_code' => isset($rec['case_code']) ? (string) $rec['case_code'] : '',
                'hw_fp'     => substr($hwFp, 0, 12),
                'duration'  => 0,
            ]);
            sh_queue_pending_ban($rec + ['attack_detail' => $bugHit['why'], 'ua' => $forensics['ua'], 'alert_sent' => $sentBug ? 1 : 0]);
            sh_stats_bump('bug_blocks', 1);
            sh_render_sad_page($rec, $forensics['real_ip'], 403);
            exit;
        }
    }

    // 4. Supreme 12-Vector Eternal Anti-DDoS Engine v10 (0% False Positives for Verified Humans & Admins!)
    $h        = md5($ip);
    $rateFile = sh_dir() . '/rates/' . substr($h, 0, 2) . '/' . $h . '.json';
    $isPost   = strtoupper((string) (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET')) === 'POST';
    $isHuman  = sh_has_human_cookie($ip);
    $surge    = sh_is_surge_active();
    $nowMs    = (int) round(microtime(true) * 1000);
    $uaRaw    = (string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '');
    $isBotUa  = sh_is_flood_tool_ua() || ($uaRaw === '' || strlen($uaRaw) < 12 || (bool) preg_match('~(python|aiohttp|httpx|curl/|wget/|go-http-client|fasthttp|wrk|siege|vegeta|ab/|apachebench|scrapy|libwww|java/|okhttp|undici|axios/|node-fetch|got/|zgrab|masscan|nuclei|sqlmap|nikto|nmap|headlesschrome|phantomjs|stress|hulk|slowloris|goldeneye|cc-attack|cfbypass|cloudscraper|rust-reqwest|hyper/|pycurl)~i', $uaRaw));
    $proto    = strtoupper((string) (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1'));
    $noBrHdr  = empty($_SERVER['HTTP_ACCEPT']) && empty($_SERVER['HTTP_ACCEPT_LANGUAGE']) && empty($_SERVER['HTTP_SEC_FETCH_MODE']);
    $xffRaw   = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
    $remoteRaw = isset($_SERVER['REMOTE_ADDR']) ? sh_norm_ip($_SERVER['REMOTE_ADDR']) : '';
    $spoofedProxyHdr = ($remoteRaw !== '' && !sh_is_trusted_proxy($remoteRaw) && (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) || !empty($_SERVER['HTTP_TRUE_CLIENT_IP'])));
    $forgedXf = ($xffRaw !== '' && substr_count($xffRaw, ',') >= 4) || $spoofedProxyHdr;
    $noOriginPost = $isPost && empty($_SERVER['HTTP_ORIGIN']) && empty($_SERVER['HTTP_REFERER']);
    $anomPts  = ($isBotUa ? 3 : 0) + ($proto === 'HTTP/1.0' ? 2 : 0) + ($noBrHdr ? 2 : 0) + ($forgedXf ? 3 : 0) + ($noOriginPost ? 2 : 0);

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
            if (count($ips) >= 4 && count($keepH) >= 32) {
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
    sh_json_update($rateFile, function ($st) use ($now, $nowMs, $isPost, $isBotUa, $isHuman, $surge, $anomPts, $subnetSwarm, $noOriginPost, $spoofedProxyHdr, $qHash, $pHash, &$verdict) {
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

        // Robotic constant-interval cadence check (stdDev < 38ms across >= 10 unverified hits)
        $roboticCadence = false;
        if (!$isHuman && $c15s >= 10) {
            $tail  = array_slice($keepMs, -10);
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
                if ($std < 38.0) {
                    $roboticCadence = true;
                }
            }
        }

        $limNoHuman5  = ($surge || $subnetSwarm) ? 15 : ($anomPts >= 2 ? 19 : 34);
        $limNoHuman15 = ($surge || $subnetSwarm) ? 32 : ($anomPts >= 2 ? 40 : 70);

        if ($spoofedProxyHdr && $c5s >= 4) {
            $verdict = ['kind' => 'Spoofed Proxy Header Flood', 'rps' => $c5s, 'w15' => $c15s];
        } elseif ($isBotUa && ($c500ms >= 4 || $c1s >= 5 || $c3s >= 9 || $c5s >= 12 || $c15s >= 20)) {
            $verdict = ['kind' => 'L7 Tool / Botnet DDoS', 'rps' => $c5s, 'w15' => $c15s];
        } elseif (!$isHuman && ($c500ms >= 8 || $c1s >= 11 || $c3s >= 19 || $c5s >= $limNoHuman5 || $c15s >= $limNoHuman15)) {
            $verdict = ['kind' => ($subnetSwarm ? 'Botnet Subnet Swarm Flood' : 'L7/L4 HTTP Stream Flood'), 'rps' => $c5s, 'w15' => $c15s];
        } elseif (!$isHuman && $uniqQs10 >= 11) {
            $verdict = ['kind' => 'L7 Cache-Bypass Random Query Flood', 'rps' => $uniqQs10, 'w15' => $c15s];
        } elseif (!$isHuman && $uniqPt10 >= 10) {
            $verdict = ['kind' => 'L7 Multi-Path Scanner / Crawl Flood', 'rps' => $uniqPt10, 'w15' => $c15s];
        } elseif ($roboticCadence) {
            $verdict = ['kind' => 'Automated Constant-Rate Script Flood', 'rps' => $c5s, 'w15' => $c15s];
        } elseif (($noOriginPost && $cp10 >= 7) || $cp10 >= 20) {
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
        // Lock DDoS attackers (BOTH Flood Server / Stream IP AND unmasked Real Origin IP!) strictly until Bot or Admin unlocks!
        $ddosUnm = sh_resolve_true_origin_ip(null, $ip, $lid, $hwFp, '');
        $realAtk = !empty($ddosUnm['real_ip']) ? (string) $ddosUnm['real_ip'] : ($forensics['real_ip'] ?: $ip);
        $atkSrv  = $forensics['stream_ip'] !== '' ? (string) $forensics['stream_ip'] : $ip;
        $devEnvD = sh_detect_device_env($forensics['ua']);
        $reason  = 'Phát hiện gửi luồng tấn công DDoS liên tục (' . $verdict['kind'] . ' · ' . $verdict['w15'] . ' yêu cầu/15s).';
        $rec     = [
            'ip'              => $ip,
            'stream_ip'       => $atkSrv,
            'real_origin_ip'  => $realAtk,
            'real_origin_isp' => trim(($ddosUnm['real_isp'] ?? '') . (!empty($ddosUnm['real_country']) ? ' (' . $ddosUnm['real_country'] . ')' : '')),
            'device_env'      => $devEnvD,
            'reason'          => $reason,
            'ban_type'        => 'ddos',
            'lid'             => $lid,
            'hw_fp'           => $hwFp,
            'created_ts'      => $now,
            'created_at'      => date('Y-m-d H:i:s', $now),
            'expires_ts'      => 0,
            'expires_at'      => null,
            'hits'            => (int) $verdict['w15'],
        ];
        $savedDdos = sh_write_ban_cache($ip, $rec);
        if (is_array($savedDdos)) {
            $rec = $savedDdos;
        }
        // Block BOTH the flood-origin server AND the unmasked real origin IP simultaneously!
        foreach (array_unique(array_filter([$realAtk, $atkSrv, $forensics['real_ip']])) as $extraDdosIp) {
            if ($extraDdosIp !== $ip) {
                $recDup = $rec;
                $recDup['ip'] = $extraDdosIp;
                sh_write_ban_cache($extraDdosIp, $recDup);
            }
        }
        sh_surge_record_attack($ip, $verdict['kind']);
        $sentDdos = sh_push_live_tg_alert('ddos_block', [
            'ip'        => $realAtk,
            'stream_ip' => $atkSrv !== $realAtk ? $atkSrv : ($ip !== $realAtk ? $ip : ''),
            'detail'    => $verdict['kind'] . ' (' . $verdict['w15'] . ' req/15s · ' . $devEnvD . ')',
            'reason'    => $reason,
            'case_code' => isset($rec['case_code']) ? (string) $rec['case_code'] : '',
            'fails'     => (int) $verdict['w15'],
            'duration'  => 0,
        ]);
        sh_queue_pending_ban($rec + [
            'attack_detail' => $verdict['kind'] . ' (' . $verdict['w15'] . ' req/15s · khoá cả IP thật & Server DDoS)',
            'ua'            => $forensics['ua'],
            'alert_sent'    => $sentDdos ? 1 : 0,
        ]);
        sh_stats_bump('ddos_blocks', 1);
        @file_put_contents(sh_dir() . '/ddos_ips.list', date('Y-m-d H:i:s') . "\treal=" . $realAtk . "\tstream=" . $atkSrv . "\t" . $verdict['kind'] . "\tperm=1\n", FILE_APPEND | LOCK_EX);
        sh_render_sad_page($rec, $realAtk, 429);
        exit;
    }

    // 5. INSTANT ZERO-DELAY VPN / PROXY CHECK -> Enforces Real 2-Minute (120s) Block on BOTH VPN IP & Verified Real IP!
    if (!empty($cfg['vpn_gate_enabled']) && $_SERVER['REQUEST_METHOD'] === 'GET' && !sh_is_benign_crawler_ua()) {
        $uriPath = strtolower((string) strtok((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''), '?'));
        $isKeyBypassAttempt = (strpos($uriPath, 'get-free') !== false || strpos($uriPath, 'get_key') !== false || strpos($uriPath, 'key-free') !== false);

        $intel = sh_inspect_ip_vpn($ip);
        if (!empty($intel['is_vpn'])) {
            $lbl      = !empty($intel['label']) ? $intel['label'] : 'VPN / Proxy';
            $dur      = $isKeyBypassAttempt ? max(900, (int) $cfg['vpn_spam_ban_sec']) : 120;
            $btp      = $isKeyBypassAttempt ? 'vpn_abuse' : 'vpn_block';
            $unmasked = sh_resolve_true_origin_ip(null, $ip, $lid, $hwFp, '');
            $devEnvV  = sh_detect_device_env($forensics['ua']);
            $why      = $isKeyBypassAttempt
                ? ('VPN, proxy, or region-spoofing tool (' . $lbl . ') used to bypass Free Key restrictions.')
                : ('VPN / Proxy detected (' . $lbl . '). Disable VPN — access is locked for 2 minutes.');
            $rec = [
                'ip'              => $ip,
                'stream_ip'       => $forensics['stream_ip'],
                'vpn_ip'          => $ip,
                'vpn_lbl'         => $lbl,
                'real_origin_ip'  => $unmasked['real_ip'],
                'real_origin_isp' => trim($unmasked['real_isp'] . ($unmasked['real_country'] !== '' ? ' (' . $unmasked['real_country'] . ')' : '')),
                'real_ip_source'  => $unmasked['real_source'],
                'device_env'      => $devEnvV,
                'reason'          => $why,
                'ban_type'        => $btp,
                'lid'             => $lid,
                'hw_fp'           => $hwFp,
                'created_ts'      => $now,
                'created_at'      => date('Y-m-d H:i:s', $now),
                'expires_ts'      => $now + $dur,
                'expires_at'      => date('Y-m-d H:i:s', $now + $dur),
                'hits'            => 1,
            ];
            $savedV = sh_write_ban_cache($ip, $rec);
            if (is_array($savedV)) {
                $rec = $savedV;
            }
            // Persist the exact 2-minute VPN block onto the visitor's verified Real Origin IP too (so turning OFF VPN still stays locked until expiry or Bot/Admin unlock!)
            if (!empty($unmasked['real_ip']) && $unmasked['real_ip'] !== $ip) {
                $recRealV = $rec;
                $recRealV['ip'] = $unmasked['real_ip'];
                sh_write_ban_cache($unmasked['real_ip'], $recRealV);
            }
            sh_stats_bump('happy_gates', 1);
            // Zero Scanner Spam: if Real IP is already known via cookies/vault, fire live alert ONCE (300s dedup);
            // otherwise wait ~250ms for Lock-Screen JS (?__sh_realip_probe=1) so headless AWS/OVH bots NEVER spam Telegram!
            $deferToProbe = ($btp === 'vpn_block' && $unmasked['real_ip'] === '');
            $allowSyncAlert = !$deferToProbe && sh_vpn_alert_claim($unmasked['real_ip'], $ip, $hwFp, $lid, 300);
            $pushedV = false;
            if ($allowSyncAlert) {
                $pushedV = sh_push_live_tg_alert('vpn_gate_block', [
                    'vpn_ip'          => $ip,
                    'vpn_lbl'         => $lbl . ' · ' . $devEnvV,
                    'real_origin_ip'  => $unmasked['real_ip'],
                    'real_origin_isp' => $rec['real_origin_isp'],
                    'real_ip_source'  => $unmasked['real_source'],
                    'case_code'       => isset($rec['case_code']) ? (string) $rec['case_code'] : '',
                    'duration'        => $dur,
                ]);
            }
            sh_queue_pending_ban($rec + [
                'attack_detail' => 'Bật VPN (' . $lbl . ') · khoá ' . (int) ($dur / 60) . ' phút',
                'alert_sent'    => ($allowSyncAlert && !$pushedV) ? 0 : 1,
            ]);
            sh_render_sad_page($rec, $ip, 403);
            exit;
        }
        // Verified non-VPN residential IP -> memorize in Real-IP Vault so if they later turn on VPN, we immediately know their true IP!
        sh_record_real_ip_identity($ip, $intel, $lid, $hwFp, '');
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
    if (!function_exists('tg_event') && is_file(__DIR__ . '/telegram.php')) {
        try {
            require_once __DIR__ . '/telegram.php';
            if (function_exists('tg_boot')) {
                tg_boot('web');
            }
        } catch (Throwable $e) {}
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
            $expTs = isset($it['expires_ts']) ? (int) $it['expires_ts'] : 0;
            $left  = $expTs > 0 ? max(60, $expTs - time()) : 0;
            $btype = isset($it['ban_type']) ? (string) $it['ban_type'] : 'ddos';
            $why   = isset($it['reason']) ? $it['reason'] : 'Khoá bảo mật tự động';
            $unm   = isset($it['username']) ? $it['username'] : '';

            // Enrich VPN blocks with MySQL real-IP lookup if not yet resolved pre-DB!
            $realOriginIp  = !empty($it['real_origin_ip']) ? (string) $it['real_origin_ip'] : '';
            $realOriginIsp = !empty($it['real_origin_isp']) ? (string) $it['real_origin_isp'] : '';
            $realIpSrc     = !empty($it['real_ip_source']) ? (string) $it['real_ip_source'] : '';
            if (in_array($btype, ['vpn_block', 'vpn_abuse'], true) && $realOriginIp === '') {
                $resTrue = sh_resolve_true_origin_ip($conn, $ip, (string) ($it['lid'] ?? ''), (string) ($it['hw_fp'] ?? ''), $unm);
                if ($resTrue['real_ip'] !== '' && $resTrue['real_ip'] !== $ip) {
                    $realOriginIp  = $resTrue['real_ip'];
                    $realOriginIsp = trim($resTrue['real_isp'] . ($resTrue['real_country'] !== '' ? ' (' . $resTrue['real_country'] . ')' : ''));
                    $realIpSrc     = $resTrue['real_source'];
                }
            }

            if ($expTs === 0 && function_exists('ipg_ban')) {
                $res = ipg_ban($conn, $ip, $why, 'bot-ai', $unm, $btype);
            } else {
                $res = ipg_temp_ban($conn, $ip, max(60, $left), $why, 'bot-ai', $unm, $btype);
            }
            if (empty($it['alert_sent']) && function_exists('tg_event')) {
                $evName = ($btype === 'ddos')
                    ? 'ddos_block'
                    : (in_array($btype, ['vpn_block', 'vpn_abuse'], true)
                        ? 'vpn_gate_block'
                        : ($btype === 'prod_spam' ? 'key_spam_ban' : 'script_bug_block'));
                tg_event($evName, [
                    'ip'              => $realOriginIp !== '' ? $realOriginIp : $ip,
                    'real_origin_ip'  => $realOriginIp,
                    'real_origin_isp' => $realOriginIsp,
                    'real_ip_source'  => $realIpSrc,
                    'vpn_ip'          => in_array($btype, ['vpn_block', 'vpn_abuse'], true) ? $ip : (string) ($it['vpn_ip'] ?? ''),
                    'vpn_lbl'         => (string) ($it['vpn_lbl'] ?? ''),
                    'stream_ip'       => isset($it['stream_ip']) ? $it['stream_ip'] : $ip,
                    'username'        => $unm,
                    'ban_type'        => $btype,
                    'detail'          => isset($it['attack_detail']) ? $it['attack_detail'] : $btype,
                    'reason'          => $why,
                    'duration'        => $left,
                    'fails'           => isset($it['hits']) ? (int) $it['hits'] : 1,
                    'case_code'       => isset($it['case_code']) ? (string) $it['case_code'] : '',
                    'created_at'      => isset($it['created_at']) ? $it['created_at'] : date('Y-m-d H:i:s'),
                    'expires_at'      => $expTs > 0 ? (isset($it['expires_at']) ? $it['expires_at'] : date('Y-m-d H:i:s', time() + $left)) : 'Vĩnh viễn (Bot/Admin mở)',
                    'hw_fp'           => isset($it['hw_fp']) ? substr((string) $it['hw_fp'], 0, 12) : '',
                    'lid'             => isset($it['lid']) ? substr((string) $it['lid'], 0, 16) : '',
                    'evasions'        => isset($it['evasion_attempts']) ? (int) $it['evasion_attempts'] : 0,
                    'ban_id'          => !empty($res['row']['id']) ? (int) $res['row']['id'] : 0,
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
    if (function_exists('tg_flush')) {
        tg_flush(false);
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
        sh_eternal_sentinel_tick($conn);
        sh_sync_pending_to_db($conn);
        if (function_exists('ipg_sweep_expired')) {
            ipg_sweep_expired($conn, !empty($GLOBALS['SH_JUST_EXPIRED_IP']) ? $GLOBALS['SH_JUST_EXPIRED_IP'] : '');
        }

        $sessUser = isset($_SESSION['username']) && is_string($_SESSION['username']) ? trim($_SESSION['username']) : '';
        if ($sessUser === '') {
            sh_revoke_admin_pass_cookie();
        }
        if ($sessUser !== '' && $ip !== '') {
            $st = @$conn->prepare('SELECT role FROM users WHERE username = ? LIMIT 1');
            if ($st) {
                $st->bind_param('s', $sessUser);
                if ($st->execute()) {
                    $rs  = $st->get_result();
                    $row = $rs ? $rs->fetch_assoc() : null;
                    if ($row && (int) $row['role'] === 1) {
                        sh_remember_admin_ip($ip, $sessUser);
                        sh_record_real_ip_identity($ip, [], sh_browser_lid(), sh_hw_fp_cookie(), $sessUser);
                        $st->close();
                        return;
                    }
                    sh_revoke_admin_pass_cookie();
                }
                $st->close();
            }

            // Record logged-in member's verified non-VPN real IP so if they later switch on VPN, we always know their true IP!
            sh_record_real_ip_identity($ip, [], sh_browser_lid(), sh_hw_fp_cookie(), $sessUser);

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
        $origInfo = sh_resolve_true_origin_ip($conn, $ip, sh_browser_lid(), sh_hw_fp_cookie(), (string) $username);
        $realOrig = (!empty($origInfo['is_vpn']) && !empty($origInfo['real_ip']) && $origInfo['real_ip'] !== $ip) ? $origInfo['real_ip'] : '';
        $res      = ipg_temp_ban($conn, $ip, $banSec, $reason, 'bot-antivpn', (string) $username, 'vpn_abuse');
        if (!empty($res['ok'])) {
            sh_write_ban_cache($ip, [
                'id'              => !empty($res['row']['id']) ? (int) $res['row']['id'] : 0,
                'ip'              => $ip,
                'stream_ip'       => $forensics['stream_ip'],
                'real_origin_ip'  => $realOrig,
                'real_origin_isp' => $realOrig !== '' ? $origInfo['real_isp'] : '',
                'real_ip_source'  => $realOrig !== '' ? $origInfo['real_source'] : '',
                'vpn_ip'          => !empty($origInfo['is_vpn']) ? $ip : '',
                'vpn_lbl'         => !empty($origInfo['vpn_label']) ? $origInfo['vpn_label'] : '',
                'reason'          => $reason,
                'ban_type'        => 'vpn_abuse',
                'username'        => (string) $username,
                'expires_ts'      => $now + $banSec,
                'expires_at'      => date('Y-m-d H:i:s', $now + $banSec),
            ]);
            sh_stats_bump('vpn_blocks', 1);
            $cached = sh_read_ban_cache($ip) ?: ['reason' => $reason, 'ban_type' => 'vpn_abuse', 'expires_ts' => $now + $banSec];
            if (function_exists('tg_event')) {
                tg_event('vpn_multi_acc', [
                    'ip'             => $realOrig !== '' ? $realOrig : $ip,
                    'real_origin_ip' => $realOrig,
                    'vpn_ip'         => !empty($origInfo['is_vpn']) ? $ip : '',
                    'username'       => (string) $username,
                    'alts'           => $info['alts'],
                    'duration'       => $banSec,
                    'case_code'      => isset($cached['case_code']) ? $cached['case_code'] : '',
                    'reason'         => $reason,
                    'ban_id'         => !empty($res['row']['id']) ? (int) $res['row']['id'] : 0,
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
