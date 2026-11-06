<?php
/**
 * پشتیبان کامل از دیتابیس و فایل‌های آپلود شده
 *
 *   php scripts/backup.php /var/backups/chatroom [--keep=14]
 *
 * خروجی یک فایل chatroom-backup-*.tar.gz با دسترسی فقط برای مالک است.
 * متن پیام‌ها داخل این فایل رمزنگاری نشده است؛ آن را مثل خود دیتابیس نگهداری کنید.
 */

require_once __DIR__ . '/lib.php';

umask(0077);

$destination = $argv[1] ?? '';
if ($destination === '' || strpos($destination, '--') === 0) {
    cliFail('Usage: php scripts/backup.php DESTINATION_DIR [--keep=N]');
}

$keep = 0;
foreach (array_slice($argv, 2) as $option) {
    if (preg_match('/^--keep=(\d+)$/', $option, $match)) {
        $keep = (int)$match[1];
    } else {
        cliFail("Unknown option: $option");
    }
}

if (!is_dir($destination) && !mkdir($destination, 0700, true)) {
    cliFail("Cannot create $destination");
}

$tempDir = makePrivateTempDir();
$defaultsFile = writeMysqlDefaultsFile($tempDir);

$dump = ['mysqldump', '--defaults-extra-file=' . $defaultsFile, '--single-transaction', '--no-tablespaces', '--default-character-set=utf8mb4'];
// mysqldump نسخه 8 روی سرورهای قدیمی‌تر و MariaDB بدون این گزینه خطا می‌دهد
if (strpos(runCommand(['mysqldump', '--help']), 'column-statistics') !== false) {
    $dump[] = '--column-statistics=0';
}
$dump[] = DB_NAME;
runCommand($dump, null, $tempDir . '/database.sql');

runCommand(['tar', '-czf', $tempDir . '/uploads.tar.gz', '-C', UPLOAD_DIR, '.']);

$archive = rtrim($destination, '/') . '/chatroom-backup-' . date('Ymd-His') . '.tar.gz';
runCommand(['tar', '-czf', $archive, '-C', $tempDir, 'database.sql', 'uploads.tar.gz']);
fwrite(STDOUT, "Backup: $archive\n");

// حذف پشتیبان‌های قدیمی فقط وقتی --keep داده شده و پشتیبان جدید ساخته شده است
if ($keep > 0) {
    $backups = glob(rtrim($destination, '/') . '/chatroom-backup-*.tar.gz');
    sort($backups);
    foreach (array_slice($backups, 0, max(0, count($backups) - $keep)) as $old) {
        unlink($old);
        fwrite(STDOUT, "Removed old backup: $old\n");
    }
}
