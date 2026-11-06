<?php
/**
 * فهرست و جست‌وجوی کاربران
 * چت روم پیشرفته - نسخه 2.2
 * سازگار با PHP 8.5
 */

require_once __DIR__ . '/functions.php';
requireAdminLogin();

const ADMIN_USERS_PER_PAGE = 25;

$db = getDB();
$search = cleanInput($_GET['q'] ?? '');
$page = validatePositiveInt($_GET['page'] ?? 1) ?: 1;

$where = '';
$params = [];
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $where = 'WHERE u.username LIKE :q1 OR u.name LIKE :q2';
    $params = [':q1' => $like, ':q2' => $like];
}

$stmt = $db->prepare("SELECT COUNT(*) AS total FROM users u $where");
$stmt->execute($params);
$totalPages = max(1, (int)ceil((int)$stmt->fetch()['total'] / ADMIN_USERS_PER_PAGE));
$page = min($page, $totalPages);

$stmt = $db->prepare("
    SELECT u.id, u.name, u.username, u.is_active, u.last_seen,
           (SELECT COUNT(*) FROM users_rooms WHERE user_id = u.id) AS room_count
    FROM users u
    $where
    ORDER BY u.created_at DESC, u.id DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', ADMIN_USERS_PER_PAGE, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($page - 1) * ADMIN_USERS_PER_PAGE, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

adminPageStart('کاربران', 'users');
?>
<div class="card">
    <div class="card-header">
        <h2>کاربران</h2>
        <a class="btn btn-primary" href="admin-create-user.php">+ کاربر جدید</a>
    </div>
    <form method="GET" class="filters">
        <div class="form-group">
            <label for="q">جست‌وجو در نام یا نام کاربری</label>
            <input type="text" id="q" name="q" value="<?php echo sanitize($search); ?>">
        </div>
        <button type="submit" class="btn btn-secondary">جست‌وجو</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>نام</th><th>نام کاربری</th><th>روم‌ها</th><th>آخرین فعالیت</th><th>وضعیت</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?php echo sanitize($u['name']); ?></td>
                    <td class="ltr"><?php echo sanitize($u['username']); ?></td>
                    <td><?php echo (int)$u['room_count']; ?></td>
                    <td class="ltr"><?php echo $u['last_seen'] ? sanitize($u['last_seen']) : '-'; ?></td>
                    <td>
                        <?php if ($u['is_active']): ?>
                            <span class="badge badge-active">فعال</span>
                        <?php else: ?>
                            <span class="badge badge-inactive">غیرفعال</span>
                        <?php endif; ?>
                    </td>
                    <td><a class="btn btn-secondary btn-small" href="admin-user-edit.php?id=<?php echo (int)$u['id']; ?>">ویرایش</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?>
                <tr><td colspan="6" class="muted-text">کاربری یافت نشد.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo paginationLinks($page, $totalPages, $search !== '' ? ['q' => $search] : []); ?>
</div>
<?php
adminPageEnd();
