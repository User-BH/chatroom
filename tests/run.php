<?php
/**
 * تست end-to-end روی MySQL یا MariaDB واقعی
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

// ---------- کلاینت HTTP ----------
class Client {
    private $jar;
    public function __construct($base, $work) {
        $this->base = $base;
        $this->jar = tempnam($work, 'jar');
    }
    public $base;
    public $csrf = '';
    public function req($method, $path, $data = null, $headers = [], $follow = false) {
        $c = curl_init($this->base . '/' . $path);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_USERAGENT => 'chatroom-test',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_NOPROXY => '*',
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($data !== null) {
            curl_setopt($c, CURLOPT_POSTFIELDS, $data);
        }
        $raw = curl_exec($c);
        $status = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE);
        $h = substr($raw, 0, $hs);
        $b = substr($raw, $hs);
        return ['status' => $status, 'headers' => $h, 'body' => $b, 'json' => json_decode($b, true)];
    }
    public function token($path = 'login.php') {
        $r = $this->req('GET', $path);
        preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $r['body'], $m);
        return $m[1] ?? '';
    }
    public function login($user, $pass = 'Str0ngPass1', $room = null, $headers = []) {
        $t = $this->token();
        $r = $this->req('POST', 'login.php', ['csrf_token' => $t, 'step' => 'password', 'username' => $user, 'password' => $pass], $headers);
        if ($room !== null && $r['status'] === 200) {
            preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $r['body'], $m);
            $r = $this->req('POST', 'login.php', ['csrf_token' => $m[1] ?? '', 'step' => 'room', 'room_id' => $room], $headers);
        }
        if ($r['status'] === 302) {
            $this->loadChat();
        }
        return $r;
    }
    public function loadChat() {
        $r = $this->req('GET', 'chat.php');
        if (preg_match('/data-csrf-token="([a-f0-9]+)"/', $r['body'], $m)) {
            $this->csrf = $m[1];
        }
        return $r;
    }
    public function api($method, $path, $data = null) {
        return $this->req($method, $path, $data, ['X-CSRF-Token: ' . $this->csrf]);
    }
}

$passed = 0;
$failed = 0;
function check($name, $ok, $detail = '') {
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  PASS  $name\n";
    } else {
        $failed++;
        echo "  FAIL  $name" . ($detail !== '' ? "  => $detail" : '') . "\n";
    }
}
function section($t) {
    echo "\n== $t\n";
}
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
    return $client->req('POST', $page, ['csrf_token' => $t, 'current_password' => $current, 'new_password' => $new, 'confirm_password' => $confirm], [], true);
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
