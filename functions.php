<?php
/**
 * ===================================================
 * ChatRoom - توابع کمکی و امنیتی
 * ===================================================
 * نسخه: 2.1
 * سازگار با: PHP 8.5
 * استفاده از: PDO
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/chat.php';

// هش ساختگی برای نام‌های کاربری ناموجود تا زمان پاسخ، وجود حساب را لو ندهد
const DUMMY_PASSWORD_HASH = '$2y$12$fae45vreC3u6c1.VACgOauSqitf15ard7/g0qub7mHY0EzviIGnye';

// ==============================================
// توابع امنیتی Session
// ==============================================

/**
 * آیا درخواست روی HTTPS آمده است؟
 * @return bool
 */
function isHttps() {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return isTrustedProxy($_SERVER['REMOTE_ADDR'] ?? '')
        && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * شروع امن session
 */
function secureSessionStart() {
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_NONE) {
        return;
    }

    // تنظیمات امنیتی session
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', isHttps() ? '1' : '0');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);

    session_name(SESSION_NAME);
    session_start();

    $now = time();

    // بازسازی session ID برای جلوگیری از session fixation
    if (!isset($_SESSION['initiated'])) {
        session_regenerate_id(true);
        $_SESSION['initiated'] = true;
        $_SESSION['created_at'] = $now;
        $_SESSION['last_activity'] = $now;
    }

    // بررسی انقضای session (بیکاری یا عمر کل)
    $idleExpired = $now - (int)($_SESSION['last_activity'] ?? $now) > SESSION_LIFETIME;
    $absoluteExpired = $now - (int)($_SESSION['created_at'] ?? $now) > SESSION_ABSOLUTE_LIFETIME;
    if ($idleExpired || $absoluteExpired) {
        sessionDestroy();
        secureSessionStart();
        return;
    }
    $_SESSION['last_activity'] = $now;

    // Fingerprint برای جلوگیری از session hijacking
    $fingerprint = hash(
        'sha256',
        ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')
    );

    if (!isset($_SESSION['fingerprint'])) {
        $_SESSION['fingerprint'] = $fingerprint;
    } elseif (!hash_equals($_SESSION['fingerprint'], $fingerprint)) {
        sessionDestroy();
        if (defined('API_REQUEST')) {
            jsonError('لطفاً دوباره وارد شوید.', 401);
        }
        header('Location: login.php?error=session');
        exit();
    }
}

/**
 * پایان امن session
 */
