<?php
/**
 * پیوستن به روم با لینک دعوت
 * ثبت‌نام عمومی وجود ندارد؛ لینک فقط کاربران موجود را به روم اضافه می‌کند.
 * نام روم فقط پس از ورود نمایش داده می‌شود.
 * چت روم پیشرفته - نسخه 3.0
 * سازگار با PHP 8.5
 */

require_once __DIR__ . '/functions.php';

$token = $_POST['token'] ?? ($_GET['token'] ?? '');
$invite = findValidInvite($token);

if (!isUserLoggedIn()) {
    if ($invite) {
        // پس از ورود موفق، login.php دعوت را اعمال می‌کند
        $_SESSION['pending_invite'] = $token;
    }
    redirect($invite ? 'login.php?invite=1' : 'login.php');
}

if (!isCurrentAccountValid()) {
    clearUserSession();
    redirect('login.php');
}

$error = $invite ? '' : 'این لینک دعوت معتبر نیست یا منقضی شده است.';

if ($invite && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'توکن امنیتی نامعتبر است. لطفاً صفحه را رفرش کنید.';
    } elseif (redeemInvite((int)$_SESSION['user_id'], $invite)) {
        $_SESSION['room_id'] = (int)$invite['room_id'];
        redirect('chat.php?room=' . (int)$invite['room_id']);
    } else {
        $error = 'ظرفیت این لینک دعوت تمام شده است.';
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>دعوت به روم | <?php echo sanitize(SITE_NAME); ?></title>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
    <nav class="navbar">
        <h1>دعوت به روم</h1>
        <div class="navbar-links"><a href="chat.php">بازگشت به چت</a></div>
    </nav>
    <div class="container narrow">
        <div class="card">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo sanitize($error); ?></div>
            <?php endif; ?>
            <?php if ($invite): ?>
                <h2>پیوستن به روم <?php echo sanitize($invite['room_name']); ?></h2>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="token" value="<?php echo sanitize($token); ?>">
                    <button type="submit" class="btn btn-primary">عضو می‌شوم</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
