<?php
/**
 * نمونه تنظیمات محلی
 *
 * این فایل را به config.local.php کپی کنید و مقادیر را تغییر دهید.
 * config.local.php نباید وارد مخزن git شود.
 *
 * روی سرور بهتر است فایل بیرون از webroot باشد و مسیرش با متغیر محیطی
 * CHATROOM_CONFIG_FILE به PHP-FPM داده شود.
 */
return [
    // کاربر اختصاصی دیتابیس؛ هرگز از root استفاده نکنید
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'chatroom_secure',
    'DB_USER' => 'chatroom',
    'DB_PASS' => 'CHANGE_ME',

    // فقط روی سیستم توسعه true شود
    'DEBUG_MODE' => false,

    // مسیر ذخیره فایل‌ها؛ بهتر است بیرون از webroot باشد
    // 'UPLOAD_DIR' => '/var/lib/chatroom/uploads',
    // 'ERROR_LOG_FILE' => '/var/log/chatroom/error.log',

    // اگر برنامه پشت Nginx/HAProxy دیگری است، IP همان پروکسی را بنویسید
    // 'TRUSTED_PROXIES' => '10.0.0.5',

    // فقط اگر تمدید گواهی HTTPS تضمین شده است
    // 'ENABLE_HSTS' => true,
];
