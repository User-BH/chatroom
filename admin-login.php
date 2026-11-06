<?php
/**
 * صفحه ورود ادمین
 * چت روم پیشرفته - نسخه 2.0
 * سازگار با PHP 7.4+
 */

require_once __DIR__ . '/functions.php';

// اگر ادمین لاگین است به پنل برود
if (isAdminLoggedIn()) {
    redirect('admin.php');
}

$error = '';
$db = getDB();

// پردازش فرم ورود
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // اعتبارسنجی CSRF
    if (!validateCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } else {
        $username = cleanInput($_POST['username'] ?? '');
        $password = postString('password');

        $result = attemptLogin('admin', $username, $password);

        if (!$result['account']) {
            $error = $result['error'];
        } else {
            $admin = $result['account'];
            rotateSessionAfterLogin();

            $_SESSION['admin_id'] = (int)$admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_session_version'] = (int)$admin['session_version'];

            // بروزرسانی last_login
            $stmt = $db->prepare("UPDATE admins SET last_login = NOW() WHERE id = :id");
            $stmt->execute([':id' => $admin['id']]);

            logSecurityEvent('admin_login', null, $admin['id'], ['username' => $username]);

            redirect('admin.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ورود مدیریت | <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Vazirmatn', Tahoma, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 50px 40px;
            max-width: 420px;
            width: 100%;
            backdrop-filter: blur(20px);
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: rgba(255,255,255,0.6);
            text-decoration: none;
            font-size: 0.9rem;
            margin-bottom: 25px;
            transition: color 0.3s;
        }

        .back-link:hover {
            color: #fff;
        }

        .back-link svg {
            width: 18px;
            height: 18px;
        }

        .logo {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #e94560, #ff6b6b);
            border-radius: 16px;
            margin: 0 auto 25px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo svg {
            width: 36px;
            height: 36px;
            fill: white;
        }

        h2 {
            color: #fff;
            font-size: 1.6rem;
            font-weight: 600;
            margin-bottom: 8px;
            text-align: center;
        }

        .subtitle {
            color: rgba(255,255,255,0.5);
            text-align: center;
            margin-bottom: 35px;
            font-size: 0.9rem;
        }

        .error-message {
            background: rgba(233, 69, 96, 0.2);
            border: 1px solid rgba(233, 69, 96, 0.3);
            color: #ff6b6b;
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 25px;
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

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: rgba(255,255,255,0.7);
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper svg {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            fill: rgba(255,255,255,0.4);
        }

        input {
            width: 100%;
            padding: 15px 15px 15px 15px;
            padding-right: 45px;
            border: 2px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            font-family: inherit;
            font-size: 1rem;
            transition: all 0.3s;
            background: rgba(255,255,255,0.05);
            color: #fff;
        }

        input::placeholder {
            color: rgba(255,255,255,0.3);
        }

        input:focus {
            outline: none;
            border-color: #e94560;
            background: rgba(255,255,255,0.08);
        }

        .btn-submit {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #e94560, #ff6b6b);
            color: white;
            border: none;
            border-radius: 12px;
            font-family: inherit;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(233, 69, 96, 0.4);
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 35px 25px;
            }

            h2 {
                font-size: 1.4rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <a href="index.php" class="back-link">
            <svg viewBox="0 0 24 24" fill="currentColor">
                <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
            </svg>
            بازگشت
        </a>

        <div class="logo">
            <svg viewBox="0 0 24 24">
                <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/>
            </svg>
        </div>

        <h2>پنل مدیریت</h2>
        <p class="subtitle">برای ورود اطلاعات خود را وارد کنید</p>

        <?php if ($error): ?>
            <div class="error-message">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                </svg>
                <?php echo sanitize($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <?php echo csrfField(); ?>

            <div class="form-group">
                <label>نام کاربری</label>
                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                    <input type="text" name="username" placeholder="نام کاربری مدیر" required autofocus>
                </div>
            </div>

            <div class="form-group">
                <label>رمز عبور</label>
                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24">
                        <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
                    </svg>
                    <input type="password" name="password" placeholder="رمز عبور" required>
                </div>
            </div>

            <button type="submit" class="btn-submit">ورود به پنل</button>
        </form>
    </div>
</body>
</html>
