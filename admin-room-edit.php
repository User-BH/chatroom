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

$stmt = $db->prepare("SELECT id, name, description, is_active, retention_days FROM rooms WHERE id = :id AND type = 'group'");
$stmt->execute([':id' => $roomId ?: 0]);
$room = $stmt->fetch();

if (!$room) {
    setFlash('error', 'روم یافت نشد.');
    redirect('admin-rooms.php');
}

$retentionOptions = ['' => 'پیش‌فرض سیستم', '7' => '7 روز', '30' => '30 روز', '90' => '90 روز', '365' => 'یک سال', '0' => 'بدون حذف خودکار'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(postString('action'), ['invite', 'revoke'], true)) {
    $adminId = (int)$_SESSION['admin_id'];

    if (!validateCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'توکن امنیتی نامعتبر است.');
    } elseif (postString('action') === 'revoke') {
        $stmt = $db->prepare("UPDATE room_invites SET revoked_at = NOW() WHERE id = :id AND room_id = :room AND revoked_at IS NULL");
        $stmt->execute([':id' => validatePositiveInt($_POST['invite_id'] ?? 0) ?: 0, ':room' => $room['id']]);
        logSecurityEvent('room_invite_revoked', null, $adminId, ['room_id' => (int)$room['id']]);
        setFlash('success', 'لینک دعوت باطل شد.');
    } else {
        $hours = validatePositiveInt($_POST['expires_hours'] ?? 0) ?: 0;
        $maxUses = validatePositiveInt($_POST['max_uses'] ?? 0) ?: null;
        // خود توکن فقط همین یک بار نمایش داده می‌شود و هش آن ذخیره می‌شود
        $token = bin2hex(random_bytes(32));
        $stmt = $db->prepare("
            INSERT INTO room_invites (room_id, token_hash, created_by, max_uses, expires_at)
            VALUES (:room, :hash, :admin, :max, IF(:hours1 > 0, DATE_ADD(NOW(), INTERVAL :hours2 HOUR), NULL))
        ");
        $stmt->execute([
            ':room' => $room['id'],
            ':hash' => hash('sha256', $token),
            ':admin' => $adminId,
            ':max' => $maxUses,
            ':hours1' => $hours,
            ':hours2' => $hours
        ]);
        logSecurityEvent('room_invite_created', null, $adminId, ['room_id' => (int)$room['id'], 'max_uses' => $maxUses, 'hours' => $hours]);
        $scheme = isHttps() ? 'https' : 'http';
        setFlash('success', 'لینک دعوت (فقط همین بار نمایش داده می‌شود): ' . $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . dirname($_SERVER['SCRIPT_NAME']) . '/join.php?token=' . $token);
    }

    redirect('admin-room-edit.php?id=' . (int)$room['id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = cleanInput($_POST['name'] ?? '');
    $retention = postString('retention_days');
    $retentionValue = array_key_exists($retention, $retentionOptions) && $retention !== '' ? (int)$retention : null;
    $description = cleanInput($_POST['description'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if (!validateCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'توکن امنیتی نامعتبر است.');
    } elseif (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 100) {
        setFlash('error', 'نام روم باید بین 2 تا 100 کاراکتر باشد.');
    } elseif (mb_strlen($description, 'UTF-8') > 2000) {
        setFlash('error', 'توضیحات نباید بیشتر از 2000 کاراکتر باشد.');
    } else {
        $stmt = $db->prepare("SELECT id FROM rooms WHERE name = :name AND id <> :id AND type = 'group'");
        $stmt->execute([':name' => $name, ':id' => $room['id']]);

        if ($stmt->fetch()) {
            setFlash('error', 'روم دیگری با این نام وجود دارد.');
        } else {
            $stmt = $db->prepare("UPDATE rooms SET name = :name, description = :description, is_active = :active, retention_days = :retention WHERE id = :id");
            $stmt->execute([
                ':name' => $name,
                ':description' => $description,
                ':active' => $isActive,
                ':retention' => $retentionValue,
                ':id' => $room['id']
            ]);
            logSecurityEvent('room_updated', null, (int)$_SESSION['admin_id'], [
                'room_id' => (int)$room['id'],
                'name' => $name,
                'is_active' => $isActive,
                'retention_days' => $retentionValue
            ]);
            setFlash('success', 'روم ذخیره شد.');
        }
    }

    redirect('admin-room-edit.php?id=' . (int)$room['id']);
}

$stmt = $db->prepare("
    SELECT id, max_uses, uses, expires_at, created_at
    FROM room_invites
    WHERE room_id = :room AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())
    ORDER BY id DESC
");
$stmt->execute([':room' => $room['id']]);
$invites = $stmt->fetchAll();
$currentRetention = $room['retention_days'] === null ? '' : (string)$room['retention_days'];

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
        <div class="form-group">
            <label for="retention_days">نگهداری پیام‌ها</label>
            <select id="retention_days" name="retention_days">
                <?php foreach ($retentionOptions as $value => $label): ?>
                    <option value="<?php echo $value; ?>" <?php echo $currentRetention === (string)$value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
            <div class="muted-text">پیام و فایل قدیمی‌تر از این مدت با scripts/cleanup.php برای همیشه حذف می‌شود.</div>
        </div>
        <button type="submit" class="btn btn-primary">ذخیره</button>
    </form>
</div>

<div class="card">
    <h2>لینک‌های دعوت</h2>
    <p class="muted-text">لینک فقط کاربران موجود را به این روم اضافه می‌کند و ثبت‌نام جدید نمی‌سازد.</p>
    <form method="POST" class="filters">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="invite">
        <div class="form-group">
            <label for="expires_hours">اعتبار</label>
            <select id="expires_hours" name="expires_hours">
                <option value="24">یک روز</option>
                <option value="168">یک هفته</option>
                <option value="720">یک ماه</option>
                <option value="0">بدون انقضا</option>
            </select>
        </div>
        <div class="form-group">
            <label for="max_uses">حداکثر استفاده</label>
            <select id="max_uses" name="max_uses">
                <option value="1">یک نفر</option>
                <option value="10">10 نفر</option>
                <option value="100">100 نفر</option>
                <option value="0">نامحدود</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">ساخت لینک</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>ساخته‌شده</th><th>انقضا</th><th>استفاده</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($invites as $invite): ?>
                <tr>
                    <td class="ltr"><?php echo sanitize($invite['created_at']); ?></td>
                    <td class="ltr"><?php echo $invite['expires_at'] ? sanitize($invite['expires_at']) : '-'; ?></td>
                    <td><?php echo (int)$invite['uses']; ?><?php echo $invite['max_uses'] ? ' از ' . (int)$invite['max_uses'] : ''; ?></td>
                    <td>
                        <form method="POST" class="inline-form">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="revoke">
                            <input type="hidden" name="invite_id" value="<?php echo (int)$invite['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-small">ابطال</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$invites): ?>
                <tr><td colspan="4" class="muted-text">لینک فعالی وجود ندارد.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
adminPageEnd();