function sessionDestroy() {
    $_SESSION = [];
    if (ini_get("session.use_cookies") && !headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * بعد از تایید هویت: شناسه session و توکن CSRF عوض می‌شوند
 */
function rotateSessionAfterLogin() {
    session_regenerate_id(true);
    unset($_SESSION[CSRF_TOKEN_NAME]);
    $_SESSION['created_at'] = time();
    $_SESSION['last_activity'] = time();
}

/**
 * پاک کردن وضعیت ورود کاربر بدون دست زدن به ورود ادمین در همان مرورگر
 */
function clearUserSession() {
    unset(
        $_SESSION['user_id'],
        $_SESSION['username'],
        $_SESSION['name'],
        $_SESSION['room_id'],
        $_SESSION['session_version'],
        $_SESSION['login_time'],
        $_SESSION['pending_login']
    );
}

// ==============================================
// توابع CSRF
// ==============================================

/**
 * تولید توکن CSRF
 * توکن تا پایان session ثابت است چون صفحه چت ساعت‌ها باز می‌ماند
 * @return string
 */
function generateCSRFToken() {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * اعتبارسنجی توکن CSRF
 * @param mixed $token
 * @return bool
 */
function validateCSRFToken($token) {
    if (empty($_SESSION[CSRF_TOKEN_NAME]) || !is_string($token) || $token === '') {
        return false;
    }
    return hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

/**
 * توکن CSRF ارسال‌شده در درخواست (هدر یا فیلد فرم)
 * @return mixed
 */
function requestCSRFToken() {
    return $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST[CSRF_TOKEN_NAME] ?? '');
}

/**
 * فیلد مخفی CSRF برای فرم‌ها
 * @return string
 */
function csrfField() {
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . generateCSRFToken() . '">';
}

// ==============================================
// توابع محافظت از ورود
// ==============================================

/**
 * تعداد تلاش‌های ناموفق در پنجره زمانی
 * @param string $type
 * @param string|null $ip
 * @param string|null $username
 * @return int
 */
function countFailedLogins($type, $ip = null, $username = null) {
    $sql = "
        SELECT COUNT(*) AS attempts
        FROM login_attempts
        WHERE attempt_type = :type
        AND success = 0
        AND attempted_at > DATE_SUB(NOW(), INTERVAL :seconds SECOND)
    ";
    $params = [':type' => $type, ':seconds' => LOGIN_LOCKOUT_TIME];

    if ($ip !== null) {
        $sql .= " AND ip_address = :ip";
        $params[':ip'] = $ip;
    }
    if ($username !== null) {
        $sql .= " AND username = :username";
        $params[':username'] = $username;
    }

    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetch()['attempts'];
}

/**
 * بررسی مسدود بودن تلاش ورود
 * محدودیت‌ها سمت سرور هستند و با پاک کردن کوکی یا جعل هدر دور زده نمی‌شوند.
 * قفل کامل حساب عمداً نداریم چون هر کسی می‌توانست با چند رمز غلط، دیگران را بیرون کند.
 * @param string $ip
 * @param string $username
 * @param string $type
 * @return bool
 */
function isLoginBlocked($ip, $username, $type = 'user') {
    return countFailedLogins($type, $ip, $username) >= MAX_LOGIN_ATTEMPTS
        || countFailedLogins($type, $ip) >= MAX_LOGIN_ATTEMPTS_PER_IP
        || countFailedLogins($type, null, $username) >= MAX_LOGIN_ATTEMPTS_PER_USER;
}

/**
 * ثبت تلاش ورود
 * @param string $ip
 * @param string $username
 * @param string $type
 * @param bool $success
 */
function logLoginAttempt($ip, $username, $type, $success) {
    $db = getDB();

    if ($success) {
        // ورود موفق شمارنده همین کاربر روی همین IP را صفر می‌کند
        $stmt = $db->prepare("
            DELETE FROM login_attempts
            WHERE ip_address = :ip AND username = :username AND attempt_type = :type AND success = 0
        ");
        $stmt->execute([':ip' => $ip, ':username' => $username, ':type' => $type]);
    }

    $stmt = $db->prepare("
        INSERT INTO login_attempts (ip_address, username, attempt_type, success)
        VALUES (:ip, :username, :type, :success)
    ");

    $stmt->execute([
        ':ip' => $ip,
        ':username' => $username,
        ':type' => $type,
        ':success' => $success ? 1 : 0
    ]);

    // پاکسازی بدون وابستگی به cron یا Event Scheduler
    if (mt_rand(1, 100) === 1) {
        cleanupOldRecords();
    }
}

/**
 * حذف رکوردهای قدیمی لاگ
 */
function cleanupOldRecords() {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL :seconds SECOND)");
    $stmt->execute([':seconds' => 30 * 86400]);
    $stmt = $db->prepare("DELETE FROM security_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL :seconds SECOND)");
    $stmt->execute([':seconds' => 90 * 86400]);
    $stmt = $db->prepare("DELETE FROM room_events WHERE created_at < DATE_SUB(NOW(), INTERVAL :seconds SECOND)");
    $stmt->execute([':seconds' => 7 * 86400]);
}

/**
 * تایید نام کاربری و رمز عبور کاربر یا ادمین
 * پیام خطا برای کاربر ناموجود، غیرفعال و رمز اشتباه یکسان است
 * @param string $type user|admin
 * @param string $username
 * @param string $password
 * @return array ['account' => array|null, 'error' => string]
 */
function attemptLogin($type, $username, $password) {
    $ip = getClientIP();
    $genericError = 'نام کاربری یا رمز عبور اشتباه است.';

    if ($username === '' || $password === '') {
        return ['account' => null, 'error' => 'لطفاً نام کاربری و رمز عبور را وارد کنید.'];
    }

    // ستون username حداکثر 50 کاراکتر است؛ چنین نامی اصلاً وجود ندارد
    if (mb_strlen($username, 'UTF-8') > 50 || !mb_check_encoding($username, 'UTF-8')) {
        return ['account' => null, 'error' => $genericError];
    }

    if (isLoginBlocked($ip, $username, $type)) {
        logSecurityEvent($type . '_login_blocked', null, null, ['username' => $username]);
        return [
            'account' => null,
            'error' => 'تعداد تلاش‌های ورود بیش از حد مجاز است. لطفاً ' . ceil(LOGIN_LOCKOUT_TIME / 60) . ' دقیقه صبر کنید.'
        ];
    }

    $table = $type === 'admin' ? 'admins' : 'users';
    $stmt = getDB()->prepare("SELECT * FROM {$table} WHERE username = :username");
    $stmt->execute([':username' => $username]);
    $account = $stmt->fetch();

    // برای کاربر ناموجود هم bcrypt اجرا می‌شود تا زمان پاسخ یکسان بماند
    $passwordOk = verifyPassword($password, $account ? $account['password'] : DUMMY_PASSWORD_HASH);

    if (!$account || !$passwordOk || !$account['is_active']) {
        logLoginAttempt($ip, $username, $type, false);
        logSecurityEvent(
            $type . '_login_failed',
            ($account && $type === 'user') ? $account['id'] : null,
            ($account && $type === 'admin') ? $account['id'] : null,
            ['username' => $username]
        );
        return ['account' => null, 'error' => $genericError];
    }

    logLoginAttempt($ip, $username, $type, true);
    return ['account' => $account, 'error' => ''];
}

/**
 * Rate Limiting با Session
 * فقط برای کاربر واردشده مناسب است؛ برای صفحه ورود از isLoginBlocked استفاده کنید
 * @param string $key
 * @param int $maxAttempts
 * @param int $timeWindow
 * @return bool
 */
function checkRateLimit($key, $maxAttempts = 10, $timeWindow = 60) {
    $cacheKey = 'rate_limit_' . md5($key);

    if (!isset($_SESSION[$cacheKey])) {
        $_SESSION[$cacheKey] = ['count' => 1, 'start' => time()];
        return true;
    }

    $data = $_SESSION[$cacheKey];

    if (time() - $data['start'] > $timeWindow) {
        $_SESSION[$cacheKey] = ['count' => 1, 'start' => time()];
        return true;
    }

    if ($data['count'] >= $maxAttempts) {
        return false;
    }

    $_SESSION[$cacheKey]['count']++;
    return true;
}

/**
 * آیا این IP یکی از پروکسی‌های مورد اعتماد است؟
 * @param string $ip
 * @return bool
 */
function isTrustedProxy($ip) {
    if ($ip === '' || TRUSTED_PROXIES === '') {
        return false;
    }
    return in_array($ip, array_map('trim', explode(',', TRUSTED_PROXIES)), true);
}

/**
 * دریافت IP واقعی کاربر
 * هدرهای پروکسی فقط وقتی خوانده می‌شوند که درخواست از پروکسی مورد اعتماد آمده باشد،
 * وگرنه هر کسی می‌توانست با جعل X-Forwarded-For محدودیت ورود را دور بزند
 * @return string
 */
function getClientIP() {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';

    if (isTrustedProxy($remote) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // از راست به چپ: اولین آدرسی که پروکسی ما نیست، آدرس واقعی است
        $chain = array_reverse(array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])));
        foreach ($chain as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) && !isTrustedProxy($ip)) {
                return $ip;
            }
        }
    }

    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

