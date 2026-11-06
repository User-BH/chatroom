<?php
/**
 * تخصیص کاربر به روم
 * چت روم پیشرفته - نسخه 2.0
 * سازگار با PHP 8.5
 */

require_once __DIR__ . '/functions.php';
requireAdminLogin();

$db = getDB();
$error = '';
$success = '';

// دریافت لیست کاربران
$stmt = $db->query("SELECT id, name, username FROM users WHERE is_active = 1 ORDER BY name");
$users = $stmt->fetchAll();

// دریافت لیست روم‌ها
$stmt = $db->query("SELECT id, name FROM rooms WHERE is_active = 1 ORDER BY name");
$rooms = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // بررسی CSRF
    if (!validateCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'توکن امنیتی نامعتبر است.';
    } else {
        $userId = (int)validatePositiveInt($_POST['user_id'] ?? 0);
        $roomId = (int)validatePositiveInt($_POST['room_id'] ?? 0);
        $role = cleanInput($_POST['role'] ?? '');

        // اعتبارسنجی نقش
        $validRoles = ['member', 'moderator', 'admin'];
        if (!in_array($role, $validRoles)) {
            $role = 'member';
        }

        if ($userId <= 0 || $roomId <= 0) {
            $error = 'لطفاً کاربر و روم را انتخاب کنید.';
        } else {
            // بررسی وجود کاربر و روم
            $stmt = $db->prepare("SELECT id FROM users WHERE id = :id AND is_active = 1");
            $stmt->execute([':id' => $userId]);
            $userExists = $stmt->fetch();

            $stmt = $db->prepare("SELECT id FROM rooms WHERE id = :id AND is_active = 1");
            $stmt->execute([':id' => $roomId]);
            $roomExists = $stmt->fetch();

            if (!$userExists || !$roomExists) {
                $error = 'کاربر یا روم نامعتبر است.';
            } else {
                // بررسی عضویت قبلی
                $stmt = $db->prepare("SELECT id FROM users_rooms WHERE user_id = :user_id AND room_id = :room_id");
                $stmt->execute([':user_id' => $userId, ':room_id' => $roomId]);
                $existing = $stmt->fetch();

                if ($existing) {
                    // به‌روزرسانی نقش
                    $stmt = $db->prepare("UPDATE users_rooms SET role = :role WHERE user_id = :user_id AND room_id = :room_id");
                    $result = $stmt->execute([
                        ':role' => $role,
                        ':user_id' => $userId,
                        ':room_id' => $roomId
                    ]);

                    if ($result) {
                        logSecurityEvent('user_room_role_changed', $userId, $_SESSION['admin_id'], [
                            'room_id' => $roomId,
                            'role' => $role
                        ]);
                        $success = 'نقش کاربر در روم به‌روزرسانی شد.';
                    } else {
                        $error = 'خطا در به‌روزرسانی.';
                    }
                } else {
                    // اضافه کردن عضویت جدید
                    $stmt = $db->prepare("
                        INSERT INTO users_rooms (user_id, room_id, role, joined_at)
                        VALUES (:user_id, :room_id, :role, NOW())
                    ");

                    $result = $stmt->execute([
                        ':user_id' => $userId,
                        ':room_id' => $roomId,
                        ':role' => $role
                    ]);

                    if ($result) {
                        logSecurityEvent('user_assigned_to_room', $userId, $_SESSION['admin_id'], [
                            'room_id' => $roomId,
                            'role' => $role
                        ]);
                        $success = 'کاربر با موفقیت به روم اضافه شد.';
                    } else {
                        $error = 'خطا در اضافه کردن کاربر به روم.';
                    }
                }
            }
        }
    }
}

// دریافت عضویت‌های فعلی
$stmt = $db->query("
    SELECT ur.*, u.name as user_name, u.username, r.name as room_name
    FROM users_rooms ur
    JOIN users u ON ur.user_id = u.id
    JOIN rooms r ON ur.room_id = r.id
    ORDER BY ur.joined_at DESC
    LIMIT 20
");
$assignments = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تخصیص کاربر به روم | <?php echo SITE_NAME; ?></title>
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

        .container { max-width: 900px; margin: 40px auto; padding: 0 20px; }

        .card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 24px;
        }

        .card h2 {
            font-size: 1.2rem;
            margin-bottom: 24px;
            color: #333;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }

        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-family: inherit;
            font-size: 1rem;
            transition: border-color 0.3s;
            background: white;
        }

        .form-group select:focus {
            outline: none;
            border-color: #e94560;
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

        table { width: 100%; border-collapse: collapse; }

        th, td {
            padding: 14px 16px;
            text-align: right;
            border-bottom: 1px solid #f0f0f0;
        }

        th {
            background: #fafafa;
            font-weight: 600;
            font-size: 0.85rem;
            color: #666;
        }

        td { font-size: 0.9rem; }

        tr:last-child td { border-bottom: none; }

        .role-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .role-member { background: #e3f2fd; color: #1565c0; }
        .role-moderator { background: #fff3e0; color: #e65100; }
        .role-admin { background: #fce4ec; color: #c2185b; }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>پنل مدیریت</h1>
        <?php echo adminNavLinks('assign'); ?>
    </nav>

    <div class="container">
        <div class="card">
            <h2>تخصیص کاربر به روم</h2>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo sanitize($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo sanitize($success); ?></div>
            <?php endif; ?>

            <form method="POST">
                <?php echo csrfField(); ?>

                <div class="form-row">
                    <div class="form-group">
                        <label>انتخاب کاربر</label>
                        <select name="user_id" required>
                            <option value="">-- کاربر را انتخاب کنید --</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo (int)$user['id']; ?>">
                                    <?php echo sanitize($user['name']); ?> (<?php echo sanitize($user['username']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>انتخاب روم</label>
                        <select name="room_id" required>
                            <option value="">-- روم را انتخاب کنید --</option>
                            <?php foreach ($rooms as $room): ?>
                                <option value="<?php echo (int)$room['id']; ?>">
                                    <?php echo sanitize($room['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>نقش</label>
                        <select name="role">
                            <option value="member">عضو عادی</option>
                            <option value="moderator">مدیر</option>
                            <option value="admin">ادمین</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">تخصیص کاربر</button>
            </form>
        </div>

        <div class="card">
            <h2>عضویت‌های اخیر</h2>
            <table>
                <thead>
                    <tr>
                        <th>کاربر</th>
                        <th>روم</th>
                        <th>نقش</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assignments as $a): ?>
                    <tr>
                        <td><?php echo sanitize($a['user_name']); ?></td>
                        <td><?php echo sanitize($a['room_name']); ?></td>
                        <td>
                            <?php
                            $roleClass = 'role-member';
                            $roleText = 'عضو';
                            if ($a['role'] === 'moderator') {
                                $roleClass = 'role-moderator';
                                $roleText = 'مدیر';
                            } elseif ($a['role'] === 'admin') {
                                $roleClass = 'role-admin';
                                $roleText = 'ادمین';
                            }
                            ?>
                            <span class="role-badge <?php echo $roleClass; ?>"><?php echo $roleText; ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
