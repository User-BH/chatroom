<?php
/**
 * API حذف پیام
 * چت روم پیشرفته - نسخه 2.1
 * سازگار با PHP 8.5
 */

define('API_REQUEST', true);
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

$access = requireChatApiAccess('POST', 'delete_message', 30);
$userId = $access['user_id'];
$roomId = $access['room_id'];
$db = getDB();

// دریافت شناسه پیام
$messageId = validatePositiveInt($_POST['message_id'] ?? 0);

if (!$messageId) {
    jsonError('شناسه پیام نامعتبر است');
}

try {
    // پیام باید متعلق به همین روم باشد؛ مدیر یک روم به پیام روم دیگر دسترسی ندارد
    $stmt = $db->prepare("
        SELECT id, user_id
        FROM messages
        WHERE id = :id AND room_id = :room_id AND is_deleted = 0
    ");
    $stmt->execute([':id' => $messageId, ':room_id' => $roomId]);
    $message = $stmt->fetch();

    if (!$message) {
        jsonError('پیام یافت نشد', 404);
    }

    // فقط صاحب پیام یا مدیر همین روم می‌تواند حذف کند
    $isOwner = (int)$message['user_id'] === $userId;
    $isModerator = in_array($access['membership']['role'], ['admin', 'moderator'], true);

    if (!$isOwner && !$isModerator) {
        jsonError('شما مجاز به حذف این پیام نیستید', 403);
    }

    // حذف نرم (soft delete)
    $stmt = $db->prepare("UPDATE messages SET is_deleted = 1, deleted_at = NOW() WHERE id = :id AND room_id = :room_id");
    $stmt->execute([':id' => $messageId, ':room_id' => $roomId]);

    if (!$isOwner) {
        logSecurityEvent('message_deleted_by_moderator', $userId, null, [
            'message_id' => $messageId,
            'room_id' => $roomId
        ]);
    }

    jsonSuccess(['message_id' => $messageId], 'پیام حذف شد');

} catch (PDOException $e) {
    error_log("Delete message error: " . $e->getMessage());
    jsonError('خطا در حذف پیام', 500);
}
