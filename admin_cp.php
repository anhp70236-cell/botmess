<?php
/* admin_cp.php - ban nang cap: giao dien toi, logo lap loa, quan ly theme (anh banner, avatar, anh xem truoc, bao tri tung tool).
   File nay chi chua ky tu ASCII: chu Viet da ma hoa san, luu kieu nao cung khong loi font.
   Cac chuc nang cu (kho key, thanh vien, bao tri toan web, cau hinh nang cao, nhat ky) giu nguyen.
   Moi thu moi deu luu trong bang site_config (khong can them cot / bang): ds_tool_meta, ds_hero_images, ds_avatar_url, ds_*, ds_t_*. */
include 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
mysqli_set_charset($conn, 'utf8mb4');

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

/* Giong index.php: de trong = dung REMOTE_ADDR. Dung Cloudflare thi dat 'HTTP_CF_CONNECTING_IP' */
const ADMIN_CLIENT_IP_HEADER = '';
/* Duong dan link bi mat khi bao tri */
const MT_ACCESS_LINK_PATH = '/maintenance_access.php/';
/* Logo mac dinh (giong index.php) neu site_config.brand_logo trong */
const ADMIN_DEFAULT_LOGO = 'https://i.postimg.cc/8CzFDHdT/IMG-1246.png';

/* ================= HAM TIEN ICH ================= */
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function slen($s) { return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s); }
function scut($s, $n) { return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n); }

function run($conn, $sql, $types = '', $args = []) {
    $st = mysqli_prepare($conn, $sql);
    if (!$st) throw new RuntimeException("Lỗi truy vấn: " . mysqli_error($conn));
    if ($types !== '') mysqli_stmt_bind_param($st, $types, ...$args);
    if (!mysqli_stmt_execute($st)) {
        $err = mysqli_stmt_error($st);
        mysqli_stmt_close($st);
        throw new RuntimeException("Lỗi truy vấn: " . $err);
    }
    return $st;
}
function one($conn, $sql, $types = '', $args = []) {
    $st = run($conn, $sql, $types, $args);
    $rs = mysqli_stmt_get_result($st);
    $row = $rs ? mysqli_fetch_assoc($rs) : null;
    mysqli_stmt_close($st);
    return $row;
}
function fetch_all($conn, $sql) {
    $o = [];
    try {
        $q = mysqli_query($conn, $sql);
        while ($q && $r = mysqli_fetch_assoc($q)) $o[] = $r;
    } catch (Throwable $ex) {
        error_log('[admin_cp] fetch_all: ' . $ex->getMessage());
    }
    return $o;
}
function load_cfg($conn) {
    $o = [];
    foreach (fetch_all($conn, "SELECT config_key, config_value FROM site_config") as $r) $o[$r['config_key']] = $r['config_value'];
    return $o;
}

function flash($m, $t = 'ok') { $_SESSION['flash'] = ['m' => $m, 't' => $t]; }
function go($tab) { header('Location: admin_cp.php#' . $tab); exit; }
function cfg_set($conn, $k, $v) {
    run($conn, "INSERT INTO site_config (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = ?", 'sss', [$k, $v, $v]);
}
function bad_link($u) { return (bool)preg_match('/^\s*(javascript|data|vbscript):/i', $u); }
/* Khop voi safe_url() cua index.php: chi nhan http(s):// hoac duong dan noi bo */
function valid_url($u) {
    $u = trim((string)$u);
    if ($u === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $u)) return false;
    return (bool)(preg_match('#^https?://[^/\s]#i', $u) || preg_match('#^/(?!/)#', $u));
}
function img_ok($u) { return !bad_link($u) && valid_url($u); }
/* Khop voi css_color() cua index.php */
function valid_color($v) {
    return (bool)(preg_match('/^#[0-9a-f]{3,8}$/i', $v)
        || preg_match('/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9.\s,%\/-]+\)$/i', $v)
        || preg_match('/^[a-z]{3,20}$/i', $v));
}

function client_ip() {
    $ip = '';
    if (ADMIN_CLIENT_IP_HEADER !== '' && !empty($_SERVER[ADMIN_CLIENT_IP_HEADER])) {
        $ip = trim(explode(',', (string)$_SERVER[ADMIN_CLIENT_IP_HEADER])[0]);
    }
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return substr($ip, 0, 45);
}

function col_exists($conn, $table, $col) {
    static $cache = [];
    $k = $table . '.' . $col;
    if (!isset($cache[$k])) {
        try {
            $r = one($conn, "SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?", 'ss', [$table, $col]);
            $cache[$k] = $r && (int)$r['c'] > 0;
        } catch (Throwable $ex) { $cache[$k] = false; }
    }
    return $cache[$k];
}
function table_exists($conn, $table) {
    static $cache = [];
    if (!isset($cache[$table])) {
        try {
            $r = one($conn, "SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", 's', [$table]);
            $cache[$table] = $r && (int)$r['c'] > 0;
        } catch (Throwable $ex) { $cache[$table] = false; }
    }
    return $cache[$table];
}

/* Nhat ky thao tac admin (bang admin_logs). Loi ghi nhat ky khong lam hong thao tac chinh. */
function admin_log($conn, $action, $detail = '') {
    try {
        if (!table_exists($conn, 'admin_logs')) return;
        $uid = isset($GLOBALS['ADMIN_ID']) ? (int)$GLOBALS['ADMIN_ID'] : null;
        $st = run($conn, "INSERT INTO admin_logs (user_id, username, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
            'issss', [$uid, (string)($GLOBALS['ADMIN_NAME'] ?? ''), scut($action, 100), scut((string)$detail, 2000), client_ip()]);
        mysqli_stmt_close($st);
    } catch (Throwable $ex) {
        error_log('[admin_cp] admin_log: ' . $ex->getMessage());
    }
}

/* Mat khau truy cap bao tri: 24 ky tu ngau nhien, bo I L O 0 1, chia nhom 4 */
function mtx_gen_password() {
    $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $max = strlen($alpha) - 1;
    $raw = '';
    for ($i = 0; $i < 24; $i++) $raw .= $alpha[random_int(0, $max)];
    return implode('-', str_split($raw, 4));
}
function mtx_access_enable($conn, $username) {
    $token = bin2hex(random_bytes(48));
    $pw    = mtx_gen_password();
    cfg_set($conn, 'maintenance_access_token', $token);
    cfg_set($conn, 'maintenance_access_hash', password_hash(str_replace('-', '', $pw), PASSWORD_DEFAULT));
    cfg_set($conn, 'maintenance_access_user', (string)$username);
    $_SESSION['mt_pw_plain'] = $pw;
}
function mtx_access_disable($conn) {
    run($conn, "DELETE FROM site_config WHERE config_key IN ('maintenance_access_token', 'maintenance_access_hash', 'maintenance_access_user')");
    unset($_SESSION['mt_pw_plain']);
}

function deny_page($title, $msg) {
    http_response_code(403);
    echo '<!DOCTYPE html><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><body style="font-family:sans-serif;padding:40px;background:#0b0d12;color:#e9edf5"><h2>' . e($title) . '</h2><p style="color:#8e98ad">' . e($msg) . '</p><a href="index.php" style="color:#4f8cff;text-decoration:none">' . "← Về trang chủ" . '</a></body>';
    exit;
}

function save_image($f, $dir, $prefix) {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return [null, "Chưa chọn ảnh hoặc tải ảnh lên thất bại."];
    if ($f['size'] > 3 * 1024 * 1024) return [null, "Ảnh vượt quá dung lượng 3MB."];
    $info = @getimagesize($f['tmp_name']);
    $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($map[$info[2]])) return [null, "Định dạng không hỗ trợ (chỉ nhận JPG, PNG, GIF, WEBP)."];
    $path = $dir . $prefix . time() . '_' . bin2hex(random_bytes(3)) . '.' . $map[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], $path)) return [null, "Không thể lưu tệp vào máy chủ."];
    return [$path, null];
}

function safe_unlink($p, $dir) {
    if (!$p) return;
    $rp = realpath($p); $rd = realpath($dir);
    if ($rp && $rd && strpos($rp, $rd) === 0 && is_file($rp)) @unlink($rp);
}

