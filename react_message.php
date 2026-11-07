<?php
/**
 * افزودن یا برداشتن واکنش روی پیام
 * چت روم پیشرفته - نسخه 3.0
 * سازگار با PHP 8.5
 */

define('API_REQUEST', true);
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

$access = requireChatApiAccess('POST', 'react', 60);
$messageId = validatePositiveInt($_POST['message_id'] ?? 0);
$emoji = postString('emoji');

if (!$messageId || !in_array($emoji, REACTION_EMOJIS, true)) {
    jsonError('درخواست نامعتبر است.');
}

if (isMembershipMuted($access['membership'])) {
    jsonError('شما در این روم ساکت شده‌اید.', 403);
}

$db = getDB();
$stmt = $db->prepare("SELECT 1 FROM messages WHERE id = :id AND room_id = :room AND is_deleted = 0");
$stmt->execute([':id' => $messageId, ':room' => $access['room_id']]);
if (!$stmt->fetch()) {
    jsonError('پیام یافت نشد', 404);
}

$key = [':message' => $messageId, ':user' => $access['user_id'], ':emoji' => $emoji];

$db->beginTransaction();
$stmt = $db->prepare("DELETE FROM message_reactions WHERE message_id = :message AND user_id = :user AND emoji = :emoji");
$stmt->execute($key);
$added = $stmt->rowCount() === 0;
if ($added) {
    $stmt = $db->prepare("INSERT INTO message_reactions (message_id, user_id, emoji) VALUES (:message, :user, :emoji)");
    $stmt->execute($key);
}
recordRoomEvent($access['room_id'], 'reaction', $messageId, $access['user_id']);
$db->commit();

jsonSuccess(['added' => $added]);
