<?php
/**
 * ایجاد کاربر جدید
 * چت روم پیشرفته - نسخه 2.0
 * سازگار با PHP 8.5
 */

require_once __DIR__ . '/functions.php';
requireAdminLogin();

$db = getDB();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // بررسی CSRF
    if (!validateCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'توکن امنیتی نامعتبر است.';
    } else {
        $name = cleanInput($_POST['name'] ?? '');
        $username = cleanInput($_POST['username'] ?? '');
        $password = postString('password');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        // اعتبارسنجی
        $usernameErrors = validateUsername($username);
        $passwordErrors = validatePassword($password);

        if ($name === '') {
            $error = 'نام الزامی است.';
        } elseif (mb_strlen($name, 'UTF-8') > 100) {
            $error = 'نام نباید بیشتر از 100 کاراکتر باشد.';
        } elseif (!empty($usernameErrors)) {
            $error = implode('، ', $usernameErrors);
        } elseif (!empty($passwordErrors)) {
            $error = implode('، ', $passwordErrors);
        } else {
            // بررسی تکراری نبودن نام کاربری
            $stmt = $db->prepare("SELECT id FROM users WHERE username = :username");
            $stmt->execute([':username' => $username]);

            if ($stmt->fetch()) {
                $error = 'این نام کاربری قبلاً استفاده شده است.';
            } else {
                // ایجاد کاربر
                $hashedPassword = hashPassword($password);

                $stmt = $db->prepare("
                    INSERT INTO users (name, username, password, is_active, created_at)
                    VALUES (:name, :username, :password, :is_active, NOW())
                ");

                $result = $stmt->execute([
                    ':name' => $name,
                    ':username' => $username,
                    ':password' => $hashedPassword,
                    ':is_active' => $isActive
                ]);

                if ($result) {
                    $newUserId = $db->lastInsertId();
                    logSecurityEvent('user_created', $newUserId, $_SESSION['admin_id'], [
                        'username' => $username,
                        'name' => $name
                    ]);
                    $success = 'کاربر با موفقیت ایجاد شد.';
                } else {
                    $error = 'خطا در ایجاد کاربر.';
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
    <title>ایجاد کاربر جدید | <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Vazirmatn', Tahoma, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
        }

        .navbar {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: white;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar h1 { font-size: 1.3rem; font-weight: 600; }

        .navbar-links { display: flex; gap: 16px; align-items: center; }

        .navbar-links a {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            font-size: 0.9rem;
            padding: 8px 16px;
            border-radius: 8px;
            transition: all 0.3s;
        }

        .navbar-links a:hover { background: rgba(255,255,255,0.1); color: white; }
        .navbar-links a.active { background: rgba(233, 69, 96, 0.8); color: white; }

        .container { max-width: 600px; margin: 40px auto; padding: 0 20px; }

        .card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .card h2 {
            font-size: 1.2rem;
            margin-bottom: 24px;
            color: #333;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }

        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-family: inherit;
            font-size: 1rem;
            transition: border-color 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #e94560;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
        }

        .btn {
            display: inline-block;
            padding: 14px 28px;
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #e94560, #ff6b6b);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(233, 69, 96, 0.3);
        }

        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }

        .alert-error {
            background: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }

        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>پنل مدیریت</h1>
        <?php echo adminNavLinks('users'); ?>
    </nav>

    <div class="container">
        <div class="card">
            <h2>ایجاد کاربر جدید</h2>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo sanitize($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo sanitize($success); ?></div>
            <?php endif; ?>

            <form method="POST">
                <?php echo csrfField(); ?>

                <div class="form-group">
                    <label>نام کامل</label>
                    <input type="text" name="name" required placeholder="مثال: علی محمدی">
                </div>

                <div class="form-group">
                    <label>نام کاربری (انگلیسی)</label>
                    <input type="text" name="username" required placeholder="مثال: ali_mohammadi" pattern="[a-zA-Z0-9_]+" dir="ltr">
                </div>

                <div class="form-group">
                    <label>رمز عبور</label>
                    <input type="password" name="password" required placeholder="حداقل 8 کاراکتر با حرف بزرگ، کوچک و عدد">
                </div>

                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_active" id="is_active" checked>
                        <label for="is_active" style="margin: 0;">حساب فعال باشد</label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">ایجاد کاربر</button>
            </form>
        </div>
    </div>
</body>
</html>
