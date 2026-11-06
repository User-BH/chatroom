<?php
/**
 * ===================================================
 * ChatRoom - تنظیمات اصلی
 * ===================================================
 * نسخه: 2.1
 * سازگار با: PHP 8.5
 *
 * مقادیر محرمانه (رمز دیتابیس و ...) نباید در این فایل نوشته شوند.
 * ترتیب اولویت خواندن هر تنظیم:
 *   1) متغیر محیطی با پیشوند CHATROOM_ (مثل CHATROOM_DB_PASS)
 *   2) فایل config.local.php (نمونه: config.local.example.php)
 *   3) مقدار پیش‌فرض همین فایل
 */

// جلوگیری از دسترسی مستقیم
if (basename($_SERVER['PHP_SELF'] ?? '') === 'config.php') {
    http_response_code(403);
    exit('Access Denied');
}

$chatroomLocalConfigFile = getenv('CHATROOM_CONFIG_FILE') ?: __DIR__ . '/config.local.php';
$GLOBALS['chatroomLocalConfig'] = is_file($chatroomLocalConfigFile) ? (array)require $chatroomLocalConfigFile : [];

/**
 * خواندن یک تنظیم از محیط، فایل محلی یا مقدار پیش‌فرض
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function chatroomConfig($key, $default = null) {
    $env = getenv('CHATROOM_' . $key);
    if ($env !== false) {
        if (is_bool($default)) {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }
        return is_int($default) ? (int)$env : $env;
    }
    return array_key_exists($key, $GLOBALS['chatroomLocalConfig'])
        ? $GLOBALS['chatroomLocalConfig'][$key]
        : $default;
}

// ===================================================
// تنظیمات دیتابیس
// ===================================================
define('DB_HOST', chatroomConfig('DB_HOST', 'localhost'));
define('DB_NAME', chatroomConfig('DB_NAME', 'chatroom_secure'));
define('DB_USER', chatroomConfig('DB_USER', ''));
define('DB_PASS', chatroomConfig('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

// ===================================================
// تنظیمات Session
// ===================================================
define('SESSION_NAME', 'CHATROOM_SESS');
define('SESSION_LIFETIME', chatroomConfig('SESSION_LIFETIME', 3600)); // زمان بیکاری مجاز (ثانیه)
define('SESSION_ABSOLUTE_LIFETIME', chatroomConfig('SESSION_ABSOLUTE_LIFETIME', 43200)); // حداکثر عمر کل session

// ===================================================
// تنظیمات CSRF
// ===================================================
define('CSRF_TOKEN_NAME', 'csrf_token');

// ===================================================
// تنظیمات ورود و امنیت
// ===================================================
// پنجره زمانی شمارش تلاش‌های ناموفق و مدت مسدودی
define('LOGIN_LOCKOUT_TIME', chatroomConfig('LOGIN_LOCKOUT_TIME', 900));
// حد ترکیب «این IP + این نام کاربری»
define('MAX_LOGIN_ATTEMPTS', chatroomConfig('MAX_LOGIN_ATTEMPTS', 5));
// حد هر IP؛ بالا است چون کاربران اینترنت همراه اغلب پشت یک IP مشترک (CGNAT) هستند
define('MAX_LOGIN_ATTEMPTS_PER_IP', chatroomConfig('MAX_LOGIN_ATTEMPTS_PER_IP', 30));
// حد هر نام کاربری از همه IPها (برای حمله توزیع‌شده)
define('MAX_LOGIN_ATTEMPTS_PER_USER', chatroomConfig('MAX_LOGIN_ATTEMPTS_PER_USER', 20));
define('PASSWORD_MIN_LENGTH', 8);
// مهلت انتخاب روم پس از تایید رمز عبور
define('PENDING_LOGIN_TTL', 300);
// IP پروکسی‌های معکوس مورد اعتماد، جدا شده با کاما. خالی یعنی به X-Forwarded-For اعتماد نکن
define('TRUSTED_PROXIES', chatroomConfig('TRUSTED_PROXIES', ''));
// فقط وقتی فعال شود که تمدید گواهی HTTPS تضمین شده است؛
// با گواهی منقضی و HSTS فعال، مرورگر کاربران اجازه ورود نمی‌دهد
define('ENABLE_HSTS', chatroomConfig('ENABLE_HSTS', false));

// ===================================================
// تنظیمات آنلاین بودن
// ===================================================
define('ONLINE_TIMEOUT', 90); // کاربر بدون heartbeat بعد از این زمان آفلاین محسوب می‌شود
define('HEARTBEAT_INTERVAL', 30); // هر 30 ثانیه وضعیت به‌روز شود

// ===================================================
// تنظیمات آپلود
// ===================================================
// بهتر است در سرور واقعی به مسیری بیرون از webroot اشاره کند (مثل /var/lib/chatroom/uploads/)
define('UPLOAD_DIR', rtrim(chatroomConfig('UPLOAD_DIR', __DIR__ . '/uploads'), '/\\') . '/');
define('MAX_FILE_SIZE', chatroomConfig('MAX_FILE_SIZE', 10 * 1024 * 1024)); // 10 مگابایت
define('UPLOAD_DAILY_QUOTA', chatroomConfig('UPLOAD_DAILY_QUOTA', 200 * 1024 * 1024)); // سهمیه روزانه هر کاربر

// ===================================================
// تنظیمات پیام
// ===================================================
define('MAX_MESSAGE_LENGTH', 5000);
define('MESSAGES_PER_PAGE', 50);
define('MAX_NEW_MESSAGES_PER_POLL', 200);

// ===================================================
// تنظیمات سایت
// ===================================================
define('SITE_NAME', chatroomConfig('SITE_NAME', 'چت روم امن'));
define('TIMEZONE', 'Asia/Tehran');

// ===================================================
// تنظیمات محیط
// ===================================================
define('DEBUG_MODE', chatroomConfig('DEBUG_MODE', false)); // فقط روی سیستم توسعه فعال شود
define('LOG_ERRORS', true);
define('ERROR_LOG_FILE', chatroomConfig('ERROR_LOG_FILE', __DIR__ . '/logs/error.log'));

// ===================================================
// تنظیم timezone
// ===================================================
date_default_timezone_set(TIMEZONE);

// ===================================================
// نمایش خطاها
// ===================================================
error_reporting(E_ALL);
ini_set('display_errors', DEBUG_MODE ? '1' : '0');

// ===================================================
// لاگ کردن خطاها
// ===================================================
if (LOG_ERRORS) {
    ini_set('log_errors', '1');
    ini_set('error_log', ERROR_LOG_FILE);
}

// ===================================================
// مدیریت خطاهای پیش‌بینی نشده
// ===================================================

/**
 * پاسخ عمومی خطای سرور بدون افشای جزئیات و پایان اجرا
 * صفحات API با define('API_REQUEST', true) پاسخ JSON می‌گیرند
 * @param int $status
 * @param string $message
 */
