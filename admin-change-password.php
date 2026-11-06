<?php
/**
 * تغییر رمز عبور ادمین
 * چت روم پیشرفته - نسخه 2.2
 * سازگار با PHP 8.5
 */

require_once __DIR__ . '/functions.php';
requireAdminLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'توکن امنیتی نامعتبر است.');
    } else {
        $error = changeOwnPassword(
            'admin',
            (int)$_SESSION['admin_id'],
            postString('current_password'),
            postString('new_password'),
            postString('confirm_password')
        );
        if ($error === '') {
            setFlash('success', 'رمز عبور تغییر کرد. نشست‌های دیگر این حساب بسته شد.');
        } else {
            setFlash('error', $error);
        }
    }
    redirect('admin-change-password.php');
}

adminPageStart('تغییر رمز عبور', 'password', true);
?>
<div class="card">
    <h2>تغییر رمز عبور ادمین</h2>
    <form method="POST" autocomplete="off">
        <?php echo csrfField(); ?>
        <div class="form-group">
            <label for="current_password">رمز عبور فعلی</label>
            <input type="password" id="current_password" name="current_password" required>
        </div>
        <div class="form-group">
            <label for="new_password">رمز عبور جدید</label>
            <input type="password" id="new_password" name="new_password" required placeholder="حداقل 8 کاراکتر با حرف بزرگ، کوچک و عدد">
        </div>
        <div class="form-group">
            <label for="confirm_password">تکرار رمز عبور جدید</label>
            <input type="password" id="confirm_password" name="confirm_password" required>
        </div>
        <button type="submit" class="btn btn-primary">تغییر رمز</button>
    </form>
</div>
<?php
adminPageEnd();
