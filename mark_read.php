<?php
/**
 * ثبت خوانده شدن پیام‌های روم تا یک پیام مشخص
 * چت روم پیشرفته - نسخه 3.0
 * سازگار با PHP 8.5
 */

define('API_REQUEST', true);
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

$access = requireChatApiAccess('POST', 'mark_read', 120);
$messageId = validatePositiveInt($_POST['message_id'] ?? 0);

if (!$messageId) {
    jsonError('شناسه پیام نامعتبر است');
}

advanceReadCursor($access['user_id'], $access['room_id'], $messageId, 'read');

jsonSuccess(['rooms' => getRoomList($access['user_id'])]);
