<?php
/**
 * ویرایش روم: نام، توضیحات و فعال/غیرفعال
 * چت روم پیشرفته - نسخه 2.2
 * سازگار با PHP 8.5
 */

require_once __DIR__ . '/functions.php';
requireAdminLogin();

$db = getDB();
$roomId = validatePositiveInt($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT id, name, description, is_active FROM rooms WHERE id = :id");
$stmt->execute([':id' => $roomId ?: 0]);
$room = $stmt->fetch();

if (!$room) {
    setFlash('error', 'روم یافت نشد.');
    redirect('admin-rooms.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = cleanInput($_POST['name'] ?? '');
    $description = cleanInput($_POST['description'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if (!validateCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'توکن امنیتی نامعتبر است.');
    } elseif (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 100) {
        setFlash('error', 'نام روم باید بین 2 تا 100 کاراکتر باشد.');
    } elseif (mb_strlen($description, 'UTF-8') > 2000) {
        setFlash('error', 'توضیحات نباید بیشتر از 2000 کاراکتر باشد.');
    } else {
        $stmt = $db->prepare("SELECT id FROM rooms WHERE name = :name AND id <> :id");
        $stmt->execute([':name' => $name, ':id' => $room['id']]);

        if ($stmt->fetch()) {
            setFlash('error', 'روم دیگری با این نام وجود دارد.');
        } else {
            $stmt = $db->prepare("UPDATE rooms SET name = :name, description = :description, is_active = :active WHERE id = :id");
            $stmt->execute([
                ':name' => $name,
                ':description' => $description,
                ':active' => $isActive,
                ':id' => $room['id']
            ]);
            logSecurityEvent('room_updated', null, (int)$_SESSION['admin_id'], [
                'room_id' => (int)$room['id'],
                'name' => $name,
                'is_active' => $isActive
            ]);
            setFlash('success', 'روم ذخیره شد.');
        }
    }

    redirect('admin-room-edit.php?id=' . (int)$room['id']);
}

adminPageStart('ویرایش روم', 'rooms', true);
?>
<div class="card">
    <div class="card-header">
        <h2>ویرایش روم</h2>
        <a class="btn btn-secondary btn-small" href="admin-room-members.php?id=<?php echo (int)$room['id']; ?>">مدیریت اعضا</a>
    </div>
    <form method="POST">
        <?php echo csrfField(); ?>
        <div class="form-group">
            <label for="name">نام روم</label>
            <input type="text" id="name" name="name" required maxlength="100" value="<?php echo sanitize($room['name']); ?>">
        </div>
        <div class="form-group">
            <label for="description">توضیحات</label>
            <textarea id="description" name="description" maxlength="2000"><?php echo sanitize($room['description']); ?></textarea>
        </div>
        <div class="form-group checkbox-group">
            <input type="checkbox" id="is_active" name="is_active" <?php echo $room['is_active'] ? 'checked' : ''; ?>>
            <label for="is_active">روم فعال باشد</label>
        </div>
        <button type="submit" class="btn btn-primary">ذخیره</button>
    </form>
</div>
<?php
adminPageEnd();
