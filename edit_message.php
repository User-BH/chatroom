<?php
/**
 * API ویرایش پیام
 * چت روم پیشرفته - نسخه 2.0
 * سازگار با PHP 7.4+
 */

require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

// فقط POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('متد نامعتبر', 405);
}

// بررسی ورود
if (!isUserLoggedIn()) {
    jsonError('لطفاً وارد شوید', 401);
}

// اعتبارسنجی CSRF
if (!validateCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
    jsonError('درخواست نامعتبر', 403);
}

$userId = $_SESSION['user_id'];
$roomId = $_SESSION['room_id'];
$db = getDB();

// دریافت ورودی‌ها
$messageId = validatePositiveInt($_POST['message_id'] ?? 0);
$newText = trim($_POST['text'] ?? '');

if (!$messageId) {
    jsonError('شناسه پیام نامعتبر است');
}

if (empty($newText)) {
    jsonError('متن پیام نمی‌تواند خالی باشد');
}

if (mb_strlen($newText, 'UTF-8') > MAX_MESSAGE_LENGTH) {
    jsonError('پیام بیش از حد طولانی است (حداکثر ' . MAX_MESSAGE_LENGTH . ' کاراکتر)');
}

try {
    // بررسی مالکیت پیام
    $stmt = $db->prepare("
        SELECT id, user_id, room_id, message_type, created_at 
        FROM messages 
        WHERE id = :id AND is_deleted = 0
    ");
    $stmt->execute([':id' => $messageId]);
    $message = $stmt->fetch();
    
    if (!$message) {
        jsonError('پیام یافت نشد');
    }
    
    // فقط صاحب پیام می‌تواند ویرایش کند
    if ((int)$message['user_id'] !== $userId) {
        jsonError('شما مجاز به ویرایش این پیام نیستید', 403);
    }
    
    // فقط پیام‌های متنی قابل ویرایش هستند
    if ($message['message_type'] !== 'text') {
        jsonError('فقط پیام‌های متنی قابل ویرایش هستند');
    }
    
    // محدودیت زمانی ویرایش (48 ساعت)
    $messageTime = strtotime($message['created_at']);
    if (time() - $messageTime > 48 * 3600) {
        jsonError('پیام‌های قدیمی‌تر از 48 ساعت قابل ویرایش نیستند');
    }
    
    // ویرایش پیام
    $stmt = $db->prepare("UPDATE messages SET message = :text, is_edited = 1, edited_at = NOW() WHERE id = :id");
    $result = $stmt->execute([':text' => $newText, ':id' => $messageId]);
    
    if ($result) {
        jsonSuccess([
            'message_id' => $messageId,
            'new_text' => $newText
        ], 'پیام ویرایش شد');
    } else {
        jsonError('خطا در ویرایش پیام');
    }
    
} catch (PDOException $e) {
    error_log("Edit message error: " . $e->getMessage());
    jsonError('خطا در ویرایش پیام', 500);
}
