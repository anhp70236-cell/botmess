<?php
/**
 * check_maintenance.php
 * Included by db.php on every page (the session is already open).
 *
 * When site_config.maintenance_mode = '1':
 *   - Admins (users.role = 1) get in as usual.
 *   - Only admin_cp and maintenance_access.php stay open; every other page, login and register included, shows the maintenance page. Logout is open for logged-in users only.
 *   - AJAX requests (?ajax / ?sync) receive JSON {"result":"maintenance"} instead of a full HTML page.
 *   - Everything else shows the maintenance page with HTTP 503.
 *
 * Content comes from site_config: maintenance_notice, maintenance_title, logo_text, site_avatar,
 * link_tele_group, link_admin_tele, footer_made_by, footer_year, maintenance_show_login,
 * theme_primary, theme_bg, theme_surface, theme_text, theme_muted.
 * Every function name has the mt_ prefix so it never clashes with functions in index.php / admin_cp.php.
 *
 * PAGE DESIGN:
 *  - No JavaScript: no countdown, no auto-reload. A visitor who wants to retry simply refreshes the page.
 *  - Compact layout: everything fits on one screen, centered (gear train, headline, notice, one button).
 *  - The gear train is drawn by PHP (mt_gears): tooth counts, spacing and phase are calculated so the gears
 *    really mesh, neighbours turn in opposite directions, and speed is inversely proportional to tooth count.
 *    This is the only motion on the page.
 *  - Flat, clean look: solid colors only. No glow, no shadows, no gradients.
 *  - Uses the exact theme colors of the site. The text on the button is picked white/black from the brightness
 *    of the primary color. If site_config has no theme_bg / theme_surface / theme_text, the page follows the
 *    light/dark mode of the visitor's device.
 *  - All text in this file is plain ASCII English, so even if the file is saved with the wrong encoding the
 *    maintenance page never shows broken "?" characters.
 *  - The "Admin login" button is hidden by default. Admins enter through the secret link + password created
 *    by admin_cp. To show it again: site_config.maintenance_show_login = 1. To change the headline:
 *    site_config.maintenance_title.
 *    (The login pages are blocked during maintenance too. To open them again set $MT_LOGIN_OPEN = true; before the include.)
 */

/* Pages that are NOT blocked during maintenance (file name or path, lowercase, no leading slash).
   Edit them to match your login page names if they differ. */
if (!isset($MT_ALLOW_PAGES)) {
    $MT_ALLOW_PAGES = [
        'dang-nhap', 'dang-nhap.php', 'dang_nhap.php', 'dangnhap.php',
        'login', 'login.php',
        'logout', 'logout.php',
        // secret admin gateway during maintenance (checks the token + password by itself)
        'maintenance_access.php',
    ];
}

/* Maintenance covers EVERY page. Only these stay open:
   - admin_cp (the admin panel, it checks the Admin role by itself)
   - maintenance_access.php (secret admin gateway)
   Set $MT_LOGIN_OPEN = true before including this file to open the login pages again.
   The logout pages are handled separately below (open for logged-in users only). */
if (!isset($MT_LOGIN_OPEN)) {
    $MT_LOGIN_OPEN = false;
}
if (!$MT_LOGIN_OPEN) {
    $MT_ALLOW_PAGES = array_values(array_diff($MT_ALLOW_PAGES, [
        'dang-nhap', 'dang-nhap.php', 'dang_nhap.php', 'dangnhap.php',
        'login', 'login.php',
    ]));
}
$MT_ALLOW_PAGES = array_values(array_unique(array_merge(
    array_diff($MT_ALLOW_PAGES, ['logout', 'logout.php']),
    ['admin_cp.php', 'admin_cp', 'maintenance_access.php', 'telegram_bot.php', 'telegram_bot']
)));
/* Logout pages: visiting one also clears the remember-Admin cookie (otherwise you would be logged straight back in). */
if (!isset($MT_LOGOUT_PAGES)) {
    $MT_LOGOUT_PAGES = ['logout', 'logout.php', 'dang-xuat', 'dang-xuat.php', 'dang_xuat.php', 'dangxuat.php'];
}

/* Remember Admin: number of days to stay logged in (sliding cookie, extended on every visit).
   Override with site_config.admin_remember_days if you want; 0 = turn this feature off completely. */
