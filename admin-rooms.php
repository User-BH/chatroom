<?php
/**
 * فهرست روم‌ها
 * چت روم پیشرفته - نسخه 2.2
 * سازگار با PHP 8.5
 */

require_once __DIR__ . '/functions.php';
requireAdminLogin();

const ADMIN_ROOMS_PER_PAGE = 25;

$db = getDB();
$page = validatePositiveInt($_GET['page'] ?? 1) ?: 1;

$total = (int)$db->query("SELECT COUNT(*) AS total FROM rooms")->fetch()['total'];
$totalPages = max(1, (int)ceil($total / ADMIN_ROOMS_PER_PAGE));
$page = min($page, $totalPages);

$stmt = $db->prepare("
    SELECT r.id, r.name, r.is_active,
           (SELECT COUNT(*) FROM users_rooms WHERE room_id = r.id) AS member_count,
           (SELECT COUNT(*) FROM messages WHERE room_id = r.id AND is_deleted = 0) AS message_count
    FROM rooms r
    ORDER BY r.created_at DESC, r.id DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', ADMIN_ROOMS_PER_PAGE, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($page - 1) * ADMIN_ROOMS_PER_PAGE, PDO::PARAM_INT);
$stmt->execute();
$rooms = $stmt->fetchAll();

adminPageStart('روم‌ها', 'rooms');
?>
<div class="card">
    <div class="card-header">
        <h2>روم‌ها</h2>
        <a class="btn btn-primary" href="admin-create-room.php">+ روم جدید</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>نام</th><th>اعضا</th><th>پیام‌ها</th><th>وضعیت</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($rooms as $r): ?>
                <tr>
                    <td><?php echo sanitize($r['name']); ?></td>
                    <td><?php echo (int)$r['member_count']; ?></td>
                    <td><?php echo number_format((int)$r['message_count']); ?></td>
                    <td>
                        <?php if ($r['is_active']): ?>
                            <span class="badge badge-active">فعال</span>
                        <?php else: ?>
                            <span class="badge badge-inactive">غیرفعال</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="btn btn-secondary btn-small" href="admin-room-members.php?id=<?php echo (int)$r['id']; ?>">اعضا</a>
                        <a class="btn btn-secondary btn-small" href="admin-room-edit.php?id=<?php echo (int)$r['id']; ?>">ویرایش</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rooms): ?>
                <tr><td colspan="5" class="muted-text">رومی وجود ندارد.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo paginationLinks($page, $totalPages); ?>
</div>
<?php
adminPageEnd();
