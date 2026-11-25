<?php
/**
 * config.example.php — Mẫu cấu hình bảo mật cho iosmods.xyz (DEVNVIOS)
 * Sao chép tệp này thành `config.php` trên máy chủ và điền thông tin thực tế.
 * TUYỆT ĐỐI KHÔNG đưa `config.php` lên GitHub.
 */

// 1. Kết nối Cơ sở dữ liệu MySQL
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_username');
define('DB_PASS', 'your_db_password_here');
define('DB_NAME', 'your_db_name');

// 2. Cloudflare Turnstile CAPTCHA
define('TURNSTILE_SITEKEY', 'YOUR_TURNSTILE_SITE_KEY_HERE');
define('TURNSTILE_SECRET',  'YOUR_TURNSTILE_SECRET_KEY_HERE');

// 3. Đăng nhập Google OAuth (để trống nếu tắt nút Google)
define('GOOGLE_CLIENT_ID',  'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com');

// 4. Cấu hình gửi mã xác minh qua Gmail SMTP
define('DS_SMTP_HOST',   'smtp.gmail.com');
define('DS_SMTP_PORT',   465);
define('DS_SMTP_SECURE', 'ssl');
define('DS_SMTP_USER',   'your_email@example.com');
define('DS_SMTP_PASS',   'YOUR_16_CHAR_APP_PASSWORD');
define('DS_MAIL_FROM',   'your_email@example.com');
define('DS_MAIL_NAME',   'iosmods.xyz');
define('DS_MAIL_DEBUG',  false);
