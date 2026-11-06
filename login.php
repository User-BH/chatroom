<?php
/**
 * صفحه ورود کاربر
 * چت روم پیشرفته - نسخه 2.0
 * سازگار با PHP 7.4+
 */

require_once __DIR__ . '/functions.php';

// اگر لاگین است، به چت برود
if (isUserLoggedIn()) {
    redirect('chat.php');
}

$error = '';
$success = '';

// پیام خروج موفق
if (isset($_GET['logout']) && $_GET['logout'] == '1') {
    $success = 'با موفقیت از سیستم خارج شدید.';
}

$db = getDB();

// دریافت لیست روم‌ها
$stmt = $db->prepare("SELECT id, name FROM rooms WHERE is_active = 1 ORDER BY name");
$stmt->execute();
$rooms = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // بررسی CSRF
    if (!validateCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'توکن امنیتی نامعتبر است. لطفاً صفحه را رفرش کنید.';
    } else {
        $username = cleanInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $selectedRoom = (int)($_POST['room_id'] ?? 0);
        $ip = getClientIP();
        
        // Rate Limiting
        if (!checkRateLimit('login_' . $ip, MAX_LOGIN_ATTEMPTS, LOGIN_LOCKOUT_TIME)) {
            $error = 'تعداد تلاش‌های ورود بیش از حد مجاز است. لطفاً ' . ceil(LOGIN_LOCKOUT_TIME / 60) . ' دقیقه صبر کنید.';
        } elseif (empty($username) || empty($password)) {
            $error = 'لطفاً نام کاربری و رمز عبور را وارد کنید.';
        } elseif ($selectedRoom <= 0) {
            $error = 'لطفاً یک روم انتخاب کنید.';
        } else {
            // جستجوی کاربر
            $stmt = $db->prepare("
                SELECT id, username, password, name, is_active, login_attempts, locked_until 
                FROM users 
                WHERE username = :username
            ");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $error = 'نام کاربری یا رمز عبور اشتباه است.';
                logSecurityEvent('login_failed_username', null, null, ['username' => $username]);
            } elseif (!$user['is_active']) {
                $error = 'حساب کاربری شما غیرفعال شده است.';
            } elseif ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                $error = 'حساب شما موقتاً قفل شده است. لطفاً بعداً تلاش کنید.';
            } elseif (!verifyPassword($password, $user['password'])) {
                // افزایش تعداد تلاش‌های ناموفق
                $attempts = $user['login_attempts'] + 1;
                $lockedUntil = null;
                
                if ($attempts >= MAX_LOGIN_ATTEMPTS) {
                    $lockedUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_TIME);
                    $error = 'حساب شما به دلیل تلاش‌های مکرر ناموفق قفل شد.';
                } else {
                    $error = 'نام کاربری یا رمز عبور اشتباه است.';
                }
                
                $stmt = $db->prepare("UPDATE users SET login_attempts = :attempts, locked_until = :locked WHERE id = :id");
                $stmt->execute([
                    ':attempts' => $attempts,
                    ':locked' => $lockedUntil,
                    ':id' => $user['id']
                ]);
                
                logSecurityEvent('login_failed_password', $user['id'], null, ['attempts' => $attempts]);
            } else {
                // بررسی عضویت در روم
                $stmt = $db->prepare("SELECT 1 FROM users_rooms WHERE user_id = :user_id AND room_id = :room_id");
                $stmt->execute([':user_id' => $user['id'], ':room_id' => $selectedRoom]);
                
                if (!$stmt->fetch()) {
                    $error = 'شما عضو این روم نیستید.';
                } else {
                    // ورود موفق
                    session_regenerate_id(true);
                    
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['room_id'] = $selectedRoom;
                    $_SESSION['login_time'] = time();
                    
                    // ریست تلاش‌های ناموفق و به‌روزرسانی آخرین ورود
                    $stmt = $db->prepare("
                        UPDATE users 
                        SET login_attempts = 0, locked_until = NULL, status = 'online', last_seen = NOW() 
                        WHERE id = :id
                    ");
                    $stmt->execute([':id' => $user['id']]);
                    
                    logSecurityEvent('login_success', $user['id'], null, ['room_id' => $selectedRoom]);
                    
                    redirect('chat.php');
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود به چت روم | <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Vazirmatn', Tahoma, sans-serif;
            background: linear-gradient(135deg, #075e54 0%, #128c7e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 420px;
            width: 100%;
            overflow: hidden;
        }
        
        .login-header {
            background: linear-gradient(135deg, #075e54 0%, #128c7e 100%);
            padding: 40px 30px;
            text-align: center;
            color: white;
        }
        
        .login-logo {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        
        .login-logo svg {
            width: 45px;
            height: 45px;
            fill: white;
        }
        
        .login-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .login-header p {
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        .login-form {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
            font-size: 0.9rem;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 1rem;
            font-family: inherit;
            transition: all 0.3s;
            background: #fafafa;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #075e54;
            background: white;
        }
        
        .btn-submit {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #075e54 0%, #128c7e 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(7, 94, 84, 0.4);
        }
        
        .error-message {
            background: #ffebee;
            color: #c62828;
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .error-message svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            fill: currentColor;
        }
        
        .success-message {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .success-message svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            fill: currentColor;
        }
        
        .admin-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .admin-link a {
            color: #075e54;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: color 0.3s;
        }
        
        .admin-link a:hover {
            color: #128c7e;
        }
        
        @media (max-width: 480px) {
            .login-header {
                padding: 30px 20px;
            }
            
            .login-form {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="login-logo">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12c0 1.85.5 3.58 1.37 5.07L2 22l4.93-1.37C8.42 21.5 10.15 22 12 22c5.52 0 10-4.48 10-10S17.52 2 12 2zm5.55 14.36c-.23.65-1.17 1.22-1.89 1.38-.5.11-1.14.2-3.31-.71-2.78-1.16-4.57-3.99-4.71-4.18-.14-.19-1.14-1.52-1.14-2.9s.72-2.06.98-2.34c.26-.28.56-.35.75-.35.19 0 .37 0 .54.01.17.01.4-.07.63.48.23.56.79 1.92.86 2.06.07.14.12.31.02.5-.1.19-.14.31-.28.48-.14.17-.3.37-.42.5-.14.14-.29.3-.12.58.17.28.73 1.21 1.57 1.96 1.08.96 1.99 1.26 2.27 1.4.28.14.45.12.61-.07.17-.19.7-.82.89-1.1.19-.28.37-.23.63-.14.26.09 1.62.76 1.89.9.28.14.46.21.53.33.07.12.07.68-.16 1.33z"/>
                </svg>
            </div>
            <h1><?php echo SITE_NAME; ?></h1>
            <p>برای ورود اطلاعات خود را وارد کنید</p>
        </div>
        
        <form class="login-form" method="POST" autocomplete="off">
            <?php echo csrfField(); ?>
            
            <?php if ($success): ?>
                <div class="success-message">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                    </svg>
                    <?php echo sanitize($success); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="error-message">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                    </svg>
                    <?php echo sanitize($error); ?>
                </div>
            <?php endif; ?>
            
            <div class="form-group">
                <label for="username">نام کاربری</label>
                <input type="text" id="username" name="username" placeholder="نام کاربری خود را وارد کنید" required autofocus>
            </div>
            
            <div class="form-group">
                <label for="password">رمز عبور</label>
                <input type="password" id="password" name="password" placeholder="رمز عبور خود را وارد کنید" required>
            </div>
            
            <div class="form-group">
                <label for="room_id">انتخاب روم</label>
                <select id="room_id" name="room_id" required>
                    <option value="">-- یک روم انتخاب کنید --</option>
                    <?php foreach ($rooms as $room): ?>
                        <option value="<?php echo $room['id']; ?>"><?php echo sanitize($room['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <button type="submit" class="btn-submit">ورود به چت روم</button>
            
            <div class="admin-link">
                <a href="admin-login.php">ورود به پنل مدیریت</a>
            </div>
        </form>
    </div>
</body>
</html>
