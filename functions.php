<?php
/**
 * ===================================================
 * ChatRoom - توابع کمکی و امنیتی
 * ===================================================
 * نسخه: 2.0
 * سازگار با: PHP 7.4+
 * استفاده از: PDO
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ==============================================
// توابع امنیتی Session
// ==============================================

/**
 * شروع امن session
 */
function secureSessionStart() {
    if (session_status() === PHP_SESSION_NONE) {
        // تنظیمات امنیتی session
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
        
        session_name(SESSION_NAME);
        session_start();
        
        // بازسازی session ID برای جلوگیری از session fixation
        if (!isset($_SESSION['initiated'])) {
            session_regenerate_id(true);
            $_SESSION['initiated'] = true;
            $_SESSION['created_at'] = time();
        }
        
        // بررسی انقضای session
        if (isset($_SESSION['created_at']) && (time() - $_SESSION['created_at'] > SESSION_LIFETIME)) {
            sessionDestroy();
            secureSessionStart();
        }
        
        // Fingerprint برای جلوگیری از session hijacking
        $fingerprint = md5(
            ($_SERVER['HTTP_USER_AGENT'] ?? '') . 
            ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')
        );
        
        if (!isset($_SESSION['fingerprint'])) {
            $_SESSION['fingerprint'] = $fingerprint;
        } elseif ($_SESSION['fingerprint'] !== $fingerprint) {
            sessionDestroy();
            header('Location: login.php?error=session');
            exit();
        }
    }
}

/**
 * پایان امن session
 */
function sessionDestroy() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
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

// ==============================================
// توابع CSRF
// ==============================================

/**
 * تولید توکن CSRF
 * @return string
 */
function generateCSRFToken() {
    if (empty($_SESSION[CSRF_TOKEN_NAME]) || empty($_SESSION['csrf_token_time']) || 
        (time() - $_SESSION['csrf_token_time'] > CSRF_TOKEN_EXPIRY)) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * اعتبارسنجی توکن CSRF
 * @param string|null $token
 * @return bool
 */
function validateCSRFToken($token) {
    if (empty($_SESSION[CSRF_TOKEN_NAME]) || empty($token)) {
        return false;
    }
    
    // بررسی انقضا
    if (empty($_SESSION['csrf_token_time']) || (time() - $_SESSION['csrf_token_time'] > CSRF_TOKEN_EXPIRY)) {
        return false;
    }
    
    return hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
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
 * بررسی محدودیت تلاش ورود
 * @param string $ip
 * @param string $type
 * @return bool
 */
function checkLoginAttempts($ip, $type = 'user') {
    $db = getDB();
    
    $stmt = $db->prepare("
        SELECT COUNT(*) as attempts 
        FROM login_attempts 
        WHERE ip_address = :ip 
        AND attempt_type = :type
        AND success = 0 
        AND attempted_at > DATE_SUB(NOW(), INTERVAL :seconds SECOND)
    ");
    
    $stmt->execute([
        ':ip' => $ip,
        ':type' => $type,
        ':seconds' => LOGIN_LOCKOUT_TIME
    ]);
    
    $result = $stmt->fetch();
    return $result['attempts'] < MAX_LOGIN_ATTEMPTS;
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
}

/**
 * Rate Limiting با Session
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
 * دریافت IP واقعی کاربر
 * @return string
 */
function getClientIP() {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            // اگر چند IP باشد (پشت پروکسی)
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    
    return '0.0.0.0';
}

// ==============================================
// توابع پاکسازی ورودی
// ==============================================

/**
 * پاکسازی رشته برای نمایش (XSS Prevention)
 * @param string $string
 * @return string
 */
function sanitize($string) {
    return htmlspecialchars(trim($string), ENT_QUOTES, 'UTF-8');
}

/**
 * پاکسازی ورودی برای ذخیره در دیتابیس
 * @param string $data
 * @return string
 */
function cleanInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    return $data;
}

/**
 * اعتبارسنجی عدد صحیح مثبت
 * @param mixed $value
 * @return int|false
 */
function validatePositiveInt($value) {
    $value = filter_var($value, FILTER_VALIDATE_INT);
    return ($value !== false && $value > 0) ? $value : false;
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
 */
function requireAdminLogin() {
    if (!isAdminLoggedIn()) {
        redirect('admin-login.php');
    }
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
    ], JSON_UNESCAPED_UNICODE);
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
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// ==============================================
// توابع امنیتی
// ==============================================

/**
 * تنظیم هدرهای امنیتی
 */
function setSecurityHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * رمزنگاری پسورد
 * @param string $password
 * @return string
 */
function hashPassword($password) {
    // استفاده از BCRYPT برای سازگاری با PHP 7.4
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
            ':ua' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null,
            ':details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null
        ]);
    } catch (PDOException $e) {
        error_log("Security log error: " . $e->getMessage());
    }
}

/**
 * اعتبارسنجی فایل آپلود شده
 * @param array $file
 * @param string $type
 * @return array
 */
function validateUploadedFile($file, $type = 'image') {
    $errors = [];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'خطا در آپلود فایل';
        return $errors;
    }
    
    if ($file['size'] > MAX_FILE_SIZE) {
        $errors[] = 'حجم فایل بیشتر از حد مجاز است';
    }
    
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    
    // PHP 7.4 compatible - بدون match
    switch ($type) {
        case 'image':
            $allowedTypes = getAllowedImageTypes();
            break;
        case 'file':
            $allowedTypes = getAllowedFileTypes();
            break;
        case 'audio':
            $allowedTypes = getAllowedAudioTypes();
            break;
        default:
            $allowedTypes = [];
    }
    
    if (!in_array($mimeType, $allowedTypes)) {
        $errors[] = 'نوع فایل مجاز نیست';
    }
    
    return $errors;
}

/**
 * تولید نام فایل امن
 * @param string $originalName
 * @return string
 */
function generateSecureFileName($originalName) {
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    return bin2hex(random_bytes(16)) . '_' . time() . '.' . $extension;
}

// ==============================================
// شروع Session و تنظیم هدرها
// ==============================================
secureSessionStart();
setSecurityHeaders();
