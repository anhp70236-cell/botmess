<?php
/*
 * IOSMODS URL gate (loaded by nginx through auto_prepend_file, no page needs editing).
 *   /asadnsrdecsb/home  ->  /asadnsrdecsb/home?<token>
 * - Token = random nonce + expiry + HMAC-SHA256 (256-bit secret) bound to the page path and to the
 *   visitor's browser cookie. It cannot be guessed, edited, or reused in another browser.
 * - Every fresh visit gets a different token. An old or foreign link is silently re-issued.
 * - Not gated: key-free, get-free (the key flow), POST, AJAX/fetch and non-page requests.
 */
const UG_TTL    = 1800; // seconds a token stays valid
const UG_COOKIE = '__Host-ub';
const UG_KEYF   = __DIR__ . '/secret.key';
const UG_PAGES  = '#^/(?:asadnsrdecsb|lazrlshalvow|ddbyevhmtepm|zwpxnupvcnsk|lwhhrrrjuaoo|vfjhdizchmgr|egoyesfdemnt)(?:/|$)#';

function ug_secret() {
    $k = @file_get_contents(UG_KEYF);
    if ($k === false || strlen($k) < 32) {
        @file_put_contents(UG_KEYF, random_bytes(32), LOCK_EX);
        @chmod(UG_KEYF, 0600);
        $k = @file_get_contents(UG_KEYF);
    }
    return ($k !== false && strlen($k) >= 32) ? substr($k, 0, 32) : hash('sha256', __FILE__ . php_uname(), true);
}

function ug_b64($b)  { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function ug_unb64($s) { return base64_decode(strtr($s, '-_', '+/')); }

function ug_mac($path, $bid, $payload) {
    return substr(hash_hmac('sha256', 'ug1|' . $path . '|' . $bid . '|' . $payload, ug_secret(), true), 0, 16);
}

function ug_issue($path, $bid) {
    $payload = random_bytes(12) . pack('N', time() + UG_TTL);
    return ug_b64($payload . ug_mac($path, $bid, $payload));
}

function ug_valid($tok, $path, $bid) {
    $raw = ug_unb64($tok);
    if ($raw === false || strlen($raw) !== 32) return false;
    $payload = substr($raw, 0, 16);
    if (!hash_equals(ug_mac($path, $bid, $payload), substr($raw, 16))) return false;
    $e = unpack('N', substr($payload, 12, 4));
    return $e[1] >= time();
}

// Only real page navigations are gated (not fetch/XHR/iframes/images)
function ug_is_nav() {
    $s = $_SERVER;
    if (isset($s['HTTP_SEC_FETCH_DEST'])) {
        return $s['HTTP_SEC_FETCH_DEST'] === 'document' && (isset($s['HTTP_SEC_FETCH_MODE']) ? $s['HTTP_SEC_FETCH_MODE'] : 'navigate') === 'navigate';
    }
    if (!empty($s['HTTP_X_REQUESTED_WITH'])) return false;
    return isset($s['HTTP_ACCEPT']) && stripos($s['HTTP_ACCEPT'], 'text/html') !== false;
}

function ug_run() {
    if (PHP_SAPI === 'cli' || (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'GET') return;
    $uri  = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || !preg_match(UG_PAGES, $path) || !ug_is_nav()) return;

    $p     = rtrim(strtolower($path), '/');
    $qs    = isset($_SERVER['QUERY_STRING']) ? (string)$_SERVER['QUERY_STRING'] : '';
    $parts = explode('&', $qs, 2);
    $first = $parts[0];
    $keep  = $qs;                                   // other parameters are kept as they are
    $isTok = preg_match('/^[A-Za-z0-9_-]{43}$/', $first) === 1;
    if ($isTok) $keep = isset($parts[1]) ? $parts[1] : '';

    $bid       = isset($_COOKIE[UG_COOKIE]) ? $_COOKIE[UG_COOKIE] : '';
    $hasCookie = preg_match('/^[a-f0-9]{32}$/', $bid) === 1;

    if ($hasCookie && $isTok && ug_valid($first, $p, $bid)) {
        header('Referrer-Policy: same-origin');     // the token never leaks to other sites
        return;
    }
    if ($isTok && !$hasCookie) {                    // cookies are blocked: stop here, no redirect loop
        http_response_code(403);
        header('Cache-Control: no-store');
        exit('Please enable cookies and reload this page.');
    }
    if (!$hasCookie) {
        $bid = bin2hex(random_bytes(16));
        setcookie(UG_COOKIE, $bid, array('expires' => time() + 31536000, 'path' => '/',
            'secure' => true, 'httponly' => true, 'samesite' => 'Lax'));
    }
    header('Cache-Control: no-store');
    header('Location: ' . $path . '?' . ug_issue($p, $bid) . ($keep !== '' ? '&' . $keep : ''), true, 302);
    exit;
}

if (is_file(dirname(__DIR__) . '/security_shield.php')) {
    require_once dirname(__DIR__) . '/security_shield.php';
    sh_early_guard();
}
ug_run();
