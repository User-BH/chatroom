<?php
/**
 * ارسال پیام
 * چت روم پیشرفته - نسخه 2.1
 * سازگار با PHP 7.4+
 */

define('API_REQUEST', true);
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

$access = requireChatApiAccess('POST', 'send_message', 30);
$userId = $access['user_id'];
$roomId = $access['room_id'];

$message = trim(postString('message'));
$replyTo = validatePositiveInt($_POST['reply_to'] ?? 0) ?: null;

// اعتبارسنجی پیام
$messageError = validateMessageText($message);
if ($messageError !== '') {
    jsonError($messageError);
}

// بررسی سکوت
if (isMembershipMuted($access['membership'])) {
    jsonError('شما در این روم ساکت شده‌اید و امکان ارسال پیام ندارید.', 403);
}

$db = getDB();

try {
    // بررسی پیام reply
    if ($replyTo !== null) {
        $stmt = $db->prepare("SELECT id FROM messages WHERE id = :id AND room_id = :room_id AND is_deleted = 0");
        $stmt->execute([':id' => $replyTo, ':room_id' => $roomId]);
        if (!$stmt->fetch()) {
            $replyTo = null; // پیام مرجع وجود ندارد
        }
    }

    // ذخیره پیام
    $stmt = $db->prepare("
        INSERT INTO messages (room_id, user_id, message, reply_to, message_type, created_at)
        VALUES (:room_id, :user_id, :message, :reply_to, 'text', NOW())
    ");

    $stmt->execute([
        ':room_id' => $roomId,
        ':user_id' => $userId,
        ':message' => $message,
        ':reply_to' => $replyTo
    ]);

    $messageId = $db->lastInsertId();

    // به‌روزرسانی وضعیت کاربر
    updateUserOnlineStatus($userId);

    jsonSuccess([
        'message_id' => (int)$messageId
    ], 'پیام ارسال شد.');

} catch (PDOException $e) {
    error_log("Send message error: " . $e->getMessage());
    jsonError('خطا در ارسال پیام.', 500);
}
