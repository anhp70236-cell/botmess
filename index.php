<?php
ob_start(); // Keep any Notice/Warning in the buffer: never corrupt JSON or the Location header

const APP_DEBUG = false; // set to true to see PHP errors on the page
if (!APP_DEBUG) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1'); // errors are still written to the server error_log
}

/* Telegram alerts (telegram.php). Does nothing until telegram_config.php has a bot token + admin id.
   Wrapped in try/catch: a Telegram problem can never break the website. */
if (is_file(__DIR__ . '/telegram.php')) {
    try {
        require_once __DIR__ . '/telegram.php';
        tg_boot('web');
    } catch (Throwable $e) {
        error_log('[index.php] telegram: ' . $e->getMessage());
    }
}
/** Report an event to the Telegram admins. Silent no-op when Telegram is off or fails. (This is the real sender; site_tg() below only queues.) */
function site_tg_send(string $type, array $data = []): void
{
    if (!function_exists('tg_event')) {
        return;
    }
    try {
        tg_event($type, $data);
    } catch (Throwable $e) {
        error_log('[index.php] site_tg: ' . $e->getMessage());
    }
}

/* patch: tg-after-response v1 -- Telegram alerts used to be sent BEFORE the answer reached the browser, so every click and every page load waited for api.telegram.org,
   and the session lock stayed held so the next click queued behind it (= lag). Now an alert is only queued: the page / JSON is sent first, the connection is released,
   and only then the queued alerts are sent. Same alerts, same data, just later. */
function site_tg(string $type, array $data = []): void
{
    if (!function_exists('tg_event')) {
        return;
    }
    $GLOBALS['SITE_TG_QUEUE'][] = [$type, $data];
    if (empty($GLOBALS['SITE_TG_HOOKED'])) {
        $GLOBALS['SITE_TG_HOOKED'] = true;
        register_shutdown_function('site_tg_flush');
    }
}

function site_tg_flush(): void
{
    $queue = (isset($GLOBALS['SITE_TG_QUEUE']) && is_array($GLOBALS['SITE_TG_QUEUE'])) ? $GLOBALS['SITE_TG_QUEUE'] : [];
    $GLOBALS['SITE_TG_QUEUE'] = [];
    if (!$queue) {
        return;
    }
    try {
        ignore_user_abort(true);
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        if (function_exists('session_write_close')) {
            @session_write_close(); // release the session lock so the user's next click is not blocked
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        } else {
            @flush();
        }
    } catch (Throwable $e) {
        // delivering the answer never depends on this
    }
    foreach ($queue as $item) {
        site_tg_send((string) $item[0], (array) $item[1]);
    }
}

require_once __DIR__ . '/db.php';

/* Session cookie: HttpOnly + SameSite=Lax (+ Secure over HTTPS). Lifetime/path keep their configured values. */
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $cookie = session_get_cookie_params();
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => $cookie['lifetime'],
            'path'     => $cookie['path'],
            'domain'   => $cookie['domain'],
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        // PHP 7.2 (host runs 7.2.34): no array form / samesite support, so append samesite to the path
        session_set_cookie_params($cookie['lifetime'], $cookie['path'] . '; samesite=Lax', $cookie['domain'], $isHttps, true);
    }
    session_start();
}

// Maintenance check runs AFTER the session is open so it can read $_SESSION (e.g. let Admins in during maintenance)
if (is_file(__DIR__ . '/check_maintenance.php')) {
    include_once __DIR__ . '/check_maintenance.php';
}

// SQL errors always throw (all PHP versions) so data is never silently corrupted
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
set_exception_handler(function (Throwable $e) {
    error_log('[index.php] ' . $e);
    discard_output();
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit('The system is busy. Please try again later.');
});

// Pages depend on the user: do not let browsers/CDNs cache stale copies
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
// Prevent framing by other sites (clickjacking) and some MIME-sniffing attacks
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

/* The two links below are only DEFAULTS. Set the real links in the site_config table:
   link_admin_tele = "Buy VIP key" button,  link_bypass = "Get Free Key" button. */
const LINK_ADMIN_TELEGRAM  = 'https://t.me/iosnea';
const LINK_FREE_KEY        = 'https://link4m.com/wKG21';
const DEFAULT_KEY_HOURS    = 24;
const ADMIN_EXEMPT         = false; // true: Admin is exempt from the rules and never loses turns. false: Admin follows the same rules as everyone
const VIP_FREE_CLICK_LIMIT = 3;     // VIP key clicking a Free product: 3 warnings, the 4th click costs 1 VIP turn
const TURNS_PER_KEY        = ['FREE' => 2, 'VIP' => 1]; // turns granted per key type
const STATUS_KEYS          = ['success', 'key_wrong', 'not_logged_in', 'has_turn', 'need_vip', 'need_key', 'vip_free', 'vip_free_penalty', 'too_many_attempts', 'csrf_fail', 'network_error', 'key_daily_limit'];
const JSON_SAFE            = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

/* Key brute-force protection: too many wrong attempts within the window = temporary lock (even for a correct key) */
const KEY_MAX_FAILS_USER   = 5;   // max failed attempts per account
const KEY_MAX_FAILS_IP     = 30;  // max failed attempts per IP (higher because many users can share a mobile-network IP)
const KEY_FAIL_WINDOW_MIN  = 10;  // window length (minutes), also the lock duration
const KEY_MAX_LENGTH       = 100; // keys longer than this are rejected immediately (matches key_code varchar(100) in SQL)
const CLIENT_IP_HEADER     = '';  // empty = use REMOTE_ADDR. Behind Cloudflare set 'HTTP_CF_CONNECTING_IP'


/* patch: key-daily-limit v25 -- each ACCOUNT and each IP may activate only 1 key per day. The IP is stored in table key_daily_claims,

   so opening incognito mode or creating another account does not give a second key on the same day. */

const KEY_DAILY_LIMIT_TYPES = ['FREE']; const KEY_DAILY_LIMIT_HOURS = 8; /*DS-PATCH-BOOT4*/ // key types that are limited per day. Add 'VIP' here to limit VIP keys as well


/* Permanent IP ban (ipguard.php): a banned IP only sees the "Access blocked" page. Bans are made from the Telegram bot.
   Wrapped in try/catch: a guard problem can never take the website down. */
if (is_file(__DIR__ . '/ipguard.php')) {
    try {
        require_once __DIR__ . '/ipguard.php';
        ipg_run($conn);
    } catch (Throwable $e) {
        error_log('[index.php] ipguard: ' . $e->getMessage());
    }
}

/* Avoid unfair turn loss: right after a turn is spent on a product, clicking that SAME product again within a few seconds reopens it for free.
   (Popup blocked by the browser, flaky network, accidentally closed tab...). Set 0 to disable. */
const REOPEN_GRACE_SECONDS = 90;

const SCHEMA_CACHE_SECONDS = 300; // how long the DB column/table check result is cached

/* Tab / Google / browser icon (favicon): the original logo is auto-converted into a compact square image with padding.
   Default source link below; override via site_config.favicon_url. */
const DEFAULT_FAVICON       = 'https://i.postimg.cc/R0N1S49b/IMG-1242.png';
const FAVICON_CACHE_SECONDS = 604800; // cache the processed image for 7 days
const FAVICON_PADDING       = 0.03;   // padding per side (3% of the image side; was 8%). Increase = smaller logo in the tab, decrease = larger
const FAVICON_VERSION       = 3;      // bump when the favicon style changes to force browsers/Google/caches to regenerate the image
/* Favicon background. The site logo is light text designed for the dark header bar, so on a white tab it is almost invisible (only a tiny blue D remains).
   Use the same dark color as the header bar so the full logo is visible and larger. For a transparent background as before: site_config.favicon_bg = none.
   Change the color: site_config.favicon_bg = #hex (e.g. #ffffff). */
const FAVICON_BG            = '#14161c';

/* Logo on the dark header bar: the server downloads the original image ONCE, resizes it to the displayed size and serves it from the site domain itself
   (with 7-day browser cache + preload), so the logo shows almost instantly without waiting for an external image host.
   Default source link below; override via site_config.brand_logo. */
const DEFAULT_BRAND_LOGO       = 'uploads/logo.png';
const BRAND_LOGO_CACHE_SECONDS = 604800; // browsers cache the logo for 7 days
const BRAND_LOGO_INLINE_MAX    = 122880; // a processed logo lighter than this (120 KB, was 60 KB) is inlined into the HTML => shows instantly, no extra request
const BRAND_LOGO_MIN_HEIGHT    = 120;    // a logo too heavy to inline is shrunk step by step, but not below this height (px) so it stays sharp
const BRAND_LOGO_PAGE_INLINE_MAX = 20480; // only a logo this light (20 KB) is inlined into the HTML. The page is sent with no-store, so a heavier inline logo would be re-sent on every page view; heavier ones are served via ?brand_logo=1 (7-day cache + preload) instead
const BRAND_LOGO_FETCH_LIMIT   = 15728640; // max original image size 15 MB (was 3 MB: larger originals were skipped and the logo loaded slowly from the external host)
const BRAND_LOGO_MAX_HEIGHT    = 200;    // the logo is displayed about 60px tall; 200px is sharp enough even for 3x screens
const BRAND_LOGO_MAX_WIDTH     = 800;
const BRAND_LOGO_RETRY_SECONDS = 300;    // after a failed download wait this many seconds before retrying (meanwhile use the original link directly)

/* ------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------ */

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Get a text value from site_config; empty or missing falls back to the default. */
function cfg(array $config, string $key, string $default): string
{
    $value = trim((string) ($config[$key] ?? ''));
    return $value === '' ? $default : $value;
}

/** CSS color entered by Admin: only #hex, rgb()/rgba()/hsl()/hsla() or a color name is accepted; anything else is ignored. */
function css_color($value, string $default = ''): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return $default;
    }
    if (preg_match('/^#[0-9a-f]{3,8}$/i', $value)
        || preg_match('/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9.\s,%\/-]+\)$/i', $value)
        || preg_match('/^[a-z]{3,20}$/i', $value)) {
        return $value;
    }
    return $default;
}

/** Only allow redirects to http(s):// or internal paths (no `//host` or `/\host`). */
function safe_url($url): string
{
    $url = trim((string) $url);
    if ($url === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $url)) {
        return 'index.php';
    }
    if (preg_match('#^https?://[^/\s]#i', $url) || preg_match('#^/(?!/)#', $url)) {
        return $url;
    }
    return 'index.php';
}

/** Sanitize the key typed by the user: strip invisible characters picked up when copying from Telegram/Zalo, enforce max length. */
function clean_key($raw): string
{
    if (!is_string($raw)) {
        return '';
    }
    $raw = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $raw) ?? '';
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) > KEY_MAX_LENGTH || preg_match('/[\x00-\x1f\x7f]/', $raw)) {
        return '';
    }
    return $raw;
}

function db_execute(mysqli $conn, string $sql, string $types = '', array $params = []): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('prepare failed: ' . $conn->error);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('execute failed: ' . $error);
    }
    return $stmt;
}

/** Discard everything held in the output buffer (Notices, stray whitespace...) before returning JSON/redirects. */
function discard_output(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

function json_response(array $data): void
{
    discard_output();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_SAFE);
    exit;
}

function plain_response(string $text): void
{
    discard_output();
    header('Cache-Control: no-store');
    exit($text);
}

function redirect(string $location): void
{
    discard_output();
    header('Cache-Control: no-store');
    header('Location: ' . $location);
    exit;
}

/* ------------------------------------------------------------------
 * Favicon: turn any logo (long, wide, transparent background...) into a square PNG with padding,
 * that looks tidy in a tab. If any step fails (host has no GD, image cannot be fetched...) the original image is used as-is.
 * ------------------------------------------------------------------ */

/** Record why image processing failed (only for Admin to see at ?brand_logo=debug). Call with no argument to read it back. */
function image_note(?string $note = null): string
{
    static $last = '';
    if ($note !== null) {
        $last = $note;
    }
    return $last;
}

/** Fetch image bytes from an http(s) link or from an internal path of the site. Returns null on failure. */
function fetch_image_bytes(string $url): ?string
{
    $limit = BRAND_LOGO_FETCH_LIMIT; // anything larger is abnormal, skip it

    // Internal path (e.g. /uploads/logo.png): read straight from the web root, no network call needed
    if (preg_match('#^/(?!/)#', $url)) {
        $docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        $root    = $docRoot !== '' ? realpath($docRoot) : false;
        $path    = (string) parse_url($url, PHP_URL_PATH);
        $file    = $root !== false ? realpath($root . $path) : false;
        if ($file === false || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file) || filesize($file) > $limit) {
            image_note('cannot read local file (bad path or file too large)');
            return null;
        }
        $bytes = @file_get_contents($file);
        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    if (!preg_match('#^https?://#i', $url)) {
        image_note('image link does not start with http(s)://');
        return null;
    }

    $bytes = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (favicon fetch)',
        ]);
        $body  = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr  = curl_error($ch);
        curl_close($ch);
        if (is_string($body) && $code === 200) {
            $bytes = $body;
        } else {
            image_note('curl failed to download the image (HTTP ' . $code . ($cerr !== '' ? ', ' . $cerr : '') . ')');
        }
    } elseif (ini_get('allow_url_fopen')) {
        $context = stream_context_create(['http' => [
            'timeout' => 8, 'follow_location' => 1, 'max_redirects' => 3, 'user_agent' => 'Mozilla/5.0 (favicon fetch)',
        ]]);
        $body = @file_get_contents($url, false, $context, 0, $limit + 1);
        if (is_string($body)) {
            $bytes = $body;
        } else {
            image_note('file_get_contents failed to download the image');
        }
    } else {
        image_note('host has no curl and allow_url_fopen is off, so external images cannot be downloaded');
    }

    if ($bytes !== null && strlen($bytes) > $limit) {
        image_note('original image is larger than ' . (int) ($limit / 1048576) . ' MB');
        return null;
    }
    return ($bytes !== null && $bytes !== '') ? $bytes : null;
}

/** Sniff the image type from its content (no GD needed). Returns ['mime' => ..., 'ext' => ...] or null if it is not a common web image. */
function sniff_image_type(string $bytes): ?array
{
    if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) {
        return ['mime' => 'image/png', 'ext' => 'png'];
    }
    if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
        return ['mime' => 'image/jpeg', 'ext' => 'jpg'];
    }
    if (strncmp($bytes, 'GIF8', 4) === 0) {
        return ['mime' => 'image/gif', 'ext' => 'gif'];
    }
    if (strlen($bytes) > 12 && strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') {
        return ['mime' => 'image/webp', 'ext' => 'webp'];
    }
    return null;
}

/** Background color #rgb / #rrggbb to [r, g, b]. Other values (none, transparent, malformed) return null = transparent background. */
function hex_to_rgb(string $value): ?array
{
    $value = ltrim(trim($value), '#');
    if (preg_match('/^[0-9a-f]{3}$/i', $value)) {
        $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
    }
    if (!preg_match('/^[0-9a-f]{6}$/i', $value)) {
        return null;
    }
    return [hexdec(substr($value, 0, 2)), hexdec(substr($value, 2, 2)), hexdec(substr($value, 4, 2))];
}

/**
 * Draw the logo centered in a square $size x $size frame, leaving FAVICON_PADDING on each side, keeping the aspect ratio.
 * $bg = [r, g, b]: fill a solid background of this color (so light-colored logo text is not invisible on a white tab).
 * $opaque = true with no $bg: solid white background (used for iPhone, which paints black into transparent areas).
 */
function build_favicon_png(string $url, int $size, bool $opaque, ?array $bg = null): ?string
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
        return null;
    }
    @ini_set('memory_limit', '256M'); // large source images need lots of memory to decode
    $bytes = fetch_image_bytes($url);
    $src   = $bytes !== null ? @imagecreatefromstring($bytes) : false;
    if ($src === false) {
        return null;
    }

    // Trim the excess transparent border around the logo so it always fills the frame (not tiny)
    if (function_exists('imagecropauto')) {
        $trimmed = @imagecropauto($src, IMG_CROP_TRANSPARENT);
        if ($trimmed !== false) {
            imagedestroy($src);
            $src = $trimmed;
        }
    }

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) {
        imagedestroy($src);
        return null;
    }

    $pad   = (int) round($size * FAVICON_PADDING);
    $inner = $size - 2 * $pad;
    $scale = min($inner / $w, $inner / $h);
    $dw    = max(1, (int) round($w * $scale));
    $dh    = max(1, (int) round($h * $scale));

    $solid = $bg !== null || $opaque; // solid background (color or white) vs transparent

    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    if ($bg !== null) {
        $fill = imagecolorallocate($dst, $bg[0], $bg[1], $bg[2]);
    } elseif ($opaque) {
        $fill = imagecolorallocate($dst, 255, 255, 255);
    } else {
        $fill = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    }
    imagefill($dst, 0, 0, $fill);
    imagealphablending($dst, $solid); // solid background: blend the transparent parts of the logo onto it; transparent background: keep transparency as is
    imagecopyresampled($dst, $src, $pad + intdiv($inner - $dw, 2), $pad + intdiv($inner - $dh, 2), 0, 0, $dw, $dh, $w, $h);
    imagedestroy($src);

    ob_start();
    imagepng($dst, null, 9);
    $png = (string) ob_get_clean();
    imagedestroy($dst);

    return $png !== '' ? $png : null;
}

/**
 * Serve the favicon image to the browser. $variant = 'apple' is the 180px iPhone version, '48' is the 48px (Google) version,
 * anything else is the 192px version used for tabs/browsers. $bgValue is the background color (site_config.favicon_bg), 'none' = transparent.
 */
function serve_favicon(string $sourceUrl, string $variant, string $bgValue = FAVICON_BG): void
{
    $apple = $variant === 'apple';
    $size  = $apple ? 180 : ($variant === '48' ? 48 : 192); // 48 / 192 are multiples of 48px: Google requires this size for icons in search results
    $bg    = hex_to_rgb($bgValue);
    $cache = brand_logo_dir() . '/favicon_' . md5($sourceUrl . '|' . $size . '|v' . FAVICON_VERSION . '|' . FAVICON_PADDING . '|' . ($bg !== null ? implode(',', $bg) : 'none')) . '.png';

    $png = null;
    if (is_file($cache) && filemtime($cache) > time() - FAVICON_CACHE_SECONDS) {
        $png = @file_get_contents($cache);
    }
    if (!is_string($png) || $png === '') {
        $failMark = $cache . '.fail';
        if (is_file($failMark) && filemtime($failMark) > time() - BRAND_LOGO_RETRY_SECONDS) {
            redirect($sourceUrl); // it failed a moment ago: do not make every visitor wait for another download attempt
        }
        $png = build_favicon_png($sourceUrl, $size, $apple, $bg);
        if ($png === null) {
            @file_put_contents($failMark, '1', LOCK_EX);
        }
        if ($png === null) {
            redirect($sourceUrl); // fallback: temporarily use the original image
        }
        @file_put_contents($cache, $png, LOCK_EX);
    }

    discard_output();
    header_remove('Pragma');
    header_remove('Expires');
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . strlen($png));
    echo $png;
    exit;
}

/* ------------------------------------------------------------------
 * Header logo: download the original image once, shrink it (keep aspect ratio, keep transparency, do NOT trim the border
 * so the layout stays the same) and serve it from the site domain itself with a long cache.
 * If any step fails (host has no GD, image cannot be fetched...) redirect straight to the original link as before.
 * ------------------------------------------------------------------ */

/** Original link of the header logo (read from site_config.brand_logo). Relative paths such as "uploads/logo.png" are accepted too. */
function brand_logo_source(array $config): string
{
    $src = cfg($config, 'brand_logo', DEFAULT_BRAND_LOGO);
    if (!preg_match('#^https?://#i', $src)) {
        $src = '/' . ltrim($src, '/\\');
    }
    return safe_url($src);
}

