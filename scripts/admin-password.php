<?php
/**
 * ساخت ادمین یا تغییر رمز ادمین از خط فرمان
 *
 * استفاده:
 *   php scripts/admin-password.php USERNAME
 *
 * رمز عبور از ورودی خوانده می‌شود تا در تاریخچه shell باقی نماند.
 * برای ورودی غیرتعاملی می‌توان رمز را از stdin فرستاد.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once dirname(__DIR__) . '/functions.php';

$username = $argv[1] ?? '';
$usernameErrors = validateUsername($username);
if ($usernameErrors) {
    fwrite(STDERR, "Usage: php scripts/admin-password.php USERNAME\n" . implode("\n", $usernameErrors) . "\n");
    exit(1);
}

$isTty = function_exists('posix_isatty') && posix_isatty(STDIN);
if ($isTty) {
    fwrite(STDOUT, 'Password: ');
    shell_exec('stty -echo');
}
$password = rtrim((string)fgets(STDIN), "\r\n");
if ($isTty) {
    shell_exec('stty echo');
    fwrite(STDOUT, "\n");
}

$passwordErrors = validatePassword($password);
if ($passwordErrors) {
    fwrite(STDERR, implode("\n", $passwordErrors) . "\n");
    exit(1);
}

$db = getDB();

$stmt = $db->prepare("SELECT id FROM admins WHERE username = :username");
$stmt->execute([':username' => $username]);
$existing = $stmt->fetch();

if ($existing) {
    setAccountPassword('admin', (int)$existing['id'], $password);
    $stmt = $db->prepare("UPDATE admins SET is_active = 1 WHERE id = :id");
    $stmt->execute([':id' => $existing['id']]);
    $adminId = (int)$existing['id'];
    logSecurityEvent('admin_password_reset_cli', null, $adminId, ['username' => $username]);
    fwrite(STDOUT, "Password updated for admin '{$username}'.\n");
} else {
    $stmt = $db->prepare("INSERT INTO admins (username, password) VALUES (:username, :password)");
    $stmt->execute([':username' => $username, ':password' => hashPassword($password)]);
    $adminId = (int)$db->lastInsertId();
    logSecurityEvent('admin_created_cli', null, $adminId, ['username' => $username]);
    fwrite(STDOUT, "Admin '{$username}' created.\n");
}
