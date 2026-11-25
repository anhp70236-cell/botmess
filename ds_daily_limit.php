<?php
/* ds_daily_limit.php - gioi han 1 key / ngay cho moi TAI KHOAN, moi DIA CHI IP va moi THIET BI (cookie ds_dev).
   Do patch_v25.php tao ra. Du lieu luu o ds_daily_data.php (bat dau bang exit nen khong ai tai ve / doc duoc qua web).

   CACH DUNG (trong code nhap key, tren server):
       require_once __DIR__ . '/ds_daily_limit.php';
       $g = ds_daily_claim($userId);              // goi TRUOC khi cong luot cho key
       if (!$g['ok']) { /* hien $g['message'] bang thong bao loi (dau X) o phan Keys, roi dung lai */ }
       ...kiem tra key...  neu key sai / cong luot that bai => ds_daily_refund($userId);
   Admin cong key trong trang admin: KHONG goi ham nay (khong bi gioi han).
   Tuy chon: define('DS_DAILY_LIMIT', 1);  define('DS_TRUST_PROXY_HEADER', true); (chi bat khi web nam sau Cloudflare / proxy) */
if (!defined('DS_DAILY_LIMIT')) { define('DS_DAILY_LIMIT', 1); }

function ds_dl_file(): string { return __DIR__ . DIRECTORY_SEPARATOR . 'ds_daily_data.php'; }

function ds_dl_day(int $offsetDays = 0): string
{
    try { $d = new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')); } catch (Exception $e) { $d = new DateTime('now'); }
    if ($offsetDays !== 0) { $d->modify(($offsetDays > 0 ? '+' : '') . $offsetDays . ' day'); }
    return $d->format('Y-m-d');
}

function ds_dl_ip(): string
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    if (defined('DS_TRUST_PROXY_HEADER') && DS_TRUST_PROXY_HEADER) {
        foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR') as $h) {
            if (!empty($_SERVER[$h])) {
                $parts = explode(',', (string) $_SERVER[$h]);
                $c = trim($parts[0]);
                if (filter_var($c, FILTER_VALIDATE_IP)) { $ip = $c; break; }
            }
        }
    }
    if (strpos($ip, ':') !== false) {
        $bin = @inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16) {
            if (substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") { return (string) inet_ntop(substr($bin, 12)); }
            return 'v6:' . bin2hex(substr($bin, 0, 8)); /* IPv6: tinh theo /64 de khong lach bang cach doi duoi dia chi */
        }
    }
    return $ip;
}

function ds_dl_device(): string
{
    $id = isset($_COOKIE['ds_dev']) ? (string) $_COOKIE['ds_dev'] : '';
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        $id = bin2hex(random_bytes(16));
        if (!headers_sent()) {
            setcookie('ds_dev', $id, array('expires' => time() + 86400 * 730, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax'));
        }
        $_COOKIE['ds_dev'] = $id;
    }
    return $id;
}

function ds_dl_update(callable $fn)
{
    $fp = @fopen(ds_dl_file(), 'c+');
    if (!$fp) { return null; }
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = array();
    if ($raw !== false) {
        $p = strpos($raw, "\n");
        if ($p !== false) {
            $j = json_decode(substr($raw, $p + 1), true);
            if (is_array($j)) { $data = $j; }
        }
    }
    $res = $fn($data);
    $out = "<?php http_response_code(404); exit; ?>\n" . json_encode($data);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $out);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $res;
}

function ds_dl_ids($userKey): array
{
    return array('u:' . strtolower(trim((string) $userKey)), 'i:' . ds_dl_ip(), 'd:' . ds_dl_device());
}

/** Giu cho 1 luot nhap key trong hom nay (kiem tra + ghi cung luc, an toan khi 2 yeu cau den cung luc).
    $bypass = true (admin) => luon cho qua, khong tinh. Tra ve ['ok' => bool, 'message' => string]. */
function ds_daily_claim($userKey, bool $bypass = false): array
{
    if ($bypass) { return array('ok' => true, 'message' => ''); }
    $limit = max(1, (int) DS_DAILY_LIMIT);
    $ids = ds_dl_ids($userKey);
    $day = ds_dl_day();
    $keep = ds_dl_day(-1);
    $r = ds_dl_update(function (&$data) use ($ids, $limit, $day, $keep) {
        foreach (array_keys($data) as $k) { if ((string) $k < $keep) { unset($data[$k]); } }
        if (!isset($data[$day]) || !is_array($data[$day])) { $data[$day] = array(); }
        foreach ($ids as $id) {
            if ((isset($data[$day][$id]) ? (int) $data[$day][$id] : 0) >= $limit) { return false; }
        }
        foreach ($ids as $id) { $data[$day][$id] = (isset($data[$day][$id]) ? (int) $data[$day][$id] : 0) + 1; }
        return true;
    });
    if ($r === null) {
        return array('ok' => false, 'message' => 'Could not verify the daily key limit. Please try again later.');
    }
    if ($r) { return array('ok' => true, 'message' => ''); }
    return array('ok' => false, 'message' => 'Daily key limit reached: ' . $limit . ' key per day for each account, device and IP. Come back tomorrow.');
}

/** Tra lai luot da giu (key sai, cong luot that bai...). */
function ds_daily_refund($userKey): void
{
    $ids = ds_dl_ids($userKey);
    $day = ds_dl_day();
    ds_dl_update(function (&$data) use ($ids, $day) {
        foreach ($ids as $id) {
            if (isset($data[$day][$id]) && (int) $data[$day][$id] > 0) { $data[$day][$id] = (int) $data[$day][$id] - 1; }
        }
        return true;
    });
}