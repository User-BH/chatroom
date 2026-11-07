<?php
/**
 * تست end-to-end روی MySQL واقعی
 *
 *   CHATROOM_TEST_DB_HOST=127.0.0.1 CHATROOM_TEST_DB_USER=root CHATROOM_TEST_DB_PASS=... php tests/run.php
 *
 * یک دیتابیس و کاربر موقت با پیشوند chatroom_test_ ساخته و در پایان حذف می‌شود.
 * برنامه با کاربر محدود (همان دسترسی‌های README) روی سرور داخلی PHP اجرا می‌شود
 * تا کمبود دسترسی هم کشف شود. به دیتابیس‌های دیگر دست زده نمی‌شود.
 * در CI با .github/workflows/ci.yml اجرا می‌شود.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

$dbHost = getenv('CHATROOM_TEST_DB_HOST');
$dbAdminUser = getenv('CHATROOM_TEST_DB_USER');
$dbAdminPass = getenv('CHATROOM_TEST_DB_PASS');
if (!$dbHost || !$dbAdminUser) {
    fwrite(STDERR, "Set CHATROOM_TEST_DB_HOST, CHATROOM_TEST_DB_USER and CHATROOM_TEST_DB_PASS.\n");
    exit(2);
}

date_default_timezone_set('Asia/Tehran');

$root = dirname(__DIR__);
$suffix = bin2hex(random_bytes(4));
$work = sys_get_temp_dir() . '/chatroom-test-' . $suffix;
$app = $work . '/app';
$port = 18080 + random_int(0, 900);
$base = "http://127.0.0.1:$port";
$dbName = 'chatroom_test_' . $suffix;
$dbUser = 'ct_' . $suffix;
$dbPass = bin2hex(random_bytes(12));

// ---------- ساخت کپی موقت ----------
function copyTree($from, $to) {
    mkdir($to, 0777, true);
    foreach (scandir($from) as $name) {
        if (in_array($name, ['.', '..', 'tests', '.git', '.github'], true)) {
            continue;
        }
        $src = "$from/$name";
        is_dir($src) ? copyTree($src, "$to/$name") : copy($src, "$to/$name");
    }
}
copyTree($root, $app);
mkdir("$work/sessions");

// ---------- ساخت دیتابیس از database.sql واقعی ----------
$admin = new PDO("mysql:host=$dbHost;charset=utf8mb4", $dbAdminUser, (string)$dbAdminPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schema = str_replace(['chatroom_secure', "\r\n"], [$dbName, "\n"], file_get_contents("$root/database.sql"));
$schema = preg_replace('/^\s*--.*$/m', '', $schema);
foreach (preg_split('/;\s*$/m', $schema) as $statement) {
    if (trim($statement) !== '') {
        $admin->exec($statement);
    }
}
$admin->exec("CREATE USER '$dbUser'@'%' IDENTIFIED BY '$dbPass'");
$admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `$dbName`.* TO '$dbUser'@'%'");

file_put_contents("$app/config.local.php", '<?php return ' . var_export([
    'DEBUG_MODE' => false,
    'ERROR_LOG_FILE' => "$work/error.log",
    'DB_HOST' => $dbHost,
    'DB_NAME' => $dbName,
    'DB_USER' => $dbUser,
    'DB_PASS' => $dbPass,
], true) . ';');

// ---------- داده تست ----------
$pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbAdminUser, (string)$dbAdminPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET time_zone = '" . date('P') . "'");

$pw = 'Str0ngPass1';
$hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 4]);
$insUser = $pdo->prepare("INSERT INTO users (id, username, password, name, is_active) VALUES (?, ?, ?, ?, ?)");
foreach ([[1, 'alice', 'آلیس'], [2, 'bob', 'باب'], [3, 'carol', 'کارول'], [4, 'dave', 'دیو'], [6, 'erin', 'ارین'], [7, 'frank', 'فرانک'], [8, 'grace', 'گریس']] as [$id, $u, $n]) {
    $insUser->execute([$id, $u, $hash, $n, 1]);
}
$insUser->execute([5, 'inactive', $hash, 'غیرفعال', 0]);
$pdo->exec("INSERT INTO rooms (id, name) VALUES (1, 'SecretRoomOne'), (2, 'SecretRoomTwo'), (3, 'ClosedRoom')");
$pdo->exec("UPDATE rooms SET is_active = 0 WHERE id = 3");
$pdo->exec("INSERT INTO users_rooms (user_id, room_id, role) VALUES
    (1, 1, 'member'), (1, 2, 'member'),
    (2, 1, 'member'),
    (3, 2, 'moderator'),
    (4, 1, 'member'),
    (6, 2, 'member'),
    (7, 1, 'member'),
    (8, 1, 'member')");

// ---------- ساخت ادمین با اسکریپت واقعی ----------
$php = PHP_BINARY;
$cmd = escapeshellarg($php) . ' ' . escapeshellarg("$app/scripts/admin-password.php") . ' rootadmin';
$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
fwrite($pipes[0], "$pw\n");
fclose($pipes[0]);
$cliOut = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
$cliExit = proc_close($proc);

// ---------- اجرای سرور ----------
$serverCmd = [
    $php, '-d', "session.save_path=$work/sessions",
    '-d', 'upload_max_filesize=12M', '-d', 'post_max_size=13M',
    '-S', "127.0.0.1:$port", '-t', $app
];
$server = proc_open($serverCmd, [0 => ['pipe', 'r'], 1 => ['file', "$work/server.log", 'a'], 2 => ['file', "$work/server.log", 'a']], $serverPipes, $app);
for ($i = 0; $i < 50; $i++) {
    $s = @fsockopen('127.0.0.1', $port);
    if ($s) {
        fclose($s);
        break;
    }
    usleep(100000);
}

// پاکسازی حتی وقتی تست وسط کار شکست بخورد
register_shutdown_function(function () use ($server, $admin, $dbName, $dbUser, $work) {
    if (is_resource($server)) {
        proc_terminate($server);
    }
    $admin->exec("DROP DATABASE IF EXISTS `$dbName`");
    $admin->exec("DROP USER IF EXISTS '$dbUser'@'%'");
    $rm = function ($dir) use (&$rm) {
        foreach (scandir($dir) as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            $p = "$dir/$n";
            is_dir($p) ? $rm($p) : @unlink($p);
        }
        @rmdir($dir);
    };
    $rm($work);
});

require __DIR__ . '/Client.php';

$newClient = function () use ($base, $work) {
    return new Client($base, $work);
};

// ---------- تست‌ها ----------
section('نصب و ادمین');
check('اسکریپت CLI ادمین می‌سازد', $cliExit === 0 && strpos($cliOut, 'created') !== false, $cliOut);
$weak = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $wp);
fwrite($wp[0], "weak\n");
fclose($wp[0]);
stream_get_contents($wp[1]);
stream_get_contents($wp[2]);
check('اسکریپت رمز ضعیف را رد می‌کند', proc_close($weak) !== 0);
$sqlText = file_get_contents("$root/database.sql");
check('database.sql ادمین پیش‌فرض ندارد', stripos($sqlText, 'INSERT INTO `admins`') === false);
check('database.sql به SET GLOBAL وابسته نیست', stripos($sqlText, 'SET GLOBAL') === false);
$a = $newClient();
$t = $a->token('admin-login.php');
$r = $a->req('POST', 'admin-login.php', ['csrf_token' => $t, 'username' => 'rootadmin', 'password' => $pw]);
check('ادمین با رمز جدید وارد می‌شود', $r['status'] === 302 && strpos($r['headers'], 'admin.php') !== false, (string)$r['status']);
$r = $a->req('GET', 'admin.php');
check('داشبورد ادمین باز می‌شود', $r['status'] === 200 && strpos($r['body'], 'کل کاربران') !== false, (string)$r['status']);
check('خروج ادمین به شکل فرم POST است', strpos($r['body'], 'action="admin-logout.php"') !== false);
$r = $a->req('GET', 'admin-logout.php');
check('GET روی خروج ادمین، ادمین را بیرون نمی‌کند', $a->req('GET', 'admin.php')['status'] === 200);
$adminToken = $a->token('admin-create-user.php');
$r = $a->req('POST', 'admin-create-user.php', ['csrf_token' => $adminToken, 'name' => 'x', 'username' => 'newuser', 'password' => 'weak']);
check('ادمین: رمز ضعیف رد می‌شود', strpos($r['body'], 'alert-error') !== false);
$r = $a->req('POST', 'admin-create-user.php', ['csrf_token' => $adminToken, 'name' => 'Ali\\Reza', 'username' => 'newuser', 'password' => $pw, 'is_active' => '1']);
check('ادمین: ساخت کاربر کار می‌کند', strpos($r['body'], 'alert-success') !== false);
check('ادمین: بک‌اسلش نام حذف نمی‌شود', $pdo->query("SELECT name FROM users WHERE username='newuser'")->fetchColumn() === 'Ali\\Reza');
$r = $a->req('POST', 'admin-create-room.php', ['csrf_token' => $adminToken, 'name' => str_repeat('x', 150)]);
check('ادمین: نام خیلی طولانی روم رد می‌شود', strpos($r['body'], 'alert-error') !== false);
$r = $a->req('POST', 'admin-assign-user.php', http_build_query(['csrf_token' => $adminToken, 'user_id' => ['1'], 'room_id' => '1', 'role' => ['x']]));
check('ادمین: ورودی آرایه‌ای خطای 500 نمی‌دهد', $r['status'] === 200, (string)$r['status']);
$pdo->exec("UPDATE admins SET is_active = 0");
check('ادمین غیرفعال‌شده فوراً بیرون می‌رود', $a->req('GET', 'admin.php')['status'] === 302);
$pdo->exec("UPDATE admins SET is_active = 1");

section('افشای اطلاعات در ورود');
$c = $newClient();
$r = $c->req('GET', 'login.php');
check('صفحه ورود نام روم‌ها را نشان نمی‌دهد', strpos($r['body'], 'SecretRoom') === false);
check('هدر CSP فعال است', stripos($r['headers'], "script-src 'self'") !== false && stripos($r['headers'], "connect-src 'self'") !== false);
check('X-XSS-Protection منسوخ حذف شده', stripos($r['headers'], 'X-XSS-Protection') === false);
$msg = function ($client, $u, $p) {
    $t = $client->token();
    $r = $client->req('POST', 'login.php', ['csrf_token' => $t, 'step' => 'password', 'username' => $u, 'password' => $p]);
    preg_match('/class="error-message">.*?<\/svg>\s*(.*?)\s*<\/div>/su', $r['body'], $m);
    return $m[1] ?? '';
};
$m1 = $msg($newClient(), 'nosuchuser', 'whatever1');
$m2 = $msg($newClient(), 'inactive', $pw);
$m3 = $msg($newClient(), 'erin', 'wrongpass1');
check('پیام خطا برای کاربر ناموجود/غیرفعال/رمز غلط یکسان است', $m1 !== '' && $m1 === $m2 && $m2 === $m3, "$m1 | $m2 | $m3");
$r = $c->req('POST', 'login.php', http_build_query(['csrf_token' => $c->token(), 'username' => ['x'], 'password' => ['y']]));
check('username آرایه‌ای خطای 500 نمی‌دهد', $r['status'] === 200, (string)$r['status']);
$r = $c->req('POST', 'login.php', ['csrf_token' => $c->token(), 'username' => str_repeat('u', 300), 'password' => 'x']);
check('نام کاربری خیلی بلند خطای 500 نمی‌دهد', $r['status'] === 200, (string)$r['status']);

section('محدودیت تلاش ورود');
$pdo->exec("DELETE FROM login_attempts");
for ($i = 0; $i < 5; $i++) {
    $bf = $newClient(); // session تازه: محدودیت قدیمی با همین کار دور زده می‌شد
    $bf->login('bob', 'wrongpass' . $i, null, ['X-Forwarded-For: 10.9.8.' . $i, 'CF-Connecting-IP: 10.9.9.' . $i]);
}
$bf = $newClient();
$r = $bf->login('bob', $pw, null, ['X-Forwarded-For: 203.0.113.77']);
check('session تازه و جعل X-Forwarded-For محدودیت را دور نمی‌زند', $r['status'] === 200 && strpos($r['body'], 'بیش از حد') !== false, (string)$r['status']);
$ips = $pdo->query("SELECT DISTINCT ip_address FROM login_attempts WHERE username='bob'")->fetchAll(PDO::FETCH_COLUMN);
check('IP ثبت‌شده از REMOTE_ADDR است', $ips === ['127.0.0.1'], implode(',', $ips));
$other = $newClient();
check('کاربر دیگر روی همان IP مسدود نمی‌شود', $other->login('dave')['status'] === 302);
$pdo->exec("DELETE FROM login_attempts");

section('ورود دو مرحله‌ای');
$alice = $newClient();
$r = $alice->login('alice');
check('چند رومی: فقط روم‌های خودش را می‌بیند', strpos($r['body'], 'SecretRoomOne') !== false && strpos($r['body'], 'SecretRoomTwo') !== false && strpos($r['body'], 'ClosedRoom') === false);
check('قبل از انتخاب روم وارد چت نشده', $alice->req('GET', 'chat.php')['status'] === 302);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $r['body'], $m);
$r = $alice->req('POST', 'login.php', ['csrf_token' => $m[1], 'step' => 'room', 'room_id' => 3]);
check('انتخاب روم غیرمجاز رد می‌شود', $r['status'] === 200 && strpos($r['body'], 'یکی از روم') !== false);
$anon = $newClient();
$r = $anon->req('POST', 'login.php', ['csrf_token' => $anon->token(), 'step' => 'room', 'room_id' => 1]);
check('پرش مستقیم به مرحله روم بدون رمز ممکن نیست', $r['status'] === 200 && $anon->req('GET', 'chat.php')['status'] === 302);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $alice->req('GET', 'login.php')['body'], $m);
$r = $alice->req('POST', 'login.php', ['csrf_token' => $m[1], 'step' => 'room', 'room_id' => 1]);
check('انتخاب روم مجاز وارد چت می‌شود', $r['status'] === 302);
$alice->loadChat();
$bob = $newClient();
check('تک رومی مستقیم وارد می‌شود', $bob->login('bob')['status'] === 302);
$carol = $newClient();
$carol->login('carol');
$dave = $newClient();
$dave->login('dave');
$erin = $newClient();
$erin->login('erin');

section('صفحه چت');
$r = $alice->req('GET', 'chat.php');
check('اسکریپت داخل صفحه ندارد', preg_match('/<script(?![^>]*\bsrc=)/i', $r['body']) === 0);
check('رویداد inline ندارد', preg_match('/\son[a-z]+="/i', $r['body']) === 0);
check('هیچ آدرس خارجی بارگذاری نمی‌شود', preg_match('/(src|href)="https?:/i', $r['body']) === 0);
$r2 = $newClient()->req('GET', 'assets/js/chat-app.js');
check('فایل جاوااسکریپت سرو می‌شود', $r2['status'] === 200);

section('CSRF و پیام');
$r = $alice->req('POST', 'send_message.php', ['message' => 'no token']);
check('ارسال بدون توکن 403', $r['status'] === 403, (string)$r['status']);
$r = $alice->req('GET', 'get_messages.php?last_id=0');
check('دریافت بدون توکن 403', $r['status'] === 403, (string)$r['status']);
$r = $alice->api('POST', 'send_message.php', ['message' => '0']);
check('پیام «0» قابل ارسال است', $r['status'] === 200 && $r['json']['success'] === true, $r['body']);
$r = $alice->api('POST', 'send_message.php', 'message[]=x');
check('پیام آرایه‌ای خطای 500 نمی‌دهد', $r['status'] === 400, (string)$r['status']);
$r = $alice->api('POST', 'send_message.php', ['message' => '<img src=x onerror=alert(1)>']);
$xssId = $r['json']['data']['message_id'] ?? 0;
$r = $alice->api('POST', 'send_message.php', ['message' => "\xFF\xFEbad"]);
check('UTF-8 نامعتبر رد می‌شود', $r['status'] === 400, (string)$r['status']);
$r = $bob->api('GET', 'get_messages.php?last_id=0');
$found = false;
foreach ($r['json']['data']['messages'] ?? [] as $mm) {
    $found = $found || $mm['id'] === $xssId;
}
check('دریافت با هدر توکن کار می‌کند', $r['status'] === 200 && $found);
check('نام کاربری فرستنده در پاسخ نیست', strpos($r['body'], 'sender_username') === false);
check('ساعت پیام معتبر است', isset($r['json']['data']['messages'][0]['time']) && preg_match('/^\d\d:\d\d$/', $r['json']['data']['messages'][0]['time']) === 1);

section('وضعیت آنلاین و خروج');
$r = $alice->api('GET', 'update_status.php?status=offline');
check('تغییر وضعیت با GET ممنوع است', $r['status'] === 405, (string)$r['status']);
$r = $alice->req('POST', 'update_status.php', ['status' => 'away']);
check('تغییر وضعیت بدون توکن 403', $r['status'] === 403, (string)$r['status']);
$r = $alice->req('POST', 'update_status.php', ['csrf_token' => $alice->csrf, 'status' => 'online']);
check('توکن در فرم (sendBeacon) پذیرفته می‌شود', $r['status'] === 200, (string)$r['status']);
$pdo->exec("UPDATE users SET status='online', last_seen=DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE username='bob'");
$pdo->exec("UPDATE users SET status='offline', last_seen=NOW() WHERE username='dave'");
$r = $alice->api('GET', 'get_messages.php?last_id=999999');
$onlineNames = array_column($r['json']['data']['online_users'] ?? [], 'name');
check('کاربر قطع‌شده آنلاین حساب نمی‌شود', !in_array('باب', $onlineNames, true));
check('کاربر خارج‌شده آنلاین حساب نمی‌شود', !in_array('دیو', $onlineNames, true));
check('کاربر فعال آنلاین است', in_array('آلیس', $onlineNames, true), implode(',', $onlineNames));
$bob->api('POST', 'update_status.php', ['status' => 'online']);

$lo = $newClient();
$lo->login('dave');
$lo->req('GET', 'logout.php');
check('GET روی خروج کاربر را بیرون نمی‌کند', $lo->req('GET', 'chat.php')['status'] === 200);
$r = $lo->req('GET', 'chat.php');
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $r['body'], $m);
$lo->req('POST', 'logout.php', ['csrf_token' => $m[1]]);
check('POST با توکن خارج می‌کند', $lo->req('GET', 'chat.php')['status'] === 302);

section('مرز بین روم‌ها');
$r = $bob->api('POST', 'send_message.php', ['message' => 'room1 message of bob']);
$bobMsg = $r['json']['data']['message_id'];
$r = $carol->api('POST', 'delete_message.php', ['message_id' => $bobMsg]);
check('مدیر روم 2 پیام روم 1 را حذف نمی‌کند', $r['status'] === 404, $r['status'] . ' ' . $r['body']);
check('پیام واقعاً حذف نشده', (int)$pdo->query("SELECT is_deleted FROM messages WHERE id=$bobMsg")->fetchColumn() === 0);
$dualOwnerMsg = $alice->api('POST', 'send_message.php', ['message' => 'alice in room1'])['json']['data']['message_id'];
$alice2 = $newClient();
$alice2->login('alice', $pw, 2);
$r = $alice2->api('POST', 'edit_message.php', ['message_id' => $dualOwnerMsg, 'text' => 'hacked']);
check('پیام روم دیگر از session فعلی ویرایش نمی‌شود', $r['status'] === 404, $r['status'] . ' ' . $r['body']);
$r = $alice->api('POST', 'edit_message.php', ['message_id' => $dualOwnerMsg, 'text' => 'edited ok']);
check('صاحب پیام در همان روم ویرایش می‌کند', $r['status'] === 200, $r['body']);
$r = $bob->api('POST', 'delete_message.php', ['message_id' => $dualOwnerMsg]);
check('عضو عادی پیام دیگران را حذف نمی‌کند', $r['status'] === 403, (string)$r['status']);
$r = $bob->api('POST', 'edit_message.php', ['message_id' => $dualOwnerMsg, 'text' => 'x']);
check('عضو عادی پیام دیگران را ویرایش نمی‌کند', $r['status'] === 403, (string)$r['status']);
$r2m = $erin->api('POST', 'send_message.php', ['message' => 'erin in room2'])['json']['data']['message_id'];
$r = $bob->api('POST', 'send_message.php', ['message' => 'cross reply', 'reply_to' => $r2m]);
$cr = $r['json']['data']['message_id'];
check('پاسخ به پیام روم دیگر پذیرفته نمی‌شود', $pdo->query("SELECT reply_to FROM messages WHERE id=$cr")->fetchColumn() === null);
$r = $carol->api('POST', 'delete_message.php', ['message_id' => $r2m]);
check('مدیر روم پیام روم خودش را حذف می‌کند', $r['status'] === 200, $r['body']);
check('حذف توسط مدیر لاگ می‌شود', (int)$pdo->query("SELECT COUNT(*) FROM security_logs WHERE action='message_deleted_by_moderator'")->fetchColumn() === 1);

section('پیام حذف‌شده');
$secret = $bob->api('POST', 'send_message.php', ['message' => 'TOP-SECRET-TEXT'])['json']['data']['message_id'];
$bob->api('POST', 'send_message.php', ['message' => 'reply to secret', 'reply_to' => $secret]);
$bob->api('POST', 'delete_message.php', ['message_id' => $secret]);
$r = $alice->api('GET', 'get_messages.php?last_id=0');
check('متن پیام حذف‌شده از طریق reply لو نمی‌رود', strpos($r['body'], 'TOP-SECRET-TEXT') === false);

section('سکوت');
$pdo->exec("UPDATE users_rooms SET is_muted=1, muted_until=NULL WHERE user_id=2 AND room_id=1");
$r = $bob->api('POST', 'send_message.php', ['message' => 'muted']);
check('کاربر ساکت پیام نمی‌فرستد', $r['status'] === 403, (string)$r['status']);
$pdo->exec("UPDATE users_rooms SET muted_until=DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE user_id=2 AND room_id=1");
$r = $bob->api('POST', 'send_message.php', ['message' => 'unmuted']);
check('سکوت منقضی‌شده مانع نیست', $r['status'] === 200, (string)$r['status']);
$pdo->exec("UPDATE users_rooms SET is_muted=0, muted_until=NULL");

section('آپلود و دانلود');
$tmp = "$work/polyglot.php";
file_put_contents($tmp, "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;<?php echo 'PWNED'; ?>");
$r = $bob->api('POST', 'upload_file.php', ['file' => new CURLFile($tmp, 'image/gif', 'evil.php')]);
check('polyglot قبول می‌شود اما به عنوان تصویر', $r['status'] === 200 && ($r['json']['data']['file_type'] ?? '') === 'image', $r['body']);
$upId = $r['json']['data']['message_id'] ?? 0;
$stored = $pdo->query("SELECT file_path FROM messages WHERE id=" . (int)$upId)->fetchColumn();
check('پسوند ذخیره‌شده gif است نه php', (bool)preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.gif$#', (string)$stored), (string)$stored);
check('هیچ فایل php در uploads نیست', count(glob("$app/uploads/*/*/*.php")) === 0);
$r = $bob->req('GET', 'download.php?id=' . $upId);
check('عضو روم دانلود می‌کند', $r['status'] === 200 && strpos($r['body'], 'GIF89a') === 0, (string)$r['status']);
check('دانلود هدر nosniff و sandbox دارد', stripos($r['headers'], 'nosniff') !== false && stripos($r['headers'], 'sandbox') !== false);
check('نوع محتوا از روی فهرست سفید است', stripos($r['headers'], 'Content-Type: image/gif') !== false);
check('دانلود بدون ورود ممکن نیست', $newClient()->req('GET', 'download.php?id=' . $upId)['status'] === 401);
check('عضو روم دیگر دانلود نمی‌کند', $erin->req('GET', 'download.php?id=' . $upId)['status'] === 404);
$tmp = "$work/note.txt";
file_put_contents($tmp, "plain text");
$r = $bob->api('POST', 'upload_file.php', ['file' => new CURLFile($tmp, 'image/png', 'a.png')]);
check('متن با MIME جعلی رد می‌شود', $r['status'] === 400, (string)$r['status']);
$tmp = "$work/a.svg";
file_put_contents($tmp, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
$r = $bob->api('POST', 'upload_file.php', ['file' => new CURLFile($tmp, 'image/svg+xml', 'a.svg')]);
check('SVG پذیرفته نمی‌شود', $r['status'] === 400, (string)$r['status']);
$tmp = "$work/v.mp3";
file_put_contents($tmp, "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xFF\xFB\x90\x00" . str_repeat("\x00", 413), 8));
$r = $bob->api('POST', 'upload_file.php', ['file' => new CURLFile($tmp, 'audio/mpeg', 'v.mp3')]);
check('فایل صوتی با نوع voice ثبت می‌شود', $r['status'] === 200 && ($r['json']['data']['file_type'] ?? '') === 'voice', $r['body']);
$r = $bob->api('POST', 'upload_file.php', ['file[]' => new CURLFile($tmp)]);
check('آپلود بدون فایل معتبر خطای 500 نمی‌دهد', $r['status'] < 500, (string)$r['status']);
$tmp = "$work/big.gif";
file_put_contents($tmp, "GIF89a" . str_repeat("\0", 10 * 1024 * 1024 + 10));
$r = $bob->api('POST', 'upload_file.php', ['file' => new CURLFile($tmp, 'image/gif', 'big.gif')]);
check('فایل بزرگ‌تر از حد رد می‌شود', $r['status'] === 400, (string)$r['status']);
$pdo->exec("UPDATE messages SET file_size = 300000000 WHERE id = " . (int)$upId);
$tmp = "$work/polyglot.php";
$r = $bob->api('POST', 'upload_file.php', ['file' => new CURLFile($tmp, 'image/gif', 'x.gif')]);
check('سهمیه روزانه اعمال می‌شود', $r['status'] === 429, (string)$r['status']);
$pdo->exec("UPDATE messages SET file_size = 10 WHERE id = " . (int)$upId);
$bob->api('POST', 'delete_message.php', ['message_id' => $upId]);
check('فایل پیام حذف‌شده دانلود نمی‌شود', $bob->req('GET', 'download.php?id=' . $upId)['status'] === 404);
$dn = "$work/nasty.gif";
file_put_contents($dn, file_get_contents("$work/polyglot.php"));
$r = $bob->api('POST', 'upload_file.php', ['file' => new CURLFile($dn, 'image/gif', "..\\..\\x\r\nSet-Cookie: a=b.gif")]);
$r3 = $bob->req('GET', 'download.php?id=' . ($r['json']['data']['message_id'] ?? 0));
check('نام فایل مخرب به هدر تزریق نمی‌شود', stripos($r3['headers'], "\nSet-Cookie: a=b") === false);

section('غیرفعال‌سازی فوری');
$pdo->exec("UPDATE users SET is_active=0 WHERE username='erin'");
$r = $erin->api('POST', 'send_message.php', ['message' => 'still here?']);
check('کاربر غیرفعال‌شده فوراً قطع می‌شود', $r['status'] === 401, (string)$r['status']);
check('صفحه چت هم او را نمی‌پذیرد (بدون حلقه ریدایرکت)', $erin->req('GET', 'login.php')['status'] === 200);
$dv = $newClient();
$dv->login('dave');
$pdo->exec("DELETE FROM users_rooms WHERE user_id=4");
check('عضو حذف‌شده از روم به صفحه ورود می‌رود', $dv->req('GET', 'chat.php')['status'] === 302);
check('و دوباره به چت ریدایرکت نمی‌شود', $dv->req('GET', 'login.php')['status'] === 200);
$cl = $newClient();
$cl->login('carol');
$pdo->exec("UPDATE rooms SET is_active=0 WHERE id=2");
$r = $cl->api('GET', 'get_messages.php?last_id=0');
check('روم بسته‌شده دسترسی را قطع می‌کند', $r['status'] === 401, (string)$r['status']);
$pdo->exec("UPDATE rooms SET is_active=1 WHERE id=2");

section('فونت و وابستگی خارجی');
$r = $newClient()->req('GET', 'assets/css/fonts.css');
check('fonts.css فقط به فونت‌های محلی اشاره می‌کند', $r['status'] === 200 && preg_match('/https?:|\/\//', preg_replace('#/\*.*?\*/#s', '', $r['body'])) === 0);
foreach (['Light', 'Regular', 'Medium', 'SemiBold', 'Bold'] as $weight) {
    $r = $newClient()->req('GET', "assets/fonts/Vazirmatn-$weight.woff2");
    check("فونت $weight از همین سرور لود می‌شود", $r['status'] === 200 && strpos($r['body'], 'wOF2') === 0, (string)$r['status']);
}
$externalRefs = [];
$scan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($scan as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (!preg_match('/\.(php|js|css)$/', $path) || preg_match('#/(\.git|\.github|tests)/#', $path)) {
        continue;
    }
    if (preg_match('#(https?:)?//[a-z0-9.-]+\.[a-z]{2,}#i', str_replace('http://www.w3.org/2000/svg', '', file_get_contents($path)), $found)) {
        $externalRefs[] = substr($path, strlen($root)) . ' => ' . $found[0];
    }
}
check('هیچ آدرس خارجی در کد برنامه نیست', $externalRefs === [], implode(', ', $externalRefs));

