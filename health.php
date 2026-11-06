<?php
/**
 * بررسی سلامت برای مانیتورینگ
 * عمداً هیچ جزئیاتی از سرور برنمی‌گرداند و session نمی‌سازد.
 * چت روم پیشرفته - نسخه 2.2
 * سازگار با PHP 7.4+
 */

define('API_REQUEST', true);
define('NO_SESSION', true);
require_once __DIR__ . '/functions.php';

header('Cache-Control: no-store');

// قطع بودن دیتابیس در db.php با پاسخ 503 مدیریت می‌شود
getDB()->query('SELECT 1');

if (!is_dir(UPLOAD_DIR) || !is_writable(UPLOAD_DIR)) {
    error_log('Health check: upload directory is not writable');
    jsonError('unhealthy', 503);
}

jsonSuccess(['status' => 'ok'], 'ok');
