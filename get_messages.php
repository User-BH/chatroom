<?php
/**
 * دریافت پیام‌ها
 * چت روم پیشرفته - نسخه 2.0
 * سازگار با PHP 7.4+
 */

require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

// بررسی ورود کاربر
if (!isUserLoggedIn()) {
    jsonError('لطفاً وارد شوید.', 401);
}

// بررسی CSRF
if (!validateCSRFToken($_GET[CSRF_TOKEN_NAME] ?? '')) {
    jsonError('توکن نامعتبر است.', 403);
}

// Rate Limiting
if (!checkRateLimit('get_messages_' . $_SESSION['user_id'], 60, 60)) {
    jsonError('تعداد درخواست‌ها بیش از حد مجاز است.', 429);
}

$userId = $_SESSION['user_id'];
$roomId = $_SESSION['room_id'];
$lastId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

$db = getDB();

try {
    // بررسی عضویت در روم
    $stmt = $db->prepare("SELECT 1 FROM users_rooms WHERE user_id = :user_id AND room_id = :room_id");
    $stmt->execute([':user_id' => $userId, ':room_id' => $roomId]);
    
    if (!$stmt->fetch()) {
        jsonError('شما عضو این روم نیستید.', 403);
    }
    
    // دریافت پیام‌ها
    if ($lastId > 0) {
        // پیام‌های جدید
        $stmt = $db->prepare("
            SELECT 
                m.id,
                m.user_id,
                m.message,
                m.reply_to,
                m.message_type,
                m.file_path,
                m.file_name,
                m.created_at,
                m.is_edited,
                u.name as sender_name,
                u.username as sender_username,
                r.message as reply_message,
                ru.name as reply_sender_name
            FROM messages m
            JOIN users u ON m.user_id = u.id
            LEFT JOIN messages r ON m.reply_to = r.id
            LEFT JOIN users ru ON r.user_id = ru.id
            WHERE m.room_id = :room_id 
            AND m.id > :last_id
            AND m.is_deleted = 0
            ORDER BY m.id ASC
        ");
        $stmt->execute([':room_id' => $roomId, ':last_id' => $lastId]);
    } else {
        // بارگذاری اولیه
        $stmt = $db->prepare("
            SELECT 
                m.id,
                m.user_id,
                m.message,
                m.reply_to,
                m.message_type,
                m.file_path,
                m.file_name,
                m.created_at,
                m.is_edited,
                u.name as sender_name,
                u.username as sender_username,
                r.message as reply_message,
                ru.name as reply_sender_name
            FROM messages m
            JOIN users u ON m.user_id = u.id
            LEFT JOIN messages r ON m.reply_to = r.id
            LEFT JOIN users ru ON r.user_id = ru.id
            WHERE m.room_id = :room_id 
            AND m.is_deleted = 0
            ORDER BY m.id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', MESSAGES_PER_PAGE, PDO::PARAM_INT);
        $stmt->execute();
    }
    
    $messages = $stmt->fetchAll();
    
    // اگر بارگذاری اولیه است، ترتیب را معکوس کن
    if ($lastId <= 0) {
        $messages = array_reverse($messages);
    }
    
    // فرمت کردن پیام‌ها
    $formattedMessages = [];
    foreach ($messages as $msg) {
        $formattedMessages[] = [
            'id' => (int)$msg['id'],
            'user_id' => (int)$msg['user_id'],
            'message' => $msg['message'],
            'reply_to' => $msg['reply_to'] ? (int)$msg['reply_to'] : null,
            'reply_message' => $msg['reply_message'],
            'reply_sender_name' => $msg['reply_sender_name'],
            'message_type' => $msg['message_type'],
            'file_path' => $msg['file_path'],
            'file_name' => $msg['file_name'],
            'sender_name' => $msg['sender_name'],
            'sender_username' => $msg['sender_username'],
            'time' => formatTime($msg['created_at']),
            'full_time' => $msg['created_at'],
            'is_edited' => (bool)$msg['is_edited'],
            'is_mine' => ((int)$msg['user_id'] === $userId),
            'avatar_color' => getAvatarColor($msg['sender_name']),
            'initials' => getInitials($msg['sender_name'])
        ];
    }
    
    // دریافت کاربران آنلاین
    $stmt = $db->prepare("
        SELECT u.id, u.name, u.status, u.last_seen
        FROM users u
        JOIN users_rooms ur ON u.id = ur.user_id
        WHERE ur.room_id = :room_id
        AND u.is_active = 1
        AND (u.status = 'online' OR u.last_seen > DATE_SUB(NOW(), INTERVAL :timeout SECOND))
    ");
    $stmt->execute([':room_id' => $roomId, ':timeout' => ONLINE_TIMEOUT]);
    $onlineUsers = $stmt->fetchAll();
    
    jsonSuccess([
        'messages' => $formattedMessages,
        'online_users' => $onlineUsers,
        'online_count' => count($onlineUsers)
    ]);
    
} catch (PDOException $e) {
    error_log("Get messages error: " . $e->getMessage());
    jsonError('خطا در دریافت پیام‌ها.', 500);
}
