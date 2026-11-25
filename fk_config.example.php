<?php
/**
 * fk_config.example.php — Mẫu cấu hình Free Key & Link Shortener
 * Sao chép tệp này thành `fk_config.php` trên máy chủ và điền khóa thực tế.
 */

define('FK_SECRET',          'REPLACE_WITH_LONG_RANDOM_SECRET_STRING');
define('FK_SHORTENER_API',   'https://link4m.co/api-shorten/v2?api=YOUR_API_TOKEN_HERE&url={url}');
define('FK_SHORT_HOST',      '');

define('FK_MIN_WAIT',        25);
define('FK_FLOW_TTL',        900);
define('FK_VIEW_WINDOW',     600);
define('FK_KEY_TURNS',       1);

define('FK_TRUST_CF',        true);
define('FK_TRUSTED_PROXIES', '');

define('FK_BLOCK_VPN',       true);
define('FK_VPN_API',         'http://ip-api.com/json/{ip}?fields=status,proxy,hosting');
define('FK_VPN_FAIL_OPEN',   true);
define('FK_VPN_CACHE_TTL',   86400);

define('FK_MAX_STRIKES',     5);
define('FK_IP_WHITELIST',    '');
define('FK_LOG_KEEP_DAYS',   90);
