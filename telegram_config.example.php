<?php
/**
 * telegram_config.example.php — Mẫu cấu hình Telegram Bot quản trị
 * Sao chép tệp này thành `telegram_config.php` trên máy chủ và điền thông tin thực tế.
 */

return [
    'bot_token'         => '1234567890:REPLACE_WITH_YOUR_TELEGRAM_BOT_TOKEN',
    'admin_ids'         => [123456789],

    'tag_user_id'       => '123456789',
    'tag_name'          => 'your_telegram_username',
    'contact_url'       => 'https://t.me/your_telegram_username',

    'webhook_secret'    => 'REPLACE_WITH_RANDOM_WEBHOOK_SECRET_HEX',
    'setup_key'         => 'REPLACE_WITH_RANDOM_SETUP_KEY_HEX',

    'site_name'         => 'DEVNVIOS',
    'webhook_url'       => '',
    'allow_destructive' => false,

    'timezone'          => 'Asia/Ho_Chi_Minh',
    'scan_interval'     => 10,
    'api_base'          => 'https://api.telegram.org',

    'emoji_enabled'     => true,
    'emoji_pack'        => '',
    'emoji_ids'         => [],
    'emoji_map'         => [],
    'button_colors'     => true,
];
