<?php
/**
 * مشاهده و فیلتر لاگ امنیتی
 * چت روم پیشرفته - نسخه 2.2
 * سازگار با PHP 8.5
 */

require_once __DIR__ . '/functions.php';
requireAdminLogin();

const ADMIN_LOGS_PER_PAGE = 50;

/**
 * تاریخ معتبر با قالب Y-m-d یا رشته خالی
 * @param mixed $value
 * @return string
 */
function cleanDateFilter($value) {
    $value = cleanInput($value);
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $value : '';
}

$db = getDB();

$filters = [
    'action' => cleanInput($_GET['action'] ?? ''),
    'ip' => cleanInput($_GET['ip'] ?? ''),
    'username' => cleanInput($_GET['username'] ?? ''),
    'from' => cleanDateFilter($_GET['from'] ?? ''),
    'to' => cleanDateFilter($_GET['to'] ?? ''),
];
$page = validatePositiveInt($_GET['page'] ?? 1) ?: 1;

$conditions = [];
$params = [];
if ($filters['action'] !== '') {
    $conditions[] = 'l.action = :action';
    $params[':action'] = $filters['action'];
}
if ($filters['ip'] !== '') {
    $conditions[] = 'l.ip_address = :ip';
    $params[':ip'] = $filters['ip'];
}
if ($filters['username'] !== '') {
    $conditions[] = '(u.username = :username1 OR a.username = :username2)';
    $params[':username1'] = $filters['username'];
    $params[':username2'] = $filters['username'];
}
if ($filters['from'] !== '') {
    $conditions[] = 'l.created_at >= :from_date';
    $params[':from_date'] = $filters['from'] . ' 00:00:00';
}
if ($filters['to'] !== '') {
    $conditions[] = 'l.created_at <= :to_date';
    $params[':to_date'] = $filters['to'] . ' 23:59:59';
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$joins = 'LEFT JOIN users u ON u.id = l.user_id LEFT JOIN admins a ON a.id = l.admin_id';

$stmt = $db->prepare("SELECT COUNT(*) AS total FROM security_logs l $joins $where");
$stmt->execute($params);
$totalPages = max(1, (int)ceil((int)$stmt->fetch()['total'] / ADMIN_LOGS_PER_PAGE));
$page = min($page, $totalPages);

$stmt = $db->prepare("
    SELECT l.created_at, l.action, l.ip_address, l.details, u.username AS user_name, a.username AS admin_name
    FROM security_logs l
    $joins
    $where
    ORDER BY l.id DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', ADMIN_LOGS_PER_PAGE, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($page - 1) * ADMIN_LOGS_PER_PAGE, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

$actions = $db->query("SELECT DISTINCT action FROM security_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

adminPageStart('لاگ امنیتی', 'logs');
?>
<div class="card">
    <h2>لاگ امنیتی</h2>
    <form method="GET" class="filters">
        <div class="form-group">
            <label for="action">رویداد</label>
            <select id="action" name="action">
                <option value="">همه</option>
                <?php foreach ($actions as $action): ?>
                    <option value="<?php echo sanitize($action); ?>" <?php echo $filters['action'] === $action ? 'selected' : ''; ?>><?php echo sanitize($action); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="username">نام کاربری</label>
            <input type="text" id="username" name="username" dir="ltr" value="<?php echo sanitize($filters['username']); ?>">
        </div>
        <div class="form-group">
            <label for="ip">IP</label>
            <input type="text" id="ip" name="ip" dir="ltr" value="<?php echo sanitize($filters['ip']); ?>">
        </div>
        <div class="form-group">
            <label for="from">از تاریخ</label>
            <input type="date" id="from" name="from" value="<?php echo sanitize($filters['from']); ?>">
        </div>
        <div class="form-group">
            <label for="to">تا تاریخ</label>
            <input type="date" id="to" name="to" value="<?php echo sanitize($filters['to']); ?>">
        </div>
        <button type="submit" class="btn btn-secondary">فیلتر</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>زمان</th><th>رویداد</th><th>کاربر</th><th>ادمین</th><th>IP</th><th>جزئیات</th></tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="ltr"><?php echo sanitize($log['created_at']); ?></td>
                    <td class="ltr"><?php echo sanitize($log['action']); ?></td>
                    <td class="ltr"><?php echo sanitize($log['user_name'] ?? '-'); ?></td>
                    <td class="ltr"><?php echo sanitize($log['admin_name'] ?? '-'); ?></td>
                    <td class="ltr"><?php echo sanitize($log['ip_address']); ?></td>
                    <td class="details ltr"><?php echo sanitize($log['details'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$logs): ?>
                <tr><td colspan="6" class="muted-text">رویدادی یافت نشد.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo paginationLinks($page, $totalPages, array_filter($filters, 'strlen')); ?>
</div>
<?php
adminPageEnd();
