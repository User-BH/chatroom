<?php
/**
 * خروج کاربر
 * چت روم پیشرفته - نسخه 2.0
 * سازگار با PHP 7.4+
 */

require_once __DIR__ . '/functions.php';

// به‌روزرسانی وضعیت کاربر به آفلاین
if (isset($_SESSION['user_id'])) {
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE users SET status = 'offline', last_seen = NOW() WHERE id = :id");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        
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
