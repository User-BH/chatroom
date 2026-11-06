<?php
/**
 * دریافت پیام‌ها
 * چت روم پیشرفته - نسخه 2.1
 * سازگار با PHP 8.5
 */

define('API_REQUEST', true);
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

// توکن CSRF در هدر می‌آید تا وارد لاگ وب‌سرور نشود
$access = requireChatApiAccess('GET', 'get_messages', 120);
$userId = $access['user_id'];
$roomId = $access['room_id'];
$lastId = validatePositiveInt($_GET['last_id'] ?? 0) ?: 0;

// این درخواست هر چند ثانیه تکرار می‌شود؛ قفل session را زود آزاد می‌کنیم
session_write_close();

$db = getDB();

// متن پیام حذف‌شده نباید از طریق پیام‌های پاسخ لو برود
$selectSql = "
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
        r.message as reply_message,
        ru.name as reply_sender_name
    FROM messages m
    JOIN users u ON m.user_id = u.id
    LEFT JOIN messages r ON m.reply_to = r.id AND r.is_deleted = 0
    LEFT JOIN users ru ON r.user_id = ru.id
    WHERE m.room_id = :room_id
    AND m.is_deleted = 0
";

try {
    // دریافت پیام‌ها
    if ($lastId > 0) {
        // پیام‌های جدید
        $stmt = $db->prepare($selectSql . " AND m.id > :last_id ORDER BY m.id ASC LIMIT :limit");
        $stmt->bindValue(':last_id', $lastId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', MAX_NEW_MESSAGES_PER_POLL, PDO::PARAM_INT);
    } else {
        // بارگذاری اولیه
        $stmt = $db->prepare($selectSql . " ORDER BY m.id DESC LIMIT :limit");
        $stmt->bindValue(':limit', MESSAGES_PER_PAGE, PDO::PARAM_INT);
    }
    $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
    $stmt->execute();

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
            // فایل فقط از مسیر کنترل‌شده دانلود می‌شود، نه با آدرس مستقیم
            'file_url' => $msg['file_path'] ? 'download.php?id=' . (int)$msg['id'] : null,
            'file_name' => $msg['file_name'],
            'sender_name' => $msg['sender_name'],
            'time' => formatTime($msg['created_at']),
            'full_time' => $msg['created_at'],
            'is_edited' => (bool)$msg['is_edited'],
            'is_mine' => ((int)$msg['user_id'] === $userId),
            'avatar_color' => getAvatarColor($msg['sender_name']),
            'initials' => getInitials($msg['sender_name'])
        ];
    }

    // دریافت کاربران آنلاین
    $onlineUsers = getOnlineUsers($roomId);

    jsonSuccess([
        'messages' => $formattedMessages,
        'online_users' => $onlineUsers,
        'online_count' => count($onlineUsers)
    ]);

} catch (PDOException $e) {
    error_log("Get messages error: " . $e->getMessage());
    jsonError('خطا در دریافت پیام‌ها.', 500);
}