// ==============================================
// توابع پاکسازی ورودی
// ==============================================

/**
 * پاکسازی رشته برای نمایش (XSS Prevention)
 * @param mixed $string
 * @return string
 */
function sanitize($string) {
    return htmlspecialchars(trim((string)$string), ENT_QUOTES, 'UTF-8');
}

/**
 * پاکسازی ورودی متنی؛ آرایه (مثل username[]=x) به رشته خالی تبدیل می‌شود
 * @param mixed $data
 * @return string
 */
function cleanInput($data) {
    return is_string($data) ? trim($data) : '';
}

/**
 * خواندن یک فیلد متنی از POST
 * @param string $key
 * @return string
 */
function postString($key) {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
}

/**
 * اعتبارسنجی عدد صحیح مثبت
 * @param mixed $value
 * @return int|false
 */
function validatePositiveInt($value) {
    if (!is_scalar($value)) {
        return false;
    }
    $value = filter_var($value, FILTER_VALIDATE_INT);
    return ($value !== false && $value > 0) ? $value : false;
}

/**
 * اعتبارسنجی متن پیام
 * @param string $text
 * @return string خطا یا رشته خالی
 */
function validateMessageText($text) {
    // مقایسه با '' و نه empty، چون پیام «0» یک پیام معتبر است
    if ($text === '') {
        return 'پیام نمی‌تواند خالی باشد.';
    }
    if (!mb_check_encoding($text, 'UTF-8')) {
        return 'متن پیام نامعتبر است.';
    }
    if (mb_strlen($text, 'UTF-8') > MAX_MESSAGE_LENGTH) {
        return 'پیام بیش از حد طولانی است (حداکثر ' . MAX_MESSAGE_LENGTH . ' کاراکتر).';
    }
    return '';
}

// ==============================================
// توابع کاربر
// ==============================================

/**
 * بررسی ورود کاربر
 * @return bool
 */
function isUserLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['room_id']);
}

/**
 * بررسی ورود ادمین
 * @return bool
 */
function isAdminLoggedIn() {
    return isset($_SESSION['admin_id']);
}

/**
 * ریدایرکت به صفحه
 * @param string $url
 */
function redirect($url) {
    header("Location: $url");
    exit();
}

