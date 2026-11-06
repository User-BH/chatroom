<?php
/**
 * به‌روزرسانی وضعیت کاربر
 * چت روم پیشرفته - نسخه 2.0
 * سازگار با PHP 7.4+
 */

require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

// بررسی ورود کاربر
if (!isUserLoggedIn()) {
    jsonError('لطفاً وارد شوید.', 401);
}

$userId = $_SESSION['user_id'];
$status = isset($_GET['status']) ? cleanInput($_GET['status']) : 'online';

// اعتبارسنجی وضعیت
$validStatuses = ['online', 'away', 'offline'];
if (!in_array($status, $validStatuses)) {
    $status = 'online';
}

$db = getDB();

try {
    $stmt = $db->prepare("UPDATE users SET status = :status, last_seen = NOW() WHERE id = :id");
    $stmt->execute([':status' => $status, ':id' => $userId]);
    
    jsonSuccess(['status' => $status]);
    
} catch (PDOException $e) {
    error_log("Update status error: " . $e->getMessage());
    jsonError('خطا در به‌روزرسانی وضعیت.', 500);
}
