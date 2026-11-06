<?php
/**
 * ارسال پیام
 * چت روم پیشرفته - نسخه 2.0
 * سازگار با PHP 7.4+
 */

require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

// فقط POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('متد نامعتبر است.', 405);
}

// بررسی ورود کاربر
if (!isUserLoggedIn()) {
    jsonError('لطفاً وارد شوید.', 401);
}

// بررسی CSRF
if (!validateCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
    jsonError('توکن نامعتبر است.', 403);
}

// Rate Limiting
if (!checkRateLimit('send_message_' . $_SESSION['user_id'], 30, 60)) {
    jsonError('تعداد پیام‌های ارسالی بیش از حد مجاز است.', 429);
}

$userId = $_SESSION['user_id'];
$roomId = $_SESSION['room_id'];
$message = isset($_POST['message']) ? trim($_POST['message']) : '';
$replyTo = isset($_POST['reply_to']) ? (int)$_POST['reply_to'] : null;

// اعتبارسنجی پیام
if (empty($message)) {
    jsonError('پیام نمی‌تواند خالی باشد.');
}

if (mb_strlen($message, 'UTF-8') > MAX_MESSAGE_LENGTH) {
    jsonError('پیام بیش از حد طولانی است.');
}

$db = getDB();

try {
    // بررسی عضویت در روم
    $stmt = $db->prepare("
        SELECT is_muted, muted_until 
        FROM users_rooms 
        WHERE user_id = :user_id AND room_id = :room_id
    ");
    $stmt->execute([':user_id' => $userId, ':room_id' => $roomId]);
    $membership = $stmt->fetch();
    
    if (!$membership) {
        jsonError('شما عضو این روم نیستید.', 403);
    }
    
    // بررسی سکوت
    if ($membership['is_muted']) {
        if ($membership['muted_until'] === null || strtotime($membership['muted_until']) > time()) {
            jsonError('شما در این روم ساکت شده‌اید و امکان ارسال پیام ندارید.');
        }
    }
    
    // بررسی پیام reply
    if ($replyTo !== null && $replyTo > 0) {
        $stmt = $db->prepare("SELECT id FROM messages WHERE id = :id AND room_id = :room_id AND is_deleted = 0");
        $stmt->execute([':id' => $replyTo, ':room_id' => $roomId]);
        if (!$stmt->fetch()) {
            $replyTo = null; // پیام مرجع وجود ندارد
        }
    } else {
        $replyTo = null;
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
    $stmt = $db->prepare("UPDATE users SET status = 'online', last_seen = NOW() WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    
    jsonSuccess([
        'message_id' => (int)$messageId
    ], 'پیام ارسال شد.');
    
} catch (PDOException $e) {
    error_log("Send message error: " . $e->getMessage());
    jsonError('خطا در ارسال پیام.', 500);
}
