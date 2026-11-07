<?php
/**
 * تست Nginx و PHP-FPM واقعی با پیکربندی پروژه
 *
 *   docker compose up -d --build && docker compose run --rm tests php tests/webserver.php
 *
 * قوانین دسترسی وب‌سرور و مسیر کامل ورود، پیام، آپلود و پنل را روی همان پشته می‌سنجد.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require __DIR__ . '/Client.php';

$base = getenv('CHATROOM_TEST_WEB_BASE');
$dbHost = getenv('CHATROOM_TEST_DB_HOST');
if (!$base || !$dbHost) {
    fwrite(STDERR, "Set CHATROOM_TEST_WEB_BASE and CHATROOM_TEST_DB_* (see docker-compose.yml).\n");
    exit(2);
}

$work = sys_get_temp_dir() . '/chatroom-web-' . bin2hex(random_bytes(4));
mkdir($work);
$newClient = function () use ($base, $work) {
    return new Client($base, $work);
};

$pdo = new PDO("mysql:host=$dbHost;dbname=chatroom_secure;charset=utf8mb4", getenv('CHATROOM_TEST_DB_USER'), (string)getenv('CHATROOM_TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// داده تست با نام تصادفی تا اجرای دوباره روی همان دیتابیس هم کار کند
$tag = bin2hex(random_bytes(3));
$userPass = 'Web' . bin2hex(random_bytes(8)) . 'A1';
$hash = password_hash($userPass, PASSWORD_BCRYPT, ['cost' => 4]);
$pdo->prepare("INSERT INTO users (username, password, name) VALUES (?, ?, ?)")->execute(["web_$tag", $hash, 'کاربر وب']);
$userId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO admins (username, password) VALUES (?, ?)")->execute(["webadmin_$tag", $hash]);
$pdo->prepare("INSERT INTO rooms (name) VALUES (?)")->execute(["WebRoom $tag"]);
$roomId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users_rooms (user_id, room_id) VALUES (?, ?)")->execute([$userId, $roomId]);

$anon = $newClient();

section('مسیرهای ممنوع');
$forbidden = [
    'config.php', 'db.php', 'functions.php', 'config.local.php', 'config.local.example.php',
    'config.php/x.php', 'db.php/x.php', 'functions.php/x',
    'database.sql', 'README.md', 'docker-compose.yml', '.gitignore', '.dockerignore', '.git/config',
    'assets/fonts/README.md', 'assets/fonts/OFL.txt',
    'includes/init.php', 'scripts/backup.php', 'scripts/migrate.php', 'scripts/admin-password.php',
    'migrations/001_security_hardening.sql', 'tests/run.php', 'deploy/nginx-chatroom.conf.example', 'deploy/php-fpm-chatroom.conf.example', 'scripts/cleanup.php', 'includes/chat.php',
    'docker/php.ini', 'logs/error.log', 'logs/', 'uploads/', 'uploads/x.php', 'uploads/x.jpg/y.php',
];
foreach ($forbidden as $path) {
    $status = $anon->req('GET', $path)['status'];
    check("/$path در دسترس نیست", $status === 403 || $status === 404, (string)$status);
}
foreach (['assets/', 'assets/fonts/', 'assets/js/'] as $path) {
    $r = $anon->req('GET', $path);
    check("/$path فهرست فایل نشان نمی‌دهد", $r['status'] === 403 || $r['status'] === 404, (string)$r['status']);
}
check('مسیر جعلی PHP اجرا نمی‌شود', $anon->req('GET', 'assets/css/admin.css/x.php')['status'] === 404);

section('مسیرهای مجاز');
foreach (['index.php', 'login.php', 'admin-login.php', 'assets/css/fonts.css', 'assets/css/admin.css', 'assets/js/chat-app.js', 'assets/fonts/Vazirmatn-Regular.woff2'] as $path) {
    $r = $anon->req('GET', $path);
    check("/$path باز می‌شود", $r['status'] === 200, (string)$r['status']);
}
check('ریشه سایت باز می‌شود', $anon->req('GET', '')['status'] === 200);
$r = $anon->req('GET', 'health.php');
check('health روی PHP-FPM سالم است', $r['status'] === 200 && ($r['json']['data']['status'] ?? '') === 'ok', $r['status'] . ' ' . $r['body']);

section('هدرها');
$r = $anon->req('GET', 'login.php');
check('CSP روی صفحه PHP', stripos($r['headers'], 'Content-Security-Policy') !== false);
check('نسخه PHP لو نمی‌رود', stripos($r['headers'], 'X-Powered-By') === false);
check('نسخه Nginx لو نمی‌رود', preg_match('/^Server: nginx\s*$/mi', $r['headers']) === 1, $r['headers']);
$r = $anon->req('GET', 'assets/js/chat-app.js');
check('nosniff روی فایل ایستا', stripos($r['headers'], 'nosniff') !== false);
$r = $anon->req('GET', 'assets/fonts/Vazirmatn-Regular.woff2');
check('نوع فونت درست است', stripos($r['headers'], 'font/woff2') !== false, $r['headers']);

section('مسیر کامل کاربر');
$user = $newClient();
check('ورود کاربر', $user->login("web_$tag", $userPass)['status'] === 302);
check('صفحه چت', strpos($user->req('GET', 'chat.php')['body'], 'chat-app.js') !== false);
$r = $user->api('POST', 'send_message.php', ['message' => 'سلام از Nginx 😀']);
check('ارسال پیام', $r['status'] === 200, $r['body']);
$r = $user->api('GET', 'get_messages.php?last_id=0');
check('دریافت پیام فارسی و ایموجی', strpos($r['body'], 'سلام از Nginx 😀') !== false);

section('آپلود روی PHP-FPM');
$gif = "$work/a.gif";
file_put_contents($gif, "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;<?php echo 'PWNED'; ?>");
$r = $user->api('POST', 'upload_file.php', ['file' => new CURLFile($gif, 'image/gif', 'evil.php')]);
check('www-data می‌تواند آپلود کند', $r['status'] === 200, $r['body']);
$messageId = (int)($r['json']['data']['message_id'] ?? 0);
$stored = $pdo->query("SELECT file_path FROM messages WHERE id = $messageId")->fetchColumn();
check('پسوند امن ذخیره شد', (bool)preg_match('/\.gif$/', (string)$stored), (string)$stored);
$direct = $anon->req('GET', 'uploads/' . $stored)['status'];
check('آدرس مستقیم فایل بسته است', $direct === 403 || $direct === 404, (string)$direct);
$r = $user->req('GET', 'download.php?id=' . $messageId);
check('دانلود کنترل‌شده کار می‌کند', $r['status'] === 200 && strpos($r['body'], 'GIF89a') === 0, (string)$r['status']);
check('محتوای PHP اجرا نشده است', strpos($r['body'], 'PWNED') !== false && strpos($r['body'], '<?php') !== false);
check('دانلود بدون ورود ممکن نیست', $anon->req('GET', 'download.php?id=' . $messageId)['status'] === 401);
$big = "$work/big.gif";
file_put_contents($big, 'GIF89a' . str_repeat("\0", 11 * 1024 * 1024));
$r = $user->api('POST', 'upload_file.php', ['file' => new CURLFile($big, 'image/gif', 'big.gif')]);
check('فایل 11 مگابایتی را برنامه رد می‌کند', $r['status'] === 400, (string)$r['status']);
file_put_contents($big, 'GIF89a' . str_repeat("\0", 13 * 1024 * 1024));
$r = $user->api('POST', 'upload_file.php', ['file' => new CURLFile($big, 'image/gif', 'big.gif')]);
check('فایل 13 مگابایتی را Nginx رد می‌کند', $r['status'] === 413, (string)$r['status']);

section('SSE روی Nginx و پول جدا');
$cursor = (int)$user->api('GET', 'get_messages.php?last_id=0')['json']['data']['cursor'];
$received = '';
$headersText = '';
$stream = curl_init("$base/events.php?room_id=$roomId&cursor=$cursor");
curl_setopt_array($stream, [
    CURLOPT_COOKIEFILE => $user->jarPath(),
    CURLOPT_USERAGENT => 'chatroom-test',
    CURLOPT_NOPROXY => '*',
    CURLOPT_TIMEOUT => 6,
    CURLOPT_HEADERFUNCTION => function ($h, $line) use (&$headersText) {
        $headersText .= $line;
        return strlen($line);
    },
    CURLOPT_WRITEFUNCTION => function ($h, $chunk) use (&$received) {
        $received .= $chunk;
        return strlen($chunk);
    },
]);
$multi = curl_multi_init();
curl_multi_add_handle($multi, $stream);
$marker = 'SSE-' . bin2hex(random_bytes(4));
$sentAt = null;
$arrivedAfter = null;
$started = monotonicSeconds();
do {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi, 0.05);
    if ($sentAt === null && monotonicSeconds() - $started > 1) {
        $t = monotonicSeconds();
        $user->api('POST', 'send_message.php', ['message' => $marker]);
        $sendSeconds = monotonicSeconds() - $t;
        $sentAt = monotonicSeconds();
    }
    if ($arrivedAfter === null && $sentAt !== null && strpos($received, $marker) !== false) {
        $arrivedAfter = monotonicSeconds() - $sentAt;
    }
} while ($running && monotonicSeconds() - $started < 7);
curl_multi_remove_handle($multi, $stream);
check('ارسال هم‌زمان با SSE باز سریع است', ($sendSeconds ?? 99) < 1, sprintf('%.2fs', $sendSeconds ?? 99));
check('پاسخ SSE نوع درست دارد', stripos($headersText, 'text/event-stream') !== false, $headersText);
check('پیام با SSE می‌رسد', $arrivedAfter !== null, 'cursor=' . $cursor . ' ' . curl_getinfo($stream, CURLINFO_RESPONSE_CODE) . ' ' . substr(str_replace("
", '|', $received), 0, 900));
check('پیام زیر 3 ثانیه می‌رسد', $arrivedAfter !== null && $arrivedAfter < 3, (string)$arrivedAfter);
check('رویداد شناسه دارد', preg_match('/^id: \d+$/m', $received) === 1);
check('SSE بدون ورود 401 است', $anon->req('GET', 'events.php')['status'] === 401);

// پر شدن SSE نباید پول اصلی را بگیرد
$multi = curl_multi_init();
$handles = [];
for ($i = 0; $i < 30; $i++) {
    $h = curl_init("$base/events.php?room_id=$roomId&cursor=$cursor");
    curl_setopt_array($h, [
        CURLOPT_COOKIEFILE => $user->jarPath(),
        CURLOPT_USERAGENT => 'chatroom-test',
        CURLOPT_NOPROXY => '*',
        CURLOPT_TIMEOUT => 5,
        CURLOPT_WRITEFUNCTION => function ($h, $chunk) {
            return strlen($chunk);
        },
    ]);
    curl_multi_add_handle($multi, $h);
    $handles[] = $h;
}
$started = monotonicSeconds();
while (monotonicSeconds() - $started < 2) {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi, 0.05);
}
$threads = (int)$pdo->query("SHOW STATUS LIKE 'Threads_connected'")->fetch(PDO::FETCH_NUM)[1];
check('SSE بیکار اتصال دیتابیس نگه نمی‌دارد', $threads < 15, (string)$threads);
$t = monotonicSeconds();
$r = $anon->req('GET', 'login.php');
$elapsed = monotonicSeconds() - $t;
check('با 30 اتصال SSE باز، صفحات عادی سریع می‌مانند', $r['status'] === 200 && $elapsed < 1, sprintf('%.2fs', $elapsed));
foreach ($handles as $h) {
    curl_multi_remove_handle($multi, $h);
}

section('فایل‌های PWA روی Nginx');
$r = $anon->req('GET', 'manifest.webmanifest');
check('manifest سرو می‌شود', $r['status'] === 200 && stripos($r['headers'], 'manifest') !== false, $r['headers']);
$r = $anon->req('GET', 'sw.js');
check('sw.js با no-cache سرو می‌شود', $r['status'] === 200 && stripos($r['headers'], 'no-cache') !== false && stripos($r['headers'], 'javascript') !== false, $r['headers']);
check('offline.html سرو می‌شود', $anon->req('GET', 'offline.html')['status'] === 200);

section('مسیر کامل ادمین');
$admin = $newClient();
$t = $admin->token('admin-login.php');
$r = $admin->req('POST', 'admin-login.php', ['csrf_token' => $t, 'username' => "webadmin_$tag", 'password' => $userPass]);
check('ورود ادمین', $r['status'] === 302);
foreach (['admin.php', 'admin-users.php', "admin-user-edit.php?id=$userId", 'admin-rooms.php', "admin-room-edit.php?id=$roomId", "admin-room-members.php?id=$roomId", 'admin-security-logs.php', 'admin-assign-user.php', 'admin-create-user.php', 'admin-create-room.php', 'admin-change-password.php'] as $page) {
    $r = $admin->req('GET', $page);
    check("$page باز می‌شود", $r['status'] === 200 && strpos($r['body'], 'admin-logout.php') !== false, (string)$r['status']);
}
$adminToken = $admin->token('admin-create-user.php');
$admin->req('POST', "admin-room-members.php?id=$roomId", ['csrf_token' => $adminToken, 'action' => 'remove', 'user_id' => $userId]);
check('حذف عضو از پنل روی کاربر اثر می‌کند', $user->api('GET', 'get_messages.php?last_id=0')['status'] === 401);

echo "\n--------------------------\nPASS: $passed   FAIL: $failed\n";
exit($failed === 0 ? 0 : 1);
