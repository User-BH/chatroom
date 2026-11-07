<?php
/**
 * شروع یا باز کردن گفتگوی خصوصی با یک کاربر
 * گفتگوی خصوصی یک روم دونفره است و بقیه قابلیت‌ها را از روم می‌گیرد
 * چت روم پیشرفته - نسخه 3.0
 * سازگار با PHP 8.5
 */

define('API_REQUEST', true);
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

$access = requireChatApiAccess('POST', 'start_direct', 20);
$userId = $access['user_id'];
$peerId = validatePositiveInt($_POST['user_id'] ?? 0);

if (!$peerId || $peerId === $userId || !in_array($peerId, array_column(getContacts($userId), 'id'), true)) {
    // وجود نداشتن کاربر با نداشتن روم مشترک یکسان است
    jsonError('این کاربر در دسترس نیست.', 404);
}

$db = getDB();
$directKey = min($userId, $peerId) . ':' . max($userId, $peerId);

$find = $db->prepare("SELECT id, is_active FROM rooms WHERE direct_key = :key");
$find->execute([':key' => $directKey]);
$room = $find->fetch();

if (!$room) {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO rooms (name, type, direct_key) VALUES (:name, 'direct', :key)");
        $stmt->execute([':name' => 'direct:' . $directKey, ':key' => $directKey]);
        $roomId = (int)$db->lastInsertId();
        $stmt = $db->prepare("INSERT INTO users_rooms (user_id, room_id, role) VALUES (:user, :room, 'member')");
        $stmt->execute([':user' => $userId, ':room' => $roomId]);
        $stmt->execute([':user' => $peerId, ':room' => $roomId]);
        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();
        // دو طرف همزمان شروع کرده‌اند؛ روم طرف دیگر معتبر است
        if ($e->getCode() !== '23000') {
            throw $e;
        }
        $find->execute([':key' => $directKey]);
        $roomId = (int)$find->fetchColumn();
    }
} else {
    $roomId = (int)$room['id'];
}

jsonSuccess(['room_id' => $roomId]);
