<?php
/**
 * جست‌وجوی پیام در روم فعلی
 * چت روم پیشرفته - نسخه 3.0
 * سازگار با PHP 8.5
 */

define('API_REQUEST', true);
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

$access = requireChatApiAccess('GET', 'search', 30);
session_write_close();

$query = cleanInput($_GET['q'] ?? '');
if (mb_strlen($query, 'UTF-8') < 2 || mb_strlen($query, 'UTF-8') > 100 || !mb_check_encoding($query, 'UTF-8')) {
    jsonError('عبارت جست‌وجو باید بین 2 تا 100 کاراکتر باشد.');
}

$like = '%' . addcslashes($query, '%_\\') . '%';
$stmt = getDB()->prepare("
    SELECT m.id, m.message, m.file_name, m.created_at, u.name AS sender_name
    FROM messages m
    JOIN users u ON u.id = m.user_id
    WHERE m.room_id = :room AND m.is_deleted = 0 AND (m.message LIKE :q1 OR m.file_name LIKE :q2)
    ORDER BY m.id DESC
    LIMIT 50
");
$stmt->execute([':room' => $access['room_id'], ':q1' => $like, ':q2' => $like]);

jsonSuccess(['results' => array_map(function ($row) {
    $text = $row['message'] !== '' ? $row['message'] : (string)$row['file_name'];
    return [
        'id' => (int)$row['id'],
        'text' => mb_substr($text, 0, 200, 'UTF-8'),
        'sender_name' => $row['sender_name'],
        'time' => $row['created_at']
    ];
}, $stmt->fetchAll())]);
