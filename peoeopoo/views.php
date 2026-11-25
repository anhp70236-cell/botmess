<?php
/* views.php - dem luot xem that: moi dia chi IP chi tinh 1 lan, luu vinh vien */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const BASE = 2369;                 /* so luot xem ban dau */
const DATA = '/var/www/peoeopoo-data';
const MAX_NEW_PER_MIN = 20;            /* toi da luot MOI moi phut (chan buff hang loat) */
const CF_RANGES = [
  '173.245.48.0/20','103.21.244.0/22','103.22.200.0/22','103.31.4.0/22','141.101.64.0/18',
  '108.162.192.0/18','190.93.240.0/20','188.114.96.0/20','197.234.240.0/22','198.41.128.0/17',
  '162.158.0.0/15','104.16.0.0/13','104.24.0.0/14','172.64.0.0/13','131.0.72.0/22',
  '2400:cb00::/32','2606:4700::/32','2803:f800::/32','2405:b500::/32','2405:8100::/32',
  '2a06:98c0::/29','2c0f:f248::/32'
];

function out($a){ echo json_encode($a); exit; }

function in_cidr($ip, $cidr){
  list($net, $bits) = explode('/', $cidr);
  $a = @inet_pton($ip); $b = @inet_pton($net);
  if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
  $bits = (int)$bits; $bytes = $bits >> 3; $rem = $bits % 8;
  if ($bytes && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) return false;
  if ($rem){
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    if ((ord($a[$bytes]) & $mask) !== (ord($b[$bytes]) & $mask)) return false;
  }
  return true;
}
function is_private_ip($ip){
  return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}
/* lay IP that cua khach; chi tin header proxy khi ket noi den tu Cloudflare / proxy noi bo */
function client_ip(){
  $ra = $_SERVER['REMOTE_ADDR'] ?? '';
  $trusted = is_private_ip($ra);
  if (!$trusted){ foreach (CF_RANGES as $c){ if (in_cidr($ra, $c)){ $trusted = true; break; } } }
  if ($trusted){
    $cf = trim($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) return $cf;
    $xf = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xf !== ''){
      $arr = array_map('trim', explode(',', $xf));
      $last = end($arr);
      if ($last !== false && filter_var($last, FILTER_VALIDATE_IP)) return $last;
    }
  }
  return $ra;
}
/* IPv4: nguyen dia chi. IPv6: gom theo /64 (dien thoai doi duoi dia chi van tinh la 1) */
function ip_key($ip){
  $bin = @inet_pton($ip);
  if ($bin === false) return '';
  if (strlen($bin) === 16){
    if (substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") return 'v4:' . inet_ntop(substr($bin, 12));
    return 'v6:' . bin2hex(substr($bin, 0, 8));
  }
  return 'v4:' . $ip;
}
function secret(){
  $f = DATA . '/secret.key';
  $s = @file_get_contents($f);
  if ($s === false || strlen(trim($s)) < 32){
    $s = bin2hex(random_bytes(32));
    @file_put_contents($f, $s, LOCK_EX);
    @chmod($f, 0600);
  }
  return trim($s);
}
function read_count(){
  $v = @file_get_contents(DATA . '/count.txt');
  $n = ($v === false) ? BASE : (int)trim($v);
  return max($n, BASE);
}
function done($count, $counted, $why){
  $r = ['ok' => true, 'count' => $count, 'counted' => $counted];
  if ($why !== '') $r['why'] = $why;
  out($r);
}

if (!is_dir(DATA) || !is_writable(DATA)){
  http_response_code(500);
  out(['ok' => false, 'err' => 'storage']);
}
$secret = secret();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* GET: chi tra so luot xem + the (token), KHONG tinh them */
if ($method !== 'POST'){
  $ts = time();
  out(['ok' => true, 'count' => read_count(), 'tok' => $ts . '.' . substr(hash_hmac('sha256', (string)$ts, $secret), 0, 32)]);
}

/* POST: chi tinh khi khach that bam Got it */
$cnt = read_count();
$tok = (string)($_POST['tok'] ?? '');
$parts = explode('.', $tok, 2);
if (count($parts) !== 2 || !ctype_digit($parts[0])) done($cnt, false, 'token');
$exp = substr(hash_hmac('sha256', $parts[0], $secret), 0, 32);
if (!hash_equals($exp, $parts[1])) done($cnt, false, 'token');
$age = time() - (int)$parts[0];
if ($age < 1 || $age > 86400) done($cnt, false, 'token');

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|curl|wget|python|java\/|go-http|axios|node-fetch|headless|phantom|lighthouse|monitor|scrap|httpclient|libwww/i', $ua)) done($cnt, false, 'bot');
$sfs = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
if ($sfs !== '' && $sfs !== 'same-origin') done($cnt, false, 'origin');

$key = ip_key(client_ip());
if ($key === '') done($cnt, false, 'ip');
$h = hash_hmac('sha256', $key, $secret);

$lock = fopen(DATA . '/lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) done($cnt, false, 'lock');

$file = DATA . '/seen.log';
$lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!is_array($lines)) $lines = [];
$seen = []; $recent = 0; $now = time();
foreach ($lines as $ln){
  $p = explode(' ', $ln);
  $seen[$p[0]] = 1;
  if (isset($p[1]) && (int)$p[1] >= $now - 60) $recent++;
}
if (isset($seen[$h])){
  flock($lock, LOCK_UN);
  done(BASE + count($lines), false, 'seen');       /* IP nay da tinh roi */
}
if ($recent >= MAX_NEW_PER_MIN){
  flock($lock, LOCK_UN);
  done(BASE + count($lines), false, 'busy');       /* qua nhieu luot moi trong 1 phut */
}
file_put_contents($file, $h . ' ' . $now . "\n", FILE_APPEND);
$c = BASE + count($lines) + 1;
file_put_contents(DATA . '/count.txt', (string)$c, LOCK_EX);
flock($lock, LOCK_UN);
done($c, true, '');
