<?php
/**
 * صفحه چت
 * چت روم پیشرفته - نسخه 2.0
 * سازگار با PHP 7.4+
 */

require_once __DIR__ . '/functions.php';
requireUserLogin();

$db = getDB();
$userId = $_SESSION['user_id'];
$roomId = $_SESSION['room_id'];

if (!$roomId) {
    redirect('login.php');
}

// دریافت اطلاعات روم
$stmt = $db->prepare("SELECT * FROM rooms WHERE id = :id AND is_active = 1");
$stmt->execute([':id' => $roomId]);
$room = $stmt->fetch();

if (!$room) {
    redirect('login.php');
}

// دریافت اطلاعات کاربر
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

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
$stmt = $db->prepare("UPDATE users SET status = 'online', last_seen = NOW() WHERE id = :id");
$stmt->execute([':id' => $userId]);

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
<body>
    <!-- Header -->
    <header class="chat-header">
        <button class="back-btn" onclick="location.href='logout.php'">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
            </svg>
        </button>
        <div class="room-avatar">
            <?php echo getInitials($room['name']); ?>
        </div>
        <div class="room-info">
            <div class="room-name"><?php echo sanitize($room['name']); ?></div>
            <div class="room-status" id="roomStatus"><?php echo $memberCount; ?> عضو</div>
        </div>
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
        <button class="reply-bar-close" onclick="cancelReply()">
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
        <button class="send-btn" onclick="sendMessage()">
            <svg viewBox="0 0 24 24">
                <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
            </svg>
        </button>
    </div>
    
    <!-- Context Menu -->
    <div class="context-menu" id="contextMenu">
        <div class="context-menu-item" onclick="replyToMessage()">
            <svg viewBox="0 0 24 24"><path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z"/></svg>
            پاسخ
        </div>
        <div class="context-menu-item" onclick="copyMessage()">
            <svg viewBox="0 0 24 24"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>
            کپی
        </div>
    </div>
    
    <!-- Scroll to bottom -->
    <button class="scroll-bottom-btn" id="scrollBottomBtn" onclick="scrollToBottom()">
        <svg viewBox="0 0 24 24"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
    </button>
    
    <!-- Toast -->
    <div class="toast" id="toast"></div>

    <script>
        // متغیرهای اصلی
        const userId = <?php echo $userId; ?>;
        const roomId = <?php echo $roomId; ?>;
        const csrfToken = '<?php echo $csrfToken; ?>';
        const csrfTokenName = '<?php echo CSRF_TOKEN_NAME; ?>';
        
        let lastMessageId = 0;
        let replyingTo = null;
        let selectedMessage = null;
        let isAtBottom = true;
        
        const messagesContainer = document.getElementById('messagesContainer');
        const messageInput = document.getElementById('messageInput');
        const contextMenu = document.getElementById('contextMenu');
        const replyBar = document.getElementById('replyBar');
        const scrollBottomBtn = document.getElementById('scrollBottomBtn');
        const loadingIndicator = document.getElementById('loadingIndicator');
        
        // بارگذاری اولیه پیام‌ها
        async function loadMessages() {
            try {
                const response = await fetch(`get_messages.php?${csrfTokenName}=${csrfToken}&last_id=0`);
                const data = await response.json();
                
                if (data.success) {
                    loadingIndicator.style.display = 'none';
                    renderMessages(data.data.messages, false);
                    scrollToBottom();
                    
                    // به‌روزرسانی تعداد آنلاین
                    document.getElementById('roomStatus').textContent = 
                        data.data.online_count + ' آنلاین از <?php echo $memberCount; ?> عضو';
                }
            } catch (error) {
                console.error('Error loading messages:', error);
                loadingIndicator.textContent = 'خطا در بارگذاری پیام‌ها';
            }
        }
        
        // دریافت پیام‌های جدید
        async function loadNewMessages() {
            try {
                const response = await fetch(`get_messages.php?${csrfTokenName}=${csrfToken}&last_id=${lastMessageId}`);
                const data = await response.json();
                
                if (data.success && data.data.messages.length > 0) {
                    const wasAtBottom = isScrolledToBottom();
                    renderMessages(data.data.messages, true);
                    
                    if (wasAtBottom) {
                        scrollToBottom();
                    }
                    
                    // به‌روزرسانی تعداد آنلاین
                    document.getElementById('roomStatus').textContent = 
                        data.data.online_count + ' آنلاین از <?php echo $memberCount; ?> عضو';
                }
            } catch (error) {
                console.error('Error loading new messages:', error);
            }
        }
        
        // رندر کردن پیام‌ها
        function renderMessages(messages, append = false) {
            messages.forEach(msg => {
                if (msg.id > lastMessageId) {
                    lastMessageId = msg.id;
                }
                
                const messageEl = createMessageElement(msg);
                messagesContainer.appendChild(messageEl);
            });
        }
        
        // ساخت المان پیام
        function createMessageElement(msg) {
            const div = document.createElement('div');
            div.className = `message ${msg.is_mine ? 'outgoing' : 'incoming'}`;
            div.dataset.id = msg.id;
            div.dataset.text = msg.message;
            div.dataset.sender = msg.sender_name;
            
            let replyHtml = '';
            if (msg.reply_to && msg.reply_message) {
                replyHtml = `
                    <div class="reply-preview" onclick="scrollToMessage(${msg.reply_to})">
                        <div class="reply-preview-name">${escapeHtml(msg.reply_sender_name)}</div>
                        <div class="reply-preview-text">${escapeHtml(msg.reply_message.substring(0, 50))}${msg.reply_message.length > 50 ? '...' : ''}</div>
                    </div>
                `;
            }
            
            let senderHtml = '';
            if (!msg.is_mine) {
                senderHtml = `<div class="message-sender" style="color: ${msg.avatar_color}">${escapeHtml(msg.sender_name)}</div>`;
            }
            
            div.innerHTML = `
                <div class="message-bubble" onclick="showContextMenu(event, this.parentElement)">
                    ${replyHtml}
                    ${senderHtml}
                    <div class="message-text">${escapeHtml(msg.message)}</div>
                    <div class="message-time">
                        ${msg.is_edited ? '<span style="font-size: 0.65rem">ویرایش شده</span>' : ''}
                        ${msg.time}
                    </div>
                </div>
            `;
            
            return div;
        }
        
        // ارسال پیام
        async function sendMessage() {
            const message = messageInput.value.trim();
            if (!message) return;
            
            const formData = new FormData();
            formData.append(csrfTokenName, csrfToken);
            formData.append('message', message);
            if (replyingTo) {
                formData.append('reply_to', replyingTo);
            }
            
            try {
                const response = await fetch('send_message.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    messageInput.value = '';
                    messageInput.style.height = 'auto';
                    cancelReply();
                    loadNewMessages();
                } else {
                    showToast(data.message);
                }
            } catch (error) {
                console.error('Error sending message:', error);
                showToast('خطا در ارسال پیام');
            }
        }
        
        // نمایش منوی راست کلیک
        function showContextMenu(event, messageEl) {
            event.preventDefault();
            event.stopPropagation();
            
            selectedMessage = messageEl;
            
            const rect = messageEl.getBoundingClientRect();
            let x = event.clientX || rect.left;
            let y = event.clientY || rect.top;
            
            // محدود کردن به صفحه
            const menuWidth = 150;
            const menuHeight = 100;
            
            if (x + menuWidth > window.innerWidth) {
                x = window.innerWidth - menuWidth - 10;
            }
            if (y + menuHeight > window.innerHeight) {
                y = window.innerHeight - menuHeight - 10;
            }
            
            contextMenu.style.left = x + 'px';
            contextMenu.style.top = y + 'px';
            contextMenu.classList.add('active');
        }
        
        // بستن منوی راست کلیک
        function hideContextMenu() {
            contextMenu.classList.remove('active');
        }
        
        // پاسخ به پیام
        function replyToMessage() {
            if (!selectedMessage) return;
            
            replyingTo = selectedMessage.dataset.id;
            document.getElementById('replyBarName').textContent = selectedMessage.dataset.sender;
            document.getElementById('replyBarText').textContent = selectedMessage.dataset.text.substring(0, 100);
            replyBar.classList.add('active');
            
            hideContextMenu();
            messageInput.focus();
        }
        
        // لغو پاسخ
        function cancelReply() {
            replyingTo = null;
            replyBar.classList.remove('active');
        }
        
        // کپی پیام
        function copyMessage() {
            if (!selectedMessage) return;
            
            const text = selectedMessage.dataset.text;
            navigator.clipboard.writeText(text).then(() => {
                showToast('کپی شد');
            });
            
            hideContextMenu();
        }
        
        // اسکرول به پیام
        function scrollToMessage(id) {
            const messageEl = document.querySelector(`.message[data-id="${id}"]`);
            if (messageEl) {
                messageEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                messageEl.style.animation = 'none';
                messageEl.offsetHeight;
                messageEl.style.animation = 'highlight 1s';
            }
        }
        
        // اسکرول به پایین
        function scrollToBottom() {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
        
        // بررسی آیا در پایین هستیم
        function isScrolledToBottom() {
            return messagesContainer.scrollHeight - messagesContainer.scrollTop - messagesContainer.clientHeight < 100;
        }
        
        // نمایش toast
        function showToast(message) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.classList.add('show');
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }
        
        // Escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // به‌روزرسانی وضعیت
        function updateStatus() {
            fetch('update_status.php?status=online');
        }
        
        // Event Listeners
        document.addEventListener('click', hideContextMenu);
        
        messagesContainer.addEventListener('scroll', function() {
            isAtBottom = isScrolledToBottom();
            scrollBottomBtn.classList.toggle('visible', !isAtBottom);
        });
        
        messageInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
        
        messageInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });
        
        // راه‌اندازی
        loadMessages();
        
        // Polling برای پیام‌های جدید
        setInterval(loadNewMessages, 3000);
        
        // به‌روزرسانی وضعیت
        setInterval(updateStatus, <?php echo HEARTBEAT_INTERVAL * 1000; ?>);
        
        // خروج
        window.addEventListener('beforeunload', function() {
            navigator.sendBeacon('update_status.php?status=offline');
        });
    </script>
</body>
</html>
