<?php
/**
 * خروج ادمین
 * چت روم پیشرفته - نسخه 2.0
 * سازگار با PHP 7.4+
 */

require_once __DIR__ . '/functions.php';

// ثبت لاگ خروج
if (isset($_SESSION['admin_id'])) {
    logSecurityEvent('admin_logout', null, $_SESSION['admin_id'], [
        'username' => isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : 'unknown'
    ]);
}

// پاک کردن session
sessionDestroy();

// ریدایرکت به صفحه ورود ادمین
header("Location: admin-login.php");
exit;