if (!isset($MT_REMEMBER_DAYS_DEFAULT)) {
    $MT_REMEMBER_DAYS_DEFAULT = 90;
}

/* Empty = use REMOTE_ADDR. Behind Cloudflare set 'HTTP_CF_CONNECTING_IP' (used when comparing against admin_ip_allow). */
if (!isset($MT_CLIENT_IP_HEADER)) {
    $MT_CLIENT_IP_HEADER = '';
}

if (!function_exists('mt_run')) {

    function mt_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

    /** Valid CSS color (#hex, rgb(), hsl() or a color name); anything else falls back to the default. */
    function mt_color($v, $default) {
        $v = trim((string)$v);
        if ($v === '') return $default;
        if (preg_match('/^#[0-9a-f]{3,8}$/i', $v)
            || preg_match('/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9.\s,%\/-]+\)$/i', $v)
            || preg_match('/^[a-z]{3,20}$/i', $v)) {
            return $v;
        }
        return $default;
    }

    /** Only accepts http(s):// or internal paths; anything invalid returns an empty string. */
    function mt_url($u) {
        $u = trim((string)$u);
        if ($u === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $u)) return '';
        if (preg_match('#^https?://[^/\s]#i', $u) || preg_match('#^/(?!/)#', $u)) return $u;
        return '';
    }

    function mt_client_ip() {
        $ip = '';
        $hdr = (string)($GLOBALS['MT_CLIENT_IP_HEADER'] ?? '');
        if ($hdr !== '' && !empty($_SERVER[$hdr])) $ip = trim(explode(',', (string)$_SERVER[$hdr])[0]);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return substr($ip, 0, 45);
    }

    function mt_https() {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /** Remember-Admin cookie: HttpOnly + SameSite=Lax (+ Secure when running over HTTPS). */
    function mt_set_cookie($value, $expires) {
        if (headers_sent()) return;
        $secure = mt_https();
        if (PHP_VERSION_ID >= 70300) {
            setcookie('dv_admin', $value, ['expires' => $expires, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        } else {
            setcookie('dv_admin', $value, $expires, '/; samesite=Lax', '', $secure, true);
        }
    }

    /** Secret used to sign the cookie: generated once and stored in site_config.remember_secret. */
    function mt_secret($conn) {
        static $secret = null;
        if ($secret !== null) return $secret;
        for ($try = 0; $try < 2; $try++) {
            $q = mysqli_query($conn, "SELECT config_value FROM site_config WHERE config_key = 'remember_secret' LIMIT 1");
            $row = $q ? mysqli_fetch_assoc($q) : null;
            if ($row && strlen((string)$row['config_value']) >= 32) return $secret = (string)$row['config_value'];
            $new = bin2hex(random_bytes(32));
            $stmt = mysqli_prepare($conn, "INSERT INTO site_config (config_key, config_value) VALUES ('remember_secret', ?) ON DUPLICATE KEY UPDATE config_value = ?");
            mysqli_stmt_bind_param($stmt, 'ss', $new, $new);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        throw new RuntimeException("Could not create remember_secret");
    }

    /** The signature is tied to the current password hash: changing the password invalidates every old cookie of that account. */
    function mt_sig($conn, $id, $exp, $pwHash) {
        return hash_hmac('sha256', (int)$id . '|' . (int)$exp . '|' . substr(hash('sha256', (string)$pwHash), 0, 32), mt_secret($conn));
    }

    function mt_issue($conn, $id, $pwHash, $days) {
        $exp = time() + $days * 86400;
        mt_set_cookie((int)$id . '.' . $exp . '.' . mt_sig($conn, $id, $exp, $pwHash), $exp);
    }

    /** A valid cookie returns that Admin's users row (with _exp added), otherwise null. */
    function mt_cookie_user($conn, $cookie) {
        if (!preg_match('/^(\d{1,10})\.(\d{9,11})\.([a-f0-9]{64})$/', (string)$cookie, $m)) return null;
        $id = (int)$m[1];
        $exp = (int)$m[2];
        if ($exp < time()) return null;
        $stmt = mysqli_prepare($conn, 'SELECT id, username, role, password FROM users WHERE id = ? AND role = 1 LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $rs = mysqli_stmt_get_result($stmt);
        $row = $rs ? mysqli_fetch_assoc($rs) : null;
        mysqli_stmt_close($stmt);
        if (!$row) return null;
        if (!hash_equals(mt_sig($conn, $id, $exp, $row['password']), $m[3])) return null;
        $row['_exp'] = $exp;
        return $row;
    }

    /**
     * Remember Admin:
     *  - Admin has just logged in: issue the cookie (once per session).
     *  - Session lost but a valid cookie remains: log that Admin back in automatically (no password needed).
     *  - Visiting the logout page: clear the cookie; this is the only way an Admin actively signs out.
     */
    function mt_remember($conn, $cfg, $isLogout) {
        $days = (isset($cfg['admin_remember_days']) && is_numeric($cfg['admin_remember_days']))
            ? (int)$cfg['admin_remember_days'] : (int)$GLOBALS['MT_REMEMBER_DAYS_DEFAULT'];
        $days = max(0, min(3650, $days));
        $cookie = isset($_COOKIE['dv_admin']) ? (string)$_COOKIE['dv_admin'] : '';

        if ($isLogout || $days === 0) {
            if ($cookie !== '') {
                mt_set_cookie('', time() - 86400);
                unset($_COOKIE['dv_admin']);
            }
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) return;

        $username = (string)($_SESSION['username'] ?? '');

        if ($username === '') {
            // Session lost: try to restore it from the cookie
            if ($cookie === '') return;
            $u = mt_cookie_user($conn, $cookie);
            if (!$u) {
                mt_set_cookie('', time() - 86400);
                return;
            }
            if (!headers_sent()) session_regenerate_id(true);
            $_SESSION['username'] = $u['username'];
            $_SESSION['_mt_rm'] = $u['username'];
            $_SESSION['last_activity'] = time();
            // extend (sliding)
            if ($u['_exp'] - time() < ($days - 1) * 86400) mt_issue($conn, $u['id'], $u['password'], $days);
            return;
        }

        // Already logged in: only check once per account in a session
        if (($_SESSION['_mt_rm'] ?? '') === $username) return;
        $_SESSION['_mt_rm'] = $username;

        $stmt = mysqli_prepare($conn, 'SELECT id, role, password FROM users WHERE username = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        $rs = mysqli_stmt_get_result($stmt);
        $row = $rs ? mysqli_fetch_assoc($rs) : null;
        mysqli_stmt_close($stmt);
        if (!$row || (int)$row['role'] !== 1) return;

        $u = $cookie !== '' ? mt_cookie_user($conn, $cookie) : null;
        if (!$u || (int)$u['id'] !== (int)$row['id'] || $u['_exp'] - time() < ($days - 1) * 86400) {
            mt_issue($conn, $row['id'], $row['password'], $days);
        }
    }

    function mt_discard_output() {
        while (ob_get_level() > 0) @ob_end_clean();
    }

    /* ------------------------------------------------------------------
     *  Maintenance page design
     * ------------------------------------------------------------------ */

    /** Float -> compact string for SVG (locale independent, trailing zeros trimmed). */
    function mt_num($v) {
        $s = rtrim(rtrim(sprintf('%.2F', $v), '0'), '.');
        return ($s === '' || $s === '-0') ? '0' : $s;
    }

    /** Text color placed on the primary color: white or black, chosen by brightness so it is always readable. */
    function mt_on_color($c) {
        if (!preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $c, $m)) return '#ffffff';
        $h = $m[1];
        if (strlen($h) <= 4) $h = preg_replace('/./', '$0$0', substr($h, 0, 3));
        $lin = [];
        foreach (str_split(substr($h, 0, 6), 2) as $pair) {
            $v = hexdec($pair) / 255;
            $lin[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }
        $lum = 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
        return $lum > 0.179 ? '#111827' : '#ffffff';
    }

    /** The color set of the page: taken from the site theme, defaults when missing. */
    function mt_theme($cfg) {
        $bg      = trim((string)($cfg['theme_bg'] ?? ''));
        $surface = trim((string)($cfg['theme_surface'] ?? ''));
        $text    = trim((string)($cfg['theme_text'] ?? ''));
        $accent  = mt_color($cfg['theme_primary'] ?? '', '#2563eb');
        return [
            'accent'   => $accent,
            'onAccent' => mt_on_color($accent),
            'bg'       => mt_color($bg, '#f5f6f8'),
            'surface'  => mt_color($surface, '#ffffff'),
            'text'     => mt_color($text, '#161a22'),
            'muted'    => mt_color($cfg['theme_muted'] ?? '', '#5d6675'),
            // true when the site has set its own background/text colors: then do not switch to the device's dark mode
            'custom'   => ($bg !== '' || $surface !== '' || $text !== ''),
        ];
    }

    /** A cut-out circle (subpath) for SVG, used with fill-rule evenodd. */
    function mt_circle($cx, $cy, $r) {
        $R = mt_num($r);
        return 'M' . mt_num($cx - $r) . ' ' . mt_num($cy)
            . 'a' . $R . ' ' . $R . ' 0 1 0 ' . mt_num(2 * $r) . ' 0'
            . 'a' . $R . ' ' . $R . ' 0 1 0 ' . mt_num(-2 * $r) . ' 0Z';
    }

    /**
     * Outline of one gear, centered at (0,0).
     * $n teeth, pitch circle radius $rp, module $m, $phase = angle of the first tooth (radians).
     * Trapezoid teeth: root width 0.56 of the pitch, tip width 0.28 of the pitch; tip height 0.8m, root depth 1.0m,
     * so two gears of the same module placed rp1 + rp2 apart still keep 0.2m of clearance and never touch.
     * Cut-outs: an axle hole in the middle and the empty cells between straight spokes (n/4 spokes, at least 3).
     */
    function mt_gear_path($n, $rp, $m, $phase) {
        $tau   = 2 * M_PI;
        $step  = $tau / $n;
        $ro    = $rp + 0.80 * $m;
        $rr    = $rp - 1.00 * $m;
        $hRoot = 0.28 * $step;
        $hTip  = 0.14 * $step;
        $pt    = function ($r, $a) { return mt_num($r * cos($a)) . ' ' . mt_num($r * sin($a)); };
        $arc   = 'A' . mt_num($rr) . ' ' . mt_num($rr) . ' 0 0 1 ';

        $d = '';
        for ($i = 0; $i < $n; $i++) {
            $c  = $phase + $i * $step;
            $d .= ($i === 0 ? 'M' : $arc) . $pt($rr, $c - $hRoot)
                . 'L' . $pt($ro, $c - $hTip)
                . 'L' . $pt($ro, $c + $hTip)
                . 'L' . $pt($rr, $c + $hRoot);
        }
        $d .= $arc . $pt($rr, $phase - $hRoot) . 'Z';

        $d .= mt_circle(0, 0, 0.14 * $rp);

        // Each empty cell sits between two spokes; the cell edges are parallel to the spokes so every spoke has an even width.
        $spokes = max(3, intdiv($n, 4));
        $half   = 0.07 * $rp;
        $r1     = 0.32 * $rp;
        $r2     = 0.70 * $rp;
        $a1     = asin($half / $r1);
        $a2     = asin($half / $r2);
        for ($k = 0; $k < $spokes; $k++) {
            $s0 = $phase + $k * $tau / $spokes;
            $s1 = $s0 + $tau / $spokes;
            $d .= 'M' . $pt($r1, $s0 + $a1)
                . 'A' . mt_num($r1) . ' ' . mt_num($r1) . ' 0 0 1 ' . $pt($r1, $s1 - $a1)
                . 'L' . $pt($r2, $s1 - $a2)
                . 'A' . mt_num($r2) . ' ' . mt_num($r2) . ' 0 0 0 ' . $pt($r2, $s0 + $a2)
                . 'Z';
        }
        return $d;
    }

    /**
     * A train of three meshing gears, returns an <svg> string.
     * Each row of $train: [tooth count, index of the parent gear, angle from the parent's center (degrees)].
     * Meshing rule: at the contact point, if the parent gear has a "phase" u (0 = middle of a tooth, 0.5 = middle of a gap)
     * then the child gear on the opposite side must have phase 0.5 - u. The two gears turn in opposite directions and the
     * rotation period is proportional to the tooth count, so the teeth always stay in step.
     */
    function mt_gears() {
        $m     = 7.0;
        $tau   = 2 * M_PI;
        $train = [[20, null, 0], [12, 0, -40], [16, 1, 20]];

        $g = [];
        foreach ($train as $i => $def) {
            list($n, $parent, $deg) = $def;
            $rp = $m * $n / 2;
            $gear = ['n' => $n, 'rp' => $rp, 'x' => 0.0, 'y' => 0.0, 'phase' => 0.0, 'dir' => 1];

            if ($parent === null) {
                // root gear: one tooth points straight at the first child gear
                foreach ($train as $child) {
                    if ($child[1] === $i) { $gear['phase'] = deg2rad($child[2]); break; }
                }
            } else {
                $p  = $g[$parent];
                $th = deg2rad($deg);
                $d  = $p['rp'] + $rp;
                $gear['x']   = $p['x'] + $d * cos($th);
                $gear['y']   = $p['y'] + $d * sin($th);
                $gear['dir'] = -$p['dir'];

                $u = fmod(($th - $p['phase']) / ($tau / $p['n']), 1.0);
                if ($u < 0) $u += 1.0;
                $v = fmod(1.5 - $u, 1.0);
                $gear['phase'] = $th + M_PI - $v * ($tau / $n);
            }
            $g[$i] = $gear;
        }

        // viewBox hugging the whole gear train
        $x0 = $y0 = INF;
        $x1 = $y1 = -INF;
        foreach ($g as $gear) {
            $ro = $gear['rp'] + 0.80 * $m + 3;
            $x0 = min($x0, $gear['x'] - $ro); $x1 = max($x1, $gear['x'] + $ro);
            $y0 = min($y0, $gear['y'] - $ro); $y1 = max($y1, $gear['y'] + $ro);
        }

        $svg = '<svg class="art__svg" viewBox="' . mt_num($x0) . ' ' . mt_num($y0) . ' ' . mt_num($x1 - $x0) . ' ' . mt_num($y1 - $y0)
             . '" aria-hidden="true" focusable="false">' . "\n";
        foreach ($g as $i => $gear) {
            $svg .= sprintf(
                '  <g transform="translate(%s %s)"><g class="spin%s" style="--dur:%ss"><path class="gear gear--%s" d="%s"/></g></g>' . "\n",
                mt_num($gear['x']),
                mt_num($gear['y']),
                $gear['dir'] < 0 ? ' spin--rev' : '',
                mt_num($gear['n'] * 1.8),
                $i === 0 ? 'solid' : 'line',
                mt_gear_path($gear['n'], $gear['rp'], $m, $gear['phase'])
            );
        }
        return $svg . '</svg>';
    }

    function mt_render($cfg, $notice, $isLoggedIn, $showLogin = true) {
        $logo    = trim((string)($cfg['logo_text'] ?? '')) ?: 'DEVNVIOS';
        $title   = trim((string)($cfg['maintenance_title'] ?? ''));
        if ($title === '') $title = "The system is being upgraded";
        if (function_exists('mb_substr')) $title = mb_substr($title, 0, 120, 'UTF-8');
        $madeBy  = trim((string)($cfg['footer_made_by'] ?? ''));
        $year    = trim((string)($cfg['footer_year'] ?? ''));
        $tele    = mt_url($cfg['link_tele_group'] ?? '');
        $contact = mt_url($cfg['link_admin_tele'] ?? '');

        $avatar = trim((string)($cfg['site_avatar'] ?? ''));
        if ($avatar !== '' && !preg_match('#^https?://[^\s"\'<>]+$#i', $avatar)) {
            $avatar = preg_match('#^[A-Za-z0-9_\-./]+$#', $avatar) ? '/' . ltrim($avatar, '/') : '';
        }

        $t = mt_theme($cfg);

        // Clean path of the current page (query string stripped). Not shown on the page anymore (the Reload button was removed) but kept for reuse.
        $reload = mt_url(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)) ?: '/';

        // The "Admin login" button: hidden by default. Turn it on with site_config.maintenance_show_login = 1.
        $loginLink = $showLogin && !$isLoggedIn && trim((string)($cfg['maintenance_show_login'] ?? '0')) === '1';
        $foot      = trim($madeBy . ' ' . $year);
        $gears     = mt_gears();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<?php if (!$t['custom']): ?><meta name="color-scheme" content="light dark">
<?php endif; ?>
<meta name="theme-color" content="<?= mt_h($t['bg']) ?>">
<title>Under maintenance - <?= mt_h($logo) ?></title>
<style>
/* tokens */
:root {
  --accent: <?= mt_h($t['accent']) ?>;
  --on-accent: <?= mt_h($t['onAccent']) ?>;
  --bg: <?= mt_h($t['bg']) ?>;
  --surface: <?= mt_h($t['surface']) ?>;
  --text: <?= mt_h($t['text']) ?>;
  --muted: <?= mt_h($t['muted']) ?>;
  --ok: #15803d;
  --line: rgba(128, 128, 128, .32);
  --font: "Segoe UI Variable Text", "Segoe UI", system-ui, -apple-system, "Helvetica Neue", Roboto, Arial, sans-serif;
  --serif: "Iowan Old Style", "Palatino Linotype", Palatino, "Book Antiqua", Georgia, "Times New Roman", serif;
  --mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace;
}
<?php if (!$t['custom']): ?>
@media (prefers-color-scheme: dark) {
  :root { --bg: #0e1218; --surface: #171c25; --text: #e9ecf2; --muted: #9ba5b5; --ok: #4ade80; }
}
<?php endif; ?>

/* base */
*, *::before, *::after { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; text-size-adjust: 100%; background: var(--bg); }
body {
  margin: 0;
  min-height: 100vh; min-height: 100dvh;
  background: var(--bg); color: var(--text);
  font: 16px/1.6 var(--font);
  -webkit-font-smoothing: antialiased;
}

/* layout: one compact, centered screen */
.page {
  display: flex; flex-direction: column;
  width: 100%; max-width: 560px; min-height: 100vh; min-height: 100dvh; margin: 0 auto;
  padding: calc(18px + env(safe-area-inset-top, 0px)) calc(24px + env(safe-area-inset-right, 0px))
           calc(18px + env(safe-area-inset-bottom, 0px)) calc(24px + env(safe-area-inset-left, 0px));
}
.head { display: flex; align-items: center; justify-content: center; gap: 10px; min-width: 0; }
.head__logo { width: 30px; height: 30px; flex: none; border-radius: 8px; object-fit: cover; background: var(--surface); border: 1px solid var(--line); }
.head__name { min-width: 0; font-size: .9375rem; font-weight: 700; letter-spacing: -.01em; overflow-wrap: anywhere; }

.stage { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 24px 0; text-align: center; }
.art { width: min(190px, 52vw); margin: 0 0 28px; }
.art__svg { display: block; width: 100%; height: auto; overflow: visible; }

/* copy */
.copy { width: 100%; max-width: 28rem; }
h1 {
  margin: 0 0 12px;
  font-size: clamp(1.5rem, 1.15rem + 1.6vw, 2rem);
  line-height: 1.2; font-weight: 750; letter-spacing: -.02em;
  text-wrap: balance;
}
.notice { margin: 0; color: var(--muted); font-size: 1rem; line-height: 1.6; white-space: pre-line; overflow-wrap: anywhere; text-wrap: pretty; }

.actions { display: flex; justify-content: center; margin-top: 24px; }
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  height: 46px; padding: 0 24px;
  border: 1px solid var(--line); border-radius: 12px; background: transparent; color: var(--text);
  font: inherit; font-size: .9375rem; font-weight: 600; text-decoration: none; white-space: nowrap;
  -webkit-tap-highlight-color: transparent;
  transition: background-color .15s, border-color .15s, filter .15s;
}
.btn:hover { background: var(--surface); border-color: var(--muted); }
.btn:active { transform: translateY(1px); }
.btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
.btn svg { width: 18px; height: 18px; flex: none; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.btn--solid { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
.btn--solid:hover { background: var(--accent); border-color: var(--accent); filter: brightness(1.08); }

.foot { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px 16px; color: var(--muted); font-size: .8125rem; }
.foot a { color: inherit; text-underline-offset: 3px; }
.foot a:hover { color: var(--text); }
.foot a:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; border-radius: 2px; }

/* gears: the only moving part */
.gear { fill-rule: evenodd; }
.gear--solid { fill: var(--accent); }
.gear--line { fill: var(--accent); fill-opacity: .16; stroke: var(--accent); stroke-width: 2; stroke-linejoin: round; }
.spin { transform-box: view-box; transform-origin: 0 0; animation: spin var(--dur, 30s) linear infinite; }
.spin--rev { animation-direction: reverse; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .spin { animation: none; } }

@media (max-width: 480px) {
  .btn { width: 100%; }
}
</style>
<script id="dv-hash">(function(){try{var CS="ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789",N=5,K="dvmap",cur=location.pathname+location.search,base=location.origin+cur,m={};try{m=JSON.parse(sessionStorage.getItem(K)||"{}")}catch(e){}var h=location.hash.slice(1);if(h&&m[h]&&m[h].split("?")[0]!==location.pathname){location.replace(m[h]+"#"+h);return}if(!h||!m[h]){h="";for(var i=0;i<N;i++)h+=CS.charAt(Math.floor(Math.random()*CS.length))}m[h]=cur;try{sessionStorage.setItem(K,JSON.stringify(m))}catch(e){}var of=window.fetch;if(of)window.fetch=function(u,o){try{if(typeof u==="string")u=new URL(u,base).href}catch(e){}return of.call(this,u,o)};var ox=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(){try{if(typeof arguments[1]==="string")arguments[1]=new URL(arguments[1],base).href}catch(e){}return ox.apply(this,arguments)};document.addEventListener("submit",function(e){try{var f=e.target;if(f&&f.tagName==="FORM")f.setAttribute("action",new URL(f.getAttribute("action")||"",base).href)}catch(x){}},true);document.addEventListener("click",function(e){try{var a=e.target.closest&&e.target.closest("a[href]");if(a){var r=a.getAttribute("href");if(r&&!/^([a-z][a-z0-9+.-]*:|\/|#)/i.test(r))a.setAttribute("href",new URL(r,base).href)}}catch(x){}},true);var go=location.origin+"/#"+h,fix=function(){try{if(location.href!==go)history.replaceState(null,"","/#"+h)}catch(e){}};window.addEventListener("load",function(){fix();setInterval(fix,700)});window.addEventListener("pageshow",fix);window.addEventListener("pagehide",fix);window.addEventListener("popstate",function(){setTimeout(fix,0)});window.addEventListener("hashchange",function(){setTimeout(fix,0)})}catch(e){}})();</script></head>
<body>
<div class="page">
  <header class="head">
    <?php if ($avatar !== ''): ?><img class="head__logo" src="<?= mt_h($avatar) ?>" alt="" width="30" height="30" onerror="this.style.display='none'"><?php endif; ?>
    <span class="head__name"><?= mt_h($logo) ?></span>
  </header>

  <main class="stage">
    <div class="art">
<?= $gears ?>

    </div>
    <div class="copy">
      <h1><?= mt_h($title) ?></h1>
      <p class="notice"><?= mt_h($notice) ?></p>
<?php if ($tele !== '' || $contact !== ''): ?>
      <div class="actions">
        <?php if ($tele !== ''): ?>
        <a class="btn btn--solid" href="<?= mt_h($tele) ?>" target="_blank" rel="noopener">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
          <span>Telegram group</span>
        </a>
        <?php elseif ($contact !== ''): ?>
        <a class="btn btn--solid" href="<?= mt_h($contact) ?>" target="_blank" rel="noopener">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
          <span>Contact Admin</span>
        </a>
        <?php endif; ?>
      </div>
<?php endif; ?>
    </div>
  </main>
<?php if ($foot !== '' || $isLoggedIn || $loginLink): ?>

  <footer class="foot">
    <span><?= mt_h($foot) ?></span>
    <?php if ($isLoggedIn): ?>
    <a href="/logout">Log out</a>
    <?php elseif ($loginLink): ?>
    <a href="/dang-nhap">Admin login</a>
    <?php endif; ?>
  </footer>
<?php endif; ?>
</div>
</body>
</html>
<?php
    }

    function mt_run($conn) {
        if (PHP_SAPI === 'cli') return;

        // 1) Read the configuration. On a DB error do NOT block the page (avoids locking the whole site because of a small error).
        $cfg = [];
        try {
            $q = mysqli_query($conn, "SELECT config_key, config_value FROM site_config");
            while ($q && ($r = mysqli_fetch_assoc($q))) $cfg[$r['config_key']] = $r['config_value'];
        } catch (Throwable $e) {
            error_log('[check_maintenance] ' . $e->getMessage());
            return;
        }

        // Backward compatibility: db.php declares $st = [] so load the config into it for old pages that share it
        if (isset($GLOBALS['st']) && is_array($GLOBALS['st'])) {
            $pub = $cfg;
            // cookie signing secret + maintenance gateway token/password: do not put them in $st
            unset($pub['remember_secret'], $pub['maintenance_access_token'], $pub['maintenance_access_hash'], $pub['maintenance_access_user']);
            $GLOBALS['st'] = array_merge($GLOBALS['st'], $pub);
        }

        $script = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
        $path   = strtolower(trim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/'));
        $pages  = [$script]; // the URL path is not used any more: "/anything/login" must not slip past the maintenance page
        $scriptFull = strtolower(trim((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/'));
        // Some servers (IIS...) put PATH_INFO into SCRIPT_NAME (e.g. /maintenance_access.php/<token>): also take the "xxx.php" segment so it still matches the allow list
        if (preg_match('#(?:^|/)([^/]+\.php)(?:/|$)#', $scriptFull, $mm)) $pages[] = $mm[1];
        // a script inside a folder (for example admin_cp/index.php): also take the folder name
        if (strpos($scriptFull, '/') !== false) $pages[] = strtok($scriptFull, '/');
        $logoutPages = isset($GLOBALS['MT_LOGOUT_PAGES']) && is_array($GLOBALS['MT_LOGOUT_PAGES']) ? $GLOBALS['MT_LOGOUT_PAGES'] : [];
        $allow       = isset($GLOBALS['MT_ALLOW_PAGES']) && is_array($GLOBALS['MT_ALLOW_PAGES']) ? $GLOBALS['MT_ALLOW_PAGES'] : [];
        $isLogout    = (bool)array_intersect($pages, $logoutPages);

        // 2) Remember Admin (runs even when NOT in maintenance): restore the session from the cookie if it was lost, issue the cookie when an Admin is logged in
        try {
            mt_remember($conn, $cfg, $isLogout);
        } catch (Throwable $e) {
            error_log('[check_maintenance] remember: ' . $e->getMessage());
        }

        if (trim((string)($cfg['maintenance_mode'] ?? '0')) !== '1') return;

        // The logout page is always open. The login pages are open, unless admin_ip_allow is filled in and this IP is not in it.
        $ipAllow   = array_filter(array_map('trim', explode(',', (string)($cfg['admin_ip_allow'] ?? ''))), 'strlen');
        $loginOpen = !$ipAllow || in_array(mt_client_ip(), $ipAllow, true);
        if ($isLogout && (string)($_SESSION['username'] ?? '') !== '') return;
        if ($loginOpen && array_intersect($pages, $allow)) return;

        // 3) Admins are let in
        $username = (string)($_SESSION['username'] ?? '');
        if ($username !== '') {
            try {
                $stmt = mysqli_prepare($conn, 'SELECT role FROM users WHERE username = ? LIMIT 1');
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 's', $username);
                    mysqli_stmt_execute($stmt);
                    $rs  = mysqli_stmt_get_result($stmt);
                    $row = $rs ? mysqli_fetch_assoc($rs) : null;
                    mysqli_stmt_close($stmt);
                    if ($row && (int)$row['role'] === 1) return;
                }
            } catch (Throwable $e) {
                error_log('[check_maintenance] role: ' . $e->getMessage());
            }
        }

        // 4) Block
        $notice = trim((string)($cfg['maintenance_notice'] ?? ''));
        if ($notice === '') $notice = "The system is under maintenance to upgrade our service. Please come back in a few minutes!";
        $notice = str_replace(['\\n', "\r\n", "\r"], "\n", $notice);

        $accept   = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $wantsJson = isset($_GET['ajax']) || isset($_GET['sync'])
            || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || strpos($accept, 'application/json') !== false;

        mt_discard_output();
        http_response_code(503);
        header('Retry-After: 300');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow');

        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['result' => 'maintenance', 'message' => $notice], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        mt_render($cfg, $notice, $username !== '', $loginOpen && !empty($GLOBALS['MT_LOGIN_OPEN']));
        exit;
    }
}

if (isset($conn) && $conn instanceof mysqli) {
    mt_run($conn);
}
