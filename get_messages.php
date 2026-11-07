<?php
/**
 * دریافت پیام‌های روم: آخرین پیام‌ها، پیام‌های جدیدتر، قدیمی‌تر یا اطراف یک پیام
 * چت روم پیشرفته - نسخه 3.0
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
$beforeId = validatePositiveInt($_GET['before_id'] ?? 0) ?: 0;
$aroundId = validatePositiveInt($_GET['around_id'] ?? 0) ?: 0;

$_SESSION['room_id'] = $roomId;

// این درخواست پرتکرار است؛ قفل session را زود آزاد می‌کنیم
session_write_close();

$db = getDB();

/**
 * @param string $condition
 * @param string $order
 * @param int $limit
 * @return array
 */
function fetchRoomMessages($condition, $order, $limit, array $params) {
    $stmt = getDB()->prepare(MESSAGE_SELECT . " WHERE m.room_id = :room_id AND m.is_deleted = 0 $condition ORDER BY m.id $order LIMIT :limit");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

try {
    // cursor قبل از خواندن پیام‌ها گرفته می‌شود تا تغییری بین این دو گم نشود
    $cursor = latestEventId();
    $roomParam = [':room_id' => $roomId];

    if ($aroundId > 0) {
        $half = (int)(MESSAGES_PER_PAGE / 2);
        $older = fetchRoomMessages('AND m.id <= :pivot', 'DESC', $half, $roomParam + [':pivot' => $aroundId]);
        $newer = fetchRoomMessages('AND m.id > :pivot', 'ASC', $half, $roomParam + [':pivot' => $aroundId]);
        $messages = array_merge(array_reverse($older), $newer);
        $hasNewer = count($newer) === $half;
    } elseif ($beforeId > 0) {
        $messages = array_reverse(fetchRoomMessages('AND m.id < :before', 'DESC', MESSAGES_PER_PAGE, $roomParam + [':before' => $beforeId]));
        $hasNewer = false;
    } elseif ($lastId > 0) {
        $messages = fetchRoomMessages('AND m.id > :last_id', 'ASC', MAX_NEW_MESSAGES_PER_POLL, $roomParam + [':last_id' => $lastId]);
        $hasNewer = false;
    } else {
        $messages = array_reverse(fetchRoomMessages('', 'DESC', MESSAGES_PER_PAGE, $roomParam));
        $hasNewer = false;
    }

    $hasOlder = false;
    if ($messages && $lastId === 0) {
        $stmt = $db->prepare("SELECT 1 FROM messages WHERE room_id = :room AND is_deleted = 0 AND id < :id LIMIT 1");
        $stmt->execute([':room' => $roomId, ':id' => $messages[0]['id']]);
        $hasOlder = (bool)$stmt->fetchColumn();
    }

    $onlineUsers = getOnlineUsers($roomId);
    $stmt = $db->prepare("SELECT COUNT(*) FROM users_rooms WHERE room_id = :room");
    $stmt->execute([':room' => $roomId]);

    jsonSuccess([
        'room_id' => $roomId,
        'role' => $access['membership']['role'],
        'room_type' => $access['membership']['type'],
        'muted' => isMembershipMuted($access['membership']),
        'cursor' => $cursor,
        'messages' => serializeMessages($messages, $userId, $access['membership']),
        'has_older' => $hasOlder,
        'has_newer' => $hasNewer,
        'pinned' => getPinnedMessages($roomId),
        'receipts' => getRoomReceipts($roomId, $userId),
        'rooms' => getRoomList($userId),
        'member_count' => (int)$stmt->fetchColumn(),
        'online_users' => $onlineUsers,
        'online_count' => count($onlineUsers)
    ]);

} catch (PDOException $e) {
    error_log("Get messages error: " . $e->getMessage());
    jsonError('خطا در دریافت پیام‌ها.', 500);
}