/**
 * نیاز به ورود کاربر
 */
function requireUserLogin() {
    if (!isUserLoggedIn()) {
        redirect('login.php');
    }
}

/**
 * نیاز به ورود ادمین
 * حساب ادمین در هر درخواست بررسی می‌شود تا غیرفعال‌سازی فوراً اثر کند
 */
function requireAdminLogin() {
    if (!isAdminLoggedIn()) {
        redirect('admin-login.php');
    }

    $stmt = getDB()->prepare("SELECT session_version FROM admins WHERE id = :id AND is_active = 1");
    $stmt->execute([':id' => $_SESSION['admin_id']]);
    $admin = $stmt->fetch();
    if (!$admin || (int)$admin['session_version'] !== (int)($_SESSION['admin_session_version'] ?? -1)) {
        unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_session_version']);
        redirect('admin-login.php');
    }
}

/**
 * عضویت معتبر: کاربر فعال، روم فعال و عضویت موجود
 * @param int $userId
 * @param int $roomId
 * @return array|null
 */
function getActiveMembership($userId, $roomId) {
    $stmt = getDB()->prepare("
        SELECT ur.role, ur.is_muted, ur.muted_until, ur.last_read_message_id, r.type, u.session_version
        FROM users_rooms ur
        JOIN users u ON u.id = ur.user_id AND u.is_active = 1
        JOIN rooms r ON r.id = ur.room_id AND r.is_active = 1
        WHERE ur.user_id = :user_id AND ur.room_id = :room_id
    ");
    $stmt->execute([':user_id' => $userId, ':room_id' => $roomId]);
    $membership = $stmt->fetch();
    return $membership ?: null;
}

/**
 * عضویت کاربر فعلی session؛ اگر رمز عبور عوض شده باشد این session دیگر معتبر نیست
 * @return array|null
 */
function getCurrentUserMembership($roomId = null) {
    if (!isUserLoggedIn()) {
        return null;
    }
    $roomId = $roomId ?? (int)$_SESSION['room_id'];
    $membership = getActiveMembership((int)$_SESSION['user_id'], (int)$roomId);
    if (!$membership || (int)$membership['session_version'] !== (int)($_SESSION['session_version'] ?? -1)) {
        return null;
    }
    return $membership;
}

/**
 * آیا حساب کاربر فعلی هنوز فعال است و رمزش پس از این ورود عوض نشده؟
 * @return bool
 */
function isCurrentAccountValid() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    $stmt = getDB()->prepare("SELECT session_version FROM users WHERE id = :id AND is_active = 1");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $version = $stmt->fetchColumn();
    return $version !== false && (int)$version === (int)($_SESSION['session_version'] ?? -1);
}

/**
 * روم درخواست؛ هر تب روم خودش را می‌فرستد تا تب‌های هم‌زمان با هم قاطی نشوند
 * @return int
 */
function requestedRoomId() {
    return validatePositiveInt($_POST['room_id'] ?? ($_GET['room_id'] ?? 0)) ?: (int)$_SESSION['room_id'];
}

/**
 * آیا عضو در حال حاضر ساکت شده است؟
 * @param array $membership
 * @return bool
 */
function isMembershipMuted($membership) {
    if (!$membership['is_muted']) {
        return false;
    }
    return $membership['muted_until'] === null || strtotime($membership['muted_until']) > time();
}

/**
 * کنترل مشترک تمام APIهای چت. فقط وقتی برمی‌گردد که درخواست مجاز باشد.
 * غیرفعال شدن کاربر یا روم و حذف از روم، از درخواست بعدی اثر می‌کند.
 * @param string $method
 * @param string $rateKey
 * @param int $rateMax
 * @param int $rateWindow
 * @return array ['user_id', 'room_id', 'membership']
 */
function requireChatApiAccess($method, $rateKey, $rateMax, $rateWindow = 60, $requireCsrf = true) {
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        jsonError('متد نامعتبر است.', 405);
    }
    if (!isUserLoggedIn()) {
        jsonError('لطفاً وارد شوید.', 401);
    }
    if ($requireCsrf && !validateCSRFToken(requestCSRFToken())) {
        jsonError('توکن امنیتی نامعتبر است.', 403);
    }

    $userId = (int)$_SESSION['user_id'];
    $roomId = requestedRoomId();
    
    if (!checkRateLimit($rateKey . '_' . $userId, $rateMax, $rateWindow)) {
        jsonError('تعداد درخواست‌ها بیش از حد مجاز است.', 429);
    }

    if (!isCurrentAccountValid()) {
        clearUserSession();
        jsonError('لطفاً دوباره وارد شوید.', 401);
    }
    
    $membership = getCurrentUserMembership($roomId);
    if (!$membership) {
        // کاربر روم‌های دیگری هم دارد؛ فقط همین روم بسته است
        if (getRoomList($userId)) {
            jsonError('دسترسی شما به این روم وجود ندارد.', 403);
        }
        clearUserSession();
        jsonError('دسترسی شما به این روم وجود ندارد.', 401);
    }

    return ['user_id' => $userId, 'room_id' => $roomId, 'membership' => $membership];
}

