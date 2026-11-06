<?php
/**
 * API آپلود فایل
 * چت روم پیشرفته - نسخه 2.0
 * سازگار با PHP 7.4+
 */

require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

// فقط POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('متد نامعتبر', 405);
}

// بررسی ورود
if (!isUserLoggedIn()) {
    jsonError('لطفاً وارد شوید', 401);
}

// اعتبارسنجی CSRF
if (!validateCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
    jsonError('درخواست نامعتبر', 403);
}

// Rate Limiting
if (!checkRateLimit('upload_' . $_SESSION['user_id'], 10, 60)) {
    jsonError('تعداد آپلودها بیش از حد مجاز است', 429);
}

$userId = $_SESSION['user_id'];
$roomId = $_SESSION['room_id'];
$db = getDB();

// بررسی وجود فایل
if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
    jsonError('فایلی انتخاب نشده است');
}

$file = $_FILES['file'];

// تشخیص نوع فایل
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);

// تعیین نوع
$fileType = 'file';
if (in_array($mimeType, getAllowedImageTypes())) {
    $fileType = 'image';
} elseif (in_array($mimeType, getAllowedAudioTypes())) {
    $fileType = 'audio';
} elseif (in_array($mimeType, getAllowedFileTypes())) {
    $fileType = 'file';
} else {
    jsonError('نوع فایل مجاز نیست');
}

// اعتبارسنجی فایل
$errors = validateUploadedFile($file, $fileType);
if (!empty($errors)) {
    jsonError(implode(', ', $errors));
}

try {
    // بررسی عضویت در روم
    $stmt = $db->prepare("SELECT is_muted FROM users_rooms WHERE user_id = :user_id AND room_id = :room_id");
    $stmt->execute([':user_id' => $userId, ':room_id' => $roomId]);
    $membership = $stmt->fetch();
    
    if (!$membership) {
        jsonError('شما عضو این روم نیستید', 403);
    }
    
    if ($membership['is_muted']) {
        jsonError('شما در این روم ساکت شده‌اید', 403);
    }
    
    // ایجاد پوشه آپلود اگر وجود ندارد
    $uploadDir = UPLOAD_DIR . date('Y/m/');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // تولید نام فایل امن
    $newFileName = generateSecureFileName($file['name']);
    $filePath = $uploadDir . $newFileName;
    $relativePath = 'uploads/' . date('Y/m/') . $newFileName;
    
    // انتقال فایل
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        jsonError('خطا در ذخیره فایل');
    }
    
    // ذخیره پیام در دیتابیس
    $caption = isset($_POST['caption']) ? cleanInput($_POST['caption']) : '';
    
    $stmt = $db->prepare("
        INSERT INTO messages (room_id, user_id, message, message_type, file_path, file_name, file_size, created_at)
        VALUES (:room_id, :user_id, :message, :type, :path, :name, :size, NOW())
    ");
    
    $result = $stmt->execute([
        ':room_id' => $roomId,
        ':user_id' => $userId,
        ':message' => $caption,
        ':type' => $fileType,
        ':path' => $relativePath,
        ':name' => $file['name'],
        ':size' => $file['size']
    ]);
    
    if ($result) {
        $messageId = $db->lastInsertId();
        
        jsonSuccess([
            'message_id' => (int)$messageId,
            'file_path' => $relativePath,
            'file_name' => $file['name'],
            'file_type' => $fileType
        ], 'فایل آپلود شد');
    } else {
        // حذف فایل در صورت خطا
        unlink($filePath);
        jsonError('خطا در ذخیره اطلاعات فایل');
    }
    
} catch (PDOException $e) {
    error_log("Upload file error: " . $e->getMessage());
    jsonError('خطا در آپلود فایل', 500);
}
