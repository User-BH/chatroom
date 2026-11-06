<?php
/**
 * خروج ادمین
 * فقط با POST و توکن CSRF تا سایت یا لینک دیگری نتواند ادمین را بیرون کند
 * چت روم پیشرفته - نسخه 2.1
 * سازگار با PHP 8.5
 */

require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken(requestCSRFToken())) {
    redirect('admin-login.php');
}

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