function chatroomFatalResponse($status = 500, $message = 'خطایی در سرور رخ داد. لطفاً بعداً تلاش کنید.') {
    if (!headers_sent()) {
        http_response_code($status);
    }
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
    if (defined('API_REQUEST')) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'message' => $message, 'data' => null], JSON_UNESCAPED_UNICODE);
    } else {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><meta charset="UTF-8"><p>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></html>';
    }
    exit();
}

set_exception_handler(function ($e) {
    error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (DEBUG_MODE) {
        chatroomFatalResponse(500, $e->getMessage());
    }
    chatroomFatalResponse(500);
});

// ===================================================
// نوع فایل‌های مجاز
// ===================================================

/**
 * فهرست سفید فایل‌های مجاز: نوع واقعی فایل => پسوند ذخیره و نوع پیام
 * پسوند همیشه از این جدول می‌آید و هرگز از نام فایل کاربر گرفته نمی‌شود
 * @return array
 */
function getAllowedUploadTypes() {
    return [
        'image/jpeg' => ['ext' => 'jpg', 'type' => 'image', 'inline' => true],
        'image/png' => ['ext' => 'png', 'type' => 'image', 'inline' => true],
        'image/gif' => ['ext' => 'gif', 'type' => 'image', 'inline' => true],
        'image/webp' => ['ext' => 'webp', 'type' => 'image', 'inline' => true],
        'application/pdf' => ['ext' => 'pdf', 'type' => 'file', 'inline' => false],
        'application/msword' => ['ext' => 'doc', 'type' => 'file', 'inline' => false],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['ext' => 'docx', 'type' => 'file', 'inline' => false],
        'audio/mpeg' => ['ext' => 'mp3', 'type' => 'voice', 'inline' => true],
        'audio/wav' => ['ext' => 'wav', 'type' => 'voice', 'inline' => true],
        'audio/x-wav' => ['ext' => 'wav', 'type' => 'voice', 'inline' => true],
        'audio/ogg' => ['ext' => 'ogg', 'type' => 'voice', 'inline' => true],
        'audio/webm' => ['ext' => 'webm', 'type' => 'voice', 'inline' => true],
    ];
}
