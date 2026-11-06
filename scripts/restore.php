<?php
/**
 * بازگردانی پشتیبان ساخته‌شده با scripts/backup.php
 *
 *   CHATROOM_DB_USER=root CHATROOM_DB_PASS=... php scripts/restore.php ARCHIVE.tar.gz --yes
 *
 * جدول‌های فعلی دیتابیس با محتوای پشتیبان جایگزین می‌شوند. کاربر برنامه دسترسی
 * ساخت و حذف جدول ندارد، پس این دستور با کاربر مدیریتی اجرا می‌شود.
 */

require_once __DIR__ . '/lib.php';

umask(0077);

$archive = $argv[1] ?? '';
if ($archive === '' || !is_file($archive) || !in_array('--yes', $argv, true)) {
    cliFail('Usage: php scripts/restore.php ARCHIVE.tar.gz --yes (replaces current data)');
}

$tempDir = makePrivateTempDir();
runCommand(['tar', '-xzf', $archive, '-C', $tempDir, 'database.sql', 'uploads.tar.gz']);

// اول دیتابیس؛ اگر شکست بخورد فایل‌ها دست نخورده می‌مانند
$defaultsFile = writeMysqlDefaultsFile($tempDir);
runCommand(
    ['mysql', '--defaults-extra-file=' . $defaultsFile, '--default-character-set=utf8mb4', DB_NAME],
    $tempDir . '/database.sql'
);

if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0750, true)) {
    cliFail('Cannot create upload directory');
}
runCommand(['tar', '-xzf', $tempDir . '/uploads.tar.gz', '-C', UPLOAD_DIR]);

fwrite(STDOUT, "Restored from $archive\nCheck that the web server user owns " . UPLOAD_DIR . "\n");
