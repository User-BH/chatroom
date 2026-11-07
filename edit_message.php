<?php
/**
 * API ویرایش پیام
 * چت روم پیشرفته - نسخه 3.0
 * سازگار با PHP 8.5
 */

define('API_REQUEST', true);
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

$access = requireChatApiAccess('POST', 'edit_message', 30);
$userId = $access['user_id'];
$roomId = $access['room_id'];
$db = getDB();

// دریافت ورودی‌ها
$messageId = validatePositiveInt($_POST['message_id'] ?? 0);
$newText = trim(postString('text'));

if (!$messageId) {
    jsonError('شناسه پیام نامعتبر است');
}

$messageError = validateMessageText($newText);
if ($messageError !== '') {
    jsonError($messageError);
}

if (isMembershipMuted($access['membership'])) {
    jsonError('شما در این روم ساکت شده‌اید و امکان ویرایش پیام ندارید.', 403);
}

try {
    // پیام باید متعلق به همین روم باشد؛ پیام روم‌های دیگر «یافت نشد» است
    $stmt = $db->prepare("
        SELECT id, user_id, message_type, created_at
        FROM messages
        WHERE id = :id AND room_id = :room_id AND is_deleted = 0
    ");
    $stmt->execute([':id' => $messageId, ':room_id' => $roomId]);
    $message = $stmt->fetch();

    if (!$message) {
        jsonError('پیام یافت نشد', 404);
    }

    // فقط صاحب پیام می‌تواند ویرایش کند
    if ((int)$message['user_id'] !== $userId) {
        jsonError('شما مجاز به ویرایش این پیام نیستید', 403);
    }

    // فقط پیام‌های متنی قابل ویرایش هستند
    if ($message['message_type'] !== 'text') {
        jsonError('فقط پیام‌های متنی قابل ویرایش هستند');
    }

    // محدودیت زمانی ویرایش
    if (time() - strtotime($message['created_at']) > MESSAGE_EDIT_WINDOW) {
        jsonError('پیام‌های قدیمی‌تر از 48 ساعت قابل ویرایش نیستند');
    }

    $db->beginTransaction();
    $stmt = $db->prepare("UPDATE messages SET message = :text, is_edited = 1, edited_at = NOW() WHERE id = :id AND room_id = :room_id");
    $stmt->execute([':text' => $newText, ':id' => $messageId, ':room_id' => $roomId]);
    saveMessageMentions($messageId, $roomId, $newText);
    recordRoomEvent($roomId, 'message_edit', $messageId, $userId);
    $db->commit();

    jsonSuccess([
        'message_id' => $messageId,
        'new_text' => $newText
    ], 'پیام ویرایش شد');

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Edit message error: " . $e->getMessage());
    jsonError('خطا در ویرایش پیام', 500);
}
