<?php
/**
 * ویرایش کاربر: نام، فعال/غیرفعال و تعیین رمز جدید
 * چت روم پیشرفته - نسخه 2.2
 * سازگار با PHP 8.5
 */

require_once __DIR__ . '/functions.php';
requireAdminLogin();

$db = getDB();
$userId = validatePositiveInt($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT id, name, username, is_active FROM users WHERE id = :id");
$stmt->execute([':id' => $userId ?: 0]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('error', 'کاربر یافت نشد.');
    redirect('admin-users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminId = (int)$_SESSION['admin_id'];
    $action = postString('action');

    if (!validateCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'توکن امنیتی نامعتبر است.');
    } elseif ($action === 'save') {
        $name = cleanInput($_POST['name'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
            setFlash('error', 'نام باید بین 1 تا 100 کاراکتر باشد.');
        } else {
            // دسترسی کاربر غیرفعال از درخواست بعدی‌اش قطع می‌شود
            $status = $isActive ? '' : ", status = 'offline'";
            $stmt = $db->prepare("UPDATE users SET name = :name, is_active = :active $status WHERE id = :id");
            $stmt->execute([':name' => $name, ':active' => $isActive, ':id' => $user['id']]);
            logSecurityEvent('user_updated', (int)$user['id'], $adminId, ['name' => $name, 'is_active' => $isActive]);
            setFlash('success', 'اطلاعات کاربر ذخیره شد.');
        }
    } elseif ($action === 'password') {
        $password = postString('password');
        $errors = validatePassword($password);

        if ($errors) {
            setFlash('error', implode('، ', $errors));
        } else {
            setAccountPassword('user', (int)$user['id'], $password);
            logSecurityEvent('user_password_reset_by_admin', (int)$user['id'], $adminId);
            setFlash('success', 'رمز جدید ثبت شد و نشست‌های فعلی کاربر بسته شدند.');
        }
    }

    redirect('admin-user-edit.php?id=' . (int)$user['id']);
}

$stmt = $db->prepare("
    SELECT r.id, r.name, ur.role, ur.is_muted
    FROM users_rooms ur
    JOIN rooms r ON r.id = ur.room_id
    WHERE ur.user_id = :id
    ORDER BY r.name
");
$stmt->execute([':id' => $user['id']]);
$memberships = $stmt->fetchAll();

adminPageStart('ویرایش کاربر', 'users', true);
?>
<div class="card">
    <h2>ویرایش کاربر <span class="ltr"><?php echo sanitize($user['username']); ?></span></h2>
    <form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save">
        <div class="form-group">
            <label for="name">نام کامل</label>
            <input type="text" id="name" name="name" required maxlength="100" value="<?php echo sanitize($user['name']); ?>">
        </div>
        <div class="form-group checkbox-group">
            <input type="checkbox" id="is_active" name="is_active" <?php echo $user['is_active'] ? 'checked' : ''; ?>>
            <label for="is_active">حساب فعال باشد</label>
        </div>
        <button type="submit" class="btn btn-primary">ذخیره</button>
    </form>
</div>

<div class="card">
    <h2>تعیین رمز جدید</h2>
    <form method="POST" autocomplete="off">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="password">
        <div class="form-group">
            <label for="password">رمز عبور جدید</label>
            <input type="password" id="password" name="password" required placeholder="حداقل 8 کاراکتر با حرف بزرگ، کوچک و عدد">
        </div>
        <button type="submit" class="btn btn-danger">ثبت رمز و بستن نشست‌ها</button>
    </form>
</div>

<div class="card">
    <h2>عضویت‌ها</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>روم</th><th>نقش</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($memberships as $m): ?>
                <tr>
                    <td><?php echo sanitize($m['name']); ?></td>
                    <td><?php echo sanitize($m['role']); ?></td>
                    <td><a class="btn btn-secondary btn-small" href="admin-room-members.php?id=<?php echo (int)$m['id']; ?>">مدیریت اعضا</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$memberships): ?>
                <tr><td colspan="3" class="muted-text">عضو هیچ رومی نیست.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
adminPageEnd();