/** Directory for processed logos: prefer a "cache" folder next to index.php (not auto-cleaned like the temp dir); fall back to the temp dir if it is not writable. */
function brand_logo_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $local = __DIR__ . '/cache';
    if ((is_dir($local) || @mkdir($local, 0755, true)) && is_writable($local)) {
        return $dir = $local;
    }
    return $dir = rtrim(sys_get_temp_dir(), '/\\');
}

function brand_logo_base(string $sourceUrl): string
{
    return brand_logo_dir() . '/brandlogo_' . md5($sourceUrl);
}

/** Possible extensions of processed logo files on the server (webp/png from GD resizing; jpg/gif only appear when the host has no GD and the original must be kept). */
function brand_logo_types(): array
{
    return ['webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif'];
}

/** Is the processed logo already on the server? If so returns ['mime' => ..., 'bytes' => ...], otherwise null. Disk read only, very fast. */
function brand_logo_cached(string $sourceUrl): ?array
{
    $base = brand_logo_base($sourceUrl);
    foreach (brand_logo_types() as $ext => $mime) {
        $file = $base . '.' . $ext;
        if (is_file($file)) {
            $bytes = @file_get_contents($file);
            if (is_string($bytes) && $bytes !== '') {
                return ['mime' => $mime, 'bytes' => $bytes];
            }
        }
    }
    return null;
}

/** Store the processed logo (write a temp file then rename so nobody reads a half-written file). */
function brand_logo_store(string $sourceUrl, array $logo): bool
{
    $base = brand_logo_base($sourceUrl);
    $ext  = array_search($logo['mime'], brand_logo_types(), true);
    if ($ext === false) {
        $ext = 'png';
    }
    $tmp = $base . '.' . $ext . '.' . bin2hex(random_bytes(4)) . '.tmp';

    if (@file_put_contents($tmp, $logo['bytes'], LOCK_EX) === false) {
        @unlink($tmp);
        image_note('cannot write file to directory ' . brand_logo_dir());
        return false;
    }
    if (!@rename($tmp, $base . '.' . $ext)) {
        @unlink($tmp);
        image_note('cannot rename file in directory ' . brand_logo_dir());
        return false;
    }
    foreach (array_keys(brand_logo_types()) as $other) {
        if ($other !== $ext) {
            @unlink($base . '.' . $other);
        }
    }
    return true;
}

/** Encode one logo variant from a drawn GD image: prefer PNG; if the PNG is too heavy to inline also try WebP. Returns ['mime', 'bytes'] or null. */
function brand_logo_encode($img): ?array
{
    ob_start();
    imagepng($img, null, 9);
    $png    = (string) ob_get_clean();
    $result = $png !== '' ? ['mime' => 'image/png', 'bytes' => $png] : null;

    if (($result === null || strlen($png) > BRAND_LOGO_INLINE_MAX) && function_exists('imagewebp')) {
        ob_start();
        $ok   = @imagewebp($img, null, 92);
        $webp = (string) ob_get_clean();
        if ($ok && $webp !== '' && ($result === null || strlen($webp) < strlen($png))) {
            $result = ['mime' => 'image/webp', 'bytes' => $webp];
        }
    }
    return $result;
}

/**
 * Shrink the logo to at most BRAND_LOGO_MAX_HEIGHT x BRAND_LOGO_MAX_WIDTH (never upscale).
 * Prefer PNG (lossless); if the PNG is too heavy to inline in the page use WebP (much lighter, still transparent).
 * If still heavier than BRAND_LOGO_INLINE_MAX, shrink further step by step (not below BRAND_LOGO_MIN_HEIGHT) so it can always be inlined.
 * Host without the GD library, or GD cannot read the image: keep the original image (still served from the site domain, still faster than an external image host).
 * Returns ['mime' => ..., 'bytes' => ...] or null.
 */
function build_brand_logo(string $url): ?array
{
    @ini_set('memory_limit', '256M'); // large source images need lots of memory to decode
    $bytes = fetch_image_bytes($url);
    if ($bytes === null) {
        return null;
    }

    $type = sniff_image_type($bytes);
    $raw  = $type !== null ? ['mime' => $type['mime'], 'bytes' => $bytes] : null;

    if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
        image_note('host has no GD library, using the original image as-is');
        return $raw;
    }
    $src = @imagecreatefromstring($bytes);
    if ($src === false) {
        image_note('GD cannot read the original image, using it as-is');
        return $raw;
    }

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) {
        imagedestroy($src);
        return $raw;
    }

    $scale  = min(1, BRAND_LOGO_MAX_HEIGHT / $h, BRAND_LOGO_MAX_WIDTH / $w);
    $result = null;

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $dw = max(1, (int) round($w * $scale));
        $dh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($dw, $dh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $w, $h);

        $encoded = brand_logo_encode($dst);
        imagedestroy($dst);

        if ($encoded !== null) {
            $result = $encoded;
        }
        // Light enough to inline, or already at the smallest allowed size: stop
        if ($result !== null && (strlen($result['bytes']) <= BRAND_LOGO_INLINE_MAX || $dh <= BRAND_LOGO_MIN_HEIGHT)) {
            break;
        }
        $next = $scale * 0.8;
        if ($h * $next < BRAND_LOGO_MIN_HEIGHT) {
            $next = BRAND_LOGO_MIN_HEIGHT / $h;
        }
        if ($next >= $scale - 0.001) {
            break;
        }
        $scale = $next;
    }

    imagedestroy($src);
    return $result !== null ? $result : $raw;
}

/** Did the last logo download fail recently? (within BRAND_LOGO_RETRY_SECONDS seconds) */
function brand_logo_failed_recently(string $sourceUrl): bool
{
    $fail = brand_logo_base($sourceUrl) . '.fail';
    return is_file($fail) && filemtime($fail) > time() - BRAND_LOGO_RETRY_SECONDS;
}

/**
 * Make sure the processed logo is available on the server: read it if present; otherwise download the original, shrink it and store it.
 * Returns ['mime', 'bytes'] or null (recently failed / someone else is working on it / could not be built).
 * Shared by serving the image (?brand_logo=1) and by warming the cache right after the page has been sent to the visitor.
 */
function brand_logo_prepare(string $sourceUrl, bool $force = false): ?array
{
    if (!$force) {
        $logo = brand_logo_cached($sourceUrl);
        if ($logo !== null) {
            return $logo;
        }
        // Failed recently: do not make anyone wait again
        if (brand_logo_failed_recently($sourceUrl)) {
            return null;
        }
    }

    $base = brand_logo_base($sourceUrl);
    $lock = @fopen($base . '.lock', 'c');
    if ($lock !== false && !@flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock); // another request is already downloading the logo: do not download twice
        return null;
    }

    try {
        if (!$force) { // someone else may have just finished while we waited for the lock
            $logo = brand_logo_cached($sourceUrl);
            if ($logo !== null) {
                return $logo;
            }
        }

        $fail = $base . '.fail';
        image_note('');
        $logo = build_brand_logo($sourceUrl);
        if ($logo === null || !brand_logo_store($sourceUrl, $logo)) {
            if ($logo === null && image_note() === '') {
                image_note('could not build the logo');
            }
            @file_put_contents($fail, image_note() !== '' ? image_note() : '1', LOCK_EX);
            return $logo !== null ? $logo : null; // storing to disk failed but an image exists: still usable for this request
        }
        @unlink($fail);
        return $logo;
    } finally {
        if ($lock !== false) {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/** Serve the header logo image to the browser (7-day cache, ETag so later requests cost only a few bytes). Also where the cache is warmed the first time. */
function serve_brand_logo(string $sourceUrl): void
{
    $logo = brand_logo_prepare($sourceUrl);
    if ($logo === null) {
        redirect($sourceUrl); // fallback: temporarily use the original image
    }

    $etag = '"' . md5($logo['bytes']) . '"';

    discard_output();
    header_remove('Pragma');
    header_remove('Expires');
    header('Cache-Control: public, max-age=' . BRAND_LOGO_CACHE_SECONDS);
    header('ETag: ' . $etag);

    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }

    header('Content-Type: ' . $logo['mime']);
    header('Content-Length: ' . strlen($logo['bytes']));
    echo $logo['bytes'];
    exit;
}

/**
 * Admin diagnostics page: open  /?brand_logo=debug  while logged in as Admin to see why the logo has not been stored yet.
 * Add &rebuild=1 to delete the old copy and rebuild immediately. Anyone else sees it as a non-existent page.
 */
function brand_logo_debug(mysqli $conn, string $sourceUrl): void
{
    $viewer = (string) ($_SESSION['username'] ?? '');
    $user   = $viewer !== '' ? find_user($conn, $viewer) : null;
    if ($user === null || (int) $user['role'] !== 1) {
        discard_output();
        http_response_code(404);
        exit;
    }

    $lines = [];
    if (isset($_GET['rebuild'])) {
        $base = brand_logo_base($sourceUrl);
        foreach (array_keys(brand_logo_types()) as $ext) {
            @unlink($base . '.' . $ext);
        }
        @unlink($base . '.fail');
        $started = microtime(true);
        $logo    = brand_logo_prepare($sourceUrl, true);
        $lines[] = 'Rebuild: ' . ($logo !== null ? 'OK ' . $logo['mime'] . ' ' . strlen($logo['bytes']) . ' bytes' : 'FAILED')
                 . ' (' . round(microtime(true) - $started, 2) . ' seconds)';
        if (image_note() !== '') {
            $lines[] = 'Note: ' . image_note();
        }
    }

    $cached  = brand_logo_cached($sourceUrl);
    $failMark = brand_logo_base($sourceUrl) . '.fail';
    $lines[] = 'Source image link: ' . $sourceUrl;
    $lines[] = 'PHP: ' . PHP_VERSION;
    $lines[] = 'GD: ' . (function_exists('imagecreatefromstring') ? 'yes' : 'NO') . ' | WebP: ' . (function_exists('imagewebp') ? 'yes' : 'no')
             . ' | curl: ' . (function_exists('curl_init') ? 'yes' : 'NO') . ' | allow_url_fopen: ' . (ini_get('allow_url_fopen') ? 'on' : 'off');
    $lines[] = 'Storage directory: ' . brand_logo_dir() . (is_writable(brand_logo_dir()) ? ' (writable)' : ' (NOT writable)');
    $lines[] = 'Stored logo: ' . ($cached !== null
        ? $cached['mime'] . ', ' . strlen($cached['bytes']) . ' bytes => ' . (strlen($cached['bytes']) <= BRAND_LOGO_PAGE_INLINE_MAX ? 'INLINED into the page (shows instantly)' : 'served via ?brand_logo=1 (heavier than the inline limit)')
        : 'NOT stored yet');
    $lines[] = 'Last failure: ' . (is_file($failMark)
        ? date('Y-m-d H:i:s', (int) filemtime($failMark)) . ' - ' . trim((string) @file_get_contents($failMark))
        : 'none');

    discard_output();
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit(implode("\n", $lines) . "\n");
}

/* ------------------------------------------------------------------
 * CSRF: every action that changes turns/keys must carry the token of the current session
 * ------------------------------------------------------------------ */

function csrf_valid($token): bool
{
    return is_string($token)
        && $token !== ''
        && isset($_SESSION['csrf'])
        && hash_equals((string) $_SESSION['csrf'], $token);
}

/** Modern browsers report requests coming from another site by themselves: block them outright. */
function request_is_same_site(): bool
{
    return strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) !== 'cross-site';
}

/** Is $ip inside the CIDR range $cidr (IPv4 or IPv6)? */
function ip_in_cidr(string $ip, string $cidr): bool
{
    $parts  = explode('/', $cidr, 2);
    $subnet = @inet_pton($parts[0]);
    $addr   = @inet_pton($ip);
    $bits   = isset($parts[1]) ? (int) $parts[1] : 0;
    if ($subnet === false || $addr === false || strlen($subnet) !== strlen($addr)) {
        return false;
    }
    $bytes = intdiv($bits, 8);
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

/** Is this address one of Cloudflare's published ranges? (https://www.cloudflare.com/ips/) */
function is_cloudflare_ip(string $ip): bool
{
    static $ranges = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];
    foreach ($ranges as $range) {
        if (ip_in_cidr($ip, $range)) {
            return true;
        }
    }
    return false;
}

function client_ip(): string
{
    $ip = '';
    if (CLIENT_IP_HEADER !== '' && !empty($_SERVER[CLIENT_IP_HEADER])) {
        $ip = trim(explode(',', (string) $_SERVER[CLIENT_IP_HEADER])[0]);
    }
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $cfIp   = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        // Behind Cloudflare: use the visitor's real IP, but only when the connection really comes from a Cloudflare address (a visitor cannot fake that)
        $ip = ($cfIp !== '' && filter_var($cfIp, FILTER_VALIDATE_IP) !== false && is_cloudflare_ip($remote)) ? $cfIp : $remote;
    }
    return substr($ip, 0, 45);
}

/* ------------------------------------------------------------------
 * Notices: legacy-style text, a single place used by both PHP and JavaScript
 * Variables: {n} {limit} {turns} {plan} {minutes}
 * Admin can override each notice via site_config.notice_<code>, e.g. notice_key_wrong
 * (type \n for a line break, keep the {…} variables as they are). Empty = use the default text below.
 * ------------------------------------------------------------------ */

function notice_catalog(): array
{
    $catalog = [
        'success'           => "Key activated successfully!\nYou received {turns} {plan} turn(s).",
        'key_wrong'         => 'Invalid key. Please check it and try again!',
        'not_logged_in'     => 'Please log in to continue!',
        'need_login'        => 'You need to log in!',
        'has_turn'          => 'System: You still have {turns} turn(s). Use them all before activating a new key.',
        'need_vip'          => "This is a VIP product!\nFree keys can only be used with Free products.",
        'need_key'          => 'You are out of turns. Please enter a key!',
        'vip_free'          => 'System: VIP keys cannot use Free products ({n}/{limit}). Clicking more than {limit} times will cost you 1 VIP turn.',
        'vip_free_last'     => 'System: VIP keys cannot use Free products ({n}/{limit}). This is the final warning; clicking again will cost you 1 VIP turn.',
        'vip_free_penalty'  => 'System: You clicked a Free product more than {limit} times; 1 VIP turn has been deducted.',
        'tool_ok'           => 'System: 1 turn deducted. You have {turns} turn(s) left.',
        'tool_ok_last'      => "System: 1 turn deducted. You are out of turns.\nPlease enter a key to continue.",
        'tool_reopen'       => 'System: Reopening the product you just used. No extra turn deducted.',
        'key_expired'       => 'System: Your key has expired. Please enter a new key.',
        'tool_missing'      => 'Product not found.',
        'network_error'     => 'Connection lost. Please try again.',
        'too_many_attempts' => "Too many failed attempts.\nPlease try again in {minutes} minutes.",
        'csrf_fail'         => 'Session expired. Please reload the page.',
        'key_daily_limit'   => "Key limit reached.\nOnly 1 key every 8 hours for each account and each IP. Please come back later.",

    ];

    $overrides = isset($GLOBALS['SITE_CONFIG']) && is_array($GLOBALS['SITE_CONFIG']) ? $GLOBALS['SITE_CONFIG'] : [];
    foreach ($catalog as $code => $text) {
        $custom = trim((string) ($overrides['notice_' . $code] ?? ''));
        if ($custom !== '') {
            $catalog[$code] = str_replace(['\\n', "\r\n", "\r"], "\n", $custom);
        }
    }

    return $catalog;
}

/** Kind of each notice: decides the icon and color of the popup. */
function notice_kinds(): array
{
    return [
        'success'           => 'success',
        'key_wrong'         => 'error',
        'not_logged_in'     => 'warning',
        'need_login'        => 'warning',
        'has_turn'          => 'info',
        'need_vip'          => 'warning',
        'need_key'          => 'warning',
        'vip_free'          => 'warning',
        'vip_free_last'     => 'warning',
        'vip_free_penalty'  => 'error',
        'tool_ok'           => 'success',
        'tool_ok_last'      => 'info',
        'tool_reopen'       => 'info',
        'key_expired'       => 'warning',
        'tool_missing'      => 'error',
        'network_error'     => 'error',
        'too_many_attempts' => 'error',
        'csrf_fail'         => 'warning',
        'key_daily_limit'   => 'warning',
    ];
}

function notice_kind(string $key): string
{
    return notice_kinds()[$key] ?? 'info';
}

function resolve_notice(string $key, array $vars = []): ?string
{
    if ($key === 'vip_free' && (int) ($vars['n'] ?? 0) >= VIP_FREE_CLICK_LIMIT) {
        $key = 'vip_free_last';
    }
    $catalog = notice_catalog();
    if (!isset($catalog[$key])) {
        return null;
    }
    $vars += ['n' => 0, 'limit' => VIP_FREE_CLICK_LIMIT, 'turns' => 0, 'plan' => '', 'minutes' => KEY_FAIL_WINDOW_MIN];

    return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($vars) {
        return isset($vars[$m[1]]) ? (string) $vars[$m[1]] : $m[0];
    }, $catalog[$key]);
}

/* ------------------------------------------------------------------
 * DB structure: NEVER alter tables at runtime. Only check which columns/tables exist (see migrate.sql);
 * a missing column makes that feature fall back to a safe mode, and the page keeps working normally.
 * ------------------------------------------------------------------ */

function schema_features(mysqli $conn): array
{
    static $features = null;
    if ($features !== null) {
        return $features;
    }

    $cached = $_SESSION['schema_cache'] ?? null;
    if (is_array($cached) && (int) ($cached['at'] ?? 0) > time() - SCHEMA_CACHE_SECONDS && is_array($cached['f'] ?? null)) {
        return $features = $cached['f'];
    }

    $features = ['turns_left' => false, 'vip_free_clicks' => false, 'key_audit' => false, 'key_attempts' => false, 'key_daily' => false];

    try {
        $result = mysqli_query(
            $conn,
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('users', 'keys_vip', 'keys_free', 'key_attempts', 'key_daily_claims')"
        );
        $cols = [];
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $cols[strtolower($row['t'])][strtolower($row['c'])] = true;
        }

        $features['turns_left']      = !empty($cols['users']['turns_left']);
        $features['vip_free_clicks'] = !empty($cols['users']['vip_free_clicks']);
        $features['key_audit']       = !empty($cols['keys_vip']['used_by'])  && !empty($cols['keys_vip']['used_at'])
                                    && !empty($cols['keys_free']['used_by']) && !empty($cols['keys_free']['used_at']);
        $features['key_attempts']    = !empty($cols['key_attempts']['username'])
                                    && !empty($cols['key_attempts']['ip'])
                                    && !empty($cols['key_attempts']['created_at']);
        $features['key_daily']       = !empty($cols['key_daily_claims']['username'])

                                    && !empty($cols['key_daily_claims']['ip'])

                                    && !empty($cols['key_daily_claims']['claim_date']);


        $_SESSION['schema_cache'] = ['at' => time(), 'f' => $features];
    } catch (Throwable $e) {
        error_log('[index.php] schema check failed: ' . $e->getMessage());
    }

    return $features;
}

function feature(mysqli $conn, string $name): bool
{
    return schema_features($conn)[$name] ?? false;
}

/* ------------------------------------------------------------------
 * Data access
 * ------------------------------------------------------------------ */

function load_site_config(mysqli $conn): array
{
    $config = [];
    try {
        $result = mysqli_query($conn, 'SELECT config_key, config_value FROM site_config');
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $config[$row['config_key']] = $row['config_value'];
        }
    } catch (Throwable $e) {
        error_log('[index.php] site_config: ' . $e->getMessage());
    }
    return $config;
}