/* ---- Ham moi: file tai len, anh theme ---- */
function pub_path($p) { return '/' . ltrim((string)$p, '/'); }
function upload_local($url, $dir) {
    $url = (string)$url;
    $d = '/' . trim($dir, '/') . '/';
    return strpos($url, $d) === 0 ? ltrim($url, '/') : '';
}
/* Chi xoa file do admin_cp tao ra (ten bat dau bang tien to cho truoc), khong dung toi file khac */
function rm_upload($url, $dir, array $prefixes) {
    $p = upload_local($url, $dir);
    if ($p === '') return;
    $b = basename($p);
    foreach ($prefixes as $x) {
        if (strpos($b, $x) === 0) { safe_unlink($p, $dir); return; }
    }
}
function files_multi($key) {
    $out = [];
    if (empty($_FILES[$key]) || !is_array($_FILES[$key]['name'])) return $out;
    $f = $_FILES[$key];
    foreach ($f['name'] as $i => $n) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $out[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}
function file_one($key, $idx) {
    if (empty($_FILES[$key]) || !isset($_FILES[$key]['name'][$idx])) return null;
    $f = $_FILES[$key];
    if (($f['error'][$idx] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    return ['name' => $f['name'][$idx], 'type' => $f['type'][$idx], 'tmp_name' => $f['tmp_name'][$idx], 'error' => $f['error'][$idx], 'size' => $f['size'][$idx]];
}
function hero_list($cf) {
    return array_values(preg_split('/[\s,]+/', (string)($cf['ds_hero_images'] ?? ''), -1, PREG_SPLIT_NO_EMPTY));
}
function meta_load($cf) {
    $raw = (string)($cf['ds_tool_meta'] ?? '');
    $j = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($j) ? $j : [];
}
function meta_save($conn, $meta) {
    foreach ($meta as $k => $v) { if (empty($v)) unset($meta[$k]); }
    cfg_set($conn, 'ds_tool_meta', json_encode($meta, JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function ic($n) {
    static $p = [
        'trash'    => '<path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v6M14 11v6"/>',
        'power'    => '<path d="M18.36 6.64a9 9 0 1 1-12.73 0M12 2v10"/>',
        'out'      => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/>',
        'copy'     => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'search'   => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>',
        'check'    => '<polyline points="20 6 9 17 4 12"/>',
        'tool'     => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'key'      => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3M18.5 4.5l3 3"/>',
        'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'alert'    => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'plus'     => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon'     => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        'image'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
        'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'up'       => '<path d="m18 15-6-6-6 6"/>',
        'down'     => '<path d="m6 9 6 6 6-6"/>',
        'palette'  => '<circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><circle cx="6.5" cy="12.5" r="1.5"/><path d="M12 2a10 10 0 0 0 0 20c1.1 0 2-.9 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.3 0-1.1.9-2 2-2H18a4 4 0 0 0 4-4c0-5.5-4.5-9.4-10-9.4z"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>'
    ];
    return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
}

function csrf() { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }

/*ADMX-HELPERS-BEGIN*/
function admx_lock_file() { return __DIR__ . DIRECTORY_SEPARATOR . 'ds_key_daily_lock.php'; }
function admx_lock_hours() {
    static $h = null;
    if ($h !== null) return $h;
    $h = 8;
    $f = __DIR__ . DIRECTORY_SEPARATOR . 'index.php';
    if (is_file($f) && is_readable($f)) {
        $src = @file_get_contents($f, false, null, 0, 800000);
        if (is_string($src) && preg_match('/KEY_DAILY_LIMIT_HOURS[\'"]?\s*[,=]\s*(\d{1,3})/', $src, $mm) && (int)$mm[1] > 0) $h = (int)$mm[1];
    }
    return $h;
}
function admx_lock_load() {
    $f = admx_lock_file();
    if (!is_file($f)) return [];
    $raw = @file_get_contents($f);
    if (!is_string($raw)) return [];
    if (strncmp($raw, '<?php', 5) === 0) { $p = strpos($raw, "\n"); $raw = ($p === false) ? '' : substr($raw, $p + 1); }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function admx_lock_save($d) {
    return @file_put_contents(admx_lock_file(), "<?php http_response_code(404); exit; ?>\n" . json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}
function admx_lock_root($d) {
    if (!empty($d['roll']) && is_array($d['roll'])) return 'roll';
    $t = date('Y-m-d');
    if (!empty($d[$t]) && is_array($d[$t])) return $t;
    return '';
}
/* Moi "luot kich hoat key" ghi IP + tai khoan + key cung 1 giay => gom theo moc thoi gian */
function admx_lock_events($conn) {
    $d = admx_lock_load();
    $root = admx_lock_root($d);
    if ($root === '') return [[], $root];
    $hours = admx_lock_hours();
    $now = time();
    $endDay = strtotime(date('Y-m-d') . ' 23:59:59');
    $ev = [];
    foreach (['ips', 'users', 'keys'] as $g) {
        foreach ((array)($d[$root][$g] ?? []) as $k => $ts) {
            $ts = (int)$ts;
            if ($ts <= 0) continue;
            $until = ($root === 'roll') ? $ts + $hours * 3600 : $endDay;
            if ($until <= $now) continue;
            if (!isset($ev[$ts])) $ev[$ts] = ['ts' => $ts, 'until' => $until, 'ips' => [], 'users' => [], 'keys' => []];
            $ev[$ts][$g][] = (string)$k;
        }
    }
    krsort($ev);
    $ids = [];
    foreach ($ev as $x) {
        foreach ($x['users'] as $uk) {
            if (preg_match('/^u_(\d+)$/', $uk, $mm)) $ids[(int)$mm[1]] = 1;
        }
    }
    $names = [];
    if ($ids) {
        foreach (fetch_all($conn, "SELECT id, username FROM users WHERE id IN (" . implode(',', array_map('intval', array_keys($ids))) . ")") as $r) {
            $names[(int)$r['id']] = (string)$r['username'];
        }
    }
    foreach ($ev as $ts => $x) {
        $lab = [];
        foreach ($x['users'] as $uk) {
            $nm = $uk;
            if (preg_match('/^u_(\d+)$/', $uk, $mm) && isset($names[(int)$mm[1]])) $nm = $names[(int)$mm[1]];
            elseif (strncmp($uk, 'u_', 2) === 0) $nm = substr($uk, 2);
            $lab[] = ['key' => $uk, 'name' => $nm];
        }
        $ev[$ts]['users'] = $lab;
    }
    return [array_values($ev), $root];
}
function admx_lock_remove($tsList) {
    $d = admx_lock_load();
    $root = admx_lock_root($d);
    if ($root === '') return 0;
    $set = [];
    foreach ((array)$tsList as $t) $set[(int)$t] = true;
    $n = 0;
    foreach (['ips', 'users', 'keys'] as $g) {
        if (empty($d[$root][$g]) || !is_array($d[$root][$g])) continue;
        foreach (array_keys($d[$root][$g]) as $k) {
            if (isset($set[(int)$d[$root][$g][$k]])) { unset($d[$root][$g][$k]); $n++; }
        }
    }
    if ($n > 0 && !admx_lock_save($d)) throw new RuntimeException("Không ghi được file khoá (ds_key_daily_lock.php). Hãy kiểm tra quyền ghi của thư mục web.");
    return $n;
}
function admx_lock_unlock_users($uidKeys) {
    $d = admx_lock_load();
    $root = admx_lock_root($d);
    if ($root === '') return 0;
    $want = [];
    foreach ((array)$uidKeys as $k) $want[strtolower((string)$k)] = true;
    $tsList = [];
    $n = 0;
    foreach (array_keys((array)($d[$root]['users'] ?? [])) as $k) {
        if (isset($want[strtolower((string)$k)])) { $tsList[] = (int)$d[$root]['users'][$k]; unset($d[$root]['users'][$k]); $n++; }
    }
    foreach (['ips', 'keys', 'users'] as $g) {
        if (empty($d[$root][$g]) || !is_array($d[$root][$g])) continue;
        foreach (array_keys($d[$root][$g]) as $k) {
            if (in_array((int)$d[$root][$g][$k], $tsList, true)) { unset($d[$root][$g][$k]); $n++; }
        }
    }
    if ($n > 0 && !admx_lock_save($d)) throw new RuntimeException("Không ghi được file khoá (ds_key_daily_lock.php). Hãy kiểm tra quyền ghi của thư mục web.");
    return $n;
}
function admx_lock_clear_all() {
    $d = admx_lock_load();
    $root = admx_lock_root($d);
    if ($root === '') return 0;
    $n = 0;
    foreach (['ips', 'users', 'keys'] as $g) {
        $n += count((array)($d[$root][$g] ?? []));
        $d[$root][$g] = [];
    }
    if ($n > 0 && !admx_lock_save($d)) throw new RuntimeException("Không ghi được file khoá (ds_key_daily_lock.php). Hãy kiểm tra quyền ghi của thư mục web.");
    return $n;
}
/* Moc mo khoa: theme bo qua khoa luu tren may nguoi dung (localStorage / cookie) cu hon moc nay */
function admx_unlock_epoch($conn) { cfg_set($conn, 'ds_key_unlock_epoch', (string)(int)(microtime(true) * 1000 + 600000)); }
function admx_user_lock($m, $map) {
    $a = 'u_' . (int)($m['id'] ?? 0);
    $b = 'u_' . strtolower(trim((string)($m['username'] ?? '')));
    if (isset($map[$a])) return $map[$a];
    if (isset($map[$b])) return $map[$b];
    return 0;
}
function admx_left_txt($until, $now) {
    $d = (int)$until - (int)$now;
    if ($d <= 0) return 'đã hết khoá';
    $h = intdiv($d, 3600);
    $mi = intdiv($d % 3600, 60);
    return 'còn ' . ($h > 0 ? $h . ' giờ ' : '') . $mi . ' phút';
}
/*ADMX-HELPERS-END*/
/*FKX-LIB-BEGIN*/
$FKX_ON = false;
try { include_once __DIR__ . '/fkx_lib.php'; $FKX_ON = function_exists('fkx_do'); } catch (Throwable $fkxEx) { error_log('[admin_cp] fkx_lib: ' . $fkxEx->getMessage()); }
function fkx_ui($what, $arg = null) {
    global $FKX_ON;
    $fn = 'fkx_html_' . $what;
    if (!$FKX_ON || !function_exists($fn)) return '';
    $lvl = ob_get_level();
    try {
        return (string)$fn($arg);
    } catch (Throwable $fkxEx) {
        while (ob_get_level() > $lvl) ob_end_clean();
        error_log('[admin_cp] fkx ' . $what . ': ' . $fkxEx->getMessage());
        return '';
    }
}
/*FKX-LIB-END*/


/* Tinh theo dong ho MySQL ($ts, $now deu la UNIX timestamp tu MySQL) nen khong lech mui gio PHP/MySQL */
function remain($ts, $now) {
    $d = (int)$ts - (int)$now;
    if ($d <= 0) return '';
    if ($d >= 86400) return "còn " . floor($d / 86400) . " ngày";
    if ($d >= 3600) return "còn " . floor($d / 3600) . " giờ";
    return "còn " . max(1, floor($d / 60)) . " phút";
}

/* ================= CAU HINH NANG CAO (index.php / check_maintenance.php doc) =================
   Moi khoa: [nhan, kieu, tham so, goi y]. Kieu: text | textarea | url | color | bool | num | select | ip_list | name_list | country
   Kieu cho theme: tri (Mac dinh / Bat / Tat) | numr (so hoac de trong) | dec (so thap phan hoac de trong) */
$ADV = [
    "Liên hệ & Liên kết" => [
        'link_admin_tele' => ["Link Telegram Admin (nút \"Mua key vip liên hệ\")", 'url'],
    ],
    "Thanh thông báo & Bảo trì" => [
        'show_announcement'  => ["Hiện thanh thông báo trên trang chủ", 'bool'],
        'site_announcement'  => ["Nội dung thanh thông báo", 'textarea', 500],
        'announce_bg'        => ["Màu nền thanh thông báo", 'color'],
        'announce_text'      => ["Màu chữ thanh thông báo", 'color'],
        'maintenance_notice' => ["Nội dung hiện ở trang bảo trì", 'textarea', 500],
        'maintenance_title'  => ["Tiêu đề trang bảo trì (trống = \"Hệ thống đang được nâng cấp\")", 'text'],
        'maintenance_show_login' => ["Hiện nút \"Đăng nhập Admin\" ở trang bảo trì (mặc định Tắt, admin vào bằng link bí mật)", 'bool'],
    ],
    "Chữ trên trang (index.php)" => [
        'banner_title'        => ["Tiêu đề banner", 'text'],
        'chao_khach'          => ["Lời chào khách chưa đăng nhập", 'text'],
        'xin_chao'            => ["Lời chào khi đã đăng nhập", 'text'],
        'text_key_vip'        => ["Tiêu đề ô Key VIP", 'text'],
        'text_key_free'       => ["Tiêu đề ô Key Free", 'text'],
        'text_kich_hoat'      => ["Nút kích hoạt", 'text'],
        'text_xem_them'       => ["Nút xem thêm", 'text'],
        'text_telegram_group' => ["Chữ link nhóm Telegram", 'text'],
        'text_buy_vip'        => ["Chữ mua key VIP", 'text'],
        'text_buy_vip_link'   => ["Chữ link mua key VIP", 'text'],
        'text_get_key_free'   => ["Chữ lấy Key Free", 'text'],
        'placeholder_key_vip' => ["Gợi ý trong ô nhập Key VIP", 'text'],
        'placeholder_key_free'=> ["Gợi ý trong ô nhập Key Free", 'text'],
        'text_menu_home'      => ["Menu: Trang chủ", 'text'],
        'text_menu_category'  => ["Menu: Danh mục", 'text'],
        'text_menu_admin'     => ['Menu: Admin Cpanel', 'text'],
        'text_menu_logout'    => ["Menu: Đăng xuất", 'text'],
        'text_login'          => ["Chữ Đăng nhập", 'text'],
        'text_register'       => ["Chữ Đăng ký", 'text'],
        'text_member'         => ["Chữ Thành viên", 'text'],
        'text_turn_word'      => ["Chữ \"lượt\"", 'text'],
        'text_footer_made'    => ['Footer: Made with', 'text'],
        'text_footer_by'      => ['Footer: by', 'text'],
    ],
    "Logo, tên tab & SEO (index.php)" => [
        'brand_logo'      => ["Logo trên thanh đầu trang (link ảnh)", 'url'],
        'favicon_url'     => ["Icon tab trình duyệt (link ảnh)", 'url'],
        'favicon_bg'      => ["Màu nền icon tab (trống = mặc định, \"none\" = trong suốt)", 'text'],
        'site_title'      => ["Tên trang (tab)", 'text'],
        'seo_title'       => ["Tiêu đề Google / khi chia sẻ link", 'text'],
        'seo_description' => ["Mô tả Google / khi chia sẻ link", 'textarea', 400],
        'seo_site_name'   => ["Tên thương hiệu cho Google", 'text'],
        'site_url'        => ["Địa chỉ website (https://...)", 'url'],
    ],
    "Avatar & Khung banner (index.php)" => [
        'avatar_shape'       => ["Hình dạng avatar", 'select', ['circle', 'rounded', 'square']],
        'avatar_size'        => ["Cỡ avatar (48 - 110 px)", 'num', [48, 110]],
        'avatar_wings'       => ["Hiện cánh đại bàng", 'bool'],
        'avatar_frame_c1'    => ["Viền avatar: màu đầu", 'color'],
        'avatar_frame_c2'    => ["Viền avatar: màu cuối", 'color'],
        'banner_bg'          => ["Nền khung banner (trống = mặc định)", 'color'],
        'banner_border'      => ["Viền khung banner (trống = mặc định)", 'color'],
        'banner_radius'      => ["Bo góc khung banner (0 - 40 px)", 'num', [0, 40]],
        'banner_title_color' => ["Màu chữ tiêu đề banner", 'color'],
        'banner_title_size'  => ["Cỡ chữ tiêu đề banner (12 - 40 px)", 'num', [12, 40]],
        'banner_desc_color'  => ["Màu chữ mô tả banner", 'color'],
        'banner_desc_size'   => ["Cỡ chữ mô tả banner (9 - 24 px)", 'num', [9, 24]],
    ],
    "Màu giao diện chung (index.php)" => [
        'theme_primary'       => ["Màu chính (nút Kích hoạt)", 'color'],
        'theme_primary_dark'  => ["Màu chính đậm (tiêu đề Key Free, link)", 'color'],
        'theme_vip_color'     => ["Màu VIP (tiêu đề Key VIP, nút Admin)", 'color'],
        'theme_accent'        => ["Màu nhấn (avatar mặc định)", 'color'],
        'theme_header_top'    => ["Thanh đầu trang: màu trên", 'color'],
        'theme_header_bottom' => ["Thanh đầu trang: màu dưới", 'color'],
        'theme_bg'            => ["Màu nền trang", 'color'],
        'theme_surface'       => ["Màu nền các khung", 'color'],
        'theme_text'          => ["Màu chữ", 'color'],
        'theme_muted'         => ["Màu chữ phụ", 'color'],
    ],
    "CSS tự viết thêm (index.php)" => [
        'custom_css' => ["CSS tuỳ chỉnh (chèn vào cuối trang chủ)", 'textarea', 20000],
    ],
    "Bảo mật tài khoản & Admin" => [
        'login_max_attempts'      => ["Số lần đăng nhập sai tối đa", 'num', [1, 50]],
        'login_lock_minutes'      => ["Khoá đăng nhập (phút)", 'num', [1, 1440]],
        'admin_max_attempts'      => ["Số lần sai tối đa khi vào admin", 'num', [1, 50]],
        'admin_lock_minutes'      => ["Khoá khi vào admin sai (phút)", 'num', [1, 1440]],
        'session_timeout_minutes' => ["Thành viên hết phiên sau N phút không hoạt động (0 = tắt, Admin không bị áp dụng)", 'num', [0, 10080]],
        'min_password_length'     => ["Độ dài mật khẩu tối thiểu", 'num', [6, 64]],
        'reserved_usernames'      => ["Tên đăng ký bị cấm (cách nhau dấu phẩy)", 'name_list'],
        'admin_ip_allow'          => ["IP được vào admin_cp (cách nhau dấu phẩy, trống = mọi IP)", 'ip_list'],
    ],
    "Chặn truy cập (VPN / Script)" => [
        'block_vpn'        => ["Chặn VPN / Proxy", 'bool'],
        'block_script'     => ["Chặn Userscript / Stay", 'bool'],
        'allowed_country'  => ["Quốc gia được phép (mã 2 chữ, ví dụ VN)", 'country'],
        'block_vpn_msg'    => ["Thông báo khi bị chặn VPN", 'textarea', 500],
        'block_script_msg' => ["Thông báo khi bị chặn Script", 'textarea', 500],
    ],
];

/* Thong bao he thong cua index.php (site_config.notice_<ma>): de trong = dung chu mac dinh o day */
$NOTICES = [
    'success' => "Key activated successfully!\nYou received {turns} {plan} turn(s).",
    'key_wrong' => 'Invalid key. Please check it and try again!',
    'not_logged_in' => 'Please log in to continue!',
    'need_login' => 'You need to log in!',
    'has_turn' => 'System: You still have {turns} turn(s). Use them all before activating a new key.',
    'need_vip' => "This is a VIP product!\nFree keys can only be used with Free products.",
    'need_key' => 'You are out of turns. Please enter a key!',
    'vip_free' => 'System: VIP keys cannot use Free products ({n}/{limit}). Clicking more than {limit} times will cost you 1 VIP turn.',
    'vip_free_last' => 'System: VIP keys cannot use Free products ({n}/{limit}). This is the final warning; clicking again will cost you 1 VIP turn.',
    'vip_free_penalty' => 'System: You clicked a Free product more than {limit} times; 1 VIP turn has been deducted.',
    'tool_ok' => 'System: 1 turn deducted. You have {turns} turn(s) left.',
    'tool_ok_last' => "System: 1 turn deducted. You are out of turns.\nPlease enter a key to continue.",
    'tool_reopen' => 'System: Reopening the product you just used. No extra turn deducted.',
    'key_expired' => 'System: Your key has expired. Please enter a new key.',
    'tool_missing' => 'Product not found.',
    'network_error' => 'Connection lost. Please try again.',
    'too_many_attempts' => "Too many failed attempts.\nPlease try again in {minutes} minutes.",
    'csrf_fail' => 'Session expired. Please reload the page.',
    'key_daily_limit' => "Daily key limit reached.\nOnly 1 key per day for each account and each IP. Please come back tomorrow.",
];
foreach ($NOTICES as $nc => $nd) {
    $ADV["Thông báo hệ thống (notice_*)"]['notice_' . $nc] = ["Thông báo: " . $nc, 'textarea', 500, $nd];
}

/* ================= THEME (devnvios_theme.php) =================
   Moi khoa ds_* duoc devnvios_theme.php doc sau khi chay patch_v25.php. De trong = giu mac dinh cua theme. */
$TH = [
    "Trang chủ (banner)" => [
        'ds_hero_title'   => ["Tiêu đề lớn trang chủ (trống = Apps)", 'text'],
        'ds_hero_desc'    => ["Mô tả dưới tiêu đề", 'text'],
        'ds_hero_seconds' => ["Giây tự chuyển ảnh banner (2 - 60)", 'dec', [2, 60]],
        'ds_hero_top'     => ["Khoảng cách từ header tới tiêu đề (0 - 40 px)", 'numr', [0, 40]],
        'ds_hero_opt'     => ["Nén ảnh banner sang WebP qua wsrv.nl (nhẹ hơn, chỉ áp dụng cho link https)", 'tri'],
        'ds_hero_width'   => ["Độ rộng ảnh khi nén (480 - 1600)", 'numr', [480, 1600]],
        'ds_chips'        => ["Thẻ nhỏ dưới banner: mỗi dòng một thẻ dạng Tên|icon (icon: cube, box, wrench, code, bolt, phone, layers, down, shield, doc, cog, key, term, menu)", 'textarea', 2000],
    ],
    "Logo & Header" => [
        'ds_shine'         => ["Bật lấp loáng logo", 'tri'],
        'ds_snow' => ["Bật tuyết rơi (để trống = bật)", 'tri'],
        'ds_shine_seconds' => ["Giây mỗi lượt lấp loáng (0.8 - 20, nhỏ = nhanh)", 'dec', [0.8, 20]],
        'ds_logo_skew'     => ["Độ nghiêng bù cho logo (-30 đến 30, 0 = không bù)", 'dec', [-30, 30]],
    ],
    "Thanh tab & hiệu ứng" => [
        'ds_nav_see'       => ["Thanh tab dưới đáy kính mờ nhìn xuyên", 'tri'],
        'ds_nav_see_alpha' => ["Độ đặc của kính mờ (20 - 90)", 'numr', [20, 90]],
        'ds_nav_float'     => ["Thanh tab dạng nổi (viên thuốc)", 'tri'],
        'ds_glass_blur'    => ["Làm mờ kính cho header + thanh tab", 'tri'],
        'ds_boot_ms'       => ["Thời gian vòng tròn nạp tối thiểu (200 - 3000 ms)", 'numr', [200, 3000]],
        'ds_toast_top'     => ["Độ cao thông báo giữa màn hình (10 - 80 %)", 'numr', [10, 80]],
        'ds_need_seconds'  => ["Giây thông báo \"nhập key\" tự biến mất (2 - 30)", 'dec', [2, 30]],
        'ds_toast_keys'    => ["Khi có thông báo, tự chuyển sang tab Keys", 'tri'],
        'ds_scroll_shell'  => ["Khung cuộn riêng (chống nhảy ở đáy trang)", 'tri'],
    ],
    "Chức năng" => [
        'ds_reopen_seconds' => ["Mở lại tool không mất lượt trong N giây (5 - 600). Server đang cho 90 giây", 'numr', [5, 600]],
        'ds_home_preview'   => ["Số tool hiện mỗi gói ở trang chủ (0 = hiện hết)", 'numr', [0, 100]],
        'ds_show_back'      => ["Hiện nút Back trong trang chi tiết", 'tri'],
        'ds_nav_register'   => ["Hiện mục Đăng ký ở thanh tab (khách)", 'tri'],
        'ds_tools_list'     => ["Hiện danh sách tool trong tab Tools", 'tri'],
        'ds_security_links' => ["Hiện liên kết Gmail / SĐT ở trang Me", 'tri'],
        'ds_userbar'        => ["Hiện thanh người dùng cũ", 'tri'],
        'ds_ios_keywords'   => ["Từ khoá nhận diện tool iOS (Package 3), cách nhau dấu phẩy", 'text'],
    ],
    "Liên kết" => [
        'ds_privacy_url'  => ["Link Privacy Policy", 'url'],
        'ds_terms_url'    => ["Link Terms of Service", 'url'],
        'ds_security_url' => ["Link trang bảo mật (mặc định /security.php)", 'url'],
        'ds_docs_url'     => ["Link trang tài liệu (mặc định /docs.php)", 'url'],
    ],
];
$COLS = [
    'bg' => "Nền trang", 'card' => "Nền khung / thẻ", 'field' => "Nền ô nhập, nút phụ", 'line' => "Đường kẻ nhạt", 'line2' => "Đường kẻ đậm",
    'text' => "Chữ chính", 'muted' => "Chữ phụ", 'accent' => "Màu nhấn (nút, thanh chọn)", 'link' => "Màu liên kết / nút View", 'vip' => "Màu VIP / đỏ",
    'ok' => "Màu thành công / Active", 'warn' => "Màu cảnh báo / Bảo trì", 'header' => "Nền thanh đầu trang", 'nav' => "Nền menu / hộp thoại",
];
foreach (['ds_c_' => "Màu giao diện tối (Dark)", 'ds_l_' => "Màu giao diện sáng (Light)"] as $pre => $gname) {
    foreach ($COLS as $ck => $cl) $TH[$gname][$pre . $ck] = [$cl, 'color', null, adm_sec_color_def($pre, $ck)];
}
$TH["CSS riêng cho theme"] = [
    'ds_custom_css' => ["CSS tuỳ chỉnh (chèn cuối phần head của theme, ghi đè được mọi thứ)", 'textarea', 20000],
];

/*ADM-V26*/

$TH["Kích thước & kiểu chữ"] = [
    'ds_hd'              => ["Chiều cao thanh header (40 - 80 px)", 'numr', [40, 80]],
    'ds_logo_h'          => ["Chiều cao logo trên header (24 - 70 px)", 'numr', [24, 70]],
    'ds_row_icon'        => ["Cỡ icon tool trong danh sách (36 - 90 px)", 'numr', [36, 90]],
    'ds_row_name_size'   => ["Cỡ chữ tên tool trong danh sách (11 - 24 px)", 'numr', [11, 24]],
    'ds_pkg_size'        => ["Cỡ chữ tiêu đề Package ở trang chủ (12 - 40 px)", 'numr', [12, 40]],
    'ds_hero_title_size' => ["Cỡ chữ tiêu đề lớn trang chủ (16 - 60 px)", 'numr', [16, 60]],
    'ds_slider_radius'   => ["Bo góc ảnh banner (0 - 40 px)", 'numr', [0, 40]],
    'ds_font'            => ["Phông chữ toàn web (tên font, ví dụ Inter hoặc Roboto; trống = mặc định hệ thống)", 'text'],
    'ds_font_url'        => ["Link tải font Google (https://fonts.googleapis.com/css2?family=Inter:wght@400;700). Chỉ nhận link fonts.googleapis.com", 'url'],
];

$TH["Package (gói tool)"] = [
    'ds_pkg_count' => ["Số Package hiển thị (3 - 8). Tăng lên 4, 5, 6... rồi đặt tên ở dưới, gán tool vào gói bằng nút Sửa của từng tool", 'numr', [3, 8]],
];
for ($pkI = 1; $pkI <= 8; $pkI++) {
    $TH["Package (gói tool)"]['ds_pkg' . $pkI . '_title'] = ["Package " . $pkI . ": tên hiển thị (trống = mặc định)", 'text'];
    $TH["Package (gói tool)"]['ds_pkg' . $pkI . '_sub']   = ["Package " . $pkI . ": dòng mô tả bên dưới tên (trống = mặc định / ẩn)", 'text'];
}

$TH["Nút GET / View / Chia sẻ / Avatar"] = [
    'ds_get_bg'       => ["Nút GET: màu nền", 'color'],
    'ds_get_fg'       => ["Nút GET: màu chữ", 'color'],
    'ds_get_radius'   => ["Nút GET: bo góc (0 - 30 px)", 'numr', [0, 30]],
    'ds_view_bg'      => ["Nút View (trong danh sách): màu nền", 'color'],
    'ds_view_fg'      => ["Nút View (trong danh sách): màu chữ", 'color'],
    'ds_share_bg'     => ["Nút chia sẻ: màu nền", 'color'],
    'ds_share_fg'     => ["Nút chia sẻ: màu icon", 'color'],
    'ds_avatar_shape' => ["Hình dạng avatar trên header", 'select', ['circle', 'rounded', 'square']],
];

$TH["Ẩn / hiện thành phần"] = [
    'ds_show_title'     => ["Hiện tiêu đề + mô tả lớn ở trang chủ", 'tri'],
    'ds_show_slider'    => ["Hiện banner ảnh ở trang chủ", 'tri'],
    'ds_show_chips'     => ["Hiện hàng thẻ nhỏ dưới banner", 'tri'],
    'ds_show_join'      => ["Hiện dòng JOIN THE TELEGRAM GROUP", 'tri'],
    'ds_show_pkgsub'    => ["Hiện dòng mô tả dưới tên mỗi Package", 'tri'],
    'ds_show_docs'      => ["Hiện khối DEVNVIOS / Legal / Documents cuối trang", 'tri'],
    'ds_tab_keys'       => ["Hiện tab Keys ở thanh dưới", 'tri'],
    'ds_tab_tools'      => ["Hiện tab Tools ở thanh dưới", 'tri'],
    'ds_tab_hist'       => ["Hiện tab History ở thanh dưới", 'tri'],
    'ds_show_theme_btn' => ["Hiện nút sáng / tối trên header", 'tri'],
    'ds_show_lang'      => ["Hiện nút EN trên header", 'tri'],
];

$TH["Thay chữ tự do"] = [
    'ds_replace' => ["Thay chữ trên web: mỗi dòng một cặp dạng 'Chữ gốc|Chữ mới' (ví dụ: Profile|Hồ sơ). Chỉ thay được chữ đứng nguyên trong một thẻ HTML và khớp đúng từng chữ (phân biệt hoa thường). Chữ gốc thường gặp: Account details, Security, Activity, Settings, Support, Profile, Turn history, Activate a key, Turns left, Hello, , Status, Device, Plan, Cost, Developer, Uploaded, Received, Used, Opened, Privacy Policy, Terms of Service, Admin panel, Clear history. Dòng bắt đầu bằng # là ghi chú.", 'textarea', 6000, "Profile|Hồ sơ\nSettings|Cài đặt"],
];

/* Tên Package cho ô chọn gói trong form sửa tool */
function adm_pkg_names($cf) {
    $n = (int)($cf['ds_pkg_count'] ?? 3);
    $n = max(3, min(8, $n));
    $o = [];
    for ($i = 1; $i <= $n; $i++) {
        $t = trim((string)($cf['ds_pkg' . $i . '_title'] ?? ''));
        $def = ($i === 1) ? 'Package 1 (VIP)' : (($i === 2) ? 'Package 2 (Free)' : (($i === 3) ? 'Package 3 (iOS)' : 'Package ' . $i));
        $o[$i] = ($t !== '') ? ($t . ' (Package ' . $i . ')') : $def;
    }
    return $o;
}


/*ADM-SECTIONS-V27*/

/* ---- Muc / Package: so muc, ten, mo ta, muc hien tai cua tung tool (giong cach devnvios_theme.php tinh) ---- */

function adm_sec_count($cf) { return max(3, min(8, (int)($cf['ds_pkg_count'] ?? 3))); }

function adm_sec_def_title($i, $cf) {

    $o = ($i <= 3) ? trim((string)($cf['ds_t_pkg' . $i] ?? '')) : '';

    return $o !== '' ? $o : 'Package ' . $i;

}

function adm_sec_def_sub($i, $cf) {

    global $TX;

    $map = [1 => 'vip_s', 2 => 'free_s', 3 => 'ios_s'];

    if (!isset($map[$i])) return '';

    $o = trim((string)($cf['ds_t_' . $map[$i]] ?? ''));

    return $o !== '' ? $o : (string)($TX[$map[$i]] ?? '');

}

function adm_sec_title($i, $cf) {

    $t = trim((string)($cf['ds_pkg' . $i . '_title'] ?? ''));

    return $t !== '' ? $t : adm_sec_def_title($i, $cf);

}

function adm_sec_sub($i, $cf) {

    $t = trim((string)($cf['ds_pkg' . $i . '_sub'] ?? ''));

    return $t !== '' ? $t : adm_sec_def_sub($i, $cf);

}

/* Muc tu dong cua tool khi admin chua chon (cot package/pkg, category=ios, tu khoa iOS, roi VIP=1 / Free=2) */

function adm_sec_auto($p, $cf, $n) {

    $pick = function ($keys) use ($p) {

        foreach ($keys as $k) {

            if (isset($p[$k]) && is_scalar($p[$k]) && trim((string)$p[$k]) !== '') return trim((string)$p[$k]);

        }

        return '';

    };

    $fallback = (strtoupper(trim((string)($p['type'] ?? 'FREE'))) === 'VIP') ? 1 : 2;

    $v = 0;

    $pp = strtolower($pick(['package', 'pkg', 'package_id']));

    if (preg_match('/^[1-8]$/', $pp)) {

        $v = (int)$pp;

    } elseif (preg_match('/^package\s*([1-8])$/', $pp, $mm)) {

        $v = (int)$mm[1];

    } elseif (strtolower($pick(['category', 'platform', 'os'])) === 'ios') {

        $v = 3;

    } else {

        $nm = (string)($p['name'] ?? '');

        $nm = function_exists('mb_strtolower') ? mb_strtolower($nm, 'UTF-8') : strtolower($nm);

        $kw = array_values(array_filter(array_map('trim', explode(',', strtolower((string)($cf['ds_ios_keywords'] ?? '')))), 'strlen'));

        if (!$kw) $kw = ['myren', 'gta5', 'gta 5', 'iosvn', 'pocket poster', 'filza'];

        foreach ($kw as $k) {

            if ($k !== '' && strpos($nm, $k) !== false) { $v = 3; break; }

        }

    }

    if ($v < 1) $v = $fallback;

    return ($v >= 1 && $v <= $n) ? $v : $fallback;

}

/* Muc dang hien tren web cua tool ($pm = thong tin ds_tool_meta cua tool) */

function adm_sec_effective($p, $pm, $cf, $n) {

    if (!empty($pm['pkg'])) {

        $v = (int)$pm['pkg'];

        if ($v >= 1 && $v <= $n) return $v;

        return (strtoupper(trim((string)($p['type'] ?? 'FREE'))) === 'VIP') ? 1 : 2;

    }

    return adm_sec_auto($p, $cf, $n);

}

/* Mau mac dinh cua theme (hien san trong o mau, chua dien thi giu mac dinh) */

function adm_sec_color_def($pre, $ck) {

    static $d = ['bg' => '#000000', 'card' => '#0a0a0b', 'field' => '#141416', 'line' => '#1f1f23', 'line2' => '#26262b',

        'text' => '#ffffff', 'muted' => '#9ca3af', 'accent' => '#0a84ff', 'link' => '#3b9eff', 'vip' => '#ff6961',

        'ok' => '#34c759', 'warn' => '#ff9f0a', 'header' => '#000000', 'nav' => '#0e0e10'];

    static $l = ['bg' => '#f5f7fa', 'card' => '#ffffff', 'field' => '#f3f4f6', 'line' => '#e5e7eb', 'line2' => '#d1d5db',

        'text' => '#111827', 'muted' => '#4b5563', 'accent' => '#0a84ff', 'link' => '#0a6cdc', 'vip' => '#dc2626',

        'ok' => '#16a34a', 'warn' => '#b45309', 'header' => '#ffffff', 'nav' => '#ffffff'];

    $a = ($pre === 'ds_l_') ? $l : $d;

    return isset($a[$ck]) ? $a[$ck] : '';

}


/* Chu tren web (ds_t trong devnvios_theme.php): de trong = chu mac dinh */
$TX = [
    'vip_t' => 'VIP tools', 'free_t' => 'Free tools',
    'vip_s' => 'Needs a VIP key. One turn is used each time a tool opens.',
    'free_s' => 'Works with a Free key. One turn is used each time a tool opens.',
    'ios_s' => 'iOS tools. The key type is shown under each tool. One turn is used each time a tool opens.',
    'vip_1' => 'VIP tool', 'free_1' => 'Free tool', 'view' => 'View', 'keys' => 'Keys', 'tools' => 'Tools',
    'kh_t' => 'Activate a key', 'kh_s' => 'Paste your VIP or Free key below. You can enter a new key once your turns are used up.',
    'need_key' => 'This tool closed. Enter your key to open it again.',
    'tools_empty' => 'The tools section is currently empty.',
    'pkg1' => 'Package 1', 'pkg2' => 'Package 2', 'pkg3' => 'Package 3',
    'search' => 'Search...', 'no_res' => 'No tools found.', 'history' => 'History', 'me' => 'Me', 'logout' => 'Log out',
    'hist_s' => 'Turns you received and the tools you opened.', 'hist_empty' => 'No activity yet',
    'hist_empty_s' => 'Open a tool or activate a key and it will show up here.',
    'previews' => 'Previews', 'no_prev' => 'No preview available', 'uploaded' => 'Uploaded', 'docs' => 'Documents',
    'hist_kick' => 'Receipts & Orders', 'hist_title' => 'Order History', 'hist_empty_t' => 'No Orders Found',
    'hist_empty_p' => 'All opened tools and received turns will appear here.', 'hist_nores' => 'No results.',
    'tools_kick' => 'Utilities', 'docs_brand' => 'DEVNVIOS',
    'docs_about' => 'Keys and tools for iOS in one place. Pick a package, activate a key, open a tool.',
    'docs_legal_t' => 'Legal disclaimer',
    'docs_legal' => 'Tools are provided for testing and entertainment purposes only. You are responsible for how you use them.',
    'st_active' => 'Active', 'st_ready' => 'Ready', 'st_mt_sub' => 'Under maintenance', 'mt_label' => 'Maintenance',
    'mt_msg' => 'This tool is under maintenance. Please come back later.',
];
$TX += [
    'desc_h' => 'Description', 'feat_h' => 'Features', 'info_h' => 'Detailed information', 'rel_h' => 'You may also like', 'get' => 'GET',
    /*PATCH-V25-FIX2*/
    'tool_1' => 'tool', 'tool_n' => 'tools',
    'lbl_status' => 'Status', 'lbl_device' => 'Device', 'lbl_plan' => 'Plan', 'lbl_cost' => 'Cost', 'lbl_dev' => 'Developer',
    'lbl_pkg' => 'Package', 'lbl_key_needed' => 'Key needed', 'lbl_cost_open' => 'Cost per open', 'lbl_supports' => 'Supports',
    'lbl_upload_date' => 'Upload date', 'lbl_last_upd' => 'Last updated', 'lbl_version' => 'Version', 'lbl_per_open' => 'Per open',
    'val_device' => 'iPhone, iPad', 'val_cost' => '1 turn', 'val_vipkey' => 'VIP key', 'val_freekey' => 'Free key',
    'need_vip' => 'Needs a VIP key', 'need_free' => 'Needs a Free key',
    'det_vip' => '{name} is a VIP tool from DEVNVIOS. It opens with an active VIP key and uses one turn each time you open it. A Free key cannot open VIP tools.',
    'det_free' => '{name} is a Free tool from DEVNVIOS. It opens with a Free key and uses one turn each time you open it. A VIP key cannot open Free tools.',
    'det_reopen' => 'If the tool does not open, tap OPEN again within {sec} seconds. It reopens without using another turn. After that, you need to enter your key again.',
];
$TXS = ['tx' => []];
foreach ($TX as $tk => $td) $TXS['tx'][$tk] = [$tk, 'text'];

/** Kiem tra + chuan hoa mot gia tri cau hinh. Tra ve chuoi de luu, hoac null = bo qua khoa nay. */
function adv_clean($label, $f, $v) {
    $type = $f[1];
    $x = $f[2] ?? null;

    if ($type === 'bool') {
        if ($v !== '0' && $v !== '1') throw new RuntimeException("$label: chỉ nhận Bật hoặc Tắt.");
        return $v;
    }
    if ($type === 'tri') {
        if ($v !== '' && $v !== '0' && $v !== '1') throw new RuntimeException("$label: chỉ nhận Mặc định, Bật hoặc Tắt.");
        return $v;
    }
    if ($type === 'select') {
        if (!in_array($v, $x, true)) throw new RuntimeException("$label: giá trị không hợp lệ.");
        return $v;
    }
    if ($type === 'num' || $type === 'numr') {
        if ($v === '') return $type === 'numr' ? '' : null;
        if (!preg_match('/^\d{1,6}$/', $v) || (int)$v < $x[0] || (int)$v > $x[1])
            throw new RuntimeException("$label: nhập số từ {$x[0]} đến {$x[1]}.");
        return (string)(int)$v;
    }
    if ($type === 'dec') {
        if ($v === '') return '';
        $v = str_replace(',', '.', $v);
        if (!preg_match('/^-?\d{1,3}(\.\d{1,2})?$/', $v) || (float)$v < $x[0] || (float)$v > $x[1])
            throw new RuntimeException("$label: nhập số từ {$x[0]} đến {$x[1]}.");
        $o = rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
        return ($o === '' || $o === '-0') ? '0' : $o;
    }
    if ($type === 'color') {
        if ($v !== '' && !valid_color($v)) throw new RuntimeException("$label: màu không hợp lệ (dùng #hex, rgb(), hsl() hoặc tên màu).");
        return $v;
    }
    if ($type === 'url') {
        if ($v !== '' && (bad_link($v) || !valid_url($v))) throw new RuntimeException("$label: link phải bắt đầu bằng http://, https:// hoặc /");
        return $v;
    }
    if ($type === 'country') {
        $v = strtoupper($v);
        if ($v !== '' && !preg_match('/^[A-Z]{2}$/', $v)) throw new RuntimeException("$label: nhập mã quốc gia 2 chữ cái, ví dụ VN.");
        return $v;
    }
    if ($type === 'name_list') {
        $v = strtolower(preg_replace('/\s+/', '', $v));
        if ($v !== '' && !preg_match('/^[a-z0-9_.\-]+(,[a-z0-9_.\-]+)*$/', $v)) throw new RuntimeException("$label: chỉ gồm chữ thường, số, _ . - và dấu phẩy.");
        return $v;
    }
    if ($type === 'ip_list') {
        $v = preg_replace('/\s+/', '', $v);
        if ($v === '') return '';
        $list = explode(',', $v);
        foreach ($list as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) throw new RuntimeException("$label: \"$ip\" không phải địa chỉ IP hợp lệ.");
        }
        if (!in_array(client_ip(), $list, true))
            throw new RuntimeException("$label: danh sách phải có IP hiện tại của bạn (" . client_ip() . ") nếu không bạn sẽ tự khoá mình.");
        return implode(',', $list);
    }
    $max = ($type === 'textarea') ? (int)($x ?: 2000) : 255;
    if (slen($v) > $max) throw new RuntimeException("$label: tối đa $max ký tự.");
    return $v;
}

/** Gom cac gia tri thay doi cua mot nhom truong. $prefix = tien to khoa trong site_config (vd ds_t_). */
function collect_changes($schema, $in, $cf, $prefix = '') {
    $todo = [];
    foreach ($schema as $fields) {
        foreach ($fields as $k => $f) {
            if (!array_key_exists($k, $in) || is_array($in[$k])) continue;
            $ck = $prefix . $k;
            $v = str_replace("\r\n", "\n", trim((string)$in[$k]));
            if ($v === '' && !array_key_exists($ck, $cf)) continue; // khoa chua tung co va de trong: khong tao moi
            $val = adv_clean($f[0], $f, $v);
            if ($val === null) continue;
            if (array_key_exists($ck, $cf) && str_replace("\r\n", "\n", (string)$cf[$ck]) === $val) continue; // khong doi
            $todo[$ck] = $val;
        }
    }
    return $todo;
}

/** HTML cua mot truong cau hinh. $name = ten mang POST (adv / th / tx). */
function field_html($name, $k, $f, $val, $idp) {
    $label = $f[0]; $type = $f[1]; $x = isset($f[2]) ? $f[2] : null; $ph = isset($f[3]) ? $f[3] : '';
    $id = $idp . $k;
    $nm = $name . '[' . $k . ']';
    $o = '<div class="form-group"><label class="form-label" for="' . e($id) . '">' . e($label) . ' <span class="muted-text key-name">(' . e($k) . ')</span></label>';
    if ($type === 'bool' || $type === 'tri') {
        $o .= '<select id="' . e($id) . '" name="' . e($nm) . '" class="form-control">';
        if ($type === 'tri') $o .= '<option value=""' . ($val === '' ? ' selected' : '') . '>' . "Mặc định" . '</option>';
        $o .= '<option value="1"' . ($val === '1' ? ' selected' : '') . '>' . "Bật" . '</option>';
        $on0 = ($type === 'tri') ? ($val === '0') : ($val !== '1');
        $o .= '<option value="0"' . ($on0 ? ' selected' : '') . '>' . "Tắt" . '</option></select>';
    } elseif ($type === 'select') {
        $o .= '<select id="' . e($id) . '" name="' . e($nm) . '" class="form-control">';
        foreach ($x as $opt) $o .= '<option value="' . e($opt) . '"' . ($val === $opt ? ' selected' : '') . '>' . e($opt) . '</option>';
        $o .= '</select>';
    } elseif ($type === 'textarea') {
        $o .= '<textarea id="' . e($id) . '" name="' . e($nm) . '" class="form-control form-textarea" maxlength="' . (int)$x . '" placeholder="' . e($ph) . '">' . e($val) . '</textarea>';
    } elseif ($type === 'num' || $type === 'numr') {
        $o .= '<input type="number" id="' . e($id) . '" name="' . e($nm) . '" value="' . e($val) . '" min="' . (int)$x[0] . '" max="' . (int)$x[1] . '" inputmode="numeric" class="form-control" placeholder="' . "Mặc định" . '">';
    } elseif ($type === 'dec') {
        $o .= '<input type="text" id="' . e($id) . '" name="' . e($nm) . '" value="' . e($val) . '" inputmode="decimal" class="form-control" autocomplete="off" placeholder="' . "Mặc định" . '">';
    } elseif ($type === 'color') {
        $hex = preg_match('/^#[0-9a-f]{6}$/i', $val) ? $val : (preg_match('/^#[0-9a-f]{6}$/i', $ph) ? $ph : '#000000');
        $o .= '<div class="color-row"><input type="text" id="' . e($id) . '" name="' . e($nm) . '" value="' . e($val) . '" class="form-control js-color" autocomplete="off" placeholder="' . e($ph !== '' ? $ph : '#rrggbb') . '">'
            . '<input type="color" class="color-pick" value="' . e($hex) . '" data-for="' . e($id) . '" aria-label="Chọn màu"></div>';
    } else {
        $pl = '';
        if ($type === 'ip_list') $pl = ' placeholder="' . "IP hiện tại của bạn: " . e(client_ip()) . '"';
        elseif ($type === 'url') $pl = ' placeholder="https://..." inputmode="url"';
        elseif ($ph !== '') $pl = ' placeholder="' . e($ph) . '"';
        $o .= '<input type="text" id="' . e($id) . '" name="' . e($nm) . '" value="' . e($val) . '" class="form-control" autocomplete="off"' . $pl . '>';
    }
    return $o . '</div>';
}

$UP = 'uploads/';
if (!is_dir($UP)) @mkdir($UP, 0755, true);

/* ================= CHAN THEO IP, KIEM TRA QUYEN ADMIN ================= */
$cf = load_cfg($conn);

// IP duoc phep vao admin_cp (site_config.admin_ip_allow). Trong = moi IP.
$allowIps = array_filter(array_map('trim', explode(',', (string)($cf['admin_ip_allow'] ?? ''))), 'strlen');
if ($allowIps && !in_array(client_ip(), $allowIps, true)) {
    deny_page("Từ chối truy cập", "Địa chỉ IP của bạn không nằm trong danh sách được phép vào trang quản trị.");
}

// Admin KHONG bao gio bi het phien (session_timeout_minutes chi ap dung cho thanh vien o index.php).
if (!empty($_SESSION['username'])) $_SESSION['last_activity'] = time();

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

$me = one($conn, "SELECT id, username, role FROM users WHERE username = ?", 's', [$_SESSION['username'] ?? '']);
if (!$me || (int)$me['role'] !== 1) {
    deny_page("Từ chối truy cập", "Tài khoản của bạn không có đặc quyền quản trị viên.");
}
$ADMIN_ID = (int)$me['id'];
$ADMIN_NAME = (string)$me['username'];

/* ================= XU LY POST ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tab = 'keys';
    $newFiles = []; // file vua tai len trong luot nay: neu loi thi xoa de khong de lai file mo coi
    try {
        if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? '')))
            throw new RuntimeException("Phiên bảo mật đã hết hạn (hoặc tệp tải lên quá lớn so với giới hạn của máy chủ). Hãy tải lại trang và thử lại.");
        $act = $_POST['action'] ?? '';

        switch ($act) {
            case 'toggle_mt':
                $tab = 'maint';
                $row = one($conn, "SELECT config_value FROM site_config WHERE config_key = 'maintenance_mode'");
                $new = (($row['config_value'] ?? '0') === '1') ? '0' : '1';
                cfg_set($conn, 'maintenance_mode', $new);
                if ($new === '1') mtx_access_enable($conn, $ADMIN_NAME); else mtx_access_disable($conn);
                admin_log($conn, 'toggle_maintenance', $new === '1' ? "Bật bảo trì (tạo link + mật khẩu truy cập)" : "Tắt bảo trì (xoá link + mật khẩu truy cập)");
                flash($new === '1'
                    ? "Đã BẬT bảo trì. Link và mật khẩu vào admin hiện ở đầu trang, hãy lưu lại ngay."
                    : "Đã TẮT bảo trì. Hệ thống hoạt động bình thường, link và mật khẩu truy cập đã bị xoá.");
                break;

            case 'regen_mt':
                $tab = 'maint';
                $row = one($conn, "SELECT config_value FROM site_config WHERE config_key = 'maintenance_mode'");
                if (($row['config_value'] ?? '0') !== '1') throw new RuntimeException("Chỉ tạo link và mật khẩu khi bảo trì đang bật.");
                mtx_access_enable($conn, $ADMIN_NAME);
                admin_log($conn, 'regen_maintenance_access', "Tạo lại link + mật khẩu truy cập bảo trì");
                flash("Đã tạo link và mật khẩu mới. Link và mật khẩu cũ không còn dùng được.");
                break;

            case 'kill_key':
                $tab = 'members';
                $id = (int)($_POST['id'] ?? 0);
                $target = one($conn, "SELECT username FROM users WHERE id = ? AND role != 1", 'i', [$id]);
                // Huy key + xoa luot con lai + bao cho thanh vien (index.php hien thong bao thu hoi tu cot alert_msg)
                $sets = ["key_type = 'NONE'", "expiry_date = '2000-01-01 00:00:00'"];
                if (col_exists($conn, 'users', 'turns_left')) $sets[] = 'turns_left = NULL';
                if (col_exists($conn, 'users', 'vip_free_clicks')) $sets[] = 'vip_free_clicks = 0';
                if (col_exists($conn, 'users', 'alert_msg')) $sets[] = "alert_msg = 'Key của bạn đã bị Admin thu hồi.'";
                $st = run($conn, "UPDATE users SET " . implode(', ', $sets) . " WHERE id = ? AND role != 1", 'i', [$id]);
                if (mysqli_stmt_affected_rows($st) < 1) throw new RuntimeException("Không thể hủy: thành viên không tồn tại hoặc là tài khoản quản trị.");
                admin_log($conn, 'kill_key', "Hủy key của " . ($target['username'] ?? ('id ' . $id)));
                flash("Đã hủy key và reset thời gian sử dụng của thành viên về 0.");
                break;

            case 'del_p':
                $tab = 'tools';
                $id = (int)($_POST['id'] ?? 0);
                $p = one($conn, "SELECT name, image FROM products WHERE id = ?", 'i', [$id]);
                if (!$p) throw new RuntimeException("Không tìm thấy tool này.");
                run($conn, "DELETE FROM products WHERE id = ?", 'i', [$id]);
                safe_unlink($p['image'], $UP);
                // don dep anh xem truoc + trang thai cua tool nay trong ds_tool_meta
                $meta = meta_load($cf);
                if (isset($meta[$id])) {
                    foreach ((array)($meta[$id]['previews'] ?? []) as $pu) rm_upload($pu, $UP, ['prev_']);
                    unset($meta[$id]);
                    meta_save($conn, $meta);
                }
                admin_log($conn, 'del_product', "Xóa tool #" . $id . ' ' . $p['name']);
                flash("Đã xóa tool thành công.");
                break;

            case 'del_key':
                $tbl = (($_POST['type'] ?? '') === 'vip') ? 'keys_vip' : 'keys_free';
                $kid = (int)($_POST['id'] ?? 0);
                $st = run($conn, "DELETE FROM $tbl WHERE id = ?", 'i', [$kid]);
                if (mysqli_stmt_affected_rows($st) < 1) throw new RuntimeException("Key không tồn tại hoặc đã bị xóa trước đó.");
                admin_log($conn, 'del_key', "Xóa 1 key trong $tbl (id $kid)");
                flash("Đã xóa 1 key khỏi kho.");
                break;

            case 'bulk_delete':
                $isVip = (($_POST['type'] ?? '') === 'vip');
                $tbl = $isVip ? 'keys_vip' : 'keys_free';
                $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
                if (!$ids) throw new RuntimeException("Bạn chưa chọn key nào để xóa.");
                $st = run($conn, "DELETE FROM $tbl WHERE id IN (" . implode(',', $ids) . ")");
                $n = mysqli_stmt_affected_rows($st);
                admin_log($conn, 'bulk_delete_keys', "Xóa $n key trong $tbl");
                flash("Đã xóa $n key " . ($isVip ? 'VIP' : 'FREE') . " khỏi kho.");
                break;

            case 'gen_keys':
                $pack = $_POST['pack'] ?? '';
                $packs = ['vip:720' => ['vip', 720], 'vip:168' => ['vip', 168], 'vip:24' => ['vip', 24], 'free:24' => ['free', 24]];
                if (!isset($packs[$pack])) throw new RuntimeException("Gói key không hợp lệ.");
                [$type, $hr] = $packs[$pack];
                $num = max(1, min(200, (int)($_POST['num'] ?? 0)));
                $tbl = $type === 'vip' ? 'keys_vip' : 'keys_free';
                $made = 0;
                for ($i = 0; $i < $num; $i++) {
                    $code = 'DEV-' . strtoupper(bin2hex(random_bytes(4))) . ($type === 'vip' ? '-VIP' : '-FREE');
                    try {
                        $st = run($conn, "INSERT INTO $tbl (key_code, duration_hours, status) VALUES (?, ?, 0)", 'si', [$code, $hr]);
                        $made += max(0, mysqli_stmt_affected_rows($st));
                        mysqli_stmt_close($st);
                    } catch (Throwable $ignored) { /* trung ma (uq_key_code): bo qua, ma sau se khac */ }
                }
                if ($made < 1) throw new RuntimeException("Không tạo được key nào, hãy kiểm tra lại bảng " . $tbl . '.');
                admin_log($conn, 'gen_keys', "Tạo $made key " . strtoupper($type) . " ($hr giờ)");
                flash("Đã tạo $made key " . strtoupper($type) . " ($hr giờ).");
                break;

            case 'add_p':
                $tab = 'tools';
                $name = trim($_POST['p_name'] ?? ''); $link = trim($_POST['p_link'] ?? '');
                $type = (($_POST['p_type'] ?? '') === 'VIP') ? 'VIP' : 'FREE';
                if ($name === '' || $link === '') throw new RuntimeException("Vui lòng nhập đầy đủ tên tool và link.");
                if (slen($name) > 255) throw new RuntimeException("Tên tool tối đa 255 ký tự.");
                if (bad_link($link) || !valid_url($link)) throw new RuntimeException("Link phải bắt đầu bằng http:// hoặc https:// (không nhận link không an toàn).");
                [$img, $err] = save_image($_FILES['p_img'] ?? null, $UP, 'tool_');
                if ($err) throw new RuntimeException($err);
                $newFiles[] = $img;
                run($conn, "INSERT INTO products (name, link, image, type) VALUES (?, ?, ?, ?)", 'ssss', [$name, $link, $img, $type]);
                $newFiles = [];
                $addPkg = (string)($_POST['p_pkg'] ?? '');

                if (preg_match('/^[1-8]$/', $addPkg) && (int)$addPkg <= adm_sec_count($cf)) {

                    $addId = (int)mysqli_insert_id($conn);

                    if ($addId > 0) {

                        $addMeta = meta_load($cf);

                        $addCur = (isset($addMeta[$addId]) && is_array($addMeta[$addId])) ? $addMeta[$addId] : [];

                        $addCur['pkg'] = (int)$addPkg;

                        $addMeta[$addId] = $addCur;

                        meta_save($conn, $addMeta);

                    }

                }
                admin_log($conn, 'add_product', "Thêm tool $name ($type)");
                flash("Đã thêm sản phẩm tool thành công.");
                break;

            case 'edit_p':
                // Sua tool: ten, link, loai, icon + trang thai (Active / Bao tri) + toi da 3 anh xem truoc
                $tab = 'tools';
                $id = (int)($_POST['id'] ?? 0);
                $p = one($conn, "SELECT id, name, image FROM products WHERE id = ?", 'i', [$id]);
                if (!$p) throw new RuntimeException("Không tìm thấy tool này.");
                $name = trim($_POST['p_name'] ?? ''); $link = trim($_POST['p_link'] ?? '');
                $type = (($_POST['p_type'] ?? '') === 'VIP') ? 'VIP' : 'FREE';
                if ($name === '' || $link === '') throw new RuntimeException("Vui lòng nhập đầy đủ tên tool và link.");
                if (slen($name) > 255) throw new RuntimeException("Tên tool tối đa 255 ký tự.");
                if (bad_link($link) || !valid_url($link)) throw new RuntimeException("Link phải bắt đầu bằng http:// hoặc https:// (không nhận link không an toàn).");
                $newImg = null;
                if (($_FILES['p_img']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    [$newImg, $err] = save_image($_FILES['p_img'], $UP, 'tool_');
                    if ($err) throw new RuntimeException($err);
                    $newFiles[] = $newImg;
                }
                $status = (($_POST['p_status'] ?? '') === 'maintenance') ? 'maintenance' : 'active';
                $note = scut(trim((string)($_POST['p_note'] ?? '')), 200);

                $meta = meta_load($cf);
                $cur = array_values((array)(($meta[$id]['previews'] ?? [])));
                $next = []; $dropOld = [];
                $pvUrl = (array)($_POST['pv_url'] ?? []);
                for ($i = 0; $i < 3; $i++) {
                    $old = isset($cur[$i]) ? (string)$cur[$i] : '';
                    $file = file_one('pv_file', $i);
                    $u = isset($pvUrl[$i]) && !is_array($pvUrl[$i]) ? trim((string)$pvUrl[$i]) : '';
                    if ($file !== null) {
                        [$pf, $perr] = save_image($file, $UP, 'prev_');
                        if ($perr) throw new RuntimeException("Ảnh xem trước " . ($i + 1) . ": " . $perr);
                        $newFiles[] = $pf;
                        $next[] = pub_path($pf);
                        if ($old !== '') $dropOld[] = $old;
                    } elseif ($u !== '') {
                        if (!img_ok($u)) throw new RuntimeException("Ảnh xem trước " . ($i + 1) . ": link phải bắt đầu bằng http://, https:// hoặc /");
                        $next[] = $u;
                        if ($old !== '' && $old !== $u) $dropOld[] = $old;
                    } elseif ($old !== '') {
                        $dropOld[] = $old; // de trong = xoa anh nay
                    }
                }
                $next = array_slice(array_values(array_unique($next)), 0, 3);

                /*ADM-V26-EXTRA*/
                $extra = [];
                foreach (['desc' => 2000, 'dev' => 100, 'ios' => 40, 'ver' => 40, 'feat' => 3000, 'date' => 40, 'upd' => 40, 'device' => 100] as $xmk => $xml) {
                    $xmv = str_replace("\r\n", "\n", trim((string)($_POST['m_' . $xmk] ?? '')));
                    if ($xmv === '') continue;
                    if (slen($xmv) > $xml) throw new RuntimeException("Thông tin trang View (" . $xmk . ") tối đa " . $xml . " ký tự.");
                    if (($xmk === 'date' || $xmk === 'upd') && strtotime($xmv) === false) throw new RuntimeException("Ngày không hợp lệ (ví dụ 2026-10-20): " . $xmv);
                    $extra[$xmk] = $xmv;
                }
                $xmp = (string)($_POST['m_pkg'] ?? '');
                if (preg_match('/^[1-8]$/', $xmp)) $extra['pkg'] = (int)$xmp;
                $xmo = trim((string)($_POST['m_ord'] ?? ''));
                if ($xmo !== '') {
                    if (!preg_match('/^-?\d{1,5}$/', $xmo)) throw new RuntimeException("Thứ tự phải là số nguyên (-99999 đến 99999).");
                    if ((int)$xmo !== 0) $extra['ord'] = (int)$xmo;
                }
                if (!empty($_POST['m_hide'])) $extra['hide'] = 1;

                if ($newImg !== null) {
                    run($conn, "UPDATE products SET name = ?, link = ?, type = ?, image = ? WHERE id = ?", 'ssssi', [$name, $link, $type, $newImg, $id]);
                } else {
                    run($conn, "UPDATE products SET name = ?, link = ?, type = ? WHERE id = ?", 'sssi', [$name, $link, $type, $id]);
                }
                $newFiles = [];
                if ($newImg !== null) safe_unlink($p['image'], $UP);
                foreach ($dropOld as $do) { if (!in_array($do, $next, true)) rm_upload($do, $UP, ['prev_']); }

                $m = [];
                if ($status === 'maintenance') $m['status'] = 'maintenance';
                if ($note !== '') $m['note'] = $note;
                if ($next) $m['previews'] = $next;
                foreach ($extra as $xk => $xv) $m[$xk] = $xv;
                if ($m) $meta[$id] = $m; else unset($meta[$id]);
                meta_save($conn, $meta);
                admin_log($conn, 'edit_product', "Sửa tool #" . $id . ' ' . $name . ' (' . ($status === 'maintenance' ? 'bảo trì' : 'hoạt động') . ', ' . count($next) . " ảnh xem trước)");
                flash("Đã lưu tool «" . $name . "».");
                break;

            case 'tool_status':
                $tab = 'tools';
                $id = (int)($_POST['id'] ?? 0);
                $p = one($conn, "SELECT name FROM products WHERE id = ?", 'i', [$id]);
                if (!$p) throw new RuntimeException("Không tìm thấy tool này.");
                $status = (($_POST['status'] ?? '') === 'maintenance') ? 'maintenance' : 'active';
                $meta = meta_load($cf);
                $m = isset($meta[$id]) && is_array($meta[$id]) ? $meta[$id] : [];
                if ($status === 'maintenance') $m['status'] = 'maintenance'; else unset($m['status']);
                if ($m) $meta[$id] = $m; else unset($meta[$id]);
                meta_save($conn, $meta);
                admin_log($conn, 'tool_status', "Tool #" . $id . ' ' . $p['name'] . ' -> ' . $status);
                flash($status === 'maintenance' ? "Đã chuyển «" . $p['name'] . "» sang BẢO TRÌ." : "«" . $p['name'] . "» đã hoạt động trở lại (Active).");
                break;

            case 'hero_add':
                $tab = 'theme';
                $list = hero_list($cf);
                $added = 0;
                foreach (preg_split('/[\s,]+/', (string)($_POST['hero_url'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $u) {
                    if (!img_ok($u)) throw new RuntimeException("Link ảnh phải bắt đầu bằng http://, https:// hoặc /  ($u)");
                    if (!in_array($u, $list, true)) { $list[] = $u; $added++; }
                }
                foreach (files_multi('hero_files') as $f) {
                    [$path, $err] = save_image($f, $UP, 'hero_');
                    if ($err) throw new RuntimeException($err);
                    $newFiles[] = $path;
                    $list[] = pub_path($path);
                    $added++;
                }
                if (!$added) throw new RuntimeException("Chưa nhập link hoặc chọn ảnh để thêm.");
                cfg_set($conn, 'ds_hero_images', implode("\n", $list));
                $newFiles = [];
                admin_log($conn, 'hero_add', "Thêm $added ảnh banner (tổng " . count($list) . ")");
                flash("Đã thêm $added ảnh banner. Tổng cộng " . count($list) . " ảnh.");
                break;

            case 'hero_del':
                $tab = 'theme';
                $list = hero_list($cf);
                $i = (int)($_POST['idx'] ?? -1);
                if (!isset($list[$i])) throw new RuntimeException("Không tìm thấy ảnh banner này.");
                $gone = $list[$i];
                array_splice($list, $i, 1);
                cfg_set($conn, 'ds_hero_images', implode("\n", $list));
                rm_upload($gone, $UP, ['hero_']);
                admin_log($conn, 'hero_del', "Xóa ảnh banner #" . ($i + 1));
                flash($list ? "Đã xóa ảnh banner." : "Đã xóa ảnh cuối cùng. Web sẽ dùng lại 3 ảnh mặc định của theme.");
                break;

            case 'hero_move':
                $tab = 'theme';
                $list = hero_list($cf);
                $i = (int)($_POST['idx'] ?? -1);
                $j = $i + (((int)($_POST['dir'] ?? 0)) < 0 ? -1 : 1);
                if (!isset($list[$i]) || !isset($list[$j])) throw new RuntimeException("Không thể di chuyển ảnh này.");
                $tmp = $list[$i]; $list[$i] = $list[$j]; $list[$j] = $tmp;
                cfg_set($conn, 'ds_hero_images', implode("\n", $list));
                admin_log($conn, 'hero_move', "Đổi vị trí ảnh banner #" . ($i + 1) . " và #" . ($j + 1));
                flash("Đã đổi thứ tự ảnh banner.");
                break;

            case 'save_avatar':
                // Avatar canh nut sang/toi tren header (theme)
                $tab = 'theme';
                $old = (string)($cf['ds_avatar_url'] ?? '');
                if (!empty($_POST['avatar_reset'])) {
                    cfg_set($conn, 'ds_avatar_url', '');
                    rm_upload($old, $UP, ['dsav_']);
                    admin_log($conn, 'avatar_reset', "Trả avatar header về mặc định");
                    flash("Đã trả avatar về mặc định của theme.");
                    break;
                }
                $new = '';
                if (($_FILES['ava_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    [$path, $err] = save_image($_FILES['ava_file'], $UP, 'dsav_');
                    if ($err) throw new RuntimeException($err);
                    $newFiles[] = $path;
                    $new = pub_path($path);
                } else {
                    $new = trim((string)($_POST['ava_url'] ?? ''));
                    if ($new === '') throw new RuntimeException("Chưa nhập link hoặc chọn ảnh avatar.");
                    if (!img_ok($new)) throw new RuntimeException("Link avatar phải bắt đầu bằng http://, https:// hoặc /");
                }
                cfg_set($conn, 'ds_avatar_url', $new);
                $newFiles = [];
                if ($old !== '' && $old !== $new) rm_upload($old, $UP, ['dsav_']);
                admin_log($conn, 'save_avatar', "Đổi avatar header");
                flash("Đã đổi avatar cạnh nút sáng / tối.");
                break;

            case 'save_logo':
                $tab = 'theme';
                $old = (string)($cf['brand_logo'] ?? '');
                if (!empty($_POST['logo_reset'])) {
                    cfg_set($conn, 'brand_logo', 'logo-trong.png');
                    rm_upload($old, $UP, ['dslogo_']);
                    admin_log($conn, 'logo_reset', "Reset logo");
                    flash("Da tra logo ve mac dinh.");
                    break;
                }
                $new = '';
                if (($_FILES['logo_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    [$path, $err] = save_image($_FILES['logo_file'], $UP, 'dslogo_');
                    if ($err) throw new RuntimeException($err);
                    $newFiles[] = $path;
                    $new = pub_path($path);
                } else {
                    $new = trim((string)($_POST['logo_url'] ?? ''));
                    if ($new === '') throw new RuntimeException("Chua nhap link hoac chon anh logo.");
                    if (!img_ok($new)) throw new RuntimeException("Link logo phai bat dau bang http://, https:// hoac /");
                }
                cfg_set($conn, 'brand_logo', $new);
                $newFiles = [];
                if ($old !== '' && $old !== $new) rm_upload($old, $UP, ['dslogo_']);
                admin_log($conn, 'save_logo', "Doi logo website");
                flash("Da doi logo website.");
                break;

            case 'save_theme':
                $tab = 'theme';
                $todo = collect_changes($TH, (array)($_POST['th'] ?? []), $cf);
                $todo += collect_changes($TXS, (array)($_POST['tx'] ?? []), $cf, 'ds_t_');
                foreach ($todo as $k => $val) cfg_set($conn, $k, $val);
                if ($todo) admin_log($conn, 'save_theme', "Đổi: " . implode(', ', array_keys($todo)));
                flash($todo ? "Đã lưu " . count($todo) . " mục giao diện. Tải lại trang chủ để thấy thay đổi." : "Không có mục nào thay đổi.");
                break;

            case 'save_sections':

                $tab = 'sections';

                $secN = adm_sec_count($cf);

                $secOp = (string)($_POST['sec_op'] ?? '');

                if ($secOp === 'add' && $secN >= 8) throw new RuntimeException("Tối đa 8 mục.");

                if ($secOp === 'remove' && $secN <= 3) throw new RuntimeException("Phải giữ tối thiểu 3 mục.");

                $secTitles = (array)($_POST['sec_title'] ?? []);

                $secSubs   = (array)($_POST['sec_sub'] ?? []);

                $secWrote = 0;

                $secMoved = 0;

                for ($i = 1; $i <= $secN; $i++) {

                    if (isset($secTitles[$i]) && !is_array($secTitles[$i])) {

                        $tv = adv_clean("Mục $i: tên", ['', 'text'], trim((string)$secTitles[$i]));

                        if ($tv === adm_sec_def_title($i, $cf)) $tv = '';

                        if ($tv !== trim((string)($cf['ds_pkg' . $i . '_title'] ?? ''))) { cfg_set($conn, 'ds_pkg' . $i . '_title', $tv); $secWrote++; }

                    }

                    if (isset($secSubs[$i]) && !is_array($secSubs[$i])) {

                        $sv = adv_clean("Mục $i: mô tả", ['', 'text'], trim((string)$secSubs[$i]));

                        if ($sv === adm_sec_def_sub($i, $cf)) $sv = '';

                        if ($sv !== trim((string)($cf['ds_pkg' . $i . '_sub'] ?? ''))) { cfg_set($conn, 'ds_pkg' . $i . '_sub', $sv); $secWrote++; }

                    }

                }

                $secAssign = (array)($_POST['tool_pkg'] ?? []);

                $secMeta = meta_load($cf);

                foreach (fetch_all($conn, "SELECT * FROM products") as $pr) {

                    $pid = (int)$pr['id'];

                    if (!isset($secAssign[$pid]) || is_array($secAssign[$pid])) continue;

                    $pv = (string)$secAssign[$pid];

                    if (!preg_match('/^[1-8]$/', $pv) || (int)$pv > $secN) continue;

                    $want = (int)$pv;

                    $m = (isset($secMeta[$pid]) && is_array($secMeta[$pid])) ? $secMeta[$pid] : [];

                    $before = adm_sec_effective($pr, $m, $cf, $secN);

                    if ($want === adm_sec_auto($pr, $cf, $secN)) unset($m['pkg']); else $m['pkg'] = $want;

                    if ($want !== $before) $secMoved++;

                    if ($m) $secMeta[$pid] = $m; else unset($secMeta[$pid]);

                }

                if ($secOp === 'remove') {

                    foreach ($secMeta as $mk => $mv) {

                        if (is_array($mv) && (int)($mv['pkg'] ?? 0) === $secN) {

                            unset($secMeta[$mk]['pkg']);

                            if (!$secMeta[$mk]) unset($secMeta[$mk]);

                        }

                    }

                }

                meta_save($conn, $secMeta);

                if ($secOp === 'add') {

                    cfg_set($conn, 'ds_pkg_count', (string)($secN + 1));

                    admin_log($conn, 'sections_add', "Thêm Mục " . ($secN + 1));

                    flash("Đã thêm Mục " . ($secN + 1) . ". Đặt tên và chọn sản phẩm cho mục này ở bên dưới. Mục chưa có sản phẩm thì trang chủ chưa hiện.");

                } elseif ($secOp === 'remove') {

                    cfg_set($conn, 'ds_pkg_count', (string)($secN - 1));

                    if (array_key_exists('ds_pkg' . $secN . '_title', $cf)) cfg_set($conn, 'ds_pkg' . $secN . '_title', '');

                    if (array_key_exists('ds_pkg' . $secN . '_sub', $cf)) cfg_set($conn, 'ds_pkg' . $secN . '_sub', '');

                    admin_log($conn, 'sections_remove', "Bớt Mục " . $secN);

                    flash("Đã bớt Mục " . $secN . ". Sản phẩm trong mục đó đã quay về mục tự động.");

                } else {

                    admin_log($conn, 'save_sections', "Lưu mục: $secWrote tên/mô tả đổi, $secMoved sản phẩm đổi mục");

                    flash(($secWrote || $secMoved)

                        ? "Đã lưu: $secWrote tên/mô tả đổi, $secMoved sản phẩm đổi mục. Tải lại trang chủ để thấy thay đổi."

                        : "Không có gì thay đổi.");

                }

                break;
            case 'save_site':
                $tab = 'settings';
                $keys = ['logo_text', 'banner_desc', 'link_bypass', 'link_tele_group', 'footer_made_by', 'footer_year'];
                $clean = [];
                foreach ($keys as $k) {
                    $v = trim((string)($_POST['cfg'][$k] ?? ''));
                    if (in_array($k, ['link_bypass', 'link_tele_group'], true) && $v !== '' && (bad_link($v) || !valid_url($v)))
                        throw new RuntimeException("Link phải bắt đầu bằng http:// hoặc https:// ở trường: " . $k);
                    if (slen($v) > 255) throw new RuntimeException("Giá trị quá dài (tối đa 255 ký tự) ở trường: " . $k);
                    $clean[$k] = $v;
                }
                $newAva = null;
                if (($_FILES['site_ava']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    [$newAva, $err] = save_image($_FILES['site_ava'], $UP, 'avatar_');
                    if ($err) throw new RuntimeException($err);
                    $newFiles[] = $newAva;
                }
                foreach ($clean as $k => $v) cfg_set($conn, $k, $v);
                if ($newAva !== null) {
                    $old = one($conn, "SELECT config_value FROM site_config WHERE config_key = 'site_avatar'");
                    cfg_set($conn, 'site_avatar', $newAva);
                    safe_unlink($old['config_value'] ?? '', $UP);
                    $newFiles = [];
                }
                admin_log($conn, 'save_site', "Lưu cấu hình giao diện" . ($newAva !== null ? " + đổi avatar" : ''));
                flash("Đã lưu cấu hình giao diện. Ảnh avatar được cập nhật tức thì!");
                break;

            case 'theme_import':
                $tab = 'theme';
                $jj = json_decode((string)($_POST['theme_json'] ?? ''), true);
                if (!is_array($jj) || !$jj) throw new RuntimeException("Nội dung nhập không phải JSON hợp lệ.");
                $known = [];
                foreach ($TH as $gf) { foreach ($gf as $gk => $gdef) $known[$gk] = $gdef; }
                foreach ($TX as $gtk => $gtd) $known['ds_t_' . $gtk] = [$gtk, 'text'];
                $cnt = 0;
                foreach ($jj as $gk => $gv) {
                    if (!is_string($gk) || is_array($gv) || is_object($gv)) continue;
                    $gv = str_replace("\r\n", "\n", trim((string)$gv));
                    if (isset($known[$gk])) {
                        $gv = adv_clean($known[$gk][0], $known[$gk], $gv);
                    } elseif ($gk === 'ds_hero_images') {
                        foreach (preg_split('/[\s,]+/', $gv, -1, PREG_SPLIT_NO_EMPTY) as $gu) {
                            if (!img_ok($gu)) throw new RuntimeException("ds_hero_images: link ảnh không hợp lệ ($gu)");
                        }
                    } elseif ($gk === 'ds_avatar_url') {
                        if ($gv !== '' && !img_ok($gv)) throw new RuntimeException("ds_avatar_url: link ảnh không hợp lệ.");
                    } elseif ($gk === 'ds_tool_meta') {
                        if ($gv !== '' && !is_array(json_decode($gv, true))) throw new RuntimeException("ds_tool_meta: không phải JSON hợp lệ.");
                    } else {
                        continue;
                    }
                    if ($gv === null) continue;
                    cfg_set($conn, $gk, $gv);
                    $cnt++;
                }
                admin_log($conn, 'theme_import', "Nhập $cnt mục giao diện");
                flash("Đã nhập $cnt mục giao diện. Tải lại trang chủ để thấy thay đổi.");
                break;

            case 'theme_reset':
                $tab = 'theme';
                $rk = [];
                foreach ($TH as $gf) { foreach ($gf as $gk => $gdef) $rk[] = $gk; }
                foreach ($TX as $gtk => $gtd) $rk[] = 'ds_t_' . $gtk;
                $rk = array_values(array_unique($rk));
                $st = run($conn, "DELETE FROM site_config WHERE config_key IN (" . implode(',', array_fill(0, count($rk), '?')) . ")", str_repeat('s', count($rk)), $rk);
                $cnt = max(0, mysqli_stmt_affected_rows($st));
                admin_log($conn, 'theme_reset', "Khôi phục mặc định $cnt mục giao diện");
                flash("Đã khôi phục mặc định $cnt mục giao diện. Ảnh banner, avatar và thông tin từng tool được giữ nguyên.");
                break;

            case 'save_mt':
                $tab = 'maint';
                $todo = collect_changes($ADV, (array)($_POST['adv'] ?? []), $cf);
                foreach ($todo as $k => $val) cfg_set($conn, $k, $val);
                if ($todo) admin_log($conn, 'save_maintenance_text', "Đổi: " . implode(', ', array_keys($todo)));
                flash($todo ? "Đã lưu nội dung trang bảo trì." : "Không có mục nào thay đổi.");
                break;

            case 'mt_tool_save':
                $tab = 'maint';
                $id = (int)($_POST['id'] ?? 0);
                $p = one($conn, "SELECT name FROM products WHERE id = ?", 'i', [$id]);
                if (!$p) throw new RuntimeException("Không tìm thấy tool này.");
                $status = (($_POST['status'] ?? '') === 'maintenance') ? 'maintenance' : 'active';
                $note = scut(trim((string)($_POST['note'] ?? '')), 200);
                $meta = meta_load($cf);
                $m = isset($meta[$id]) && is_array($meta[$id]) ? $meta[$id] : [];
                if ($status === 'maintenance') $m['status'] = 'maintenance'; else unset($m['status']);
                if ($note !== '') $m['note'] = $note; else unset($m['note']);
                if ($m) $meta[$id] = $m; else unset($meta[$id]);
                meta_save($conn, $meta);
                admin_log($conn, 'tool_status', "Tool #" . $id . ' ' . $p['name'] . ' -> ' . $status);
                flash($status === 'maintenance' ? "Đã chuyển «" . $p['name'] . "» sang BẢO TRÌ." : "«" . $p['name'] . "» đã hoạt động trở lại.");
                break;

            case 'mt_tools_all':
                $tab = 'maint';
                $status = (($_POST['status'] ?? '') === 'maintenance') ? 'maintenance' : 'active';
                $meta = meta_load($cf);
                $cnt = 0;
                foreach (fetch_all($conn, "SELECT id FROM products") as $r) {
                    $id = (int)$r['id'];
                    $m = isset($meta[$id]) && is_array($meta[$id]) ? $meta[$id] : [];
                    if ($status === 'maintenance') $m['status'] = 'maintenance'; else unset($m['status']);
                    if ($m) $meta[$id] = $m; else unset($meta[$id]);
                    $cnt++;
                }
                meta_save($conn, $meta);
                admin_log($conn, 'tool_status_all', "$cnt tool -> $status");
                flash($status === 'maintenance' ? "Đã chuyển $cnt tool sang BẢO TRÌ." : "Đã mở lại $cnt tool (Active).");
                break;

            case 'lock_unlock':
                $tab = 'locks';
                $ts = (int)($_POST['ts'] ?? 0);
                if ($ts <= 0) throw new RuntimeException("Thiếu mã khoá cần mở.");
                $n = admx_lock_remove([$ts]);
                if ($n < 1) throw new RuntimeException("Khoá này đã hết hạn hoặc đã được mở trước đó.");
                admx_unlock_epoch($conn);
                admin_log($conn, 'key_unlock', "Mở khoá get key (mốc $ts, $n mục)");
                flash("Đã mở khoá. Tài khoản / IP này get key tiếp được ngay (nếu web đang mở thì tải lại trang).");
                break;

            case 'lock_unlock_all':
                $tab = 'locks';
                $n = admx_lock_clear_all();
                if ($n < 1) throw new RuntimeException("Hiện không có khoá nào để mở.");
                admx_unlock_epoch($conn);
                admin_log($conn, 'key_unlock_all', "Mở khoá get key tất cả ($n mục)");
                flash("Đã mở khoá tất cả ($n mục).");
                break;

            case 'lock_unlock_user':
                $tab = (($_POST['back'] ?? '') === 'locks') ? 'locks' : 'members';
                $id = (int)($_POST['id'] ?? 0);
                $u = one($conn, "SELECT username FROM users WHERE id = ?", 'i', [$id]);
                if (!$u) throw new RuntimeException("Không tìm thấy thành viên.");
                $n = admx_lock_unlock_users(['u_' . $id, 'u_' . strtolower(trim((string)$u['username']))]);
                if ($n < 1) throw new RuntimeException("Tài khoản này hiện không bị khoá get key.");
                admx_unlock_epoch($conn);
                admin_log($conn, 'key_unlock_user', "Mở khoá get key cho " . $u['username']);
                flash("Đã mở khoá get key cho «" . $u['username'] . "».");
                break;

            case 'member_extend':
                $tab = 'members';
                $id = (int)($_POST['id'] ?? 0);
                $h = (int)($_POST['hours'] ?? 0);
                if ($h === 0 || $h < -87600 || $h > 87600) throw new RuntimeException("Số giờ không hợp lệ (khác 0, tối đa 87600 giờ).");
                $u = one($conn, "SELECT username, key_type FROM users WHERE id = ? AND role != 1", 'i', [$id]);
                if (!$u) throw new RuntimeException("Thành viên không tồn tại hoặc là tài khoản quản trị.");
                if (($u['key_type'] ?? 'NONE') === 'NONE') throw new RuntimeException("Thành viên này chưa có key, hãy cấp key trước rồi mới cộng giờ.");
                run($conn, "UPDATE users SET expiry_date = DATE_ADD(GREATEST(IFNULL(expiry_date, NOW()), NOW()), INTERVAL ? HOUR) WHERE id = ? AND role != 1", 'ii', [$h, $id]);
                admin_log($conn, 'member_extend', ($h > 0 ? "Cộng " : "Trừ ") . abs($h) . " giờ key của " . $u['username']);
                flash(($h > 0 ? "Đã cộng " : "Đã trừ ") . abs($h) . " giờ key cho «" . $u['username'] . "».");
                break;

            case 'member_turns':
                $tab = 'members';
                $id = (int)($_POST['id'] ?? 0);
                if (!col_exists($conn, 'users', 'turns_left')) throw new RuntimeException("Bảng users không có cột turns_left.");
                $t = (int)($_POST['turns'] ?? -1);
                if ($t < 0 || $t > 9999) throw new RuntimeException("Số lượt phải từ 0 đến 9999.");
                $u = one($conn, "SELECT username FROM users WHERE id = ? AND role != 1", 'i', [$id]);
                if (!$u) throw new RuntimeException("Thành viên không tồn tại hoặc là tài khoản quản trị.");
                run($conn, "UPDATE users SET turns_left = ? WHERE id = ? AND role != 1", 'ii', [$t, $id]);
                admin_log($conn, 'member_turns', "Đặt $t lượt cho " . $u['username']);
                flash("Đã đặt $t lượt cho «" . $u['username'] . "».");
                break;

            case 'member_msg':
                $tab = 'members';
                $id = (int)($_POST['id'] ?? 0);
                if (!col_exists($conn, 'users', 'alert_msg')) throw new RuntimeException("Bảng users không có cột alert_msg.");
                $msg = scut(trim((string)($_POST['msg'] ?? '')), 200);
                if ($msg === '') throw new RuntimeException("Hãy nhập nội dung thông báo.");
                $u = one($conn, "SELECT username FROM users WHERE id = ? AND role != 1", 'i', [$id]);
                if (!$u) throw new RuntimeException("Thành viên không tồn tại hoặc là tài khoản quản trị.");
                run($conn, "UPDATE users SET alert_msg = ? WHERE id = ? AND role != 1", 'si', [$msg, $id]);
                admin_log($conn, 'member_msg', "Gửi thông báo cho " . $u['username']);
                flash("Đã gửi thông báo cho «" . $u['username'] . "». Thành viên sẽ thấy khi vào web.");
                break;

            case 'save_adv':
                $tab = 'settings';
                $todo = collect_changes($ADV, (array)($_POST['adv'] ?? []), $cf);
                foreach ($todo as $k => $val) cfg_set($conn, $k, $val);
                if ($todo) admin_log($conn, 'save_advanced', "Đổi: " . implode(', ', array_keys($todo)));
                flash($todo ? "Đã lưu " . count($todo) . " mục cấu hình nâng cao." : "Không có mục nào thay đổi.");
                break;

            /*FKX-POST-BEGIN*/
            case 'fk_unlock_claim':
            case 'fk_unlock_user':
            case 'fk_unlock_ip':
            case 'fk_unlock_all':
            case 'fk_view_open':
            case 'fk_view_user':
            case 'fk_unlock_full':
                $tab = (($_POST['back'] ?? '') === 'members') ? 'members' : 'fkx';
                if (empty($FKX_ON)) throw new RuntimeException("Chưa nạp được fkx_lib.php (xem error_log của máy chủ).");
                flash(fkx_do($conn, $act, $_POST));
                break;

            case 'member_grant':
                $tab = 'members';
                if (empty($FKX_ON)) throw new RuntimeException("Chưa nạp được fkx_lib.php (xem error_log của máy chủ).");
                flash(fkx_grant($conn, $_POST));
                break;
            /*FKX-POST-END*/
            default:
                throw new RuntimeException("Thao tác không xác định.");
        }
    } catch (mysqli_sql_exception $ex) {
        foreach ($newFiles as $nf) safe_unlink($nf, $UP);
        error_log('[admin_cp] SQL: ' . $ex->getMessage());
        flash("Lỗi cơ sở dữ liệu. Chi tiết đã được ghi vào error_log của máy chủ.", 'err');
    } catch (RuntimeException $ex) {
        foreach ($newFiles as $nf) safe_unlink($nf, $UP);
        flash($ex->getMessage(), 'err');
    } catch (Throwable $ex) {
        foreach ($newFiles as $nf) safe_unlink($nf, $UP);
        error_log('[admin_cp] ' . $ex->getMessage());
        flash("Lỗi máy chủ: " . $ex->getMessage(), 'err');
    }
    go($tab);
}

/* ================= LAY DU LIEU ================= */
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

$is_mt = (($cf['maintenance_mode'] ?? '0') === '1');

// Link + mat khau truy cap bao tri: CHI co khi bao tri dang bat
$mt_token = $is_mt ? (string)($cf['maintenance_access_token'] ?? '') : '';
$mt_link = '';
if ($mt_token !== '') {
    $mtHost = (string)($_SERVER['HTTP_HOST'] ?? '');
    $mtHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $mt_link = (preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $mtHost) ? ($mtHttps ? 'https' : 'http') . '://' . $mtHost : '')
        . MT_ACCESS_LINK_PATH . $mt_token;
}
$mt_pw = $is_mt ? (string)($_SESSION['mt_pw_plain'] ?? '') : '';

$vip     = fetch_all($conn, "SELECT * FROM keys_vip ORDER BY status ASC, id DESC");
$free    = fetch_all($conn, "SELECT * FROM keys_free ORDER BY status ASC, id DESC");
$prods   = fetch_all($conn, "SELECT * FROM products ORDER BY id DESC");
$members = fetch_all($conn, "SELECT *, UNIX_TIMESTAMP(expiry_date) AS exp_ts FROM users ORDER BY id DESC");
foreach ($members as $mi => $mrow) unset($members[$mi]['password']); // hash mat khau khong bao gio can o trang nay

$vip_open  = count(array_filter($vip,  function ($k) { return (int)$k['status'] === 0; }));
$free_open = count(array_filter($free, function ($k) { return (int)$k['status'] === 0; }));

$nowRow = one($conn, "SELECT UNIX_TIMESTAMP() AS n");
$now = (int)($nowRow['n'] ?? time());
$active_members = count(array_filter($members, function ($u) use ($now) {
    return ($u['key_type'] ?? 'NONE') !== 'NONE' && !empty($u['exp_ts']) && (int)$u['exp_ts'] > $now;
}));

$admin_logs = table_exists($conn, 'admin_logs')
    ? fetch_all($conn, "SELECT username, action, detail, ip, created_at FROM admin_logs ORDER BY id DESC LIMIT 30") : [];
$sec_logs = table_exists($conn, 'security_logs')
    ? fetch_all($conn, "SELECT ip_address, violation_type, created_at FROM security_logs ORDER BY id DESC LIMIT 30") : [];

$ava = $cf['site_avatar'] ?? '';
$ava_src = $ava ? $ava . (file_exists($ava) ? '?v=' . filemtime($ava) : '') : '';

$meta     = meta_load($cf);
$heroList = hero_list($cf);
$dsAvatar = trim((string)($cf['ds_avatar_url'] ?? ''));
$mt_tools = 0;
foreach ($prods as $pp) { if ((($meta[(int)$pp['id']]['status'] ?? '') === 'maintenance')) $mt_tools++; }

$logoSrc = trim((string)($cf['brand_logo'] ?? ''));
if ($logoSrc === '') $logoSrc = ADMIN_DEFAULT_LOGO;
if (!preg_match('#^(https?://|/)#i', $logoSrc)) $logoSrc = '/' . ltrim($logoSrc, '/\\');
$skewRaw = trim((string)($cf['ds_logo_skew'] ?? ''));
$logoSkew = is_numeric($skewRaw) ? max(-30, min(30, (float)$skewRaw)) : 10;
$shineRaw = trim((string)($cf['ds_shine_seconds'] ?? ''));
$logoShine = is_numeric($shineRaw) ? max(0.8, min(20, (float)$shineRaw)) : 1.6;

/*ADMX-DATA-BEGIN*/
list($admxEvents, $admxRoot) = admx_lock_events($conn);
$admxLockCount = count($admxEvents);
$admxLockMap = [];
foreach ($admxEvents as $admxE) {
    foreach ($admxE['users'] as $admxU) { $admxLockMap[strtolower($admxU['key'])] = $admxE['until']; }
}
$admxLockHours = admx_lock_hours();
$admxLockFile = admx_lock_file();
$admxLockWritable = is_file($admxLockFile) ? is_writable($admxLockFile) : is_writable(__DIR__);
$admxHasTurns = col_exists($conn, 'users', 'turns_left');
$admxHasAlert = col_exists($conn, 'users', 'alert_msg');
$admxMtFields = [];
foreach ($ADV as $admxSec => $admxFs) {
    foreach (['maintenance_title', 'maintenance_notice', 'maintenance_show_login'] as $admxK) {
        if (isset($admxFs[$admxK])) $admxMtFields[$admxK] = $admxFs[$admxK];
    }
}
/*ADMX-DATA-END*/
/*FKX-DATA-BEGIN*/
fkx_ui('load', $conn);
/*FKX-DATA-END*/

/* ================= KHOI RENDER KHO KEY ================= */
function render_key_box($type, $rows, $open) {
    $isVip = ($type === 'vip');
    $label = $isVip ? 'VIP' : 'FREE';
    ?>
    <section class="card keybox" data-seg="<?= $type ?>" data-mode="all">
        <form method="post" class="kform">
            <?= csrf() ?>
            <input type="hidden" name="action" value="bulk_delete">
            <input type="hidden" name="type" value="<?= $type ?>">

            <div class="card-header border-b">
                <div class="flex-1">
                    <div class="flex-align gap-2">
                        <span class="badge <?= $isVip ? 'badge-vip' : 'badge-neutral' ?>"><?= $label ?></span>
                        <h3 class="card-title">Kho key <?= $label ?></h3>
                    </div>
                    <p class="muted-text text-xs mt-1">
                        Hiện <span class="js-shown font-semibold"><?= count($rows) ?></span>/<?= count($rows) ?> key &bull; <b class="text-ok"><?= $open ?></b> chưa dùng
                    </p>
                </div>
                <button type="button" class="btn btn-ghost btn-sm js-copy"><?= ic('copy') ?> Copy trống</button>
            </div>

            <div class="toolbar">
                <div class="search-input-wrap">
                    <?= ic('search') ?>
                    <input type="search" class="search-input js-q" placeholder="Tìm key code..." autocomplete="off">
                </div>
                <div class="chips-group" role="group">
                    <button type="button" class="chip-item" data-m="all" aria-pressed="true">Tất cả</button>
                    <button type="button" class="chip-item" data-m="free" aria-pressed="false">Trống</button>
                    <button type="button" class="chip-item" data-m="used" aria-pressed="false">Đã dùng</button>
                </div>
            </div>

            <div class="bulk-action-bar">
                <label class="custom-chk"><input type="checkbox" class="js-all"><span>Chọn tất cả danh sách</span></label>
                <button type="button" class="btn btn-danger-outline btn-xs js-bulk" disabled><?= ic('trash') ?> Xóa mục chọn</button>
            </div>

            <ul class="key-scroll-list">
                <?php foreach ($rows as $k): $used = (int)$k['status'] === 1; ?>
                <li class="key-item-row" data-code="<?= e($k['key_code']) ?>" data-used="<?= $used ? 1 : 0 ?>">
                    <label class="custom-chk"><input type="checkbox" name="ids[]" value="<?= (int)$k['id'] ?>"></label>
                    <div class="key-code-info">
                        <code class="key-code-text"><?= e($k['key_code']) ?></code>
                        <span class="key-time-meta"><?= (int)$k['duration_hours'] ?> giờ<?php if ($used && !empty($k['used_by'])): ?> &bull; <?= e($k['used_by']) ?><?php if (!empty($k['used_at'])): ?> &bull; <?= e(date('d/m H:i', strtotime($k['used_at']))) ?><?php endif; ?><?php endif; ?></span>
                    </div>
                    <span class="badge <?= $used ? 'badge-bad' : 'badge-ok' ?>"><?= $used ? "Đã kích hoạt" : "Khả dụng" ?></span>
                    <button type="button" class="action-icon-btn" title="Xóa key này"
                        data-act="del_key" data-fields="<?= e(json_encode(['type' => $type, 'id' => (int)$k['id']])) ?>"
                        data-title="Xóa key <?= $label ?> này?" data-desc="Mã <?= e($k['key_code']) ?> sẽ bị xóa vĩnh viễn khỏi kho.">
                        <?= ic('trash') ?>
                    </button>
                </li>
                <?php endforeach; ?>
            </ul>

            <div class="empty-state js-empty" <?= $rows ? 'hidden' : '' ?>><?= $rows ? "Không tìm thấy key nào phù hợp với bộ lọc." : "Kho key hiện đang trống." ?></div>
        </form>
    </section>
    <?php
}
?>
<!DOCTYPE html>
<html lang="vi" data-adm="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Admin &bull; Quản trị hệ thống</title>
<script>try{if(localStorage.getItem('adm-theme')==='light')document.documentElement.setAttribute('data-adm','light');}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#07090d;--bg2:#0c0f15;--card:#10141b;--card2:#161b24;--line:#1f2530;--line2:#2a3140;
  --tx:#eaeef6;--mu:#8b95aa;--su:#5b657a;
  --pr:#0a84ff;--pr2:#2f95ff;--prs:rgba(10,132,255,.14);
  --dg:#ff6259;--dgs:rgba(255,98,89,.12);--dgl:rgba(255,98,89,.38);
  --ok:#34c77b;--oks:rgba(52,199,123,.13);--okl:rgba(52,199,123,.32);
  --wn:#ffa826;--wns:rgba(255,168,38,.13);--wnl:rgba(255,168,38,.35);
  --font:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;--mono:'JetBrains Mono',Consolas,monospace;
  --r:10px;--rl:16px;color-scheme:dark;
}
html[data-adm="light"]{
  --bg:#f2f4f8;--bg2:#e8ecf3;--card:#ffffff;--card2:#f6f8fb;--line:#e2e7ef;--line2:#ccd4e1;
  --tx:#0e1420;--mu:#586275;--su:#8892a5;--pr:#0a6cdc;--pr2:#0a84ff;--prs:rgba(10,108,220,.1);
  --dg:#d93a31;--dgs:rgba(217,58,49,.09);--dgl:rgba(217,58,49,.3);--ok:#13924f;--oks:rgba(19,146,79,.1);--okl:rgba(19,146,79,.28);
  --wn:#b36200;--wns:rgba(179,98,0,.1);--wnl:rgba(179,98,0,.3);color-scheme:light;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%;scroll-behavior:smooth}
body{font:14px/1.5 var(--font);background:var(--bg);color:var(--tx);min-height:100vh;padding-bottom:calc(40px + env(safe-area-inset-bottom));-webkit-font-smoothing:antialiased}
.wrap{max-width:1120px;margin:0 auto;padding:0 16px}
.flex-align{display:flex;align-items:center}.flex-between{display:flex;align-items:center;justify-content:space-between}
.gap-2{gap:8px}.gap-3{gap:12px}.flex-1{flex:1;min-width:0}
.text-xs{font-size:12px}.muted-text{color:var(--mu)}.font-semibold{font-weight:600}.text-ok{color:var(--ok)}.mt-1{margin-top:4px}
.border-b{border-bottom:1px solid var(--line)}
.ic{width:16px;height:16px;flex-shrink:0}
.key-name{font-weight:400;font-family:var(--mono);font-size:11px}

/* Thanh dau (luon toi nhu header web de logo nhin ro) */
.topbar{position:sticky;top:0;z-index:40;background:linear-gradient(180deg,#171a22,#0e1016);border-bottom:1px solid #232834;padding-top:env(safe-area-inset-top);color:#fff}
.topbar-inner{min-height:58px;display:flex;align-items:center;justify-content:space-between;gap:10px}
.brand-box{display:flex;align-items:center;gap:12px;text-decoration:none;color:#fff;min-width:0}
.logo{position:relative;display:inline-block;height:36px;overflow:hidden;line-height:0;flex:0 1 auto;min-width:0;transform:translateZ(0) skewX(<?= number_format($logoSkew, 2, '.', '') ?>deg)}
.logo-img{display:block;height:36px;width:auto;max-width:200px;object-fit:contain}
.logo-sw{position:absolute;top:0;left:0;width:100%;height:100%;overflow:hidden;pointer-events:none;
  -webkit-mask-image:linear-gradient(90deg,rgba(0,0,0,0) 8%,rgba(0,0,0,.55) 28%,#000 50%,rgba(0,0,0,.55) 72%,rgba(0,0,0,0) 92%);
  mask-image:linear-gradient(90deg,rgba(0,0,0,0) 8%,rgba(0,0,0,.55) 28%,#000 50%,rgba(0,0,0,.55) 72%,rgba(0,0,0,0) 92%);
  will-change:transform;animation:logo-sw <?= number_format($logoShine, 2, '.', '') ?>s linear infinite}
.logo-swi{position:absolute;top:0;left:0;display:block;width:100%;height:100%;max-width:none;object-fit:contain;-webkit-filter:brightness(0) invert(1);filter:brightness(0) invert(1);opacity:.95;will-change:transform;animation:logo-swi <?= number_format($logoShine, 2, '.', '') ?>s linear infinite}
.logo-sw2,.logo-sw2 .logo-swi{animation-delay:-<?= number_format($logoShine / 2, 2, '.', '') ?>s}
@keyframes logo-sw{from{transform:translate3d(-78%,0,0)}to{transform:translate3d(78%,0,0)}}
@keyframes logo-swi{from{transform:translate3d(78%,0,0)}to{transform:translate3d(-78%,0,0)}}
@media (prefers-reduced-motion:reduce){.logo-sw{display:none}}
.brand-pill{font-size:11px;font-weight:600;padding:3px 9px;border-radius:999px;background:rgba(255,255,255,.08);color:#aeb7ca;white-space:nowrap}
.top-actions{display:flex;align-items:center;gap:8px}
.icon-btn{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:var(--r);border:1px solid #2a3140;background:rgba(255,255,255,.04);color:#cfd6e6;cursor:pointer}
.icon-btn:hover{background:rgba(255,255,255,.09)}
.icon-btn .ic{width:17px;height:17px}
.mt-toggle-btn{display:inline-flex;align-items:center;gap:8px;height:34px;padding:0 12px;border-radius:var(--r);border:1px solid #2a3140;background:rgba(255,255,255,.04);color:#e8ecf6;font:600 13px var(--font);cursor:pointer;white-space:nowrap}
.mt-indicator{width:8px;height:8px;border-radius:50%;background:var(--ok)}
.mt-toggle-btn.is-active{background:var(--dgs);border-color:var(--dgl);color:#ff8f88}
.mt-toggle-btn.is-active .mt-indicator{background:var(--dg)}
.home-link-btn{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 10px;border-radius:var(--r);font-size:13px;color:#aeb7ca;text-decoration:none;font-weight:500;white-space:nowrap}
.home-link-btn:hover{color:#fff}
.mt-alert-banner{background:var(--wns);border-bottom:1px solid var(--wnl);color:var(--wn);font-size:13px;font-weight:500;padding:9px 0}

.stats-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin:18px 0}
.stat-card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:14px 16px}
.stat-num{font-size:24px;font-weight:700;line-height:1.1;font-variant-numeric:tabular-nums;letter-spacing:-.02em}
.stat-sub{font-size:12px;color:var(--mu);margin-top:4px}

.tabs-nav{display:flex;gap:4px;border-bottom:1px solid var(--line);margin-bottom:18px;overflow-x:auto;scrollbar-width:none}
.tabs-nav::-webkit-scrollbar{display:none}
.tab-link{display:inline-flex;align-items:center;gap:6px;padding:11px 14px;text-decoration:none;font-size:13px;font-weight:500;color:var(--mu);border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap}
.tab-link:hover{color:var(--tx)}
.tab-link[aria-current="page"]{color:var(--pr2);border-bottom-color:var(--pr2);font-weight:600}
.panel[hidden]{display:none}

.card{background:var(--card);border:1px solid var(--line);border-radius:var(--rl);margin-bottom:16px;overflow:hidden}
.card-header{padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:10px}
.card-title{font-size:14px;font-weight:600;display:flex;align-items:center;gap:8px}
.card-body{padding:16px}
.form-group{margin-bottom:14px}
.form-label{display:block;font-size:12px;font-weight:600;margin-bottom:6px}
.form-control{width:100%;height:38px;padding:0 12px;font:13px var(--font);border:1px solid var(--line2);border-radius:var(--r);background:var(--bg2);color:var(--tx);outline:none;transition:border-color .15s}
.form-control:focus{border-color:var(--pr)}
select.form-control{appearance:auto}
input[type="file"].form-control{padding-top:5px;height:auto;min-height:38px;padding-bottom:5px}
.form-textarea{height:auto;min-height:84px;padding:8px 12px;resize:vertical;line-height:1.45}
.form-hint{font-size:12px;color:var(--mu)}
.form-grid-2{display:grid;grid-template-columns:1fr;gap:12px}
.color-row{display:flex;gap:8px}
.color-pick{width:44px;height:38px;padding:2px;border:1px solid var(--line2);border-radius:var(--r);background:var(--bg2);cursor:pointer}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:38px;padding:0 14px;border:1px solid transparent;border-radius:var(--r);font:500 13px var(--font);cursor:pointer;background:var(--pr);color:#fff;white-space:nowrap;transition:background .15s}
.btn:hover{background:var(--pr2)}
.btn-sm{height:32px;padding:0 10px;font-size:12px}.btn-xs{height:26px;padding:0 8px;font-size:11px;font-weight:600}
.btn-ghost{background:transparent;border-color:var(--line2);color:var(--tx)}.btn-ghost:hover{background:var(--card2)}
.btn-danger-outline{background:transparent;border-color:var(--dgl);color:var(--dg)}.btn-danger-outline:hover{background:var(--dgs)}
.btn-danger{background:var(--dg);color:#fff}.btn-danger:hover{background:#ff7a72}
.btn-full{width:100%}
.btn:disabled{opacity:.4;cursor:not-allowed}

.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap}
.badge-ok{background:var(--oks);color:var(--ok);border:1px solid var(--okl)}
.badge-bad{background:var(--dgs);color:var(--dg);border:1px solid var(--dgl)}
.badge-vip{background:var(--prs);color:var(--pr2)}
.badge-neutral{background:var(--card2);color:var(--mu);border:1px solid var(--line)}
.status-pill{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;font:600 11px var(--font);cursor:pointer;border:1px solid}
.status-pill::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
.status-pill.is-ok{background:var(--oks);color:var(--ok);border-color:var(--okl)}
.status-pill.is-mt{background:var(--wns);color:var(--wn);border-color:var(--wnl)}

.gen-grid{display:grid;grid-template-columns:1fr;gap:12px}
.key-tabs-selector{display:flex;padding:3px;background:var(--card2);border-radius:var(--r);margin-bottom:14px;border:1px solid var(--line)}
.key-tabs-selector button{flex:1;height:32px;border:none;background:transparent;font:600 12px var(--font);color:var(--mu);border-radius:7px;cursor:pointer}
.key-tabs-selector button[aria-pressed="true"]{background:var(--pr);color:#fff}
.keys-container{display:block}.keybox{display:none}
.keys-container[data-seg="vip"] .keybox[data-seg="vip"],.keys-container[data-seg="free"] .keybox[data-seg="free"]{display:block}
.toolbar{display:flex;flex-direction:column;gap:8px;padding:10px 14px;border-bottom:1px solid var(--line)}
.search-input-wrap{position:relative;display:flex;align-items:center}
.search-input-wrap .ic{position:absolute;left:10px;color:var(--su);pointer-events:none}
.search-input{width:100%;height:34px;padding-left:32px;padding-right:10px;font:13px var(--font);border:1px solid var(--line2);border-radius:var(--r);outline:none;background:var(--bg2);color:var(--tx)}
.search-input:focus{border-color:var(--pr)}
.chips-group{display:flex;gap:6px}
.chip-item{height:26px;padding:0 11px;border-radius:13px;border:1px solid var(--line2);background:transparent;font:500 12px var(--font);color:var(--mu);cursor:pointer}
.chip-item[aria-pressed="true"]{background:var(--tx);border-color:var(--tx);color:var(--bg)}
.bulk-action-bar{display:flex;align-items:center;justify-content:space-between;padding:8px 14px;background:var(--card2);border-bottom:1px solid var(--line);font-size:12px}
.custom-chk{display:inline-flex;align-items:center;gap:7px;cursor:pointer;user-select:none}
.custom-chk input{width:15px;height:15px;accent-color:var(--pr);margin:0}
.key-scroll-list{list-style:none;max-height:480px;overflow-y:auto}
.key-item-row{display:flex;align-items:center;gap:10px;padding:8px 14px;border-bottom:1px solid var(--line)}
.key-item-row[hidden]{display:none}
.key-item-row[data-used="1"] .key-code-text{color:var(--su);text-decoration:line-through}
.key-code-info{flex:1;min-width:0;display:flex;flex-direction:column}
.key-code-text{font-family:var(--mono);font-size:12px;font-weight:600;overflow-wrap:anywhere}
.key-time-meta{font-size:11px;color:var(--mu)}
.action-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border:none;border-radius:7px;background:transparent;color:var(--su);cursor:pointer;flex-shrink:0}
.action-icon-btn:hover{background:var(--dgs);color:var(--dg)}
.empty-state{padding:32px 16px;text-align:center;color:var(--mu);font-size:13px}.empty-state[hidden]{display:none}

.tools-layout{display:grid;grid-template-columns:1fr;gap:16px}
.tool-card{border-bottom:1px solid var(--line)}.tool-card:last-child{border-bottom:none}
.tool-item-card{display:flex;align-items:center;gap:10px;padding:10px 14px;flex-wrap:wrap}
.tool-thumb{width:42px;height:42px;border-radius:11px;object-fit:cover;background:var(--card2);border:1px solid var(--line);flex-shrink:0}
.tool-title{font-weight:600;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tool-link-meta{font-size:11px;color:var(--mu);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tool-edit{padding:4px 14px 16px;border-top:1px dashed var(--line)}.tool-edit[hidden]{display:none}
.pv-grid{display:grid;grid-template-columns:1fr;gap:12px;margin-bottom:14px}
.pv-slot{border:1px solid var(--line);border-radius:var(--r);padding:10px;background:var(--bg2)}
.pv-thumb{height:110px;border-radius:8px;background:var(--card2);display:flex;align-items:center;justify-content:center;color:var(--su);font-size:12px;margin-bottom:8px;overflow:hidden}
.pv-thumb img{width:100%;height:100%;object-fit:cover}
.pv-slot .form-control{margin-bottom:6px;height:34px}
.member-row{display:flex;align-items:center;gap:12px;padding:12px 14px;border-bottom:1px solid var(--line)}
.member-row:last-child{border-bottom:none}.member-row[hidden]{display:none}

.avatar-setting-box{display:flex;align-items:center;gap:14px;padding:12px;border:1px solid var(--line);border-radius:var(--r);background:var(--bg2)}
.avatar-preview-img{width:46px;height:46px;border-radius:50%;object-fit:cover;background:var(--card2);border:2px solid var(--line2);flex-shrink:0}
.hero-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:16px}
.hero-item{border:1px solid var(--line);border-radius:var(--r);overflow:hidden;background:var(--bg2)}
.hero-item img{display:block;width:100%;aspect-ratio:16/9;object-fit:cover;background:var(--card2)}
.hero-meta{display:flex;align-items:center;justify-content:space-between;padding:6px 8px}
.hero-btns{display:flex;gap:2px}

.adv-group{border:1px solid var(--line);border-radius:var(--r);margin-bottom:10px;background:var(--card)}
.adv-group>summary{padding:11px 14px;font-size:13px;font-weight:600;cursor:pointer;list-style:none}
.adv-group>summary::-webkit-details-marker{display:none}
.adv-group>summary::before{content:"\25B8";display:inline-block;margin-right:8px;color:var(--su);transition:transform .15s}
.adv-group[open]>summary::before{transform:rotate(90deg)}
.adv-group[open]>summary{border-bottom:1px solid var(--line)}
.adv-body{padding:14px 14px 2px}
.log-row{display:flex;align-items:center;gap:12px;padding:9px 14px;border-bottom:1px solid var(--line)}
.log-row:last-child{border-bottom:none}
.log-meta{font-size:11px;color:var(--mu);overflow-wrap:anywhere}
.mt-access{padding:14px 0 0}
.mt-access-box{background:var(--card);border:1px solid var(--line);border-radius:var(--rl);padding:14px 16px}
.mt-access-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px;font-size:14px}
.mt-copy-row{display:flex;gap:8px;margin-bottom:12px}
.mt-copy-row .form-control{font-family:var(--mono);font-size:12px}

dialog{border:1px solid var(--line2);border-radius:var(--rl);padding:0;width:calc(100vw - 32px);max-width:360px;color:var(--tx);background:var(--card);box-shadow:0 12px 40px rgba(0,0,0,.5)}
dialog::backdrop{background:rgba(0,0,0,.6)}
.modal-body{padding:18px 20px}.modal-title{font-size:15px;font-weight:700;margin-bottom:6px}
.modal-desc{font-size:13px;color:var(--mu);line-height:1.45}
.modal-actions{display:flex;gap:8px;justify-content:flex-end;padding:0 20px 18px}
.toast-notice{position:fixed;left:50%;bottom:calc(20px + env(safe-area-inset-bottom));transform:translate(-50%,16px);max-width:calc(100vw - 32px);width:max-content;padding:10px 16px;border-radius:var(--r);background:var(--card2);border:1px solid var(--line2);color:var(--tx);font-size:13px;font-weight:500;opacity:0;pointer-events:none;transition:opacity .2s,transform .2s;z-index:70;box-shadow:0 8px 28px rgba(0,0,0,.4)}
.toast-notice.show{opacity:1;transform:translate(-50%,0)}
.toast-notice.toast-err{background:var(--dg);border-color:var(--dg);color:#fff}

@media (min-width:640px){
  .form-grid-2{grid-template-columns:1fr 1fr}.gen-grid{grid-template-columns:1fr 120px auto}
  .toolbar{flex-direction:row;align-items:center}.search-input-wrap{flex:1}
  .pv-grid{grid-template-columns:repeat(3,1fr)}
}
@media (min-width:900px){
  .stats-grid{grid-template-columns:repeat(4,1fr);gap:14px}
  .key-tabs-selector{display:none}
  .keys-container{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .keys-container .keybox{display:block;margin-bottom:0}
  .tools-layout{grid-template-columns:340px 1fr}
}
@media (max-width:520px){.brand-pill,.home-link-btn span{display:none}.logo-img{max-width:150px}}
</style>
<style id="admx-css">
#tab-maint .card,#tab-locks .card{margin-bottom:16px}
.admx-hero{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:16px 18px}
.admx-hero.is-on{border-color:var(--dgl);background:var(--dgs)}
.admx-hero-l{display:flex;align-items:center;gap:12px;min-width:0}
.admx-dot{width:12px;height:12px;border-radius:50%;background:var(--ok);box-shadow:0 0 0 4px var(--oks);flex:none}
.admx-hero.is-on .admx-dot{background:var(--dg);box-shadow:0 0 0 4px var(--dgs)}
.admx-hero-t{font-size:15px;margin-bottom:2px}
.admx-bulk{display:flex;gap:8px;flex-wrap:wrap}
.admx-trow{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 14px;border-bottom:1px solid var(--line)}
.admx-trow:last-child{border-bottom:none}
.admx-trow[hidden],.admx-lrow[hidden]{display:none}
.admx-tname{flex:1 1 150px;min-width:0;font-size:13px}
.admx-tname b{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.admx-trow input[name=note]{flex:2 1 200px;min-width:0;height:34px}
.admx-sw-wrap{position:relative;display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:12px;font-weight:600;white-space:nowrap;min-width:118px}
.admx-sw-wrap input{position:absolute;opacity:0;pointer-events:none}
.admx-sw-wrap i{width:40px;height:22px;border-radius:999px;background:var(--card2);border:1px solid var(--line2);position:relative;transition:.15s;flex:none}
.admx-sw-wrap i::after{content:'';position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:var(--mu);transition:.15s}
.admx-sw-wrap input:checked + i{background:var(--wns);border-color:var(--wnl)}
.admx-sw-wrap input:checked + i::after{left:20px;background:var(--wn)}
.admx-lrow{flex-wrap:wrap}
.admx-dlg{border:1px solid var(--line2);border-radius:var(--rl);background:var(--card);color:var(--tx);padding:16px;width:calc(100% - 28px);max-width:440px}
.admx-dlg::backdrop{background:rgba(0,0,0,.55)}
.admx-dlg-h{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
.admx-mf{padding:10px 0;border-top:1px solid var(--line)}
.admx-mrow{display:flex;gap:8px;align-items:center}
.admx-mrow .form-control{flex:1;min-width:0}
</style>
<style id="fkx-css">
/* FKX: chi them class fkx-*, khong sua CSS cu */
.fkx-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:12px 14px 8px}
.fkx-st{background:var(--bg2);border:1px solid var(--line);border-radius:12px;padding:8px 11px}
.fkx-st b{display:block;font-size:20px;line-height:1.1;font-variant-numeric:tabular-nums}
.fkx-st span{font-size:11px;color:var(--mu)}
.fkx-chips{display:flex;flex-wrap:wrap;gap:6px;padding:2px 14px 12px}
.fkx-chip{font-size:11px;padding:3px 9px;border-radius:999px;background:var(--card2);border:1px solid var(--line);color:var(--mu);white-space:nowrap}
.fkx-chip b{color:var(--tx);font-weight:600}
.fkx-chip.ok{border-color:var(--okl);background:var(--oks)}
.fkx-chip.warn{border-color:var(--wnl);background:var(--wns);color:var(--wn)}
.fkx-chip.warn b{color:var(--wn)}
.fkx-quick{display:grid;grid-template-columns:1fr;gap:14px;padding:14px}
.fkx-btns{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:8px}
.fkx-btns .custom-chk{margin-right:auto}
.fkx-row{display:flex;align-items:center;gap:10px;padding:8px 14px;border-bottom:1px solid var(--line);flex-wrap:wrap}
.fkx-row:last-child{border-bottom:none}
.fkx-row[hidden]{display:none}
.fkx-line{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.fkx-acts{display:flex;gap:6px;flex-wrap:wrap}
.fkx-meta{overflow-wrap:anywhere}
.fkx-meta code{font-family:var(--mono);font-size:11px}
.fkx-link{padding:12px 14px;border-color:var(--okl);background:var(--oks)}
@media (min-width:640px){.fkx-quick{grid-template-columns:1fr 1fr}}
@media (max-width:520px){.fkx-stats{gap:6px}.fkx-st b{font-size:18px}}
</style>
</head>
<body>

<header class="topbar">
  <div class="wrap topbar-inner">
    <a href="admin_cp.php" class="brand-box" aria-label="Admin">
      <span class="logo">
        <img class="logo-img" src="<?= e($logoSrc) ?>" alt="Logo" onerror="this.style.display='none'">
        <i class="logo-sw" aria-hidden="true"><img class="logo-swi" src="<?= e($logoSrc) ?>" alt=""></i>
        <i class="logo-sw logo-sw2" aria-hidden="true"><img class="logo-swi" src="<?= e($logoSrc) ?>" alt=""></i>
      </span>
      <span class="brand-pill">Quản trị</span>
    </a>

    <div class="top-actions">
      <button type="button" class="icon-btn" id="admTheme" aria-label="Đổi giao diện sáng / tối" title="Sáng / Tối"><?= ic('moon') ?></button>
      <button type="button" class="mt-toggle-btn <?= $is_mt ? 'is-active' : '' ?>"
        data-goto="maint" title="Mở trang Bảo Trì"
        data-title="<?= $is_mt ? "Tắt chế độ bảo trì?" : "Bật chế độ bảo trì ngay?" ?>"
        data-desc="<?= $is_mt ? "Khách và thành viên thường sẽ truy cập lại web bình thường." : "Khi bật, khách và thành viên ngoài sẽ bị chuyển hướng sang trang bảo trì." ?>"
        data-danger="<?= $is_mt ? '0' : '1' ?>">
        <span class="mt-indicator"></span>
        Bảo trì: <?= $is_mt ? "Đang Bật" : "Tắt" ?>
      </button>
      <a class="home-link-btn" href="index.php" target="_blank" rel="noopener"><span>Vào Web</span> <?= ic('out') ?></a>
    </div>
  </div>
</header>

<?php if ($is_mt): ?>
<div class="mt-alert-banner" onclick="location.hash='maint'" style="cursor:pointer" title="Mở trang Bảo Trì">
  <div class="wrap flex-align gap-2"><?= ic('alert') ?><span>Chế độ bảo trì <b>ĐANG BẬT</b>. Chỉ tài khoản Admin mới có thể xem và truy cập website.</span></div>
</div>
<div class="mt-access" hidden style="display:none">
  <div class="wrap">
    <div class="mt-access-box">
      <div class="mt-access-head"><?= ic('key') ?> <b>Truy cập quản trị khi bảo trì</b>
        <span class="muted-text text-xs">Chỉ hiện khi bảo trì đang bật, tắt bảo trì là bị xoá.</span></div>
      <?php if ($mt_link !== ''): ?>
        <label class="form-label" for="mtLink">Link đăng nhập bí mật (đừng gửi cho ai)</label>
        <div class="mt-copy-row">
          <input type="text" id="mtLink" class="form-control" readonly value="<?= e($mt_link) ?>" onfocus="this.select()">
          <button type="button" class="btn btn-ghost btn-sm" data-copy="mtLink"><?= ic('copy') ?> Copy</button>
        </div>
        <label class="form-label" for="mtPw">Mật khẩu truy cập (đã mã hoá)</label>
        <?php if ($mt_pw !== ''): ?>
          <div class="mt-copy-row">
            <input type="text" id="mtPw" class="form-control" readonly value="<?= e($mt_pw) ?>" onfocus="this.select()">
            <button type="button" class="btn btn-ghost btn-sm" data-copy="mtPw"><?= ic('copy') ?> Copy</button>
          </div>
        <?php else: ?>
          <p class="muted-text text-xs" style="margin-bottom:12px">Mật khẩu chỉ lưu dạng băm nên không xem lại được, nó chỉ hiện ở phiên đã bật bảo trì. Mất mật khẩu thì bấm "Tạo lại" bên dưới.</p>
        <?php endif; ?>
      <?php else: ?>
        <p class="muted-text text-xs" style="margin-bottom:12px">Bảo trì đang bật nhưng chưa có link và mật khẩu truy cập. Bấm nút dưới để tạo.</p>
      <?php endif; ?>
      <button type="button" class="btn btn-danger-outline btn-sm" data-act="regen_mt" data-fields="{}" data-danger="1"
        data-title="Tạo lại link và mật khẩu?" data-desc="Link và mật khẩu hiện tại sẽ hết hiệu lực ngay lập tức, bạn sẽ nhận bộ mới.">Tạo lại link &amp; mật khẩu</button>
    </div>
  </div>
</div>
<?php endif; ?>

<main class="wrap">

  <div class="stats-grid">
    <div class="stat-card"><div class="stat-num"><?= $vip_open ?></div><div class="stat-sub">Key VIP trống (chưa dùng)</div></div>
    <div class="stat-card"><div class="stat-num"><?= $free_open ?></div><div class="stat-sub">Key FREE trống (chưa dùng)</div></div>
    <div class="stat-card"><div class="stat-num"><?= count($prods) ?><?php if ($mt_tools): ?> <span class="text-xs" style="color:var(--wn)">/ <?= $mt_tools ?> bảo trì</span><?php endif; ?></div><div class="stat-sub">Sản phẩm tool đã đăng</div></div>
    <div class="stat-card"><div class="stat-num"><?= $active_members ?> <span class="text-xs muted-text">/ <?= count($members) ?></span></div><div class="stat-sub">Thành viên có key còn hạn</div></div>
  </div>

<?= fkx_ui('linkbox') ?>
  <nav class="tabs-nav" aria-label="Mục quản trị">
    <a href="#keys" data-tab="keys" class="tab-link"><?= ic('key') ?> Kho Key</a>
    <a href="#tools" data-tab="tools" class="tab-link"><?= ic('tool') ?> Quản Lý Tool</a>
    <a href="#members" data-tab="members" class="tab-link"><?= ic('users') ?> Thành Viên</a>
    <a href="#sections" data-tab="sections" class="tab-link"><?= ic('edit') ?> Mục / Package</a>
    <a href="#theme" data-tab="theme" class="tab-link"><?= ic('palette') ?> Giao Diện Web</a>
    <a href="#maint" data-tab="maint" class="tab-link"><?= ic('power') ?> Bảo Trì<?php if ($is_mt || $mt_tools): ?> <span class="badge badge-bad"><?= $is_mt ? 'BẬT' : (int)$mt_tools ?></span><?php endif; ?></a>
    <a href="#locks" data-tab="locks" class="tab-link"><?= ic('key') ?> Mở Khoá Key<?php if ($admxLockCount): ?> <span class="badge badge-bad"><?= (int)$admxLockCount ?></span><?php endif; ?></a>
<?= fkx_ui('nav') ?>
    <a href="#settings" data-tab="settings" class="tab-link"><?= ic('settings') ?> Cấu Hình</a>
  </nav>

  <!-- ===== KHO KEY ===== -->
  <div class="panel" id="tab-keys">
    <section class="card">
      <div class="card-header border-b"><h2 class="card-title"><?= ic('plus') ?> Trình Tạo Key Hàng Loạt</h2></div>
      <form method="post" class="card-body">
        <?= csrf() ?>
        <input type="hidden" name="action" value="gen_keys">
        <div class="gen-grid">
          <div>
            <label class="form-label">Gói thời hạn &amp; Loại key</label>
            <select name="pack" class="form-control">
              <option value="vip:720">VIP &bull; 30 Ngày (720 giờ)</option>
              <option value="vip:168">VIP &bull; 7 Ngày (168 giờ)</option>
              <option value="vip:24">VIP &bull; 1 Ngày (24 giờ)</option>
              <option value="free:24">FREE &bull; 24 Giờ</option>
            </select>
          </div>
          <div>
            <label class="form-label">Số lượng</label>
            <input type="number" name="num" value="20" min="1" max="200" inputmode="numeric" class="form-control">
          </div>
          <div style="display:flex;align-items:flex-end"><button type="submit" class="btn btn-full">Tạo Ngay</button></div>
        </div>
      </form>
    </section>

    <div class="key-tabs-selector" role="group">
      <button type="button" data-s="vip" aria-pressed="true">Kho Key VIP (<?= count($vip) ?>)</button>
      <button type="button" data-s="free" aria-pressed="false">Kho Key FREE (<?= count($free) ?>)</button>
    </div>
    <div class="keys-container" data-seg="vip" id="keysContainer">
      <?php render_key_box('vip', $vip, $vip_open); ?>
      <?php render_key_box('free', $free, $free_open); ?>
    </div>
  </div>

  <!-- ===== QUAN LY TOOL ===== -->
  <div class="panel" id="tab-tools" hidden>
    <div class="tools-layout">
      <section class="card" style="align-self:start">
        <div class="card-header border-b"><h2 class="card-title"><?= ic('plus') ?> Đăng Tool Mới</h2></div>
        <form method="post" enctype="multipart/form-data" class="card-body">
          <?= csrf() ?>
          <input type="hidden" name="action" value="add_p">
          <div class="form-group"><label class="form-label">Tên Tool</label>
            <input type="text" name="p_name" class="form-control" placeholder="Ví dụ: Tool Auto Reg FB..." required maxlength="255"></div>
          <div class="form-group"><label class="form-label">Link tải / Điều hướng</label>
            <input type="text" name="p_link" class="form-control" placeholder="https://..." required inputmode="url"></div>
          <div class="form-group"><label class="form-label">Phân quyền sử dụng</label>
            <select name="p_type" class="form-control">
              <option value="FREE">FREE (Thành viên miễn phí)</option>
              <option value="VIP">VIP (Chỉ thành viên VIP)</option>
            </select></div>
          <div class="form-group"><label class="form-label">Ảnh đại diện Icon (JPG, PNG, WEBP &bull; &le; 3MB)</label>
            <input type="file" name="p_img" class="form-control" accept="image/*" required></div>

          <div class="form-group"><label class="form-label">Hiện ở mục</label>

            <select name="p_pkg" class="form-control">

              <option value="">Tự động (theo FREE / VIP)</option>

              <?php foreach (adm_pkg_names($cf) as $addI => $addN): ?>

                <option value="<?= (int)$addI ?>"><?= e($addN) ?></option>

              <?php endforeach; ?>

            </select></div>
          <button type="submit" class="btn btn-full">Đăng Tool Lên Web</button>
          <p class="form-hint" style="margin-top:10px">Ảnh xem trước (tối đa 3 ảnh) và trạng thái Active / Bảo trì chỉnh ở nút &ldquo;Sửa&rdquo; của từng tool bên cạnh.</p>
        </form>
      </section>

      <section class="card">
        <div class="card-header border-b">
          <h2 class="card-title"><?= ic('tool') ?> Danh Sách Tool Đang Đăng</h2>
          <span class="muted-text text-xs"><?= count($prods) ?> sản phẩm</span>
        </div>
        <div>
          <?php if (!$prods): ?>
            <div class="empty-state">Chưa có tool nào được đăng. Hãy tạo tool ở khung bên trái.</div>
          <?php else: foreach ($prods as $p):
              $pid = (int)$p['id'];
              $pm = isset($meta[$pid]) && is_array($meta[$pid]) ? $meta[$pid] : [];
              $mtOn = (($pm['status'] ?? '') === 'maintenance');
              $pv = array_values((array)($pm['previews'] ?? []));
              $iconSrc = (string)$p['image'];
              if ($iconSrc !== '' && !preg_match('#^(https?://|/)#i', $iconSrc)) $iconSrc = '/' . ltrim($iconSrc, '/');
          ?>
            <div class="tool-card">
              <div class="tool-item-card">
                <img class="tool-thumb" src="<?= e($iconSrc) ?>" alt="" loading="lazy" onerror="this.removeAttribute('src')">
                <div class="flex-1">
                  <div class="tool-title"><?= e($p['name']) ?> <?php if (!empty($pm['hide'])): ?><span class="badge badge-bad">Ẩn</span><?php endif; ?></div>
                  <div class="tool-link-meta"><?= e($p['link']) ?></div>
                </div>
                <span class="badge badge-neutral" title="Mục hiển thị trên web"><?= e(adm_sec_title(adm_sec_effective($p, $pm, $cf, adm_sec_count($cf)), $cf)) ?></span>
                <span class="badge <?= $p['type'] === 'VIP' ? 'badge-vip' : 'badge-neutral' ?>"><?= e($p['type']) ?></span>
                <button type="button" class="status-pill <?= $mtOn ? 'is-mt' : 'is-ok' ?>" title="Bấm để đổi trạng thái"
                  data-act="tool_status" data-fields="<?= e(json_encode(['id' => $pid, 'status' => $mtOn ? 'active' : 'maintenance'])) ?>" data-danger="0"
                  data-title="<?= $mtOn ? "Cho tool hoạt động trở lại?" : "Chuyển tool sang bảo trì?" ?>"
                  data-desc="<?= $mtOn ? "Tool sẽ hiện Active và bấm GET được bình thường." : "Trang chi tiết tool sẽ hiện Bảo trì và nút GET bị khoá cho tới khi bạn bật lại." ?>"><?= $mtOn ? "Bảo trì" : "Active" ?></button>
                <button type="button" class="action-icon-btn" title="Sửa tool" data-edit="<?= $pid ?>" aria-expanded="false"><?= ic('edit') ?></button>
                <button type="button" class="action-icon-btn" title="Xóa tool"
                  data-act="del_p" data-fields="<?= e(json_encode(['id' => $pid])) ?>"
                  data-title="Xóa tool này?" data-desc="Tool «<?= e($p['name']) ?>» và file ảnh sẽ bị xóa hoàn toàn."><?= ic('trash') ?></button>
              </div>

              <div class="tool-edit" id="edit-<?= $pid ?>" hidden>
                <form method="post" enctype="multipart/form-data" style="padding-top:14px">
                  <?= csrf() ?>
                  <input type="hidden" name="action" value="edit_p">
                  <input type="hidden" name="id" value="<?= $pid ?>">
                  <div class="form-grid-2">
                    <div class="form-group"><label class="form-label">Tên tool</label>
                      <input type="text" name="p_name" class="form-control" value="<?= e($p['name']) ?>" required maxlength="255"></div>
                    <div class="form-group"><label class="form-label">Phân quyền</label>
                      <select name="p_type" class="form-control">
                        <option value="FREE" <?= $p['type'] !== 'VIP' ? 'selected' : '' ?>>FREE</option>
                        <option value="VIP" <?= $p['type'] === 'VIP' ? 'selected' : '' ?>>VIP</option>
                      </select></div>
                  </div>
                  <div class="form-group"><label class="form-label">Link tải / Điều hướng</label>
                    <input type="text" name="p_link" class="form-control" value="<?= e($p['link']) ?>" required inputmode="url"></div>
                  <div class="form-grid-2">
                    <div class="form-group"><label class="form-label">Trạng thái hiện ở trang View</label>
                      <select name="p_status" class="form-control">
                        <option value="active" <?= $mtOn ? '' : 'selected' ?>>Active (hoạt động)</option>
                        <option value="maintenance" <?= $mtOn ? 'selected' : '' ?>>Bảo trì (khoá nút GET)</option>
                      </select></div>
                    <div class="form-group"><label class="form-label">Lời nhắn khi bảo trì (trống = chữ mặc định)</label>
                      <input type="text" name="p_note" class="form-control" value="<?= e($pm['note'] ?? '') ?>" maxlength="200"></div>
                  </div>
                  <div class="form-group"><label class="form-label">Đổi icon (để trống = giữ icon cũ)</label>
                    <input type="file" name="p_img" class="form-control" accept="image/*"></div>

                  <details class="adv-group">
                    <summary>Thông tin trang View (mô tả, nhà phát triển, iOS, phiên bản, chức năng, gói, thứ tự, ẩn)</summary>
                    <div class="adv-body">
                      <?php
                        $mpk = (string)($pm['pkg'] ?? '');
                        $mpn = adm_pkg_names($cf);
                        if ($mpk !== '' && !isset($mpn[(int)$mpk])) $mpn[(int)$mpk] = 'Package ' . (int)$mpk;
                      ?>
                      <div class="form-grid-2">
                        <div class="form-group"><label class="form-label">Hiện ở gói</label>
                          <select name="m_pkg" class="form-control">
                            <option value=""<?= $mpk === '' ? ' selected' : '' ?>>Tự động (theo loại tool / từ khoá iOS)</option>
                            <?php foreach ($mpn as $mpi => $mpt): ?>
                              <option value="<?= (int)$mpi ?>"<?= $mpk === (string)$mpi ? ' selected' : '' ?>><?= e($mpt) ?></option>
                            <?php endforeach; ?>
                          </select></div>
                        <div class="form-group"><label class="form-label">Thứ tự hiển thị (số nhỏ lên trước, trống = giữ nguyên)</label>
                          <input type="number" name="m_ord" class="form-control" value="<?= isset($pm['ord']) ? (int)$pm['ord'] : '' ?>" min="-99999" max="99999" inputmode="numeric"></div>
                      </div>
                      <div class="form-group"><label class="custom-chk"><input type="checkbox" name="m_hide" value="1"<?= !empty($pm['hide']) ? ' checked' : '' ?>><span>Ẩn tool này khỏi web (vẫn giữ trong admin)</span></label></div>
                      <div class="form-group"><label class="form-label">Mô tả tool (trống = câu mô tả mặc định của theme)</label>
                        <textarea name="m_desc" class="form-control form-textarea" maxlength="2000"><?= e($pm['desc'] ?? '') ?></textarea></div>
                      <div class="form-grid-2">
                        <div class="form-group"><label class="form-label">Nhà phát triển (trống = DEVNVIOS)</label>
                          <input type="text" name="m_dev" class="form-control" value="<?= e($pm['dev'] ?? '') ?>" maxlength="100"></div>
                        <div class="form-group"><label class="form-label">Hỗ trợ iOS (ví dụ 16 hoặc iOS 16+)</label>
                          <input type="text" name="m_ios" class="form-control" value="<?= e($pm['ios'] ?? '') ?>" maxlength="40"></div>
                        <div class="form-group"><label class="form-label">Thiết bị ở trang View (trống = iPhone, iPad)</label>
                          <input type="text" name="m_device" class="form-control" value="<?= e($pm['device'] ?? '') ?>" maxlength="100"></div>
                      </div>
                      <div class="form-grid-2">
                        <div class="form-group"><label class="form-label">Phiên bản</label>
                          <input type="text" name="m_ver" class="form-control" value="<?= e($pm['ver'] ?? '') ?>" maxlength="40"></div>
                        <div class="form-group"><label class="form-label">Ngày đăng (ví dụ 2026-10-20)</label>
                          <input type="text" name="m_date" class="form-control" value="<?= e($pm['date'] ?? '') ?>" maxlength="40" autocomplete="off"></div>
                      </div>
                      <div class="form-group"><label class="form-label">Ngày cập nhật gần nhất (ví dụ 2026-10-22)</label>
                        <input type="text" name="m_upd" class="form-control" value="<?= e($pm['upd'] ?? '') ?>" maxlength="40" autocomplete="off"></div>
                      <div class="form-group"><label class="form-label">Chức năng (mỗi dòng một chức năng, trống = 4 dòng mặc định)</label>
                        <textarea name="m_feat" class="form-control form-textarea" maxlength="3000"><?= e($pm['feat'] ?? '') ?></textarea></div>
                    </div>
                  </details>

                  <label class="form-label">Ảnh xem trước (tối đa 3 ảnh &bull; dán link hoặc tải lên &bull; để trống ô link = xoá ảnh đó)</label>
                  <div class="pv-grid">
                    <?php for ($i = 0; $i < 3; $i++): $cu = isset($pv[$i]) ? (string)$pv[$i] : ''; ?>
                      <div class="pv-slot">
                        <div class="pv-thumb"><?php if ($cu !== ''): ?><img src="<?= e($cu) ?>" alt="" loading="lazy" onerror="this.remove()"><?php else: ?>Ảnh <?= $i + 1 ?><?php endif; ?></div>
                        <input type="text" name="pv_url[<?= $i ?>]" class="form-control" value="<?= e($cu) ?>" placeholder="Link ảnh https://..." inputmode="url">
                        <input type="file" name="pv_file[<?= $i ?>]" class="form-control" accept="image/*">
                      </div>
                    <?php endfor; ?>
                  </div>
                  <button type="submit" class="btn btn-full">Lưu tool này</button>
                </form>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </section>
    </div>
  </div>

  <!-- ===== MUC / PACKAGE ===== -->

  <div class="panel" id="tab-sections" hidden>

<?php

$secN = adm_sec_count($cf);

$secCur = [];

$secCnt = array_fill(1, $secN, 0);

foreach ($prods as $sp) {

    $spId = (int)$sp['id'];

    $spm = (isset($meta[$spId]) && is_array($meta[$spId])) ? $meta[$spId] : [];

    $spv = adm_sec_effective($sp, $spm, $cf, $secN);

    $secCur[$spId] = $spv;

    $secCnt[$spv]++;

}

$secOpts = function ($sel) use ($secN, $cf) {

    $h = '';

    for ($i = 1; $i <= $secN; $i++) {

        $h .= '<option value="' . $i . '"' . ((int)$sel === $i ? ' selected' : '') . '>' . e($i . '. ' . adm_sec_title($i, $cf)) . '</option>';

    }

    return $h;

};

?>

    <style>

      .sec-row{padding:12px 0;border-bottom:1px solid var(--line)}

      .sec-row:last-child{border-bottom:0}

      .sec-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;font-size:13px;font-weight:600}

      .sec-row .form-control{margin-bottom:6px}

      .sec-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:10px 14px;border-bottom:1px solid var(--line);background:var(--card2)}

      .sec-bar .form-control{width:auto;min-width:150px;flex:1 1 150px}

      .sec-tool .sec-sel{width:150px;flex:0 0 auto}

    </style>

    <div style="max-width:760px;margin:0 auto">

      <form method="post" id="secForm">

        <?= csrf() ?>

        <input type="hidden" name="action" value="save_sections">



        <section class="card">

          <div class="card-header border-b">

            <h2 class="card-title"><?= ic('edit') ?> Các mục trên trang chủ</h2>

            <span class="muted-text text-xs"><?= (int)$secN ?>/8 mục</span>

          </div>

          <div class="card-body">

            <p class="form-hint" style="margin-bottom:10px">Tên và mô tả đã điền sẵn. Đổi tên thành bất kỳ mục nào bạn muốn (ví dụ: Game, Công cụ, Hack...), rồi bấm Lưu. Mục chưa có sản phẩm thì trang chủ chưa hiện mục đó.</p>

            <?php for ($i = 1; $i <= $secN; $i++): ?>

              <div class="sec-row">

                <div class="sec-head">Mục <?= $i ?>

                  <span class="badge badge-neutral"><?= (int)$secCnt[$i] ?> sản phẩm</span>

                  <?php if ($secCnt[$i] === 0): ?><span class="badge badge-bad">Trống: web chưa hiện</span><?php endif; ?>

                </div>

                <input type="text" name="sec_title[<?= $i ?>]" class="form-control" value="<?= e(adm_sec_title($i, $cf)) ?>" maxlength="255" placeholder="Tên mục" autocomplete="off">

                <input type="text" name="sec_sub[<?= $i ?>]" class="form-control" value="<?= e(adm_sec_sub($i, $cf)) ?>" maxlength="255" placeholder="Mô tả ngắn dưới tên mục (có thể để trống)" autocomplete="off">

              </div>

            <?php endfor; ?>

            <div class="flex-align gap-2" style="flex-wrap:wrap;margin-top:12px">

              <button type="submit" class="btn">Lưu tất cả</button>

              <?php if ($secN < 8): ?><button type="submit" name="sec_op" value="add" class="btn btn-ghost"><?= ic('plus') ?> Thêm mục mới</button><?php endif; ?>

              <?php if ($secN > 3): ?><button type="submit" name="sec_op" value="remove" class="btn btn-danger-outline" onclick="return confirm('Bớt Mục <?= (int)$secN ?>? Sản phẩm trong mục này sẽ quay về mục tự động.');"><?= ic('trash') ?> Bớt mục cuối</button><?php endif; ?>

            </div>

          </div>

        </section>



        <section class="card">

          <div class="card-header border-b">

            <h2 class="card-title"><?= ic('tool') ?> Xếp sản phẩm vào mục</h2>

            <span class="muted-text text-xs"><?= count($prods) ?> sản phẩm</span>

          </div>

          <?php if (!$prods): ?>

            <div class="empty-state">Chưa có tool nào. Hãy đăng tool ở tab Quản Lý Tool.</div>

          <?php else: ?>

            <div class="sec-bar">

              <label class="custom-chk"><input type="checkbox" id="secAll"><span>Chọn tất cả</span></label>

              <select id="secBulk" class="form-control" aria-label="Mục muốn chuyển tới"><?= $secOpts(0) ?></select>

              <button type="button" class="btn btn-ghost btn-sm" id="secBulkGo">Chuyển các tool đã tick</button>

            </div>

            <div>

              <?php foreach ($prods as $sp):

                  $spId = (int)$sp['id'];

                  $spIcon = (string)$sp['image'];

                  if ($spIcon !== '' && !preg_match('#^(https?://|/)#i', $spIcon)) $spIcon = '/' . ltrim($spIcon, '/');

              ?>

                <div class="member-row sec-tool">

                  <label class="custom-chk"><input type="checkbox" class="js-sec-chk" aria-label="Chọn tool"></label>

                  <img class="tool-thumb" src="<?= e($spIcon) ?>" alt="" loading="lazy" onerror="this.removeAttribute('src')">

                  <div class="flex-1">

                    <div class="tool-title"><?= e($sp['name']) ?></div>

                    <div class="tool-link-meta"><?= e($sp['type']) ?></div>

                  </div>

                  <select name="tool_pkg[<?= $spId ?>]" class="form-control sec-sel" aria-label="Mục của tool"><?= $secOpts($secCur[$spId]) ?></select>

                </div>

              <?php endforeach; ?>

            </div>

            <div class="card-body">

              <p class="form-hint" style="margin-bottom:10px">Mỗi sản phẩm đã chọn sẵn mục đang hiện trên web. Đổi ở ô bên phải, hoặc tick nhiều sản phẩm rồi chuyển cùng lúc, sau đó bấm Lưu tất cả.</p>

              <button type="submit" class="btn btn-full">Lưu tất cả</button>

            </div>

          <?php endif; ?>

        </section>

      </form>

    </div>

    <script>

    (function () {

      var f = document.getElementById('secForm');

      if (!f) return;

      var all = document.getElementById('secAll'), go = document.getElementById('secBulkGo'), sel = document.getElementById('secBulk');

      function chks() { return [].slice.call(f.querySelectorAll('.js-sec-chk')); }

      if (all) all.addEventListener('change', function () { chks().forEach(function (c) { c.checked = all.checked; }); });

      if (go) go.addEventListener('click', function () {

        var n = 0;

        chks().forEach(function (c) {

          if (!c.checked) return;

          var row = c.closest('.sec-tool'), s = row ? row.querySelector('.sec-sel') : null;

          if (s) { s.value = sel.value; n++; }

          c.checked = false;

        });

        if (all) all.checked = false;

        var msg = n ? 'Đã chọn mục cho ' + n + ' sản phẩm. Bấm Lưu tất cả để áp dụng.' : 'Hãy tick ít nhất 1 sản phẩm.';

        if (typeof toast === 'function') toast(msg, n ? '' : 'err'); else alert(msg);

      });

    })();

    </script>

  </div>

  <!-- ADMX-PANELS-BEGIN -->
  <div class="panel" id="tab-maint" hidden>
    <section class="card admx-hero<?= $is_mt ? ' is-on' : '' ?>">
      <div class="admx-hero-l">
        <span class="admx-dot"></span>
        <div>
          <div class="admx-hero-t">Bảo trì toàn website: <b><?= $is_mt ? 'ĐANG BẬT' : 'đang tắt' ?></b></div>
          <div class="muted-text text-xs"><?= $is_mt ? 'Khách và thành viên thường đang bị chuyển sang trang bảo trì, chỉ admin vào được.' : 'Web đang hoạt động bình thường. Bật khi cần nâng cấp hoặc sửa lỗi.' ?></div>
        </div>
      </div>
      <button type="button" class="btn<?= $is_mt ? '' : ' btn-danger' ?>" data-act="toggle_mt" data-fields="{}" data-danger="<?= $is_mt ? '0' : '1' ?>"
        data-title="<?= $is_mt ? 'Tắt chế độ bảo trì?' : 'Bật chế độ bảo trì ngay?' ?>"
        data-desc="<?= $is_mt ? 'Khách và thành viên thường sẽ truy cập lại web bình thường.' : 'Khi bật, khách và thành viên ngoài sẽ bị chuyển sang trang bảo trì. Link và mật khẩu vào admin hiện ngay ở trang này.' ?>"><?= ic('power') ?> <?= $is_mt ? 'Tắt bảo trì' : 'Bật bảo trì' ?></button>
    </section>

    <?php if ($is_mt): ?>
    <section class="card">
      <div class="card-header border-b"><h2 class="card-title"><?= ic('key') ?> Link &amp; mật khẩu vào admin khi bảo trì</h2></div>
      <div class="card-body">
        <?php if ($mt_link !== ''): ?>
          <label class="form-label" for="mtLink2">Link đăng nhập bí mật (đừng gửi cho ai)</label>
          <div class="mt-copy-row">
            <input type="text" id="mtLink2" class="form-control" readonly value="<?= e($mt_link) ?>" onfocus="this.select()">
            <button type="button" class="btn btn-ghost btn-sm" data-copy="mtLink2"><?= ic('copy') ?> Copy</button>
          </div>
          <label class="form-label" for="mtPw2">Mật khẩu truy cập</label>
          <?php if ($mt_pw !== ''): ?>
            <div class="mt-copy-row">
              <input type="text" id="mtPw2" class="form-control" readonly value="<?= e($mt_pw) ?>" onfocus="this.select()">
              <button type="button" class="btn btn-ghost btn-sm" data-copy="mtPw2"><?= ic('copy') ?> Copy</button>
            </div>
          <?php else: ?>
            <p class="muted-text text-xs" style="margin-bottom:12px">Mật khẩu chỉ lưu dạng băm nên không xem lại được, nó chỉ hiện ở phiên đã bật bảo trì. Mất mật khẩu thì bấm nút Tạo lại bên dưới.</p>
          <?php endif; ?>
        <?php else: ?>
          <p class="muted-text text-xs" style="margin-bottom:12px">Bảo trì đang bật nhưng chưa có link và mật khẩu truy cập. Bấm nút dưới để tạo.</p>
        <?php endif; ?>
        <button type="button" class="btn btn-danger-outline btn-sm" data-act="regen_mt" data-fields="{}" data-danger="1"
          data-title="Tạo lại link và mật khẩu?" data-desc="Link và mật khẩu hiện tại sẽ hết hiệu lực ngay lập tức, bạn sẽ nhận bộ mới.">Tạo lại link &amp; mật khẩu</button>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($admxMtFields): ?>
    <section class="card">
      <div class="card-header border-b"><h2 class="card-title"><?= ic('edit') ?> Nội dung trang bảo trì</h2></div>
      <form method="post" class="card-body">
        <?= csrf() ?>
        <input type="hidden" name="action" value="save_mt">
        <?php foreach ($admxMtFields as $admxMk => $admxMf) { echo field_html('adv', $admxMk, $admxMf, (string)($cf[$admxMk] ?? ''), 'mt_'); } ?>
        <button type="submit" class="btn">Lưu nội dung bảo trì</button>
      </form>
    </section>
    <?php endif; ?>

    <section class="card">
      <div class="card-header border-b">
        <div>
          <h2 class="card-title"><?= ic('tool') ?> Bảo trì từng tool</h2>
          <p class="muted-text text-xs mt-1"><b><?= (int)$mt_tools ?></b>/<?= count($prods) ?> tool đang bảo trì. Gạt công tắc là có hiệu lực ngay, nút GET của tool đó bị khoá.</p>
        </div>
        <?php if ($prods): ?>
        <div class="admx-bulk">
          <button type="button" class="btn btn-danger-outline btn-sm" data-act="mt_tools_all" data-fields="<?= e(json_encode(['status' => 'maintenance'])) ?>" data-danger="1"
            data-title="Bảo trì tất cả tool?" data-desc="Mọi tool sẽ hiện Bảo trì và nút GET bị khoá cho tới khi bạn mở lại.">Bảo trì tất cả</button>
          <button type="button" class="btn btn-ghost btn-sm" data-act="mt_tools_all" data-fields="<?= e(json_encode(['status' => 'active'])) ?>" data-danger="0"
            data-title="Mở lại tất cả tool?" data-desc="Mọi tool sẽ hoạt động trở lại (Active).">Mở lại tất cả</button>
        </div>
        <?php endif; ?>
      </div>
      <div class="toolbar">
        <div class="search-input-wrap"><?= ic('search') ?>
          <input type="search" id="admxQ" class="search-input" placeholder="Tìm tool..." autocomplete="off"></div>
      </div>
      <div>
        <?php if (!$prods): ?>
          <div class="empty-state">Chưa có tool nào được đăng.</div>
        <?php else: foreach ($prods as $admxP):
            $admxId = (int)$admxP['id'];
            $admxPm = isset($meta[$admxId]) && is_array($meta[$admxId]) ? $meta[$admxId] : [];
            $admxOn = (($admxPm['status'] ?? '') === 'maintenance');
            $admxIcon = (string)$admxP['image'];
            if ($admxIcon !== '' && !preg_match('#^(https?://|/)#i', $admxIcon)) $admxIcon = '/' . ltrim($admxIcon, '/');
        ?>
          <form method="post" class="admx-trow" data-name="<?= e(strtolower($admxP['name'])) ?>">
            <?= csrf() ?>
            <input type="hidden" name="action" value="mt_tool_save">
            <input type="hidden" name="id" value="<?= $admxId ?>">
            <input type="hidden" name="status" value="<?= $admxOn ? 'maintenance' : 'active' ?>">
            <img class="tool-thumb" src="<?= e($admxIcon) ?>" alt="" loading="lazy" onerror="this.removeAttribute('src')">
            <div class="admx-tname"><b><?= e($admxP['name']) ?></b>
              <span class="badge <?= $admxP['type'] === 'VIP' ? 'badge-vip' : 'badge-neutral' ?>"><?= e($admxP['type']) ?></span></div>
            <label class="admx-sw-wrap"><input type="checkbox" class="admx-sw"<?= $admxOn ? ' checked' : '' ?>><i></i><span><?= $admxOn ? 'Đang bảo trì' : 'Hoạt động' ?></span></label>
            <input type="text" name="note" class="form-control" maxlength="200" placeholder="Lời nhắn khi bảo trì (trống = chữ mặc định)" value="<?= e($admxPm['note'] ?? '') ?>">
            <button type="submit" class="btn btn-ghost btn-sm">Lưu</button>
          </form>
        <?php endforeach; endif; ?>
      </div>
    </section>
  </div>

  <div class="panel" id="tab-locks" hidden>
    <section class="card">
      <div class="card-header border-b">
        <div>
          <h2 class="card-title"><?= ic('key') ?> Tài khoản đang bị khoá get key</h2>
          <p class="muted-text text-xs mt-1">Sau khi kích hoạt key, tài khoản và IP bị khoá <b><?= (int)$admxLockHours ?> giờ</b> (hết giờ tự mở). Bấm Mở khoá để cho vượt tiếp ngay. Đang khoá: <b><?= (int)$admxLockCount ?></b></p>
        </div>
        <?php if ($admxLockCount): ?>
        <button type="button" class="btn btn-danger-outline btn-sm" data-act="lock_unlock_all" data-fields="{}" data-danger="1"
          data-title="Mở khoá tất cả?" data-desc="Mọi tài khoản và IP đang bị khoá sẽ get key được ngay.">Mở khoá tất cả</button>
        <?php endif; ?>
      </div>
      <?php if (!$admxLockWritable): ?>
        <div class="mt-alert-banner"><div class="wrap flex-align gap-2"><?= ic('alert') ?><span>Máy chủ không ghi được file khoá, nút Mở khoá sẽ báo lỗi. Hãy cấp quyền ghi cho thư mục web.</span></div></div>
      <?php endif; ?>
      <div class="toolbar">
        <div class="search-input-wrap"><?= ic('search') ?>
          <input type="search" id="admxLQ" class="search-input" placeholder="Tìm theo tài khoản, IP hoặc key..." autocomplete="off"></div>
      </div>
      <div>
        <?php if (!$admxEvents): ?>
          <div class="empty-state">Hiện không có tài khoản hay IP nào bị khoá get key.</div>
        <?php else: foreach ($admxEvents as $admxEv): ?>
          <div class="member-row admx-lrow" data-q="<?= e(strtolower(implode(' ', array_merge(array_column($admxEv['users'], 'name'), $admxEv['ips'], $admxEv['keys'])))) ?>">
            <div class="flex-1">
              <div class="flex-align gap-2" style="flex-wrap:wrap">
                <?php if ($admxEv['users']): foreach ($admxEv['users'] as $admxU): ?>
                  <span class="font-semibold" style="font-size:13px"><?= e($admxU['name']) ?></span>
                <?php endforeach; else: ?>
                  <span class="badge badge-neutral">Khoá theo IP</span>
                <?php endif; ?>
                <span class="badge badge-bad js-left" data-until="<?= (int)$admxEv['until'] ?>"><?= e(admx_left_txt($admxEv['until'], time())) ?></span>
              </div>
              <div class="muted-text text-xs mt-1">Kích hoạt lúc <b><?= e(date('d/m/Y H:i', $admxEv['ts'])) ?></b> &bull; IP: <?= e(implode(', ', $admxEv['ips']) ?: '-') ?><?php if ($admxEv['keys']): ?> &bull; Key: <?= e(implode(', ', $admxEv['keys'])) ?><?php endif; ?></div>
            </div>
            <button type="button" class="btn btn-ghost btn-xs" data-act="lock_unlock" data-fields="<?= e(json_encode(['ts' => (int)$admxEv['ts']])) ?>" data-danger="0"
              data-title="Mở khoá get key?" data-desc="Tài khoản / IP này sẽ get key được ngay, không cần chờ hết giờ.">Mở khoá</button>
          </div>
        <?php endforeach; endif; ?>
      </div>
      <div class="empty-state" id="admxLEmpty" hidden>Không có mục nào khớp từ khoá.</div>
    </section>
  </div>

<?= fkx_ui('panel') ?>
  <dialog id="memDlg" class="admx-dlg">
    <div class="admx-dlg-h"><h3 class="modal-title" id="memTitle">Quản lý thành viên</h3><button type="button" class="btn btn-ghost btn-xs" id="memClose">Đóng</button></div>
    <form method="post" class="admx-mf">
      <?= csrf() ?>
      <input type="hidden" name="action" value="member_extend">
      <input type="hidden" name="id" class="js-memid">
      <label class="form-label">Cộng / trừ giờ key (số âm là trừ)</label>
      <div class="admx-mrow"><input type="number" name="hours" class="form-control" placeholder="Ví dụ 24 hoặc -12" required><button type="submit" class="btn btn-sm">Áp dụng</button></div>
    </form>
    <?php if ($admxHasTurns): ?>
    <form method="post" class="admx-mf">
      <?= csrf() ?>
      <input type="hidden" name="action" value="member_turns">
      <input type="hidden" name="id" class="js-memid">
      <label class="form-label">Đặt số lượt còn lại</label>
      <div class="admx-mrow"><input type="number" name="turns" id="memTurns" class="form-control" min="0" max="9999" required><button type="submit" class="btn btn-sm">Lưu</button></div>
    </form>
    <?php endif; ?>
    <?php if ($admxHasAlert): ?>
    <form method="post" class="admx-mf">
      <?= csrf() ?>
      <input type="hidden" name="action" value="member_msg">
      <input type="hidden" name="id" class="js-memid">
      <label class="form-label">Gửi thông báo cho thành viên</label>
      <div class="admx-mrow"><input type="text" name="msg" class="form-control" maxlength="200" placeholder="Nội dung thông báo..." required><button type="submit" class="btn btn-sm">Gửi</button></div>
    </form>
    <?php endif; ?>
    <form method="post" class="admx-mf">
      <?= csrf() ?>
      <input type="hidden" name="action" value="lock_unlock_user">
      <input type="hidden" name="id" class="js-memid">
      <button type="submit" class="btn btn-ghost btn-sm btn-full"><?= ic('key') ?> Mở khoá get key cho thành viên này</button>
    </form>
<?= fkx_ui('memdlg') ?>
  </dialog>
  <!-- ADMX-PANELS-END -->

  <!-- ===== THANH VIEN ===== -->
  <div class="panel" id="tab-members" hidden>
    <section class="card">
      <div class="card-header border-b">
        <div>
          <h2 class="card-title"><?= ic('users') ?> Danh Sách Thành Viên</h2>
          <p class="muted-text text-xs mt-1">Hiển thị <span id="mShown" class="font-semibold"><?= count($members) ?></span>/<?= count($members) ?> tài khoản</p>
        </div>
      </div>
      <div class="toolbar">
        <div class="search-input-wrap"><?= ic('search') ?>
          <input type="search" id="mQ" class="search-input" placeholder="Tìm kiếm theo username..." autocomplete="off"></div>
      </div>
      <div>
        <?php foreach ($members as $m):
            $exp = $m['expiry_date'] ?? '';
            $none = (($m['key_type'] ?? 'NONE') === 'NONE' || !$exp || $exp === '2000-01-01 00:00:00');
            $live = !$none && (int)($m['exp_ts'] ?? 0) > $now;
            $isAdmin = (int)$m['role'] === 1;
            $turnsLeft = array_key_exists('turns_left', $m) ? (int)($m['turns_left'] ?? 1) : null; // NULL trong DB = 1 luot (giong index.php)
        ?>
          <div class="member-row" data-name="<?= e(strtolower($m['username'])) ?>">
            <div class="flex-1">
              <div class="flex-align gap-2">
                <span class="font-semibold" style="font-size:13px"><?= e($m['username']) ?></span>
                <?php if ($isAdmin): ?><span class="badge badge-neutral">Quản trị viên</span><?php endif; ?>
                <span class="badge <?= $m['key_type'] === 'VIP' ? 'badge-vip' : ($m['key_type'] === 'FREE' ? 'badge-ok' : 'badge-neutral') ?>"><?= e($m['key_type'] ?: 'NONE') ?></span>
              </div>
              <div class="muted-text text-xs mt-1">
                <?php if ($none): ?>
                  Chưa kích hoạt key / Không có thời hạn
                <?php elseif ($live): ?>
                  Hết hạn: <b><?= e(date('d/m/Y H:i', strtotime($exp))) ?></b> &bull; <span class="text-ok font-semibold"><?= remain($m['exp_ts'], $now) ?></span><?php if ($turnsLeft !== null): ?> &bull; <?= $turnsLeft ?> lượt<?php endif; ?>
                <?php else: ?>
                  <span style="color:var(--dg)">Hết hạn từ: <?= e(date('d/m/Y', strtotime($exp))) ?></span>
                <?php endif; ?>
              </div>
            </div>
            <?php if (!$isAdmin): ?>
<?= fkx_ui('memrow', $m) ?>
              <?php if (admx_user_lock($m, $admxLockMap)): ?>
                <span class="badge badge-bad" title="Tài khoản đang bị khoá get key">Đang khoá get key</span>
                <button type="button" class="btn btn-ghost btn-xs" data-act="lock_unlock_user" data-fields="<?= e(json_encode(['id' => (int)$m['id']])) ?>" data-danger="0"
                  data-title="Mở khoá get key cho <?= e($m['username']) ?>?" data-desc="Thành viên này sẽ get key tiếp được ngay, không cần chờ hết giờ.">Mở khoá</button>
              <?php endif; ?>
              <button type="button" class="btn btn-ghost btn-xs" data-mem="<?= (int)$m['id'] ?>" data-name="<?= e($m['username']) ?>" data-turns="<?= $turnsLeft !== null ? (int)$turnsLeft : '' ?>"><?= ic('edit') ?> Sửa</button>
            <?php endif; ?>
            <?php if ($m['key_type'] !== 'NONE' && !$isAdmin): ?>
              <button type="button" class="btn btn-danger-outline btn-xs"
                data-act="kill_key" data-fields="<?= e(json_encode(['id' => (int)$m['id']])) ?>"
                data-title="Hủy key của <?= e($m['username']) ?>?" data-desc="Toàn bộ thời gian sử dụng sẽ reset về 0 và tài khoản trở về thành viên thường.">Hủy Key Về 0</button>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="empty-state" id="mEmpty" hidden>Không tìm thấy thành viên nào khớp với từ khóa.</div>
    </section>
  </div>

  <!-- ===== GIAO DIEN WEB (theme) ===== -->
  <div class="panel" id="tab-theme" hidden>
    <div style="max-width:760px;margin:0 auto">

      <section class="card">
        <div class="card-header border-b">
          <h2 class="card-title"><?= ic('image') ?> Ảnh banner trang chủ</h2>
          <span class="muted-text text-xs"><?= count($heroList) ?> ảnh &bull; không giới hạn</span>
        </div>
        <div class="card-body">
          <p class="form-hint" style="margin-bottom:12px">Banner tự chuyển ảnh theo thứ tự bên dưới. <?= $heroList ? "Bấm mũi tên để đổi thứ tự, thùng rác để xóa." : "Chưa thêm ảnh nào nên web đang dùng 3 ảnh mặc định của theme. Thêm ảnh đầu tiên là thay hoàn toàn bộ mặc định." ?></p>
          <?php if ($heroList): ?>
            <div class="hero-grid">
              <?php foreach ($heroList as $hi => $hu): ?>
                <div class="hero-item">
                  <img src="<?= e($hu) ?>" alt="" loading="lazy" onerror="this.style.opacity='.25'">
                  <div class="hero-meta">
                    <span class="muted-text text-xs">#<?= $hi + 1 ?></span>
                    <div class="hero-btns">
                      <?php if ($hi > 0): ?><button type="button" class="action-icon-btn" title="Lên trước" data-act="hero_move" data-confirm="0" data-fields="<?= e(json_encode(['idx' => $hi, 'dir' => -1])) ?>"><?= ic('up') ?></button><?php endif; ?>
                      <?php if ($hi < count($heroList) - 1): ?><button type="button" class="action-icon-btn" title="Xuống sau" data-act="hero_move" data-confirm="0" data-fields="<?= e(json_encode(['idx' => $hi, 'dir' => 1])) ?>"><?= ic('down') ?></button><?php endif; ?>
                      <button type="button" class="action-icon-btn" title="Xóa ảnh" data-act="hero_del" data-fields="<?= e(json_encode(['idx' => $hi])) ?>" data-title="Xóa ảnh banner #<?= $hi + 1 ?>?" data-desc="Ảnh sẽ biến khỏi banner trang chủ (file tải lên cũng bị xóa)."><?= ic('trash') ?></button>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <form method="post" enctype="multipart/form-data">
            <?= csrf() ?>
            <input type="hidden" name="action" value="hero_add">
            <div class="form-group"><label class="form-label">Dán link ảnh (mỗi dòng một link)</label>
              <textarea name="hero_url" class="form-control form-textarea" placeholder="https://..."></textarea></div>
            <div class="form-group"><label class="form-label">Hoặc tải ảnh lên (chọn được nhiều ảnh, mỗi ảnh &le; 3MB)</label>
              <input type="file" name="hero_files[]" class="form-control" accept="image/*" multiple></div>
            <button type="submit" class="btn btn-full">Thêm ảnh vào banner</button>
          </form>
        </div>
      </section>

      <section class="card">
        <div class="card-header border-b"><h2 class="card-title"><?= ic('user') ?> Avatar cạnh nút sáng / tối</h2></div>
        <form method="post" enctype="multipart/form-data" class="card-body">
          <?= csrf() ?>
          <input type="hidden" name="action" value="save_avatar">
          <div class="avatar-setting-box" style="margin-bottom:14px">
            <img id="dsAvaPrev" class="avatar-preview-img" src="<?= e($dsAvatar) ?>" alt="Avatar" onerror="this.removeAttribute('src')">
            <div class="flex-1">
              <input type="text" name="ava_url" class="form-control" value="<?= e($dsAvatar) ?>" placeholder="Dán link ảnh https://... (hoặc /uploads/...)" inputmode="url" style="margin-bottom:8px">
              <input type="file" name="ava_file" id="dsAvaIn" class="form-control" accept="image/*">
              <p class="muted-text text-xs mt-1">Chọn file thì file được ưu tiên hơn link. <?= $dsAvatar === '' ? "Đang dùng avatar mặc định của theme." : "" ?></p>
            </div>
          </div>
          <div class="flex-align gap-2" style="flex-wrap:wrap">
            <button type="submit" class="btn">Lưu avatar</button>
            <?php if ($dsAvatar !== ''): ?>
              <button type="submit" name="avatar_reset" value="1" class="btn btn-ghost">Trả về mặc định</button>
            <?php endif; ?>
          </div>
        </form>
      </section>

      <?php $lg = (string)($cf['brand_logo'] ?? ''); $lgSrc = preg_match('#^https?://#i', $lg) ? $lg : '/' . ltrim($lg, '/'); ?>
      <section class="card">
        <div class="card-header border-b"><h2 class="card-title"><?= ic('palette') ?> Logo website</h2></div>
        <form method="post" enctype="multipart/form-data" class="card-body">
          <?= csrf() ?>
          <input type="hidden" name="action" value="save_logo">
          <div style="margin-bottom:14px">
            <img src="<?= e($lgSrc) ?>" alt="Logo" style="max-height:56px;max-width:100%;object-fit:contain;margin-bottom:10px;display:block;background:#8884;padding:6px;border-radius:8px" onerror="this.style.display='none'">
            <input type="text" name="logo_url" class="form-control" value="<?= e($lg) ?>" placeholder="https://... hoac /uploads/...">
            <input type="file" name="logo_file" class="form-control" accept="image/*" style="margin-top:8px">
            <p class="muted-text text-xs mt-1">Ch&#7885;n file th&#236; file &#273;&#432;&#7907;c &#432;u ti&#234;n h&#417;n link.</p>
          </div>
          <div class="flex-align gap-2" style="flex-wrap:wrap">
            <button type="submit" class="btn">L&#432;u logo</button>
            <button type="submit" name="logo_reset" value="1" class="btn btn-ghost">Tr&#7843; v&#7873; m&#7863;c &#273;&#7883;nh</button>
          </div>
        </form>
      </section>

      <section class="card">
        <div class="card-header border-b"><h2 class="card-title"><?= ic('palette') ?> Giao diện, màu sắc &amp; chữ trên web</h2></div>
        <form method="post" class="card-body">
          <?= csrf() ?>
          <input type="hidden" name="action" value="save_theme">
          <p class="form-hint" style="margin-bottom:12px">Ô để trống hoặc &ldquo;Mặc định&rdquo; là giữ nguyên giao diện hiện tại của theme. Chỉ những mục bạn đổi mới được ghi vào DB. Cần patch_v25.php đã chạy để theme đọc được các mục này.</p>
          <?php foreach ($TH as $group => $fields): ?>
            <details class="adv-group">
              <summary><?= e($group) ?></summary>
              <div class="adv-body">
                <?php foreach ($fields as $k => $f) echo field_html('th', $k, $f, (string)($cf[$k] ?? ''), 'th_'); ?>
              </div>
            </details>
          <?php endforeach; ?>
          <details class="adv-group">
            <summary>Chữ trên web (tên tab, tiêu đề gói, mô tả, thông báo...)</summary>
            <div class="adv-body">
              <p class="form-hint" style="margin-bottom:12px">Mỗi ô là một đoạn chữ của theme. Để trống = chữ mặc định (hiện mờ trong ô).</p>
              <?php foreach ($TX as $tk => $td): ?>
                <div class="form-group">
                  <label class="form-label" for="tx_<?= e($tk) ?>"><span class="key-name"><?= e($tk) ?></span></label>
                  <input type="text" id="tx_<?= e($tk) ?>" name="tx[<?= e($tk) ?>]" value="<?= e($cf['ds_t_' . $tk] ?? '') ?>" placeholder="<?= e($td) ?>" class="form-control" maxlength="255" autocomplete="off">
                </div>
              <?php endforeach; ?>
            </div>
          </details>
          <button type="submit" class="btn btn-full">Lưu giao diện web</button>
        </form>
      </section>
      <section class="card">
        <div class="card-header border-b"><h2 class="card-title"><?= ic('settings') ?> Sao lưu / khôi phục giao diện</h2></div>
        <div class="card-body">
          <?php
            $dsExp = [];
            foreach ($cf as $ek => $ev) { if (strncmp((string)$ek, 'ds_', 3) === 0) $dsExp[$ek] = $ev; }
            ksort($dsExp);
            $dsExpJson = $dsExp ? json_encode($dsExp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : '{}';
          ?>
          <label class="form-label" for="dsExport">Cấu hình giao diện hiện tại (copy để sao lưu, có cả ảnh banner, avatar, thông tin tool)</label>
          <textarea id="dsExport" class="form-control form-textarea" readonly onfocus="this.select()" style="min-height:130px;font-family:var(--mono);font-size:11px"><?= e($dsExpJson) ?></textarea>
          <div class="flex-align gap-2" style="flex-wrap:wrap;margin:10px 0 16px">
            <button type="button" class="btn btn-ghost btn-sm" data-copy="dsExport"><?= ic('copy') ?> Copy</button>
            <button type="button" class="btn btn-danger-outline btn-sm" data-act="theme_reset" data-fields="{}" data-danger="1"
              data-title="Khôi phục giao diện mặc định?" data-desc="Xoá toàn bộ màu, chữ, cỡ chữ, tuỳ chọn và CSS riêng đã chỉnh. Ảnh banner, avatar và thông tin từng tool được giữ nguyên.">Khôi phục mặc định</button>
          </div>
          <form method="post">
            <?= csrf() ?>
            <input type="hidden" name="action" value="theme_import">
            <div class="form-group"><label class="form-label">Nhập cấu hình (dán JSON đã sao lưu)</label>
              <textarea name="theme_json" class="form-control form-textarea" style="font-family:var(--mono);font-size:11px" placeholder='{"ds_hero_title":"Apps"}'></textarea></div>
            <button type="submit" class="btn btn-full">Nhập cấu hình</button>
          </form>
        </div>
      </section>
    </div>
  </div>

  <!-- ===== CAU HINH ===== -->
  <div class="panel" id="tab-settings" hidden>
    <section class="card" style="max-width:640px;margin:0 auto 16px">
      <div class="card-header border-b"><h2 class="card-title"><?= ic('settings') ?> Cấu Hình Giao Diện Trang Chủ</h2></div>
      <form method="post" enctype="multipart/form-data" class="card-body">
        <?= csrf() ?>
        <input type="hidden" name="action" value="save_site">
        <div class="form-group"><label class="form-label">Logo / Tên Website</label>
          <input type="text" name="cfg[logo_text]" value="<?= e($cf['logo_text'] ?? '') ?>" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Mô tả Banner (Slogan)</label>
          <input type="text" name="cfg[banner_desc]" value="<?= e($cf['banner_desc'] ?? '') ?>" class="form-control"></div>
        <div class="form-group"><label class="form-label">Link Vượt Key Free (Bypass)</label>
          <input type="text" name="cfg[link_bypass]" value="<?= e($cf['link_bypass'] ?? '') ?>" class="form-control" inputmode="url"></div>
        <div class="form-group"><label class="form-label">Link Nhóm Telegram</label>
          <input type="text" name="cfg[link_tele_group]" value="<?= e($cf['link_tele_group'] ?? '') ?>" class="form-control" inputmode="url"></div>
        <div class="form-grid-2">
          <div class="form-group"><label class="form-label">Footer By (Tên hiển thị)</label>
            <input type="text" name="cfg[footer_made_by]" value="<?= e($cf['footer_made_by'] ?? '') ?>" class="form-control"></div>
          <div class="form-group"><label class="form-label">Năm bản quyền</label>
            <input type="text" name="cfg[footer_year]" value="<?= e($cf['footer_year'] ?? '') ?>" class="form-control"></div>
        </div>
        <div class="form-group"><label class="form-label">Ảnh Avatar Banner (Tải lên ảnh mới)</label>
          <div class="avatar-setting-box">
            <img id="avaPrev" src="<?= e($ava_src) ?>" alt="Avatar" class="avatar-preview-img" onerror="this.removeAttribute('src')">
            <div class="flex-1">
              <input type="file" name="site_ava" id="avaIn" accept="image/*" class="form-control">
              <p class="muted-text text-xs mt-1">Hệ thống tự động xóa avatar cũ và làm mới cache tức thì.</p>
            </div>
          </div>
        </div>
        <button type="submit" class="btn btn-full">Lưu Tất Cả Cài Đặt</button>
      </form>
    </section>

    <section class="card" style="max-width:640px;margin:0 auto 16px">
      <div class="card-header border-b"><h2 class="card-title"><?= ic('settings') ?> Cấu Hình Nâng Cao</h2></div>
      <form method="post" class="card-body">
        <?= csrf() ?>
        <input type="hidden" name="action" value="save_adv">
        <p class="form-hint" style="margin-bottom:12px">Mở từng nhóm để chỉnh. Ô màu để trống = dùng màu mặc định của trang. Chỉ những mục bạn thay đổi mới được ghi vào DB.</p>
        <?php foreach ($ADV as $group => $fields): ?>
          <details class="adv-group">
            <summary><?= e($group) ?></summary>
            <div class="adv-body">
              <?php foreach ($fields as $k => $f) echo field_html('adv', $k, $f, (string)($cf[$k] ?? ''), 'adv_'); ?>
            </div>
          </details>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-full">Lưu Cấu Hình Nâng Cao</button>
      </form>
    </section>

    <section class="card" style="max-width:640px;margin:0 auto">
      <div class="card-header border-b"><h2 class="card-title"><?= ic('alert') ?> Nhật Ký Hệ Thống</h2></div>
      <div class="card-body">
        <details class="adv-group">
          <summary>Thao tác trong admin (<?= count($admin_logs) ?> gần nhất)</summary>
          <div>
            <?php if (!$admin_logs): ?>
              <div class="empty-state">Chưa có thao tác nào được ghi lại.</div>
            <?php else: foreach ($admin_logs as $l): ?>
              <div class="log-row"><div class="flex-1">
                <div class="font-semibold" style="font-size:12px"><?= e($l['action']) ?> <span class="muted-text" style="font-weight:400">&bull; <?= e($l['username']) ?></span></div>
                <div class="log-meta"><?= e($l['detail']) ?> &bull; <?= e($l['ip']) ?> &bull; <?= e($l['created_at']) ?></div>
              </div></div>
            <?php endforeach; endif; ?>
          </div>
        </details>
        <details class="adv-group">
          <summary>Vi phạm bảo mật VPN / Script (<?= count($sec_logs) ?> gần nhất)</summary>
          <div>
            <?php if (!$sec_logs): ?>
              <div class="empty-state">Chưa ghi nhận vi phạm nào.</div>
            <?php else: foreach ($sec_logs as $l): ?>
              <div class="log-row"><div class="flex-1">
                <div class="font-semibold" style="font-size:12px"><?= e($l['violation_type']) ?></div>
                <div class="log-meta"><?= e($l['ip_address']) ?> &bull; <?= e($l['created_at']) ?></div>
              </div></div>
            <?php endforeach; endif; ?>
          </div>
        </details>
      </div>
    </section>
  </div>

</main>

<form id="actForm" method="post" hidden>
  <?= csrf() ?>
  <input type="hidden" name="action" id="actName">
  <span id="actFields"></span>
</form>

<dialog id="dlg">
  <div class="modal-body"><h3 class="modal-title" id="dlgT"></h3><p class="modal-desc" id="dlgD"></p></div>
  <div class="modal-actions">
    <button type="button" class="btn btn-ghost btn-sm" id="dlgNo">Hủy</button>
    <button type="button" class="btn btn-danger btn-sm" id="dlgOk">Xác nhận</button>
  </div>
</dialog>

<div class="toast-notice" id="toast" role="status" aria-live="polite"></div>

<script>
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const ICO_SUN = <?= json_encode(ic('sun')) ?>, ICO_MOON = <?= json_encode(ic('moon')) ?>;

/* ---- Giao dien sang / toi cua trang admin ---- */
function paintTheme() {
  const light = document.documentElement.getAttribute('data-adm') === 'light';
  $('#admTheme').innerHTML = light ? ICO_SUN : ICO_MOON;
}
$('#admTheme').addEventListener('click', () => {
  const light = document.documentElement.getAttribute('data-adm') === 'light';
  if (light) document.documentElement.removeAttribute('data-adm'); else document.documentElement.setAttribute('data-adm', 'light');
  document.documentElement.setAttribute('data-adm', light ? 'dark' : 'light');
  try { localStorage.setItem('adm-theme', light ? 'dark' : 'light'); } catch (e) {}
  paintTheme();
});
paintTheme();

/* ---- Tab ---- */
function showTab(id) {
  if (!$('#tab-' + id)) id = 'keys';
  $$('.panel').forEach(p => p.hidden = (p.id !== 'tab-' + id));
  $$('.tabs-nav a').forEach(a => a.setAttribute('aria-current', a.dataset.tab === id ? 'page' : 'false'));
}
addEventListener('hashchange', () => showTab(location.hash.slice(1)));
showTab(location.hash.slice(1) || 'keys');

/* ---- Toast ---- */
let toastTimer;
function toast(msg, type) {
  const t = $('#toast');
  t.textContent = msg;
  t.className = 'toast-notice show' + (type === 'err' ? ' toast-err' : '');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 4200);
}
<?php if ($flash): ?>
toast(<?= json_encode($flash['m'], JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($flash['t']) ?>);
<?php endif; ?>

/* ---- Hop thoai xac nhan ---- */
const dlg = $('#dlg');
function ask(title, desc, danger, onOk) {
  $('#dlgT').textContent = title;
  $('#dlgD').textContent = desc;
  $('#dlgOk').className = 'btn btn-sm ' + (danger ? 'btn-danger' : '');
  $('#dlgOk').onclick = () => { if (dlg.close) dlg.close(); onOk(); };
  if (dlg.showModal) dlg.showModal(); else if (confirm(title + '\n' + desc)) onOk();
}
$('#dlgNo').onclick = () => dlg.close();
dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });

/* ---- Cac nut data-act (gui form POST an). data-confirm="0" = khong hoi xac nhan ---- */
document.addEventListener('click', e => {
  const b = e.target.closest('[data-act]');
  if (!b) return;
  const f = JSON.parse(b.dataset.fields || '{}');
  const send = () => {
    $('#actName').value = b.dataset.act;
    $('#actFields').innerHTML = '';
    for (const k in f) {
      const i = document.createElement('input');
      i.type = 'hidden'; i.name = k; i.value = f[k];
      $('#actFields').appendChild(i);
    }
    $('#actForm').submit();
  };
  if (b.dataset.confirm === '0') { send(); return; }
  ask(b.dataset.title, b.dataset.desc, b.dataset.danger !== '0', send);
});

/*ADMX-JS-BEGIN*/
const ADMX_OFF = <?= (int)time() ?> * 1000 - Date.now();
const memDlg = $('#memDlg');
document.addEventListener('click', e => {
  const g = e.target.closest('[data-goto]');
  if (g) { location.hash = g.dataset.goto; return; }
  const mb = e.target.closest('[data-mem]');
  if (!mb || !memDlg) return;
  $('#memTitle').textContent = 'Quản lý: ' + (mb.dataset.name || '');
  $$('.js-memid').forEach(i => { i.value = mb.dataset.mem; });
  const tn = $('#memTurns'); if (tn) tn.value = mb.dataset.turns || '';
  if (memDlg.showModal) memDlg.showModal();
});
if (memDlg) {
  $('#memClose').onclick = () => memDlg.close();
  memDlg.addEventListener('click', e => { if (e.target === memDlg) memDlg.close(); });
}
document.addEventListener('change', e => {
  const sw = e.target.closest('.admx-sw');
  if (!sw) return;
  const f = sw.closest('form');
  f.querySelector('input[name=status]').value = sw.checked ? 'maintenance' : 'active';
  if (f.requestSubmit) f.requestSubmit(); else f.submit();
});
const admxQ = $('#admxQ');
if (admxQ) admxQ.addEventListener('input', () => {
  const q = admxQ.value.trim().toLowerCase();
  $$('.admx-trow').forEach(r => { r.hidden = !!q && !(r.dataset.name || '').includes(q); });
});
const admxLQ = $('#admxLQ');
if (admxLQ) admxLQ.addEventListener('input', () => {
  const q = admxLQ.value.trim().toLowerCase();
  let n = 0;
  $$('.admx-lrow').forEach(r => { const ok = !q || (r.dataset.q || '').includes(q); r.hidden = !ok; if (ok) n++; });
  const em = $('#admxLEmpty'); if (em) em.hidden = (n > 0 || !$$('.admx-lrow').length);
});
function admxTick() {
  const now = Date.now() + ADMX_OFF;
  $$('.js-left').forEach(el => {
    const d = Math.floor((Number(el.dataset.until) * 1000 - now) / 1000);
    if (d <= 0) { el.textContent = 'đã hết khoá'; return; }
    const h = Math.floor(d / 3600), m = Math.floor((d % 3600) / 60);
    el.textContent = 'còn ' + (h ? h + ' giờ ' : '') + m + ' phút';
  });
}
admxTick();
setInterval(admxTick, 20000);
/*ADMX-JS-END*/
/*FKX-JS-BEGIN*/
try {
(function () {
  const fq = $('#fkxQ');
  if (fq) fq.addEventListener('input', () => {
    const s = fq.value.trim().toLowerCase();
    let n = 0;
    $$('.fkx-row[data-q]').forEach(r => { const ok = !s || (r.dataset.q || '').includes(s); r.hidden = !ok; if (ok) n++; });
    const em = $('#fkxEmpty'); if (em) em.hidden = (n > 0 || !$$('.fkx-row[data-q]').length);
  });
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-copytext]');
    if (!b) return;
    const t = b.dataset.copytext || '';
    const done = () => toast('Đã copy link.');
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(t).then(done, () => fallbackCopy(t, done));
    else fallbackCopy(t, done);
  });
  function fkxTick() {
    const now = Date.now() + ADMX_OFF;
    $$('.fkx-left').forEach(el => {
      const d = Math.floor((Number(el.dataset.until) * 1000 - now) / 1000);
      if (d <= 0) { el.textContent = el.dataset.zero || ''; return; }
      const h = Math.floor(d / 3600), m = Math.floor((d % 3600) / 60);
      el.textContent = (el.dataset.pre || '') + (h ? h + 'h ' : '') + m + 'm';
    });
  }
  fkxTick();
  setInterval(fkxTick, 20000);
})();
} catch (fkxErr) { if (window.console) console.error("fkx", fkxErr); }
/*FKX-JS-END*/

/* ---- Mo / dong khung sua tool ---- */
document.addEventListener('click', e => {
  const b = e.target.closest('[data-edit]');
  if (!b) return;
  const box = document.getElementById('edit-' + b.dataset.edit);
  if (!box) return;
  box.hidden = !box.hidden;
  b.setAttribute('aria-expanded', String(!box.hidden));
});

/* ---- Chon kho VIP / FREE tren mobile ---- */
$$('.key-tabs-selector button').forEach(b => {
  b.onclick = () => {
    $('#keysContainer').dataset.seg = b.dataset.s;
    $$('.key-tabs-selector button').forEach(x => x.setAttribute('aria-pressed', x === b));
  };
});

/* ---- Kho key: tim kiem, loc, chon nhieu, copy ---- */
$$('.keybox').forEach(box => {
  const rows = $$('.key-item-row', box);
  const all = $('.js-all', box);
  const bulk = $('.js-bulk', box);
  const form = $('.kform', box);
  const type = box.dataset.seg.toUpperCase();

  function applyFilter() {
    const q = $('.js-q', box).value.trim().toUpperCase();
    const m = box.dataset.mode;
    let shown = 0;
    rows.forEach(r => {
      const matchQ = !q || r.dataset.code.includes(q);
      const matchM = (m === 'all') || (m === 'free' ? r.dataset.used === '0' : r.dataset.used === '1');
      const ok = matchQ && matchM;
      r.hidden = !ok;
      if (ok) shown++;
    });
    $('.js-shown', box).textContent = shown;
    $('.js-empty', box).hidden = (rows.length > 0 && shown > 0);
    syncState();
  }
  form.addEventListener('submit', e => e.preventDefault());
  function syncState() {
    const vis = rows.filter(r => !r.hidden);
    const chk = rows.filter(r => r.querySelector('input').checked);
    bulk.disabled = (chk.length === 0);
    bulk.textContent = chk.length ? `Xóa mục chọn (${chk.length})` : 'Xóa mục chọn';
    all.checked = (vis.length > 0 && vis.every(r => r.querySelector('input').checked));
  }
  $('.js-q', box).addEventListener('input', applyFilter);
  $$('.chip-item', box).forEach(c => {
    c.onclick = () => {
      box.dataset.mode = c.dataset.m;
      $$('.chip-item', box).forEach(x => x.setAttribute('aria-pressed', x === c));
      applyFilter();
    };
  });
  all.onchange = () => {
    rows.filter(r => !r.hidden).forEach(r => r.querySelector('input').checked = all.checked);
    syncState();
  };
  rows.forEach(r => r.querySelector('input').onchange = syncState);
  bulk.onclick = () => {
    const n = rows.filter(r => r.querySelector('input').checked).length;
    ask(`Xóa ${n} key ${type}?`, 'Các key đã chọn sẽ bị xóa vĩnh viễn khỏi hệ thống.', true, () => form.submit());
  };
  $('.js-copy', box).onclick = () => {
    const list = rows.filter(r => r.dataset.used === '0').map(r => r.dataset.code).join('\n');
    if (!list) return toast('Không có key nào còn trống để copy.', 'err');
    const done = () => toast(`Đã copy ${list.split('\n').length} key trống vào clipboard.`);
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(list).then(done, () => fallbackCopy(list, done));
    } else {
      fallbackCopy(list, done);
    }
  };
  syncState();
});

function fallbackCopy(text, done) {
  const t = document.createElement('textarea');
  t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
  document.body.appendChild(t); t.select();
  try { document.execCommand('copy'); done(); } catch (e) { toast('Không thể copy, vui lòng thử lại.', 'err'); }
  t.remove();
}

/* ---- Tim thanh vien ---- */
const mQ = $('#mQ');
if (mQ) {
  mQ.addEventListener('input', () => {
    const q = mQ.value.trim().toLowerCase();
    let n = 0;
    $$('.member-row').forEach(m => {
      const ok = !q || m.dataset.name.includes(q);
      m.hidden = !ok;
      if (ok) n++;
    });
    $('#mShown').textContent = n;
    $('#mEmpty').hidden = (n > 0);
  });
}

/* ---- Nut Copy (link / mat khau bao tri) ---- */
document.addEventListener('click', e => {
  const b = e.target.closest('[data-copy]');
  if (!b) return;
  const el = document.getElementById(b.dataset.copy);
  if (!el) return;
  const done = () => toast('Đã copy vào clipboard.');
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(el.value).then(done, () => fallbackCopy(el.value, done));
  } else {
    fallbackCopy(el.value, done);
  }
});

/* ---- Xem truoc avatar tuc thi ---- */
$('#avaIn').addEventListener('change', e => {
  const f = e.target.files[0];
  if (f) $('#avaPrev').src = URL.createObjectURL(f);
});
const dsAvaIn = $('#dsAvaIn');
if (dsAvaIn) dsAvaIn.addEventListener('change', e => {
  const f = e.target.files[0];
  if (f) $('#dsAvaPrev').src = URL.createObjectURL(f);
});

/* ---- O chon mau dong bo voi o chu ---- */
document.addEventListener('input', e => {
  const t = e.target;
  if (t.classList && t.classList.contains('color-pick')) {
    const inp = document.getElementById(t.dataset.for);
    if (inp) inp.value = t.value;
  } else if (t.classList && t.classList.contains('js-color')) {
    const pick = t.parentNode.querySelector('.color-pick');
    if (pick && /^#[0-9a-f]{6}$/i.test(t.value.trim())) pick.value = t.value.trim();
  }
});
</script>
</body>
</html>