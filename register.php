<?php
include 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (is_file(__DIR__ . '/telegram.php')) {
    try {
        require_once __DIR__ . '/telegram.php';
        tg_boot('web');
    } catch (Throwable $e) {
        error_log('[register.php] telegram: ' . $e->getMessage());
    }
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');


/* Cloudflare Turnstile (CAPTCHA verification) */
if (!defined('TURNSTILE_SECRET'))  define('TURNSTILE_SECRET',  (string) (getenv('TURNSTILE_SECRET') ?: ''));
if (!defined('TURNSTILE_SITEKEY')) define('TURNSTILE_SITEKEY', (string) (getenv('TURNSTILE_SITEKEY') ?: ''));

/* Minimum lengths when registering */
const USERNAME_MIN = 7;
const PASSWORD_MIN = 8;
/* patch spinner: ten tai khoan toi da bao nhieu ky tu khi dang ky (doi so nay neu muon) */
const USERNAME_MAX = 24;

/* Defaults same as index.php (change the real values in the site_config table) */
const DEFAULT_BRAND_LOGO = 'uploads/logo.png';
const DEFAULT_FAVICON    = 'https://i.postimg.cc/R0N1S49b/IMG-1242.png';
const FAVICON_BG         = '#14161c';
const FAVICON_VERSION    = 3;

/* Documents page (same as DS_DOCS_URL on the main site) */
const DOCS_URL = '/docs.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cfg(array $config, string $key, string $default): string
{
    $value = trim((string) ($config[$key] ?? ''));
    return $value === '' ? $default : $value;
}

function load_site_config($conn): array
{
    $config = [];
    try {
        $result = mysqli_query($conn, 'SELECT config_key, config_value FROM site_config');
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $config[$row['config_key']] = $row['config_value'];
        }
    } catch (Throwable $e) {
        error_log('[register.php] site_config: ' . $e->getMessage());
    }
    return $config;
}

function turnstile_ok(string $token): bool
{
    if ($token === '') {
        return false;
    }
    $url  = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    $data = http_build_query([
        'secret'   => TURNSTILE_SECRET,
        'response' => $token,
        'remoteip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ]);

    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => $data,
            'timeout' => 10,
        ]]);
        $body = @file_get_contents($url, false, $context);
    }

    $json = is_string($body) ? json_decode($body, true) : null;
    return is_array($json) && !empty($json['success']);
}

/** Attach the error class (red border) to an input whose name is in the list. */
function invalid_attr(?array $notice, string $field): string
{
    return ($notice !== null && in_array($field, $notice['fields'], true)) ? ' class="is-invalid" aria-invalid="true"' : '';
}

/* patch spinner: kiem tra ten tai khoan da co chua (JS goi luc dang go). Khong phan biet hoa thuong. */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ds_check_user'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $dsQ     = trim((string) $_GET['ds_check_user']);
    $dsTaken = false;
    $dsNow   = time();
    $dsWin   = (int) ($_SESSION['ds_chk_t'] ?? 0);
    $dsCnt   = (int) ($_SESSION['ds_chk_n'] ?? 0);
    if ($dsNow - $dsWin > 60) { $dsWin = $dsNow; $dsCnt = 0; }
    $dsCnt++;
    $_SESSION['ds_chk_t'] = $dsWin;
    $_SESSION['ds_chk_n'] = $dsCnt;
    if ($dsCnt <= 40 && $dsQ !== '' && strlen($dsQ) <= 64) {
        try {
            $dsSt = $conn->prepare('SELECT 1 FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
            if ($dsSt !== false) {
                $dsSt->bind_param('s', $dsQ);
                $dsSt->execute();
                $dsTaken = $dsSt->get_result()->num_rows > 0;
                $dsSt->close();
            }
        } catch (Throwable $e) {
            error_log('[register.php] check_user: ' . $e->getMessage());
        }
    }
    echo json_encode(['taken' => $dsTaken]);
    exit();
}

/* ------------------------------------------------------------------ Registration handling */

$notice    = null;   // ['text' => ..., 'fields' => [names of the inputs with errors]]
$typedUser = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $typedUser = trim((string) ($_POST['username'] ?? ''));
    $pass      = (string) ($_POST['password'] ?? '');
    $repass    = (string) ($_POST['repassword'] ?? '');
    $token     = (string) ($_POST['cf-turnstile-response'] ?? '');
    $nameLen   = function_exists('mb_strlen') ? mb_strlen($typedUser, 'UTF-8') : strlen($typedUser);

    if (!turnstile_ok($token)) {
        $notice = ['text' => 'Please complete the security verification.', 'fields' => []];
    } elseif ($nameLen < USERNAME_MIN) {
        $notice = ['text' => 'Username must be at least ' . USERNAME_MIN . ' characters.', 'fields' => ['username']];
    } elseif ($nameLen > USERNAME_MAX) {
        $notice = ['text' => 'Username must be at most ' . USERNAME_MAX . ' characters.', 'fields' => ['username']];
    } elseif (!preg_match('/^[A-Za-z0-9_]+$/D', $typedUser)) {
        $notice = ['text' => 'Username cannot contain accents, spaces or special characters.', 'fields' => ['username']];
    } elseif (!preg_match('/[A-Z]/', $typedUser)) {
        $notice = ['text' => 'Username must contain at least 1 uppercase letter.', 'fields' => ['username']];
    } elseif (strlen($pass) < PASSWORD_MIN) {
        $notice = ['text' => 'Password must be at least ' . PASSWORD_MIN . ' characters.', 'fields' => ['password']];
    } elseif (!preg_match('/[A-Z]/', $pass)) {
        $notice = ['text' => 'Password must contain at least 1 uppercase letter.', 'fields' => ['password']];
    } elseif (strpbrk($pass, '@,.!#$%^&*()_+-=?;:/\\|<>{}[]~`\'"') === false) {
        $notice = ['text' => 'Password must contain at least 1 special character, e.g. @ , . ! #', 'fields' => ['password']];
    } elseif ($pass !== $repass) {
        $notice = ['text' => 'Passwords do not match.', 'fields' => ['password', 'repassword']];
    } else {
        try {
            $check = $conn->prepare('SELECT 1 FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
            if ($check === false) {
                throw new RuntimeException('prepare failed: ' . $conn->error);
            }
            $check->bind_param('s', $typedUser);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            $check->close();

            if ($exists) {
                $notice = ['text' => 'This username is already taken.', 'fields' => ['username']];
            } else {
                $insert = $conn->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
                if ($insert === false) {
                    throw new RuntimeException('prepare failed: ' . $conn->error);
                }
                $insert->bind_param('ss', $typedUser, $pass);
                $insert->execute();
                $newRegId = (int) $insert->insert_id;
                $insert->close();

                try {
                    $regObs = function_exists('sh_observe_register')
                        ? sh_observe_register($conn, $typedUser, $newRegId)
                        : ['alts' => [], 'vpn' => false, 'ip' => ''];
                    if (function_exists('tg_event')) {
                        tg_event('register', [
                            'username' => $typedUser,
                            'id'       => $newRegId,
                            'ip'       => $regObs['ip'],
                            'alts'     => $regObs['alts'],
                            'vpn'      => !empty($regObs['vpn']),
                        ]);
                    }
                } catch (Throwable $tgErr) {
                    error_log('[register.php] tg_event: ' . $tgErr->getMessage());
                }

                session_regenerate_id(true);
                $_SESSION['username'] = $typedUser;
                header('Location: index.php?status=success_reg');
                exit();
            }
        } catch (Throwable $e) {
            if ((int) $e->getCode() === 1062) { // duplicate username because two people registered at the same time
                $notice = ['text' => 'This username is already taken.', 'fields' => ['username']];
            } else {
                error_log('[register.php] ' . $e->getMessage());
                $notice = ['text' => 'The system is busy. Please try again later.', 'fields' => []];
            }
        }
    }
}

/* ------------------------------------------------------------------ UI data (same source as index.php) */

$config = load_site_config($conn);

$isIosmods = (defined('IS_IOSMODS') && IS_IOSMODS) || stripos($_SERVER['HTTP_HOST'] ?? '', 'iosmods') !== false;
$siteTitle  = cfg($config, 'site_title', 'Devnvios'); if ($isIosmods) { $siteTitle = 'iosmods'; }

/* SEO: title and description of this page (Google / shared links).
   Edit in site_config: register_seo_title, register_seo_description (leave empty = use the defaults below). */
$seoTitle = cfg($config, 'register_seo_title', 'Register - Devnvios.io.vn | Trusted Tool & Automation Platform');
$seoDesc  = cfg($config, 'register_seo_description', 'Create your Devnvios.io.vn account in seconds to access trusted tools and automation, activate your key and get fast support via Telegram.');
$madeBy     = $config['footer_made_by'] ?? 'iosmods';
if (strcasecmp(trim((string) $madeBy), 'DEVNVIOS') === 0) {
    $madeBy = 'iosmods';
}
$footerYear = $config['footer_year']    ?? '@2024';
$textMadeWith = cfg($config, 'text_footer_made', 'Made with');
$textBy       = cfg($config, 'text_footer_by', 'by');

