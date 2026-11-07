<?php
/**
 * دانلود کنترل‌شده فایل‌های پیام
 * پوشه uploads از وب مستقیم در دسترس نیست و تنها راه دریافت فایل همین مسیر است.
 * درخواست Range پشتیبانی می‌شود تا پخش صوت در همه مرورگرها جلو و عقب برود.
 * چت روم پیشرفته - نسخه 3.0
 * سازگار با PHP 8.5
 */

define('API_REQUEST', true);
require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('متد نامعتبر است.', 405);
}
if (!isUserLoggedIn() || !isCurrentAccountValid()) {
    jsonError('لطفاً وارد شوید.', 401);
}

$userId = (int)$_SESSION['user_id'];
$messageId = validatePositiveInt($_GET['id'] ?? 0);

if (!$messageId) {
    jsonError('فایل یافت نشد.', 404);
}

// پیام باید در رومی باشد که کاربر الان عضو فعال آن است؛ بقیه موارد عمداً یکسان 404 هستند
$stmt = getDB()->prepare("
    SELECT m.room_id, m.file_path, m.file_name, m.file_mime
    FROM messages m
    WHERE m.id = :id AND m.is_deleted = 0 AND m.file_path IS NOT NULL
");
$stmt->execute([':id' => $messageId]);
$message = $stmt->fetch();

if (!$message || !getActiveMembership($userId, (int)$message['room_id'])) {
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

$size = filesize($fullPath);
$start = 0;
$end = $size - 1;
$isPartial = false;

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'], $range) && ($range[1] !== '' || $range[2] !== '')) {
    if ($range[1] === '') {
        $start = max(0, $size - (int)$range[2]);
    } else {
        $start = (int)$range[1];
        if ($range[2] !== '') {
            $end = min((int)$range[2], $size - 1);
        }
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit();
    }
    $isPartial = true;
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}

$fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$message['file_name']);

header('Content-Type: ' . $contentType);
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
header('Content-Disposition: ' . $disposition . '; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode((string)$message['file_name']));
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, max-age=3600');

while (ob_get_level() > 0) {
    ob_end_clean();
}

if (!$isPartial) {
    readfile($fullPath);
    exit();
}

$handle = fopen($fullPath, 'rb');
fseek($handle, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(65536, $remaining));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
}
fclose($handle);
exit();