section('سلامت سرویس');
$hc = $newClient();
$r = $hc->req('GET', 'health.php');
check('health پاسخ ok می‌دهد', $r['status'] === 200 && ($r['json']['data']['status'] ?? '') === 'ok', $r['status'] . ' ' . $r['body']);
check('health نشست نمی‌سازد', stripos($r['headers'], 'Set-Cookie') === false);

section('تغییر رمز کاربر');
$frank = $newClient();
check('ورود پیش از تغییر رمز', $frank->login('frank')['status'] === 302);
$frankOther = $newClient();
$frankOther->login('frank');
$changePw = function ($client, $current, $new, $confirm, $page = 'change-password.php') {
    $t = $client->token($page);
    $r = $client->req('POST', $page, ['csrf_token' => $t, 'current_password' => $current, 'new_password' => $new, 'confirm_password' => $confirm]);
    // مثل مرورگر، ریدایرکت با GET دنبال می‌شود تا پیام یک‌بارمصرف نمایش داده شود
    return $r['status'] === 302 ? $client->req('GET', $page) : $r;
};
check('بدون ورود به تغییر رمز دسترسی نیست', $newClient()->req('GET', 'change-password.php')['status'] === 302);
check('چت لینک تغییر رمز دارد', strpos($frank->req('GET', 'chat.php')['body'], 'href="change-password.php"') !== false);
$r = $frank->req('POST', 'change-password.php', ['current_password' => $pw, 'new_password' => 'N3wPassword9', 'confirm_password' => 'N3wPassword9']);
check('تغییر رمز بدون CSRF رد می‌شود', strpos($r['body'], 'alert-error') !== false);
check('رمز فعلی اشتباه رد می‌شود', strpos($changePw($frank, 'WrongPass1', 'N3wPassword9', 'N3wPassword9')['body'], 'alert-error') !== false);
check('تکرار نادرست رد می‌شود', strpos($changePw($frank, $pw, 'N3wPassword9', 'Different9A')['body'], 'alert-error') !== false);
check('رمز ضعیف رد می‌شود', strpos($changePw($frank, $pw, 'weak', 'weak')['body'], 'alert-error') !== false);
check('رمز تکراری رد می‌شود', strpos($changePw($frank, $pw, $pw, $pw)['body'], 'alert-error') !== false);
check('تغییر رمز موفق', strpos($changePw($frank, $pw, 'N3wPassword9', 'N3wPassword9')['body'], 'alert-success') !== false);
$r = $frank->api('POST', 'send_message.php', ['message' => 'still in']);
check('نشست جاری معتبر می‌ماند', $r['status'] === 200, (string)$r['status']);
$r = $frankOther->api('POST', 'send_message.php', ['message' => 'stale session']);
check('نشست دستگاه دیگر باطل می‌شود', $r['status'] === 401, (string)$r['status']);
check('رمز قدیمی دیگر کار نمی‌کند', $newClient()->login('frank')['status'] === 200);
$pdo->exec("DELETE FROM login_attempts");
check('رمز جدید کار می‌کند', $newClient()->login('frank', 'N3wPassword9')['status'] === 302);

