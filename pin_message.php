<?php
/**
 * سنجاق کردن یا برداشتن سنجاق پیام
 * در روم گروهی فقط مدیر و ادمین روم؛ در گفتگوی خصوصی هر دو طرف
 * چت روم پیشرفته - نسخه 3.0
 * سازگار با PHP 8.5
 */

define('API_REQUEST', true);
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

$access = requireChatApiAccess('POST', 'pin', 30);
$messageId = validatePositiveInt($_POST['message_id'] ?? 0);

if (!$messageId) {
    jsonError('شناسه پیام نامعتبر است');
}

$canPin = $access['membership']['type'] === 'direct'
    || in_array($access['membership']['role'], ['admin', 'moderator'], true);
if (!$canPin) {
    jsonError('شما مجاز به سنجاق کردن پیام نیستید.', 403);
}

$db = getDB();
$stmt = $db->prepare("SELECT pinned_at FROM messages WHERE id = :id AND room_id = :room AND is_deleted = 0");
$stmt->execute([':id' => $messageId, ':room' => $access['room_id']]);
$message = $stmt->fetch();
if (!$message) {
    jsonError('پیام یافت نشد', 404);
}

$pinned = $message['pinned_at'] === null;

$db->beginTransaction();
if ($pinned) {
    $stmt = $db->prepare("UPDATE messages SET pinned_at = NOW(), pinned_by = :user WHERE id = :id");
    $stmt->execute([':user' => $access['user_id'], ':id' => $messageId]);
} else {
    $stmt = $db->prepare("UPDATE messages SET pinned_at = NULL, pinned_by = NULL WHERE id = :id");
    $stmt->execute([':id' => $messageId]);
}
recordRoomEvent($access['room_id'], 'pin', $messageId, $access['user_id']);
$db->commit();

jsonSuccess(['pinned' => $pinned, 'list' => getPinnedMessages($access['room_id'])]);
