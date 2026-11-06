<?php
/**
 * API حذف پیام
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

// دریافت شناسه پیام
$messageId = validatePositiveInt($_POST['message_id'] ?? 0);

if (!$messageId) {
    jsonError('شناسه پیام نامعتبر است');
}

try {
    // بررسی پیام
    $stmt = $db->prepare("
        SELECT id, user_id, room_id 
        FROM messages 
        WHERE id = :id AND is_deleted = 0
    ");
    $stmt->execute([':id' => $messageId]);
    $message = $stmt->fetch();
    
    if (!$message) {
        jsonError('پیام یافت نشد');
    }
    
    // فقط صاحب پیام یا مدیر روم می‌تواند حذف کند
    $canDelete = false;
    
    if ((int)$message['user_id'] === $userId) {
        $canDelete = true;
    } else {
        // بررسی آیا کاربر مدیر روم است
        $stmt = $db->prepare("
            SELECT role FROM users_rooms 
            WHERE user_id = :user_id AND room_id = :room_id
        ");
        $stmt->execute([':user_id' => $userId, ':room_id' => $roomId]);
        $membership = $stmt->fetch();
        
        if ($membership && in_array($membership['role'], ['admin', 'moderator'])) {
            $canDelete = true;
        }
    }
    
    if (!$canDelete) {
        jsonError('شما مجاز به حذف این پیام نیستید', 403);
    }
    
    // حذف نرم (soft delete)
    $stmt = $db->prepare("UPDATE messages SET is_deleted = 1, deleted_at = NOW() WHERE id = :id");
    $result = $stmt->execute([':id' => $messageId]);
    
    if ($result) {
        jsonSuccess(['message_id' => $messageId], 'پیام حذف شد');
    } else {
        jsonError('خطا در حذف پیام');
    }
    
} catch (PDOException $e) {
    error_log("Delete message error: " . $e->getMessage());
    jsonError('خطا در حذف پیام', 500);
}
