<?php
/* fkx_lib.php - Get Key / Key.php cho admin_cp.php (do patch_spinner.py tao, KHONG sua tay).
   File rieng: neu co loi o day thi chi tab "Get Key / Key.php" bi an, admin_cp.php van chay binh thuong. */
if (!isset($GLOBALS['conn']) || !function_exists('run') || !function_exists('admin_log')) { return; }

/* ===== GET KEY (get_key.php) + TRANG KEY (key.php): doc cau hinh, mo khoa theo tai khoan / IP / luot nhan ===== */
if (is_file(__DIR__ . '/fk_config.php')) { @include_once __DIR__ . '/fk_config.php'; }
function fkx_c($n, $d) { return defined($n) ? constant($n) : $d; }
function fkx_cd()   { return max(60, (int)fkx_c('FK_COOLDOWN', 28800)); }
function fkx_view() { return max((int)fkx_c('FK_VIEW_WINDOW', 28800), fkx_cd()); }
function fkx_max()  { return max(1, (int)fkx_c('FK_MAX_STRIKES', 5)); }
function fkx_zero() { return str_repeat('0', 64); }
function fkx_pass_ttl() { return max(3600, (int)fkx_c('FK_PASS_TTL', 86400)); }
/* Cap "quyen nhan key" 1 lan cho dung 1 tai khoan: get_key.php bo qua khoa IP / thiet bi / VPN / IP bi chan cua tai khoan do.
   Dung xong tu xoa (hoac het han). Nguoi khac dung chung IP / thiet bi van bi khoa nhu cu. */
