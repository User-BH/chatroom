/**
 * چت روم - جاوااسکریپت اصلی
 * شامل مدیریت پیام‌ها، ریپلای، آپلود و ...
 */

class ChatApp {
    constructor(config) {
        this.config = {
            roomId: config.roomId,
            userId: config.userId,
            userName: config.userName,
            csrfToken: config.csrfToken,
            pollInterval: config.pollInterval || 3000,
            apiBase: config.apiBase || '/api'
        };
        
        this.state = {
            lastMessageId: 0,
            isTyping: false,
            typingTimeout: null,
            replyTo: null,
            selectedMessages: [],
            isScrolledToBottom: true
        };
        
        this.elements = {};
        this.init();
    }
    
    init() {
        this.cacheElements();
        this.bindEvents();
        this.loadMessages();
        this.startPolling();
        this.scrollToBottom();
    }
    
    cacheElements() {
        this.elements = {
            messagesContainer: document.getElementById('messages-container'),
            messageInput: document.getElementById('message-input'),
            sendBtn: document.getElementById('send-btn'),
            replyPanel: document.getElementById('reply-panel'),
            replyPanelSender: document.getElementById('reply-panel-sender'),
            replyPanelText: document.getElementById('reply-panel-text'),
            replyCloseBtn: document.getElementById('reply-close-btn'),
            typingIndicator: document.getElementById('typing-indicator'),
            attachBtn: document.getElementById('attach-btn'),
            fileInput: document.getElementById('file-input'),
            emojiBtn: document.getElementById('emoji-btn'),
            contextMenu: document.getElementById('context-menu')
        };
    }
    