/* Header logo: uses the exact uncropped 600x200 main logo from security_shield.php / index.php */
$brandSource = cfg($config, 'brand_logo', DEFAULT_BRAND_LOGO);
if (!preg_match('#^https?://#i', $brandSource)) {
    $brandSource = '/' . ltrim($brandSource, '/\\');
}
$brandUrl = function_exists('sh_logo_url') ? sh_logo_url() : ('index.php?brand_logo=1&v=' . substr(md5($brandSource), 0, 8));

/* spinner v3: lay DUNG gia tri logo / header cua theme chinh (site_config: ds_hd, ds_logo_h, ds_logo_skew, ds_shine_seconds, ds_shine) */
function dsp_num(array $config, string $key, float $min, float $max, ?float $default): ?float
{
    $v = str_replace(',', '.', trim((string) ($config[$key] ?? '')));
    if ($v === '' || !is_numeric($v)) {
        return $default;
    }
    return max($min, min($max, (float) $v));
}

function dsp_fmt(float $n): string
{
    $s = rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    return ($s === '' || $s === '-0') ? '0' : $s;
}

$dspHd         = dsp_num($config, 'ds_hd', 40, 80, null);
$dspLogoH      = dsp_num($config, 'ds_logo_h', 24, 70, dsp_num($config, 'brand_logo_height', 28, 64, 42.0));
$dspBrandScale = dsp_num($config, 'brand_logo_scale', 1.0, 3.0, 1.4);
$dspSkew       = dsp_num($config, 'ds_logo_skew', -30, 30, 10.0);
$dspSecs       = dsp_num($config, 'ds_shine_seconds', 1.2, 20, 2.2);
$dspShineOn    = trim((string) ($config['ds_shine'] ?? '')) !== '0';

$faviconVer = FAVICON_VERSION . '-' . substr(md5(cfg($config, 'favicon_url', DEFAULT_FAVICON) . '|' . cfg($config, 'favicon_bg', FAVICON_BG)), 0, 6);