function load_products(mysqli $conn): array
{
    $tools = ['vip' => [], 'free' => []];
    try {
        $result = mysqli_query($conn, 'SELECT * FROM products ORDER BY id DESC');
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $group = strtoupper(trim((string) ($row['type'] ?? 'FREE'))) === 'VIP' ? 'vip' : 'free';
            $tools[$group][] = $row;
        }
    } catch (Throwable $e) {
        error_log('[index.php] products: ' . $e->getMessage());
    }
    return array_merge($tools['vip'], $tools['free']);
}

function find_tool(mysqli $conn, int $id): ?array
{
    $stmt = db_execute($conn, 'SELECT * FROM products WHERE id = ? LIMIT 1', 'i', [$id]);
    $tool = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $tool ?: null;
}

/** Expiry time, current time and turn count are all read from MySQL so PHP/MySQL timezones never drift apart. */
function find_user(mysqli $conn, string $username): ?array
{
    $turnsExpr = feature($conn, 'turns_left') ? 'COALESCE(turns_left, 1)' : '1';

    $stmt = db_execute(
        $conn,
        "SELECT role, key_type, alert_msg,
                UNIX_TIMESTAMP(expiry_date) AS expires_at,
                UNIX_TIMESTAMP()            AS server_now,
                IF(expiry_date > NOW(), $turnsExpr, 0) AS turns
           FROM users WHERE username = ? LIMIT 1",
        's',
        [$username]
    );
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** Key state sent to the browser (times in milliseconds). */
function key_state(?array $user): array
{
    return [
        'keyType'   => strtoupper(trim((string) (($user['key_type'] ?? '') ?: 'NONE'))), // always upper-case so VIP/FREE comparisons never miss
        'turns'     => (int) ($user['turns'] ?? 0),
        'expiresAt' => (int) ($user['expires_at'] ?? 0) * 1000,
        'serverNow' => (int) ($user['server_now'] ?? time()) * 1000,
    ];
}

/** How long the product that just cost a turn can still be reopened for free (milliseconds). */
function reopen_state(): array
{
    $paid = $_SESSION['paid_tool'] ?? null;
    if (REOPEN_GRACE_SECONDS > 0 && is_array($paid)) {
        $left = (int) ($paid['at'] ?? 0) + REOPEN_GRACE_SECONDS - time();
        if ($left > 0) {
            return ['reopenId' => (int) ($paid['id'] ?? 0), 'reopenMs' => $left * 1000];
        }
    }
    return ['reopenId' => 0, 'reopenMs' => 0];
}

function reopen_allowed(int $toolId, string $needPlan = '', string $keyType = 'NONE', bool $hasTurn = false): bool
{
    $state = reopen_state();
    if ($state['reopenId'] !== $toolId || $state['reopenMs'] <= 0) {
        return false;
    }
    if ($needPlan === '') {
        return true; // old callers
    }
    /* patch: hard-lock v1 -- a free reopen is only valid for the SAME kind of key that paid for the product:
       a VIP product is only ever reopened by VIP, a Free product only by Free. Paying with one key and then switching key never opens the other kind. */
    $paid     = $_SESSION['paid_tool'] ?? null;
    $paidPlan = is_array($paid) ? strtoupper((string) ($paid['plan'] ?? '')) : '';
    if ($paidPlan !== $needPlan) {
        return false;
    }
    if ($hasTurn && strtoupper($keyType) !== $needPlan) {
        return false; // a different key is active right now
    }
    return true;
}

function remember_paid_tool(int $toolId, string $plan = ''): void
{
    if (REOPEN_GRACE_SECONDS > 0) {
        $_SESSION['paid_tool'] = ['id' => $toolId, 'at' => time(), 'plan' => strtoupper($plan)];
    }
}

function expire_user_key(mysqli $conn, string $username): void
{
    $resetTurns = feature($conn, 'turns_left') ? ', turns_left = NULL' : '';
    $notAdmin   = ADMIN_EXEMPT ? ' AND role != 1' : '';

    db_execute(
        $conn,
        "UPDATE users SET key_type = 'NONE', expiry_date = NULL{$resetTurns}
          WHERE username = ? AND expiry_date <= NOW(){$notAdmin}",
        's',
        [$username]
    )->close();
}

/**
 * Spend 1 turn. Running out of turns also deletes the key. The expiry_date > NOW() condition means opening several tabs
 * at once still spends only one turn.
 * (In an UPDATE, MySQL assigns left to right, so turns_left is set last.)
 */
function spend_turn(mysqli $conn, string $username): bool
{
    if (feature($conn, 'turns_left')) {
        $sql = "UPDATE users
                   SET key_type    = IF(COALESCE(turns_left, 1) <= 1, 'NONE', key_type),
                       expiry_date = IF(COALESCE(turns_left, 1) <= 1, NULL, expiry_date),
                       turns_left  = IF(COALESCE(turns_left, 1) <= 1, NULL, turns_left - 1)
                 WHERE username = ? AND expiry_date > NOW()";
    } else {
        $sql = "UPDATE users SET key_type = 'NONE', expiry_date = NULL
                 WHERE username = ? AND expiry_date > NOW()";
    }

    $stmt  = db_execute($conn, $sql, 's', [$username]);
    $spent = $stmt->affected_rows === 1;
    $stmt->close();
    return $spent;
}

function clear_alert(mysqli $conn, string $username): void
{
    db_execute($conn, 'UPDATE users SET alert_msg = NULL WHERE username = ?', 's', [$username])->close();
}

/* ------------------------------------------------------------------
 * Count clicks on Free products made with a VIP key: stored in the DB (logging out/in again cannot reset it).
 * Without the vip_free_clicks column it falls back to the session like the old version.
 * ------------------------------------------------------------------ */

function bump_vip_free_clicks(mysqli $conn, string $username): int
{
    if (feature($conn, 'vip_free_clicks')) {
        $stmt  = db_execute(
            $conn,
            'UPDATE users SET vip_free_clicks = LAST_INSERT_ID(COALESCE(vip_free_clicks, 0) + 1) WHERE username = ?',
            's',
            [$username]
        );
        $count = (int) $stmt->insert_id;
        $stmt->close();

        if ($count <= 0) { // in case the driver returns no value: read it back from the DB
            $stmt = db_execute($conn, 'SELECT vip_free_clicks FROM users WHERE username = ? LIMIT 1', 's', [$username]);
            $row  = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $count = (int) ($row['vip_free_clicks'] ?? 0);
        }
        if ($count > 0) {
            return $count;
        }
    }

    $count = (int) ($_SESSION['vip_free_clicks'] ?? 0) + 1;
    $_SESSION['vip_free_clicks'] = $count;
    return $count;
}

/** Read the current VIP-on-Free click count WITHOUT adding to it (the page uses it to show the warning instantly). */
function read_vip_free_clicks(mysqli $conn, string $username): int
{
    if (feature($conn, 'vip_free_clicks')) {
        $stmt = db_execute($conn, 'SELECT vip_free_clicks FROM users WHERE username = ? LIMIT 1', 's', [$username]);
        $row  = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return max(0, (int) ($row['vip_free_clicks'] ?? 0));
    }
    return max(0, (int) ($_SESSION['vip_free_clicks'] ?? 0));
}

function reset_vip_free_clicks(mysqli $conn, string $username): void
{
    unset($_SESSION['vip_free_clicks']);
    if (feature($conn, 'vip_free_clicks')) {
        db_execute($conn, 'UPDATE users SET vip_free_clicks = 0 WHERE username = ? AND vip_free_clicks <> 0', 's', [$username])->close();
    }
}

/* ------------------------------------------------------------------
 * Key brute-force protection: count failed attempts per account and per IP within KEY_FAIL_WINDOW_MIN minutes
 * ------------------------------------------------------------------ */

function key_attempts_blocked(mysqli $conn, string $username): bool
{
    if (feature($conn, 'key_attempts')) {
        $stmt = db_execute(
            $conn,
            'SELECT SUM(username = ?) AS by_user, SUM(ip = ?) AS by_ip
               FROM key_attempts WHERE created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            'ssi',
            [$username, client_ip(), KEY_FAIL_WINDOW_MIN]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['by_user'] ?? 0) >= KEY_MAX_FAILS_USER
            || (int) ($row['by_ip'] ?? 0) >= KEY_MAX_FAILS_IP;
    }

    // Fallback mode (no key_attempts table): count per session, only blocks regular users
    $since  = time() - KEY_FAIL_WINDOW_MIN * 60;
    $recent = array_values(array_filter((array) ($_SESSION['key_fails'] ?? []), function ($t) use ($since) {
        return (int) $t > $since;
    }));
    $_SESSION['key_fails'] = $recent;
    return count($recent) >= KEY_MAX_FAILS_USER;
}

function record_key_failure(mysqli $conn, string $username): void
{
    if (feature($conn, 'key_attempts')) {
        db_execute($conn, 'INSERT INTO key_attempts (username, ip, created_at) VALUES (?, ?, NOW())', 'ss', [$username, client_ip()])->close();
        if (random_int(1, 50) === 1) { // occasionally purge old rows
            db_execute($conn, 'DELETE FROM key_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)')->close();
        }
        return;
    }
    $_SESSION['key_fails'][] = time();
}

/**
 * Use a key: everything runs in ONE transaction (needs InnoDB tables, see migrate.sql).
 *   1. Lock the unused key row  2. Mark it as used (who used it, when)
 *   3. Add duration + turns to the account — only if the account has no active key
 * Failure at any step => rollback, the key returns to its original state (no key lost, no turn lost).
 *
 * Result: ok | invalid | has_turn | error
 */
function claim_key(mysqli $conn, string $table, string $code, string $username, string $keyType, bool $guardActive): string
{
    if ($code === '' || !in_array($table, ['keys_vip', 'keys_free'], true)) {
        return 'invalid';
    }

    $dailyStep = false; // patch: key-daily-limit v25
    $conn->begin_transaction();
    try {
        // 1) Lock the unused key row and read the duration of that exact row
        $stmt = db_execute($conn, "SELECT duration_hours FROM `$table` WHERE key_code = ? AND status = 0 LIMIT 1 FOR UPDATE", 's', [$code]);
        $key  = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$key) {
            $conn->rollback();
            return 'invalid';
        }

        // 2) Mark it as used with exactly 1 statement (two people cannot share one key)
        if (feature($conn, 'key_audit')) {
            $stmt = db_execute($conn, "UPDATE `$table` SET status = 1, used_by = ?, used_at = NOW() WHERE key_code = ? AND status = 0 LIMIT 1", 'ss', [$username, $code]);
        } else {
            $stmt = db_execute($conn, "UPDATE `$table` SET status = 1 WHERE key_code = ? AND status = 0 LIMIT 1", 's', [$code]);
        }
        $claimed = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$claimed) {
            $conn->rollback();
            return 'invalid';
        }

        // 3) Add duration + turns to the account
        $hours  = max(1, (int) ($key['duration_hours'] ?: DEFAULT_KEY_HOURS)); // ?: so both NULL and 0 fall back to the default hours
        $turns  = TURNS_PER_KEY[$keyType] ?? 1;
        $sets   = ['key_type = ?', 'expiry_date = DATE_ADD(NOW(), INTERVAL ? HOUR)', 'alert_msg = NULL'];
        $types  = 'si';
        $params = [$keyType, $hours];
        if (feature($conn, 'turns_left')) {
            $sets[]   = 'turns_left = ?';
            $types   .= 'i';
            $params[] = $turns;
        }
        if (feature($conn, 'vip_free_clicks')) {
            $sets[] = 'vip_free_clicks = 0';
        }
        $types   .= 's';
        $params[] = $username;

        // An account with a still-valid key must not be overwritten (blocks entering 2 keys at once in 2 tabs)
        $guard = $guardActive ? ' AND (expiry_date IS NULL OR expiry_date <= NOW())' : '';
        $stmt  = db_execute($conn, 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE username = ?' . $guard, $types, $params);
        $applied = $stmt->affected_rows >= 1 || !$guardActive;
        $stmt->close();
        if (!$applied) {
            $conn->rollback();
            return 'has_turn';
        }

        // patch: key-daily-limit v25 -- record "this account + this IP took a key today". The table has UNIQUE (username, day) and UNIQUE (ip, day),

        // so even two requests at the same moment cannot both pass: the 2nd one fails here and the whole transaction (including the key) is rolled back.

        if ($guardActive && daily_key_limited($keyType) && feature($conn, 'key_daily')) {

            $dailyStep = true;

            db_execute($conn, 'DELETE FROM key_daily_claims WHERE created_at <= DATE_SUB(NOW(), INTERVAL ' . (int) KEY_DAILY_LIMIT_HOURS . ' HOUR) AND (username = ? OR ip = ?)', 'ss', [$username, daily_ip()])->close(); /*DS-PATCH-BOOT4*/ db_execute($conn, 'INSERT INTO key_daily_claims (username, ip, claim_date, created_at) VALUES (?, ?, CURDATE(), NOW())', 'ss', [$username, daily_ip()])->close();

        }

        $conn->commit();
        return 'ok';
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) { /* ignore */ }
        // patch: key-daily-limit v25 -- duplicate on key_daily_claims = already took a key today: the key goes back untouched

        if ($dailyStep && ((int) $e->getCode() === 1062 || stripos($e->getMessage(), 'Duplicate entry') !== false)) {

            return 'daily_limit';

        }

        error_log('[index.php] claim_key: ' . $e->getMessage());
        return 'error';
    }
}

/* patch: key-daily-limit v25 -- 1 key per day for each account AND each IP (table key_daily_claims).

   IPv6: the whole /64 counts as one address (a phone keeps changing the last 64 bits). */

function daily_ip(): string

{

    $ip = client_ip();

    if (strpos($ip, ':') !== false) {

        $bin = @inet_pton($ip);

        if ($bin !== false && strlen($bin) === 16) {

            $ip = (string) @inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8));

        }

    }

    return $ip;

}



function daily_key_limited(string $keyType): bool

{

    return in_array($keyType, KEY_DAILY_LIMIT_TYPES, true);

}



/** Has this account OR this IP already activated a key today? (checked BEFORE the key is looked up, so a valid key is never revealed or burned) */

function daily_key_limit_hit(mysqli $conn, string $username): bool

{

    if (!feature($conn, 'key_daily')) {

        error_log('[index.php] key_daily_claims table is missing: the daily key limit is NOT active');

        return false;

    }

    $stmt = db_execute(

        $conn,

        'SELECT 1 FROM key_daily_claims WHERE created_at > DATE_SUB(NOW(), INTERVAL ' . (int) KEY_DAILY_LIMIT_HOURS . ' HOUR) AND (username = ? OR ip = ?) LIMIT 1' /*DS-PATCH-BOOT4*/,

        'ss',

        [$username, daily_ip()]

    );

    $row = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    return (bool) $row;

}



function nfc(string $text): string
{
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_C);
        if (is_string($normalized)) {
            return $normalized;
        }
    }
    return $text;
}

function revoke_message(?string $raw): string
{
    $raw = nfc(trim((string) $raw));
    if ($raw === '') {
        return '';
    }
    // Vietnamese needles are kept so existing Admin messages written in Vietnamese are still recognized
    foreach (['xoá', 'xóa', 'thu hồi', 'delete', 'remove', 'revoke'] as $needle) {
        $needle = nfc($needle);
        $found  = function_exists('mb_stripos') ? mb_stripos($raw, $needle, 0, 'UTF-8') : stripos($raw, $needle);
        if ($found !== false) {
            return $raw;
        }
    }
    return "Your key has been deleted by an Admin.\nReason: " . $raw;
}

/* ------------------------------------------------------------------
 * Rules when a product is clicked
 * Every turn change lives here; both the AJAX path and the page-redirect path share it.
 *
 *  - Just paid a turn for this very product (within REOPEN_GRACE_SECONDS) -> reopen, do not charge again
 *  - No turns left                  -> need_key  ("Please enter a key")
 *  - Free key clicks a VIP product   -> need_vip
 *  - VIP key clicks a Free product   -> warn (clicks 1..3), 4th click -> penalty (lose 1 VIP turn)
 *  - Otherwise                      -> spend 1 turn, then open
 *
 * Result: open | warn | penalty | need_vip | need_key | missing
 * ------------------------------------------------------------------ */

function apply_tool_use(mysqli $conn, string $username, array $tool, string $keyType, bool $hasTurn, bool $enforceRules): array
{
    $url = safe_url($tool['link'] ?? '');

    if ($enforceRules) {
        $toolId = (int) ($tool['id'] ?? 0);

        $isVipTool = strtoupper(trim((string) ($tool['type'] ?? 'FREE'))) === 'VIP';
        $needPlan  = $isVipTool ? 'VIP' : 'FREE';

        // A turn was already spent on this product a moment ago (popup blocked, tab closed by accident...): reopen without charging again
        // (hard-lock: only when the key that paid is the same kind the product needs, and no other kind of key is active now)
        if ($toolId > 0 && reopen_allowed($toolId, $needPlan, $keyType, $hasTurn)) {
            return ['result' => 'open', 'url' => $url, 'spent' => true, 'reopen' => true];
        }

        if (!$hasTurn) {
            return ['result' => 'need_key'];
        }
        if ($isVipTool && $keyType !== 'VIP') {
            return ['result' => 'need_vip'];
        }

        if (!$isVipTool && $keyType === 'VIP') {
            $clicks = bump_vip_free_clicks($conn, $username);

            if ($clicks > VIP_FREE_CLICK_LIMIT) {
                reset_vip_free_clicks($conn, $username);
                return spend_turn($conn, $username)
                    ? ['result' => 'penalty']
                    : ['result' => 'need_key'];
            }

            return ['result' => 'warn', 'count' => $clicks];
        }

        // hard-lock: from here the key kind MUST equal the product kind (VIP key + VIP product, Free key + Free product), anything else is refused
        if ($keyType !== $needPlan) {
            return ['result' => $isVipTool ? 'need_vip' : 'need_key'];
        }

        // Clicking a valid product (Free or VIP) costs a turn immediately
        if (!spend_turn($conn, $username)) {
            return ['result' => 'need_key'];
        }
        reset_vip_free_clicks($conn, $username);
        remember_paid_tool($toolId, $keyType);
    }

    return ['result' => 'open', 'url' => $url, 'spent' => $enforceRules];
}

/** Fallback path when the browser does not run JavaScript: redirect with ?status=... */
function tool_redirect(array $outcome): string
{
    switch ($outcome['result']) {
        case 'open':       return $outcome['url'];
        case 'warn':       return '?status=vip_free&n=' . (int) $outcome['count'];
        case 'penalty':    return '?status=vip_free_penalty';
        case 'need_login': return '?status=not_logged_in';
        case 'need_vip':   return '?status=need_vip';
        case 'need_key':   return '?status=need_key';
        case 'csrf_fail':  return '?status=csrf_fail';
        default:           return 'index.php';
    }
}

/* Tab icon: the browser/Google requests ?favicon=1 (or ?favicon=apple for iPhone, ?favicon=48 for Google). Does not touch accounts or turns. */
if (isset($_GET['favicon'])) {
    session_write_close(); // release the session lock: building the favicon can take seconds and must not block the user's other requests
    $faviconConfig = load_site_config($conn);
    serve_favicon(
        safe_url(cfg($faviconConfig, 'favicon_url', DEFAULT_FAVICON)),
        is_string($_GET['favicon']) ? $_GET['favicon'] : '',
        cfg($faviconConfig, 'favicon_bg', FAVICON_BG)
    );
}

/* Logo on the dark bar: the browser requests ?brand_logo=1 (already shrunk + long cache). Does not touch accounts or turns. */
if (isset($_GET['brand_logo'])) {
    if ($_GET['brand_logo'] === 'debug') {
        brand_logo_debug($conn, brand_logo_source(load_site_config($conn))); // Admin only; exits by itself
    }
    session_write_close(); // release the session lock: the first request may need several seconds to download the source image and must not block the user's other actions
    serve_brand_logo(brand_logo_source(load_site_config($conn)));
}

