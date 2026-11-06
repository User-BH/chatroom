<?php
/**
 * اجرای مایگریشن‌های اجرا نشده
 *
 *   php scripts/migrate.php
 *
 * کاربر برنامه فقط دسترسی خواندن و نوشتن داده دارد؛ برای این دستور از کاربر مدیریتی استفاده کنید:
 *   CHATROOM_DB_USER=root CHATROOM_DB_PASS=... php scripts/migrate.php
 */

require_once __DIR__ . '/lib.php';

$db = getDB();

$db->exec("
    CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `name` VARCHAR(190) NOT NULL PRIMARY KEY,
        `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$applied = $db->query("SELECT name FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
$files = glob(dirname(__DIR__) . '/migrations/*.sql');
sort($files);

$count = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }

    // هر مایگریشن از دستورهای ساده‌ای تشکیل شده که با ; در پایان خط تمام می‌شوند
    $sql = preg_replace('/^\s*--.*$/m', '', str_replace("\r\n", "\n", file_get_contents($file)));
    foreach (preg_split('/;\s*$/m', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $db->exec($statement);
        }
    }

    $stmt = $db->prepare("INSERT INTO schema_migrations (name) VALUES (:name)");
    $stmt->execute([':name' => $name]);
    fwrite(STDOUT, "Applied $name\n");
    $count++;
}

fwrite(STDOUT, $count === 0 ? "Nothing to migrate.\n" : "Done: $count migration(s).\n");
