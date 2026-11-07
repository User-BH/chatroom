<?php
/**
 * حذف نهایی پیام‌ها و فایل‌ها طبق سیاست نگهداری
 *
 *   php scripts/cleanup.php [--dry-run]
 *
 * - پیام‌های حذف‌شده پس از DELETED_MESSAGE_PURGE_DAYS روز
 * - پیام‌های قدیمی‌تر از retention_days روم، یا MESSAGE_RETENTION_DAYS اگر برای روم تعیین نشده
 *
 * این دستور را روزانه با cron اجرا کنید.
 */

require_once __DIR__ . '/lib.php';

$dryRun = in_array('--dry-run', $argv, true);
$db = getDB();

$condition = "
    (m.is_deleted = 1 AND m.deleted_at < DATE_SUB(NOW(), INTERVAL :purge DAY))
    OR (COALESCE(r.retention_days, :default1) > 0
        AND m.created_at < DATE_SUB(NOW(), INTERVAL COALESCE(r.retention_days, :default2) DAY))
";
$params = [
    ':purge' => DELETED_MESSAGE_PURGE_DAYS,
    ':default1' => MESSAGE_RETENTION_DAYS,
    ':default2' => MESSAGE_RETENTION_DAYS
];

$stmt = $db->prepare("SELECT m.id, m.file_path FROM messages m JOIN rooms r ON r.id = m.room_id WHERE $condition");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$files = 0;
$baseDir = realpath(UPLOAD_DIR);

foreach (array_chunk($rows, 500) as $chunk) {
    if ($dryRun) {
        continue;
    }
    $ids = implode(',', array_map('intval', array_column($chunk, 'id')));
    // ابتدا رکورد پاک می‌شود؛ فایل بدون رکورد دیگر از هیچ مسیری قابل دریافت نیست
    $db->exec("DELETE FROM messages WHERE id IN ($ids)");

    foreach ($chunk as $row) {
        if ($row['file_path'] === null || $baseDir === false) {
            continue;
        }
        $path = realpath(UPLOAD_DIR . $row['file_path']);
        if ($path !== false && strpos($path, $baseDir . DIRECTORY_SEPARATOR) === 0 && unlink($path)) {
            $files++;
        }
    }
}

$fileCount = count(array_filter(array_column($rows, 'file_path')));
if ($dryRun) {
    fwrite(STDOUT, 'Would remove ' . count($rows) . " message(s) and $fileCount file(s).\n");
} else {
    if ($rows) {
        logSecurityEvent('messages_purged', null, null, ['messages' => count($rows), 'files' => $files]);
    }
    fwrite(STDOUT, 'Removed ' . count($rows) . " message(s) and $files file(s).\n");
}