/* ------------------------------------------------------------------
 * Session state
 * ------------------------------------------------------------------ */

$username   = (string) ($_SESSION['username'] ?? '');
$isLoggedIn = $username !== '';
$isAdmin    = false;
$keyType    = 'NONE';
$turns      = 0;
$expiresAt  = 0;
$serverNow  = time();
$alertText  = '';
$user       = null;

if ($isLoggedIn) {
    expire_user_key($conn, $username);

    $user = find_user($conn, $username);
    if ($user === null) {
        // Account no longer exists (deleted): log out immediately, no "ghost logins"
        $_SESSION = [];
        session_regenerate_id(true);
        $username   = '';
        $isLoggedIn = false;
    } else {
        $isAdmin   = (int) $user['role'] === 1;
        $keyType   = strtoupper(trim((string) ($user['key_type'] ?: 'NONE'))); // always upper-case so VIP/FREE comparisons never miss
        $turns     = (int) $user['turns'];
        $expiresAt = (int) $user['expires_at'];
        $serverNow = (int) $user['server_now'];
        // The revoke notice is only cleared when the user clicks OK (action ack_alert)
        $alertText = revoke_message($user['alert_msg']);
    }
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['csrf'];

/* Member activity tracking (Telegram): every normal visit of a logged-in member. Logins are NOT tracked. */
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'GET'
    && !isset($_GET['sync']) && !isset($_GET['tg_ping']) && !isset($_GET['use_tool'])) {
    site_tg('page_view', [
        'username' => $username, 'role' => $isAdmin ? 'admin' : 'member', 'plan' => $keyType,
        'turns' => $turns, 'status' => (string) ($_GET['status'] ?? ''),
    ]);
}

$hasTurn      = $turns > 0 && $expiresAt > $serverNow;
$enforceRules = !(ADMIN_EXEMPT && $isAdmin);

// The page re-syncs the turn state when the user returns from another tab
if (isset($_GET['sync'])) {
    if ($isLoggedIn) {
        site_tg('member_action', ['username' => $username, 'role' => $isAdmin ? 'admin' : 'member', 'action' => 'tab_sync',
            'detail' => 'came back to the tab', 'cat' => 'minor', 'throttle' => 120]);
    }
    json_response(key_state($user) + reopen_state());
}

/* ------------------------------------------------------------------
 * Request handlers
 * ------------------------------------------------------------------ */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ack_alert'])) {
    if ($isLoggedIn && csrf_valid($_POST['csrf'] ?? '')) {
        clear_alert($conn, $username);
        site_tg('member_action', ['username' => $username, 'role' => $isAdmin ? 'admin' : 'member', 'action' => 'ack_notice',
            'detail' => 'pressed OK on the admin notice']);
    }
    plain_response('ok');
}

/* "Get Free Key" click: the page sends a tiny beacon (does not change the link or the user's turns) */
if (isset($_GET['tg_ping']) && $_GET['tg_ping'] === 'freekey') {
    if ($isLoggedIn && csrf_valid((string) ($_GET['t'] ?? ''))) {
        site_tg('freekey_click', ['username' => $username]);
    }
    plain_response('ok');
}

if (isset($_GET['use_tool'])) {
    $outcome = ['result' => 'need_login'];
    $tgTool  = null;

    if ($isLoggedIn) {
        $token = $_GET['t'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        if (!request_is_same_site() || !csrf_valid($token)) {
            // Request did not come from our own page: never spend a turn
            $outcome = ['result' => 'csrf_fail'];
        } else {
            $tool    = find_tool($conn, (int) $_GET['use_tool']);
            $tgTool  = $tool;
            $outcome = $tool
                ? apply_tool_use($conn, $username, $tool, $keyType, $hasTurn, $enforceRules)
                : ['result' => 'missing'];
        }
    }

    if ($isLoggedIn) {
        // Telegram alert (turns left are read again only when a turn was really spent)
        $tgOutcome = (string) ($outcome['result'] ?? '');
        $tgData    = [
            'username'  => $username,
            'outcome'   => $tgOutcome,
            'tool'      => $tgTool ? (string) ($tgTool['name'] ?? '?') : '?',
            'tool_type' => $tgTool ? (string) ($tgTool['type'] ?? '') : '',
            'plan'      => $keyType,
        ];
        if ($tgOutcome === 'open') {
            $tgData['spent']  = !empty($outcome['spent']);
            $tgData['reopen'] = !empty($outcome['reopen']);
            if ($tgData['spent'] && function_exists('tg_enabled') && tg_enabled()) {
                try {
                    $fresh = find_user($conn, $username);
                    $tgData['turns'] = $fresh ? (int) $fresh['turns'] : 0;
                } catch (Throwable $e) {
                    $tgData['turns'] = 0;
                }
            }
        } elseif ($tgOutcome === 'warn') {
            $tgData['count'] = (int) ($outcome['count'] ?? 0);
        }
        site_tg('tool_use', $tgData);
    }

    if (isset($_GET['ajax'])) {
        // Always include the latest state from the DB so the UI shows the real turn count
        json_response($outcome + key_state($isLoggedIn ? find_user($conn, $username) : null) + reopen_state()
            + ['vipFreeClicks' => $isLoggedIn ? read_vip_free_clicks($conn, $username) : 0]);
    }
    redirect(tool_redirect($outcome));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isLoggedIn) {
        redirect('?status=not_logged_in');
    }
    if (!csrf_valid($_POST['csrf'] ?? '')) {
        redirect('?status=csrf_fail');
    }
    if ($hasTurn && $enforceRules) {
        redirect('?status=has_turn');
    }
    if (key_attempts_blocked($conn, $username)) {
        site_tg('key_locked', ['username' => $username]);
        redirect('?status=too_many_attempts');
    }


    // patch: key-daily-limit v25 -- 1 key per day for each account and each IP (checked before the key is looked up)

    $dailyKeyType = isset($_POST['submit_vip']) ? 'VIP' : (isset($_POST['submit_free']) ? 'FREE' : '');

    if ($enforceRules && daily_key_limited($dailyKeyType) && daily_key_limit_hit($conn, $username)) {

        redirect('?status=key_daily_limit');

    }


    $result = 'invalid';
    $tgKeyType = isset($_POST['submit_vip']) ? 'VIP' : 'FREE';
    $tgKeyCode = clean_key($_POST[$tgKeyType === 'VIP' ? 'key_vip' : 'key_free'] ?? '');
    if (isset($_POST['submit_vip'])) {
        $result = claim_key($conn, 'keys_vip', clean_key($_POST['key_vip'] ?? ''), $username, 'VIP', $enforceRules);
    } elseif (isset($_POST['submit_free'])) {
        $result = claim_key($conn, 'keys_free', clean_key($_POST['key_free'] ?? ''), $username, 'FREE', $enforceRules);
    }

    switch ($result) {
        case 'ok':
            unset($_SESSION['vip_free_clicks'], $_SESSION['key_fails'], $_SESSION['paid_tool']); // a new key never inherits a free reopen from the old one
            $tgHours = 0;
            try {
                $stmt    = db_execute($conn, 'SELECT duration_hours FROM `' . ($tgKeyType === 'VIP' ? 'keys_vip' : 'keys_free') . '` WHERE key_code = ? LIMIT 1', 's', [$tgKeyCode]);
                $tgRow   = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $tgHours = $tgRow ? (int) $tgRow['duration_hours'] : 0;
            } catch (Throwable $e) {
                $tgHours = 0;
            }
            site_tg('key_ok', ['username' => $username, 'type' => $tgKeyType, 'hours' => $tgHours,
                'turns' => TURNS_PER_KEY[$tgKeyType] ?? 1, 'key' => $tgKeyCode]);
            redirect('?status=success');
        case 'has_turn':
            site_tg('key_has_turn', ['username' => $username]);
            redirect('?status=has_turn');
        case 'daily_limit':
            redirect('?status=key_daily_limit');
        case 'error':
            site_tg('key_error', ['username' => $username, 'key' => $tgKeyCode]);
            redirect('?status=network_error');
        default:
            record_key_failure($conn, $username);
            site_tg('key_invalid', ['username' => $username, 'type' => $tgKeyType, 'key' => $tgKeyCode]);
            redirect('?status=key_wrong');
    }
}

/* ------------------------------------------------------------------
 * View model
 * All text / links / colors of the home page are read from the site_config table (Admin edits them in admin_cp).
 * A key that is missing or empty falls back to the default value written right here.
 * ------------------------------------------------------------------ */

$config      = load_site_config($conn);
$GLOBALS['SITE_CONFIG'] = $config; // so notice_catalog() can read the notices Admin has customized
$logoText    = $config['logo_text']      ?? 'DEVNVIOS.io.vn';
$greeting    = $config['chao_khach']     ?? 'Hi!';
$bannerTitle = $config['banner_title']   ?? 'DEVNVIOS.CO';
$bannerDesc  = $config['banner_desc']    ?? 'Utility tools & automation';
$madeBy      = $config['footer_made_by'] ?? 'DEVNVIOS';
$footerYear  = $config['footer_year']    ?? '@2024';
$telegramUrl = safe_url($config['link_tele_group'] ?? 'https://t.me/crackpythonva');
$avatarUrl   = $config['site_avatar']    ?? '';
$tools       = load_products($conn);

/* Links: link_admin_tele = "Buy VIP key" button, link_bypass = "Get Free Key" button */
$adminTeleUrl = safe_url(cfg($config, 'link_admin_tele', LINK_ADMIN_TELEGRAM));
$freeKeyUrl   = safe_url(cfg($config, 'link_bypass', LINK_FREE_KEY));

/* Page text */
$helloText    = cfg($config, 'xin_chao', 'Hi,');
$textKeyVip   = cfg($config, 'text_key_vip', 'Key VIP');
$textKeyFree  = cfg($config, 'text_key_free', 'Key Free');
$textActivate = cfg($config, 'text_kich_hoat', 'Activate');
$textMore     = cfg($config, 'text_xem_them', 'Show more');
$textGroup    = cfg($config, 'text_telegram_group', 'Join our Telegram group');
$textBuyVip   = cfg($config, 'text_buy_vip', 'Buy a VIP key: contact');
$textBuyLink  = cfg($config, 'text_buy_vip_link', 'Telegram');
$textGetFree  = cfg($config, 'text_get_key_free', 'Link Key Free');
$phKeyVip     = cfg($config, 'placeholder_key_vip', 'Enter VIP key...');
$phKeyFree    = cfg($config, 'placeholder_key_free', 'Enter Free key...');
$textHome     = cfg($config, 'text_menu_home', 'Home');
$textCategory = cfg($config, 'text_menu_category', 'Categories');
$textAdmin    = cfg($config, 'text_menu_admin', 'Admin Cpanel');
$textLogout   = cfg($config, 'text_menu_logout', 'Log Out');
$textLogin    = cfg($config, 'text_login', 'Log In');
$textRegister = cfg($config, 'text_register', 'Sign Up');
$textMember   = cfg($config, 'text_member', 'Member');
$textSecurity = cfg($config, 'text_menu_security', 'Security'); // menu link to security.php (Gmail link + change password)
$textTurnWord = cfg($config, 'text_turn_word', 'turns');
$textMadeWith = cfg($config, 'text_footer_made', 'Made with');
$textBy       = cfg($config, 'text_footer_by', 'by');

/* About text under the key boxes: plain text, left aligned, no frame.
   Edit in site_config: about_site_title / about_site_text, about_team_title / about_team_text, about_help_title / about_help_text
   (type \n for a line break). show_about = 0 hides the whole block. */
$showAbout      = trim((string) ($config['show_about'] ?? '1')) !== '0';
$aboutSiteTitle = cfg($config, 'about_site_title', 'About DEVNVIOS');
$aboutSiteText  = str_replace('\\n', "\n", cfg($config, 'about_site_text', "DEVNVIOS.io.vn is a collection of utility tools and automation built to save you time on repetitive work. Every tool is listed in the menu at the top right of the page, and each one is marked FREE or VIP, so you know what you need before you open it.\n\nTo use a tool you activate a key. Free keys work with Free tools and VIP keys work with VIP tools. A key is valid for a limited time and gives you a limited number of turns, and one turn is used each time you open a tool.\n\nThe tools are tested before they are released and updated when the services they depend on change."));
$aboutTeamTitle = cfg($config, 'about_team_title', 'Dev team');
$aboutTeamText  = str_replace('\\n', "\n", cfg($config, 'about_team_text', "DEVNVIOS is built and maintained by a small team of developers. We write the tools, test every release, fix problems and answer support messages ourselves, so the people who build a tool are the same people who look after it.\n\nNew tools are added to the menu as soon as they are finished, and existing ones are updated regularly."));
$aboutHelpTitle = cfg($config, 'about_help_title', 'Support');
$aboutHelpText  = str_replace('\\n', "\n", cfg($config, 'about_help_text', "Want to buy a VIP key, or something is not working? Contact us on Telegram through the links on this page. Tell us your username and what happened, and we will get back to you as soon as we can."));
$aboutUseTitle  = cfg($config, 'about_use_title', 'How to use');
$aboutUseText   = str_replace('\\n', "\n", cfg($config, 'about_use_text', "1. Sign up or log in with your account.\n2. Get a key. Under the Free Key box press See more and use the free key link. For a VIP key, press See more under the VIP Key box and contact us on Telegram.\n3. Paste the key into the matching box and press Activate. Your turns and your plan (FREE or VIP) are shown at the top of the page.\n4. Open the menu at the top right and tap a tool. One turn is used each time a tool opens. Free keys work with Free tools and VIP keys work with VIP tools.\n5. If a tool does not open, tap the same tool again within about a minute and a half and it will reopen without using another turn.\n\nGood to know: you can enter a new key only after your turns are used up or the key has expired. A VIP key tapping a Free tool gets three warnings, and the next tap costs one VIP turn. After several wrong keys in a row, key entry is locked for 10 minutes."));

/* Main logo on the dark header bar: image only, no text. Edit in site_config: brand_logo.
   - Processed copy already on the server and light enough: INLINED into the HTML (data URI) => the logo is present in the very first frame, nothing more to download.
   - Present but somewhat heavy: served via ?brand_logo=1 (7-day cache) + preload.
   - Not present yet (first visit after an update/logo change): temporarily use the original link as before, while the browser calls ?brand_logo=1 in the background
     so the server prepares the processed copy; from the next visit everyone sees the logo instantly. The v parameter changes when Admin changes the logo link. */
$brandSource = brand_logo_source($config);
$brandUrl    = '?brand_logo=1&v=' . substr(md5($brandSource), 0, 8);
$brandCached = brand_logo_cached($brandSource);
$brandInline = false;
$brandWarm   = false;
if ($brandCached !== null && strlen($brandCached['bytes']) <= BRAND_LOGO_PAGE_INLINE_MAX) {
    $brandLogo   = 'data:' . $brandCached['mime'] . ';base64,' . base64_encode($brandCached['bytes']);
    $brandInline = true;
} elseif ($brandCached !== null) {
    $brandLogo = $brandUrl;
} else {
    $brandLogo = $brandSource;
    $brandWarm = true;
}
$brandOrigin = (!$brandInline && preg_match('#^(https?://[^/]+)#i', $brandLogo, $brandMatch)) ? $brandMatch[1] : '';

/* Warm the logo cache RIGHT AFTER the page has been sent to the visitor (they never wait), without waiting for the browser to call ?brand_logo=1.
   Hosts without fastcgi_finish_request fall back to the old way: the browser calls ?brand_logo=1 in the background (script below).
   If the last download just failed, do not retry continuously (wait BRAND_LOGO_RETRY_SECONDS seconds). */
$brandWarmJs = $brandWarm;
if ($brandWarm && brand_logo_failed_recently($brandSource)) {
    $brandWarm = false;
    $brandWarmJs = false;
}
if ($brandWarm && function_exists('fastcgi_finish_request')) {
    $brandWarmJs = false;
    register_shutdown_function(function () use ($brandSource) {
        try {
            ignore_user_abort(true);
            @set_time_limit(40);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close(); // release the session lock so the user's other actions are not blocked while the image downloads
            }
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @flush();
            fastcgi_finish_request();
            brand_logo_prepare($brandSource);
        } catch (Throwable $e) {
            error_log('[index.php] brand logo warm-up: ' . $e->getMessage());
        }
    });
}

/* Page title (tab title, Google result). Edit in site_config: site_title.
   The tab icon comes from site_config.favicon_url (see DEFAULT_FAVICON at the top of the file) and is auto-converted into a compact square image. */
$isIosmods = (defined('IS_IOSMODS') && IS_IOSMODS) || stripos($_SERVER['HTTP_HOST'] ?? '', 'iosmods') !== false;
$siteTitle = cfg($config, 'site_title', 'Devnvios');
if ($isIosmods) { $siteTitle = 'iosmods'; }

/* SEO: the title and description shown on Google / when the link is shared (Facebook, Telegram, Zalo...).
   Edit in site_config: seo_title, seo_description, site_url (leave empty = use the defaults below).
   Google may take a few days to a few weeks to refresh a result it has already indexed. */
$seoTitle = cfg($config, 'seo_title', 'DEVNVIOS - Devnvios.io.vn Trusted Tool & Automation Platform');
$seoDesc  = cfg($config, 'seo_description', 'DEVNVIOS (devnvios.io.vn) is a trusted platform offering useful tools and automation. Stable, regularly updated, with fast key activation and direct support via Telegram.');
$siteUrl  = cfg($config, 'site_url', 'https://devnvios.io.vn');
if (!preg_match('#^https?://[^/\s]#i', $siteUrl)) {
    $siteUrl = 'https://devnvios.io.vn';
}
$siteUrl = rtrim($siteUrl, '/') . '/';

/* IOSMODS SEO override */
$iosTitle = 'Iosmods';
$canonicalUrl = $siteUrl;
if ($isIosmods) {
    $seoTitle = $iosTitle;
    $seoDesc = 'Iosmods offers iOS apps, modded IPA files, games and useful tools. Stable, regularly updated, with fast key activation and direct support via Telegram.';
    $canonicalUrl = 'https://iosmods.xyz/asadnsrdecsb/home';
}

/* Version number appended to the favicon links: changes when the image/background/favicon style changes so browsers and Google fetch the new image instead of the old small one. */
$faviconVer = FAVICON_VERSION . '-' . substr(md5(cfg($config, 'favicon_url', DEFAULT_FAVICON) . '|' . cfg($config, 'favicon_bg', FAVICON_BG)), 0, 6);

/* Page announcement bar (site_config: show_announcement = 1 to enable, site_announcement = content) */
$announceText = trim((string) ($config['site_announcement'] ?? ''));
$showAnnounce = trim((string) ($config['show_announcement'] ?? '0')) === '1' && $announceText !== '';

/* Avatar: image (site_avatar), frame (border color, shape, size), eagle wings on/off */
$showWings = trim((string) ($config['avatar_wings'] ?? '1')) !== '0';

/* og:image must be a full URL (site_avatar is usually a relative path such as uploads/avatar_site.png) */
$ogImage = $avatarUrl;
if ($avatarUrl !== '' && !preg_match('#^https?://#i', $avatarUrl)) {
    $reqHost  = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $reqHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $ogImage = preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $reqHost)
        ? ($reqHttps ? 'https' : 'http') . '://' . $reqHost . '/' . ltrim($avatarUrl, '/')
        : '';
}