section('پنل: مدیریت کاربران');
$a = $newClient();
$t = $a->token('admin-login.php');
$a->req('POST', 'admin-login.php', ['csrf_token' => $t, 'username' => 'rootadmin', 'password' => $pw]);
$adminToken = $a->token('admin-create-user.php');
foreach (['admin-users.php', 'admin-user-edit.php?id=1', 'admin-rooms.php', 'admin-room-edit.php?id=1', 'admin-room-members.php?id=1', 'admin-security-logs.php', 'admin-change-password.php'] as $page) {
    check("$page بدون ورود ادمین باز نمی‌شود", $newClient()->req('GET', $page)['status'] === 302);
    $userOnly = $newClient();
    $userOnly->login('grace');
    check("$page برای کاربر عادی باز نمی‌شود", $userOnly->req('GET', $page)['status'] === 302);
    check("$page برای ادمین باز می‌شود", $a->req('GET', $page)['status'] === 200);
}
$pdo->exec("UPDATE users SET name = '<script>alert(1)</script>' WHERE username = 'newuser'");
$r = $a->req('GET', 'admin-users.php?q=newuser');
check('جست‌وجو کار می‌کند', strpos($r['body'], 'newuser') !== false && strpos($r['body'], 'grace') === false);
check('نام کاربر escape می‌شود', strpos($r['body'], '<script>alert(1)') === false);
$r = $a->req('GET', 'admin-users.php?q=%25');
check('% در جست‌وجو تحت‌اللفظی است', strpos($r['body'], 'grace') === false);
$r = $a->req('GET', 'admin-users.php?page=abc&q[]=x');
check('ورودی نامعتبر صفحه‌بندی خطا نمی‌دهد', $r['status'] === 200, (string)$r['status']);
check('کاربر ناموجود به فهرست برمی‌گردد', $a->req('GET', 'admin-user-edit.php?id=99999')['status'] === 302);

