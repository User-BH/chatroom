<?php
/**
 * صفحه اصلی
 * چت روم پیشرفته - نسخه 2.0
 * سازگار با PHP 8.5
 */

require_once __DIR__ . '/functions.php';

// اگر کاربر لاگین است به چت برود
if (isUserLoggedIn()) {
    redirect('chat.php');
}

// اگر ادمین لاگین است به پنل برود
if (isAdminLoggedIn()) {
    redirect('admin.php');
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - خوش آمدید</title>
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
            flex-direction: column;
            color: #333;
        }

        .header {
            padding: 30px 20px;
            text-align: center;
            color: white;
        }

        .header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .header p {
            opacity: 0.9;
            font-size: 1rem;
        }

        .main-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .welcome-card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 420px;
            width: 100%;
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, #075e54 0%, #128c7e 100%);
            padding: 40px 30px;
            text-align: center;
            color: white;
        }

        .logo-container {
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }

        .logo-container svg {
            width: 60px;
            height: 60px;
            fill: white;
        }

        .card-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .card-header p {
            opacity: 0.9;
            font-size: 0.95rem;
        }

        .card-body {
            padding: 30px;
        }

        .feature-list {
            margin-bottom: 30px;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #eee;
        }

        .feature-item:last-child {
            border-bottom: none;
        }

        .feature-icon {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #075e54 0%, #128c7e 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .feature-icon svg {
            width: 24px;
            height: 24px;
            fill: white;
        }

        .feature-text h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .feature-text p {
            font-size: 0.85rem;
            color: #666;
        }

        .btn {
            display: block;
            width: 100%;
            padding: 16px 24px;
            border: none;
            border-radius: 12px;
            font-family: inherit;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s ease;
            margin-bottom: 12px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #075e54 0%, #128c7e 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(7, 94, 84, 0.4);
        }

        .btn-secondary {
            background: #f5f5f5;
            color: #333;
        }

        .btn-secondary:hover {
            background: #e0e0e0;
        }

        .footer {
            padding: 20px;
            text-align: center;
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.85rem;
        }

        @media (max-width: 480px) {
            .header h1 {
                font-size: 1.5rem;
            }

            .card-header {
                padding: 30px 20px;
            }

            .logo-container {
                width: 80px;
                height: 80px;
            }

            .logo-container svg {
                width: 45px;
                height: 45px;
            }

            .card-body {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <h1><?php echo SITE_NAME; ?></h1>
        <p>پلتفرم ارتباطی امن و سریع</p>
    </header>

    <main class="main-container">
        <div class="welcome-card">
            <div class="card-header">
                <div class="logo-container">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 2C6.48 2 2 6.48 2 12c0 1.85.5 3.58 1.37 5.07L2 22l4.93-1.37C8.42 21.5 10.15 22 12 22c5.52 0 10-4.48 10-10S17.52 2 12 2zm5.55 14.36c-.23.65-1.17 1.22-1.89 1.38-.5.11-1.14.2-3.31-.71-2.78-1.16-4.57-3.99-4.71-4.18-.14-.19-1.14-1.52-1.14-2.9s.72-2.06.98-2.34c.26-.28.56-.35.75-.35.19 0 .37 0 .54.01.17.01.4-.07.63.48.23.56.79 1.92.86 2.06.07.14.12.31.02.5-.1.19-.14.31-.28.48-.14.17-.3.37-.42.5-.14.14-.29.3-.12.58.17.28.73 1.21 1.57 1.96 1.08.96 1.99 1.26 2.27 1.4.28.14.45.12.61-.07.17-.19.7-.82.89-1.1.19-.28.37-.23.63-.14.26.09 1.62.76 1.89.9.28.14.46.21.53.33.07.12.07.68-.16 1.33z"/>
                    </svg>
                </div>
                <h2>به چت‌روم خوش آمدید</h2>
                <p>با دوستان و همکاران خود در ارتباط باشید</p>
            </div>

            <div class="card-body">
                <div class="feature-list">
                    <div class="feature-item">
                        <div class="feature-icon">
                            <svg viewBox="0 0 24 24">
                                <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/>
                            </svg>
                        </div>
                        <div class="feature-text">
                            <h3>امنیت بالا</h3>
                            <p>رمز عبور هش‌شده و دسترسی فقط برای اعضا</p>
                        </div>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon">
                            <svg viewBox="0 0 24 24">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                            </svg>
                        </div>
                        <div class="feature-text">
                            <h3>قابلیت پاسخ</h3>
                            <p>پاسخ به پیام‌های خاص در گفتگوها</p>
                        </div>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon">
                            <svg viewBox="0 0 24 24">
                                <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
                            </svg>
                        </div>
                        <div class="feature-text">
                            <h3>اتاق‌های متعدد</h3>
                            <p>عضویت در چندین اتاق گفتگو</p>
                        </div>
                    </div>
                </div>

                <a href="login.php" class="btn btn-primary">ورود به چت‌روم</a>
                <a href="admin-login.php" class="btn btn-secondary">پنل مدیریت</a>
            </div>
        </div>
    </main>

    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. تمامی حقوق محفوظ است.</p>
    </footer>
</body>
</html>
