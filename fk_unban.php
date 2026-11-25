<?php
// Unban tool for the free key system. Run from the VPS shell (Termius) only:
//   php fk_unban.php list
//   php fk_unban.php ip 1.2.3.4
//   php fk_unban.php user TENTAIKHOAN
//   php fk_unban.php device <fk_did>
//   php fk_unban.php all
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('FK_LIB_ONLY', true);
require __DIR__ . '/get_key.php';   // loads db.php + helpers, runs nothing else

function out($t) { echo $t . PHP_EOL; }
function qa($conn, $sql, $types = '', $params = array()) {
    $st = mysqli_prepare($conn, $sql);
    if (!$st) { out('SQL error: ' . mysqli_error($conn)); return 0; }
    if ($types !== '') mysqli_stmt_bind_param($st, $types, ...$params);
    mysqli_stmt_execute($st);
    $n = mysqli_stmt_affected_rows($st);
    mysqli_stmt_close($st);
    return max(0, $n);
}

fk_install($conn);
$cmd = isset($argv[1]) ? strtolower($argv[1]) : '';
$arg = isset($argv[2]) ? trim($argv[2]) : '';
$now = time();

if ($cmd === 'list') {
    $r = mysqli_query($conn, "SELECT username, ip, device_id, created_at FROM free_key_claims WHERE created_at > " . ($now - FK_COOLDOWN) . " ORDER BY id DESC LIMIT 50");
    out("Active locks (last " . fk_cd_label() . "):");
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $left = max(0, $row['created_at'] + FK_COOLDOWN - $now);
        out(sprintf("  %-20s %-24s reopens in %dh %dm", $row['username'], $row['ip'], floor($left / 3600), floor(($left % 3600) / 60)));
    }
    $r = mysqli_query($conn, "SELECT ip, COUNT(*) n FROM free_key_ip_log WHERE event LIKE 'blocked%' AND created_at >= " . ($now - FK_COOLDOWN) . " GROUP BY ip ORDER BY n DESC LIMIT 20");
    out("Blocked attempts:");
    while ($r && ($row = mysqli_fetch_assoc($r))) { out(sprintf("  %-28s %d strikes", $row['ip'], $row['n'])); }
    exit(0);
}

if ($cmd === 'ip' && $arg !== '') {
    if (!filter_var($arg, FILTER_VALIDATE_IP)) { out('Invalid IP.'); exit(1); }
    $k = fk_ip_key($arg);
    $a = qa($conn, "DELETE FROM free_key_claims WHERE ip = ?", 's', array($k));
    $b = qa($conn, "DELETE FROM free_key_ip_log WHERE ip = ? OR ip_raw = ?", 'ss', array($k, $arg));
    $c = qa($conn, "DELETE FROM free_key_ip_cache WHERE ip = ?", 's', array($arg));
    out("Unbanned $arg (lock key: $k) - claims: $a, log rows: $b, vpn cache: $c");
} elseif ($cmd === 'user' && $arg !== '') {
    $a = qa($conn, "DELETE FROM free_key_claims WHERE username = ?", 's', array($arg));
    $b = qa($conn, "DELETE FROM free_key_flow WHERE username = ?", 's', array($arg));
    $c = qa($conn, "DELETE FROM free_key_ip_log WHERE username = ? AND event LIKE 'blocked%'", 's', array($arg));
    out("Unbanned account $arg - claims: $a, sessions: $b, strikes: $c");
} elseif ($cmd === 'device' && $arg !== '') {
    $a = qa($conn, "DELETE FROM free_key_claims WHERE device_id = ?", 's', array($arg));
    $b = qa($conn, "DELETE FROM free_key_ip_log WHERE device_id = ? AND event LIKE 'blocked%'", 's', array($arg));
    out("Unbanned device $arg - claims: $a, strikes: $b");
} elseif ($cmd === 'all') {
    $a = qa($conn, "DELETE FROM free_key_claims");
    $b = qa($conn, "DELETE FROM free_key_flow");
    $c = qa($conn, "DELETE FROM free_key_ip_log WHERE event LIKE 'blocked%'");
    $d = qa($conn, "DELETE FROM free_key_ip_cache");
    out("Unbanned everyone - claims: $a, sessions: $b, strikes: $c, vpn cache: $d");
} else {
    out("Usage:\n  php fk_unban.php list\n  php fk_unban.php ip <ip>\n  php fk_unban.php user <username>\n  php fk_unban.php device <id>\n  php fk_unban.php all");
}