$grace = $newClient();
$grace->login('grace');
$r = $a->req('POST', 'admin-user-edit.php?id=8', ['action' => 'save', 'name' => 'x', 'is_active' => '1']);
check('ویرایش بدون CSRF اعمال نمی‌شود', $pdo->query("SELECT name FROM users WHERE id=8")->fetchColumn() === 'گریس');
$a->req('POST', 'admin-user-edit.php?id=8', ['csrf_token' => $adminToken, 'action' => 'save', 'name' => 'گریس جدید', 'is_active' => '1']);
check('ادمین نام را ویرایش می‌کند', $pdo->query("SELECT name FROM users WHERE id=8")->fetchColumn() === 'گریس جدید');
$a->req('POST', 'admin-user-edit.php?id=8', ['csrf_token' => $adminToken, 'action' => 'save', 'name' => 'گریس جدید']);
check('ادمین کاربر را غیرفعال می‌کند', (int)$pdo->query("SELECT is_active FROM users WHERE id=8")->fetchColumn() === 0);
check('کاربر غیرفعال‌شده از پنل قطع می‌شود', $grace->api('POST', 'send_message.php', ['message' => 'x'])['status'] === 401);
$a->req('POST', 'admin-user-edit.php?id=8', ['csrf_token' => $adminToken, 'action' => 'save', 'name' => 'گریس جدید', 'is_active' => '1']);
$grace = $newClient();
check('پس از فعال‌سازی وارد می‌شود', $grace->login('grace')['status'] === 302);
$a->req('POST', 'admin-user-edit.php?id=8', ['csrf_token' => $adminToken, 'action' => 'password', 'password' => 'weak']);
check('رمز ضعیف در پنل رد می‌شود', stripos($a->req('GET', 'admin-user-edit.php?id=8')['body'], 'alert-error') !== false);
$a->req('POST', 'admin-user-edit.php?id=8', ['csrf_token' => $adminToken, 'action' => 'password', 'password' => 'Adm1nSetPass']);
check('تعیین رمز توسط ادمین نشست‌ها را می‌بندد', $grace->api('POST', 'send_message.php', ['message' => 'x'])['status'] === 401);
$grace = $newClient();
check('کاربر با رمز جدید وارد می‌شود', $grace->login('grace', 'Adm1nSetPass')['status'] === 302);

