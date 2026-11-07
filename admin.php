<?php
/**
 * داشبورد مدیریت
 * چت روم پیشرفته - نسخه 2.0
 * سازگار با PHP 8.5
 */

require_once __DIR__ . '/functions.php';
requireAdminLogin();

$db = getDB();

// آمار کلی
$stats = [];

// تعداد کاربران
$stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE is_active = 1");
$stats['users'] = $stmt->fetch()['total'];

// تعداد روم‌ها
$stmt = $db->query("SELECT COUNT(*) as total FROM rooms WHERE is_active = 1 AND type = 'group'");
$stats['rooms'] = $stmt->fetch()['total'];

// تعداد پیام‌ها
$stmt = $db->query("SELECT COUNT(*) as total FROM messages WHERE is_deleted = 0");
$stats['messages'] = $stmt->fetch()['total'];

// کاربران آنلاین
$stmt = $db->prepare("
    SELECT COUNT(*) as total FROM users 
    WHERE is_active = 1 AND status <> 'offline' AND last_seen > DATE_SUB(NOW(), INTERVAL :timeout SECOND)
");
$stmt->execute([':timeout' => ONLINE_TIMEOUT]);
$stats['online'] = $stmt->fetch()['total'];

// لیست کاربران
$stmt = $db->query("
    SELECT u.id, u.name, u.username, u.is_active,
           (SELECT COUNT(*) FROM users_rooms WHERE user_id = u.id) as room_count
    FROM users u 
    ORDER BY u.created_at DESC 
    LIMIT 10
");
$recentUsers = $stmt->fetchAll();

// لیست روم‌ها
$stmt = $db->query("
    SELECT r.*, 
           (SELECT COUNT(*) FROM users_rooms WHERE room_id = r.id) as member_count,
           (SELECT COUNT(*) FROM messages WHERE room_id = r.id AND is_deleted = 0) as message_count
    FROM rooms r 
    WHERE r.type = 'group'
    ORDER BY r.created_at DESC 
    LIMIT 10
");
$recentRooms = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پنل مدیریت | <?php echo SITE_NAME; ?></title>
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

        .container { max-width: 1200px; margin: 0 auto; padding: 24px; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .stat-card h3 {
            font-size: 0.85rem;
            color: #666;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .stat-card .value {
            font-size: 2rem;
            font-weight: 700;
            color: #1a1a2e;
        }

        .stat-card.users .value { color: #3390ec; }
        .stat-card.rooms .value { color: #4fae4e; }
        .stat-card.messages .value { color: #e17076; }
        .stat-card.online .value { color: #eaa700; }

        .section { margin-bottom: 30px; }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .section-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #e94560, #ff6b6b);
            color: white;
        }

        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(233, 69, 96, 0.3); }

        .table-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
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

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-online { background: #e8f5e9; color: #2e7d32; }
        .status-offline { background: #f5f5f5; color: #666; }
        .status-active { background: #e3f2fd; color: #1565c0; }
        .status-inactive { background: #ffebee; color: #c62828; }

        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 24px;
        }

        @media (max-width: 768px) {
            .navbar { flex-direction: column; gap: 12px; }
            .grid-2 { grid-template-columns: 1fr; }
            .container { padding: 16px; }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>پنل مدیریت <?php echo SITE_NAME; ?></h1>
        <?php echo adminNavLinks('dashboard'); ?>
    </nav>

    <div class="container">
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card users">
                <h3>کل کاربران</h3>
                <div class="value"><?php echo number_format($stats['users']); ?></div>
            </div>
            <div class="stat-card rooms">
                <h3>کل روم‌ها</h3>
                <div class="value"><?php echo number_format($stats['rooms']); ?></div>
            </div>
            <div class="stat-card messages">
                <h3>کل پیام‌ها</h3>
                <div class="value"><?php echo number_format($stats['messages']); ?></div>
            </div>
            <div class="stat-card online">
                <h3>کاربران آنلاین</h3>
                <div class="value"><?php echo number_format($stats['online']); ?></div>
            </div>
        </div>

        <div class="grid-2">
            <!-- Recent Users -->
            <div class="section">
                <div class="section-header">
                    <h2>آخرین کاربران</h2>
                    <a href="admin-create-user.php" class="btn btn-primary">+ کاربر جدید</a>
                </div>
                <div class="table-card">
                    <table>
                        <thead>
                            <tr>
                                <th>نام</th>
                                <th>نام کاربری</th>
                                <th>روم‌ها</th>
                                <th>وضعیت</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentUsers as $u): ?>
                            <tr>
                                <td><?php echo sanitize($u['name']); ?></td>
                                <td><?php echo sanitize($u['username']); ?></td>
                                <td><?php echo (int)$u['room_count']; ?></td>
                                <td>
                                    <?php if ($u['is_active']): ?>
                                        <span class="status-badge status-active">فعال</span>
                                    <?php else: ?>
                                        <span class="status-badge status-inactive">غیرفعال</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Rooms -->
            <div class="section">
                <div class="section-header">
                    <h2>آخرین روم‌ها</h2>
                    <a href="admin-create-room.php" class="btn btn-primary">+ روم جدید</a>
                </div>
                <div class="table-card">
                    <table>
                        <thead>
                            <tr>
                                <th>نام روم</th>
                                <th>اعضا</th>
                                <th>پیام‌ها</th>
                                <th>وضعیت</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentRooms as $r): ?>
                            <tr>
                                <td><?php echo sanitize($r['name']); ?></td>
                                <td><?php echo (int)$r['member_count']; ?></td>
                                <td><?php echo number_format($r['message_count']); ?></td>
                                <td>
                                    <?php if ($r['is_active']): ?>
                                        <span class="status-badge status-active">فعال</span>
                                    <?php else: ?>
                                        <span class="status-badge status-inactive">غیرفعال</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
