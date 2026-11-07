<?php
/**
 * صفحه چت
 * روم از آدرس صفحه می‌آید تا هر تب روم خودش را داشته باشد.
 * چت روم پیشرفته - نسخه 3.0
 * سازگار با PHP 8.5
 */

require_once __DIR__ . '/functions.php';
requireUserLogin();

$userId = (int)$_SESSION['user_id'];

// حساب غیرفعال شده یا رمز عوض شده است
if (!isCurrentAccountValid()) {
    clearUserSession();
    redirect('login.php');
}

$rooms = getRoomList($userId);

// عضو هیچ رومی نیست؛ session پاک می‌شود تا login.php و chat.php مدام به هم ریدایرکت نکنند
if (!$rooms) {
    clearUserSession();
    redirect('login.php');
}

$requestedRoom = validatePositiveInt($_GET['room'] ?? 0) ?: (int)$_SESSION['room_id'];
$roomIds = array_column($rooms, 'id');
$roomId = in_array($requestedRoom, $roomIds, true) ? $requestedRoom : $roomIds[0];
$_SESSION['room_id'] = $roomId;

updateUserOnlineStatus($userId);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#16213e">
    <title><?php echo sanitize(SITE_NAME); ?></title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/images/icon-192.png">
    <link rel="stylesheet" href="assets/css/fonts.css">
    <link rel="stylesheet" href="assets/css/chat.css?v=3">
</head>
<body
    data-csrf-token="<?php echo sanitize(generateCSRFToken()); ?>"
    data-csrf-name="<?php echo sanitize(CSRF_TOKEN_NAME); ?>"
    data-user-id="<?php echo $userId; ?>"
    data-room-id="<?php echo $roomId; ?>"
    data-site-name="<?php echo sanitize(SITE_NAME); ?>"
    data-max-file-size="<?php echo (int)MAX_FILE_SIZE; ?>"
    data-heartbeat-ms="<?php echo HEARTBEAT_INTERVAL * 1000; ?>">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-title"><?php echo sanitize($_SESSION['name'] ?? ''); ?></div>
            <button type="button" class="icon-btn" id="newChatBtn" title="گفتگوی خصوصی جدید" aria-label="گفتگوی خصوصی جدید">
                <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-3 9h-4v4h-2v-4H7V9h4V5h2v4h4v2z"/></svg>
            </button>
        </div>
        <nav class="room-list" id="roomList" aria-label="روم‌ها"></nav>
        <div class="sidebar-footer">
            <a class="icon-btn" href="change-password.php" title="تغییر رمز عبور" aria-label="تغییر رمز عبور">
                <svg viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
            </a>
            <form method="POST" action="logout.php" id="logoutForm">
                <?php echo csrfField(); ?>
                <button type="submit" class="icon-btn" title="خروج" aria-label="خروج">
                    <svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5-5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
                </button>
            </form>
        </div>
    </aside>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <main class="chat">
        <header class="chat-header">
            <button type="button" class="icon-btn menu-btn" id="menuBtn" aria-label="روم‌ها">
                <svg viewBox="0 0 24 24"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
            </button>
            <div class="room-avatar" id="roomAvatar"></div>
            <div class="room-info">
                <div class="room-name" id="roomName"></div>
                <div class="room-status" id="roomStatus"></div>
            </div>
            <button type="button" class="icon-btn" id="searchBtn" title="جست‌وجو" aria-label="جست‌وجو">
                <svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
            </button>
        </header>

        <div class="connection-bar" id="connectionBar" hidden></div>

        <button type="button" class="pinned-bar" id="pinnedBar" hidden>
            <span class="pinned-label">سنجاق‌شده</span>
            <span class="pinned-text" id="pinnedText"></span>
        </button>

        <div class="messages" id="messages" aria-live="polite">
            <div class="loading" id="loadingIndicator">در حال بارگذاری...</div>
        </div>

        <button type="button" class="scroll-bottom-btn" id="scrollBottomBtn" aria-label="رفتن به انتهای گفتگو">
            <svg viewBox="0 0 24 24"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
        </button>

        <div class="composer">
            <div class="composer-bar" id="composerBar" hidden>
                <div class="composer-bar-content">
                    <div class="composer-bar-name" id="composerBarName"></div>
                    <div class="composer-bar-text" id="composerBarText"></div>
                </div>
                <button type="button" class="icon-btn" id="composerBarClose" aria-label="لغو">
                    <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                </button>
            </div>
            <div class="upload-progress" id="uploadProgress" hidden><div class="upload-progress-bar" id="uploadProgressBar"></div></div>
            <div class="mention-box" id="mentionBox" hidden></div>
            <div class="composer-row">
                <button type="button" class="icon-btn" id="attachBtn" title="ارسال فایل" aria-label="ارسال فایل">
                    <svg viewBox="0 0 24 24"><path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5a2.5 2.5 0 0 1 5 0v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5a2.5 2.5 0 0 0 5 0V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z"/></svg>
                </button>
                <input type="file" id="fileInput" multiple hidden accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,.doc,.docx,audio/*">
                <textarea class="message-input" id="messageInput" placeholder="پیام خود را بنویسید..." rows="1" aria-label="متن پیام"></textarea>
                <button type="button" class="icon-btn" id="voiceBtn" title="ضبط پیام صوتی" aria-label="ضبط پیام صوتی">
                    <svg viewBox="0 0 24 24"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5-3c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/></svg>
                </button>
                <button type="button" class="send-btn" id="sendBtn" aria-label="ارسال">
                    <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                </button>
            </div>
        </div>

        <div class="drop-overlay" id="dropOverlay" hidden>فایل را اینجا رها کنید</div>
    </main>

    <aside class="search-panel" id="searchPanel" hidden>
        <div class="search-header">
            <input type="text" id="searchInput" placeholder="جست‌وجو در این روم..." aria-label="عبارت جست‌وجو">
            <button type="button" class="icon-btn" id="searchClose" aria-label="بستن">
                <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
            </button>
        </div>
        <div class="search-results" id="searchResults"></div>
    </aside>

    <div class="context-menu" id="contextMenu" role="menu">
        <div class="reaction-row" id="reactionRow"></div>
        <button type="button" class="context-menu-item" id="menuReply">پاسخ</button>
        <button type="button" class="context-menu-item" id="menuCopy">کپی</button>
        <button type="button" class="context-menu-item" id="menuEdit">ویرایش</button>
        <button type="button" class="context-menu-item" id="menuPin">سنجاق</button>
        <button type="button" class="context-menu-item danger" id="menuDelete">حذف</button>
    </div>

    <div class="modal" id="contactsModal" hidden>
        <div class="modal-card">
            <div class="modal-header">
                <h2>گفتگوی خصوصی جدید</h2>
                <button type="button" class="icon-btn" id="contactsClose" aria-label="بستن">
                    <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                </button>
            </div>
            <div class="contact-list" id="contactList"></div>
        </div>
    </div>

    <div class="toast" id="toast" role="status"></div>
    <script src="assets/js/chat-app.js?v=3" defer></script>
</body>
</html>
