<?php
/**
 * صفحه چت
 * چت روم پیشرفته - نسخه 2.0
 * سازگار با PHP 7.4+
 */

require_once __DIR__ . '/functions.php';
requireUserLogin();

$db = getDB();
$userId = (int)$_SESSION['user_id'];
$roomId = (int)$_SESSION['room_id'];

// کاربر یا روم غیرفعال شده یا عضویت حذف شده است.
// session پاک می‌شود، وگرنه login.php و chat.php مدام به هم ریدایرکت می‌کنند.
if (!getCurrentUserMembership()) {
    clearUserSession();
    redirect('login.php');
}

// دریافت اطلاعات روم
$stmt = $db->prepare("SELECT * FROM rooms WHERE id = :id AND is_active = 1");
$stmt->execute([':id' => $roomId]);
$room = $stmt->fetch();

if (!$room) {
    clearUserSession();
    redirect('login.php');
}

// دریافت اعضای روم
$stmt = $db->prepare("
    SELECT u.id, u.name, u.status, u.last_seen
    FROM users u
    JOIN users_rooms ur ON u.id = ur.user_id
    WHERE ur.room_id = :room_id AND u.is_active = 1
    ORDER BY u.status DESC, u.name ASC
");
$stmt->execute([':room_id' => $roomId]);
$roomMembers = $stmt->fetchAll();

// به‌روزرسانی وضعیت کاربر
updateUserOnlineStatus($userId);

$csrfToken = generateCSRFToken();
$memberCount = count($roomMembers);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo sanitize($room['name']); ?> - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --primary: #6c5ce7;
            --primary-light: #a29bfe;
            --primary-dark: #5b4cdb;
            --accent: #00cec9;
            --bg-main: #0f0f23;
            --bg-chat: #1a1a2e;
            --bg-surface: #16213e;
            --message-out: linear-gradient(135deg, #6c5ce7 0%, #a29bfe 100%);
            --message-in: #252542;
            --text-primary: #ffffff;
            --text-secondary: #8b8b9e;
            --text-muted: #5a5a7a;
            --border-color: #2d2d4a;
            --reply-accent: #00cec9;
            --glow: 0 0 20px rgba(108, 92, 231, 0.3);
        }

        body {
            font-family: 'Vazirmatn', Tahoma, sans-serif;
            background: var(--bg-main);
            height: 100vh;
            overflow: hidden;
        }

        /* Scrollbar Modern */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--primary-light); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--primary); }

        /* Header */
        .chat-header {
            background: linear-gradient(135deg, var(--bg-surface) 0%, var(--bg-chat) 100%);
            color: var(--text-primary);
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 100;
            height: 68px;
            border-bottom: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
        }

        .back-btn {
            background: rgba(108, 92, 231, 0.15);
            border: none;
            color: var(--primary-light);
            cursor: pointer;
            padding: 10px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .back-btn:hover { 
            background: var(--primary);
            color: white;
            transform: translateX(-3px);
        }

        .room-avatar {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            color: white;
            box-shadow: var(--glow);
        }

        .room-info { flex: 1; }

        .room-name {
            font-weight: 600;
            font-size: 1.05rem;
            color: var(--text-primary);
        }

        .room-status {
            font-size: 0.8rem;
            color: var(--accent);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .room-status::before {
            content: '';
            width: 8px;
            height: 8px;
            background: var(--accent);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.8); }
        }

        /* Messages Container */
        .messages-container {
            position: fixed;
            top: 68px;
            bottom: 80px;
            left: 0;
            right: 0;
            overflow-y: auto;
            padding: 20px 24px;
            background: var(--bg-main);
            background-image: 
                radial-gradient(circle at 20% 80%, rgba(108, 92, 231, 0.05) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(0, 206, 201, 0.05) 0%, transparent 50%);
        }

        .message {
            display: flex;
            margin-bottom: 12px;
            animation: messageIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @keyframes messageIn {
            from { opacity: 0; transform: translateY(20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .message.outgoing { justify-content: flex-end; }
        .message.incoming { justify-content: flex-start; }

        .message-bubble {
            max-width: 70%;
            padding: 14px 18px;
            border-radius: 20px;
            position: relative;
            transition: transform 0.2s ease;
        }

        .message-bubble:hover { transform: scale(1.02); }

        .message.outgoing .message-bubble {
            background: var(--message-out);
            border-radius: 20px 20px 6px 20px;
            color: white;
            box-shadow: 0 4px 15px rgba(108, 92, 231, 0.3);
        }

        .message.incoming .message-bubble {
            background: var(--message-in);
            border-radius: 20px 20px 20px 6px;
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .message-sender {
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--accent);
        }

        .message.outgoing .message-sender { color: rgba(255,255,255,0.8); }

        .message-text {
            font-size: 0.95rem;
            line-height: 1.6;
            word-wrap: break-word;
            white-space: pre-wrap;
        }

        .message-time {
            font-size: 0.7rem;
            color: var(--text-muted);
            text-align: left;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 4px;
            justify-content: flex-end;
        }

        .message.outgoing .message-time { color: rgba(255,255,255,0.6); }

        /* Reply */
        .reply-preview {
            background: rgba(0, 206, 201, 0.1);
            border-right: 3px solid var(--reply-accent);
            padding: 8px 12px;
            border-radius: 8px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: background 0.2s;
        }

        .reply-preview:hover { background: rgba(0, 206, 201, 0.2); }

        .reply-preview-name {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--reply-accent);
        }

        .reply-preview-text {
            font-size: 0.8rem;
            color: var(--text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }

        /* Input Area */
        .input-area {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(180deg, transparent 0%, var(--bg-surface) 20%);
            padding: 16px 24px 20px;
            display: flex;
            align-items: flex-end;
            gap: 12px;
        }

        .reply-bar {
            display: none;
            position: fixed;
            bottom: 80px;
            left: 0;
            right: 0;
            background: var(--bg-surface);
            padding: 12px 24px;
            border-top: 1px solid var(--border-color);
            animation: slideUp 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .reply-bar.active { display: flex; align-items: center; gap: 12px; }

        @keyframes slideUp {
            from { transform: translateY(100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .reply-bar-content {
            flex: 1;
            border-right: 3px solid var(--reply-accent);
            padding-right: 12px;
        }

        .reply-bar-name {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--reply-accent);
        }

        .reply-bar-text {
            font-size: 0.85rem;
            color: var(--text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .reply-bar-close {
            background: rgba(255, 107, 107, 0.15);
            border: none;
            padding: 10px;
            border-radius: 10px;
            cursor: pointer;
            color: #ff6b6b;
            transition: all 0.2s;
        }

        .reply-bar-close:hover { background: #ff6b6b; color: white; }

        .message-input {
            flex: 1;
            background: var(--bg-chat);
            border: 2px solid var(--border-color);
            border-radius: 16px;
            padding: 14px 20px;
            font-family: inherit;
            font-size: 1rem;
            color: var(--text-primary);
            resize: none;
            max-height: 120px;
            outline: none;
            transition: all 0.3s ease;
        }

        .message-input::placeholder { color: var(--text-muted); }

        .message-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(108, 92, 231, 0.15);
        }

        .send-btn {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            border: none;
            border-radius: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(108, 92, 231, 0.4);
        }

        .send-btn:hover { 
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 8px 25px rgba(108, 92, 231, 0.5);
        }

        .send-btn:active { transform: scale(0.95); }
        .send-btn svg { fill: white; width: 24px; height: 24px; }

        /* Context Menu */
        .context-menu {
            display: none;
            position: fixed;
            background: var(--bg-surface);
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.4);
            border: 1px solid var(--border-color);
            z-index: 200;
            min-width: 180px;
            overflow: hidden;
            backdrop-filter: blur(10px);
        }

        .context-menu.active { display: block; animation: menuIn 0.2s ease; }

        @keyframes menuIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }

        .context-menu-item {
            padding: 14px 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.9rem;
            color: var(--text-primary);
            transition: all 0.2s;
        }

        .context-menu-item:hover { 
            background: rgba(108, 92, 231, 0.15);
            color: var(--primary-light);
        }

        .context-menu-item svg { width: 20px; height: 20px; fill: var(--text-secondary); }
        .context-menu-item:hover svg { fill: var(--primary-light); }

        /* Scroll to bottom */
        .scroll-bottom-btn {
            display: none;
            position: fixed;
            bottom: 100px;
            left: 24px;
            width: 48px;
            height: 48px;
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            cursor: pointer;
            align-items: center;
            justify-content: center;
            z-index: 50;
            transition: all 0.3s ease;
        }

        .scroll-bottom-btn:hover {
            background: var(--primary);
            border-color: var(--primary);
            transform: translateY(-3px);
        }

        .scroll-bottom-btn.visible { display: flex; }
        .scroll-bottom-btn svg { fill: var(--text-secondary); width: 24px; height: 24px; }
        .scroll-bottom-btn:hover svg { fill: white; }

        /* Toast */
        .toast {
            position: fixed;
            bottom: 120px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            padding: 14px 28px;
            border-radius: 14px;
            font-size: 0.9rem;
            font-weight: 500;
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            z-index: 300;
            box-shadow: 0 8px 30px rgba(108, 92, 231, 0.4);
        }

        .toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }

        /* Date divider */
        .date-divider {
            text-align: center;
            margin: 24px 0;
        }

        .date-divider span {
            background: var(--bg-surface);
            padding: 8px 18px;
            border-radius: 20px;
            font-size: 0.8rem;
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }

        @keyframes highlight {
            0%, 100% { background: transparent; }
            50% { background: rgba(0, 206, 201, 0.2); }
        }

        /* Loading */
        .loading {
            text-align: center;
            padding: 20px;
            color: var(--text-secondary);
        }

        @media (max-width: 480px) {
            .message-bubble { max-width: 85%; }
        }
    </style>
</head>
<body data-csrf-token="<?php echo sanitize($csrfToken); ?>" data-csrf-name="<?php echo sanitize(CSRF_TOKEN_NAME); ?>" data-member-count="<?php echo (int)$memberCount; ?>" data-heartbeat-ms="<?php echo HEARTBEAT_INTERVAL * 1000; ?>">
    <!-- Header -->
    <header class="chat-header">
        <form method="POST" action="logout.php" style="margin:0">
            <?php echo csrfField(); ?>
            <button type="submit" class="back-btn" title="خروج" aria-label="خروج">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
                </svg>
            </button>
        </form>
        <div class="room-avatar">
            <?php echo sanitize(getInitials($room['name'])); ?>
        </div>
        <div class="room-info">
            <div class="room-name"><?php echo sanitize($room['name']); ?></div>
            <div class="room-status" id="roomStatus"><?php echo (int)$memberCount; ?> عضو</div>
        </div>
        <a class="back-btn" href="change-password.php" title="تغییر رمز عبور" aria-label="تغییر رمز عبور">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
            </svg>
        </a>
    </header>

    <!-- Messages -->
    <div class="messages-container" id="messagesContainer">
        <div class="loading" id="loadingIndicator">در حال بارگذاری...</div>
    </div>

    <!-- Reply Bar -->
    <div class="reply-bar" id="replyBar">
        <div class="reply-bar-content">
            <div class="reply-bar-name" id="replyBarName"></div>
            <div class="reply-bar-text" id="replyBarText"></div>
        </div>
        <button class="reply-bar-close" id="replyBarClose" aria-label="لغو پاسخ">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
            </svg>
        </button>
    </div>

    <!-- Input Area -->
    <div class="input-area">
        <textarea 
            class="message-input" 
            id="messageInput" 
            placeholder="پیام خود را بنویسید..."
            rows="1"
        ></textarea>
        <button class="send-btn" id="sendBtn" aria-label="ارسال">
            <svg viewBox="0 0 24 24">
                <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
            </svg>
        </button>
    </div>

    <!-- Context Menu -->
    <div class="context-menu" id="contextMenu">
        <div class="context-menu-item" id="menuReply">
            <svg viewBox="0 0 24 24"><path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z"/></svg>
            پاسخ
        </div>
        <div class="context-menu-item" id="menuCopy">
            <svg viewBox="0 0 24 24"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>
            کپی
        </div>
    </div>

    <!-- Scroll to bottom -->
    <button class="scroll-bottom-btn" id="scrollBottomBtn" aria-label="رفتن به انتهای گفتگو">
        <svg viewBox="0 0 24 24"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
    </button>

    <!-- Toast -->
    <div class="toast" id="toast"></div>

    <script src="assets/js/chat-app.js?v=2.1" defer></script>
</body>
</html>
