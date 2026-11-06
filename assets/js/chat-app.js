/**
 * منطق صفحه چت (chat.php)
 * این فایل جدا از صفحه است تا CSP بتواند هر اسکریپت داخل صفحه را مسدود کند.
 * چت روم پیشرفته - نسخه 2.1
 */
(function () {
    'use strict';

    const config = document.body.dataset;
    const csrfToken = config.csrfToken;
    const csrfName = config.csrfName;
    const memberCount = Number(config.memberCount);
    const POLL_INTERVAL_MS = 3000;

    let lastMessageId = 0;
    let replyingTo = null;
    let selectedMessage = null;
    let pollInFlight = false;
    let sendInFlight = false;
    let leaving = false;

    const messagesContainer = document.getElementById('messagesContainer');
    const messageInput = document.getElementById('messageInput');
    const contextMenu = document.getElementById('contextMenu');
    const replyBar = document.getElementById('replyBar');
    const scrollBottomBtn = document.getElementById('scrollBottomBtn');
    const loadingIndicator = document.getElementById('loadingIndicator');
    const roomStatus = document.getElementById('roomStatus');
    const toast = document.getElementById('toast');

    /**
     * درخواست به API با توکن CSRF.
     * اگر نشست تمام شده باشد کاربر به صفحه ورود می‌رود.
     */
    async function api(url, options) {
        const response = await fetch(url, Object.assign({
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrfToken }
        }, options));

        if (response.status === 401) {
            leaving = true;
            location.href = 'login.php?error=session';
            throw new Error('session expired');
        }
        return response.json();
    }

    function updateOnlineCount(count) {
        roomStatus.textContent = count + ' آنلاین از ' + memberCount + ' عضو';
    }

    async function fetchMessages(isInitial) {
        if (pollInFlight) return;
        pollInFlight = true;
        try {
            const data = await api('get_messages.php?last_id=' + lastMessageId);
            if (!data.success) {
                if (isInitial) loadingIndicator.textContent = data.message;
                return;
            }
            loadingIndicator.style.display = 'none';

            const wasAtBottom = isScrolledToBottom();
            renderMessages(data.data.messages);
            if (isInitial || (wasAtBottom && data.data.messages.length > 0)) {
                scrollToBottom();
            }
            updateOnlineCount(data.data.online_count);
        } catch (error) {
            console.error('Error loading messages:', error);
            if (isInitial) loadingIndicator.textContent = 'خطا در بارگذاری پیام‌ها';
        } finally {
            pollInFlight = false;
        }
    }

    function renderMessages(messages) {
        messages.forEach(function (msg) {
            // یک پیام ممکن است در دو پاسخ همزمان برگردد؛ نباید دوبار نمایش داده شود
            if (msg.id <= lastMessageId) return;
            lastMessageId = msg.id;
            messagesContainer.appendChild(createMessageElement(msg));
        });
    }

    /** ساخت عنصر با textContent؛ هیچ متن کاربری به صورت HTML تفسیر نمی‌شود */
    function el(className, text) {
        const node = document.createElement('div');
        node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function createMessageElement(msg) {
        const row = el('message ' + (msg.is_mine ? 'outgoing' : 'incoming'));
        row.dataset.id = msg.id;
        row.dataset.text = msg.message;
        row.dataset.sender = msg.sender_name;

        const bubble = el('message-bubble');
        bubble.addEventListener('click', function (event) {
            showContextMenu(event, row);
        });

        if (msg.reply_to && msg.reply_message !== null) {
            const preview = msg.reply_message.length > 50
                ? msg.reply_message.substring(0, 50) + '...'
                : msg.reply_message;
            const reply = el('reply-preview');
            reply.appendChild(el('reply-preview-name', msg.reply_sender_name));
            reply.appendChild(el('reply-preview-text', preview));
            reply.addEventListener('click', function (event) {
                event.stopPropagation();
                scrollToMessage(msg.reply_to);
            });
            bubble.appendChild(reply);
        }

        if (!msg.is_mine) {
            const sender = el('message-sender', msg.sender_name);
            sender.style.color = msg.avatar_color;
            bubble.appendChild(sender);
        }

        bubble.appendChild(el('message-text', msg.message));

        const time = el('message-time');
        if (msg.is_edited) {
            const edited = document.createElement('span');
            edited.style.fontSize = '0.65rem';
            edited.textContent = 'ویرایش شده';
            time.appendChild(edited);
        }
        time.appendChild(document.createTextNode(msg.time));
        bubble.appendChild(time);

        row.appendChild(bubble);
        return row;
    }

    async function sendMessage() {
        const message = messageInput.value.trim();
        if (!message || sendInFlight) return;
        sendInFlight = true;

        const formData = new FormData();
        formData.append('message', message);
        if (replyingTo) formData.append('reply_to', replyingTo);

        try {
            const data = await api('send_message.php', { method: 'POST', body: formData });
            if (data.success) {
                messageInput.value = '';
                messageInput.style.height = 'auto';
                cancelReply();
                fetchMessages(false);
            } else {
                showToast(data.message);
            }
        } catch (error) {
            console.error('Error sending message:', error);
            if (!leaving) showToast('خطا در ارسال پیام');
        } finally {
            sendInFlight = false;
        }
    }

    function showContextMenu(event, messageEl) {
        event.preventDefault();
        event.stopPropagation();

        selectedMessage = messageEl;

        const rect = messageEl.getBoundingClientRect();
        let x = event.clientX || rect.left;
        let y = event.clientY || rect.top;

        // محدود کردن به صفحه
        const menuWidth = 180;
        const menuHeight = 110;
        if (x + menuWidth > window.innerWidth) x = window.innerWidth - menuWidth - 10;
        if (y + menuHeight > window.innerHeight) y = window.innerHeight - menuHeight - 10;

        contextMenu.style.left = x + 'px';
        contextMenu.style.top = y + 'px';
        contextMenu.classList.add('active');
    }

    function hideContextMenu() {
        contextMenu.classList.remove('active');
    }

    function replyToMessage() {
        if (!selectedMessage) return;
        replyingTo = selectedMessage.dataset.id;
        document.getElementById('replyBarName').textContent = selectedMessage.dataset.sender;
        document.getElementById('replyBarText').textContent = selectedMessage.dataset.text.substring(0, 100);
        replyBar.classList.add('active');
        hideContextMenu();
        messageInput.focus();
    }

    function cancelReply() {
        replyingTo = null;
        replyBar.classList.remove('active');
    }

    /**
     * navigator.clipboard فقط روی HTTPS یا localhost وجود دارد؛
     * روی شبکه داخلی با HTTP از روش قدیمی استفاده می‌شود
     */
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            const area = document.createElement('textarea');
            area.value = text;
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            const copied = document.execCommand('copy');
            document.body.removeChild(area);
            if (copied) {
                resolve();
            } else {
                reject(new Error('copy failed'));
            }
        });
    }

    function copyMessage() {
        if (!selectedMessage) return;
        copyText(selectedMessage.dataset.text).then(function () {
            showToast('کپی شد');
        }).catch(function () {
            showToast('کپی انجام نشد');
        });
        hideContextMenu();
    }

    function scrollToMessage(id) {
        const messageEl = messagesContainer.querySelector('.message[data-id="' + Number(id) + '"]');
        if (messageEl) {
            messageEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            messageEl.style.animation = 'none';
            void messageEl.offsetHeight;
            messageEl.style.animation = 'highlight 1s';
        }
    }

    function scrollToBottom() {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function isScrolledToBottom() {
        return messagesContainer.scrollHeight - messagesContainer.scrollTop - messagesContainer.clientHeight < 100;
    }

    function showToast(message) {
        toast.textContent = message;
        toast.classList.add('show');
        setTimeout(function () {
            toast.classList.remove('show');
        }, 3000);
    }

    function updateStatus() {
        const formData = new FormData();
        formData.append('status', 'online');
        api('update_status.php', { method: 'POST', body: formData }).catch(function (error) {
            console.error('Error updating status:', error);
        });
    }

    // Event Listeners
    document.addEventListener('click', hideContextMenu);
    document.getElementById('sendBtn').addEventListener('click', sendMessage);
    document.getElementById('replyBarClose').addEventListener('click', cancelReply);
    document.getElementById('menuReply').addEventListener('click', replyToMessage);
    document.getElementById('menuCopy').addEventListener('click', copyMessage);
    scrollBottomBtn.addEventListener('click', scrollToBottom);

    messagesContainer.addEventListener('scroll', function () {
        scrollBottomBtn.classList.toggle('visible', !isScrolledToBottom());
    });

    messageInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            sendMessage();
        }
    });

    messageInput.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });

    // هنگام بستن صفحه؛ sendBeacon هدر سفارشی ندارد پس توکن در فرم می‌رود
    window.addEventListener('beforeunload', function () {
        if (leaving) return;
        const formData = new FormData();
        formData.append(csrfName, csrfToken);
        formData.append('status', 'offline');
        navigator.sendBeacon('update_status.php', formData);
    });

    // راه‌اندازی
    fetchMessages(true);
    setInterval(function () { fetchMessages(false); }, POLL_INTERVAL_MS);
    setInterval(updateStatus, Number(config.heartbeatMs));
})();
