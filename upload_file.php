<?php
/**
 * API آپلود فایل و پیام صوتی
 * چت روم پیشرفته - نسخه 3.0
 * سازگار با PHP 8.5
 */

define('API_REQUEST', true);
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

$access = requireChatApiAccess('POST', 'upload', 10);
$userId = $access['user_id'];
$roomId = $access['room_id'];
$db = getDB();

if (isMembershipMuted($access['membership'])) {
    jsonError('شما در این روم ساکت شده‌اید', 403);
}

// بررسی وجود فایل
if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
    jsonError('فایلی انتخاب نشده است');
}

$file = $_FILES['file'];

// بررسی فایل و تشخیص نوع واقعی از روی محتوا
$inspection = inspectUploadedFile($file, postString('kind') === 'voice');
if ($inspection['error'] !== '') {
    jsonError($inspection['error']);
}

$caption = trim(postString('caption'));
if ($caption !== '') {
    $captionError = validateMessageText($caption);
    if ($captionError !== '') {
        jsonError($captionError);
    }
}

$filePath = null;

try {
    // سهمیه روزانه؛ فایل‌های حذف‌شده هم حساب می‌شوند چون هنوز روی دیسک هستند
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(file_size), 0) AS used
        FROM messages
        WHERE user_id = :user_id AND created_at > DATE_SUB(NOW(), INTERVAL :seconds SECOND)
    ");
    $stmt->execute([':user_id' => $userId, ':seconds' => 86400]);
    if ((int)$stmt->fetch()['used'] + (int)$file['size'] > UPLOAD_DAILY_QUOTA) {
        jsonError('سهمیه آپلود روزانه شما تمام شده است.', 429);
    }

    // ایجاد پوشه آپلود اگر وجود ندارد
    $subDir = date('Y/m/');
    $uploadDir = UPLOAD_DIR . $subDir;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
        error_log('Upload error: cannot create directory ' . $uploadDir);
        jsonError('خطا در ذخیره فایل', 500);
    }

    // پسوند از فهرست سفید نوع فایل می‌آید
    $newFileName = generateSecureFileName($inspection['info']['ext']);
    $filePath = $uploadDir . $newFileName;
    $relativePath = $subDir . $newFileName;
    $originalName = cleanOriginalFileName($file['name']);

    // انتقال فایل
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        $filePath = null;
        jsonError('خطا در ذخیره فایل', 500);
    }
    chmod($filePath, 0640);

    $db->beginTransaction();

    $stmt = $db->prepare("
        INSERT INTO messages (room_id, user_id, message, message_type, file_path, file_name, file_size, file_mime, created_at)
        VALUES (:room_id, :user_id, :message, :type, :path, :name, :size, :mime, NOW())
    ");
    $stmt->execute([
        ':room_id' => $roomId,
        ':user_id' => $userId,
        ':message' => $caption,
        ':type' => $inspection['info']['type'],
        ':path' => $relativePath,
        ':name' => $originalName,
        ':size' => $file['size'],
        ':mime' => $inspection['mime']
    ]);
    $messageId = (int)$db->lastInsertId();

    saveMessageMentions($messageId, $roomId, $caption);
    recordRoomEvent($roomId, 'message_new', $messageId, $userId);

    $stmt = $db->prepare("
        UPDATE users_rooms
        SET last_read_message_id = GREATEST(last_read_message_id, :id1),
            last_delivered_message_id = GREATEST(last_delivered_message_id, :id2)
        WHERE user_id = :user AND room_id = :room
    ");
    $stmt->execute([':id1' => $messageId, ':id2' => $messageId, ':user' => $userId, ':room' => $roomId]);

    $db->commit();

    jsonSuccess([
        'message_id' => $messageId,
        'file_url' => 'download.php?id=' . $messageId,
        'file_name' => $originalName,
        'file_type' => $inspection['info']['type']
    ], 'فایل آپلود شد');

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    // فایل بدون رکورد دیتابیس روی دیسک نماند
    if ($filePath !== null && is_file($filePath)) {
        unlink($filePath);
    }
    error_log("Upload file error: " . $e->getMessage());
    jsonError('خطا در آپلود فایل', 500);
}
