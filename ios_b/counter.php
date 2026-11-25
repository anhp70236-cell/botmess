<?php /* v20 wrapper: POST = count a new device (counter_core.php); ?peek=1 = only read the last number */
$cache = __DIR__ . '/views_last.json';
if (isset($_GET['peek'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $c = @file_get_contents($cache);
    echo ($c !== false && trim($c) !== '') ? $c : '{"views":null}';
    exit;
}
ob_start(function ($buf) use ($cache) {
    $j = json_decode($buf, true);
    if (is_array($j) && isset($j['views']) && is_numeric($j['views'])) {
        @file_put_contents($cache, json_encode(['views' => (int)$j['views']]), LOCK_EX);
    }
    return $buf;
});
require __DIR__ . '/counter_core.php';
