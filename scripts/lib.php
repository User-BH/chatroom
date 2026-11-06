<?php
/**
 * توابع مشترک اسکریپت‌های خط فرمان
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once dirname(__DIR__) . '/functions.php';

/**
 * پایان اسکریپت با پیام خطا
 * @param string $message
 */
function cliFail($message) {
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * اجرای دستور بدون shell تا هیچ مقداری به عنوان دستور تفسیر نشود
 * @param array $command
 * @param string|null $stdinFile
 * @param string|null $stdoutFile
 */
function runCommand(array $command, $stdinFile = null, $stdoutFile = null) {
    $descriptors = [
        0 => $stdinFile !== null ? ['file', $stdinFile, 'r'] : ['file', '/dev/null', 'r'],
        1 => $stdoutFile !== null ? ['file', $stdoutFile, 'w'] : ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        cliFail('Cannot run: ' . $command[0]);
    }
    $output = isset($pipes[1]) ? stream_get_contents($pipes[1]) : '';
    $errors = stream_get_contents($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        cliFail($command[0] . " failed (exit $exitCode):\n" . $errors);
    }
    return $output;
}

/**
 * پوشه موقت خصوصی که در پایان اسکریپت حذف می‌شود
 * @return string
 */
function makePrivateTempDir() {
    $dir = sys_get_temp_dir() . '/chatroom-' . bin2hex(random_bytes(8));
    if (!mkdir($dir, 0700)) {
        cliFail('Cannot create temporary directory');
    }
    register_shutdown_function(function () use ($dir) {
        foreach (glob($dir . '/*') as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    });
    return $dir;
}

/**
 * فایل تنظیمات کلاینت MySQL تا رمز در فهرست پردازش‌ها دیده نشود
 * @param string $dir
 * @return string
 */
function writeMysqlDefaultsFile($dir) {
    $quote = function ($value) {
        return '"' . addcslashes((string)$value, "\\\"") . '"';
    };
    $file = $dir . '/client.cnf';
    file_put_contents($file, "[client]\nhost=" . $quote(DB_HOST) . "\nuser=" . $quote(DB_USER) . "\npassword=" . $quote(DB_PASS) . "\n");
    chmod($file, 0600);
    return $file;
}
