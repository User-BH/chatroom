<?php
/**
 * ارسال پیام
 * چت روم پیشرفته - نسخه 3.0
 * سازگار با PHP 8.5
 */

define('API_REQUEST', true);
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

$access = requireChatApiAccess('POST', 'send_message', 30);
$userId = $access['user_id'];
$roomId = $access['room_id'];

$message = trim(postString('message'));
$replyTo = validatePositiveInt($_POST['reply_to'] ?? 0) ?: null;

// شناسه کلاینت: ارسال دوباره از صف آفلاین پیام تکراری نمی‌سازد
$clientToken = postString('client_token');
if ($clientToken === '' || !preg_match('/^[a-f0-9-]{36}$/', $clientToken)) {
    $clientToken = null;
}

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

/**
 * پیام قبلی با همین شناسه کلاینت
 * @return int|null
 */
function findExistingMessage(PDO $db, $userId, $clientToken) {
    if ($clientToken === null) {
        return null;
    }
    $stmt = $db->prepare("SELECT id FROM messages WHERE user_id = :user AND client_token = :token");
    $stmt->execute([':user' => $userId, ':token' => $clientToken]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

try {
    $existing = findExistingMessage($db, $userId, $clientToken);
    if ($existing !== null) {
        jsonSuccess(['message_id' => $existing, 'duplicate' => true], 'پیام قبلاً ثبت شده است.');
    }

    // بررسی پیام reply
    if ($replyTo !== null) {
        $stmt = $db->prepare("SELECT id FROM messages WHERE id = :id AND room_id = :room_id AND is_deleted = 0");
        $stmt->execute([':id' => $replyTo, ':room_id' => $roomId]);
        if (!$stmt->fetch()) {
            $replyTo = null; // پیام مرجع وجود ندارد
        }
    }

    $db->beginTransaction();

    $stmt = $db->prepare("
        INSERT INTO messages (room_id, user_id, message, reply_to, message_type, client_token, created_at)
        VALUES (:room_id, :user_id, :message, :reply_to, 'text', :token, NOW())
    ");
    $stmt->execute([
        ':room_id' => $roomId,
        ':user_id' => $userId,
        ':message' => $message,
        ':reply_to' => $replyTo,
        ':token' => $clientToken
    ]);
    $messageId = (int)$db->lastInsertId();

    saveMessageMentions($messageId, $roomId, $message);
    recordRoomEvent($roomId, 'message_new', $messageId, $userId);

    // پیام خود فرستنده خوانده‌شده حساب می‌شود
    $stmt = $db->prepare("
        UPDATE users_rooms
        SET last_read_message_id = GREATEST(last_read_message_id, :id1),
            last_delivered_message_id = GREATEST(last_delivered_message_id, :id2)
        WHERE user_id = :user AND room_id = :room
    ");
    $stmt->execute([':id1' => $messageId, ':id2' => $messageId, ':user' => $userId, ':room' => $roomId]);

    $db->commit();

    updateUserOnlineStatus($userId);

    jsonSuccess([
        'message_id' => $messageId
    ], 'پیام ارسال شد.');

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    // دو ارسال همزمان با یک شناسه: یکی ثبت شده و همان معتبر است
    if ($e->getCode() === '23000' && ($existing = findExistingMessage($db, $userId, $clientToken)) !== null) {
        jsonSuccess(['message_id' => $existing, 'duplicate' => true], 'پیام قبلاً ثبت شده است.');
    }
    error_log("Send message error: " . $e->getMessage());
    jsonError('خطا در ارسال پیام.', 500);
}