/**
 * روم‌های فعالی که کاربر عضو آن‌هاست
 * @param int $userId
 * @return array
 */
function getUserRooms($userId) {
    $stmt = getDB()->prepare("
        SELECT r.id, r.name
        FROM rooms r
        JOIN users_rooms ur ON ur.room_id = r.id
        WHERE ur.user_id = :user_id AND r.is_active = 1 AND r.type = 'group'
        ORDER BY r.name
    ");
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetchAll();
}

/**
 * کسانی که با کاربر حداقل یک روم گروهی فعال مشترک دارند
 * @param int $userId
 * @return array
 */
function getContacts($userId) {
    $stmt = getDB()->prepare("
        SELECT DISTINCT u.id, u.name, u.username
        FROM users_rooms mine
        JOIN rooms r ON r.id = mine.room_id AND r.is_active = 1 AND r.type = 'group'
        JOIN users_rooms other ON other.room_id = mine.room_id AND other.user_id <> mine.user_id
        JOIN users u ON u.id = other.user_id AND u.is_active = 1
        WHERE mine.user_id = :user
        ORDER BY u.name
        LIMIT 500
    ");
    $stmt->execute([':user' => $userId]);
    return array_map(function ($row) {
        $row['id'] = (int)$row['id'];
        return $row;
    }, $stmt->fetchAll());
}

/**
 * کاربران آنلاین روم: خروج نکرده و heartbeat تازه
 * @param int $roomId
 * @return array
 */
function getOnlineUsers($roomId) {
    $stmt = getDB()->prepare("
        SELECT u.id, u.name, u.status
        FROM users u
        JOIN users_rooms ur ON u.id = ur.user_id
        WHERE ur.room_id = :room_id
        AND u.is_active = 1
        AND u.status <> 'offline'
        AND u.last_seen > DATE_SUB(NOW(), INTERVAL :timeout SECOND)
    ");
    $stmt->execute([':room_id' => $roomId, ':timeout' => ONLINE_TIMEOUT]);
    return $stmt->fetchAll();
}

/**
 * دریافت اطلاعات کاربر
 * @param int $userId
 * @return array|null
 */
function getUserById($userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = :id AND is_active = 1");
    $stmt->execute([':id' => $userId]);
    $result = $stmt->fetch();
    return $result ?: null;
}

/**
 * دریافت اطلاعات روم
 * @param int $roomId
 * @return array|null
 */
function getRoomById($roomId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM rooms WHERE id = :id AND is_active = 1");
    $stmt->execute([':id' => $roomId]);
    $result = $stmt->fetch();
    return $result ?: null;
}

/**
 * بروزرسانی وضعیت آنلاین کاربر
 * @param int $userId
 * @param string $status
 */
function updateUserOnlineStatus($userId, $status = 'online') {
    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET status = :status, last_seen = NOW() WHERE id = :id");
    $stmt->execute([':status' => $status, ':id' => $userId]);
}

/**
 * دریافت حرف اول نام برای آواتار
 * @param string $name
 * @return string
 */
function getInitials($name) {
    $words = explode(' ', trim($name));
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= mb_substr($word, 0, 1, 'UTF-8');
            if (mb_strlen($initials, 'UTF-8') >= 2) {
                break;
            }
        }
    }
    return mb_strtoupper($initials, 'UTF-8');
}

/**
 * تولید رنگ برای آواتار بر اساس نام
 * @param string $name
 * @return string
 */
function getAvatarColor($name) {
    $colors = [
        '#3390ec', '#4fae4e', '#e17076', '#eaa700',
        '#7b79e0', '#e47d57', '#00a9c7', '#9078c9'
    ];
    $index = abs(crc32($name)) % count($colors);
    return $colors[$index];
}

// ==============================================
// توابع پیام
// ==============================================

/**
 * فرمت زمان پیام به صورت خوانا
 * @param string $timestamp
 * @return string
 */
function formatMessageTime($timestamp) {
    $msgTime = strtotime($timestamp);
    $now = time();
    $diff = $now - $msgTime;

    if ($diff < 60) {
        return 'الان';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' دقیقه پیش';
    } elseif (date('Ymd', $msgTime) === date('Ymd', $now)) {
        return date('H:i', $msgTime);
    } elseif (date('Ymd', $msgTime) === date('Ymd', strtotime('yesterday'))) {
        return 'دیروز ' . date('H:i', $msgTime);
    } else {
        return jdate('j F', $msgTime) . ' ' . date('H:i', $msgTime);
    }
}

/**
 * فرمت زمان ساده
 * @param string $timestamp
 * @return string
 */
function formatTime($timestamp) {
    return date('H:i', strtotime($timestamp));
}

/**
 * تبدیل تاریخ میلادی به شمسی
 * @param string $format
 * @param int|null $timestamp
 * @return string
 */
function jdate($format, $timestamp = null) {
    if ($timestamp === null) {
        $timestamp = time();
    }

    $g_d = (int)date('j', $timestamp);
    $g_m = (int)date('n', $timestamp);
    $g_y = (int)date('Y', $timestamp);

    $d_4 = $g_y % 4;
    $g_a = [0, 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $doy_g = $g_a[$g_m] + $g_d;
    if ($d_4 == 0 && $g_m > 2) {
        $doy_g++;
    }

    $d_33 = (int)(($g_y - 16) % 132 * 0.0305);
    $a = ($d_33 != 3 && $d_4 <= $d_33) ? 286 : 287;
    $b = (($d_33 == 1 || $d_33 == 2) && ($d_33 == $d_4 || $d_4 == 1)) ? 78 : (($d_33 == 3 && $d_4 == 0) ? 80 : 79);

    if ((int)(($g_y - 10) / 63) == 30) {
        $a--;
        $b++;
    }

    if ($doy_g > $b) {
        $jy = $g_y - 621;
        $doy_j = $doy_g - $b;
    } else {
        $jy = $g_y - 622;
        $doy_j = $doy_g + $a;
    }

    if ($doy_j < 187) {
        $jm = (int)(($doy_j - 1) / 31);
        $jd = $doy_j - (31 * $jm++);
    } else {
        $jm = (int)(($doy_j - 187) / 30);
        $jd = $doy_j - 186 - ($jm * 30);
        $jm += 7;
    }

    $months = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
               'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

    $result = str_replace('j', (string)$jd, $format);
    $result = str_replace('F', $months[$jm], $result);
    $result = str_replace('Y', (string)$jy, $result);

    return $result;
}

// ==============================================
// توابع JSON Response
// ==============================================

/**
 * ارسال پاسخ JSON موفق
 * @param array $data
 * @param string $message
 */
function jsonSuccess($data = [], $message = 'موفق') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}

/**
 * ارسال پاسخ JSON خطا
 * @param string $message
 * @param int $code
 */
function jsonError($message = 'خطا', $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $message,
        'data' => null
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}

// ==============================================
// توابع امنیتی
// ==============================================

/**
 * تنظیم هدرهای امنیتی
 * CSP تضمین می‌کند مرورگر حتی در صورت خطای برنامه‌نویسی هم به هیچ مقصد خارجی وصل نشود
 */
function setSecurityHeaders() {
    if (PHP_SAPI === 'cli') {
        return;
    }
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; media-src 'self'; manifest-src 'self'; worker-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), geolocation=(), payment=(), usb=()');
    if (ENABLE_HSTS && isHttps()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/**
 * رمزنگاری پسورد
 * @param string $password
 * @return string
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * تایید پسورد
 * @param string $password
 * @param string $hash
 * @return bool
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * اعتبارسنجی پسورد
 * @param string $password
 * @return array
 */
function validatePassword($password) {
    $errors = [];

    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'رمز عبور باید حداقل ' . PASSWORD_MIN_LENGTH . ' کاراکتر باشد';
    }
    // bcrypt فقط 72 بایت اول را در نظر می‌گیرد
    if (strlen($password) > 72) {
        $errors[] = 'رمز عبور نباید بیشتر از 72 بایت باشد';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'رمز عبور باید شامل حداقل یک حرف بزرگ باشد';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'رمز عبور باید شامل حداقل یک حرف کوچک باشد';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'رمز عبور باید شامل حداقل یک عدد باشد';
    }

    return $errors;
}

/**
 * اعتبارسنجی نام کاربری
 * @param string $username
 * @return array
 */
function validateUsername($username) {
    $errors = [];

    if (strlen($username) < 3) {
        $errors[] = 'نام کاربری باید حداقل ۳ کاراکتر باشد';
    }
    if (strlen($username) > 50) {
        $errors[] = 'نام کاربری نباید بیشتر از ۵۰ کاراکتر باشد';
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = 'نام کاربری فقط می‌تواند شامل حروف انگلیسی، اعداد و _ باشد';
    }

    return $errors;
}

/**
 * ثبت لاگ امنیتی
 * @param string $action
 * @param int|null $userId
 * @param int|null $adminId
 * @param array|null $details
 */
function logSecurityEvent($action, $userId = null, $adminId = null, $details = null) {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO security_logs (user_id, admin_id, action, ip_address, user_agent, details)
            VALUES (:user_id, :admin_id, :action, :ip, :ua, :details)
        ");

        $stmt->execute([
            ':user_id' => $userId,
            ':admin_id' => $adminId,
            ':action' => $action,
            ':ip' => getClientIP(),
            ':ua' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 500, 'UTF-8') : null,
            ':details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null
        ]);
    } catch (PDOException $e) {
        error_log("Security log error: " . $e->getMessage());
    }
}

