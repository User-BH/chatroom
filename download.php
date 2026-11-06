<?php
/**
 * دانلود کنترل‌شده فایل‌های پیام
 * پوشه uploads از وب مستقیم در دسترس نیست و تنها راه دریافت فایل همین مسیر است.
 * چت روم پیشرفته - نسخه 2.1
 * سازگار با PHP 8.5
 */

define('API_REQUEST', true);
require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('متد نامعتبر است.', 405);
}
if (!isUserLoggedIn()) {
    jsonError('لطفاً وارد شوید.', 401);
}

$userId = (int)$_SESSION['user_id'];
$roomId = (int)$_SESSION['room_id'];
$messageId = validatePositiveInt($_GET['id'] ?? 0);

if (!$messageId) {
    jsonError('فایل یافت نشد.', 404);
}

if (!getCurrentUserMembership()) {
    jsonError('دسترسی شما به این روم وجود ندارد.', 401);
}

// پیام باید در روم فعلی کاربر باشد؛ بقیه موارد عمداً یکسان 404 هستند
$stmt = getDB()->prepare("
    SELECT file_path, file_name, file_mime
    FROM messages
    WHERE id = :id AND room_id = :room_id AND is_deleted = 0 AND file_path IS NOT NULL
");
$stmt->execute([':id' => $messageId, ':room_id' => $roomId]);
$message = $stmt->fetch();

if (!$message) {
    jsonError('فایل یافت نشد.', 404);
}

// مسیر نهایی حتماً باید داخل پوشه آپلود باشد
$baseDir = realpath(UPLOAD_DIR);
$fullPath = realpath(UPLOAD_DIR . $message['file_path']);

if ($baseDir === false || $fullPath === false || !is_file($fullPath)
    || strpos($fullPath, $baseDir . DIRECTORY_SEPARATOR) !== 0) {
    jsonError('فایل یافت نشد.', 404);
}

$allowed = getAllowedUploadTypes();
$mime = (string)$message['file_mime'];
$info = $allowed[$mime] ?? null;
$contentType = $info ? $mime : 'application/octet-stream';
$disposition = ($info && $info['inline']) ? 'inline' : 'attachment';

// خواندن فایل بزرگ نباید بقیه درخواست‌های همین کاربر را پشت قفل session نگه دارد
session_write_close();

$fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$message['file_name']);

header('Content-Type: ' . $contentType);
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: ' . $disposition . '; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode((string)$message['file_name']));
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, no-store');

while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($fullPath);
exit();