section('پنل: روم و اعضا');
$a->req('POST', 'admin-room-edit.php?id=2', ['csrf_token' => $adminToken, 'name' => 'SecretRoomOne', 'description' => 'd', 'is_active' => '1']);
check('نام تکراری روم رد می‌شود', $pdo->query("SELECT name FROM rooms WHERE id=2")->fetchColumn() === 'SecretRoomTwo');
$a->req('POST', 'admin-room-edit.php?id=2', ['csrf_token' => $adminToken, 'name' => 'SecretRoomTwo', 'description' => '<b>desc</b>', 'is_active' => '1']);
check('توضیحات روم escape می‌شود', strpos($a->req('GET', 'admin-room-edit.php?id=2')['body'], '<b>desc') === false);
$members = 'admin-room-members.php?id=1';
$a->req('POST', $members, ['csrf_token' => $adminToken, 'action' => 'mute', 'user_id' => '8', 'duration' => '3600']);
check('سکوت موقت اعمال می‌شود', $grace->api('POST', 'send_message.php', ['message' => 'x'])['status'] === 403);
$a->req('POST', $members, ['csrf_token' => $adminToken, 'action' => 'unmute', 'user_id' => '8']);
check('رفع سکوت اعمال می‌شود', $grace->api('POST', 'send_message.php', ['message' => 'after unmute'])['status'] === 200);
$a->req('POST', $members, ['csrf_token' => $adminToken, 'action' => 'mute', 'user_id' => '8', 'duration' => '0']);
check('سکوت نامحدود اعمال می‌شود', $pdo->query("SELECT muted_until FROM users_rooms WHERE user_id=8 AND room_id=1")->fetchColumn() === null);
$a->req('POST', $members, ['csrf_token' => $adminToken, 'action' => 'unmute', 'user_id' => '8']);
$a->req('POST', $members, ['csrf_token' => $adminToken, 'action' => 'mute', 'user_id' => '8', 'duration' => '12345']);
check('مدت سکوت خارج از فهرست رد می‌شود', (int)$pdo->query("SELECT is_muted FROM users_rooms WHERE user_id=8 AND room_id=1")->fetchColumn() === 0);
$a->req('POST', $members, ['csrf_token' => $adminToken, 'action' => 'role', 'user_id' => '8', 'role' => 'superuser']);
check('نقش نامعتبر رد می‌شود', $pdo->query("SELECT role FROM users_rooms WHERE user_id=8 AND room_id=1")->fetchColumn() === 'member');
$a->req('POST', $members, ['csrf_token' => $adminToken, 'action' => 'role', 'user_id' => '8', 'role' => 'moderator']);
check('تغییر نقش اعمال می‌شود', $pdo->query("SELECT role FROM users_rooms WHERE user_id=8 AND room_id=1")->fetchColumn() === 'moderator');
$a->req('POST', 'admin-room-members.php?id=2', ['csrf_token' => $adminToken, 'action' => 'remove', 'user_id' => '8']);
check('عضو روم دیگر با این فرم حذف نمی‌شود', (int)$pdo->query("SELECT COUNT(*) FROM users_rooms WHERE user_id=8")->fetchColumn() === 1);
$a->req('POST', $members, ['csrf_token' => $adminToken, 'action' => 'remove', 'user_id' => '8']);
check('حذف عضو دسترسی را قطع می‌کند', $grace->api('GET', 'get_messages.php?last_id=0')['status'] === 401);
check('پیام‌های عضو حذف‌شده می‌مانند', (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE user_id=8")->fetchColumn() > 0);

section('پنل: لاگ امنیتی');
$msg($newClient(), '<script>alert(2)</script>', 'whatever1');
$r = $a->req('GET', 'admin-security-logs.php');
check('لاگ نمایش داده می‌شود', strpos($r['body'], 'user_removed_from_room') !== false);
check('محتوای لاگ escape می‌شود', strpos($r['body'], '<script>alert(2)') === false);
$r = $a->req('GET', 'admin-security-logs.php?action=user_muted');
check('فیلتر رویداد کار می‌کند', strpos($r['body'], '<td class="ltr">user_muted</td>') !== false && strpos($r['body'], '<td class="ltr">user_removed_from_room</td>') === false);
$r = $a->req('GET', 'admin-security-logs.php?username=frank');
check('فیلتر کاربر کار می‌کند', strpos($r['body'], 'user_password_changed') !== false && strpos($r['body'], '<td class="ltr">user_muted</td>') === false);
$r = $a->req('GET', 'admin-security-logs.php?ip=203.0.113.1');
check('فیلتر IP کار می‌کند', strpos($r['body'], 'رویدادی یافت نشد') !== false);
$r = $a->req('GET', 'admin-security-logs.php?from=2000-01-01&to=2000-01-02');
check('فیلتر تاریخ کار می‌کند', strpos($r['body'], 'رویدادی یافت نشد') !== false);
$r = $a->req('GET', 'admin-security-logs.php?from=bad-date&action[]=x&page=-1');
check('فیلتر نامعتبر خطا نمی‌دهد', $r['status'] === 200, (string)$r['status']);

section('تغییر رمز ادمین');
$a2 = $newClient();
$t = $a2->token('admin-login.php');
$a2->req('POST', 'admin-login.php', ['csrf_token' => $t, 'username' => 'rootadmin', 'password' => $pw]);
$r = $changePw($a, 'WrongPass1', 'Adm1nNewPass', 'Adm1nNewPass', 'admin-change-password.php');
check('رمز فعلی اشتباه ادمین رد می‌شود', strpos($r['body'], 'alert-error') !== false);
$r = $changePw($a, $pw, 'Adm1nNewPass', 'Adm1nNewPass', 'admin-change-password.php');
check('ادمین رمز خود را عوض می‌کند', strpos($r['body'], 'alert-success') !== false);
check('نشست جاری ادمین می‌ماند', $a->req('GET', 'admin.php')['status'] === 200);
check('نشست دیگر ادمین باطل می‌شود', $a2->req('GET', 'admin.php')['status'] === 302);

section('مایگریشن');
$runCli = function ($args, $env = []) use ($php, $app) {
    $process = proc_open(array_merge([$php], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $p, $app, array_merge(getenv(), $env));
    fclose($p[0]);
    $out = stream_get_contents($p[1]) . stream_get_contents($p[2]);
    return ['exit' => proc_close($process), 'out' => $out];
};
$adminDbEnv = ['CHATROOM_DB_USER' => $dbAdminUser, 'CHATROOM_DB_PASS' => (string)$dbAdminPass];
$r = $runCli(['scripts/migrate.php'], $adminDbEnv);
check('database.sql همه مایگریشن‌ها را دارد', $r['exit'] === 0 && strpos($r['out'], 'Nothing to migrate') !== false, $r['out']);

section('چند روم در تب‌های هم‌زمان');
$insUser = $pdo->prepare("INSERT INTO users (id, username, password, name, is_active) VALUES (?, ?, ?, ?, 1)");
foreach ([[20, 'henry', 'هنری'], [21, 'ivy', 'آیوی'], [22, 'jack', 'جک'], [23, 'kate', 'کیت'], [24, 'liam', 'لیام'], [25, 'mona', 'مونا']] as [$id, $u, $n]) {
    $insUser->execute([$id, $u, $hash, $n]);
}
$pdo->exec("INSERT INTO rooms (id, name) VALUES (10, 'TeamRoom'), (11, 'SideRoom'), (12, 'RetentionRoom')");
$pdo->exec("INSERT INTO users_rooms (user_id, room_id, role) VALUES
    (20, 10, 'moderator'), (20, 11, 'member'), (20, 12, 'member'),
    (21, 10, 'member'), (22, 10, 'member'), (23, 11, 'member'), (24, 12, 'member'), (25, 12, 'member')");
$henry = $newClient();
$henry->login('henry', $pw, 10);
$ivy = $newClient();
$ivy->login('ivy');
$jack = $newClient();
$jack->login('jack');
$kate = $newClient();
$kate->login('kate');
check('کاربر چند رومی بعد از انتخاب وارد چت می‌شود', $henry->csrf !== '');
$r = $henry->api('POST', 'send_message.php', ['room_id' => 11, 'message' => 'در روم دوم']);
$sideMsg = $r['json']['data']['message_id'] ?? 0;
check('پیام به روم همان تب می‌رود نه روم session', (int)$pdo->query("SELECT room_id FROM messages WHERE id = " . (int)$sideMsg)->fetchColumn() === 11, $r['body']);
$r = $henry->api('POST', 'send_message.php', ['message' => 'بدون پارامتر روم']);
check('بدون پارامتر، روم session استفاده می‌شود', (int)$pdo->query("SELECT room_id FROM messages WHERE id = " . (int)($r['json']['data']['message_id'] ?? 0))->fetchColumn() === 10);
check('عضو نبودن در روم 403 است', $ivy->api('GET', 'get_messages.php?room_id=11&last_id=0')['status'] === 403);
check('ارسال به روم دیگران 403 است', $kate->api('POST', 'send_message.php', ['room_id' => 10, 'message' => 'x'])['status'] === 403);
check('دسترسی به روم ناموجود 403 است', $ivy->api('GET', 'sync.php?room_id=999999')['status'] === 403);
$r = $ivy->req('GET', 'chat.php?room=11');
check('chat.php روم غیرمجاز را به روم مجاز می‌برد', strpos($r['body'], 'data-room-id="10"') !== false);

section('همگام‌سازی رویدادها');
$cursor = (int)$ivy->api('GET', 'get_messages.php?last_id=0')['json']['data']['cursor'];
$kateCursor = (int)$kate->api('GET', 'get_messages.php?last_id=0')['json']['data']['cursor'];
$r = $henry->api('POST', 'send_message.php', ['room_id' => 10, 'message' => 'SYNC-ORIGINAL']);
$syncMsg = (int)$r['json']['data']['message_id'];
$r = $ivy->api('GET', "sync.php?cursor=$cursor");
check('پیام جدید با sync می‌رسد', strpos($r['body'], 'SYNC-ORIGINAL') !== false, $r['body']);
check('sync بدون توکن رد می‌شود', $ivy->req('GET', "sync.php?cursor=$cursor")['status'] === 403);
$cursor = (int)$r['json']['data']['cursor'];
$henry->api('POST', 'edit_message.php', ['room_id' => 10, 'message_id' => $syncMsg, 'text' => 'SYNC-EDITED']);
$r = $ivy->api('GET', "sync.php?cursor=$cursor");
check('ویرایش به بقیه می‌رسد', strpos($r['body'], 'SYNC-EDITED') !== false && ($r['json']['data']['messages'][0]['is_edited'] ?? false) === true, $r['body']);
$cursor = (int)$r['json']['data']['cursor'];
$henry->api('POST', 'delete_message.php', ['room_id' => 10, 'message_id' => $syncMsg]);
$r = $ivy->api('GET', "sync.php?cursor=$cursor");
check('حذف به بقیه می‌رسد', in_array($syncMsg, $r['json']['data']['deleted_ids'] ?? [], true), $r['body']);
check('متن پیام حذف‌شده در sync نیست', strpos($r['body'], 'SYNC-EDITED') === false);
$r = $kate->api('GET', "sync.php?cursor=$kateCursor");
check('رویداد روم دیگر به غیرعضو نمی‌رسد', strpos($r['body'], 'SYNC-') === false && ($r['json']['data']['messages'] ?? null) === []);
$cursor = (int)$r['json']['data']['cursor'];
$r = $kate->api('GET', "sync.php?cursor=$cursor");
check('بدون تغییر، cursor جلو نمی‌رود', (int)$r['json']['data']['cursor'] === $cursor && !isset($r['json']['data']['rooms']));

section('خوانده‌نشده و رسید');
$unreadOf = function ($client, $roomId) {
    foreach ($client->api('GET', 'get_messages.php?room_id=' . $roomId . '&last_id=999999999')['json']['data']['rooms'] ?? [] as $room) {
        if ($room['id'] === $roomId) {
            return $room;
        }
    }
    return null;
};
$jackCursor = (int)$jack->api('GET', 'get_messages.php?last_id=0')['json']['data']['cursor'];
$receiptMsg = (int)$henry->api('POST', 'send_message.php', ['room_id' => 10, 'message' => 'برای رسید'])['json']['data']['message_id'];
check('پیام جدید خوانده‌نشده حساب می‌شود', ($unreadOf($ivy, 10)['unread'] ?? 0) > 0);
$r = $henry->api('GET', 'get_messages.php?room_id=10&last_id=999999999');
$receipts = array_column($r['json']['data']['receipts'] ?? [], null, 'user_id');
check('قبل از رسیدن، تحویل نشده است', ($receipts[22]['delivered'] ?? 0) < $receiptMsg);
$jack->api('GET', "sync.php?cursor=$jackCursor");
$r = $henry->api('GET', 'get_messages.php?room_id=10&last_id=999999999');
$receipts = array_column($r['json']['data']['receipts'] ?? [], null, 'user_id');
check('دریافت با sync تحویل را ثبت می‌کند', ($receipts[22]['delivered'] ?? 0) >= $receiptMsg);
check('تحویل به معنی خواندن نیست', ($receipts[22]['read'] ?? 0) < $receiptMsg);
$r = $ivy->api('POST', 'mark_read.php', ['room_id' => 10, 'message_id' => $receiptMsg]);
check('mark_read خوانده‌نشده را صفر می‌کند', ($r['json']['data']['rooms'][0]['unread'] ?? -1) === 0 || ($unreadOf($ivy, 10)['unread'] ?? 1) === 0, $r['body']);
$r = $henry->api('GET', 'get_messages.php?room_id=10&last_id=999999999');
$receipts = array_column($r['json']['data']['receipts'] ?? [], null, 'user_id');
check('خواندن به فرستنده می‌رسد', ($receipts[21]['read'] ?? 0) >= $receiptMsg);
$ivy->api('POST', 'mark_read.php', ['room_id' => 10, 'message_id' => 1]);
check('نشانگر خواندن عقب نمی‌رود', (int)$pdo->query("SELECT last_read_message_id FROM users_rooms WHERE user_id=21 AND room_id=10")->fetchColumn() >= $receiptMsg);
$ivy->api('POST', 'mark_read.php', ['room_id' => 10, 'message_id' => 2000000000]);
check('نشانگر از آخرین پیام روم جلوتر نمی‌رود', (int)$pdo->query("SELECT last_read_message_id FROM users_rooms WHERE user_id=21 AND room_id=10")->fetchColumn() < 2000000000);
check('mark_read روم دیگران ممنوع است', $ivy->api('POST', 'mark_read.php', ['room_id' => 11, 'message_id' => 1])['status'] === 403);

section('پیام‌های قدیمی‌تر');
$bulk = $pdo->prepare("INSERT INTO messages (room_id, user_id, message) VALUES (11, 20, ?)");
for ($i = 1; $i <= 120; $i++) {
    $bulk->execute(["OLD-$i"]);
}
$r = $henry->api('GET', 'get_messages.php?room_id=11&last_id=0');
$page = $r['json']['data'];
check('صفحه اول 50 پیام و has_older دارد', count($page['messages']) === 50 && $page['has_older'] === true);
$r = $henry->api('GET', 'get_messages.php?room_id=11&last_id=0&before_id=' . $page['messages'][0]['id']);
$older = $r['json']['data'];
check('پیام‌های قدیمی‌تر بدون هم‌پوشانی می‌آیند', count($older['messages']) === 50 && end($older['messages'])['id'] < $page['messages'][0]['id']);
$pivot = $older['messages'][10]['id'];
$r = $henry->api('GET', "get_messages.php?room_id=11&last_id=0&around_id=$pivot");
check('around_id پیام هدف را برمی‌گرداند', in_array($pivot, array_column($r['json']['data']['messages'], 'id'), true) && $r['json']['data']['has_newer'] === true);
$r = $henry->api('GET', 'get_messages.php?room_id=10&last_id=0&before_id=' . $pivot);
check('before_id از روم دیگر پیامی نمی‌آورد', strpos($r['body'], 'OLD-') === false);

section('واکنش');
$reactMsg = (int)$henry->api('POST', 'send_message.php', ['room_id' => 10, 'message' => 'برای واکنش'])['json']['data']['message_id'];
check('ایموجی خارج از فهرست رد می‌شود', $ivy->api('POST', 'react_message.php', ['room_id' => 10, 'message_id' => $reactMsg, 'emoji' => '<b>'])['status'] === 400);
$r = $ivy->api('POST', 'react_message.php', ['room_id' => 10, 'message_id' => $reactMsg, 'emoji' => '👍']);
check('واکنش ثبت می‌شود', ($r['json']['data']['added'] ?? false) === true, $r['body']);
$jack->api('POST', 'react_message.php', ['room_id' => 10, 'message_id' => $reactMsg, 'emoji' => '👍']);
$messageById = function ($client, $roomId, $id) {
    foreach ($client->api('GET', "get_messages.php?room_id=$roomId&last_id=" . ($id - 1))['json']['data']['messages'] ?? [] as $m) {
        if ($m['id'] === $id) {
            return $m;
        }
    }
    return null;
};
$m = $messageById($ivy, 10, $reactMsg);
check('شمارش و mine درست است', ($m['reactions'][0]['count'] ?? 0) === 2 && ($m['reactions'][0]['mine'] ?? false) === true && $m['reactions'][0]['emoji'] === '👍');
$r = $ivy->api('POST', 'react_message.php', ['room_id' => 10, 'message_id' => $reactMsg, 'emoji' => '👍']);
check('واکنش دوباره برداشته می‌شود', ($r['json']['data']['added'] ?? true) === false);
check('واکنش روی پیام روم دیگر ممکن نیست', $ivy->api('POST', 'react_message.php', ['room_id' => 10, 'message_id' => $sideMsg, 'emoji' => '👍'])['status'] === 404);

section('سنجاق');
check('عضو عادی سنجاق نمی‌کند', $ivy->api('POST', 'pin_message.php', ['room_id' => 10, 'message_id' => $reactMsg])['status'] === 403);
$r = $henry->api('POST', 'pin_message.php', ['room_id' => 10, 'message_id' => $reactMsg]);
check('مدیر روم سنجاق می‌کند', ($r['json']['data']['pinned'] ?? false) === true, $r['body']);
$r = $ivy->api('GET', 'get_messages.php?last_id=0');
check('سنجاق به اعضا نمایش داده می‌شود', ($r['json']['data']['pinned'][0]['id'] ?? 0) === $reactMsg);
$henry->api('POST', 'delete_message.php', ['room_id' => 10, 'message_id' => $reactMsg]);
$r = $ivy->api('GET', 'get_messages.php?last_id=0');
check('پیام حذف‌شده از سنجاق خارج می‌شود', ($r['json']['data']['pinned'] ?? [1]) === []);

$pinEditMsg = (int)$henry->api('POST', 'send_message.php', ['room_id' => 10, 'message' => 'سنجاق قبل از ویرایش'])['json']['data']['message_id'];
$henry->api('POST', 'pin_message.php', ['room_id' => 10, 'message_id' => $pinEditMsg]);
$pinCursor = (int)$ivy->api('GET', 'get_messages.php?last_id=0')['json']['data']['cursor'];
$henry->api('POST', 'edit_message.php', ['room_id' => 10, 'message_id' => $pinEditMsg, 'text' => 'سنجاق بعد از ویرایش']);
$r = $ivy->api('GET', "sync.php?cursor=$pinCursor");
check('ویرایش، متن نوار سنجاق را به‌روز می‌کند', ($r['json']['data']['pinned'][0]['text'] ?? '') === 'سنجاق بعد از ویرایش', $r['body']);
$henry->api('POST', 'pin_message.php', ['room_id' => 10, 'message_id' => $pinEditMsg]);

section('منشن');
$r = $ivy->api('POST', 'send_message.php', ['message' => 'سلام @jack و @kate و @nobody email@jack.ir']);
$mentionMsg = (int)$r['json']['data']['message_id'];
$mentioned = $pdo->query("SELECT user_id FROM message_mentions WHERE message_id = $mentionMsg")->fetchAll(PDO::FETCH_COLUMN);
check('فقط اعضای روم منشن می‌شوند', array_map('intval', $mentioned) === [22], implode(',', $mentioned));
check('منشن در فهرست روم دیده می‌شود', ($unreadOf($jack, 10)['mentions'] ?? 0) === 1);
check('پیام برای فرد منشن‌شده علامت دارد', ($messageById($jack, 10, $mentionMsg)['mentions_me'] ?? false) === true);
check('برای دیگران علامت ندارد', ($messageById($henry, 10, $mentionMsg)['mentions_me'] ?? true) === false);
$ivy->api('POST', 'edit_message.php', ['message_id' => $mentionMsg, 'text' => 'بدون منشن']);
check('ویرایش، منشن را به‌روز می‌کند', ($unreadOf($jack, 10)['mentions'] ?? 1) === 0);
$r = $ivy->api('GET', 'room_members.php');
$usernames = array_column($r['json']['data']['members'] ?? [], 'username');
check('پیشنهاد منشن فقط اعضای روم است', in_array('jack', $usernames, true) && !in_array('kate', $usernames, true) && !in_array('ivy', $usernames, true));
check('فهرست اعضا هش رمز ندارد', strpos($r['body'], 'password') === false);

section('جست‌وجو');
$henry->api('POST', 'send_message.php', ['room_id' => 10, 'message' => 'کلمه‌ی جستجوپذیر یکتا 100%']);
$r = $ivy->api('GET', 'search_messages.php?q=' . urlencode('جستجوپذیر'));
check('جست‌وجوی فارسی کار می‌کند', count($r['json']['data']['results'] ?? []) === 1, $r['body']);
$r = $ivy->api('GET', 'search_messages.php?q=' . urlencode('OLD-1'));
check('پیام روم دیگر پیدا نمی‌شود', ($r['json']['data']['results'] ?? [1]) === []);
$r = $henry->api('GET', 'search_messages.php?room_id=11&q=' . urlencode('OLD-11'));
check('جست‌وجو در روم همان تب است', count($r['json']['data']['results'] ?? []) > 0);
$r = $ivy->api('GET', 'search_messages.php?q=' . urlencode('%%'));
check('% تحت‌اللفظی است', ($r['json']['data']['results'] ?? [1]) === []);
check('عبارت کوتاه رد می‌شود', $ivy->api('GET', 'search_messages.php?q=a')['status'] === 400);
check('عبارت آرایه‌ای خطای 500 نمی‌دهد', $ivy->api('GET', 'search_messages.php?q[]=abc')['status'] === 400);
$r = $ivy->api('GET', 'search_messages.php?q=' . urlencode('SYNC-EDITED'));
check('پیام حذف‌شده پیدا نمی‌شود', ($r['json']['data']['results'] ?? [1]) === []);

section('گفتگوی خصوصی');
$r = $ivy->api('GET', 'contacts.php');
$contactIds = array_column($r['json']['data']['contacts'] ?? [], 'id');
check('فقط هم‌روم‌ها در فهرست هستند', in_array(20, $contactIds, true) && in_array(22, $contactIds, true) && !in_array(23, $contactIds, true) && !in_array(21, $contactIds, true), implode(',', $contactIds));
check('با غیر هم‌روم گفتگو شروع نمی‌شود', $ivy->api('POST', 'start_direct.php', ['user_id' => 23])['status'] === 404);
check('با خود گفتگو شروع نمی‌شود', $ivy->api('POST', 'start_direct.php', ['user_id' => 21])['status'] === 404);
check('کاربر ناموجود هم همان پاسخ را دارد', $ivy->api('POST', 'start_direct.php', ['user_id' => 987654])['status'] === 404);
$r = $ivy->api('POST', 'start_direct.php', ['user_id' => 22]);
$dmRoom = (int)($r['json']['data']['room_id'] ?? 0);
check('گفتگو ساخته می‌شود', $dmRoom > 0 && $pdo->query("SELECT type FROM rooms WHERE id = $dmRoom")->fetchColumn() === 'direct', $r['body']);
check('طرف مقابل به همان گفتگو می‌رسد', (int)($jack->api('POST', 'start_direct.php', ['user_id' => 21])['json']['data']['room_id'] ?? 0) === $dmRoom);
$r = $ivy->api('POST', 'send_message.php', ['room_id' => $dmRoom, 'message' => 'پیام خصوصی']);
$dmMsg = (int)$r['json']['data']['message_id'];
$r = $jack->api('GET', "get_messages.php?room_id=$dmRoom&last_id=0");
check('طرف مقابل پیام را می‌بیند', strpos($r['body'], 'پیام خصوصی') !== false);
check('نوع روم به کلاینت می‌رسد', ($r['json']['data']['room_type'] ?? '') === 'direct');
$names = array_column($r['json']['data']['rooms'] ?? [], 'name', 'id');
check('نام گفتگو نام طرف مقابل است', ($names[$dmRoom] ?? '') === 'آیوی', json_encode($names, JSON_UNESCAPED_UNICODE));
check('شخص سوم پیام خصوصی را نمی‌بیند', $henry->api('GET', "get_messages.php?room_id=$dmRoom&last_id=0")['status'] === 403);
check('شخص سوم نمی‌تواند بفرستد', $henry->api('POST', 'send_message.php', ['room_id' => $dmRoom, 'message' => 'x'])['status'] === 403);
check('طرف مقابل پیام را حذف نمی‌کند', $jack->api('POST', 'delete_message.php', ['room_id' => $dmRoom, 'message_id' => $dmMsg])['status'] === 403);
check('هر دو طرف سنجاق می‌کنند', ($jack->api('POST', 'pin_message.php', ['room_id' => $dmRoom, 'message_id' => $dmMsg])['json']['data']['pinned'] ?? false) === true);
$r = $a->req('GET', 'admin-rooms.php');
check('گفتگوی خصوصی در پنل روم‌ها نیست', strpos($r['body'], 'direct:') === false);
check('صفحه اعضای گفتگوی خصوصی در پنل باز نمی‌شود', $a->req('GET', "admin-room-members.php?id=$dmRoom")['status'] === 302);
check('گفتگوی خصوصی در انتخاب روم ورود نیست', strpos($newClient()->login('ivy')['headers'], 'chat.php') !== false);

section('صف آفلاین');
$token = '11111111-2222-4333-8444-555555555555';
$r1 = $ivy->api('POST', 'send_message.php', ['message' => 'تکرار نشود', 'client_token' => $token]);
$r2 = $ivy->api('POST', 'send_message.php', ['message' => 'تکرار نشود', 'client_token' => $token]);
check('ارسال دوباره از صف پیام تکراری نمی‌سازد', ($r1['json']['data']['message_id'] ?? 1) === ($r2['json']['data']['message_id'] ?? 2) && ($r2['json']['data']['duplicate'] ?? false) === true);
check('فقط یک ردیف ثبت شده است', (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE client_token = '$token'")->fetchColumn() === 1);
$r3 = $jack->api('POST', 'send_message.php', ['message' => 'همان شناسه از کاربر دیگر', 'client_token' => $token]);
check('شناسه هر کاربر جداست', ($r3['json']['data']['duplicate'] ?? false) === false && $r3['json']['data']['message_id'] !== $r1['json']['data']['message_id']);
$r = $ivy->api('POST', 'send_message.php', ['message' => 'شناسه بد', 'client_token' => "'; DROP TABLE x"]);
check('شناسه نامعتبر نادیده گرفته می‌شود', $r['status'] === 200);

section('فایل و پیام صوتی');
$tmp = "$work/voice.webm";
file_put_contents($tmp, "\x1A\x45\xDF\xA3\x01\x00\x00\x00\x00\x00\x00\x1F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x02\x42\x85\x81\x02" . str_repeat("\0", 2000));
$r = $henry->api('POST', 'upload_file.php', ['room_id' => 10, 'file' => new CURLFile($tmp, 'video/webm', 'v.webm')]);
check('ویدیو بدون حالت صوتی رد می‌شود', $r['status'] === 400, $r['body']);
$r = $henry->api('POST', 'upload_file.php', ['room_id' => 10, 'kind' => 'voice', 'file' => new CURLFile($tmp, 'audio/webm', 'voice.webm')]);
check('ضبط مرورگر به عنوان پیام صوتی ثبت می‌شود', $r['status'] === 200 && ($r['json']['data']['file_type'] ?? '') === 'voice', $r['body']);
$voiceId = (int)($r['json']['data']['message_id'] ?? 0);
check('نوع فایل به صوت تبدیل می‌شود', $pdo->query("SELECT file_mime FROM messages WHERE id = $voiceId")->fetchColumn() === 'audio/webm');
$r = $ivy->req('GET', "download.php?id=$voiceId");
check('پخش صوت با Content-Type صوتی است', stripos($r['headers'], 'Content-Type: audio/webm') !== false && stripos($r['headers'], 'Accept-Ranges: bytes') !== false);
$r = $ivy->req('GET', "download.php?id=$voiceId", null, ['Range: bytes=0-9']);
check('درخواست Range پاسخ 206 می‌دهد', $r['status'] === 206 && strlen($r['body']) === 10 && stripos($r['headers'], 'Content-Range: bytes 0-9/') !== false, (string)$r['status']);
$r = $ivy->req('GET', "download.php?id=$voiceId", null, ['Range: bytes=-5']);
check('Range از انتها کار می‌کند', $r['status'] === 206 && strlen($r['body']) === 5);
$r = $ivy->req('GET', "download.php?id=$voiceId", null, ['Range: bytes=99999999-']);
check('Range خارج از فایل 416 است', $r['status'] === 416, (string)$r['status']);
check('عضو روم دوم هم از تب دیگر دانلود می‌کند', $henry->req('GET', "download.php?id=$voiceId")['status'] === 200);
check('غیرعضو دانلود نمی‌کند', $kate->req('GET', "download.php?id=$voiceId")['status'] === 404);
$m = $messageById($ivy, 10, $voiceId);
check('فایل در پیام نمایش داده می‌شود', ($m['message_type'] ?? '') === 'voice' && ($m['file_url'] ?? '') === "download.php?id=$voiceId" && ($m['file_size'] ?? 0) > 0);
check('مسیر واقعی فایل لو نمی‌رود', !isset($m['file_path']));

section('لینک دعوت');
$makeInvite = function ($room, $hours, $maxUses) use ($a, $adminToken) {
    $a->req('POST', "admin-room-edit.php?id=$room", ['csrf_token' => $adminToken, 'action' => 'invite', 'expires_hours' => $hours, 'max_uses' => $maxUses]);
    preg_match('/join\.php\?token=([a-f0-9]{64})/', $a->req('GET', "admin-room-edit.php?id=$room")['body'], $m);
    return $m[1] ?? '';
};
$anonEdit = $newClient()->req('POST', 'admin-room-edit.php?id=10', ['action' => 'invite']);
check('ساخت دعوت بدون ادمین ممکن نیست', $anonEdit['status'] === 302);
$token1 = $makeInvite(10, 24, 1);
check('لینک دعوت ساخته می‌شود', strlen($token1) === 64);
check('توکن خام در دیتابیس نیست', (int)$pdo->query("SELECT COUNT(*) FROM room_invites WHERE token_hash = '$token1'")->fetchColumn() === 0);
$r = $newClient()->req('GET', "join.php?token=$token1");
check('بدون ورود نام روم نشان داده نمی‌شود', strpos($r['body'], 'TeamRoom') === false && $r['status'] === 302);
$r = $kate->req('GET', "join.php?token=$token1");
check('کاربر واردشده نام روم را می‌بیند', strpos($r['body'], 'TeamRoom') !== false);
$r = $kate->req('POST', 'join.php', ['token' => $token1]);
check('پیوستن بدون CSRF رد می‌شود', (int)$pdo->query("SELECT COUNT(*) FROM users_rooms WHERE user_id=23 AND room_id=10")->fetchColumn() === 0);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $kate->req('GET', "join.php?token=$token1")['body'], $m);
$r = $kate->req('POST', 'join.php', ['token' => $token1, 'csrf_token' => $m[1]]);
check('کاربر به روم می‌پیوندد', $r['status'] === 302 && (int)$pdo->query("SELECT COUNT(*) FROM users_rooms WHERE user_id=23 AND room_id=10")->fetchColumn() === 1);
check('عضو جدید پیام‌های قبلی را خوانده‌نشده نمی‌بیند', ($unreadOf($kate, 10)['unread'] ?? 1) === 0);
$r = $newClient();
$r->login('mona');
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $r->req('GET', 'change-password.php')['body'], $m);
$r2 = $r->req('POST', 'join.php', ['token' => $token1, 'csrf_token' => $m[1]]);
check('ظرفیت پرشده پذیرفته نمی‌شود', (int)$pdo->query("SELECT COUNT(*) FROM users_rooms WHERE user_id=25 AND room_id=10")->fetchColumn() === 0);
$r = $a->req('GET', 'admin-room-edit.php?id=10');
check('دعوت پرشده در پنل مصرف را نشان می‌دهد', strpos($r['body'], '1 از 1') !== false);
$token2 = $makeInvite(10, 0, 0);
$fresh = $newClient();
$r = $fresh->req('GET', "join.php?token=$token2");
check('بدون ورود به صفحه ورود می‌رود', strpos($r['headers'], 'login.php?invite=1') !== false);
$r = $fresh->login('liam');
check('پس از ورود مستقیم عضو می‌شود', (int)$pdo->query("SELECT COUNT(*) FROM users_rooms WHERE user_id=24 AND room_id=10")->fetchColumn() === 1);
$r = $fresh->req('GET', 'chat.php');
check('و همان روم باز می‌شود', strpos($r['body'], 'data-room-id="10"') !== false);
$inviteId = (int)$pdo->query("SELECT MAX(id) FROM room_invites")->fetchColumn();
$a->req('POST', 'admin-room-edit.php?id=10', ['csrf_token' => $adminToken, 'action' => 'revoke', 'invite_id' => $inviteId]);
$r = $henry->req('GET', "join.php?token=$token2");
check('دعوت باطل‌شده کار نمی‌کند', strpos($r['body'], 'معتبر نیست') !== false);
$pdo->exec("INSERT INTO room_invites (room_id, token_hash, expires_at) VALUES (10, '" . hash('sha256', str_repeat('a', 64)) . "', DATE_SUB(NOW(), INTERVAL 1 HOUR))");
$r = $henry->req('GET', 'join.php?token=' . str_repeat('a', 64));
check('دعوت منقضی کار نمی‌کند', strpos($r['body'], 'معتبر نیست') !== false);
$r = $henry->req('GET', 'join.php?token[]=x');
check('توکن آرایه‌ای خطای 500 نمی‌دهد', $r['status'] === 200, (string)$r['status']);

section('نگهداری پیام');
$a->req('POST', 'admin-room-edit.php?id=12', ['csrf_token' => $adminToken, 'name' => 'RetentionRoom', 'description' => '', 'is_active' => '1', 'retention_days' => '7']);
check('مدت نگهداری ذخیره می‌شود', (int)$pdo->query("SELECT retention_days FROM rooms WHERE id = 12")->fetchColumn() === 7);
$a->req('POST', 'admin-room-edit.php?id=12', ['csrf_token' => $adminToken, 'name' => 'RetentionRoom', 'description' => '', 'is_active' => '1', 'retention_days' => '12345']);
check('مقدار خارج از فهرست به پیش‌فرض برمی‌گردد', $pdo->query("SELECT retention_days FROM rooms WHERE id = 12")->fetchColumn() === null);
$a->req('POST', 'admin-room-edit.php?id=12', ['csrf_token' => $adminToken, 'name' => 'RetentionRoom', 'description' => '', 'is_active' => '1', 'retention_days' => '7']);
$oldFile = "$work/old.gif";
file_put_contents($oldFile, file_get_contents("$work/polyglot.php"));
$mona = $newClient();
$mona->login('mona');
$r = $mona->api('POST', 'upload_file.php', ['file' => new CURLFile($oldFile, 'image/gif', 'old.gif')]);
$oldId = (int)($r['json']['data']['message_id'] ?? 0);
$oldPath = $pdo->query("SELECT file_path FROM messages WHERE id = $oldId")->fetchColumn();
$newId = (int)$mona->api('POST', 'send_message.php', ['message' => 'پیام تازه'])['json']['data']['message_id'];
$softId = (int)$mona->api('POST', 'send_message.php', ['message' => 'حذف قدیمی'])['json']['data']['message_id'];
$keepRoomMsg = (int)$henry->api('POST', 'send_message.php', ['room_id' => 10, 'message' => 'روم بدون نگهداری'])['json']['data']['message_id'];
$pdo->exec("UPDATE messages SET created_at = DATE_SUB(NOW(), INTERVAL 10 DAY) WHERE id IN ($oldId, $keepRoomMsg)");
$pdo->exec("UPDATE messages SET is_deleted = 1, deleted_at = DATE_SUB(NOW(), INTERVAL 40 DAY) WHERE id = $softId");
$countMsg = function ($id) use ($pdo) {
    return (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE id = " . (int)$id)->fetchColumn();
};
$r = $runCli(['scripts/cleanup.php', '--dry-run']);
check('dry-run چیزی پاک نمی‌کند', $r['exit'] === 0 && $countMsg($oldId) === 1 && is_file("$app/uploads/$oldPath") && strpos($r['out'], 'Would remove') !== false, $r['out']);
$r = $runCli(['scripts/cleanup.php']);
check('cleanup با کاربر محدود اجرا می‌شود', $r['exit'] === 0, $r['out']);
check('پیام قدیمی‌تر از مدت روم پاک می‌شود', $countMsg($oldId) === 0);
check('فایل آن هم از دیسک پاک می‌شود', !is_file("$app/uploads/$oldPath"));
check('پیام تازه می‌ماند', $countMsg($newId) === 1);
check('پیام حذف‌شده قدیمی پاک می‌شود', $countMsg($softId) === 0);
check('روم بدون سیاست نگهداری دست نمی‌خورد', $countMsg($keepRoomMsg) === 1);

section('PWA');
foreach (['manifest.webmanifest', 'sw.js', 'offline.html', 'assets/images/icon-192.png', 'assets/images/icon-512.png', 'assets/css/chat.css'] as $path) {
    check("$path سرو می‌شود", $newClient()->req('GET', $path)['status'] === 200);
}
$manifest = json_decode(file_get_contents("$root/manifest.webmanifest"), true);
check('manifest معتبر و محلی است', is_array($manifest) && strpos(json_encode($manifest), 'http') === false);
$sw = file_get_contents("$root/sw.js");
foreach (['offline.html', 'assets/css/chat.css', 'assets/js/chat-app.js', 'assets/fonts/Vazirmatn-Regular.woff2'] as $file) {
    check("$file در SW هست و وجود دارد", strpos($sw, "'$file'") !== false && is_file("$root/$file"));
}
check('SW هیچ پاسخ API را ذخیره نمی‌کند', strpos($sw, '.php') === false);
$r = $ivy->req('GET', 'chat.php');
check('CSP اجازه manifest و worker محلی را می‌دهد', stripos($r['headers'], "manifest-src 'self'") !== false && stripos($r['headers'], "worker-src 'self'") !== false);

section('پشتیبان و بازیابی');
$uploadedFile = $pdo->query("SELECT file_path FROM messages WHERE file_path IS NOT NULL AND is_deleted = 0 ORDER BY id DESC LIMIT 1")->fetchColumn();
$backupDir = "$work/backups";
$r = $runCli(['scripts/backup.php', $backupDir]);
check('پشتیبان با کاربر محدود ساخته می‌شود', $r['exit'] === 0, $r['out']);
$archives = glob("$backupDir/chatroom-backup-*.tar.gz");
check('فایل پشتیبان وجود دارد', count($archives) === 1);
check('پشتیبان فقط برای مالک قابل خواندن است', (fileperms($archives[0]) & 0077) === 0, decoct(fileperms($archives[0])));
$pdo->exec("INSERT INTO rooms (name) VALUES ('RoomAfterBackup')");
$pdo->exec("UPDATE users SET name = 'تغییر پس از پشتیبان' WHERE id = 1");
unlink("$app/uploads/$uploadedFile");
$r = $runCli(['scripts/restore.php', $archives[0]], $adminDbEnv);
check('restore بدون --yes اجرا نمی‌شود', $r['exit'] !== 0);
$r = $runCli(['scripts/restore.php', $archives[0], '--yes'], $adminDbEnv);
check('restore موفق است', $r['exit'] === 0, $r['out']);
check('داده دیتابیس برگشت', $pdo->query("SELECT name FROM users WHERE id = 1")->fetchColumn() === 'آلیس');
check('داده بعد از پشتیبان حذف شد', (int)$pdo->query("SELECT COUNT(*) FROM rooms WHERE name = 'RoomAfterBackup'")->fetchColumn() === 0);
check('متن فارسی سالم برگشت', (int)$pdo->query("SELECT COUNT(*) FROM users WHERE name = 'باب'")->fetchColumn() === 1);
check('فایل آپلود برگشت', is_file("$app/uploads/$uploadedFile"));
$r = $runCli(['scripts/backup.php', $backupDir, '--keep=1']);
sleep(1);
$r = $runCli(['scripts/backup.php', $backupDir, '--keep=1']);
check('--keep پشتیبان‌های قدیمی را حذف می‌کند', count(glob("$backupDir/chatroom-backup-*.tar.gz")) === 1, $r['out']);

section('فایل‌ها و خطاهای سرور');
$errors = is_file("$work/error.log") ? file_get_contents("$work/error.log") : '';
check('لاگ خطای PHP خالی است', trim($errors) === '', substr($errors, 0, 1500));

echo "\n--------------------------\nPASS: $passed   FAIL: $failed\n";

exit($failed === 0 ? 0 : 1);