// ==============================================
// توابع آپلود
// ==============================================

/**
 * بررسی فایل آپلود شده و تشخیص نوع واقعی آن از روی محتوا
 * @param mixed $file
 * @param bool $isVoice ضبط مرورگر؛ فایل webm و mp4 فقط صوتی با این حالت پذیرفته می‌شود
 * @return array ['error' => string, 'mime' => string|null, 'info' => array|null]
 */
function inspectUploadedFile($file, $isVoice = false) {
    $fail = function ($error) {
        return ['error' => $error, 'mime' => null, 'info' => null];
    };

    // file[]=... باعث می‌شود مقادیر آرایه باشند
    if (!is_array($file) || !isset($file['error']) || !is_int($file['error'])) {
        return $fail('فایل نامعتبر است.');
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return $fail('حجم فایل بیشتر از حد مجاز است.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return $fail('خطا در آپلود فایل.');
    }
    if ($file['size'] <= 0 || $file['size'] > MAX_FILE_SIZE) {
        return $fail('حجم فایل بیشتر از حد مجاز است.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = getAllowedUploadTypes();
    
    // libmagic ظرف webm و mp4 را ویدیو تشخیص می‌دهد و ذخیره به صورت صوت است
    if ($isVoice && in_array($mime, ['video/webm', 'video/mp4'], true)) {
        $mime = str_replace('video/', 'audio/', $mime);
    }
    
    if (!isset($allowed[$mime])) {
        return $fail('نوع فایل مجاز نیست.');
    }

    return ['error' => '', 'mime' => $mime, 'info' => $allowed[$mime]];
}

/**
 * تولید نام فایل امن؛ پسوند از فهرست سفید می‌آید نه از ورودی کاربر
 * @param string $extension
 * @return string
 */
function generateSecureFileName($extension) {
    return bin2hex(random_bytes(16)) . '.' . $extension;
}

/**
 * نام نمایشی فایل برای ذخیره: بدون مسیر و کاراکتر کنترلی
 * @param mixed $name
 * @return string
 */
function cleanOriginalFileName($name) {
    $name = is_string($name) ? $name : '';
    if (!mb_check_encoding($name, 'UTF-8')) {
        $name = '';
    }
    $name = str_replace('\\', '/', $name);
    $name = substr((string)strrchr('/' . $name, '/'), 1);
    $name = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $name));
    return $name === '' ? 'file' : mb_substr($name, -200, null, 'UTF-8');
}

// ==============================================
// اجزای مشترک رابط کاربری
// ==============================================

/**
 * پیام یک‌بارمصرف بعد از ریدایرکت
 * @param string $type success|error
 * @param string $message
 */
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * HTML پیام یک‌بارمصرف
 * @return string
 */
function renderFlash() {
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $class = $flash['type'] === 'success' ? 'alert-success' : 'alert-error';
    return '<div class="alert ' . $class . '">' . sanitize($flash['message']) . '</div>';
}

/**
 * لینک‌های نوار پنل مدیریت
 * @param string $active
 * @return string
 */
function adminNavLinks($active = '') {
    $links = [
        'dashboard' => ['admin.php', 'داشبورد'],
        'users' => ['admin-users.php', 'کاربران'],
        'rooms' => ['admin-rooms.php', 'روم‌ها'],
        'assign' => ['admin-assign-user.php', 'تخصیص کاربر'],
        'logs' => ['admin-security-logs.php', 'لاگ امنیتی'],
        'password' => ['admin-change-password.php', 'تغییر رمز'],
    ];
    $html = '<div class="navbar-links" style="flex-wrap:wrap">';
    foreach ($links as $key => $link) {
        $html .= '<a href="' . $link[0] . '"' . ($key === $active ? ' class="active"' : '') . '>' . $link[1] . '</a>';
    }
    return $html . adminLogoutForm() . '</div>';
}

/**
 * شروع مشترک صفحات جدید پنل مدیریت
 * @param string $title
 * @param string $active
 * @param bool $narrow
 */
function adminPageStart($title, $active, $narrow = false) {
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . sanitize($title) . ' | ' . sanitize(SITE_NAME) . '</title>'
        . '<link rel="stylesheet" href="assets/css/fonts.css"><link rel="stylesheet" href="assets/css/admin.css">'
        . '</head><body><nav class="navbar"><h1>پنل مدیریت</h1>' . adminNavLinks($active) . '</nav>'
        . '<div class="container' . ($narrow ? ' narrow' : '') . '">' . renderFlash();
}

/**
 * پایان مشترک صفحات جدید پنل مدیریت
 */
function adminPageEnd() {
    echo '</div></body></html>';
}

/**
 * لینک‌های صفحه‌بندی با حفظ فیلترهای فعلی
 * @param int $page
 * @param int $totalPages
 * @param array $query
 * @return string
 */
function paginationLinks($page, $totalPages, $query = []) {
    if ($totalPages <= 1) {
        return '';
    }
    $url = function ($p) use ($query) {
        return '?' . sanitize(http_build_query(array_merge($query, ['page' => $p])));
    };
    $html = '<div class="pagination">';
    if ($page > 1) {
        $html .= '<a class="btn btn-secondary btn-small" href="' . $url($page - 1) . '">قبلی</a>';
    }
    $html .= '<span class="muted-text">صفحه ' . (int)$page . ' از ' . (int)$totalPages . '</span>';
    if ($page < $totalPages) {
        $html .= '<a class="btn btn-secondary btn-small" href="' . $url($page + 1) . '">بعدی</a>';
    }
    return $html . '</div>';
}

/**
 * ذخیره رمز جدید؛ با افزایش session_version همه نشست‌های قبلی آن حساب باطل می‌شوند
 * @param string $type user|admin
 * @param int $accountId
 * @param string $password
 */
function setAccountPassword($type, $accountId, $password) {
    $table = $type === 'admin' ? 'admins' : 'users';
    $stmt = getDB()->prepare("UPDATE {$table} SET password = :password, session_version = session_version + 1 WHERE id = :id");
    $stmt->execute([':password' => hashPassword($password), ':id' => $accountId]);
}

/**
 * تغییر رمز توسط خود کاربر یا ادمین؛ نشست فعلی معتبر می‌ماند و بقیه باطل می‌شوند
 * @param string $type user|admin
 * @param int $accountId
 * @param string $current
 * @param string $new
 * @param string $confirm
 * @return string خطا یا رشته خالی
 */
function changeOwnPassword($type, $accountId, $current, $new, $confirm) {
    if (!checkRateLimit('change_password_' . $type . '_' . $accountId, 5, 900)) {
        return 'تعداد تلاش‌ها بیش از حد مجاز است. لطفاً بعداً تلاش کنید.';
    }

    $table = $type === 'admin' ? 'admins' : 'users';
    $stmt = getDB()->prepare("SELECT password, session_version FROM {$table} WHERE id = :id AND is_active = 1");
    $stmt->execute([':id' => $accountId]);
    $account = $stmt->fetch();

    if (!$account || !verifyPassword($current, $account['password'])) {
        logSecurityEvent($type . '_password_change_failed', $type === 'user' ? $accountId : null, $type === 'admin' ? $accountId : null);
        return 'رمز عبور فعلی اشتباه است.';
    }
    if ($new !== $confirm) {
        return 'رمز جدید و تکرار آن یکسان نیستند.';
    }
    if ($new === $current) {
        return 'رمز جدید باید با رمز فعلی متفاوت باشد.';
    }
    $errors = validatePassword($new);
    if ($errors) {
        return implode('، ', $errors);
    }

    setAccountPassword($type, $accountId, $new);

    session_regenerate_id(true);
    $versionKey = $type === 'admin' ? 'admin_session_version' : 'session_version';
    $_SESSION[$versionKey] = (int)$account['session_version'] + 1;

    logSecurityEvent($type . '_password_changed', $type === 'user' ? $accountId : null, $type === 'admin' ? $accountId : null);
    return '';
}

/**
 * دکمه خروج ادمین به صورت فرم POST
 * @return string
 */
function adminLogoutForm() {
    return '<form method="POST" action="admin-logout.php" style="display:inline;margin:0">'
        . csrfField()
        . '<button type="submit" style="background:none;border:none;color:rgba(255,255,255,0.8);font:inherit;font-size:0.9rem;padding:8px 16px;cursor:pointer">خروج</button>'
        . '</form>';
}

// ==============================================
// تنظیم هدرها و شروع Session
// ==============================================
setSecurityHeaders();
if (!defined('NO_SESSION')) {
    secureSessionStart();
}
