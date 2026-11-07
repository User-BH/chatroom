<?php
/**
 * افرادی که کاربر می‌تواند با آن‌ها گفتگوی خصوصی شروع کند
 * فقط کسانی که حداقل یک روم گروهی مشترک دارند؛ فهرست کل کاربران لو نمی‌رود
 * چت روم پیشرفته - نسخه 3.0
 * سازگار با PHP 8.5
 */

define('API_REQUEST', true);
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

$access = requireChatApiAccess('GET', 'contacts', 30);
session_write_close();

jsonSuccess(['contacts' => getContacts($access['user_id'])]);
