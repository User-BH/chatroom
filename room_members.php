<?php
/**
 * اعضای روم برای پیشنهاد منشن
 * چت روم پیشرفته - نسخه 3.0
 * سازگار با PHP 8.5
 */

define('API_REQUEST', true);
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

$access = requireChatApiAccess('GET', 'room_members', 60);
session_write_close();

$stmt = getDB()->prepare("
    SELECT u.id, u.name, u.username
    FROM users u
    JOIN users_rooms ur ON ur.user_id = u.id
    WHERE ur.room_id = :room AND u.is_active = 1 AND u.id <> :viewer
    ORDER BY u.name
    LIMIT 500
");
$stmt->execute([':room' => $access['room_id'], ':viewer' => $access['user_id']]);

jsonSuccess(['members' => $stmt->fetchAll()]);