$privacyUrl = DOCS_URL . '?doc=privacy';
$termsUrl   = DOCS_URL . '?doc=terms';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="color-scheme" content="dark light">
    <?php if ($isIosmods) { $seoDesc = 'Create your Iosmods account to access iOS apps, modded IPA files, games and tools.'; } ?>
    <title><?= $isIosmods ? 'Iosmods Register' : 'devnvios register' ?></title>
    <meta name="description" content="<?= h($seoDesc) ?>">
    <meta name="application-name" content="<?= h($siteTitle) ?>">
    <meta property="og:site_name" content="<?= h($siteTitle) ?>">
    <meta property="og:title" content="<?= $isIosmods ? 'Iosmods Register' : 'devnvios register' ?>">
    <meta property="og:description" content="<?= h($seoDesc) ?>">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="en_US">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="devnvios register">
    <meta name="twitter:description" content="<?= h($seoDesc) ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="/favicon-192.png?v=<?= h($faviconVer) ?>">
    <link rel="icon" type="image/png" sizes="48x48" href="/favicon-48.png?v=<?= h($faviconVer) ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png?v=<?= h($faviconVer) ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="/favicon-192.png?v=<?= h($faviconVer) ?>">
    <link rel="preload" as="image" href="<?= h($brandUrl) ?>" fetchpriority="high">
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <script>try{if(localStorage.getItem('ds-theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
    <style>
        :root {
            color-scheme: dark;
            --ds-bg: #000; --ds-card: #0a0a0b; --ds-field: #141416; --ds-line: #1f1f23; --ds-line2: #26262b;
            --ds-h: #fff; --ds-p: #9ca3af; --ds-link: #3b9eff; --ds-soft: #0d2744; --ds-nav: #0e0e10;
            --ds-red: #ff6961; --ds-hd-bg: #000; --ds-hd-line: #1c1c1f;
            --ds-btn-bg: #0a0a0b; --ds-btn-line: #26262b; --ds-btn-fg: #fff;
            --ds-nav-glass: rgba(14, 14, 16, .56); --ds-nav-line: rgba(255, 255, 255, .09); --ds-pill: rgba(59, 158, 255, .2);
            --ds-mute: #8e8e93;
            --ds-nav-see: rgba(22, 22, 26, .46); --ds-nav-see-line: rgba(255, 255, 255, .07);
            --blue: #0a84ff;
            --ds-font: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif;
            --hd: 54px; --logo-h: 42px; --hbtn: 32px; --nav-h: 56px;
        }
        :root[data-theme="light"] {
            color-scheme: light;
            --ds-bg: #f5f7fa; --ds-card: #fff; --ds-field: #f3f4f6; --ds-line: #e5e7eb; --ds-line2: #d1d5db;
            --ds-h: #111827; --ds-p: #4b5563; --ds-link: #0a6cdc; --ds-soft: #e5f1ff; --ds-nav: #fff;
            --ds-red: #dc2626; --ds-hd-bg: #fff; --ds-hd-line: #e5e7eb;
            --ds-btn-bg: #f3f4f6; --ds-btn-line: #d1d5db; --ds-btn-fg: #111827;
            --ds-nav-glass: rgba(255, 255, 255, .66); --ds-nav-line: rgba(0, 0, 0, .08); --ds-pill: rgba(10, 108, 220, .13);
            --ds-mute: #4b5563;
            --ds-nav-see: rgba(255, 255, 255, .52); --ds-nav-see-line: rgba(0, 0, 0, .06);
        }

        *, *::before, *::after { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; text-size-adjust: 100%; touch-action: manipulation; background: var(--ds-bg); }
        body {
            display: flex; flex-direction: column; min-height: 100vh; min-height: 100dvh; margin: 0; overflow-x: hidden;
            padding-bottom: calc(var(--nav-h) + env(safe-area-inset-bottom, 0px));
            font-family: var(--ds-font); font-size: 14px; line-height: 1.45; color: var(--ds-h); background: var(--ds-bg);
            -webkit-font-smoothing: antialiased; -webkit-tap-highlight-color: transparent;
        }
        body * { font-family: var(--ds-font); }
        h1, h2, h3, p { margin: 0; }
        a { color: inherit; }
        button { padding: 0; font: inherit; color: inherit; background: none; border: 0; cursor: pointer; }
        [hidden] { display: none !important; }
        html.ds-typing body { padding-bottom: 30vh; }
        html.ds-typing .ds-nav { display: none; }

        /* Header (exact 1.4x scaled main logo as iosmods.xyz) */
        .site-header { position: -webkit-sticky; position: sticky; top: 0; z-index: 900; display: flex; align-items: center; justify-content: space-between; box-sizing: border-box; height: var(--hd); padding: 0 14px; overflow: visible; background: var(--ds-hd-bg); border-bottom: 1px solid var(--ds-hd-line); -webkit-transform: translate3d(0, 0, 0); transform: translate3d(0, 0, 0); isolation: isolate; }
        .brand { display: flex; align-items: center; min-width: 0; height: 100%; text-decoration: none; line-height: 0; }
        .brand__mark { position: relative; display: block; height: var(--logo-h); margin-left: -6px; -webkit-transform: scale(<?= dsp_fmt($dspBrandScale) ?>); transform: scale(<?= dsp_fmt($dspBrandScale) ?>); -webkit-transform-origin: 0 50%; transform-origin: 0 50%; pointer-events: none; }

        /* Logo iosmods.xyz: dung THANG lai + lap loa vua phai (2.2s) */
        .ds-logo { position: relative; display: inline-block; flex: 0 1 auto; min-width: 0; height: var(--logo-h); line-height: 0; overflow: hidden; contain: paint; -webkit-transform: translateZ(0) skewX(<?= dsp_fmt($dspSkew) ?>deg); transform: translateZ(0) skewX(<?= dsp_fmt($dspSkew) ?>deg); transform-origin: 50% 50%; }
        .brand__logo { display: block; width: auto; height: var(--logo-h); min-height: var(--logo-h); max-height: var(--logo-h); max-width: 260px; object-fit: contain; }
        .ds-sw { position: absolute; top: 0; left: 0; display: block; width: 100%; height: 100%; pointer-events: none; transform-origin: 50% 50%; -webkit-mask-image: linear-gradient(90deg, rgba(0, 0, 0, 0) 8%, rgba(0, 0, 0, .55) 28%, #000 50%, rgba(0, 0, 0, .55) 72%, rgba(0, 0, 0, 0) 92%); mask-image: linear-gradient(90deg, rgba(0, 0, 0, 0) 8%, rgba(0, 0, 0, .55) 28%, #000 50%, rgba(0, 0, 0, .55) 72%, rgba(0, 0, 0, 0) 92%); will-change: transform; -webkit-backface-visibility: hidden; backface-visibility: hidden; animation: ds-sw35 1.6s linear infinite; }
        .ds-swi { position: absolute; top: 0; left: 0; display: block; width: 100%; height: 100%; max-width: none; object-fit: contain; -webkit-filter: brightness(0) invert(1); filter: brightness(0) invert(1); opacity: 1; transform-origin: 50% 50%; will-change: transform; animation: ds-swi35 1.6s linear infinite; }
        .ds-sw2, .ds-sw2 .ds-swi { animation-delay: -.8s; }
        @keyframes ds-sw35  { from { -webkit-transform: translate3d(-78%, 0, 0); transform: translate3d(-78%, 0, 0); } to { -webkit-transform: translate3d(78%, 0, 0); transform: translate3d(78%, 0, 0); } }
        @keyframes ds-swi35 { from { -webkit-transform: translate3d(78%, 0, 0); transform: translate3d(78%, 0, 0); } to { -webkit-transform: translate3d(-78%, 0, 0); transform: translate3d(-78%, 0, 0); } }
        @media (prefers-reduced-motion: reduce) { .ds-sw { display: none; } }

        .ds-hd { display: flex; align-items: center; gap: 6px; flex: 0 0 auto; }
        .ds-hbtn { box-sizing: border-box; display: flex; align-items: center; justify-content: center; min-width: var(--hbtn); height: var(--hbtn); padding: 0 8px; font-size: 12px; font-weight: 800; color: var(--ds-btn-fg); background: var(--ds-btn-bg); border: 1px solid var(--ds-btn-line); border-radius: 10px; }
        .ds-hbtn svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        #ds-lang-btn { cursor: default; }

        /* Card + form (shopdsgm.vn style) */
        .page { flex: 1 0 auto; display: flex; align-items: center; justify-content: center; padding: 24px 16px; }
        .panel { width: 100%; max-width: 420px; padding: 26px 20px 20px; background: var(--ds-card); border: 1px solid var(--ds-line); border-radius: 22px; }
        .panel__title { margin-bottom: 22px; font-size: 26px; font-weight: 800; line-height: 1.15; letter-spacing: -.02em; text-align: center; color: var(--ds-h); }
        .field { margin-bottom: 14px; }
        .control { position: relative; }
        .control input { display: block; width: 100%; height: 50px; padding: 0 16px; font-size: 16px; color: var(--ds-h); background: var(--ds-field); border: 1px solid var(--ds-line2); border-radius: 14px; outline: 0; -webkit-appearance: none; appearance: none; transition: border-color .15s; }
        .control--pw input { padding-right: 50px; }
        .control input::placeholder { color: var(--ds-p); opacity: .85; }
        .control input:focus { border-color: var(--blue); }
        .control input.is-invalid, .control input.is-invalid:focus { border-color: var(--ds-red); }
        .control input:-webkit-autofill { -webkit-box-shadow: 0 0 0 100px var(--ds-field) inset; -webkit-text-fill-color: var(--ds-h); caret-color: var(--ds-h); }
        .reveal { position: absolute; top: 0; right: 0; display: flex; align-items: center; justify-content: center; width: 50px; height: 50px; color: var(--ds-p); }
        .reveal svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
        .reveal .slash { display: none; }
        .reveal.is-on { color: var(--ds-h); }
        .reveal.is-on .slash { display: block; }

        .meter { margin-top: 10px; }
        .meter__bar { height: 3px; overflow: hidden; background: var(--ds-line2); border-radius: 2px; }
        .meter__fill { width: 0; height: 100%; transition: width .3s, background-color .3s; }
        .meter__text { margin-top: 6px; font-size: 11.5px; font-weight: 600; color: var(--ds-p); transition: color .3s; }

        .form-error { max-height: 60px; margin: 2px 0 12px; overflow: hidden; font-size: 12.5px; font-weight: 600; line-height: 1.4; text-align: center; color: var(--ds-red); transition: opacity .3s, max-height .3s, margin .3s; }
        .form-error.is-gone { max-height: 0; margin: 0; opacity: 0; }

        /* Verification (Cloudflare Turnstile): full width like the username / password fields, same rounded corners and border color.
           The widget is "flexible" (fills the width); its own 1px edge is hidden under the frame border so only one clean border shows. */
        .captcha { margin: 4px 0 16px; }
        .captcha__frame { box-sizing: border-box; width: 100%; height: 50px; overflow: hidden; background: var(--ds-field); border: 1px solid var(--ds-line2); border-radius: 14px; }
        .captcha .cf-turnstile { width: calc(100% + 2px); height: 65px; margin: -8.5px -1px; transform-origin: 0 0; }

        .submit { width: 100%; height: 50px; font-size: 16px; font-weight: 700; color: #fff; background: var(--blue); border-radius: 14px; transition: transform .1s, opacity .15s; }
        .submit:active { transform: scale(.985); }
        .submit:disabled { opacity: .6; cursor: default; }

        .or { display: flex; align-items: center; gap: 12px; margin: 22px 0 16px; font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--ds-p); }
        .or::before, .or::after { content: ""; flex: 1 1 0; height: 1px; background: var(--ds-line2); }
        .or span { white-space: nowrap; }
        .gbtn { display: flex; align-items: center; justify-content: center; min-height: 44px; overflow: hidden; border-radius: 14px; color-scheme: light; }

        .panel__foot { margin-top: 18px; font-size: 14px; text-align: center; color: var(--ds-p); }
        .panel__foot a { font-weight: 700; color: var(--ds-link); text-decoration: none; }

        /* Gioi thieu DEVNVIOS (giong khoi cuoi trang cua trang chu) */
        .site-about { flex: 0 0 auto; margin: 22px 0 0; padding: 26px 16px 0; border-top: 1px solid var(--ds-line); }
        .ds-docs { max-width: 420px; margin: 0 auto 26px; }
        .ds-docs__blk { display: block; margin: 0 0 22px; }
        .ds-docs__h { display: flex; align-items: center; gap: 8px; margin: 0 0 10px; font-size: 14.5px; font-weight: 700; line-height: 1.25; letter-spacing: .03em; text-transform: uppercase; color: var(--ds-h); }
        .ds-docs__h svg { flex: 0 0 auto; width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .ds-docs__h--brand { margin-bottom: 8px; font-size: 18px; letter-spacing: -.01em; text-transform: none; }
        .ds-docs__p { font-size: 14px; font-weight: 400; line-height: 1.45; color: var(--ds-mute); }
        .ds-docs > .ds-docs__h { margin: 0 0 6px; }
        .ds-doclink { display: block; padding: 6px 0; font-size: 14px; font-weight: 400; line-height: 1.3; text-decoration: none; color: var(--ds-mute); }
        .ds-doclink:active { opacity: .6; }
        .ds-doclink:last-child { padding-bottom: 0; }

        .site-footer { padding: 6px 16px 14px; font-size: 11.5px; font-weight: 600; text-align: center; text-transform: none; color: var(--ds-p); }
        .site-footer a { color: var(--blue); text-decoration: none; }
        .site-footer__heart { display: inline-block; width: 12px; height: 12px; vertical-align: -2px; color: #ff3b30; animation: beat .6s infinite; }
        .site-footer__year { display: block; margin-top: 2px; font-weight: 400; opacity: .7; }
        @keyframes beat { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.2); } }

        /* Bottom tab bar: frosted glass, same as the main site */
        .ds-nav { position: fixed; left: 0; right: 0; bottom: 0; z-index: 1000; isolation: isolate; -webkit-transform: translate3d(0, 0, 0); transform: translate3d(0, 0, 0); padding: 3px 10px calc(4px + env(safe-area-inset-bottom, 0px)); background: transparent; -webkit-user-select: none; user-select: none; -webkit-touch-callout: none; }
        .ds-nav::before { content: ""; position: absolute; z-index: -1; left: 0; right: 0; top: 0; bottom: -160px; pointer-events: none; background: var(--ds-nav-see); border-top: 1px solid var(--ds-nav-see-line); -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px); }
        @supports not ((-webkit-backdrop-filter: blur(1px)) or (backdrop-filter: blur(1px))) { .ds-nav::before { background: var(--ds-nav); } }
        .ds-nav__in { position: relative; display: flex; gap: 6px; max-width: 36rem; margin: 0 auto; }
        .ds-nav__item { box-sizing: border-box; display: flex; flex: 1 1 0; flex-direction: column; align-items: center; gap: 1px; min-width: 0; padding: 5px 2px 4px; font-size: 10.5px; font-weight: 600; color: var(--ds-p); text-decoration: none; background: transparent; border: 0; border-radius: 12px; transition: background-color .18s ease, color .18s ease; }
        .ds-nav__item:active { background: var(--ds-pill); }
        .ds-nav__item.is-active { color: var(--ds-link); background: var(--ds-pill); }
        .ds-nav__item span { display: block; max-width: 100%; overflow: hidden; line-height: 1.25; text-overflow: ellipsis; white-space: nowrap; color: inherit; }
        .ds-ico { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    </style>
<!--DS-SPINNER-HEAD-BEGIN-->
<style id="ds-spinner-v3">
    /* spinner v3: logo devnvios.io.vn giong het trang chu (kich thuoc / goc thang / toc do lay tu site_config) + lap loa khoi dong 1 lan.
       Dai sang chi chay sau khi logo da len (JS them class is-ready), nen khong con chap chung luc load. */
<?php if ($dspHd !== null || $dspLogoH !== null): ?>
    html:root:root:root:root { <?= $dspHd !== null ? '--hd: ' . (int) round($dspHd) . 'px; ' : '' ?><?= $dspLogoH !== null ? '--logo-h: ' . (int) round($dspLogoH) . 'px;' : '' ?> }
<?php endif; ?>
    html:root:root:root:root .ds-logo { contain: paint; -webkit-transform: translateZ(0) skewX(<?= dsp_fmt($dspSkew) ?>deg); transform: translateZ(0) skewX(<?= dsp_fmt($dspSkew) ?>deg); transform-origin: 50% 50%; }
    html:root:root:root:root .ds-logo .ds-sw {
        display: <?= $dspShineOn ? 'block' : 'none' ?> !important; visibility: visible !important; opacity: 0 !important;
        -webkit-animation: ds-sw35 <?= dsp_fmt($dspSecs) ?>s linear infinite !important; animation: ds-sw35 <?= dsp_fmt($dspSecs) ?>s linear infinite !important;
        -webkit-animation-play-state: paused !important; animation-play-state: paused !important;
    }
    html:root:root:root:root .ds-logo .ds-swi {
        display: block !important; visibility: visible !important; opacity: 1 !important;
        -webkit-animation: ds-swi35 <?= dsp_fmt($dspSecs) ?>s linear infinite !important; animation: ds-swi35 <?= dsp_fmt($dspSecs) ?>s linear infinite !important;
        -webkit-animation-play-state: paused !important; animation-play-state: paused !important;
    }
    html:root:root:root:root .ds-logo .ds-sw2,
    html:root:root:root:root .ds-logo .ds-sw2 .ds-swi {
        -webkit-animation-delay: -<?= dsp_fmt($dspSecs / 2) ?>s !important; animation-delay: -<?= dsp_fmt($dspSecs / 2) ?>s !important;
    }
    html:root:root:root:root .ds-logo.is-ready .ds-sw { opacity: 1 !important; }
    html:root:root:root:root .ds-logo.is-ready .ds-sw,
    html:root:root:root:root .ds-logo.is-ready .ds-swi { -webkit-animation-play-state: running !important; animation-play-state: running !important; }
    html:root:root:root:root .ds-logo.is-reset .ds-sw,
    html:root:root:root:root .ds-logo.is-reset .ds-swi { -webkit-animation: none !important; animation: none !important; }
</style>
<?php
/* patch spinner: lay DUNG kich thuoc logo / do nghieng / toc do lap loa nhu theme (admin chinh trong site_config:
   ds_hd, ds_logo_h, ds_logo_skew, ds_shine_seconds, ds_shine) */
$dsNum = function (string $k, int $min, int $max) use ($config): string {
    $v = trim((string) ($config[$k] ?? ''));
    return ($v !== '' && preg_match('/^\d{1,4}$/', $v) && (int) $v >= $min && (int) $v <= $max) ? (string) (int) $v : '';
};
$dsHd  = $dsNum('ds_hd', 40, 80);
$dsLh  = $dsNum('ds_logo_h', 24, 70);
$dsCss = '';
if ($dsHd !== '' || $dsLh !== '') {
    $dsCss .= 'html:root:root:root:root{' . ($dsHd !== '' ? '--hd:' . $dsHd . 'px;' : '') . ($dsLh !== '' ? '--logo-h:' . $dsLh . 'px;' : '') . '}';
}
$dsSkew = str_replace(',', '.', trim((string) ($config['ds_logo_skew'] ?? '')));
if ($dsSkew !== '' && is_numeric($dsSkew)) {
    $dsSk = rtrim(rtrim(number_format(max(-30, min(30, (float) $dsSkew)), 2, '.', ''), '0'), '.');
    if ($dsSk === '' || $dsSk === '-0') { $dsSk = '0'; }
    $dsCss .= 'html:root:root:root:root .ds-logo{-webkit-transform:translateZ(0) skewX(' . $dsSk . 'deg);transform:translateZ(0) skewX(' . $dsSk . 'deg)}';
}
$dsShine = str_replace(',', '.', trim((string) ($config['ds_shine_seconds'] ?? '')));
if ($dsShine !== '' && is_numeric($dsShine)) {
    $dsF  = max(0.8, min(20, (float) $dsShine));
    $dsFs = rtrim(rtrim(number_format($dsF, 2, '.', ''), '0'), '.');
    $dsHs = rtrim(rtrim(number_format($dsF / 2, 2, '.', ''), '0'), '.');
    $dsPS = 'html:root:root:root:root:root:root';
    $dsCss .= $dsPS . ' .ds-logo .ds-sw,' . $dsPS . ' .ds-logo .ds-swi{animation-duration:' . $dsFs . 's!important}'
        . $dsPS . ' .ds-logo .ds-sw2,' . $dsPS . ' .ds-logo .ds-sw2 .ds-swi{animation-delay:-' . $dsHs . 's!important}';
}
if (trim((string) ($config['ds_shine'] ?? '')) === '0') {
    $dsCss .= 'html:root:root:root:root:root:root .ds-logo .ds-sw{display:none!important}';
}
?>
<style id="ds-logo-cfg"><?= $dsCss ?></style>
<!--DS-SPINNER-HEAD-END-->
<script id="dv-hash">(function(){try{var CS="abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789",N=7,K="dvmap",cur=location.pathname+location.search,base=location.origin+cur,m={};try{m=JSON.parse(sessionStorage.getItem(K)||"{}")}catch(e){}var h=location.hash.slice(1);if(h&&m[h]&&m[h].split("?")[0]!==location.pathname){location.replace(m[h]+"#"+h);return}if(!h||!m[h]){h="";for(var i=0;i<N;i++)h+=CS.charAt(Math.floor(Math.random()*CS.length))}m[h]=cur;try{sessionStorage.setItem(K,JSON.stringify(m))}catch(e){}var of=window.fetch;if(of)window.fetch=function(u,o){try{if(typeof u==="string")u=new URL(u,base).href}catch(e){}return of.call(this,u,o)};var ox=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(){try{if(typeof arguments[1]==="string")arguments[1]=new URL(arguments[1],base).href}catch(e){}return ox.apply(this,arguments)};document.addEventListener("submit",function(e){try{var f=e.target;if(f&&f.tagName==="FORM")f.setAttribute("action",new URL(f.getAttribute("action")||"",base).href)}catch(x){}},true);document.addEventListener("click",function(e){try{var a=e.target.closest&&e.target.closest("a[href]");if(a){var r=a.getAttribute("href");if(r&&!/^([a-z][a-z0-9+.-]*:|\/|#)/i.test(r))a.setAttribute("href",new URL(r,base).href)}}catch(x){}},true);var go=location.origin+"/#"+h,fix=function(){try{if(location.href!==go)history.replaceState(null,"","/#"+h)}catch(e){}};window.addEventListener("load",function(){fix();setInterval(fix,700)});window.addEventListener("pageshow",fix);window.addEventListener("pagehide",fix);window.addEventListener("popstate",function(){setTimeout(fix,0)});window.addEventListener("hashchange",function(){setTimeout(fix,0)})}catch(e){}})();</script><style id="ds-spinner-v2-css">
/* spinner-v2-logo: shine chi bat khi trang da tai xong va dang hien; an dan khi thoat web, hien dan khi quay lai */
html:root:root:root:root:root:root:root.ds-lg-off .ds-logo .ds-sw{opacity:0!important}
html:root:root:root:root:root:root:root .ds-logo .ds-sw{-webkit-transition:opacity .6s ease!important;transition:opacity .6s ease!important}
html:root:root:root:root:root:root:root.ds-lg-off .ds-logo .ds-sw{-webkit-transition-duration:.3s!important;transition-duration:.3s!important}
html:root:root:root:root:root:root:root.ds-lg-off .ds-logo .ds-sw,html:root:root:root:root:root:root:root.ds-lg-off .ds-logo .ds-swi{-webkit-animation-play-state:paused!important;animation-play-state:paused!important}
html:root:root:root:root:root:root:root.ds-hid .ds-chips2__track{-webkit-animation-play-state:paused!important;animation-play-state:paused!important}
html:root:root:root:root:root:root:root.ds-hid body #ds-acc::after,html:root:root:root:root:root:root:root.ds-hid body #ds-me-av::after{-webkit-animation-play-state:paused!important;animation-play-state:paused!important}
</style>
<script id="ds-spinner-v2-head">(function(){try{var d=document.documentElement;if(document.readyState==='complete')return;d.classList.add('ds-lg-off');var g=function(){d.classList.remove('ds-lg-off')};window.addEventListener('load',function(){setTimeout(g,250)});setTimeout(g,2500);}catch(e){}})();</script>
<!-- dsx-v7 -->
<style id="dsx-css">
/* dsx-v7: tu chua hoan toan (khong dung lop cu), lap loa y het theme, vong xoay don gian, gach tren dong Made with */
html { --dsx-line: #1f1f23; }
html[data-theme="light"] { --dsx-line: #e5e7eb; }
html:root:root:root:root:root:root:root:root:root:root #ds-boot { display: none !important; }
html:root:root:root:root:root:root:root:root:root:root .ds-logo .ds-sw, html:root:root:root:root:root:root:root:root:root:root .ds-logo .ds-sw2, html:root:root:root:root:root:root:root:root:root:root .dsx-sh { display: none !important; }

/* ---- lap loa logo: 2 dai sang (cach nhau nua nhip) + ban sao trang cua logo, y het theme ---- */
.dsx-lg { position: relative; display: inline-block; flex: 0 1 auto; min-width: 0; max-width: 100%; line-height: 0; overflow: hidden; vertical-align: top; -webkit-transform: translateZ(0); transform: translateZ(0); }
.dsx-lg.dsx-sk { -webkit-transform: translateZ(0) skewX(10deg); transform: translateZ(0) skewX(10deg); }
.dsx-s { position: absolute; top: 0; left: 0; display: block; width: 100%; height: 100%; pointer-events: none; transform-origin: 50% 50%;
    -webkit-mask-image: linear-gradient(90deg, rgba(0, 0, 0, 0) 8%, rgba(0, 0, 0, .4) 28%, rgba(0, 0, 0, .72) 50%, rgba(0, 0, 0, .4) 72%, rgba(0, 0, 0, 0) 92%); mask-image: linear-gradient(90deg, rgba(0, 0, 0, 0) 8%, rgba(0, 0, 0, .4) 28%, rgba(0, 0, 0, .72) 50%, rgba(0, 0, 0, .4) 72%, rgba(0, 0, 0, 0) 92%);
    will-change: transform; -webkit-backface-visibility: hidden; backface-visibility: hidden;
    -webkit-animation: dsx-sw 1.6s linear infinite; animation: dsx-sw 1.6s linear infinite;
    -webkit-animation-delay: var(--dsx-ph, 0s); animation-delay: var(--dsx-ph, 0s); }
.dsx-si { position: absolute; top: 0; left: 0; display: block; width: 100%; height: 100%; max-width: none; object-fit: contain; opacity: .9; pointer-events: none;
    -webkit-filter: brightness(0) invert(1); filter: brightness(0) invert(1);
    will-change: transform; -webkit-backface-visibility: hidden; backface-visibility: hidden;
    -webkit-animation: dsx-swi 1.6s linear infinite; animation: dsx-swi 1.6s linear infinite;
    -webkit-animation-delay: var(--dsx-ph, 0s); animation-delay: var(--dsx-ph, 0s); }
.dsx-s2, .dsx-s2 .dsx-si { -webkit-animation-delay: var(--dsx-ph2, -.8s); animation-delay: var(--dsx-ph2, -.8s); }
html.dsx-re .dsx-s, html.dsx-re .dsx-si { -webkit-animation: none !important; animation: none !important; }
@-webkit-keyframes dsx-sw { from { -webkit-transform: translate3d(-78%, 0, 0); transform: translate3d(-78%, 0, 0); } to { -webkit-transform: translate3d(78%, 0, 0); transform: translate3d(78%, 0, 0); } }
@keyframes dsx-sw { from { -webkit-transform: translate3d(-78%, 0, 0); transform: translate3d(-78%, 0, 0); } to { -webkit-transform: translate3d(78%, 0, 0); transform: translate3d(78%, 0, 0); } }
@-webkit-keyframes dsx-swi { from { -webkit-transform: translate3d(78%, 0, 0); transform: translate3d(78%, 0, 0); } to { -webkit-transform: translate3d(-78%, 0, 0); transform: translate3d(-78%, 0, 0); } }
@keyframes dsx-swi { from { -webkit-transform: translate3d(78%, 0, 0); transform: translate3d(78%, 0, 0); } to { -webkit-transform: translate3d(-78%, 0, 0); transform: translate3d(-78%, 0, 0); } }

/* ---- gach ngang tren dong Made with (giong .site-footer cua theme) ---- */
html:root:root:root:root:root:root:root:root:root:root .dsx-foot { border-top: 1px solid var(--dsx-line) !important; padding-top: 22px !important; }

/* ---- man hinh load / cho: chi vong tron + cung xanh, khong mau me ---- */
#dsx-load, #dsx-wait { position: fixed; left: 0; right: 0; top: 0; bottom: 0; display: flex; align-items: center; justify-content: center; background: var(--ds-bg, #000); -webkit-user-select: none; user-select: none; }
html[data-theme="light"] #dsx-load, html[data-theme="light"] #dsx-wait { background: var(--ds-bg, #f5f7fa); }
#dsx-load { z-index: 99999; opacity: 1; -webkit-transition: opacity .28s ease; transition: opacity .28s ease; -webkit-animation: dsx-fs 0s linear 4s forwards; animation: dsx-fs 0s linear 4s forwards; }
#dsx-load.is-out { opacity: 0; pointer-events: none; }
#dsx-wait { z-index: 100000; touch-action: none; font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Text', 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-animation: dsx-fade .2s ease both; animation: dsx-fade .2s ease both; }
.dsx-box { display: flex; flex-direction: column; align-items: center; gap: 16px; -webkit-animation: dsx-in .35s ease backwards; animation: dsx-in .35s ease backwards; }
/* quay ca khoi svg bang transform (chay tren GPU) => khong bi do khi trang dang ban / dang chuyen trang */
.dsx-box svg { display: block; width: 36px; height: 36px; fill: none; overflow: visible; will-change: transform; -webkit-backface-visibility: hidden; backface-visibility: hidden; -webkit-transform: translateZ(0); transform: translateZ(0); -webkit-animation: dsx-rot 1s linear infinite; animation: dsx-rot 1s linear infinite; }
.dsx-txt { display: block; font-family: inherit; font-size: 16px; font-weight: 700; line-height: 1.2; letter-spacing: .01em; text-align: center; color: var(--ds-h, #fff); }
html[data-theme="light"] .dsx-txt { color: var(--ds-h, #111827); }
.dsx-txt b { display: inline-block; margin-left: 1px; font-weight: 800; -webkit-animation: dsx-hop 1.2s ease-in-out infinite; animation: dsx-hop 1.2s ease-in-out infinite; }
.dsx-txt b:nth-of-type(2) { -webkit-animation-delay: .16s; animation-delay: .16s; }
.dsx-txt b:nth-of-type(3) { -webkit-animation-delay: .32s; animation-delay: .32s; }
@keyframes dsx-fade { from { opacity: 0; } to { opacity: 1; } }
@keyframes dsx-in { from { opacity: 0; } to { opacity: 1; } }
@-webkit-keyframes dsx-rot { from { -webkit-transform: translateZ(0) rotate(0deg); } to { -webkit-transform: translateZ(0) rotate(360deg); } }
@keyframes dsx-rot { from { transform: translateZ(0) rotate(0deg); } to { transform: translateZ(0) rotate(360deg); } }
@-webkit-keyframes dsx-hop { 0%, 60%, 100% { -webkit-transform: translateY(0); transform: translateY(0); opacity: .4; } 30% { -webkit-transform: translateY(-7px); transform: translateY(-7px); opacity: 1; } }
@keyframes dsx-hop { 0%, 60%, 100% { transform: translateY(0); opacity: .4; } 30% { transform: translateY(-7px); opacity: 1; } }
@keyframes dsx-fs { to { opacity: 0; visibility: hidden; pointer-events: none; } }
</style>
<script id="dsx-js">
(function () {
    try {
        var LABEL = 'Register';
        var d = document.documentElement;

        /* nhip lap loa khoa theo dong ho (2 dai lech nua nhip, toc do vua phai) */
        function phase() {
            try { var P = <?= (int) round($dspSecs * 1000) ?>, H = <?= (int) round($dspSecs * 500) ?>, q = Date.now() % P; d.style.setProperty('--dsx-ph', (-q / 1000) + 's'); d.style.setProperty('--dsx-ph2', (-((q + H) % P) / 1000) + 's'); } catch (e) {}
        }
        phase();

        function isLight() { return d.getAttribute('data-theme') === 'light'; }
        function spin() {
            var tk = isLight() ? '#dfe3ea' : '#26262b';
            return '<svg viewBox="0 0 48 48" aria-hidden="true">'
                + '<circle cx="24" cy="24" r="18" fill="none" stroke="' + tk + '" stroke-width="2.2"/>'
                + '<circle cx="24" cy="24" r="18" fill="none" stroke="#0a84ff" stroke-width="2.8" stroke-linecap="round" stroke-dasharray="34 79.1"/></svg>';
        }

        /* 1) man hinh load khi vao trang */
        if (document.readyState !== 'complete' && !window.__dsxLoad) {
            window.__dsxLoad = 1;
            var b = document.createElement('div');
            b.id = 'dsx-load';
            b.setAttribute('aria-hidden', 'true');
            b.innerHTML = '<div class="dsx-box">' + spin() + '</div>';
            d.appendChild(b);
            var MIN = 600, MAX = 1800, t0 = Date.now(), closed = 0;
            var logosOk = function () {
                var i = document.querySelector('img.brand__logo, #brand-logo');
                return !!(i && i.complete);
            };
            var widgetsOk = function () {
                var c = document.getElementById('cf-box'), g = document.getElementById('gbtn');
                return (!c || !!c.firstElementChild) && (!g || !!g.firstElementChild);
            };
            var close = function () {
                if (closed) return;
                closed = 1;
                b.className = 'is-out';
                setTimeout(function () { if (b.parentNode) b.parentNode.removeChild(b); }, 320);
            };
            var step = function () {
                var el = Date.now() - t0;
                if (el >= MAX || (document.readyState !== 'loading' && logosOk() && el >= MIN && widgetsOk())) { close(); return; }
                setTimeout(step, 40);
            };
            step();
        }

        /* 2) logo lap loa: tu dung khung + 2 dai sang rieng, gan ngay khi logo xuat hien (khong cho trang tai xong) */
        function mount() {
            try {
                var list = document.querySelectorAll('img.brand__logo, #brand-logo, .ds-brandblock img, .ds-logo > img');
                Array.prototype.forEach.call(list, function (img) {
                    if (img.classList.contains('dsx-si') || img.classList.contains('ds-swi')) return;
                    if (img.closest && img.closest('.dsx-lg')) return;
                    var p = img.parentNode;
                    if (!p) return;
                    var w = document.createElement('span');
                    w.className = 'dsx-lg' + ((p.classList && p.classList.contains('ds-logo')) ? '' : ' dsx-sk');
                    p.insertBefore(w, img);
                    w.appendChild(img);
                    var sis = [];
                    for (var k = 0; k < 2; k++) {
                        var s = document.createElement('i');
                        s.className = 'dsx-s' + (k ? ' dsx-s2' : '');
                        s.setAttribute('aria-hidden', 'true');
                        var c = document.createElement('img');
                        c.className = 'dsx-si';
                        c.alt = '';
                        c.setAttribute('aria-hidden', 'true');
                        c.src = img.currentSrc || img.src;
                        s.appendChild(c);
                        w.appendChild(s);
                        sis.push(c);
                    }
                    var sync = function () {
                        var u = img.currentSrc || img.src;
                        for (var j = 0; j < sis.length; j++) { if (u && sis[j].src !== u) sis[j].src = u; }
                    };
                    img.addEventListener('load', sync);
                });
            } catch (e) {}
        }

        /* 2b) gach ngang tren dong "Made with ..." */
        function foot() {
            try {
                if (document.querySelector('.dsx-foot') || !document.body) return;
                var f = document.querySelector('.site-footer');
                if (!f) {
                    var tw = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false), n;
                    while ((n = tw.nextNode())) {
                        if (/Made with/i.test(n.nodeValue || '')) { f = n.parentNode; break; }
                    }
                    while (f && f.parentNode && f !== document.body && (window.getComputedStyle(f).display || '').indexOf('inline') === 0) f = f.parentNode;
                }
                if (f && f !== document.body && f !== document.documentElement) f.classList.add('dsx-foot');
            } catch (e) {}
        }
        function boot() { mount(); foot(); }

        /* gan som: ngay khi the <img> logo vua duoc trinh duyet doc ra */
        try {
            if (window.MutationObserver) {
                var mo = new MutationObserver(function () { mount(); });
                mo.observe(d, { childList: true, subtree: true });
                setTimeout(function () { try { mo.disconnect(); } catch (e) {} }, 4000);
            }
        } catch (e) {}
        boot();
        document.addEventListener('DOMContentLoaded', boot);
        window.addEventListener('load', function () { boot(); setTimeout(boot, 300); });

        /* lap loa luon chay lai khi quay lai web / mo lai tab / back-forward / iOS dong bang trang */
        var lastRe = 0;
        function restart() {
            var n = Date.now();
            if (n - lastRe < 400) return;
            lastRe = n;
            phase();
            d.classList.add('dsx-re');
            void d.offsetWidth;
            d.classList.remove('dsx-re');
        }
        document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') restart(); });
        window.addEventListener('pageshow', restart);
        window.addEventListener('focus', restart);
        var beat = Date.now();
        setInterval(function () {
            var n = Date.now();
            if (n - beat > 5000 && document.visibilityState === 'visible') restart();
            beat = n;
        }, 2000);

        /* 3) bam Login / Register: man hinh cho + chu + dau cham nhay */
        function showWait() {
            try {
                if (document.getElementById('dsx-wait')) return;
                var a = document.activeElement;
                if (a && a.blur) a.blur();
                var o = document.createElement('div');
                o.id = 'dsx-wait';
                o.setAttribute('role', 'status');
                o.setAttribute('aria-live', 'polite');
                try { var ff = window.getComputedStyle(document.body).fontFamily; if (ff) o.style.fontFamily = ff; } catch (e) {}
                o.innerHTML = '<div class="dsx-box">' + spin() + '<div class="dsx-txt"><span>' + LABEL + '</span><b>.</b><b>.</b><b>.</b></div></div>';
                d.appendChild(o);
                setTimeout(function () {
                    var x = document.getElementById('dsx-wait');
                    if (x && x.parentNode) x.parentNode.removeChild(x);
                    var s = document.getElementById('submit');
                    if (s) s.disabled = false;
                }, 30000);
            } catch (e) {}
        }
        document.addEventListener('submit', function (e) {
            var f = e.target;
            if (!f || f.id !== 'main-form') return;
            setTimeout(function () { if (!e.defaultPrevented) showWait(); }, 0);
        }, true);
        window.addEventListener('pageshow', function () {
            var x = document.getElementById('dsx-wait');
            if (x && x.parentNode) x.parentNode.removeChild(x);
        });
    } catch (e) {}
})();
</script>
</head>
<body>

<header class="site-header">
    <a class="brand" href="/" aria-label="<?= h($siteTitle) ?>">
        <span class="brand__mark">
            <span class="ds-logo" id="ds-logo">
                <img class="brand__logo" id="brand-logo" src="<?= h($brandUrl) ?>" data-fallback="<?= h($brandSource) ?>" onerror="this.onerror=null;this.src=this.dataset.fallback" alt="<?= h($siteTitle) ?>" fetchpriority="high" decoding="sync" draggable="false">
                <i class="ds-sw" aria-hidden="true"><img class="ds-swi" src="<?= h($brandUrl) ?>" alt="" decoding="async" draggable="false"></i>
                <i class="ds-sw ds-sw2" aria-hidden="true"><img class="ds-swi" src="<?= h($brandUrl) ?>" alt="" decoding="async" draggable="false"></i>
            </span>
        </span>
    </a>
    <div class="ds-hd">
        <span class="ds-hbtn" id="ds-lang-btn">EN</span>
        <button class="ds-hbtn" id="ds-theme-btn" type="button" aria-label="Theme"></button>
    </div>
</header>

<main class="page">
    <section class="panel">
        <h1 class="panel__title">Register</h1>

        <form method="post" id="main-form" autocomplete="on" novalidate data-umin="<?= USERNAME_MIN ?>" data-umax="<?= USERNAME_MAX ?>" data-pmin="<?= PASSWORD_MIN ?>">
            <div class="field">
                <div class="control">
                    <input id="username" type="text" name="username"<?= invalid_attr($notice, 'username') ?> value="<?= h($typedUser) ?>" placeholder="Username" aria-label="Username" autocomplete="username" autocapitalize="none" spellcheck="false" required minlength="<?= USERNAME_MIN ?>" maxlength="<?= USERNAME_MAX ?>">
                </div>
            </div>

            <div class="field">
                <div class="control control--pw">
                    <input id="password" type="password" name="password"<?= invalid_attr($notice, 'password') ?> placeholder="Password" aria-label="Password" autocomplete="new-password" required minlength="<?= PASSWORD_MIN ?>">
                    <button class="reveal" type="button" data-reveal="password" aria-label="Show password" aria-pressed="false">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><path class="slash" d="M4 4l16 16"/></svg>
                    </button>
                </div>
            </div>

            <div class="field">
                <div class="control control--pw">
                    <input id="repassword" type="password" name="repassword"<?= invalid_attr($notice, 'repassword') ?> placeholder="Confirm password" aria-label="Confirm password" autocomplete="new-password" required>
                    <button class="reveal" type="button" data-reveal="repassword" aria-label="Show password" aria-pressed="false">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><path class="slash" d="M4 4l16 16"/></svg>
                    </button>
                </div>
            </div>

<?php if ($notice !== null): ?>
            <p class="form-error" id="notice" role="alert"><?= h($notice['text']) ?></p>
<?php endif; ?>

            <div class="captcha" id="captcha">
                <div class="captcha__frame" id="captcha-frame">
                    <div class="cf-turnstile" id="cf-box" data-sitekey="<?= h(TURNSTILE_SITEKEY) ?>" data-theme="dark" data-size="flexible"></div>
                </div>
            </div>
            <script>try{if(localStorage.getItem('ds-theme')==='light')document.getElementById('cf-box').setAttribute('data-theme','light');}catch(e){}</script>

            <button class="submit" type="submit" id="submit">Register</button>
        </form>

        <p class="panel__foot">Already have an account? <a href="login.php">Login</a></p>
    </section>
</main>

<section class="site-about">
    <div class="ds-docs">
        <div class="ds-docs__blk">
            <h3 class="ds-docs__h ds-docs__h--brand"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="6" width="20" height="12" rx="4"/><path d="M7 10v4M5 12h4M16 11h.01M18 13h.01"/></svg><span>IOSMODS</span></h3>
            <p class="ds-docs__p">Keys and tools for iOS in one place. Pick a package, activate a key, open a tool.</p>
        </div>
        <div class="ds-docs__blk">
            <h3 class="ds-docs__h"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18M7 21h10M5 7h14M5 7l-3 7a3.5 3.5 0 0 0 6 0zM19 7l-3 7a3.5 3.5 0 0 0 6 0z"/></svg><span>Legal disclaimer</span></h3>
            <p class="ds-docs__p">Tools are provided for testing and entertainment purposes only. You are responsible for how you use them.</p>
        </div>
        <h3 class="ds-docs__h"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></svg><span>Documents</span></h3>
        <a class="ds-doclink" href="<?= h($privacyUrl) ?>">Privacy Policy</a>
        <a class="ds-doclink" href="<?= h($termsUrl) ?>">Terms of Service</a>
    </div>
</section>

<footer class="site-footer">
    <?= h($textMadeWith) ?>
    <svg class="site-footer__heart" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
    <?= h($textBy) ?> <a href="/"><?= h($madeBy) ?></a>
    <span class="site-footer__year"><?= h($footerYear) ?></span>
</footer>

<nav class="ds-nav" aria-label="Main">
    <div class="ds-nav__in">
        <a class="ds-nav__item" href="/"><svg class="ds-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg><span>Home</span></a>
        <button class="ds-nav__item" type="button" data-go="tools"><svg class="ds-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg><span>Tools</span></button>
        <button class="ds-nav__item" type="button" data-go="hist"><svg class="ds-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M6.2 8 8 4h8l1.8 4"/><rect x="4" y="8" width="16" height="12.5" rx="2.4"/><path d="M9 12.2a3 3 0 0 0 6 0"/></svg><span>History</span></button>
        <a class="ds-nav__item is-active" href="#"><svg class="ds-ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><circle cx="12" cy="10" r="3"/><path d="M6.2 18.6a6.5 6.5 0 0 1 11.6 0"/></svg><span>Register</span></a>
    </div>
</nav>

<script>
(() => {
    'use strict';

    const $ = (selector) => document.querySelector(selector);
    const root = document.documentElement;

    /* Light / dark button (same setting as the main site) */
    const themeBtn = $('#ds-theme-btn');
    const MOON = '<svg viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
    const SUN = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4m11.4-11.4 1.4-1.4"/></svg>';
    function paintTheme() { themeBtn.innerHTML = root.getAttribute('data-theme') === 'light' ? SUN : MOON; }
    themeBtn.addEventListener('click', () => {
        const light = root.getAttribute('data-theme') !== 'light';
        if (light) root.setAttribute('data-theme', 'light'); else root.removeAttribute('data-theme');
        try { localStorage.setItem('ds-theme', light ? 'light' : 'dark'); } catch (e) {}
        paintTheme();
    });
    paintTheme();


    /* Keys / Tools tabs open the matching tab of the main site */
    document.querySelectorAll('[data-go]').forEach((button) => {
        button.addEventListener('click', () => {
            try { sessionStorage.setItem('ds-view', button.dataset.go); } catch (e) {}
            window.location.href = '/';
        });
    });

    /* Typing: hide the tab bar so the keyboard never covers the form.
       Only while the keyboard is REALLY open (measured with visualViewport); removed as soon as it closes,
       so the page never keeps the extra empty space / hidden tab bar by mistake. */
    let typingTimer = 0;
    const keyboardOpen = () => {
        const vv = window.visualViewport;
        if (!vv) return true;
        if (vv.scale && vv.scale > 1.05) return false;
        return (window.innerHeight - vv.height) > 150;
    };
    document.addEventListener('focusin', (event) => {
        const target = event.target;
        if (!/^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName)) return;
        clearTimeout(typingTimer);
        setTimeout(() => {
            if (document.activeElement === target && keyboardOpen()) root.classList.add('ds-typing');
        }, 380);
    });
    document.addEventListener('focusout', () => {
        clearTimeout(typingTimer);
        typingTimer = setTimeout(() => root.classList.remove('ds-typing'), 200);
    });
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', () => {
            const a = document.activeElement;
            const inField = !!(a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName));
            if (!keyboardOpen()) root.classList.remove('ds-typing');
            else if (inField) root.classList.add('ds-typing');
        });
    }

    /* Verification: the widget (cropped to the same 50px height as the fields) fills the full width of the field column (same as username / password).
       Only on very narrow screens (under 300px, the minimum width of the widget) it is scaled down to fit. */
    const captchaFrame = $('#captcha-frame');
    const captchaBox = captchaFrame.querySelector('.cf-turnstile');
    function fitCaptcha() {
        const full = captchaFrame.clientWidth + 2;
        if (full > 2 && full < 300) {
            const scale = full / 300;
            captchaBox.style.width = '300px';
            captchaBox.style.transform = 'scale(' + scale + ')';
            captchaBox.style.marginTop = Math.min(0, -((65 * scale - 48) / 2)).toFixed(1) + 'px';
        } else {
            captchaBox.style.width = '';
            captchaBox.style.transform = '';
            captchaBox.style.marginTop = '';
        }
    }
    fitCaptcha();
    window.addEventListener('resize', fitCaptcha);
    window.addEventListener('load', fitCaptcha);

    /* Show / hide password */
    document.querySelectorAll('[data-reveal]').forEach((button) => {
        const input = document.getElementById(button.dataset.reveal);
        button.addEventListener('click', () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.classList.toggle('is-on', show);
            button.setAttribute('aria-pressed', String(show));
            button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });

    /* Submitting: lock the button to prevent double clicks */
    const form = $('#main-form');
    const submit = $('#submit');
    const submitLabel = submit.textContent;
    window.addEventListener('pageshow', (event) => {
        /* spinner v3: vao lai bang nut Back: mo lai nut gui + reset widget xac minh (khong reload ca trang => logo khong nhay) */
        root.classList.remove('ds-typing');
        if (!event.persisted) return;
        submit.disabled = false;
        submit.textContent = submitLabel;
        try {
            if (window.turnstile) window.turnstile.reset(); else window.location.reload();
        } catch (e) { window.location.reload(); }
    });

    /* spinner v3: Register khong con hien san quy tac / do manh mat khau / kiem tra ten. Chi bao loi KHI bam Register. */
    const SPECIAL = /[@,.!#$%^&*()_+\-=?;:\/\\|<>{}\[\]~`'"]/;
    const UMIN = parseInt(form.getAttribute('data-umin'), 10) || 7;
    const UMAX = parseInt(form.getAttribute('data-umax'), 10) || 7;
    const PMIN = parseInt(form.getAttribute('data-pmin'), 10) || 8;

    /* ---- Thong bao loi: CHI hien khi bam nut gui (hoac khi server tra loi), noi ro cho nao thieu / sai. Tu tat sau 4 giay hoac khi go lai. ---- */
    const captchaAnchor = $('#captcha');
    let noticeEl = $('#notice');
    let noticeTimer = 0;
    function dropInvalid() {
        document.querySelectorAll('.is-invalid').forEach((el) => {
            el.classList.remove('is-invalid');
            el.removeAttribute('aria-invalid');
        });
    }
    function hideNotice() {
        clearTimeout(noticeTimer);
        if (noticeEl) noticeEl.classList.add('is-gone');
        dropInvalid();
    }
    function showNotice(text, ids) {
        if (!noticeEl) {
            noticeEl = document.createElement('p');
            noticeEl.id = 'notice';
            noticeEl.className = 'form-error is-gone';
            noticeEl.setAttribute('role', 'alert');
            captchaAnchor.parentNode.insertBefore(noticeEl, captchaAnchor);
        }
        dropInvalid();
        noticeEl.textContent = text;
        void noticeEl.offsetWidth;
        noticeEl.classList.remove('is-gone');
        ids.forEach((id) => {
            const el = document.getElementById(id);
            if (el) { el.classList.add('is-invalid'); el.setAttribute('aria-invalid', 'true'); }
        });
        clearTimeout(noticeTimer);
        noticeTimer = setTimeout(hideNotice, 4000);
        const first = ids.length ? document.getElementById(ids[0]) : null;
        if (first) first.focus();
    }
    function captchaMissing() {
        if (!window.turnstile) return false;
        const t = form.querySelector('[name="cf-turnstile-response"]');
        return !t || !t.value;
    }
    ['username', 'password', 'repassword'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', () => { if (noticeEl && !noticeEl.classList.contains('is-gone')) hideNotice(); });
    });

    function checkForm() {
        const u = $('#username').value.trim();
        const p = $('#password').value;
        const r = $('#repassword').value;
        if (u === '') return ['Please enter a username.', ['username']];
        if (u.length < UMIN) return ['Username must be at least ' + UMIN + ' characters.', ['username']];
        if (u.length > UMAX) return ['Username must be at most ' + UMAX + ' characters.', ['username']];
        if (!/^[A-Za-z0-9_]+$/.test(u)) return ['Username cannot contain accents, spaces or special characters.', ['username']];
        if (!/[A-Z]/.test(u)) return ['Username must contain at least 1 uppercase letter.', ['username']];
        if (p === '') return ['Please enter a password.', ['password']];
        if (p.length < PMIN) return ['Password must be at least ' + PMIN + ' characters.', ['password']];
        if (!/[A-Z]/.test(p)) return ['Password must contain at least 1 uppercase letter.', ['password']];
        if (!SPECIAL.test(p)) return ['Password must contain at least 1 special character, e.g. @ , . ! #', ['password']];
        if (r === '') return ['Please confirm your password.', ['repassword']];
        if (p !== r) return ['Passwords do not match.', ['password', 'repassword']];
        if (captchaMissing()) return ['Please complete the security verification.', []];
        return null;
    }
    form.addEventListener('submit', (event) => {
        const bad = checkForm();
        if (bad) { event.preventDefault(); showNotice(bad[0], bad[1]); return; }
        submit.disabled = true;
        submit.textContent = '...';
    });
    if (noticeEl) {
        noticeTimer = setTimeout(hideNotice, 4000);
        (document.querySelector('.is-invalid') || $('#username')).focus();
    }
})();
</script>
<!--DS-SPINNER-BODY-BEGIN-->
<script id="ds-logo-kick">
/* spinner-v2-logo: giu lap loa logo on dinh.
   - Chi khoi dong lai MOT lan khi quay lai web (hoac sau khi trang bi dong bang lau), co lam mo dan, khong khoi dong lai khi focus / cham / go chu.
   - Chua tai xong thi shine an; thoat web thi an + tam dung (do ton GPU). */
(function () {
    try {
        if (window.__dsLg2) return;
        window.__dsLg2 = 1;
        var root = document.documentElement, last = 0, tmA = 0, tmB = 0, hid = false, beat = Date.now();
        function ensure() {
            try {
                document.querySelectorAll('.brand__logo, .ds-brandblock img:not(.ds-swi)').forEach(function (img) {
                    var p = img.parentNode;
                    if (!p) return;
                    if (!p.classList || !p.classList.contains('ds-logo')) {
                        var w = document.createElement('span'), sw = document.createElement('i'), c = document.createElement('img');
                        w.className = 'ds-logo'; sw.className = 'ds-sw'; sw.setAttribute('aria-hidden', 'true');
                        c.className = 'ds-swi'; c.alt = ''; c.setAttribute('aria-hidden', 'true'); c.src = img.currentSrc || img.src;
                        p.insertBefore(w, img); w.appendChild(img); sw.appendChild(c); w.appendChild(sw); p = w;
                    }
                    var a = p.querySelector('.ds-sw');
                    if (a && !p.querySelector('.ds-sw2')) { var b = a.cloneNode(true); b.className = 'ds-sw ds-sw2'; p.appendChild(b); }
                    var src = img.currentSrc || img.src;
                    p.querySelectorAll('.ds-swi').forEach(function (si) { if (src && si.src !== src) si.src = src; });
                });
            } catch (e) {}
        }
        function off() { root.classList.add('ds-lg-off'); }
        function on() { root.classList.remove('ds-lg-off'); }
        function restart() {
            ensure();
            var list = document.querySelectorAll('.ds-logo .ds-sw, .ds-logo .ds-swi');
            Array.prototype.forEach.call(list, function (el) {
                el.style.setProperty('animation', 'none', 'important');
                el.style.setProperty('-webkit-animation', 'none', 'important');
            });
            void root.offsetWidth;
            Array.prototype.forEach.call(list, function (el) {
                el.style.removeProperty('animation');
                el.style.removeProperty('-webkit-animation');
            });
        }
        function comeback() {
            var n = Date.now();
            if (n - last < 1200) return;
            last = n;
            clearTimeout(tmA); clearTimeout(tmB);
            off();
            tmA = setTimeout(function () { restart(); tmB = setTimeout(on, 140); }, 260);
        }
        ensure();
        if (document.readyState !== 'complete') {
            off();
            window.addEventListener('load', function () { ensure(); setTimeout(on, 250); });
            setTimeout(on, 2500);
        }
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') { hid = true; off(); root.classList.add('ds-hid'); }
            else { root.classList.remove('ds-hid'); if (hid) { hid = false; comeback(); } }
        });
        window.addEventListener('pagehide', function () { hid = true; off(); });
        window.addEventListener('pageshow', function (e) { ensure(); if (e.persisted || hid) { hid = false; comeback(); } });
        /* trang bi iOS dong bang lau ma khong ban su kien: khoang cach giua 2 lan dem > 9 giay => coi nhu vua quay lai */
        setInterval(function () {
            var n = Date.now();
            if (n - beat > 9000 && document.visibilityState === 'visible') { comeback(); }
            beat = n;
        }, 3000);
    } catch (e) {}
})();
</script>
<!--DS-SPINNER-BODY-END-->
<script id="ds-shine-keep">(function(){var d=document.documentElement,t;function on(){d.classList.remove('ds-lg-off');}
window.addEventListener('load',function(){setTimeout(on,300);});
window.addEventListener('pageshow',function(){setTimeout(on,300);});
document.addEventListener('visibilitychange',function(){if(!document.hidden)setTimeout(on,300);});
window.addEventListener('scroll',function(){clearTimeout(t);t=setTimeout(on,600);},{passive:true});
setTimeout(on,3500);})();</script>
<style id="ds-shine-soft"><?php if (!$dspShineOn): ?>html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .ds-logo .ds-sw,html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .dsx-s{display:none!important}<?php else: ?>html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:not(.ds-lg-off) .ds-logo .ds-sw{opacity:.48!important}html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .ds-logo .ds-sw,html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .ds-logo .ds-swi{-webkit-animation-duration:<?= dsp_fmt($dspSecs) ?>s!important;animation-duration:<?= dsp_fmt($dspSecs) ?>s!important;-webkit-animation-timing-function:linear!important;animation-timing-function:linear!important;-webkit-animation-iteration-count:infinite!important;animation-iteration-count:infinite!important}html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .ds-logo .ds-sw2,html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .ds-logo .ds-sw2 .ds-swi{-webkit-animation-delay:-<?= dsp_fmt($dspSecs / 2) ?>s!important;animation-delay:-<?= dsp_fmt($dspSecs / 2) ?>s!important}html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .dsx-s,html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .dsx-si{-webkit-animation-duration:<?= dsp_fmt($dspSecs) ?>s!important;animation-duration:<?= dsp_fmt($dspSecs) ?>s!important;-webkit-animation-timing-function:linear!important;animation-timing-function:linear!important;-webkit-animation-iteration-count:infinite!important;animation-iteration-count:infinite!important;-webkit-animation-delay:var(--dsx-ph,0s)!important;animation-delay:var(--dsx-ph,0s)!important}html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .dsx-s2,html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .dsx-s2 .dsx-si{-webkit-animation-delay:var(--dsx-ph2,-<?= dsp_fmt($dspSecs / 2) ?>s)!important;animation-delay:var(--dsx-ph2,-<?= dsp_fmt($dspSecs / 2) ?>s)!important}html:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root:root .dsx-si{opacity:.48!important}<?php endif; ?></style>
</body>
</html>
