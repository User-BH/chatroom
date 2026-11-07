<?php
/**
 * همگام‌سازی با polling؛ وقتی SSE در دسترس نیست کلاینت از این مسیر استفاده می‌کند
 * چت روم پیشرفته - نسخه 3.0
 * سازگار با PHP 8.5
 */

define('API_REQUEST', true);
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

$access = requireChatApiAccess('GET', 'sync', 120);
session_write_close();

try {
    jsonSuccess(buildSyncPayload(
        $access['user_id'],
        $access['room_id'],
        $access['membership'],
        validatePositiveInt($_GET['cursor'] ?? 0) ?: 0
    ));
} catch (PDOException $e) {
    error_log('Sync error: ' . $e->getMessage());
    jsonError('خطا در همگام‌سازی.', 500);
}