/* Theme: colors and sizes Admin can tune, converted to CSS variables (format-checked so no foreign code can be injected) */
$themeColors = [
    'theme_primary'      => '--blue',          // Activate button, input border on focus
    'theme_primary_dark' => '--blue-600',      // Free Key title, links, FREE label
    'theme_vip_color'    => '--red',           // VIP Key title, Admin button
    'theme_accent'       => '--violet',        // default avatar icon
    'theme_header_top'   => '--ink-top',       // top bar (top color)
    'theme_header_bottom'=> '--ink-bottom',    // top bar (bottom color)
    'theme_bg'           => '--bg',            // page background
    'theme_surface'      => '--surface',       // background of the cards
    'theme_text'         => '--text',
    'theme_muted'        => '--muted',
    'banner_bg'          => '--banner-bg',     // background of the avatar + text frame
    'banner_border'      => '--banner-border', // border of the avatar + text frame
    'banner_title_color' => '--banner-title',  // title text next to the avatar
    'banner_desc_color'  => '--banner-desc',   // description text next to the avatar
    'avatar_frame_c1'    => '--avatar-c1',     // avatar border: start color
    'avatar_frame_c2'    => '--avatar-c2',     // avatar border: end color
    'announce_bg'        => '--announce-bg',
    'announce_text'      => '--announce-text',
];
$themeCss = '';
foreach ($themeColors as $configKey => $cssVar) {
    $color = css_color($config[$configKey] ?? '');
    if ($color !== '') {
        $themeCss .= $cssVar . ':' . $color . ';';
    }
}

$themeSizes = [ // key => [CSS variable, min, max] (px)
    'avatar_size'       => ['--avatar-size', 48, 110],
    'banner_radius'     => ['--banner-radius', 0, 40],
    'banner_title_size' => ['--banner-title-size', 12, 40],
    'banner_desc_size'  => ['--banner-desc-size', 9, 24],
    'brand_logo_height' => ['--brand-logo-height', 20, 48], // logo on the dark bar (header is 50px tall)
];
foreach ($themeSizes as $configKey => $spec) {
    if (isset($config[$configKey]) && is_numeric($config[$configKey])) {
        $themeCss .= $spec[0] . ':' . max($spec[1], min($spec[2], (int) $config[$configKey])) . 'px;';
    }
}

/* Scale of the header logo (a number from 1 to 3, no unit). Defaults to 1.4 in CSS when unset. */
if (isset($config['brand_logo_scale']) && is_numeric($config['brand_logo_scale'])) {
    $themeCss .= '--brand-logo-scale:' . number_format(max(1, min(3, (float) $config['brand_logo_scale'])), 2, '.', '') . ';';
}

$avatarShapes = ['circle' => ['50%', '50%'], 'rounded' => ['22px', '18px'], 'square' => ['0', '0']]; // [outer, inner]
$avatarShape  = strtolower(trim((string) ($config['avatar_shape'] ?? '')));
if (isset($avatarShapes[$avatarShape])) {
    $themeCss .= '--avatar-radius:' . $avatarShapes[$avatarShape][0] . ';--avatar-radius-inner:' . $avatarShapes[$avatarShape][1] . ';';
}

/* Extra hand-written CSS (site_config.custom_css): can tweak everything else. The only guard is against escaping the <style> tag. */
$customCss = substr(str_ireplace('</', '', (string) ($config['custom_css'] ?? '')), 0, 20000);

$planKey   = $hasTurn ? $keyType : 'NONE';
$planLabel = $planKey === 'NONE' ? $textMember : $planKey;

// Notice from ?status=... (shown as soon as the page opens). The Admin revoke notice is handled separately and stays until the user dismisses it.
$initialNotice = null;
$status        = (string) ($_GET['status'] ?? '');
if ($status !== '' && in_array($status, STATUS_KEYS, true)) {
    $initialText = resolve_notice($status, [
        'n'     => max(0, min(VIP_FREE_CLICK_LIMIT, (int) ($_GET['n'] ?? 0))),
        'turns' => $hasTurn ? $turns : 0,
        'plan'  => $hasTurn ? $keyType : '',
    ]);
    if ($initialText !== null) {
        $initialNotice = ['text' => $initialText, 'kind' => notice_kind($status)];
    }
}

$clientConfig = [
    'loggedIn'      => $isLoggedIn,
    'admin'         => $isAdmin,
    'adminExempt'   => ADMIN_EXEMPT,
    'keyType'       => $keyType,
    'turns'         => $turns,
    'expiresAt'     => $expiresAt * 1000,
    'serverNow'     => $serverNow * 1000,
    'freeLimit'     => VIP_FREE_CLICK_LIMIT,
    'lockMinutes'   => KEY_FAIL_WINDOW_MIN,
    'csrf'          => $csrf,
    'notices'       => notice_catalog(),
    'kinds'         => notice_kinds(),
    'notice'        => $initialNotice,
    'revokeMessage' => $alertText,
    'turnWord'      => $textTurnWord,
    'memberLabel'   => $textMember,
    'vipFreeClicks' => ($isLoggedIn && $keyType === 'VIP') ? read_vip_free_clicks($conn, $username) : 0,
] + reopen_state();

