<?php
/**
 * خروج کاربر
 * فقط با POST و توکن CSRF تا سایت یا لینک دیگری نتواند کاربر را بیرون کند
 * چت روم پیشرفته - نسخه 2.1
 * سازگار با PHP 7.4+
 */

require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken(requestCSRFToken())) {
    redirect('login.php');
}

// به‌روزرسانی وضعیت کاربر به آفلاین
if (isset($_SESSION['user_id'])) {
    try {
        updateUserOnlineStatus($_SESSION['user_id'], 'offline');

        // ثبت لاگ خروج
        logSecurityEvent('user_logout', $_SESSION['user_id'], null, [
            'username' => isset($_SESSION['username']) ? $_SESSION['username'] : 'unknown'
        ]);
    } catch (PDOException $e) {
        error_log("Logout error: " . $e->getMessage());
    }
}

// پاک کردن session
sessionDestroy();

// ریدایرکت به صفحه ورود
header("Location: login.php?logout=1");
exit;