    bindEvents() {
        // ارسال پیام
        this.elements.sendBtn?.addEventListener('click', () => this.sendMessage());
        
        // Enter برای ارسال، Shift+Enter برای خط جدید
        this.elements.messageInput?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.sendMessage();
            }
        });
        
        // تایپ کردن
        this.elements.messageInput?.addEventListener('input', () => {
            this.handleTyping();
            this.adjustTextareaHeight();
        });
        
        // بستن ریپلای
        this.elements.replyCloseBtn?.addEventListener('click', () => this.cancelReply());
        
        // آپلود فایل
        this.elements.attachBtn?.addEventListener('click', () => {
            this.elements.fileInput?.click();
        });
        
        this.elements.fileInput?.addEventListener('change', (e) => {
            this.handleFileUpload(e.target.files);
        });
        
        // اسکرول
        this.elements.messagesContainer?.addEventListener('scroll', () => {
            this.checkScrollPosition();
        });
        
        // راست کلیک روی پیام
        document.addEventListener('contextmenu', (e) => {
            const messageEl = e.target.closest('.message');
            if (messageEl) {
                e.preventDefault();
                this.showContextMenu(e, messageEl);
            }
        });
        
        // بستن منوی راست کلیک
        document.addEventListener('click', () => {
            this.hideContextMenu();
        });
        
        // Drag & Drop برای فایل
        this.elements.messagesContainer?.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.stopPropagation();
        });
        
        this.elements.messagesContainer?.addEventListener('drop', (e) => {
            e.preventDefault();
            e.stopPropagation();
            this.handleFileUpload(e.dataTransfer.files);
        });
    }
    
    async loadMessages() {
        try {
            const response = await this.apiRequest('get_messages.php', {
                room_id: this.config.roomId
            });
            
            if (response.success && response.messages) {
                this.renderMessages(response.messages);
                if (response.messages.length > 0) {
                    this.state.lastMessageId = response.messages[response.messages.length - 1].id;
                }
                this.scrollToBottom();
            }
        } catch (error) {
            console.error('خطا در بارگذاری پیام‌ها:', error);
        }
    }
    
    async fetchNewMessages() {
        try {
            const response = await this.apiRequest('get_messages.php', {
                room_id: this.config.roomId,
                last_id: this.state.lastMessageId
            });
            
            if (response.success && response.messages && response.messages.length > 0) {
                this.appendMessages(response.messages);
                this.state.lastMessageId = response.messages[response.messages.length - 1].id;
                
                if (this.state.isScrolledToBottom) {
                    this.scrollToBottom();
                }
            }
            
            // بروزرسانی وضعیت تایپ
            if (response.typing_users) {
                this.updateTypingIndicator(response.typing_users);
            }
        } catch (error) {
            console.error('خطا در دریافت پیام‌های جدید:', error);
        }
    }
    
    renderMessages(messages) {
        if (!this.elements.messagesContainer) return;
        
        this.elements.messagesContainer.innerHTML = '';
        let currentDate = '';
        
        messages.forEach(message => {
            const messageDate = this.formatDate(message.created_at);
            
            // اضافه کردن جداکننده تاریخ
            if (messageDate !== currentDate) {
                currentDate = messageDate;
                this.elements.messagesContainer.appendChild(
                    this.createDateDivider(messageDate)
                );
            }
            
            this.elements.messagesContainer.appendChild(
                this.createMessageElement(message)
            );
        });
    }
    
    appendMessages(messages) {
        messages.forEach(message => {
            // جلوگیری از نمایش پیام‌های تکراری
            if (document.querySelector(`[data-message-id="${message.id}"]`)) {
                return;
            }
            
            this.elements.messagesContainer?.appendChild(
                this.createMessageElement(message)
            );
        });
    }
    
    createMessageElement(message) {
        const isOutgoing = parseInt(message.user_id) === parseInt(this.config.userId);
        const div = document.createElement('div');
        div.className = `message ${isOutgoing ? 'outgoing' : 'incoming'}`;
        if (message.type === 'system') div.className = 'message system';
        div.dataset.messageId = message.id;
        div.dataset.userId = message.user_id;
        
        let replyHtml = '';
        if (message.reply_to_id && message.reply_content) {
            replyHtml = `
                <div class="message-reply" data-reply-id="${message.reply_to_id}">
                    <div class="message-reply-sender">${this.escapeHtml(message.reply_sender_name)}</div>
                    <div class="message-reply-text">${this.escapeHtml(this.truncateText(message.reply_content, 50))}</div>
                </div>
            `;
        }
        
        let attachmentHtml = '';
        if (message.attachment) {
            attachmentHtml = this.createAttachmentHtml(message);
        }
        
        const senderHtml = !isOutgoing && message.type !== 'system' 
            ? `<div class="message-sender">${this.escapeHtml(message.sender_name)}</div>` 
            : '';
        
        const editedHtml = message.is_edited 
            ? '<span class="message-edited">ویرایش شده</span>' 
            : '';
        
        div.innerHTML = `
            <div class="message-bubble">
                ${senderHtml}
                ${replyHtml}
                ${attachmentHtml}
                <div class="message-text">${this.formatMessageText(message.content)}</div>
                <div class="message-meta">
                    ${editedHtml}
                    <span class="message-time">${this.formatTime(message.created_at)}</span>
                    ${isOutgoing ? '<span class="message-status">✓✓</span>' : ''}
                </div>
            </div>
        `;
        
        // کلیک روی ریپلای
        const replyEl = div.querySelector('.message-reply');
        if (replyEl) {
            replyEl.addEventListener('click', () => {
                this.scrollToMessage(message.reply_to_id);
            });
        }
        
        return div;
    }
    
    createAttachmentHtml(message) {
        switch (message.type) {
            case 'image':
                return `
                    <div class="message-attachment">
                        <img src="${this.escapeHtml(message.attachment)}" 
                             alt="${this.escapeHtml(message.attachment_name)}" 
                             class="message-image"
                             onclick="ChatApp.openImage('${this.escapeHtml(message.attachment)}')">
                    </div>
                `;
            
            case 'voice':
                return `
                    <div class="message-attachment message-voice">
                        <button class="voice-play-btn" onclick="ChatApp.playVoice(this, '${this.escapeHtml(message.attachment)}')">
                            ▶
                        </button>
                        <div class="voice-waveform"></div>
                        <span class="voice-duration">${message.attachment_size || '0:00'}</span>
                    </div>
                `;
            
            case 'file':
                return `
                    <div class="message-attachment message-file">
                        <div class="message-file-icon">📄</div>
                        <div class="message-file-info">
                            <div class="message-file-name">${this.escapeHtml(message.attachment_name)}</div>
                            <div class="message-file-size">${this.formatFileSize(message.attachment_size)}</div>
                        </div>
                        <a href="${this.escapeHtml(message.attachment)}" download class="btn btn-secondary">
                            دانلود
                        </a>
                    </div>
                `;
            
            default:
                return '';
        }
    }
    
    createDateDivider(date) {
        const div = document.createElement('div');
        div.className = 'message-date-divider';
        div.innerHTML = `<span>${date}</span>`;
        return div;
    }
    
    async sendMessage() {
        const content = this.elements.messageInput?.value.trim();
        if (!content) return;
        
        // غیرفعال کردن دکمه ارسال
        if (this.elements.sendBtn) {
            this.elements.sendBtn.disabled = true;
        }
        
        try {
            const data = {
                room_id: this.config.roomId,
                content: content,
                reply_to_id: this.state.replyTo?.id || null
            };
            
            const response = await this.apiRequest('send_message.php', data, 'POST');
            
            if (response.success) {
                // پاک کردن ورودی
                this.elements.messageInput.value = '';
                this.adjustTextareaHeight();
                
                // بستن پنل ریپلای
                this.cancelReply();
                
                // دریافت پیام‌های جدید
                await this.fetchNewMessages();
                this.scrollToBottom();
            } else {
                this.showError(response.message || 'خطا در ارسال پیام');
            }
        } catch (error) {
            console.error('خطا در ارسال پیام:', error);
            this.showError('خطا در ارتباط با سرور');
        } finally {
            if (this.elements.sendBtn) {
                this.elements.sendBtn.disabled = false;
            }
        }
    }
    
    setReply(messageId, senderName, content) {
        this.state.replyTo = { id: messageId, sender: senderName, content: content };
        
        if (this.elements.replyPanel) {
            this.elements.replyPanel.style.display = 'flex';
        }
        if (this.elements.replyPanelSender) {
            this.elements.replyPanelSender.textContent = senderName;
        }
        if (this.elements.replyPanelText) {
            this.elements.replyPanelText.textContent = this.truncateText(content, 50);
        }
        
        this.elements.messageInput?.focus();
    }
    
    cancelReply() {
        this.state.replyTo = null;
        if (this.elements.replyPanel) {
            this.elements.replyPanel.style.display = 'none';
        }
    }
    
    showContextMenu(event, messageEl) {
        const messageId = messageEl.dataset.messageId;
        const userId = messageEl.dataset.userId;
        const isOwn = parseInt(userId) === parseInt(this.config.userId);
        const messageText = messageEl.querySelector('.message-text')?.textContent || '';
        const senderName = messageEl.querySelector('.message-sender')?.textContent || this.config.userName;
        
        if (!this.elements.contextMenu) return;
        
        // نمایش/مخفی کردن آیتم‌های مناسب
        const editItem = this.elements.contextMenu.querySelector('[data-action="edit"]');
        const deleteItem = this.elements.contextMenu.querySelector('[data-action="delete"]');
        
        if (editItem) editItem.style.display = isOwn ? 'flex' : 'none';
        if (deleteItem) deleteItem.style.display = isOwn ? 'flex' : 'none';
        
        // تنظیم موقعیت
        this.elements.contextMenu.style.display = 'block';
        this.elements.contextMenu.style.left = `${event.clientX}px`;
        this.elements.contextMenu.style.top = `${event.clientY}px`;
        
        // ذخیره اطلاعات پیام
        this.elements.contextMenu.dataset.messageId = messageId;
        this.elements.contextMenu.dataset.messageText = messageText;
        this.elements.contextMenu.dataset.senderName = senderName;
    }
    
    hideContextMenu() {
        if (this.elements.contextMenu) {
            this.elements.contextMenu.style.display = 'none';
        }
    }
    
    handleContextMenuAction(action) {
        const messageId = this.elements.contextMenu?.dataset.messageId;
        const messageText = this.elements.contextMenu?.dataset.messageText;
        const senderName = this.elements.contextMenu?.dataset.senderName;
        
        switch (action) {
            case 'reply':
                this.setReply(messageId, senderName, messageText);
                break;
            case 'copy':
                navigator.clipboard.writeText(messageText);
                break;
            case 'edit':
                this.editMessage(messageId, messageText);
                break;
            case 'delete':
                this.deleteMessage(messageId);
                break;
            case 'forward':
                // TODO: پیاده‌سازی فوروارد
                break;
        }
        
        this.hideContextMenu();
    }
    
    async editMessage(messageId, currentText) {
        const newText = prompt('متن جدید پیام:', currentText);
        if (newText === null || newText.trim() === currentText) return;
        
        try {
            const response = await this.apiRequest('edit_message.php', {
                message_id: messageId,
                content: newText.trim()
            }, 'POST');
            
            if (response.success) {
                // بروزرسانی پیام در UI
                const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
                if (messageEl) {
                    const textEl = messageEl.querySelector('.message-text');
                    if (textEl) textEl.textContent = newText.trim();
                    
                    // اضافه کردن برچسب ویرایش شده
                    const metaEl = messageEl.querySelector('.message-meta');
                    if (metaEl && !metaEl.querySelector('.message-edited')) {
                        const editedSpan = document.createElement('span');
                        editedSpan.className = 'message-edited';
                        editedSpan.textContent = 'ویرایش شده';
                        metaEl.prepend(editedSpan);
                    }
                }
            }
        } catch (error) {
            console.error('خطا در ویرایش پیام:', error);
        }
    }
    
    async deleteMessage(messageId) {
        if (!confirm('آیا از حذف این پیام مطمئن هستید؟')) return;
        
        try {
            const response = await this.apiRequest('delete_message.php', {
                message_id: messageId
            }, 'POST');
            
            if (response.success) {
                const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
                messageEl?.remove();
            }
        } catch (error) {
            console.error('خطا در حذف پیام:', error);
        }
    }
    
    async handleFileUpload(files) {
        if (!files || files.length === 0) return;
        
        for (const file of files) {
            // بررسی حجم فایل (10MB)
            if (file.size > 10 * 1024 * 1024) {
                this.showError('حجم فایل بیش از 10 مگابایت است');
                continue;
            }
            
            const formData = new FormData();
            formData.append('file', file);
            formData.append('room_id', this.config.roomId);
            formData.append('csrf_token', this.config.csrfToken);
            
            if (this.state.replyTo) {
                formData.append('reply_to_id', this.state.replyTo.id);
            }
            
            try {
                const response = await fetch(`${this.config.apiBase}/upload_file.php`, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    this.cancelReply();
                    await this.fetchNewMessages();
                    this.scrollToBottom();
                } else {
                    this.showError(data.message || 'خطا در آپلود فایل');
                }
            } catch (error) {
                console.error('خطا در آپلود فایل:', error);
                this.showError('خطا در آپلود فایل');
            }
        }
        
        // پاک کردن input
        if (this.elements.fileInput) {
            this.elements.fileInput.value = '';
        }
    }
    
    handleTyping() {
        if (this.state.typingTimeout) {
            clearTimeout(this.state.typingTimeout);
        }
        
        if (!this.state.isTyping) {
            this.state.isTyping = true;
            this.sendTypingStatus(true);
        }
        
        this.state.typingTimeout = setTimeout(() => {
            this.state.isTyping = false;
            this.sendTypingStatus(false);
        }, 2000);
    }
    
    async sendTypingStatus(isTyping) {
        try {
            await this.apiRequest('typing_status.php', {
                room_id: this.config.roomId,
                is_typing: isTyping
            }, 'POST');
        } catch (error) {
            // بی‌صدا
        }
    }
    
    updateTypingIndicator(typingUsers) {
        if (!this.elements.typingIndicator) return;
        
        // فیلتر کردن کاربر فعلی
        const others = typingUsers.filter(u => u.id !== this.config.userId);
        
        if (others.length === 0) {
            this.elements.typingIndicator.style.display = 'none';
            return;
        }
        
        let text = '';
        if (others.length === 1) {
            text = `${others[0].name} در حال نوشتن است`;
        } else if (others.length === 2) {
            text = `${others[0].name} و ${others[1].name} در حال نوشتن هستند`;
        } else {
            text = `${others.length} نفر در حال نوشتن هستند`;
        }
        
        this.elements.typingIndicator.innerHTML = `
            <div class="typing-dots">
                <span></span><span></span><span></span>
            </div>
            ${text}
        `;
        this.elements.typingIndicator.style.display = 'block';
    }
    
    scrollToMessage(messageId) {
        const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
        if (messageEl) {
            messageEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            messageEl.style.animation = 'highlight 1s ease';
            setTimeout(() => {
                messageEl.style.animation = '';
            }, 1000);
        }
    }
    
    scrollToBottom() {
        if (this.elements.messagesContainer) {
            this.elements.messagesContainer.scrollTop = this.elements.messagesContainer.scrollHeight;
        }
    }
    
    checkScrollPosition() {
        if (!this.elements.messagesContainer) return;
        
        const { scrollTop, scrollHeight, clientHeight } = this.elements.messagesContainer;
        this.state.isScrolledToBottom = scrollHeight - scrollTop - clientHeight < 100;
    }
    
    adjustTextareaHeight() {
        const textarea = this.elements.messageInput;
        if (!textarea) return;
        
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 150) + 'px';
    }
    
    startPolling() {
        setInterval(() => {
            this.fetchNewMessages();
        }, this.config.pollInterval);
    }
    
    async apiRequest(endpoint, data = {}, method = 'GET') {
        const url = new URL(`${this.config.apiBase}/${endpoint}`, window.location.origin);
        
        const options = {
            method: method,
            headers: {
                'X-CSRF-Token': this.config.csrfToken
            }
        };
        
        if (method === 'GET') {
            Object.keys(data).forEach(key => {
                if (data[key] !== null && data[key] !== undefined) {
                    url.searchParams.append(key, data[key]);
                }
            });
        } else {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(data);
        }
        
        const response = await fetch(url.toString(), options);
        return await response.json();
    }
    
    // ابزارهای کمکی
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    formatMessageText(text) {
        // تبدیل URL به لینک
        const urlRegex = /(https?:\/\/[^\s]+)/g;
        text = this.escapeHtml(text);
        text = text.replace(urlRegex, '<a href="$1" target="_blank" rel="noopener">$1</a>');
        
        // تبدیل خط جدید به <br>
        text = text.replace(/\n/g, '<br>');
        
        return text;
    }
    
    formatTime(dateString) {
        const date = new Date(dateString);
        return date.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
    }
    
    formatDate(dateString) {
        const date = new Date(dateString);
        const today = new Date();
        const yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);
        
        if (date.toDateString() === today.toDateString()) {
            return 'امروز';
        } else if (date.toDateString() === yesterday.toDateString()) {
            return 'دیروز';
        } else {
            return date.toLocaleDateString('fa-IR');
        }
    }
    
    formatFileSize(bytes) {
        if (!bytes) return '0 بایت';
        const units = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت'];
        let i = 0;
        while (bytes >= 1024 && i < units.length - 1) {
            bytes /= 1024;
            i++;
        }
        return `${bytes.toFixed(1)} ${units[i]}`;
    }
    
    truncateText(text, maxLength) {
        if (text.length <= maxLength) return text;
        return text.substring(0, maxLength) + '...';
    }
    
    showError(message) {
        // TODO: نمایش پیام خطا به صورت toast
        alert(message);
    }
    
    // متدهای استاتیک
    static openImage(url) {
        // TODO: نمایش تصویر در lightbox
        window.open(url, '_blank');
    }
    
    static playVoice(button, url) {
        const audio = new Audio(url);
        const isPlaying = button.dataset.playing === 'true';
        
        if (isPlaying) {
            audio.pause();
            button.textContent = '▶';
            button.dataset.playing = 'false';
        } else {
            audio.play();
            button.textContent = '⏸';
            button.dataset.playing = 'true';
            
            audio.onended = () => {
                button.textContent = '▶';
                button.dataset.playing = 'false';
            };
        }
    }
}

// صادر کردن برای استفاده جهانی
window.ChatApp = ChatApp;
