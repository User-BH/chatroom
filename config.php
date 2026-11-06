<?php
/**
 * ===================================================
 * ChatRoom - تنظیمات اصلی
 * ===================================================
 * نسخه: 2.0
 * سازگار با: PHP 7.4+
 */

// جلوگیری از دسترسی مستقیم
if (basename($_SERVER['PHP_SELF']) === 'config.php') {
    http_response_code(403);
    exit('Access Denied');
}

// ===================================================
// تنظیمات دیتابیس
// ===================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'chatroom_secure');
define('DB_USER', 'root');
define('DB_PASS', ''); // در محیط production تغییر دهید
define('DB_CHARSET', 'utf8mb4');

// ===================================================
// تنظیمات Session
// ===================================================
define('SESSION_NAME', 'CHATROOM_SESS');
define('SESSION_LIFETIME', 3600); // یک ساعت

// ===================================================
// تنظیمات CSRF
// ===================================================
define('CSRF_TOKEN_NAME', 'csrf_token');
define('CSRF_TOKEN_EXPIRY', 3600); // یک ساعت

// ===================================================
// تنظیمات ورود و امنیت
// ===================================================
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 دقیقه
define('LOCKOUT_DURATION', 900); // 15 دقیقه (برای سازگاری)
define('PASSWORD_MIN_LENGTH', 8);

// ===================================================
// تنظیمات آنلاین بودن
// ===================================================
define('ONLINE_TIMEOUT', 300); // 5 دقیقه - کاربر بعد از این زمان آفلاین محسوب می‌شود
define('HEARTBEAT_INTERVAL', 30); // هر 30 ثانیه وضعیت به‌روز شود

// ===================================================
// تنظیمات آپلود
// ===================================================
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10 مگابایت

// ===================================================
// تنظیمات پیام
// ===================================================
define('MAX_MESSAGE_LENGTH', 5000);
define('MESSAGES_PER_PAGE', 50);

// ===================================================
// تنظیمات سایت
// ===================================================
define('SITE_NAME', 'چت روم امن');
define('SITE_URL', 'http://localhost/chatroom/');
define('TIMEZONE', 'Asia/Tehran');

// ===================================================
// تنظیمات محیط
// ===================================================
define('DEBUG_MODE', true); // در production به false تغییر دهید
define('LOG_ERRORS', true);
define('ERROR_LOG_FILE', __DIR__ . '/logs/error.log');

// ===================================================
// نوع فایل‌های مجاز
// ===================================================
define('ALLOWED_IMAGE_TYPES_STR', 'image/jpeg,image/png,image/gif,image/webp');
define('ALLOWED_FILE_TYPES_STR', 'application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document');
define('ALLOWED_AUDIO_TYPES_STR', 'audio/mpeg,audio/wav,audio/ogg,audio/webm');

// ===================================================
// تنظیم timezone
// ===================================================
date_default_timezone_set(TIMEZONE);

// ===================================================
// نمایش خطاها
// ===================================================
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// ===================================================
// لاگ کردن خطاها
// ===================================================
if (LOG_ERRORS) {
    ini_set('log_errors', '1');
    ini_set('error_log', ERROR_LOG_FILE);
}

// ===================================================
// توابع کمکی برای نوع فایل‌ها (PHP 7.4 compatible)
// ===================================================
function getAllowedImageTypes() {
    return explode(',', ALLOWED_IMAGE_TYPES_STR);
}

function getAllowedFileTypes() {
    return explode(',', ALLOWED_FILE_TYPES_STR);
}

function getAllowedAudioTypes() {
    return explode(',', ALLOWED_AUDIO_TYPES_STR);
}
