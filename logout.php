<?php
session_start();
session_unset(); // Xoá tất cả biến session
session_destroy(); // Huỷ session
header("Location: index.php"); // Chuyển hướng về trang chủ
exit();
?>
	