function fkx_pass_grant($conn, $un) {
    $un = trim((string)$un);
    if ($un === '') return false;
    try {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS free_key_pass (username VARCHAR(100) NOT NULL PRIMARY KEY, created_at INT UNSIGNED NOT NULL, expires_at INT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $t = time();
        $st = run($conn, "REPLACE INTO free_key_pass (username, created_at, expires_at) VALUES (?, ?, ?)", 'sii', [$un, $t, $t + fkx_pass_ttl()]);
        mysqli_stmt_close($st);
        return true;
    } catch (Throwable $ex) {
        return false;
    }
}
function fkx_pass_note($ok) {
    return $ok ? " Thành viên nhận key lại được ngay (đổi mạng / thiết bị / VPN đều được), dùng 1 lần, hiệu lực " . fkx_dur(fkx_pass_ttl()) . "." : "";
}
function fkx_dur($s) {
    $s = (int)$s;
    if ($s <= 0) return '';
    $h = intdiv($s, 3600);
    return ($h > 0 ? $h . 'h ' : '') . intdiv($s % 3600, 60) . 'm';
}
function fkx_src($f) {
    $p = __DIR__ . DIRECTORY_SEPARATOR . $f;
    if (!is_file($p) || !is_readable($p)) return '';
    $s = @file_get_contents($p, false, null, 0, 400000);
    return is_string($s) ? $s : '';
}
function fkx_hook_ok() { return strpos(fkx_src('key.php'), 'FKX-REBIND-BEGIN') !== false; }
function fkx_site() {
    foreach (['get_key.php', 'key.php'] as $f) {
        if (preg_match('/\$main_web\s*=\s*[\'"](https?:\/\/[^\'"]+)[\'"]/', fkx_src($f), $m)) return rtrim($m[1], '/');
    }
    $h = (string)($_SERVER['HTTP_HOST'] ?? '');
    return preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $h) ? 'https://' . $h : '';
}
function fkx_route() {
    if (preg_match('~/([A-Za-z0-9_-]+)/key-free/~', fkx_src('get_key.php'), $m)) return $m[1];
    return 'hkvpnmzyqfhw';
}
function fkx_key_url($user, $tok) { return fkx_site() . '/' . fkx_route() . '/key-free/' . rawurlencode((string)$user) . '?=' . $tok; }
/* Giong fk_ip_key() cua get_key.php: IPv4 giu nguyen, IPv6 rut ve /64 */
function fkx_ip_key($ip) {
    $bin = @inet_pton($ip);
    if ($bin !== false && strlen($bin) === 16) {
        if (substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") return inet_ntop(substr($bin, 12));
        return bin2hex(substr($bin, 0, 8)) . '/64';
    }
    return $ip;
}
function fkx_rows($conn, $sql, $types = '', $args = []) {
    $st = run($conn, $sql, $types, $args);
    $rs = mysqli_stmt_get_result($st);
    $o = [];
    while ($rs && ($r = mysqli_fetch_assoc($rs))) $o[] = $r;
    mysqli_stmt_close($st);
    return $o;
}
/* Mo khoa = day moc nhan key lui ra ngoai chu ky + doi cot day thanh gia tri rieng (de nhan key moi khong dung unique index).
   Lich su van con nguyen, khong xoa dong nao. */
function fkx_release_where($conn, $cond, $types, $args) {
    if (!table_exists($conn, 'free_key_claims')) return 0;
    $since = time() - fkx_cd();
    $st = run($conn, "UPDATE free_key_claims SET created_at = ?, day = CONCAT('r', LOWER(HEX(id))) WHERE created_at > ? AND (" . $cond . ")",
        'ii' . $types, array_merge([$since - 1, $since], $args));
    $n = (int)mysqli_stmt_affected_rows($st);
    mysqli_stmt_close($st);
    return max(0, $n);
}
/* Go "strike" (blocked_*) cua IP / tai khoan: doi thanh cleared_*, nhat ky van con */
function fkx_clear_strikes($conn, $ip = '', $user = '') {
    if (!table_exists($conn, 'free_key_ip_log')) return 0;
    $since = time() - fkx_cd();
    $ips = [];
    if ($ip !== '') $ips[(string)$ip] = 1;
    if ($user !== '') {
        foreach (fkx_rows($conn, "SELECT DISTINCT ip FROM free_key_ip_log WHERE username = ? AND event LIKE 'blocked%' AND created_at >= ?", 'si', [$user, $since]) as $r) $ips[(string)$r['ip']] = 1;
    }
    $n = 0;
    foreach (array_keys($ips) as $k) {
        $k = (string)$k;
        $st = run($conn, "UPDATE free_key_ip_log SET event = CONCAT('cleared', SUBSTRING(event, 8)) WHERE (ip = ? OR ip_raw = ?) AND event LIKE 'blocked%' AND created_at >= ?", 'ssi', [$k, $k, $since]);
        $n += max(0, (int)mysqli_stmt_affected_rows($st));
        mysqli_stmt_close($st);
    }
    return $n;
}
/* Cho phep IP qua bo loc VPN (ghi vao cache 30 ngay la khong phai VPN) */
function fkx_vpn_allow($conn, $ip_raw) {
    if (!table_exists($conn, 'free_key_ip_cache') || !filter_var($ip_raw, FILTER_VALIDATE_IP)) return false;
    $st = run($conn, "REPLACE INTO free_key_ip_cache (ip, is_vpn, checked_at) VALUES (?, 0, ?)", 'si', [$ip_raw, time() + 30 * 86400]);
    mysqli_stmt_close($st);
    return true;
}
/* Lay thanh vien tu id (nut trong danh sach) hoac tu ten go tay */
function fkx_member($conn, $in) {
    $id = (int)($in['id'] ?? 0);
    if ($id > 0) {
        $u = one($conn, "SELECT id, username FROM users WHERE id = ?", 'i', [$id]);
        if (!$u) throw new RuntimeException("Không tìm thấy thành viên.");
        return [(int)$u['id'], (string)$u['username']];
    }
    $nm = trim((string)($in['username'] ?? ''));
    if ($nm === '' || slen($nm) > 100) throw new RuntimeException("Hãy nhập tên tài khoản.");
    $u = one($conn, "SELECT id, username FROM users WHERE username = ?", 's', [$nm]);
    return [$u ? (int)$u['id'] : 0, $u ? (string)$u['username'] : $nm];
}
/* Mo lai trang key.php cho 1 luot nhan key: gia han thoi gian xem + (neu key.php co hook) cho gan lai vao thiet bi / mang moi */
function fkx_open_claim($conn, $claimId) {
    if (!table_exists($conn, 'free_key_flow')) throw new RuntimeException("Chưa có bảng free_key_flow.");
    $c = one($conn, "SELECT id, username FROM free_key_claims WHERE id = ?", 'i', [(int)$claimId]);
    if (!$c) throw new RuntimeException("Không tìm thấy lượt nhận key này.");
    $f = one($conn, "SELECT id, view_token FROM free_key_flow WHERE claim_id = ? AND view_token IS NOT NULL ORDER BY id DESC LIMIT 1", 'i', [(int)$claimId]);
    if (!$f || (string)$f['view_token'] === '') throw new RuntimeException("Lượt này không có token xem key nên không mở lại được.");
    $now = time();
    if (fkx_hook_ok()) {
        $st = run($conn, "UPDATE free_key_flow SET sid_hash = ?, verified_at = ? WHERE id = ?", 'sii', [fkx_zero(), $now, (int)$f['id']]);
        mysqli_stmt_close($st);
        $_SESSION['fkx_link'] = ['user' => (string)$c['username'], 'url' => fkx_key_url($c['username'], $f['view_token'])];
        admin_log($conn, 'fk_view_open', "Mở lại key.php cho " . $c['username'] . " (lượt #" . (int)$claimId . ")");
        return "Đã mở lại key.php cho «" . $c['username'] . "». Thành viên đăng nhập đúng tài khoản rồi bấm nhận key (hoặc mở trang key) là xem lại được, không cần link. Link dự phòng hiện ở đầu trang.";
    }
    $st = run($conn, "UPDATE free_key_flow SET verified_at = ? WHERE id = ?", 'ii', [$now, (int)$f['id']]);
    mysqli_stmt_close($st);
    admin_log($conn, 'fk_view_extend', "Gia hạn xem key.php cho " . $c['username'] . " (lượt #" . (int)$claimId . ")");
    return "key.php chưa cài hook mở lại (hãy chạy lại file patch). Mới gia hạn thời gian xem: thành viên phải dùng đúng trình duyệt và mạng cũ.";
}
/* Moi thao tac mo khoa get_key.php / key.php. Tra ve cau thong bao, loi thi nem RuntimeException */
function fkx_do_inner($conn, $act, $in) {
    $now = time();
    switch ($act) {
        case 'fk_unlock_claim':
            $id = (int)($in['id'] ?? 0);
            $c = one($conn, "SELECT id, username, ip FROM free_key_claims WHERE id = ?", 'i', [$id]);
            if (!$c) throw new RuntimeException("Không tìm thấy lượt nhận key này.");
            $n = fkx_release_where($conn, 'id = ?', 'i', [$id]);
            $s = fkx_clear_strikes($conn, (string)$c['ip'], (string)$c['username']);
            $p = fkx_pass_grant($conn, (string)$c['username']);
            if ($n + $s < 1 && !$p) throw new RuntimeException("Lượt này đã hết khoá từ trước.");
            admin_log($conn, 'fk_unlock_claim', "Mở khoá get_key.php cho " . $c['username'] . " (lượt #$id, IP " . $c['ip'] . ")");
            return "Đã mở khoá get_key.php cho «" . $c['username'] . "»." . fkx_pass_note($p);

        case 'fk_unlock_user':
            list($uid, $un) = fkx_member($conn, $in);
            if ($uid < 1) throw new RuntimeException("Không tìm thấy tài khoản «" . $un . "».");
            $n = fkx_release_where($conn, 'username = ?', 's', [$un]);
            $s = fkx_clear_strikes($conn, '', $un);
            $p = fkx_pass_grant($conn, $un);
            if ($n + $s < 1 && !$p) throw new RuntimeException("Không mở khoá được «" . $un . "» (không tạo được quyền nhận key).");
            admin_log($conn, 'fk_unlock_user', "Mở khoá get_key.php cho " . $un . " ($n lượt, $s lần chặn" . ($p ? ', cấp quyền nhận key 1 lần' : '') . ")");
            return "Đã mở khoá get_key.php cho «" . $un . "»." . fkx_pass_note($p);

        case 'fk_unlock_ip':
            $raw = trim((string)($in['ip'] ?? ''));
            if (filter_var($raw, FILTER_VALIDATE_IP)) {
                $key = fkx_ip_key($raw);
            } elseif (preg_match('/^[0-9a-f]{16}\/64$/i', $raw)) {
                $key = strtolower($raw);
                $raw = '';
            } else {
                throw new RuntimeException("IP không hợp lệ.");
            }
            $n = fkx_release_where($conn, 'ip = ?', 's', [$key]);
            $s = fkx_clear_strikes($conn, $key);
            if ($raw !== '' && $raw !== $key) $s += fkx_clear_strikes($conn, $raw);
            $v = (!empty($in['vpn']) && $raw !== '') ? fkx_vpn_allow($conn, $raw) : false;
            if ($n + $s < 1 && !$v) throw new RuntimeException("IP này hiện không bị khoá.");
            admin_log($conn, 'fk_unlock_ip', "Mở khoá get_key.php cho IP " . ($raw !== '' ? $raw : $key) . " ($n lượt, $s lần chặn" . ($v ? ', cho phép VPN' : '') . ")");
            return "Đã mở khoá IP " . ($raw !== '' ? $raw : $key) . ($v ? " (cho phép cả VPN trong 30 ngày)" : "") . ".";

        case 'fk_unlock_all':
            $n = fkx_release_where($conn, '1 = 1', '', []);
            $s = 0;
            if (table_exists($conn, 'free_key_ip_log')) {
                $st = run($conn, "UPDATE free_key_ip_log SET event = CONCAT('cleared', SUBSTRING(event, 8)) WHERE event LIKE 'blocked%' AND created_at >= ?", 'i', [$now - fkx_cd()]);
                $s = max(0, (int)mysqli_stmt_affected_rows($st));
                mysqli_stmt_close($st);
            }
            if ($n + $s < 1) throw new RuntimeException("Hiện không có khoá nào ở get_key.php.");
            admin_log($conn, 'fk_unlock_all', "Mở khoá get_key.php tất cả ($n lượt, $s lần chặn)");
            return "Đã mở khoá get_key.php cho tất cả ($n lượt nhận key, $s lần chặn IP).";

        case 'fk_view_open':
            return fkx_open_claim($conn, (int)($in['id'] ?? 0));

        case 'fk_view_user':
            list($uid, $un) = fkx_member($conn, $in);
            $c = one($conn, "SELECT c.id FROM free_key_claims c JOIN free_key_flow f ON f.claim_id = c.id WHERE c.username = ? AND f.view_token IS NOT NULL ORDER BY c.id DESC LIMIT 1", 's', [$un]);
            if (!$c) throw new RuntimeException("«" . $un . "» chưa nhận key free nào bằng get_key.php.");
            return fkx_open_claim($conn, (int)$c['id']);

        case 'fk_unlock_full':
            list($uid, $un) = fkx_member($conn, $in);
            $out = [];
            try {
                $keys = ['u_' . strtolower($un)];
                if ($uid > 0) $keys[] = 'u_' . $uid;
                $a = admx_lock_unlock_users($keys);
                if ($a > 0) { admx_unlock_epoch($conn); $out[] = "nhập key (theme) $a mục"; }
            } catch (Throwable $ex) {
                $out[] = "nhập key (theme) lỗi ghi file khoá";
            }
            $b = fkx_release_where($conn, 'username = ?', 's', [$un]);
            $s = fkx_clear_strikes($conn, '', $un);
            if ($b + $s > 0) $out[] = "get_key.php $b lượt, $s lần chặn";
            if ($uid > 0 && fkx_pass_grant($conn, $un)) $out[] = "get_key.php nhận key lại 1 lần (bỏ qua khoá IP / thiết bị / VPN)";
            if (table_exists($conn, 'free_key_flow')) {
                $c = one($conn, "SELECT c.id FROM free_key_claims c JOIN free_key_flow f ON f.claim_id = c.id WHERE c.username = ? AND f.view_token IS NOT NULL AND f.verified_at >= ? ORDER BY c.id DESC LIMIT 1", 'si', [$un, $now - fkx_view()]);
                if ($c) {
                    try { fkx_open_claim($conn, (int)$c['id']); $out[] = "key.php đã mở lại"; } catch (Throwable $ex) { /* bo qua */ }
                }
            }
            if (!$out) throw new RuntimeException("«" . $un . "» hiện không bị khoá ở đâu cả.");
            admin_log($conn, 'fk_unlock_full', "Mở khoá toàn bộ cho " . $un . ": " . implode('; ', $out));
            return "Đã mở khoá «" . $un . "»: " . implode('; ', $out) . ".";
    }
    throw new RuntimeException("Thao tác không xác định.");
}

function fkx_do($conn, $act, $in) {
    try {
        return fkx_do_inner($conn, $act, $in);
    } catch (RuntimeException $ex) {
        throw $ex;
    } catch (Throwable $ex) {
        error_log('[admin_cp] fkx_do: ' . $ex->getMessage());
        throw new RuntimeException("Lỗi xử lý (đã ghi vào error_log): " . $ex->getMessage());
    }
}
/* Cap key truc tiep cho thanh vien (hop "Quan ly thanh vien") */
function fkx_grant($conn, $in) {
    $id = (int)($in['id'] ?? 0);
    $kt = strtoupper(trim((string)($in['ktype'] ?? '')));
    $h = (int)($in['hours'] ?? 0);
    if (!in_array($kt, ['FREE', 'VIP'], true)) throw new RuntimeException("Loại key không hợp lệ.");
    if ($h < 1 || $h > 87600) throw new RuntimeException("Số giờ phải từ 1 đến 87600.");
    $u = one($conn, "SELECT username FROM users WHERE id = ? AND role != 1", 'i', [$id]);
    if (!$u) throw new RuntimeException("Thành viên không tồn tại hoặc là tài khoản quản trị.");
    $st = run($conn, "UPDATE users SET key_type = ?, expiry_date = DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id = ? AND role != 1", 'sii', [$kt, $h, $id]);
    mysqli_stmt_close($st);
    admin_log($conn, 'member_grant', "Cấp key $kt $h giờ cho " . $u['username']);
    return "Đã cấp key $kt ($h giờ) cho «" . $u['username'] . "».";
}
/* Doc du lieu cho tab (goi 1 lan moi lan tai trang) */
function fkx_load($conn) {

$fkxNow = time();
$fkxCd = fkx_cd();
$fkxVw = fkx_view();
$fkxMax = fkx_max();
$fkxHas = table_exists($conn, 'free_key_claims');
$fkxHasFlow = table_exists($conn, 'free_key_flow');
$fkxHasLog = table_exists($conn, 'free_key_ip_log');
$fkxClaims = [];
$fkxKeyState = [];
$fkxStrikes = [];
$fkxLog = [];
$fkxLockUser = [];
$fkxActive = 0;
$fkxLockedIps = 0;
$fkxBlocks = 0;
$fkxLinkBox = isset($_SESSION['fkx_link']) ? $_SESSION['fkx_link'] : null;
unset($_SESSION['fkx_link']);
$fkxEvLbl = ['blocked_ip' => 'trùng IP', 'blocked_device' => 'trùng thiết bị', 'blocked_vpn' => 'VPN / proxy', 'blocked_race' => 'gửi trùng',
    'claim' => 'nhận key', 'start' => 'bắt đầu'];
if ($fkxHas) {
    $fkxSince = $fkxNow - $fkxCd;
    foreach (fetch_all($conn, "SELECT username, MAX(created_at) AS m FROM free_key_claims WHERE created_at > " . (int)$fkxSince . " GROUP BY username") as $r) {
        $fkxActive++;
        $fkxLockUser[strtolower((string)$r['username'])] = (int)$r['m'] + $fkxCd;
    }
    $fkxSel = "c.id, c.username, c.key_code, c.ip, c.created_at" . (col_exists($conn, 'free_key_claims', 'device_id') ? ", c.device_id" : "");
    if ($fkxHasFlow) {
        $fkxClaims = fetch_all($conn, "SELECT $fkxSel, f.view_token, f.verified_at, f.sid_hash FROM free_key_claims c LEFT JOIN free_key_flow f ON f.claim_id = c.id ORDER BY c.id DESC LIMIT 120");
    } else {
        $fkxClaims = fetch_all($conn, "SELECT $fkxSel FROM free_key_claims c ORDER BY c.id DESC LIMIT 120");
    }
    if ($fkxClaims) {
        $codes = [];
        foreach ($fkxClaims as $r) $codes["'" . mysqli_real_escape_string($conn, (string)$r['key_code']) . "'"] = 1;
        foreach (fetch_all($conn, "SELECT key_code, status FROM keys_free WHERE key_code IN (" . implode(',', array_keys($codes)) . ")") as $r) {
            $fkxKeyState[(string)$r['key_code']] = (int)$r['status'];
        }
    }
    if ($fkxHasLog) {
        $fkxStrikes = fetch_all($conn, "SELECT ip, MAX(ip_raw) AS ip_raw, COUNT(*) AS n, MAX(created_at) AS last_at, GROUP_CONCAT(DISTINCT event SEPARATOR ',') AS evs, GROUP_CONCAT(DISTINCT username SEPARATOR ', ') AS users FROM free_key_ip_log WHERE event LIKE 'blocked%' AND created_at >= " . (int)$fkxSince . " GROUP BY ip ORDER BY n DESC, last_at DESC LIMIT 40");
        $fkxLog = fetch_all($conn, "SELECT ip, ip_raw, username, event, created_at FROM free_key_ip_log ORDER BY id DESC LIMIT 40");
        foreach ($fkxStrikes as $r) {
            $fkxBlocks += (int)$r['n'];
            if ((int)$r['n'] >= $fkxMax) $fkxLockedIps++;
        }
    }
}
$fkxWl = count(array_filter(array_map('trim', explode(',', (string)fkx_c('FK_IP_WHITELIST', ''))), 'strlen'));
$fkxVpnOn = (bool)fkx_c('FK_BLOCK_VPN', true);
$fkxHook = fkx_hook_ok();
    $GLOBALS['FKXD'] = compact('fkxNow', 'fkxCd', 'fkxVw', 'fkxMax', 'fkxHas', 'fkxHasFlow', 'fkxHasLog', 'fkxClaims', 'fkxKeyState', 'fkxStrikes', 'fkxLog', 'fkxLockUser', 'fkxActive', 'fkxLockedIps', 'fkxBlocks', 'fkxLinkBox', 'fkxEvLbl', 'fkxWl', 'fkxVpnOn', 'fkxHook');
}

function fkx_html_load($conn) { fkx_load($conn); return ''; }

function fkx_html_linkbox($arg = null) {
    if (empty($GLOBALS['FKXD'])) return '';
    extract($GLOBALS['FKXD']);
    ob_start();
?>
  <!--FKX-LINK-BEGIN-->
  <?php if (!empty($fkxLinkBox)): ?>
  <section class="card fkx-link">
    <div class="fkx-line"><?= ic('key') ?> <b>Link mở lại key.php cho «<?= e($fkxLinkBox['user']) ?>»</b></div>
    <p class="muted-text text-xs" style="margin:4px 0 8px">Gửi riêng cho thành viên. Họ phải đăng nhập đúng tài khoản rồi mở link, trang key sẽ gắn lại vào trình duyệt và mạng mới.</p>
    <div class="mt-copy-row" style="margin-bottom:0">
      <input type="text" id="fkxLink" class="form-control" readonly value="<?= e($fkxLinkBox['url']) ?>" onfocus="this.select()">
      <button type="button" class="btn btn-ghost btn-sm" data-copy="fkxLink"><?= ic('copy') ?> Copy</button>
    </div>
  </section>
  <?php endif; ?>
  <!--FKX-LINK-END-->
<?php
    return ob_get_clean();
}

function fkx_html_nav($arg = null) {
    if (empty($GLOBALS['FKXD'])) return '';
    extract($GLOBALS['FKXD']);
    ob_start();
?>
    <a href="#fkx" data-tab="fkx" class="tab-link"><?= ic('key') ?> Get Key / Key.php<?php if ($fkxActive): ?> <span class="badge badge-bad"><?= (int)$fkxActive ?></span><?php endif; ?></a>
<?php
    return ob_get_clean();
}

function fkx_html_panel($arg = null) {
    if (empty($GLOBALS['FKXD'])) return '';
    extract($GLOBALS['FKXD']);
    ob_start();
?>
  <!-- ===== GET KEY / KEY.PHP (FKX-PANEL-BEGIN) ===== -->
  <div class="panel" id="tab-fkx" hidden>
    <section class="card">
      <div class="card-header border-b">
        <div>
          <h2 class="card-title"><?= ic('key') ?> Get Key &amp; Key.php</h2>
          <p class="muted-text text-xs mt-1">Mở khoá giới hạn của <b>get_key.php</b> (tài khoản / IP / thiết bị / VPN) và mở lại trang <b>key.php</b> cho thành viên.</p>
        </div>
        <?php if ($fkxActive || $fkxLockedIps): ?>
        <button type="button" class="btn btn-danger-outline btn-sm" data-act="fk_unlock_all" data-fields="{}" data-danger="1"
          data-title="Mở khoá tất cả get_key.php?" data-desc="Mọi tài khoản, IP và thiết bị đang bị khoá sẽ nhận key tiếp được ngay.">Mở khoá tất cả</button>
        <?php endif; ?>
      </div>
      <div class="fkx-stats">
        <div class="fkx-st"><b><?= (int)$fkxActive ?></b><span>Đang khoá nhận key</span></div>
        <div class="fkx-st"><b><?= (int)$fkxLockedIps ?></b><span>IP bị khoá tạm</span></div>
        <div class="fkx-st"><b><?= (int)$fkxBlocks ?></b><span>Lượt bị chặn</span></div>
      </div>
      <div class="fkx-chips">
        <span class="fkx-chip">Chu kỳ <b><?= e(rtrim(rtrim(number_format($fkxCd / 3600, 1, '.', ''), '0'), '.')) ?>h</b></span>
        <span class="fkx-chip">Xem key <b><?= e(rtrim(rtrim(number_format($fkxVw / 3600, 1, '.', ''), '0'), '.')) ?>h</b></span>
        <span class="fkx-chip">Chặn sau <b><?= (int)$fkxMax ?></b> lần</span>
        <span class="fkx-chip <?= $fkxVpnOn ? 'ok' : '' ?>">VPN: <b><?= $fkxVpnOn ? 'đang chặn' : 'tắt' ?></b></span>
        <span class="fkx-chip">Whitelist <b><?= (int)$fkxWl ?></b> IP</span>
        <span class="fkx-chip <?= $fkxHook ? 'ok' : 'warn' ?>">key.php hook: <b><?= $fkxHook ? 'đã cài' : 'chưa cài' ?></b></span>
      </div>
      <?php if (!$fkxHas): ?>
        <div class="empty-state">Chưa có dữ liệu get_key.php. Các bảng sẽ tự tạo khi có người vào get_key.php lần đầu.</div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="fkx-quick">
        <form method="post" class="fkx-qf">
          <?= csrf() ?>
          <input type="hidden" name="back" value="fkx">
          <label class="form-label" for="fkxUser">Mở khoá theo tài khoản</label>
          <input type="text" id="fkxUser" name="username" class="form-control" maxlength="100" placeholder="Tên tài khoản..." autocomplete="off" required>
          <div class="fkx-btns">
            <button type="submit" name="action" value="fk_unlock_full" class="btn btn-sm">Mở tất cả</button>
            <button type="submit" name="action" value="fk_unlock_user" class="btn btn-ghost btn-sm">get_key.php</button>
            <button type="submit" name="action" value="fk_view_user" class="btn btn-ghost btn-sm">key.php</button>
          </div>
        </form>
        <form method="post" class="fkx-qf">
          <?= csrf() ?>
          <input type="hidden" name="back" value="fkx">
          <input type="hidden" name="action" value="fk_unlock_ip">
          <label class="form-label" for="fkxIp">Mở khoá theo IP</label>
          <input type="text" id="fkxIp" name="ip" class="form-control" maxlength="64" placeholder="Ví dụ 113.22.10.5" autocomplete="off" required>
          <div class="fkx-btns">
            <label class="custom-chk text-xs"><input type="checkbox" name="vpn" value="1"> Cho phép cả VPN</label>
            <button type="submit" class="btn btn-sm">Gỡ khoá IP</button>
          </div>
        </form>
      </div>
    </section>

    <section class="card">
      <div class="card-header border-b">
        <div>
          <h2 class="card-title"><?= ic('users') ?> Lượt nhận key gần đây</h2>
          <p class="muted-text text-xs mt-1"><b><?= count($fkxClaims) ?></b> lượt mới nhất &bull; <b>Mở khoá</b> cho nhận key tiếp ngay, <b>Mở key.php</b> cho xem lại trang key</p>
        </div>
      </div>
      <div class="toolbar">
        <div class="search-input-wrap"><?= ic('search') ?>
          <input type="search" id="fkxQ" class="search-input" placeholder="Tìm tài khoản, IP hoặc key..." autocomplete="off"></div>
      </div>
      <div>
        <?php if (!$fkxClaims): ?>
          <div class="empty-state">Chưa có lượt nhận key nào.</div>
        <?php else: foreach ($fkxClaims as $fc):
            $fcLockUntil = (int)$fc['created_at'] + $fkxCd;
            $fcLocked = $fcLockUntil > $fkxNow;
            $fcVer = (int)($fc['verified_at'] ?? 0);
            $fcViewUntil = $fcVer + $fkxVw;
            $fcViewOk = $fcVer > 0 && $fcViewUntil > $fkxNow;
            $fcTok = !empty($fc['view_token']);
            $fcPending = isset($fc['sid_hash']) && $fc['sid_hash'] === fkx_zero();
            $fcKey = (string)$fc['key_code'];
            $fcTurn = preg_match('/-\d+turns$/', $fcKey, $fcM) ? $fcM[0] : '';
            $fcShort = strlen($fcKey) > 20 ? substr($fcKey, 0, 10) . '…' . $fcTurn : $fcKey;
            $fcUsed = isset($fkxKeyState[$fcKey]) ? (int)$fkxKeyState[$fcKey] : -1;
            $fcDev = !empty($fc['device_id']) ? substr((string)$fc['device_id'], 0, 6) : '';
        ?>
          <div class="fkx-row" data-q="<?= e(strtolower($fc['username'] . ' ' . $fc['ip'] . ' ' . $fcKey)) ?>">
            <div class="flex-1">
              <div class="fkx-line">
                <span class="font-semibold" style="font-size:13px"><?= e($fc['username']) ?></span>
                <?php if ($fcLocked): ?>
                  <span class="badge badge-bad fkx-left" data-until="<?= $fcLockUntil ?>" data-pre="Khoá " data-zero="Hết khoá"><?= e('Khoá ' . fkx_dur($fcLockUntil - $fkxNow)) ?></span>
                <?php else: ?>
                  <span class="badge badge-neutral">Hết khoá</span>
                <?php endif; ?>
                <?php if ($fcUsed === 1): ?><span class="badge badge-neutral">Key đã dùng</span>
                <?php elseif ($fcUsed === 0): ?><span class="badge badge-ok">Key chưa dùng</span><?php endif; ?>
                <?php if ($fcPending): ?>
                  <span class="badge badge-vip">Chờ mở key.php</span>
                <?php elseif ($fcViewOk): ?>
                  <span class="badge badge-vip fkx-left" data-until="<?= $fcViewUntil ?>" data-pre="Xem " data-zero="Hết hạn xem"><?= e('Xem ' . fkx_dur($fcViewUntil - $fkxNow)) ?></span>
                <?php elseif ($fcTok): ?>
                  <span class="badge badge-neutral">Hết hạn xem</span>
                <?php endif; ?>
              </div>
              <div class="muted-text text-xs mt-1 fkx-meta"><?= e(date('d/m H:i', (int)$fc['created_at'])) ?> &bull; IP <?= e($fc['ip']) ?><?php if ($fcDev !== ''): ?> &bull; máy <?= e($fcDev) ?><?php endif; ?> &bull; <code title="<?= e($fcKey) ?>"><?= e($fcShort) ?></code></div>
            </div>
            <div class="fkx-acts">
              <?php if ($fcLocked): ?>
                <button type="button" class="btn btn-ghost btn-xs" data-act="fk_unlock_claim" data-fields="<?= e(json_encode(['id' => (int)$fc['id']])) ?>" data-danger="0"
                  data-title="Mở khoá get_key.php cho <?= e($fc['username']) ?>?" data-desc="Tài khoản, IP và thiết bị của lượt này nhận key tiếp được ngay, không cần chờ hết chu kỳ.">Mở khoá</button>
              <?php endif; ?>
              <?php if ($fcTok): ?>
                <button type="button" class="btn btn-ghost btn-xs" data-act="fk_view_open" data-fields="<?= e(json_encode(['id' => (int)$fc['id']])) ?>" data-danger="0"
                  data-title="Mở lại key.php cho <?= e($fc['username']) ?>?" data-desc="Gia hạn thời gian xem và tạo link để thành viên xem lại key trên trình duyệt hoặc mạng khác.">Mở key.php</button>
              <?php endif; ?>
              <?php if ($fcPending && $fcTok): ?>
                <button type="button" class="btn btn-ghost btn-xs" data-copytext="<?= e(fkx_key_url($fc['username'], $fc['view_token'])) ?>"><?= ic('copy') ?> Link</button>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
      <div class="empty-state" id="fkxEmpty" hidden>Không có mục nào khớp từ khoá.</div>
    </section>

    <?php if ($fkxHasLog): ?>
    <section class="card">
      <div class="card-header border-b">
        <div>
          <h2 class="card-title"><?= ic('alert') ?> IP bị chặn trong chu kỳ</h2>
          <p class="muted-text text-xs mt-1">Bị chặn <b><?= (int)$fkxMax ?></b> lần thì IP bị khoá tạm đến hết chu kỳ.</p>
        </div>
      </div>
      <?php if (!$fkxStrikes): ?>
        <div class="empty-state">Không có IP nào bị chặn.</div>
      <?php else: foreach ($fkxStrikes as $fs):
          $fsEv = [];
          foreach (explode(',', (string)$fs['evs']) as $fe) { if ($fe !== '') $fsEv[] = isset($fkxEvLbl[$fe]) ? $fkxEvLbl[$fe] : $fe; }
          $fsVpn = (strpos((string)$fs['evs'], 'blocked_vpn') !== false);
          $fsRaw = (string)($fs['ip_raw'] !== '' && $fs['ip_raw'] !== null ? $fs['ip_raw'] : $fs['ip']);
      ?>
        <div class="fkx-row">
          <div class="flex-1">
            <div class="fkx-line">
              <span class="font-semibold" style="font-size:13px"><?= e($fsRaw) ?></span>
              <span class="badge <?= ((int)$fs['n'] >= $fkxMax) ? 'badge-bad' : 'badge-neutral' ?>"><?= (int)$fs['n'] ?>/<?= (int)$fkxMax ?> lần</span>
              <?php foreach ($fsEv as $fe): ?><span class="badge badge-neutral"><?= e($fe) ?></span><?php endforeach; ?>
            </div>
            <div class="muted-text text-xs mt-1">Gần nhất <?= e(date('d/m H:i', (int)$fs['last_at'])) ?><?php if (trim((string)$fs['users']) !== ''): ?> &bull; <?= e($fs['users']) ?><?php endif; ?></div>
          </div>
          <div class="fkx-acts">
            <button type="button" class="btn btn-ghost btn-xs" data-act="fk_unlock_ip" data-fields="<?= e(json_encode(['ip' => $fsRaw, 'vpn' => $fsVpn ? 1 : 0, 'back' => 'fkx'])) ?>" data-danger="0"
              data-title="Gỡ chặn IP <?= e($fsRaw) ?>?" data-desc="<?= $fsVpn ? 'IP này bị chặn vì VPN / proxy: sẽ được cho phép qua bộ lọc VPN 30 ngày.' : 'Xoá các lần bị chặn của IP này để nhận key lại được.' ?>">Gỡ chặn</button>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </section>

    <details class="adv-group">
      <summary>Nhật ký get key (<?= count($fkxLog) ?> sự kiện gần nhất)</summary>
      <?php if (!$fkxLog): ?>
        <div class="empty-state">Chưa có nhật ký.</div>
      <?php else: foreach ($fkxLog as $fl):
          $flEv = (string)$fl['event'];
          $flBad = (strpos($flEv, 'blocked') === 0);
          $flLbl = isset($fkxEvLbl[$flEv]) ? $fkxEvLbl[$flEv] : (strpos($flEv, 'cleared') === 0 ? 'đã gỡ chặn' : $flEv);
      ?>
        <div class="log-row">
          <span class="badge <?= $flBad ? 'badge-bad' : (strpos($flEv, 'claim') === 0 ? 'badge-ok' : 'badge-neutral') ?>"><?= e($flLbl) ?></span>
          <div class="flex-1">
            <div class="font-semibold" style="font-size:12.5px"><?= e($fl['username'] !== '' ? $fl['username'] : '(khách)') ?></div>
            <div class="log-meta"><?= e(date('d/m/Y H:i:s', (int)$fl['created_at'])) ?> &bull; IP <?= e($fl['ip_raw'] !== '' ? $fl['ip_raw'] : $fl['ip']) ?></div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </details>
    <?php endif; ?>
  </div>
  <!-- ===== /FKX-PANEL ===== -->
<?php
    return ob_get_clean();
}

function fkx_html_memdlg($arg = null) {
    if (empty($GLOBALS['FKXD'])) return '';
    extract($GLOBALS['FKXD']);
    ob_start();
?>
    <!--FKX-MEM-BEGIN-->
    <form method="post" class="admx-mf">
      <?= csrf() ?>
      <input type="hidden" name="id" class="js-memid">
      <input type="hidden" name="back" value="members">
      <label class="form-label">Mở khoá nhanh</label>
      <div class="fkx-btns">
        <button type="submit" name="action" value="fk_unlock_full" class="btn btn-sm">Mở tất cả</button>
        <button type="submit" name="action" value="fk_unlock_user" class="btn btn-ghost btn-sm">get_key.php</button>
        <button type="submit" name="action" value="fk_view_user" class="btn btn-ghost btn-sm">key.php</button>
      </div>
      <p class="form-hint" style="margin-top:6px">Mở tất cả = nhập key (theme) + get_key.php + key.php.</p>
    </form>
    <form method="post" class="admx-mf">
      <?= csrf() ?>
      <input type="hidden" name="action" value="member_grant">
      <input type="hidden" name="id" class="js-memid">
      <label class="form-label">Cấp key trực tiếp (tính từ bây giờ)</label>
      <div class="admx-mrow">
        <select name="ktype" class="form-control" style="max-width:96px"><option value="FREE">FREE</option><option value="VIP">VIP</option></select>
        <input type="number" name="hours" class="form-control" min="1" max="87600" placeholder="Số giờ" required>
        <button type="submit" class="btn btn-sm">Cấp</button>
      </div>
    </form>
    <!--FKX-MEM-END-->
<?php
    return ob_get_clean();
}

function fkx_html_memrow($m) {
    if (empty($GLOBALS['FKXD'])) return '';
    extract($GLOBALS['FKXD']);
    ob_start();
?>
              <?php $fkxMU = isset($fkxLockUser[strtolower((string)$m['username'])]) ? (int)$fkxLockUser[strtolower((string)$m['username'])] : 0; if ($fkxMU > $fkxNow): ?>
                <span class="badge badge-bad" title="Đang khoá nhận key ở get_key.php">Khoá get_key</span>
                <button type="button" class="btn btn-ghost btn-xs" data-act="fk_unlock_user" data-fields="<?= e(json_encode(['id' => (int)$m['id'], 'back' => 'members'])) ?>" data-danger="0"
                  data-title="Mở khoá get_key.php cho <?= e($m['username']) ?>?" data-desc="Tài khoản này nhận key tiếp được ngay, không cần chờ hết chu kỳ.">Mở get_key</button>
              <?php endif; ?>
<?php
    return ob_get_clean();
}
