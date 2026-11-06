<?php
/**
 * تغییر رمز عبور کاربر
 * چت روم پیشرفته - نسخه 2.2
 * سازگار با PHP 8.5
 */

require_once __DIR__ . '/functions.php';
requireUserLogin();

if (!getCurrentUserMembership()) {
    clearUserSession();
    redirect('login.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'توکن امنیتی نامعتبر است. لطفاً صفحه را رفرش کنید.';
    } else {
        $error = changeOwnPassword(
            'user',
            (int)$_SESSION['user_id'],
            postString('current_password'),
            postString('new_password'),
            postString('confirm_password')
        );
        if ($error === '') {
            $success = 'رمز عبور تغییر کرد. نشست شما در دستگاه‌های دیگر بسته شد.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تغییر رمز عبور | <?php echo sanitize(SITE_NAME); ?></title>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
    <nav class="navbar">
        <h1>تغییر رمز عبور</h1>
        <div class="navbar-links"><a href="chat.php">بازگشت به چت</a></div>
    </nav>
    <div class="container narrow">
        <div class="card">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo sanitize($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo sanitize($success); ?></div>
            <?php endif; ?>
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
    </div>
</body>
</html>
