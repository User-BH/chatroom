<?php
/**
 * به‌روزرسانی وضعیت کاربر
 * چت روم پیشرفته - نسخه 2.1
 * سازگار با PHP 8.5
 */

define('API_REQUEST', true);
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

$access = requireChatApiAccess('POST', 'update_status', 30);

$status = postString('status');

// اعتبارسنجی وضعیت
$validStatuses = ['online', 'away', 'offline'];
if (!in_array($status, $validStatuses, true)) {
    $status = 'online';
}

try {
    updateUserOnlineStatus($access['user_id'], $status);

    jsonSuccess(['status' => $status]);

} catch (PDOException $e) {
    error_log("Update status error: " . $e->getMessage());
    jsonError('خطا در به‌روزرسانی وضعیت.', 500);
}
