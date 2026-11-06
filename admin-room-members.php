<?php
/**
 * مدیریت اعضای روم: نقش، سکوت و حذف عضویت
 * چت روم پیشرفته - نسخه 2.2
 * سازگار با PHP 7.4+
 */

require_once __DIR__ . '/functions.php';
requireAdminLogin();

$db = getDB();
$roomId = validatePositiveInt($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT id, name FROM rooms WHERE id = :id");
$stmt->execute([':id' => $roomId ?: 0]);
$room = $stmt->fetch();

if (!$room) {
    setFlash('error', 'روم یافت نشد.');
    redirect('admin-rooms.php');
}

$roles = ['member' => 'عضو', 'moderator' => 'مدیر', 'admin' => 'ادمین'];
// مدت سکوت بر حسب ثانیه؛ صفر یعنی تا رفع دستی
$muteDurations = [3600 => 'یک ساعت', 86400 => 'یک روز', 604800 => 'یک هفته', 0 => 'نامحدود'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminId = (int)$_SESSION['admin_id'];
    $action = postString('action');
    $memberId = validatePositiveInt($_POST['user_id'] ?? 0);
    $key = [':user_id' => $memberId ?: 0, ':room_id' => $room['id']];
    $logDetails = ['room_id' => (int)$room['id']];

    $stmt = $db->prepare("SELECT 1 FROM users_rooms WHERE user_id = :user_id AND room_id = :room_id");
    $stmt->execute($key);
    $isMember = (bool)$stmt->fetch();

    if (!validateCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'توکن امنیتی نامعتبر است.');
    } elseif (!$isMember) {
        setFlash('error', 'این کاربر عضو روم نیست.');
    } elseif ($action === 'role' && isset($roles[postString('role')])) {
        $role = postString('role');
        $stmt = $db->prepare("UPDATE users_rooms SET role = :role WHERE user_id = :user_id AND room_id = :room_id");
        $stmt->execute($key + [':role' => $role]);
        logSecurityEvent('user_room_role_changed', $memberId, $adminId, $logDetails + ['role' => $role]);
        setFlash('success', 'نقش عضو تغییر کرد.');
    } elseif ($action === 'mute' && array_key_exists((int)postString('duration'), $muteDurations)) {
        $duration = (int)postString('duration');
        if ($duration > 0) {
            $stmt = $db->prepare("
                UPDATE users_rooms SET is_muted = 1, muted_until = DATE_ADD(NOW(), INTERVAL :seconds SECOND)
                WHERE user_id = :user_id AND room_id = :room_id
            ");
            $stmt->execute($key + [':seconds' => $duration]);
        } else {
            $stmt = $db->prepare("UPDATE users_rooms SET is_muted = 1, muted_until = NULL WHERE user_id = :user_id AND room_id = :room_id");
            $stmt->execute($key);
        }
        logSecurityEvent('user_muted', $memberId, $adminId, $logDetails + ['duration' => $duration]);
        setFlash('success', 'عضو ساکت شد.');
    } elseif ($action === 'unmute') {
        $stmt = $db->prepare("UPDATE users_rooms SET is_muted = 0, muted_until = NULL WHERE user_id = :user_id AND room_id = :room_id");
        $stmt->execute($key);
        logSecurityEvent('user_unmuted', $memberId, $adminId, $logDetails);
        setFlash('success', 'سکوت عضو برداشته شد.');
    } elseif ($action === 'remove') {
        // پیام‌های قبلی عضو در روم باقی می‌مانند
        $stmt = $db->prepare("DELETE FROM users_rooms WHERE user_id = :user_id AND room_id = :room_id");
        $stmt->execute($key);
        logSecurityEvent('user_removed_from_room', $memberId, $adminId, $logDetails);
        setFlash('success', 'عضو از روم حذف شد.');
    } else {
        setFlash('error', 'درخواست نامعتبر است.');
    }

    redirect('admin-room-members.php?id=' . (int)$room['id']);
}

$stmt = $db->prepare("
    SELECT u.id, u.name, u.username, u.is_active, ur.role, ur.is_muted, ur.muted_until
    FROM users_rooms ur
    JOIN users u ON u.id = ur.user_id
    WHERE ur.room_id = :room_id
    ORDER BY u.name
");
$stmt->execute([':room_id' => $room['id']]);
$members = $stmt->fetchAll();

adminPageStart('اعضای روم', 'rooms');
?>
<div class="card">
    <div class="card-header">
        <h2>اعضای روم <?php echo sanitize($room['name']); ?></h2>
        <a class="btn btn-primary" href="admin-assign-user.php">+ افزودن عضو</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>نام</th><th>نقش</th><th>سکوت</th><th>حذف</th></tr>
            </thead>
            <tbody>
                <?php foreach ($members as $m): ?>
                <?php $muted = isMembershipMuted($m); ?>
                <tr>
                    <td>
                        <a href="admin-user-edit.php?id=<?php echo (int)$m['id']; ?>"><?php echo sanitize($m['name']); ?></a>
                        <div class="muted-text ltr"><?php echo sanitize($m['username']); ?></div>
                        <?php if (!$m['is_active']): ?>
                            <span class="badge badge-inactive">غیرفعال</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST" class="inline-form">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="role">
                            <input type="hidden" name="user_id" value="<?php echo (int)$m['id']; ?>">
                            <select name="role" aria-label="نقش">
                                <?php foreach ($roles as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo $m['role'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-secondary btn-small">ثبت</button>
                        </form>
                    </td>
                    <td>
                        <?php if ($muted): ?>
                            <span class="badge badge-muted">ساکت<?php echo $m['muted_until'] ? ' تا ' . sanitize($m['muted_until']) : ''; ?></span>
                            <form method="POST" class="inline-form">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="unmute">
                                <input type="hidden" name="user_id" value="<?php echo (int)$m['id']; ?>">
                                <button type="submit" class="btn btn-secondary btn-small">رفع سکوت</button>
                            </form>
                        <?php else: ?>
                            <form method="POST" class="inline-form">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="mute">
                                <input type="hidden" name="user_id" value="<?php echo (int)$m['id']; ?>">
                                <select name="duration" aria-label="مدت سکوت">
                                    <?php foreach ($muteDurations as $seconds => $label): ?>
                                        <option value="<?php echo (int)$seconds; ?>"><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-secondary btn-small">ساکت کن</button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST" class="inline-form">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="user_id" value="<?php echo (int)$m['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-small">حذف از روم</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$members): ?>
                <tr><td colspan="4" class="muted-text">این روم عضوی ندارد.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
adminPageEnd();
