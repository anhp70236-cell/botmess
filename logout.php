<?php
if (is_file(__DIR__ . '/security_shield.php')) {
    require_once __DIR__ . '/security_shield.php';
    sh_early_guard();
}
session_start();
session_unset(); // Xoá tất cả biến session
session_destroy(); // Huỷ session
header("Location: index.php"); // Chuyển hướng về trang chủ
exit();
?>
	