/** The feather layers of one wing: each element is [tilt angle (degrees), length]. */
function eagle_feathers(array $feathers): string
{
    $out = '';
    foreach ($feathers as [$angle, $length]) {
        $out .= '<use href="#eg-feather" transform="rotate('
            . number_format(180 + $angle, 1, '.', '') . ') scale('
            . number_format($length, 1, '.', '') . ')"/>';
    }
    return $out;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= $isIosmods ? h($iosTitle) : 'devnvios' ?></title>
    <?php if (!$brandInline): ?>
    <!-- Header logo cannot be inlined into the page yet: load it as early as possible (before CSS/fonts/JS) -->
    <?php if ($brandOrigin !== ''): ?><link rel="preconnect" href="<?= h($brandOrigin) ?>"><?php endif; ?>
    <link rel="preload" as="image" href="<?= h($brandLogo) ?>" fetchpriority="high">
    <?php endif; ?>
    <link rel="icon" type="image/png" sizes="192x192" href="/favicon-192.png?v=<?= h($faviconVer) ?>">
    <link rel="icon" type="image/png" sizes="48x48" href="/favicon-48.png?v=<?= h($faviconVer) ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png?v=<?= h($faviconVer) ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="/favicon-192.png?v=<?= h($faviconVer) ?>">
    <meta name="application-name" content="<?= h($siteTitle) ?>">
    <meta name="description" content="<?= h($seoDesc) ?>">
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">
    <link rel="canonical" href="<?= h($canonicalUrl) ?>">
    <meta property="og:site_name" content="<?= h($siteTitle) ?>">
    <meta property="og:title" content="<?= $isIosmods ? h($iosTitle) : 'devnvios' ?>">
    <meta property="og:description" content="<?= h($seoDesc) ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= h($canonicalUrl) ?>">
    <meta property="og:locale" content="en_US">
    <?php if ($ogImage !== ''): ?><meta property="og:image" content="<?= h($ogImage) ?>"><?php endif; ?>
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="devnvios">
    <meta name="twitter:description" content="<?= h($seoDesc) ?>">
    <!-- Structured data: helps Google understand the site name and what the site is about -->
    <script type="application/ld+json"><?= json_encode([
        '@context'    => 'https://schema.org',
        '@type'       => 'WebSite',
        'name'        => ($isIosmods ? 'Iosmods' : cfg($config, 'seo_site_name', 'DEVNVIOS')),
        'alternateName' => ['DEVNVIOS', 'Devnvios', 'Devnvios.io.vn', 'devnvios io vn'],
        'url'         => $siteUrl,
        'description' => $seoDesc,
        'inLanguage'  => 'en',
    ], JSON_SAFE | JSON_UNESCAPED_SLASHES) ?></script>
    <!-- Structured data: the brand/organization behind the site (name DEVNVIOS, logo, Telegram profile) -->

    <script type="application/ld+json"><?= json_encode([

        '@context'      => 'https://schema.org',

        '@type'         => 'Organization',

        'name'          => ($isIosmods ? 'Iosmods' : cfg($config, 'seo_site_name', 'DEVNVIOS')),

        'alternateName' => ['Devnvios.io.vn', 'devnvios io vn'],

        'url'           => $siteUrl,

        'logo'          => $siteUrl . '?favicon=1&v=' . $faviconVer,

        'sameAs'        => preg_match('#^https?://#i', $telegramUrl) ? [$telegramUrl] : [],

    ], JSON_SAFE | JSON_UNESCAPED_SLASHES) ?></script>

    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <!-- Font Awesome loads in the background: it does not block rendering the page and the dialogs -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"></noscript>
    <style>
        /* ==========================================================================
           Home page
           ========================================================================== */

        :root {
            --bg: #f4f6f9;
            --surface: #fff;
            --line: #e8eaee;
            --text: #1f2937;
            --muted: #6b7280;

            --ink-top: #23262f;      /* header: medium black, not washed out */
            --ink-bottom: #14161c;

            --blue: #007bff;
            --blue-600: #2563eb;
            --sky: #0ea5e9;
            --red: #dc2626;
            --violet: #7c3aed;

            --radius-card: 14px;
            --radius-control: 8px;
            --safe-bottom: env(safe-area-inset-bottom, 0px);
        }

        /* Reset ------------------------------------------------------------------ */

        *, *::before, *::after { box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;   /* fallback for older browsers that do not understand dvh */
            min-height: 100dvh;
            margin: 0;
            overflow-x: hidden;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: var(--text);
            background: var(--bg);
            -webkit-tap-highlight-color: transparent;
        }

        h1, h2, h3, h4, p { margin: 0; }
        a { color: inherit; }
        button { padding: 0; font: inherit; color: inherit; background: none; border: 0; cursor: pointer; }
        img { display: block; max-width: 100%; }
        [hidden] { display: none !important; }

        /* Header ----------------------------------------------------------------- */

        .site-header {
            position: relative;
            z-index: 1001;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 50px;
            padding: 0 15px;
            color: #fff;
            background: linear-gradient(180deg, var(--ink-top) 0%, var(--ink-bottom) 100%);
            box-shadow: 0 1px 0 rgba(139, 92, 246, .4), 0 3px 14px rgba(0, 0, 0, .45);
        }

        .brand {
            font-size: 19px;
            font-weight: 900;
            line-height: 1;
            letter-spacing: -.5px;
            color: #fff;
            text-decoration: none;
            transform: skewX(-6deg);
        }

        /* Main logo in the header: image only, no text. The header stays 50px tall.
           The logo image has a lot of transparent padding, so it is enlarged with scale (the transparent padding spills out invisibly).
           Tune it with site_config.brand_logo_scale (1 - 3, default 1.4). */
        .brand--logo {
            display: inline-flex;
            align-items: center;
            min-width: 0;
            line-height: 0;
            transform: none;
        }
        .brand__mark {
            position: relative;
            display: block;
            height: var(--brand-logo-height, 42px);
            margin-left: -6px;
            transform: scale(var(--brand-logo-scale, 1.4));
            transform-origin: 0 50%;
            pointer-events: none; /* the spilled transparent border must not block clicks on the menu button */
        }
        .brand__logo { display: block; width: auto; height: 100%; max-width: 260px; object-fit: contain; }

        /* Brand-style shine effect: two brighter copies of the logo itself, revealed only inside a diagonal band
           that sweeps continuously from the letter D to the last letter and then repeats. The wide band gives soft light, the narrow one is the core of the shine. */
        .brand__shine {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: contain;
            pointer-events: none;
            -webkit-clip-path: polygon(-45% 0, -45% 0, -45% 100%, -45% 100%);
            clip-path: polygon(-45% 0, -45% 0, -45% 100%, -45% 100%);
        }
        .brand__shine--wide {
            filter: brightness(1.3) saturate(1.1);
            animation: logo-sheen-wide 0.9s linear infinite;
        }
        .brand__shine--core {
            filter: brightness(1.8) saturate(.7);
            animation: logo-sheen-core 0.9s linear infinite;
        }
        @keyframes logo-sheen-wide {
            0%   { -webkit-clip-path: polygon(-33% 0, -5% 0, -17% 100%, -45% 100%);  clip-path: polygon(-33% 0, -5% 0, -17% 100%, -45% 100%); }
            100% { -webkit-clip-path: polygon(117% 0, 145% 0, 133% 100%, 105% 100%); clip-path: polygon(117% 0, 145% 0, 133% 100%, 105% 100%); }
        }
        @keyframes logo-sheen-core {
            0%   { -webkit-clip-path: polygon(-26% 0, -16% 0, -24% 100%, -34% 100%);  clip-path: polygon(-26% 0, -16% 0, -24% 100%, -34% 100%); }
            100% { -webkit-clip-path: polygon(124% 0, 134% 0, 126% 100%, 116% 100%); clip-path: polygon(124% 0, 134% 0, 126% 100%, 116% 100%); }
        }

        /* Hamburger button: on open the 3 bars collapse, a curved X is drawn stroke by stroke and the whole button turns half a revolution; on close it returns to 3 bars.
           No frame or background around the button. */
        .menu-toggle {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            margin-right: -6px;
            color: #fff;
        }
        .burger {
            width: 26px;
            height: 26px;
            overflow: visible;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
            transition: transform .6s cubic-bezier(.68, -.3, .27, 1.3);
        }
        .burger__bar {
            stroke: #fff;
            stroke-width: 2.4;
            transform-box: fill-box;
            transform-origin: center;
            transition: transform .35s cubic-bezier(.4, 0, .2, 1) .12s, opacity .25s ease .12s;
        }
        .burger__x {
            stroke-width: 2.4;
            stroke-dasharray: 1;
            stroke-dashoffset: 1;
            transition: stroke-dashoffset .3s cubic-bezier(.4, 0, .2, 1);
        }
        .burger__x--b { transition-delay: 0s; }

        .menu-toggle[aria-expanded="true"] .burger { transform: rotate(180deg); }
        .menu-toggle[aria-expanded="true"] .burger__bar { opacity: 0; transition-delay: 0s; }
        .menu-toggle[aria-expanded="true"] .burger__bar--top { transform: translateY(5.5px) scaleX(.25); }
        .menu-toggle[aria-expanded="true"] .burger__bar--mid { transform: scaleX(0); }
        .menu-toggle[aria-expanded="true"] .burger__bar--bot { transform: translateY(-5.5px) scaleX(.25); }
        .menu-toggle[aria-expanded="true"] .burger__x { stroke-dashoffset: 0; transition: stroke-dashoffset .45s cubic-bezier(.4, 0, .2, 1) .18s; }
        .menu-toggle[aria-expanded="true"] .burger__x--b { transition-delay: .3s; }

        @media (prefers-reduced-motion: reduce) {
            .burger, .burger__bar, .burger__x { transition-duration: .01s !important; transition-delay: 0s !important; }
        }

        /* Dropdown menu ---------------------------------------------------------- */

        .menu {
            position: absolute;
            top: 50px;
            right: 10px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            width: 230px;
            max-height: 0;
            overflow: hidden;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-20px);
            background: var(--surface);
            border: 1px solid #eee;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, .2);
            transition: max-height .4s cubic-bezier(.4, 0, .2, 1), opacity .3s, transform .4s cubic-bezier(.4, 0, .2, 1), visibility .4s;
        }

        .menu.is-open {
            max-height: 80vh;
            padding-bottom: 10px;
            opacity: 1;
            visibility: visible;
            transform: none;
        }

        .menu__item {
            display: flex;
            align-items: center;
            flex-shrink: 0;
            gap: 10px;
            width: 100%;
            padding: 10px 15px;
            font-size: 10px;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            text-decoration: none;
            color: #666;
            background: var(--surface);
            border-bottom: 1px solid #f5f5f5;
        }

        .menu__home { color: #3b82f6; }
        .menu__heading {
            flex-shrink: 0;
            padding: 8px 16px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: -.02em;
            text-transform: uppercase;
            color: #9ca3af;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
        }

        .menu__tools { flex: 1 1 auto; max-height: 250px; overflow-y: auto; overscroll-behavior: contain; }
        .menu__tool { border-bottom-color: #f1f1f1; }
        .menu__tool.is-busy { opacity: .5; pointer-events: none; }
        .menu__tool img { width: 24px; height: 24px; border-radius: 5px; object-fit: cover; }
        .menu__tool span { font-size: 11px; color: #333; }
        .menu__item--admin { margin-top: 4px; color: var(--red); border-top: 1px solid #e5e7eb; }
        .menu__item--security { border-top: 1px solid #e5e7eb; }
        .menu__item--logout { color: #b91c1c; border-top: 1px solid #e5e7eb; }

        .badge { margin-left: 4px; font-size: 9px; font-weight: 700; }
        .badge--vip { color: #ef4444; }
        .badge--free { color: #9ca3af; }

        .menu-overlay {
            position: fixed;
            inset: 50px 0 0 0;   /* starts below the dark bar (50px tall): does not cover the top edge, so Safari does not clip the dark background when the menu opens */
            z-index: 999;
            visibility: hidden;
            opacity: 0;
            background: rgba(0, 0, 0, .05);
            transition: opacity .3s, visibility .3s;
        }
        .menu-overlay.is-open { visibility: visible; opacity: 1; }

        /* User bar --------------------------------------------------------------- */

        .user-bar {
            position: relative;
            z-index: 998;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: var(--surface);
            border-bottom: 1px solid var(--line);
            box-shadow: 0 1px 2px rgba(0, 0, 0, .05);
        }

        .user-bar__info { font-size: 9px; font-weight: 700; font-style: italic; text-transform: uppercase; color: var(--muted); }
        .user-bar__name { color: var(--blue-600); }
        .user-bar__timer { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: #ef4444; }

        /* | FREE or | VIP right after the turns word */
        .user-bar__plan { color: #9ca3af; }
        .user-bar__plan.is-free { color: var(--blue-600); }
        .user-bar__plan.is-vip  { color: #ef4444; }

        .auth { display: flex; gap: 16px; }
        .auth__link { font-size: 10px; font-weight: 800; text-transform: uppercase; text-decoration: none; }
        .auth__link--login { color: #555; }
        .auth__link--register { color: var(--blue); }

        /* Page announcement bar (site_config: show_announcement / site_announcement) */
        .announce {
            position: relative;
            z-index: 997;
            padding: 8px 16px;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.4;
            text-align: center;
            white-space: pre-line;
            overflow-wrap: anywhere;
            color: var(--announce-text, #92400e);
            background: var(--announce-bg, #fffbeb);
            border-bottom: 1px solid #fde68a;
        }

        /* Page ------------------------------------------------------------------- */

        .page { flex: 1 0 auto; width: 100%; max-width: 36rem; margin: 0 auto; padding: 16px; }

        /* Banner + eagle --------------------------------------------------------- */

        .banner {
            position: relative;
            z-index: 10;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
            padding: 14px 14px 14px 10px;
            background: var(--banner-bg, var(--surface));
            border: 1px solid var(--banner-border, #e9e5f5);
            border-radius: var(--banner-radius, 22px);
            box-shadow: 0 4px 18px rgba(124, 58, 237, .08);
        }

        /* Long text wraps onto new lines, never hidden or overflowing the frame */
        .banner__text { flex: 1 1 0; min-width: 0; }

        .banner__title {
            padding-right: 4px;
            font-size: var(--banner-title-size, 20px);
            font-weight: 900;
            line-height: 1.2;
            letter-spacing: -.5px;
            text-transform: uppercase;
            color: var(--banner-title, #3498db);
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .banner__desc {
            display: block;
            margin-top: 4px;
            padding-right: 4px;
            font-size: var(--banner-desc-size, 11px);
            line-height: 1.35;
            color: var(--banner-desc, var(--muted));
            white-space: normal;
            overflow-wrap: anywhere;
            transform: skewX(-5deg);
            transform-origin: left center;
        }

        .eagle {
            display: flex;
            align-items: center;
            flex-shrink: 0;
            animation: pop-in .6s ease-out both;
        }

        /* Short, full wings: the viewBox is cropped tight to the wing (left 16 6 40 48, right 0 6 40 48), wing root right against the avatar.
           Wing size follows the avatar size (72px => 36x43). */
        .eagle__wing {
            position: relative;
            z-index: 1;
            flex-shrink: 0;
            width: calc(var(--avatar-size, 72px) * .5);
            height: calc(var(--avatar-size, 72px) * .6);
            overflow: visible;
            filter: drop-shadow(0 1px 3px rgba(0, 0, 0, .18));
            will-change: transform;
            animation: flap-left 3.2s ease-in-out infinite;
        }

        .eagle__wing--left  { margin-right: -6px; transform-origin: 95% 50%; }
        .eagle__wing--right { margin-left: -6px;  transform-origin: 5% 50%; animation-name: flap-right; }

        /* On a notice: wings flap hard and fast. No glow effect around the avatar anymore */
        .eagle.is-alert .eagle__wing { animation-duration: .46s; }
        .eagle.is-alert .eagle__wing--left  { animation-name: flap-left-strong; }
        .eagle.is-alert .eagle__wing--right { animation-name: flap-right-strong; }
        /* (removed .eagle.is-glow .avatar: the avatar no longer glows on page reload or on warnings) */

        .avatar {
            position: relative;
            z-index: 2;
            flex-shrink: 0;
            width: var(--avatar-size, 72px);
            height: var(--avatar-size, 72px);
            padding: 3px;
            border-radius: var(--avatar-radius, 50%);
            background: linear-gradient(135deg, var(--avatar-c1, #8b5cf6), var(--avatar-c2, #38bdf8));
            box-shadow: 0 2px 8px rgba(0, 0, 0, .14);
        }

        .avatar__inner { width: 100%; height: 100%; padding: 3px; overflow: hidden; background: #fff; border-radius: var(--avatar-radius-inner, 50%); }
        .avatar__inner img { width: 100%; height: 100%; object-fit: cover; border-radius: inherit; }
        .avatar__fallback {
            display: flex; align-items: center; justify-content: center;
            width: 100%; height: 100%; font-size: 24px; color: var(--violet);
            background: linear-gradient(135deg, #ede9fe, #ddd6fe); border-radius: inherit;
        }

        @keyframes flap-left         { 0%, 100% { transform: rotate(-4deg); } 50% { transform: rotate(9deg); } }
        @keyframes flap-right        { 0%, 100% { transform: rotate(4deg); }  50% { transform: rotate(-9deg); } }
        @keyframes flap-left-strong  { 0%, 100% { transform: rotate(-6deg); } 50% { transform: rotate(20deg) scaleY(.92); } }
        @keyframes flap-right-strong { 0%, 100% { transform: rotate(6deg); }  50% { transform: rotate(-20deg) scaleY(.92); } }

        @keyframes avatar-glow {
            0%, 100% { box-shadow: 0 4px 14px rgba(124, 58, 237, .3); }
            50%      { box-shadow: 0 4px 22px rgba(56, 189, 248, .7); }
        }

        @keyframes pop-in {
            0%   { opacity: 0; transform: scale(.6); }
            80%  { opacity: 1; transform: scale(1.04); }
            100% { opacity: 1; transform: scale(1); }
        }

        @media (max-width: 359px) {
            .eagle__wing { width: 30px; height: 36px; }
            .eagle__wing--left  { margin-right: -5px; }
            .eagle__wing--right { margin-left: -5px; }
            .avatar { width: 60px; height: 60px; }
            .banner__title { font-size: 17px; }
        }

        /* Reduced motion: only drop the entrance effect; the wings still flap gently so the avatar does not look frozen */
        @media (prefers-reduced-motion: reduce) {
            .eagle { animation: none !important; }
        }

        /* Telegram link ----------------------------------------------------------
           The underline is set on the <span> itself (inline-block) because the parent's underline
           does not "flow" into inline-block children, which is why it used to not show up. */

        .group-link {
            display: block;
            padding: 8px 0 12px;
            font-size: 12px;
            font-weight: 800;
            text-align: center;
            text-transform: uppercase;
            color: #ef4444;
            text-decoration: none;
        }
        .group-link span {
            display: inline-block;
            transform: skewX(-5deg);
            text-decoration-line: underline;
            text-decoration-style: solid;
            text-decoration-color: #ef4444;
            text-decoration-thickness: 2px;
            text-underline-offset: 4px;
        }

        /* Cards & forms ---------------------------------------------------------- */

        .card {
            margin-bottom: 16px;
            padding: 15px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-card);
            box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
        }

        .card__title {
            display: inline-block;
            margin-bottom: 12px;
            padding-bottom: 4px;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: -.5px;
            text-transform: uppercase;
            border-bottom: 2px solid currentColor;
        }
        .card__title--vip { color: var(--red); }
        .card__title--free { color: var(--blue-600); }

        .card__actions { display: flex; align-items: center; justify-content: space-between; margin-top: 12px; }

        .field {
            display: block;
            width: 100%;
            height: 40px;
            margin-top: 5px;
            padding: 8px 12px;
            font: inherit;
            font-size: 16px;   /* >= 16px so iOS does not auto-zoom on focus */
            outline: none;
            border: 1px solid #ddd;
            border-radius: var(--radius-control);
            transition: border-color .2s;
        }
        .field:focus { border-color: var(--blue); }
        .field--free:focus { border-color: var(--sky); }

        .link-btn { font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--blue-600); }

        .btn {
            height: 36px;
            padding: 0 15px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #fff;
            background: var(--blue);
            border-radius: var(--radius-control);
        }
        .btn:active { transform: scale(.97); }

        .note {
            margin-top: 16px;
            padding: 12px;
            font-size: 12px;
            font-style: italic;
            color: #374151;
            background: #eff6ff;
            border: 1px solid #dbeafe;
            border-radius: var(--radius-control);
        }
        .note a { font-weight: 700; color: #0088cc; }

        /* "Get Free Key" sits centered in the box, still underlined text so it is clearly a link */
        .free-help { margin-top: 14px; padding-top: 12px; text-align: center; border-top: 1px dashed var(--line); }
        .free-link {
            display: inline-block;
            padding: 4px 6px;
            font-size: 13px;
            font-weight: 600;
            color: var(--blue-600);
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        /* Footer ----------------------------------------------------------------- */

        .site-footer {
            margin-top: -20px;   /* pull the footer up over the excess empty space above it */
            padding: 8px 0 calc(14px + var(--safe-bottom));   /* compact footer box; the text still sits high thanks to the negative margin-top above */
            font-size: 11px;
            font-weight: 700;
            text-align: center;
            text-transform: uppercase;
            color: #4b5563;
            background: var(--surface);
            border-top: 1px solid var(--line);
        }
        .site-footer a { color: var(--blue-600); text-decoration: none; }
        .site-footer__heart { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; color: #ff3b30; animation: beat .6s infinite; }
        .site-footer__year { display: block; margin-top: 3px; font-weight: 400; color: #9ca3af; }

        @keyframes beat { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.2); } }

        /* Notices (toast) under the Free Key box -----------------------------------------
           No background, no frame, no shadow: just an animated icon and text, sliding in right under the Free Key box
           (the empty space of the page), right-aligned, and above the hamburger menu if it is open. Text and icon have a thin white outline (no blur) to stay readable
           on any background. Regular notices let touches pass through (products below stay clickable) and auto-dismiss after a few seconds;
           only the key-revoke notice stays and is dismissed by tapping/swiping right.
             success -> self-drawing check mark      warning -> shaking red bell
             info    -> bouncing ! mark              error / revoke -> self-drawing X / ban circle, light shake */

        /* The anchor sits right after the Free Key box and reserves a free slot (min-height) so notices appear in real empty space instead of overlapping the footer;
           notices open inside it and move up/down with the Free Key box when "Show more" is pressed. */
        .toast-anchor { position: relative; min-height: 64px; margin-bottom: 4px; }

        .toasts {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
            width: 100%;
            pointer-events: none;
        }

        .toast {
            display: flex;
            align-items: center;
            gap: 8px;
            max-width: 100%;
            padding: 2px 4px;
            color: #111827;
            pointer-events: none; /* touches pass through, never blocking clicks on the menu below */
            user-select: none;
            -webkit-user-select: none;
            /* thin 1px white outline around the text for readability, not blurry so no glow is needed */
            text-shadow: -1px -1px 0 #fff, 1px -1px 0 #fff, -1px 1px 0 #fff, 1px 1px 0 #fff,
                         0 -1px 0 #fff, 0 1px 0 #fff, -1px 0 0 #fff, 1px 0 0 #fff;
            transition: transform .2s ease, opacity .2s ease;
            animation: toast-in .32s cubic-bezier(.2, .9, .3, 1) backwards; /* backwards: so manual dragging can still override transform */
        }
        /* The key-revoke notice is the only one that can be clicked */
        .toast--revoke { pointer-events: auto; cursor: pointer; touch-action: pan-y; }
        .toast.is-dragging { transition: none; }
        .toast.is-leaving  { pointer-events: none; animation: toast-out .2s ease-in forwards; }
        .toast.is-bump     { animation: toast-bump .25s ease-out; }

        .toast__icon {
            flex-shrink: 0;
            width: 26px;
            height: 26px;
            overflow: visible;
            fill: none;
            stroke: currentColor;
            stroke-width: 2.6;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        /* 1px white outline around the icon (4 directions, no blur) */
        .toast__icon {
            filter: drop-shadow(1px 0 0 #fff) drop-shadow(-1px 0 0 #fff) drop-shadow(0 1px 0 #fff) drop-shadow(0 -1px 0 #fff);
        }
        .toast--success .toast__icon { color: #16a34a; }
        .toast--error   .toast__icon,
        .toast--revoke  .toast__icon { color: #dc2626; }
        .toast--warning .toast__icon { color: #ef4444; }
        .toast--info    .toast__icon { color: #f59e0b; stroke-width: 3.2; }

        .toast--error  .toast__icon,
        .toast--revoke .toast__icon { animation: icon-shake .5s .25s both; }

        /* Self-drawing strokes (check mark, X, ban circle) */
        .toast__icon .draw {
            stroke-dasharray: 1;
            stroke-dashoffset: 1;
            opacity: 0;
            animation: draw .4s .1s cubic-bezier(.4, 0, .2, 1) forwards;
        }

        /* Red bell: swings back and forth then rests for a beat, two vibration marks blink along with it */
        .toast__icon .bell {
            transform-box: view-box;
            transform-origin: 12px 4.5px;
            animation: bell-ring 1.5s ease-in-out .1s infinite;
        }
        .toast__icon .wave { opacity: 0; animation: wave-blink 1.5s ease-out .1s infinite; }

        /* Bouncing ! mark: the stem and the dot bounce slightly out of sync */
        .toast__icon .bang-stem { animation: bang-hop 1.5s ease-in-out infinite; }
        .toast__icon .bang-dot  { animation: bang-hop 1.5s ease-in-out .09s infinite; }

        .toast__body { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
        .toast__title { font-size: 14px; font-weight: 700; line-height: 1.35; overflow-wrap: anywhere; }
        .toast__text  { font-size: 12.5px; font-weight: 500; line-height: 1.4; color: #4b5563; white-space: pre-line; overflow-wrap: anywhere; }

        /* A staying notice (key revoked) has a small X so it is clear that tapping dismisses it */
        .toast__x { flex-shrink: 0; width: 16px; height: 16px; margin-left: 2px; fill: none; stroke: #6b7280; stroke-width: 2.4; stroke-linecap: round; }

        @keyframes toast-in   { from { opacity: 0; transform: translateX(28px) scale(.96); } to { opacity: 1; transform: none; } }
        @keyframes toast-out  { from { opacity: 1; transform: none; } to { opacity: 0; transform: translateX(36px); } }
        @keyframes toast-bump { 50% { transform: scale(1.04); } }

        @keyframes draw       { 0% { stroke-dashoffset: 1; opacity: 0; } 1% { opacity: 1; } 100% { stroke-dashoffset: 0; opacity: 1; } }
        @keyframes icon-shake { 0%, 100% { transform: translateX(0); } 20% { transform: translateX(-4px); } 40% { transform: translateX(4px); } 60% { transform: translateX(-2px); } 80% { transform: translateX(2px); } }

        @keyframes bell-ring {
            0%   { transform: rotate(0); }
            8%   { transform: rotate(17deg); }
            16%  { transform: rotate(-15deg); }
            24%  { transform: rotate(12deg); }
            32%  { transform: rotate(-9deg); }
            40%  { transform: rotate(6deg); }
            48%  { transform: rotate(-3deg); }
            56%, 100% { transform: rotate(0); }
        }
        @keyframes wave-blink { 0% { opacity: 0; } 8%, 48% { opacity: 1; } 60%, 100% { opacity: 0; } }
        @keyframes bang-hop {
            0%, 60%, 100% { transform: translateY(0); }
            15% { transform: translateY(-3.4px); }
            30% { transform: translateY(0); }
            42% { transform: translateY(-1.6px); }
            52% { transform: translateY(0); }
        }

        /* User turned motion off: keep the final frame, run no animation */
        @media (prefers-reduced-motion: reduce) {
            .toast, .toast.is-bump, .toast__icon, .toast__icon * { animation: none !important; transition: none; }
            .toast.is-leaving { opacity: 0; }
            .toast__icon .draw { stroke-dashoffset: 0; opacity: 1; }
            .toast__icon .wave { opacity: 1; }
        }
        /* ==== Notice stability fix: NO shake / zoom / bounce when a notice appears ====
           Overrides only: the original effects above are kept in the file but switched off here.
           Notices now just fade in/out in place. To get the old effects back, remove this block and set NOTICE_MOTION = true in the JavaScript. */
        @keyframes toast-fade-in  { from { opacity: 0; } to { opacity: 1; } }
        @keyframes toast-fade-out { from { opacity: 1; } to { opacity: 0; } }

        html { touch-action: manipulation; } /* no double-tap zoom when tapping buttons / products quickly (pinch-zoom still works) */

        .toast { animation: toast-fade-in .18s ease-out backwards; }       /* was: slide + scale in */
        .toast.is-leaving { animation: toast-fade-out .2s ease-in forwards; } /* was: slide out */
        .toast.is-bump { animation: none; }                                 /* was: scale 1.04 pulse when the same notice repeats */
        .toast--error .toast__icon,
        .toast--revoke .toast__icon { animation: none; }                    /* was: icon shake */
        .toast__icon .bell { animation: none; }                             /* was: swinging bell */
        .toast__icon .wave { animation: none; opacity: 1; }
        .toast__icon .bang-stem,
        .toast__icon .bang-dot { animation: none; }                         /* was: bouncing ! */
        .eagle { animation: none; }                                         /* was: pop-in zoom every time the page reloads */
        .eagle.is-alert .eagle__wing { animation-duration: 3.2s; }          /* was: hard fast flap */
        .eagle.is-alert .eagle__wing--left  { animation-name: flap-left; }
        .eagle.is-alert .eagle__wing--right { animation-name: flap-right; }

        /* About text under the key boxes: plain text, left aligned, no frame, no background */
        .site-about { margin: 8px 0 24px; padding: 0 2px; text-align: left; }
        .site-about__title { margin: 24px 0 8px; font-size: 19px; font-weight: 800; line-height: 1.25; letter-spacing: -.2px; color: #111; }
        .site-about__title:first-child { margin-top: 0; }
        .site-about__text { font-size: 13.5px; line-height: 1.7; color: #374151; white-space: pre-line; overflow-wrap: anywhere; }
        /* Everything above the About text fills the first screen, so the About text only shows after scrolling down */
        .page-top.has-about { min-height: 0; } /* switched off: the changing height of the mobile toolbar made the page jump while scrolling */

        /* How to use: keep the numbered steps readable */
        .site-about__text--steps { line-height: 1.8; }

        /* About: thin white frame around the whole About text, same look as the key boxes */
        .site-about { margin: 0 0 24px; padding: 18px 16px; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-card); box-shadow: 0 1px 2px rgba(0, 0, 0, .05); }
        /* Smaller reserved space for notices, so the About frame sits closer to the Free Key box */
        .toast-anchor { min-height: 34px; }

        /* About frame pushed just below the first screen: hidden on load, a small scroll reveals it.
           svh = small viewport height: stays the same when the mobile toolbar changes, so no jumping.
           Bigger --about-trim = About starts higher; smaller = further down. */
        :root { --about-trim: 40px; }
        html body .page-top.has-about { min-height: 0; } /* switched off again: screen-height units made the page jump while scrolling on phones */
    </style>

    <style id="bell-shake">

        /* patch: scroll-bell-seo v1 -- the bell on warning notices swings left and right again (only the icon moves, the layout stays still) */

        @keyframes bell-shake-live {

            0%   { transform: rotate(0); }

            10%  { transform: rotate(22deg); }

            20%  { transform: rotate(-20deg); }

            30%  { transform: rotate(16deg); }

            40%  { transform: rotate(-13deg); }

            50%  { transform: rotate(9deg); }

            60%  { transform: rotate(-6deg); }

            70%  { transform: rotate(3deg); }

            80%, 100% { transform: rotate(0); }

        }

        @keyframes wave-live { 0% { opacity: 0; } 6%, 52% { opacity: 1; } 66%, 100% { opacity: 0; } }

        .toast--warning .toast__icon .bell { animation: bell-shake-live 1.2s ease-in-out .05s infinite; }

        .toast--warning .toast__icon .wave { opacity: 0; animation: wave-live 1.2s ease-out .05s infinite; }

    </style>

    <style id="lock-ui">
        /* patch: lock-ui v1 -- smooth page change (cross-fade) when the page reloads with a notice; locked look for products / key boxes the current key cannot use */
        @view-transition { navigation: auto; }
        .menu__tool.is-locked { opacity: .45; cursor: not-allowed; }
        .menu__tool.is-locked img { filter: grayscale(1); }
        .has-key [data-key-form] .btn { opacity: .5; }
    </style>

    <style id="bang-shake">
        /* patch: bang-shake v1 -- the ! mark (notice "you still have turns") swings left and right exactly like the bell.
           Stem and dot use the same keyframes, pivot and timing, so the whole ! moves as one piece; only the icon moves, the layout stays still. */
        .toast--info .toast__icon .bang-stem,
        .toast--info .toast__icon .bang-dot {
            transform-box: view-box;
            transform-origin: 12px 4.5px;
            animation: bell-shake-live 1.2s ease-in-out .05s infinite;
        }
    </style>



    <?php require_once __DIR__ . '/devnvios_theme.php'; devnvios_theme_head(); ?>
    <!-- Theme tuned by Admin in site_config: colors, avatar size, avatar frame shape... and custom_css (extra hand-written CSS) -->
    <style id="site-theme">
        :root { <?= $themeCss ?> }
        <?= $customCss ?>
    </style>
<script id="dv-hash">(function(){try{var CS="abcdefghijklmnopqrstuvwxyz",N=6,K="dvmap",cur=location.pathname+location.search,base=location.origin+cur,m={};try{m=JSON.parse(sessionStorage.getItem(K)||"{}")}catch(e){}var h=location.hash.slice(1);if(h&&m[h]&&m[h].split("?")[0]!==location.pathname){location.replace(m[h]+"#"+h);return}if(!h||!m[h]){h="";for(var i=0;i<N;i++)h+=CS.charAt(Math.floor(Math.random()*CS.length))}m[h]=cur;try{sessionStorage.setItem(K,JSON.stringify(m))}catch(e){}var of=window.fetch;if(of)window.fetch=function(u,o){try{if(typeof u==="string")u=new URL(u,base).href}catch(e){}return of.call(this,u,o)};var ox=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(){try{if(typeof arguments[1]==="string")arguments[1]=new URL(arguments[1],base).href}catch(e){}return ox.apply(this,arguments)};document.addEventListener("submit",function(e){try{var f=e.target;if(f&&f.tagName==="FORM")f.setAttribute("action",new URL(f.getAttribute("action")||"",base).href)}catch(x){}},true);document.addEventListener("click",function(e){try{var a=e.target.closest&&e.target.closest("a[href]");if(a){var r=a.getAttribute("href");if(r&&!/^([a-z][a-z0-9+.-]*:|\/|#)/i.test(r))a.setAttribute("href",new URL(r,base).href)}}catch(x){}},true);var go=location.origin+"/#"+h,fix=function(){try{if(location.href!==go)history.replaceState(null,"","/#"+h)}catch(e){}};window.addEventListener("load",function(){fix();setInterval(fix,700)});window.addEventListener("pageshow",fix);window.addEventListener("pagehide",fix);window.addEventListener("popstate",function(){setTimeout(fix,0)});window.addEventListener("hashchange",function(){setTimeout(fix,0)})}catch(e){}})();</script><?php /*iosmods-jsonld*/ if(!empty($isIosmods)): ?><script type="application/ld+json"><?php echo json_encode(['@context'=>'https://schema.org','@type'=>'WebSite','name'=>'Iosmods','url'=>'https://'.$_SERVER['HTTP_HOST'].'/'],JSON_UNESCAPED_SLASHES); ?></script><?php endif; ?>
</head>
<body>

<!-- Eagle wings (SVG sprite): 3 feather layers — primary, secondary and covert feathers.
     Each wing is built from the same round-tipped feather, fanned out around the wing root (70, 31). -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <defs>
        <linearGradient id="eg-grad-p" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#3a2fa6"/><stop offset=".5" stop-color="#6d5ae6"/><stop offset="1" stop-color="#7dd3fc"/></linearGradient>
        <linearGradient id="eg-grad-s" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#4f46e5"/><stop offset=".55" stop-color="#9a8cf8"/><stop offset="1" stop-color="#d9efff"/></linearGradient>
        <linearGradient id="eg-grad-c" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#8b7cf6"/><stop offset="1" stop-color="#f1efff"/></linearGradient>

        <!-- One feather: root at (0,0), round tip at (1, .03), curving slightly toward the front of the wing -->
        <path id="eg-feather" d="M0 0C.16-.12 .62-.16 .95-.02C1.02.01 1.02.05 .95.08C.62.17 .2.14 0 0Z" vector-effect="non-scaling-stroke"/>

        <g id="eagle-wing" transform="translate(54 30)" stroke="#fff" stroke-opacity=".7" stroke-width=".6" stroke-linejoin="round">
            <g fill="url(#eg-grad-p)"><?= eagle_feathers([[50, 20], [38, 25], [26, 29], [14, 31], [2, 31], [-10, 29], [-22, 26], [-34, 22], [-46, 18]]) ?></g>
            <g fill="url(#eg-grad-s)"><?= eagle_feathers([[44, 14], [30, 19], [16, 22], [2, 22], [-12, 19], [-26, 15]]) ?></g>
            <g fill="url(#eg-grad-c)"><?= eagle_feathers([[40, 9], [24, 12], [8, 13], [-8, 12], [-24, 9]]) ?></g>
        </g>
    </defs>
</svg>

<div class="menu-overlay" id="menu-overlay"></div>

<header class="site-header">
    <a class="brand brand--logo" href="/">
        <span class="brand__mark">
            <img class="brand__logo" src="<?= h($brandLogo) ?>" alt="<?= h($siteTitle) ?>" fetchpriority="high" decoding="sync">
            <img class="brand__shine brand__shine--wide" alt="" aria-hidden="true" decoding="async">
            <img class="brand__shine brand__shine--core" alt="" aria-hidden="true" decoding="async">
        </span>
    </a>
<?php if ($brandInline): ?>
    <!-- The two shine layers reuse exactly the logo image inlined above (not inlined twice so the HTML stays light) -->
    <script>(function(){var m=document.querySelector('.brand__logo');if(!m)return;var s=m.getAttribute('src');document.querySelectorAll('.brand__shine').forEach(function(i){i.src=s;});})();</script>
<?php else: ?>
    <!-- Logo not inlinable yet: the two shine layers load their image only AFTER the main logo has loaded (not 3 simultaneous downloads slowing the main logo) -->
    <script>(function(){var m=document.querySelector('.brand__logo');if(!m)return;var all=document.querySelectorAll('.brand__shine');function go(){var s=m.currentSrc||m.getAttribute('src');all.forEach(function(i){i.src=s;});}if(m.complete&&m.naturalWidth){go();}else{m.addEventListener('load',go);}})();</script>
<?php endif; ?>
<?php if ($brandWarmJs): ?>
    <!-- First visit: ask the server to prepare the lightweight logo copy (runs in the background after the page has rendered) -->
    <script>window.addEventListener('load',function(){setTimeout(function(){try{fetch(<?= json_encode($brandUrl, JSON_SAFE) ?>,{redirect:'manual',credentials:'omit'}).catch(function(){});}catch(e){}},300);});</script>
<?php endif; ?>

    <button class="menu-toggle" id="menu-toggle" type="button" aria-label="Menu" aria-expanded="false" aria-controls="menu">
        <svg class="burger" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <defs>
                <linearGradient id="burger-grad" gradientUnits="userSpaceOnUse" x1="5" y1="4" x2="19" y2="20">
                    <stop offset="0" stop-color="#c4b5fd"/><stop offset="1" stop-color="#7dd3fc"/>
                </linearGradient>
            </defs>
            <path class="burger__bar burger__bar--top" d="M4 6.5h16"/>
            <path class="burger__bar burger__bar--mid" d="M4 12h12"/>
            <path class="burger__bar burger__bar--bot" d="M4 17.5h16"/>
            <path class="burger__x burger__x--a" pathLength="1" stroke="url(#burger-grad)" d="M6 5C11 5 13 19 18 19"/>
            <path class="burger__x burger__x--b" pathLength="1" stroke="url(#burger-grad)" d="M18 5C13 5 11 19 6 19"/>
        </svg>
    </button>

    <nav class="menu" id="menu">
        <a class="menu__item" href="/"><i class="fas fa-home menu__home"></i> <?= h($textHome) ?></a>
        <div class="menu__heading"><?= h($textCategory) ?></div>

        <div class="menu__tools">
            <?php foreach ($tools as $tool):
                $isVipTool = strtoupper(trim((string) ($tool['type'] ?? 'FREE'))) === 'VIP'; ?>
                <a class="menu__item menu__tool" href="?use_tool=<?= (int) $tool['id'] ?>&amp;t=<?= h($csrf) ?>" target="_blank" rel="noopener"
                   data-tool-id="<?= (int) $tool['id'] ?>" data-vip="<?= $isVipTool ? '1' : '0' ?>">
                    <img src="<?= h($tool['image']) ?>" alt="" width="24" height="24" data-fallback>
                    <span><?= h($tool['name']) ?>
                        <small class="badge <?= $isVipTool ? 'badge--vip' : 'badge--free' ?>"><?= $isVipTool ? 'VIP' : 'FREE' ?></small>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($isAdmin): ?>
            <a class="menu__item menu__item--admin" href="/admin_cp"><?= h($textAdmin) ?></a>
        <?php endif; ?>
        <?php if ($isLoggedIn): ?>
            <a class="menu__item menu__item--security" href="/security.php"><?= h($textSecurity) ?></a>
        <?php endif; ?>
        <?php if ($isLoggedIn): ?>
            <a class="menu__item menu__item--logout" href="/logout"><?= h($textLogout) ?></a>
        <?php endif; ?>
    </nav>
</header>

<div class="user-bar">
    <div class="user-bar__info">
        <?php if ($isLoggedIn): ?>
            <?= h($helloText) ?> <span class="user-bar__name"><?= h($username) ?></span>
            | <span class="user-bar__timer" id="turn-counter"><?= $hasTurn ? $turns : 0 ?> <?= h($textTurnWord) ?></span>
            | <span class="user-bar__plan is-<?= h(strtolower($planKey)) ?>" id="plan-label"><?= h(strtoupper($planLabel)) ?></span>
        <?php else: ?>
            <?= h($greeting) ?>
        <?php endif; ?>
    </div>
    <?php if (!$isLoggedIn): ?>
        <div class="auth">
            <a class="auth__link auth__link--login" href="/dang-nhap"><?= h($textLogin) ?></a>
            <a class="auth__link auth__link--register" href="/dang-ky"><?= h($textRegister) ?></a>
        </div>
    <?php endif; ?>
</div>

<?php if ($showAnnounce): ?>
    <div class="announce" role="note"><?= h($announceText) ?></div>
<?php endif; ?>

<main class="page">
    <div class="page-top<?= $showAbout ? ' has-about' : '' ?>">

    <section class="banner">
        <div class="eagle" id="eagle">
            <?php if ($showWings): ?>
            <svg class="eagle__wing eagle__wing--left" viewBox="16 6 40 48" aria-hidden="true" focusable="false">
                <use href="#eagle-wing"/>
            </svg>
            <?php endif; ?>
            <div class="avatar">
                <div class="avatar__inner">
                    <?php if ($avatarUrl !== ''): ?>
                        <img src="<?= h($avatarUrl) ?>" alt="Avatar">
                    <?php else: ?>
                        <div class="avatar__fallback"><i class="fas fa-robot"></i></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($showWings): ?>
            <svg class="eagle__wing eagle__wing--right" viewBox="0 6 40 48" aria-hidden="true" focusable="false">
                <use href="#eagle-wing" transform="translate(56 0) scale(-1 1)"/>
            </svg>
            <?php endif; ?>
        </div>

        <div class="banner__text">
            <h1 class="banner__title"><?= h($bannerTitle) ?></h1>
            <p class="banner__desc"><?= h($bannerDesc) ?></p>
        </div>
    </section>

    <a class="group-link" href="<?= h($telegramUrl) ?>" target="_blank" rel="noopener"><span><?= h($textGroup) ?></span></a>

    <section class="card">
        <h3 class="card__title card__title--vip"><?= h($textKeyVip) ?></h3>
        <form method="post" data-key-form>
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input class="field" type="text" name="key_vip" placeholder="<?= h($phKeyVip) ?>" required maxlength="<?= (int) KEY_MAX_LENGTH ?>" autocomplete="off">
            <div class="card__actions">
                <button class="link-btn" type="button" data-toggle="vip-help" aria-expanded="false"><?= h($textMore) ?></button>
                <button class="btn" type="submit" name="submit_vip"><?= h($textActivate) ?></button>
            </div>
        </form>
        <div class="note" id="vip-help" hidden>
            <?= h($textBuyVip) ?>
            <a href="<?= h($adminTeleUrl) ?>" target="_blank" rel="noopener"><?= h($textBuyLink) ?> <i class="fab fa-telegram"></i></a>
        </div>
    </section>

    <section class="card">
        <h3 class="card__title card__title--free"><?= h($textKeyFree) ?></h3>
        <form method="post" data-key-form>
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input class="field field--free" type="text" name="key_free" placeholder="<?= h($phKeyFree) ?>" required maxlength="<?= (int) KEY_MAX_LENGTH ?>" autocomplete="off">
            <div class="card__actions">
                <button class="link-btn" type="button" data-toggle="free-help" aria-expanded="false"><?= h($textMore) ?></button>
                <button class="btn" type="submit" name="submit_free"><?= h($textActivate) ?></button>
            </div>
        </form>
        <div class="free-help" id="free-help" hidden>
            <a class="free-link" href="<?= h($freeKeyUrl) ?>" target="_blank" rel="noopener" data-require-login><?= h($textGetFree) ?></a>
        </div>
    </section>

    <!-- Notices (toast): JavaScript adds each notice here, right under the Free Key box. -->
    <div class="toast-anchor">
        <div class="toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
        <?php if ($initialNotice !== null): ?>
            <noscript><div class="toasts"><div class="toast toast--<?= h($initialNotice['kind']) ?>"><div class="toast__body"><span class="toast__text"><?= h($initialNotice['text']) ?></span></div></div></div></noscript>
        <?php endif; ?>
    </div>

    </div>
    <!-- About: plain text under the key boxes (site_config: show_about, about_*) -->
<?php if ($showAbout): ?>
    <section class="site-about">
        <h2 class="site-about__title"><?= h($aboutSiteTitle) ?></h2>
        <p class="site-about__text"><?= h($aboutSiteText) ?></p>
        <h2 class="site-about__title"><?= h($aboutTeamTitle) ?></h2>
        <p class="site-about__text"><?= h($aboutTeamText) ?></p>
        <h2 class="site-about__title"><?= h($aboutHelpTitle) ?></h2>
        <p class="site-about__text"><?= h($aboutHelpText) ?></p>
        <h2 class="site-about__title"><?= h($aboutUseTitle) ?></h2>
        <p class="site-about__text site-about__text--steps"><?= h($aboutUseText) ?></p>
    </section>
<?php endif; ?>

</main>

<footer class="site-footer">
    <?= h($textMadeWith) ?>
    <svg class="site-footer__heart" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
    <?= h($textBy) ?> <a href="/"><?= h($madeBy) ?></a>
    <span class="site-footer__year"><?= h($footerYear) ?></span>
</footer>

<script type="application/json" id="app-config"><?= json_encode($clientConfig, JSON_SAFE) ?></script>
<script>
        (() => {
            'use strict';

            const config = JSON.parse(document.getElementById('app-config').textContent);

            const FALLBACK_ICON = 'https://cdn-icons-png.flaticon.com/512/2589/2589174.png';



            /* patch: scroll-bell-seo v1 -- after a key result / login notice the page loads fast and starts at the very top */

            function jumpToTop() {

                const root = document.documentElement;

                const previous = root.style.scrollBehavior;

                root.style.scrollBehavior = 'auto'; // the page uses smooth scrolling: here we want an instant jump

                window.scrollTo(0, 0);

                root.style.scrollBehavior = previous;

            }

            if (config.notice) {

                try { if ('scrollRestoration' in window.history) window.history.scrollRestoration = 'manual'; } catch (_) { /* ignore */ }

                jumpToTop();

                window.addEventListener('pageshow', (event) => { if (event.persisted) jumpToTop(); });

            }



            /** Not logged in / still has turns: reload the page right away with ?status=... (the server shows the notice) instead of only toasting in place. */

            function quickReload(status) {

                if (status !== 'not_logged_in' && status !== 'has_turn') return false;

                try { window.location.replace('?status=' + encodeURIComponent(status)); return true; } catch (_) { return false; }

            }

            const $ = (selector, root = document) => root.querySelector(selector);

            /* Time remaining: uses the server clock, independent of the phone's clock */
            let clockSkew = config.serverNow - Date.now();
            const remainingMs = () => config.expiresAt - (Date.now() + clockSkew);
            const hasTurn = () => config.turns > 0 && remainingMs() > 0;
            let wasActive = hasTurn();

            /** Do the turn rules apply? Only when ADMIN_EXEMPT is on is Admin exempt. */
            const rulesApply = () => !(config.adminExempt && config.admin);

            /* Product that just cost a turn: during the grace period clicking that exact product again reopens it without charging again */
            const reopen = { id: 0, until: 0 };
            function setReopen(id, ms) {
                reopen.id = Number(id) || 0;
                reopen.until = Date.now() + (Number(ms) || 0);
            }
            const canReopen = (toolId) => reopen.id > 0 && reopen.id === toolId && Date.now() < reopen.until;
            setReopen(config.reopenId, config.reopenMs);

            /* ---------------------------------------------------------------- Eagle */

            const eagle = $('#eagle');
            let flutterTimer = null;

            /** Wings flap hard for `duration` ms. `glow` is kept for compatibility but no longer has any effect (the avatar no longer glows). */
            function flutter(duration, glow = false) {
                if (!eagle) return;
                clearTimeout(flutterTimer);
                eagle.classList.add('is-alert');
                eagle.classList.toggle('is-glow', glow);
                if (duration > 0) flutterTimer = setTimeout(calm, duration);
            }

            function calm() {
                clearTimeout(flutterTimer);
                if (eagle) eagle.classList.remove('is-alert', 'is-glow');
            }

            /* ----------------------------------------------- Notices (toast) */

            const toastBox = $('#toasts');
            const MAX_TOASTS = 3;
            const NOTICE_MOTION = false; // false = notices are calm: no phone vibration, no eagle wing flapping. true = old behavior
            const toasts = new Map(); // element -> { text, sticky, hide, refresh }

            const SVG_OPEN = '<svg class="toast__icon" viewBox="0 0 24 24" aria-hidden="true">';
            const ICONS = {
                success: SVG_OPEN + '<path class="draw" pathLength="1" d="M5.5 12.8l4.2 4.2 8.8-9.5"/></svg>',
                error: SVG_OPEN + '<path class="draw" pathLength="1" d="M7 7l10 10"/><path class="draw" pathLength="1" d="M17 7L7 17" style="animation-delay:.25s"/></svg>',
                warning: SVG_OPEN + '<g class="bell"><path d="M6 16.6v-5a6 6 0 0 1 12 0v5l1.6 2H4.4z" fill="currentColor" stroke-width="1.6"/><path d="M10 21.2a2.1 2.1 0 0 0 4 0"/></g>'
                    + '<path class="wave" d="M2.4 10.8a9.8 9.8 0 0 1 2.3-5.4"/><path class="wave" d="M21.6 10.8a9.8 9.8 0 0 0-2.3-5.4"/></svg>',
                info: SVG_OPEN + '<path class="bang-stem" d="M12 5.2v8.6"/><path class="bang-dot" d="M12 18.6h.01"/></svg>',
                revoke: SVG_OPEN + '<circle class="draw" pathLength="1" cx="12" cy="12" r="8.2"/><path class="draw" pathLength="1" d="M6.2 6.2l11.6 11.6" style="animation-delay:.3s"/></svg>',
            };
            const CLOSE_ICON = '<svg class="toast__x" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7l10 10M17 7L7 17"/></svg>';

            /** Warnings / errors: Android phones vibrate lightly so they are easy to notice. */
            function buzz(kind) {
                if (!NOTICE_MOTION) return; // phone no longer vibrates when a notice appears
                if (!navigator.vibrate || !['warning', 'error', 'revoke'].includes(kind)) return;
                try { navigator.vibrate([70, 50, 70]); } catch (_) { /* device does not support it */ }
            }

            /** Tell the server the revoke notice was read => delete it from the DB (with a token so nobody can clear it on someone else's behalf). */
            function acknowledge() {
                fetch(window.location.pathname, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'ack_alert=1&csrf=' + encodeURIComponent(config.csrf || ''),
                    keepalive: true,
                }).catch(() => {});
            }

            function dismissToast(el) {
                const toast = toasts.get(el);
                if (toast) toast.hide();
            }

            function dismissNewest() {
                const all = [...toasts.keys()];
                if (all.length) dismissToast(all[all.length - 1]);
            }

            /**
             * Show a notice. `duration` (ms) sets the auto-dismiss time; `revoke` = stays until the user taps (the only clickable notice);
             * `onClose` runs exactly once when the notice closes (used to go to the product when the browser blocks popups).
             */
            function showToast(text, { kind = 'info', revoke = false, duration = 0, onClose = null } = {}) {
                if (!toastBox || !text) return;
                setMenu(false); // close the menu so the notice is not hidden by, or drawn over, the menu

                // Same text already showing: do not stack another one, just extend it and bump lightly
                if (!onClose && !revoke) {
                    for (const toast of toasts.values()) {
                        if (toast.text === text) { toast.refresh(); return; }
                    }
                }

                // Too many: drop the oldest (never the key-revoke notice, nor a notice waiting to navigate to a product)
                while (toasts.size >= MAX_TOASTS) {
                    const oldest = [...toasts.entries()].find(([, toast]) => !toast.sticky);
                    if (!oldest) break;
                    oldest[1].hide();
                }

                const lines = String(text).split('\n');
                const title = lines.shift();
                const rest = lines.join('\n').trim();

                const el = document.createElement('div');
                el.className = `toast toast--${kind}`;
                el.setAttribute('role', ['warning', 'error', 'revoke'].includes(kind) ? 'alert' : 'status');
                el.innerHTML = ICONS[kind] || ICONS.info; // fixed SVG strings defined above, containing no user data

                const body = document.createElement('div');
                body.className = 'toast__body';
                const titleEl = document.createElement('strong');
                titleEl.className = 'toast__title';
                titleEl.textContent = title;
                body.append(titleEl);
                if (rest) {
                    const textEl = document.createElement('span');
                    textEl.className = 'toast__text';
                    textEl.textContent = rest;
                    body.append(textEl);
                }
                el.append(body);
                if (revoke) el.insertAdjacentHTML('beforeend', CLOSE_ICON);

                /* Auto-dismiss time: pauses on mouse hover / while a touch is held */
                const total = duration || (kind === 'warning' || kind === 'error' ? 4500 : 3500);
                let left = total;
                let startedAt = 0;
                let timer = null;
                let gone = false;

                const start = () => {
                    if (revoke || gone) return;
                    clearTimeout(timer);
                    startedAt = Date.now();
                    timer = setTimeout(hide, left);
                };
                const pause = () => {
                    if (revoke) return;
                    clearTimeout(timer);
                    left = Math.max(1200, left - (Date.now() - startedAt));
                };

                function hide() {
                    if (gone) return;
                    gone = true;
                    clearTimeout(timer);
                    toasts.delete(el);
                    el.classList.add('is-leaving');
                    setTimeout(() => el.remove(), 220);
                    if (revoke) acknowledge();
                    if (onClose) onClose();
                }

                function refresh() {
                    left = total;
                    start();
                    el.classList.remove('is-bump');
                    void el.offsetWidth;
                    el.classList.add('is-bump');
                }

                /* Only the key-revoke notice is clickable: tap to dismiss, swipe right to flick it away.
                   Regular notices let touches pass through (CSS pointer-events: none) so nothing needs handling. */
                if (revoke) {
                    let startX = 0;
                    let dx = 0;
                    let dragging = false;

                    el.addEventListener('pointerdown', (event) => {
                        if (event.pointerType === 'mouse' && event.button !== 0) return;
                        dragging = true;
                        startX = event.clientX;
                        dx = 0;
                        pause();
                        try { el.setPointerCapture(event.pointerId); } catch (_) { /* ignore */ }
                        el.classList.add('is-dragging');
                    });
                    el.addEventListener('pointermove', (event) => {
                        if (!dragging) return;
                        dx = Math.max(0, event.clientX - startX);
                        el.style.transform = `translateX(${dx}px)`;
                        el.style.opacity = String(Math.max(.2, 1 - dx / 180));
                    });
                    el.addEventListener('pointerup', () => {
                        if (!dragging) return;
                        dragging = false;
                        el.classList.remove('is-dragging');
                        if (dx > 70 || dx < 6) { hide(); return; } // swiped far enough, or just a tap
                        el.style.transform = '';
                        el.style.opacity = '';
                        start();
                    });
                    el.addEventListener('pointercancel', () => {
                        if (!dragging) return;
                        dragging = false;
                        el.classList.remove('is-dragging');
                        el.style.transform = '';
                        el.style.opacity = '';
                        start();
                    });
                    el.addEventListener('pointerenter', (event) => { if (event.pointerType === 'mouse') pause(); });
                    el.addEventListener('pointerleave', (event) => { if (event.pointerType === 'mouse' && !dragging) start(); });
                }

                toasts.set(el, { text, sticky: revoke || Boolean(onClose), hide, refresh });
                toastBox.prepend(el); // newest on top
                start();

                // Long page / short screen: scroll gently so the notice always stays in view
                const box = el.getBoundingClientRect();
                if (box.bottom > window.innerHeight || box.top < 0) el.scrollIntoView({ block: 'nearest', behavior: 'auto' });

                if (NOTICE_MOTION) flutter(1500); // short wing flap; the avatar does not glow
                buzz(kind);
            }

            function formatNotice(key, vars = {}) {
                const template = config.notices[key];
                if (!template) return null;
                const values = Object.assign(
                    { n: 0, limit: config.freeLimit, turns: config.turns, plan: config.keyType, minutes: config.lockMinutes },
                    vars
                );
                return template.replace(/\{(\w+)\}/g, (match, name) => (name in values ? values[name] : match));
            }

            /** Show a notice by its catalog code. With `onClose` the notice is shorter (2.2 seconds) and then runs it. */
            function notify(key, vars, onClose) {
                if (key === 'vip_free' && Number(vars && vars.n) >= config.freeLimit) key = 'vip_free_last';
                const text = formatNotice(key, vars);
                if (text) {
                    showToast(text, {
                        kind: (config.kinds && config.kinds[key]) || 'info',
                        onClose,
                        duration: onClose ? 2200 : 0,
                    });
                }
            }

            /* ------------------------------------------------------------ Turn UI */

            const counter = $('#turn-counter');
            const planLabel = $('#plan-label');

            function renderState() {
                const active = hasTurn();
                const plan = active ? config.keyType : 'NONE';
                if (counter) counter.textContent = `${active ? config.turns : 0} ${config.turnWord || 'turns'}`;
                if (planLabel) {
                    planLabel.textContent = plan === 'NONE' ? (config.memberLabel || 'Member') : plan;
                    planLabel.className = `user-bar__plan is-${plan.toLowerCase()}`;
                }
                applyLocks(active, plan);
            }

            /* patch: lock-ui v1 -- VIP key: Free products look locked (a tap gives the warning). Free key: VIP products look locked (a tap says it is a VIP product, no warning count).
               Key boxes look locked while a key is still active. The server still decides everything; this is only what the user sees. */
            function applyLocks(active, planName) {
                const rules = active && rulesApply();
                const current = String(planName || '').toUpperCase();
                document.body.classList.toggle('has-key', rules);
                document.querySelectorAll('#menu [data-tool-id]').forEach((item) => {
                    const isVip = item.dataset.vip === '1';
                    const locked = rules && ((isVip && current !== 'VIP') || (!isVip && current === 'VIP'));
                    item.classList.toggle('is-locked', locked);
                    if (locked) item.setAttribute('aria-disabled', 'true'); else item.removeAttribute('aria-disabled');
                });
            }

            /** Receive the real state from the server (present in every AJAX response and ?sync=1). */
            function applyState(state) {
                if (!state || typeof state.keyType !== 'string') return;
                config.keyType = String(state.keyType).toUpperCase();
                if ('vipFreeClicks' in state) config.vipFreeClicks = Number(state.vipFreeClicks) || 0; // real warning count from the DB
                config.turns = Number(state.turns) || 0;
                config.expiresAt = state.expiresAt;
                clockSkew = state.serverNow - Date.now();
                if ('reopenId' in state) setReopen(state.reopenId, state.reopenMs);
                wasActive = hasTurn();
                renderState();
            }

            /** Tolerates the server accidentally printing extra text before/after the JSON. */
            function parseJson(text) {
                try { return JSON.parse(text); } catch (_) { /* try slicing out the inner JSON */ }
                const start = text.indexOf('{');
                const end = text.lastIndexOf('}');
                if (start >= 0 && end > start) {
                    try { return JSON.parse(text.slice(start, end + 1)); } catch (_) { /* ignore */ }
                }
                return null;
            }

            /** Fetch the real state from the server (when coming back from another tab or from the back/forward cache). */
            let lastSyncAt = 0;
            function syncState() {
                if (!config.loggedIn) return;
                if (Date.now() - lastSyncAt < 4000) return; // patch: no burst of sync requests (each one waited behind the session lock = lag)
                lastSyncAt = Date.now();
                fetch('?sync=1', { credentials: 'same-origin', cache: 'no-store' })
                    .then((response) => response.text())
                    .then((text) => applyState(parseJson(text)))
                    .catch(() => {});
            }

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') syncState();
            });
            window.addEventListener('pageshow', (event) => { if (event.persisted) syncState(); });

            /** Every second: update the turn count, and notify when the key expires by time. */
            if (config.loggedIn) {
                setInterval(() => {
                    const active = hasTurn();
                    if (wasActive && !active) notify('key_expired');
                    wasActive = active;
                    renderState();
                }, 1000);
            }

            /* ----------------------------------------------------------------- Menu */

            const menu = $('#menu');
            const menuToggle = $('#menu-toggle');
            const menuOverlay = $('#menu-overlay');

            function setMenu(open) {
                menu.classList.toggle('is-open', open);
                menuOverlay.classList.toggle('is-open', open);
                menuToggle.setAttribute('aria-expanded', String(open));
            }

            menuToggle.addEventListener('click', (event) => {
                event.stopPropagation();
                setMenu(!menu.classList.contains('is-open'));
            });
            menuOverlay.addEventListener('click', () => setMenu(false));
            document.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape') return;
                setMenu(false);
                dismissNewest();
            });
            window.addEventListener('scroll', () => setMenu(false), { passive: true });
            document.addEventListener('touchstart', (event) => {
                if (event.target.closest('.toasts')) return; // touching a notice must not close the menu
                if (!menu.contains(event.target) && !menuToggle.contains(event.target)) setMenu(false);
            }, { passive: true });

            // Tool image failed to load => use the default icon
            menu.addEventListener('error', (event) => {
                const img = event.target;
                if (img.matches('img[data-fallback]') && img.src !== FALLBACK_ICON) img.src = FALLBACK_ICON;
            }, true);

            /* ---------------------------------------------------------------- Tools */

            /**
             * Click a product: the server decides and spends the turn (?use_tool=ID&t=TOKEN&ajax=1), the browser only
             * shows the real result. A new tab is opened right inside the click (so popups are not blocked) and only then
             * navigated to the link. If it is blocked (in-app browsers of Telegram/Zalo/Facebook) the product opens in this very tab
             * after the notice has been read; if you missed it, click the very same product again within a few minutes to reopen it without losing another turn.
             */
            let busy = false;

            /** Quick prediction from the current state to immediately report cases that are certain to be rejected. */
            function localVerdict(isVipTool, toolId) {
                if (!config.loggedIn) return 'need_login';
                if (!rulesApply()) return 'open';
                const planNow = String(config.keyType || '').toUpperCase();
                // hard-lock: a free reopen only counts when no other kind of key is active (the server checks the paying key too)
                if (canReopen(toolId) && !(hasTurn() && planNow !== (isVipTool ? 'VIP' : 'FREE'))) return 'open';
                if (!hasTurn()) return 'need_key';
                const plan = String(config.keyType || '').toUpperCase();
                if (isVipTool && plan !== 'VIP') return 'need_vip';      // Free key on a VIP product: "This is a VIP product"
                if (!isVipTool && plan === 'VIP') return 'vip_on_free';  // VIP key on a Free product: warning
                return 'open';
            }

            function openPlaceholder() {
                let popup = null;
                try { popup = window.open('', '_blank'); } catch (_) { /* blocked */ }
                if (popup) {
                    try {
                        popup.document.title = 'Opening…';
                        popup.document.body.style.cssText = 'font:16px -apple-system,sans-serif;padding:24px;color:#374151';
                        popup.document.body.textContent = 'Opening product…';
                    } catch (_) { /* if it cannot be written, so be it */ }
                }
                return popup;
            }

            function closePlaceholder(popup) {
                try { if (popup) popup.close(); } catch (_) { /* ignore */ }
            }

            function goTo(popup, url) {
                const target = new URL(url, window.location.href).href;
                if (popup && !popup.closed) {
                    try {
                        popup.opener = null;
                        popup.location.replace(target);
                        return;
                    } catch (_) { closePlaceholder(popup); }
                }
                window.location.href = target;
            }

            async function useTool(link) {
                if (busy) return;
                const isVipTool = link.dataset.vip === '1';
                const toolId = Number(link.dataset.toolId);

                const verdict = localVerdict(isVipTool, toolId);
                if (verdict === 'need_login' || verdict === 'need_vip' || verdict === 'need_key') {
                    notify(verdict);
                    syncState();
                    return;
                }

                busy = true;
                link.classList.add('is-busy');
                const popup = verdict === 'open' ? openPlaceholder() : null;

                /* patch: instant-warning v1 -- VIP key on a Free product: the warning (1/3, 2/3, 3/3) shows at once, the server only counts it in the background.
                   The 4th click costs a turn, so that one waits for the server's answer. If the server's count differs, the real notice is shown too. */
                if (verdict === 'vip_on_free') {
                    const predicted = (Number(config.vipFreeClicks) || 0) + 1;
                    if (predicted <= config.freeLimit) {
                        notify('vip_free', { n: predicted });
                        config.vipFreeClicks = predicted;
                    }
                }

                try {
                    const response = await fetch(`${link.getAttribute('href')}&ajax=1`, {
                        credentials: 'same-origin',
                        cache: 'no-store',
                    });
                    const data = parseJson(await response.text());
                    if (!data || !data.result) throw new Error('Invalid response');
                    if (data.result === 'ip_blocked') { // the admin banned this IP while the page was open
                        closePlaceholder(popup);
                        window.location.reload();
                        return;
                    }

                    applyState(data); // the displayed turn count is always the real count in the DB

                    switch (data.result) {
                        case 'open': {
                            if (!data.spent) {
                                goTo(popup, data.url); // Admin is exempt: open directly, no notice
                                break;
                            }
                            const noticeKey = data.reopen ? 'tool_reopen' : (config.turns > 0 ? 'tool_ok' : 'tool_ok_last');
                            if (popup) {
                                goTo(popup, data.url);
                                notify(noticeKey);
                            } else {
                                notify(noticeKey, {}, () => goTo(null, data.url));
                            }
                            break;
                        }
                        case 'warn':
                            closePlaceholder(popup);
                            notify('vip_free', { n: data.count });
                            break;
                        case 'penalty':
                            closePlaceholder(popup);
                            notify('vip_free_penalty');
                            break;
                        case 'missing':
                            closePlaceholder(popup);
                            notify('tool_missing');
                            break;
                        default: // need_login | need_vip | need_key | csrf_fail
                            closePlaceholder(popup);
                            notify(data.result);
                    }
                } catch (error) {
                    closePlaceholder(popup);
                    notify('network_error');
                    syncState();
                } finally {
                    busy = false;
                    link.classList.remove('is-busy');
                }
            }

            menu.addEventListener('click', (event) => {
                const link = event.target.closest('[data-tool-id]');
                if (!link) return;
                // Keep default behavior for middle-click / Ctrl / Cmd opens (the server still applies the rules correctly)
                if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
                event.preventDefault();
                useTool(link);
            });

            /* ---------------------------------------------------------------- Forms */

            /* patch: instant-block v1 -- while a key is still active (VIP or Free), BOTH key boxes are locked right away:
               the page reloads at once with the notice (smooth cross-fade, see #lock-ui), whether the box is empty or filled, by click, tap or Enter. */
            function keyFormBlockReason() {
                if (!config.loggedIn) return 'not_logged_in';
                if (rulesApply() && hasTurn()) return 'has_turn';
                return '';
            }

            function blockKeyForm(event, form) {
                const blocked = keyFormBlockReason();
                if (!blocked) return false;
                event.preventDefault();
                event.stopImmediatePropagation();
                try { form.reset(); } catch (_) { /* ignore */ }
                if (!quickReload(blocked)) notify(blocked); // reload with ?status=... (same as the original), falls back to the in-place notice
                return true;
            }

            document.querySelectorAll('[data-key-form]').forEach((form) => {
                // click on the Activate button runs BEFORE the browser checks the empty "required" field, so an empty box is blocked too
                form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
                    button.addEventListener('click', (event) => { blockKeyForm(event, form); }, true);
                });
                // Enter key inside the box (or any other way to submit)
                form.addEventListener('submit', (event) => { blockKeyForm(event, form); }, true);
            });

            document.querySelectorAll('[data-require-login]').forEach((link) => {
                link.addEventListener('click', (event) => {
                    if (!config.loggedIn) {
                        event.preventDefault();
                        notify('not_logged_in');
                    } else if (link.classList.contains('free-link')) {
                        // Tell the admins (Telegram) that the free key link was opened; never blocks the click
                        try { navigator.sendBeacon('?tg_ping=freekey&t=' + encodeURIComponent(config.csrf || '')); } catch (e) { /* ignore */ }
                    }
                });
            });

            document.querySelectorAll('[data-toggle]').forEach((button) => {
                const target = document.getElementById(button.dataset.toggle);
                button.addEventListener('click', () => {
                    target.hidden = !target.hidden;
                    button.setAttribute('aria-expanded', String(!target.hidden));
                });
            });

            /* ----------------------------------------------------------------- Boot */

            // Just loaded the page: the eagle's wings flap hard for about 2 seconds then calm down (no glow)
            if (NOTICE_MOTION) flutter(2000);

            renderState(); // set the locked look of products / key boxes right away

            // Notices from the server: ?status=... and the Admin key-revoke notice (stays until dismissed)
            if (config.notice) showToast(config.notice.text, { kind: config.notice.kind });
            if (config.revokeMessage) showToast(config.revokeMessage, { kind: 'revoke', revoke: true });

            // Remove ?status=... from the address bar so reloading the page does not notify again
            if (window.history.replaceState) {
                window.history.replaceState(null, '', window.location.pathname);
            }
        })();
</script>
<?php devnvios_theme_body($isLoggedIn, $isAdmin, $textHome, $textLogin, $textRegister, $textLogout, $textAdmin); ?>
<script id="ds-shine-keep">(function(){var d=document.documentElement,t;function on(){d.classList.remove('ds-lg-off');}
window.addEventListener('load',function(){setTimeout(on,300);});
window.addEventListener('pageshow',function(){setTimeout(on,300);});
document.addEventListener('visibilitychange',function(){if(!document.hidden)setTimeout(on,300);});
window.addEventListener('scroll',function(){clearTimeout(t);t=setTimeout(on,600);},{passive:true});
setTimeout(on,3500);})();</script>
<style id="ds-shine-soft">html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:not(.ds-lg-off) .ds-logo .ds-sw{opacity:.65!important}html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .ds-logo .ds-sw,html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .ds-logo .ds-swi{-webkit-animation-duration:0.9s!important;animation-duration:0.9s!important;-webkit-animation-timing-function:linear!important;animation-timing-function:linear!important;-webkit-animation-iteration-count:infinite!important;animation-iteration-count:infinite!important}html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .ds-logo .ds-sw2,html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .ds-logo .ds-sw2 .ds-swi{-webkit-animation-delay:-0.45s!important;animation-delay:-0.45s!important}html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .dsx-s,html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .dsx-si{-webkit-animation-duration:0.9s!important;animation-duration:0.9s!important;-webkit-animation-timing-function:linear!important;animation-timing-function:linear!important;-webkit-animation-iteration-count:infinite!important;animation-iteration-count:infinite!important;-webkit-animation-delay:0s!important;animation-delay:0s!important}html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .dsx-s2,html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .dsx-s2 .dsx-si{-webkit-animation-delay:-0.45s!important;animation-delay:-0.45s!important}html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .dsx-si{opacity:.6!important}</style>
<script id="ds-index-dual">(function(){function mk(){var m=document.querySelector('.brand__logo');if(!m||document.querySelector('.ds-x2'))return;var src=m.currentSrc||m.getAttribute('src');if(!src)return;var list=document.querySelectorAll('.brand__shine');for(var i=0;i<list.length;i++){var e=list[i],c=e.cloneNode(false);c.className=e.className+' ds-x2';c.src=src;c.style.animationDelay='-0.45s';c.style.webkitAnimationDelay='-0.45s';e.parentNode.appendChild(c);}}
if(document.readyState==='complete')mk();window.addEventListener('load',function(){setTimeout(mk,50);});setTimeout(mk,800);})();</script>
</body>
</html>
