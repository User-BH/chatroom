/**
 * منطق صفحه چت (chat.php)
 * همگام‌سازی با SSE و در صورت نبودن آن با polling؛ پیام متنی در صف محلی می‌ماند تا
 * ارسال شود. هیچ متن کاربری به صورت HTML تفسیر نمی‌شود.
 * چت روم پیشرفته - نسخه 3.0
 */
(function () {
    'use strict';

    const config = document.body.dataset;
    const csrfToken = config.csrfToken;
    const csrfName = config.csrfName;
    const userId = Number(config.userId);
    const siteName = config.siteName;
    const maxFileSize = Number(config.maxFileSize);
    const QUEUE_KEY = 'chatroom_queue_' + userId;
    const POLL_VISIBLE_MS = 3000;
    const POLL_HIDDEN_MS = 15000;
    const SSE_RETRY_MS = 60000;
    const MAX_RECORDING_MS = 5 * 60 * 1000;
    const REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

    const state = {
        roomId: Number(config.roomId),
        roomType: 'group',
        role: 'member',
        muted: false,
        memberCount: 0,
        cursor: 0,
        rooms: [],
        messages: new Map(),
        receipts: null,
        pinned: [],
        hasOlder: false,
        loadingOlder: false,
        atLatest: true,
        lastMarkedRead: 0,
        replyTo: null,
        editing: null,
        selected: null,
        members: null,
        mentionMatches: [],
        mentionIndex: 0,
        leaving: false,
        loadToken: 0
    };

    const sync = { source: null, pollTimer: null, sseRetryTimer: null, sseFailures: 0, inFlight: false, generation: 0 };

    const $ = function (id) { return document.getElementById(id); };
    const messagesEl = $('messages');
    const input = $('messageInput');
    const contextMenu = $('contextMenu');
    const toastEl = $('toast');

    // ---------- ابزارها ----------

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    function formatSize(bytes) {
        if (bytes === null || bytes === undefined) return '';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(0) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function showToast(message) {
        toastEl.textContent = message;
        toastEl.classList.add('show');
        clearTimeout(showToast.timer);
        showToast.timer = setTimeout(function () { toastEl.classList.remove('show'); }, 3000);
    }

    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    }

    function goToLogin() {
        if (state.leaving) return;
        state.leaving = true;
        location.href = 'login.php?error=session';
    }

    /** درخواست به API با توکن CSRF */
    async function api(url, options) {
        const response = await fetch(url, Object.assign({
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrfToken }
        }, options));
        if (response.status === 401) {
            goToLogin();
            throw new Error('session expired');
        }
        const data = await response.json();
        data.status = response.status;
        return data;
    }

    function roomQuery() {
        return 'room_id=' + state.roomId;
    }

    function postForm(fields) {
        const form = new FormData();
        form.append('room_id', state.roomId);
        Object.keys(fields).forEach(function (key) {
            if (fields[key] !== null && fields[key] !== undefined) form.append(key, fields[key]);
        });
        return { method: 'POST', body: form };
    }

    // ---------- نوار کناری ----------

    function renderRooms() {
        const list = $('roomList');
        list.textContent = '';
        let totalUnread = 0;
        state.rooms.forEach(function (room) {
            if (room.id !== state.roomId) totalUnread += room.unread;
            const item = el('button', 'room-item' + (room.id === state.roomId ? ' active' : ''));
            item.type = 'button';
            const avatar = el('div', 'room-item-avatar' + (room.type === 'direct' ? ' direct' : ''), room.initials);
            avatar.style.background = room.color;
            item.appendChild(avatar);
            item.appendChild(el('div', 'room-item-name', room.name));
            if (room.mentions > 0) item.appendChild(el('span', 'badge mention', '@'));
            if (room.unread > 0) item.appendChild(el('span', 'badge', room.unread > 99 ? '99+' : room.unread));
            item.addEventListener('click', function () {
                closeSidebar();
                if (room.id !== state.roomId) switchRoom(room.id, true);
            });
            list.appendChild(item);
        });

        const current = state.rooms.find(function (room) { return room.id === state.roomId; });
        if (current) {
            $('roomName').textContent = current.name;
            const avatar = $('roomAvatar');
            avatar.textContent = current.initials;
            avatar.style.background = current.color;
            document.title = (totalUnread > 0 ? '(' + totalUnread + ') ' : '') + current.name + ' - ' + siteName;
        }
    }

    function openSidebar() { $('sidebar').classList.add('open'); }
    function closeSidebar() { $('sidebar').classList.remove('open'); }

    function updateOnlineCount(count) {
        if (state.roomType === 'direct') {
            $('roomStatus').textContent = count > 1 ? 'آنلاین' : 'گفتگوی خصوصی';
        } else {
            $('roomStatus').textContent = count + ' آنلاین از ' + state.memberCount + ' عضو';
        }
    }

    function renderPinned() {
        const bar = $('pinnedBar');
        bar.hidden = state.pinned.length === 0;
        if (state.pinned.length > 0) {
            $('pinnedText').textContent = state.pinned[0].sender_name + ': ' + state.pinned[0].text;
        }
    }

    // ---------- بارگذاری روم ----------

    async function switchRoom(roomId, pushHistory) {
        stopSync();
        state.roomId = roomId;
        state.members = null;
        cancelComposerMode();
        closeSearch();
        if (pushHistory) history.pushState({ room: roomId }, '', 'chat.php?room=' + roomId);
        await loadRoom();
    }

    function clearMessages() {
        state.messages.clear();
        messagesEl.textContent = '';
    }

    function applyRoomData(data) {
        state.roomType = data.room_type;
        state.role = data.role;
        state.muted = data.muted;
        state.memberCount = data.member_count;
        state.receipts = data.receipts;
        state.pinned = data.pinned;
        state.hasOlder = data.has_older;
        state.atLatest = !data.has_newer;
        state.cursor = data.cursor;
        state.rooms = data.rooms;
        input.placeholder = state.muted ? 'شما در این روم ساکت شده‌اید' : 'پیام خود را بنویسید...';
        renderRooms();
        renderPinned();
        updateOnlineCount(data.online_count);
    }

    async function loadRoom(aroundId) {
        const token = ++state.loadToken;
        clearMessages();
        const loading = el('div', 'loading', 'در حال بارگذاری...');
        messagesEl.appendChild(loading);
        state.lastMarkedRead = 0;

        try {
            const around = aroundId ? '&around_id=' + aroundId : '';
            const result = await api('get_messages.php?' + roomQuery() + '&last_id=0' + around);
            if (token !== state.loadToken) return;

            if (!result.success) {
                // دسترسی به این روم بسته شده؛ به روم دیگری برمی‌گردیم
                if (result.status === 403) {
                    showToast(result.message);
                    const fallback = state.rooms.find(function (room) { return room.id !== state.roomId; });
                    if (fallback) {
                        switchRoom(fallback.id, true);
                    } else {
                        location.href = 'chat.php';
                    }
                    return;
                }
                loading.textContent = result.message;
                return;
            }

            loading.remove();
            applyRoomData(result.data);
            result.data.messages.forEach(upsertMessage);
            renderPendingQueue();
            updateEmptyState();
            refreshTicks();

            if (aroundId) {
                focusMessage(aroundId);
            } else {
                scrollToBottom();
                markReadSoon();
            }
            startSync();
            flushQueue();
        } catch (error) {
            if (token === state.loadToken && !state.leaving) {
                console.error('Error loading room:', error);
                loading.textContent = 'خطا در بارگذاری پیام‌ها';
                setConnectionState(false);
                setTimeout(function () {
                    if (token === state.loadToken) loadRoom(aroundId);
                }, 5000);
            }
        }
    }

    async function loadOlder() {
        if (state.loadingOlder || !state.hasOlder || state.messages.size === 0) return;
        state.loadingOlder = true;
        const oldest = Math.min.apply(null, Array.from(state.messages.keys()));
        const previousHeight = messagesEl.scrollHeight;
        const token = state.loadToken;
        try {
            const result = await api('get_messages.php?' + roomQuery() + '&last_id=0&before_id=' + oldest);
            if (token !== state.loadToken || !result.success) return;
            state.hasOlder = result.data.has_older;
            result.data.messages.forEach(upsertMessage);
            refreshTicks();
            messagesEl.scrollTop += messagesEl.scrollHeight - previousHeight;
        } catch (error) {
            console.error('Error loading older messages:', error);
        } finally {
            state.loadingOlder = false;
        }
    }

    function updateEmptyState() {
        const existing = messagesEl.querySelector('.empty-room');
        const isEmpty = state.messages.size === 0 && !messagesEl.querySelector('.message');
        if (isEmpty && !existing) {
            messagesEl.appendChild(el('div', 'empty-room', 'هنوز پیامی در این گفتگو نیست.'));
        } else if (!isEmpty && existing) {
            existing.remove();
        }
    }

    // ---------- نمایش پیام ----------

    function appendTextWithMentions(parent, text) {
        const parts = text.split(/(@[a-zA-Z0-9_]{3,50})/);
        parts.forEach(function (part, index) {
            if (index % 2 === 1) {
                parent.appendChild(el('span', 'mention', part));
            } else if (part) {
                parent.appendChild(document.createTextNode(part));
            }
        });
    }

    function renderAttachment(bubble, msg) {
        if (!msg.file_url) return;
        if (msg.message_type === 'image') {
            const image = el('img', 'message-image');
            image.src = msg.file_url;
            image.alt = msg.file_name || '';
            image.loading = 'lazy';
            image.addEventListener('click', function (event) {
                event.stopPropagation();
                window.open(msg.file_url, '_blank', 'noopener');
            });
            bubble.appendChild(image);
        } else if (msg.message_type === 'voice') {
            const audio = el('audio', 'message-audio');
            audio.controls = true;
            audio.preload = 'none';
            audio.src = msg.file_url;
            audio.addEventListener('click', function (event) { event.stopPropagation(); });
            bubble.appendChild(audio);
        } else {
            const link = el('a', 'message-file');
            link.href = msg.file_url;
            link.addEventListener('click', function (event) { event.stopPropagation(); });
            const svgNs = 'http://www.w3.org/2000/svg';
            const svg = document.createElementNS(svgNs, 'svg');
            svg.setAttribute('viewBox', '0 0 24 24');
            const path = document.createElementNS(svgNs, 'path');
            path.setAttribute('d', 'M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm4 18H6V4h7v5h5v11z');
            svg.appendChild(path);
            link.appendChild(svg);
            const info = el('div');
            info.appendChild(el('div', null, msg.file_name));
            info.appendChild(el('div', 'message-file-size', formatSize(msg.file_size)));
            link.appendChild(info);
            bubble.appendChild(link);
        }
    }

    function replyLabel(msg) {
        if (msg.reply_message) {
            return msg.reply_message.length > 60 ? msg.reply_message.substring(0, 60) + '...' : msg.reply_message;
        }
        return msg.reply_type === 'image' ? 'تصویر' : msg.reply_type === 'voice' ? 'پیام صوتی' : 'فایل';
    }

    function buildMessageElement(msg) {
        const row = el('div', 'message ' + (msg.is_mine ? 'outgoing' : 'incoming') + (msg.mentions_me ? ' mentioned' : ''));
        row.dataset.id = msg.id;

        const bubble = el('div', 'message-bubble');
        bubble.addEventListener('click', function (event) { showContextMenu(event, msg.id); });

        if (msg.reply_to && msg.reply_sender_name) {
            const reply = el('div', 'reply-preview');
            reply.appendChild(el('div', 'reply-preview-name', msg.reply_sender_name));
            reply.appendChild(el('div', 'reply-preview-text', replyLabel(msg)));
            reply.addEventListener('click', function (event) {
                event.stopPropagation();
                jumpToMessage(msg.reply_to);
            });
            bubble.appendChild(reply);
        }

        if (!msg.is_mine && state.roomType !== 'direct') {
            const sender = el('div', 'message-sender', msg.sender_name);
            sender.style.color = msg.avatar_color;
            bubble.appendChild(sender);
        }

        renderAttachment(bubble, msg);

        if (msg.message) {
            const text = el('div', 'message-text');
            appendTextWithMentions(text, msg.message);
            bubble.appendChild(text);
        }

        if (msg.reactions.length > 0) {
            const reactions = el('div', 'reactions');
            msg.reactions.forEach(function (reaction) {
                const chip = el('button', 'reaction-chip' + (reaction.mine ? ' mine' : ''), reaction.emoji + ' ' + reaction.count);
                chip.type = 'button';
                chip.addEventListener('click', function (event) {
                    event.stopPropagation();
                    toggleReaction(msg.id, reaction.emoji);
                });
                reactions.appendChild(chip);
            });
            bubble.appendChild(reactions);
        }

        const time = el('div', 'message-time');
        if (msg.is_pinned) time.appendChild(el('span', null, '📌'));
        if (msg.is_edited) time.appendChild(el('span', null, 'ویرایش شده'));
        time.appendChild(el('span', null, msg.time));
        if (msg.is_mine) time.appendChild(el('span', 'tick'));
        bubble.appendChild(time);

        row.appendChild(bubble);
        return row;
    }

    /** پیام را در جای درست بر اساس شناسه قرار می‌دهد یا جایگزین می‌کند */
    function upsertMessage(msg) {
        const element = buildMessageElement(msg);
        const existing = messagesEl.querySelector('.message[data-id="' + msg.id + '"]');
        state.messages.set(msg.id, msg);
        if (existing) {
            existing.replaceWith(element);
            return;
        }
        const emptyNote = messagesEl.querySelector('.empty-room');
        if (emptyNote) emptyNote.remove();

        const rows = messagesEl.querySelectorAll('.message:not(.pending)');
        let before = null;
        for (let i = rows.length - 1; i >= 0; i--) {
            if (Number(rows[i].dataset.id) < msg.id) break;
            before = rows[i];
        }
        messagesEl.insertBefore(element, before || messagesEl.querySelector('.message.pending'));
    }

    function removeMessage(id) {
        state.messages.delete(id);
        const row = messagesEl.querySelector('.message[data-id="' + id + '"]');
        if (row) row.remove();
        updateEmptyState();
    }

    /** وضعیت ارسال پیام‌های خودم از روی نشانگر سایر اعضا */
    function refreshTicks() {
        const receipts = state.receipts;
        messagesEl.querySelectorAll('.message.outgoing:not(.pending)').forEach(function (row) {
            const tick = row.querySelector('.tick');
            if (!tick) return;
            if (receipts === null || receipts.length === 0) {
                tick.textContent = '✓';
                tick.className = 'tick';
                tick.title = '';
                return;
            }
            const id = Number(row.dataset.id);
            const delivered = receipts.filter(function (r) { return r.delivered >= id; }).length;
            const read = receipts.filter(function (r) { return r.read >= id; }).length;
            const allRead = read === receipts.length;
            tick.textContent = delivered === receipts.length ? '✓✓' : '✓';
            tick.className = 'tick' + (allRead ? ' read' : '');
            tick.title = receipts.length > 1 ? 'خوانده‌شده توسط ' + read + ' از ' + receipts.length : (allRead ? 'خوانده شد' : '');
        });
    }

    function focusMessage(id) {
        const row = messagesEl.querySelector('.message[data-id="' + id + '"]');
        if (!row) return false;
        row.scrollIntoView({ block: 'center' });
        row.classList.remove('highlight');
        void row.offsetHeight;
        row.classList.add('highlight');
        return true;
    }

    function jumpToMessage(id) {
        closeSearch();
        if (focusMessage(id)) return;
        stopSync();
        loadRoom(id);
    }

    function scrollToBottom() {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function isScrolledToBottom() {
        return messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 100;
    }

    // ---------- همگام‌سازی ----------

    function setConnectionState(online, text) {
        const bar = $('connectionBar');
        bar.hidden = online;
        if (!online) bar.textContent = text || 'اتصال قطع است؛ پیام‌ها پس از برقراری ارسال می‌شوند.';
    }

    function applySync(payload) {
        if (payload.resync) {
            stopSync();
            loadRoom();
            return;
        }
        state.cursor = payload.cursor;
        setConnectionState(true);

        const wasAtBottom = isScrolledToBottom();
        let hasNew = false;

        payload.messages.forEach(function (msg) {
            const known = state.messages.has(msg.id);
            // وقتی کاربر پیام‌های قدیمی را می‌بیند، پیام جدید بین آن‌ها نمی‌نشیند
            if (!known && !state.atLatest) return;
            if (!known) hasNew = true;
            upsertMessage(msg);
        });
        payload.deleted_ids.forEach(removeMessage);

        if (payload.pinned) {
            state.pinned = payload.pinned;
            renderPinned();
        }
        if (payload.receipts !== undefined) {
            state.receipts = payload.receipts;
        }
        refreshTicks();
        if (payload.rooms) {
            state.rooms = payload.rooms;
            if (!state.rooms.some(function (room) { return room.id === state.roomId; })) {
                loadRoom();
                return;
            }
            renderRooms();
        }
        updateOnlineCount(payload.online_count);

        // نوار سنجاق یا واکنش جدید ارتفاع را عوض می‌کند؛ کاربر پایین گفتگو همان‌جا می‌ماند
        if (wasAtBottom) scrollToBottom();
        if (hasNew) markReadSoon();
    }

    function startSync() {
        stopSync();
        if (window.EventSource && sync.sseFailures < 3) {
            startEventSource();
        } else {
            startPolling();
        }
    }

    function stopSync() {
        sync.generation++;
        if (sync.source) {
            sync.source.close();
            sync.source = null;
        }
        clearTimeout(sync.pollTimer);
        sync.pollTimer = null;
        clearTimeout(sync.sseRetryTimer);
        sync.sseRetryTimer = null;
    }

    function startEventSource() {
        const source = new EventSource('events.php?' + roomQuery() + '&cursor=' + state.cursor);
        sync.source = source;

        source.addEventListener('sync', function (event) {
            sync.sseFailures = 0;
            applySync(JSON.parse(event.data));
        });

        // قطع طبیعی پس از پایان عمر اتصال هم خطا گزارش می‌شود و مرورگر خودش وصل می‌شود؛
        // خطای پشت سر هم یعنی SSE در دسترس نیست (مثلاً ظرفیت پر است)
        source.addEventListener('error', function () {
            if (source !== sync.source) return;
            sync.sseFailures++;
            if (source.readyState === EventSource.CLOSED || sync.sseFailures >= 3) {
                source.close();
                sync.source = null;
                startPolling();
                sync.sseRetryTimer = setTimeout(function () {
                    sync.sseFailures = 0;
                    startSync();
                }, SSE_RETRY_MS);
            }
        });
    }

    function startPolling() {
        clearTimeout(sync.pollTimer);
        // با هر توقف نسل عوض می‌شود تا حلقه قدیمی پس از تعویض روم ادامه ندهد
        const generation = sync.generation;
        const tick = async function () {
            if (generation !== sync.generation) return;
            await pollOnce();
            if (generation !== sync.generation) return;
            sync.pollTimer = setTimeout(tick, document.hidden ? POLL_HIDDEN_MS : POLL_VISIBLE_MS);
        };
        sync.pollTimer = setTimeout(tick, 0);
    }

    async function pollOnce() {
        if (sync.inFlight) return;
        sync.inFlight = true;
        const roomId = state.roomId;
        try {
            const result = await api('sync.php?' + roomQuery() + '&cursor=' + state.cursor);
            if (roomId !== state.roomId) return;
            if (result.success) {
                applySync(result.data);
            } else if (result.status === 403) {
                loadRoom();
            }
        } catch (error) {
            if (!state.leaving) setConnectionState(false);
        } finally {
            sync.inFlight = false;
        }
    }

    function markReadSoon() {
        clearTimeout(markReadSoon.timer);
        markReadSoon.timer = setTimeout(markRead, 500);
    }

    async function markRead() {
        if (document.hidden || !state.atLatest || !isScrolledToBottom() || state.messages.size === 0) return;
        const newest = Math.max.apply(null, Array.from(state.messages.keys()));
        if (newest <= state.lastMarkedRead) return;
        state.lastMarkedRead = newest;
        try {
            const result = await api('mark_read.php', postForm({ message_id: newest }));
            if (result.success) {
                state.rooms = result.data.rooms;
                renderRooms();
            }
        } catch (error) {
            state.lastMarkedRead = 0;
        }
    }

    function updateStatus() {
        api('update_status.php', postForm({ status: 'online' })).catch(function () {});
    }

    // ---------- صف ارسال ----------

    function readQueue() {
        try {
            return JSON.parse(localStorage.getItem(QUEUE_KEY)) || [];
        } catch (error) {
            return [];
        }
    }

    function writeQueue(queue) {
        try {
            if (queue.length > 0) {
                localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
            } else {
                localStorage.removeItem(QUEUE_KEY);
            }
        } catch (error) {
            console.error('Queue storage unavailable:', error);
        }
    }

    function buildPendingElement(item) {
        const row = el('div', 'message outgoing pending');
        row.dataset.token = item.token;
        const bubble = el('div', 'message-bubble');
        const text = el('div', 'message-text');
        appendTextWithMentions(text, item.message);
        bubble.appendChild(text);
        const time = el('div', 'message-time');
        time.appendChild(el('span', null, 'در صف ارسال'));
        bubble.appendChild(time);
        row.appendChild(bubble);
        return row;
    }

    function renderPendingQueue() {
        messagesEl.querySelectorAll('.message.pending').forEach(function (row) { row.remove(); });
        readQueue().filter(function (item) { return item.room_id === state.roomId; }).forEach(function (item) {
            messagesEl.appendChild(buildPendingElement(item));
        });
    }

    function removeFromQueue(token) {
        writeQueue(readQueue().filter(function (item) { return item.token !== token; }));
        const row = messagesEl.querySelector('.message.pending[data-token="' + token + '"]');
        if (row) row.remove();
        updateEmptyState();
    }

    async function flushQueue() {
        if (flushQueue.running || state.leaving) return;
        flushQueue.running = true;
        try {
            let queue = readQueue();
            while (queue.length > 0) {
                const item = queue[0];
                const form = new FormData();
                form.append('room_id', item.room_id);
                form.append('message', item.message);
                form.append('client_token', item.token);
                if (item.reply_to) form.append('reply_to', item.reply_to);

                let result;
                try {
                    result = await api('send_message.php', { method: 'POST', body: form });
                } catch (error) {
                    setConnectionState(false);
                    return; // شبکه قطع است؛ پیام‌ها در صف می‌مانند
                }

                // خطای موقت سرور: بعداً دوباره تلاش می‌شود
                if (result.status >= 500 || result.status === 429) {
                    return;
                }

                removeFromQueue(item.token);
                if (!result.success) {
                    showToast(result.message);
                    if (item.room_id === state.roomId && input.value === '') input.value = item.message;
                }
                setConnectionState(true);
                queue = readQueue();
            }
            if (!sync.source) pollOnce();
        } finally {
            flushQueue.running = false;
        }
    }

    // ---------- ارسال و ویرایش ----------

    function submitComposer() {
        const text = input.value.trim();
        if (!text) return;
        if (state.editing) {
            submitEdit(text);
            return;
        }
        const item = { token: uuid(), room_id: state.roomId, message: text, reply_to: state.replyTo ? state.replyTo.id : null };
        const queue = readQueue();
        queue.push(item);
        writeQueue(queue);
        messagesEl.appendChild(buildPendingElement(item));
        const emptyNote = messagesEl.querySelector('.empty-room');
        if (emptyNote) emptyNote.remove();

        input.value = '';
        input.style.height = 'auto';
        cancelComposerMode();
        hideMentions();
        scrollToBottom();
        flushQueue();
    }

    async function submitEdit(text) {
        const id = state.editing;
        try {
            const result = await api('edit_message.php', postForm({ message_id: id, text: text }));
            if (result.success) {
                input.value = '';
                input.style.height = 'auto';
                cancelComposerMode();
            } else {
                showToast(result.message);
            }
        } catch (error) {
            showToast('ویرایش انجام نشد؛ اتصال را بررسی کنید.');
        }
    }

    function setComposerBar(name, text) {
        $('composerBar').hidden = false;
        $('composerBarName').textContent = name;
        $('composerBarText').textContent = text;
    }

    function cancelComposerMode() {
        const wasEditing = state.editing !== null;
        state.replyTo = null;
        state.editing = null;
        $('composerBar').hidden = true;
        if (wasEditing) {
            input.value = '';
            input.style.height = 'auto';
        }
    }

    // ---------- منوی پیام ----------

    function showContextMenu(event, id) {
        event.preventDefault();
        event.stopPropagation();
        const msg = state.messages.get(id);
        if (!msg) return;
        state.selected = msg;

        $('menuEdit').hidden = !msg.can_edit;
        $('menuDelete').hidden = !msg.can_delete;
        $('menuPin').hidden = !msg.can_pin;
        $('menuPin').textContent = msg.is_pinned ? 'برداشتن سنجاق' : 'سنجاق';
        $('menuCopy').hidden = !msg.message;

        contextMenu.classList.add('active');
        const width = contextMenu.offsetWidth;
        const height = contextMenu.offsetHeight;
        contextMenu.style.left = Math.max(8, Math.min(event.clientX, window.innerWidth - width - 8)) + 'px';
        contextMenu.style.top = Math.max(8, Math.min(event.clientY, window.innerHeight - height - 8)) + 'px';
    }

    function hideContextMenu() {
        contextMenu.classList.remove('active');
    }

    /** navigator.clipboard فقط روی HTTPS یا localhost وجود دارد */
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

    async function postAction(url, fields) {
        try {
            const result = await api(url, postForm(fields));
            if (!result.success) showToast(result.message);
            return result;
        } catch (error) {
            showToast('انجام نشد؛ اتصال را بررسی کنید.');
            return null;
        }
    }

    function toggleReaction(id, emoji) {
        postAction('react_message.php', { message_id: id, emoji: emoji });
    }

    // ---------- منشن ----------

    function loadMembers() {
        if (state.members === null) {
            state.members = api('room_members.php?' + roomQuery()).then(function (result) {
                return result.success ? result.data.members : [];
            }).catch(function () {
                state.members = null;
                return [];
            });
        }
        return state.members;
    }

    function currentMentionQuery() {
        const before = input.value.slice(0, input.selectionStart);
        const match = before.match(/(?:^|[^a-zA-Z0-9_])@([a-zA-Z0-9_]*)$/);
        return match ? match[1].toLowerCase() : null;
    }

    function hideMentions() {
        state.mentionMatches = [];
        $('mentionBox').hidden = true;
    }

    function renderMentions() {
        const box = $('mentionBox');
        box.textContent = '';
        state.mentionMatches.forEach(function (member, index) {
            const item = el('button', 'mention-item' + (index === state.mentionIndex ? ' active' : ''), member.name);
            item.type = 'button';
            item.appendChild(el('small', null, '@' + member.username));
            item.addEventListener('mousedown', function (event) {
                event.preventDefault();
                insertMention(member);
            });
            box.appendChild(item);
        });
        box.hidden = state.mentionMatches.length === 0;
    }

    async function updateMentions() {
        if (currentMentionQuery() === null) {
            hideMentions();
            return;
        }
        const members = await loadMembers();
        // کاربر در این فاصله ممکن است متن را عوض کرده باشد
        const query = currentMentionQuery();
        if (query === null) {
            hideMentions();
            return;
        }
        state.mentionMatches = members.filter(function (member) {
            return member.username.toLowerCase().indexOf(query) === 0 || member.name.toLowerCase().indexOf(query) !== -1;
        }).slice(0, 8);
        state.mentionIndex = 0;
        renderMentions();
    }

    function insertMention(member) {
        const position = input.selectionStart;
        const before = input.value.slice(0, position).replace(/@[a-zA-Z0-9_]*$/, '@' + member.username + ' ');
        input.value = before + input.value.slice(position);
        input.selectionStart = input.selectionEnd = before.length;
        hideMentions();
        input.focus();
    }

    // ---------- آپلود ----------

    function uploadFile(file, kind) {
        return new Promise(function (resolve) {
            if (file.size > maxFileSize) {
                showToast('حجم فایل ' + file.name + ' بیشتر از حد مجاز است.');
                resolve(false);
                return;
            }
            const form = new FormData();
            form.append('room_id', state.roomId);
            form.append('file', file, file.name);
            if (kind) form.append('kind', kind);

            const bar = $('uploadProgressBar');
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'upload_file.php');
            xhr.setRequestHeader('X-CSRF-Token', csrfToken);
            $('uploadProgress').hidden = false;
            bar.style.width = '0';
            xhr.upload.addEventListener('progress', function (event) {
                if (event.lengthComputable) bar.style.width = (event.loaded * 100 / event.total) + '%';
            });
            xhr.addEventListener('loadend', function () {
                $('uploadProgress').hidden = true;
                if (xhr.status === 401) {
                    goToLogin();
                    resolve(false);
                    return;
                }
                let data = null;
                try {
                    data = JSON.parse(xhr.responseText);
                } catch (error) {
                    data = null;
                }
                if (xhr.status === 413) {
                    showToast('حجم فایل بیشتر از حد مجاز است.');
                } else if (!data || !data.success) {
                    showToast(data ? data.message : 'آپلود انجام نشد؛ اتصال را بررسی کنید.');
                }
                if (!sync.source) pollOnce();
                resolve(Boolean(data && data.success));
            });
            xhr.send(form);
        });
    }

    async function uploadFiles(files) {
        for (const file of Array.from(files)) {
            await uploadFile(file);
        }
        scrollToBottom();
    }

    // ---------- پیام صوتی ----------

    const recorder = { media: null, stream: null, chunks: [], timer: null, cancelled: false };

    async function toggleRecording() {
        if (recorder.media) {
            stopRecording(false);
            return;
        }
        // مرورگر میکروفن را فقط روی HTTPS یا localhost می‌دهد
        if (!navigator.mediaDevices || !window.MediaRecorder) {
            showToast('ضبط صدا فقط روی اتصال HTTPS در دسترس است.');
            return;
        }
        try {
            recorder.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (error) {
            showToast('دسترسی به میکروفن داده نشد.');
            return;
        }
        const type = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4'].find(function (candidate) {
            return MediaRecorder.isTypeSupported(candidate);
        });
        recorder.media = new MediaRecorder(recorder.stream, type ? { mimeType: type } : undefined);
        recorder.chunks = [];
        recorder.cancelled = false;
        recorder.media.addEventListener('dataavailable', function (event) {
            if (event.data.size > 0) recorder.chunks.push(event.data);
        });
        recorder.media.addEventListener('stop', function () {
            const mime = recorder.media.mimeType || 'audio/webm';
            const blob = new Blob(recorder.chunks, { type: mime });
            recorder.stream.getTracks().forEach(function (track) { track.stop(); });
            recorder.media = null;
            recorder.stream = null;
            clearTimeout(recorder.timer);
            $('voiceBtn').classList.remove('recording');
            if (!recorder.cancelled && blob.size > 0) {
                const extension = mime.indexOf('ogg') !== -1 ? 'ogg' : mime.indexOf('mp4') !== -1 ? 'm4a' : 'webm';
                uploadFile(new File([blob], 'voice.' + extension, { type: mime }), 'voice').then(scrollToBottom);
            }
        });
        recorder.media.start();
        $('voiceBtn').classList.add('recording');
        showToast('در حال ضبط؛ برای ارسال دوباره بزنید.');
        recorder.timer = setTimeout(function () { stopRecording(false); }, MAX_RECORDING_MS);
    }

    function stopRecording(cancel) {
        if (!recorder.media) return;
        recorder.cancelled = cancel;
        recorder.media.stop();
    }

    // ---------- جست‌وجو ----------

    function openSearch() {
        $('searchPanel').hidden = false;
        $('searchInput').focus();
    }

    function closeSearch() {
        $('searchPanel').hidden = true;
        $('searchInput').value = '';
        $('searchResults').textContent = '';
    }

    async function runSearch() {
        const query = $('searchInput').value.trim();
        const results = $('searchResults');
        if (query.length < 2) {
            results.textContent = '';
            return;
        }
        try {
            const result = await api('search_messages.php?' + roomQuery() + '&q=' + encodeURIComponent(query));
            if (query !== $('searchInput').value.trim()) return;
            results.textContent = '';
            if (!result.success) {
                results.appendChild(el('div', 'search-empty', result.message));
                return;
            }
            if (result.data.results.length === 0) {
                results.appendChild(el('div', 'search-empty', 'نتیجه‌ای پیدا نشد.'));
                return;
            }
            result.data.results.forEach(function (item) {
                const button = el('button', 'search-result');
                button.type = 'button';
                button.appendChild(el('div', 'search-result-meta', item.sender_name + ' · ' + item.time));
                button.appendChild(el('div', 'search-result-text', item.text));
                button.addEventListener('click', function () { jumpToMessage(item.id); });
                results.appendChild(button);
            });
        } catch (error) {
            showToast('جست‌وجو انجام نشد.');
        }
    }

    // ---------- گفتگوی خصوصی ----------

    async function openContacts() {
        const list = $('contactList');
        list.textContent = '';
        $('contactsModal').hidden = false;
        try {
            const result = await api('contacts.php?' + roomQuery());
            if (!result.success) {
                list.appendChild(el('div', 'search-empty', result.message));
                return;
            }
            if (result.data.contacts.length === 0) {
                list.appendChild(el('div', 'search-empty', 'هم‌رومی دیگری ندارید.'));
                return;
            }
            result.data.contacts.forEach(function (contact) {
                const item = el('button', 'mention-item', contact.name);
                item.type = 'button';
                item.appendChild(el('small', null, '@' + contact.username));
                item.addEventListener('click', function () { startDirect(contact.id); });
                list.appendChild(item);
            });
        } catch (error) {
            list.appendChild(el('div', 'search-empty', 'فهرست دریافت نشد.'));
        }
    }

    async function startDirect(contactId) {
        const result = await postAction('start_direct.php', { user_id: contactId });
        if (result && result.success) {
            $('contactsModal').hidden = true;
            closeSidebar();
            switchRoom(result.data.room_id, true);
        }
    }

    // ---------- رویدادها ----------

    REACTIONS.forEach(function (emoji) {
        const button = el('button', 'reaction-btn', emoji);
        button.type = 'button';
        button.addEventListener('click', function () {
            if (state.selected) toggleReaction(state.selected.id, emoji);
            hideContextMenu();
        });
        $('reactionRow').appendChild(button);
    });

    document.addEventListener('click', hideContextMenu);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            hideContextMenu();
            hideMentions();
            closeSidebar();
            $('contactsModal').hidden = true;
            if (state.replyTo || state.editing) cancelComposerMode();
        }
    });

    $('sendBtn').addEventListener('click', submitComposer);
    $('composerBarClose').addEventListener('click', cancelComposerMode);
    $('menuBtn').addEventListener('click', openSidebar);
    $('sidebarBackdrop').addEventListener('click', closeSidebar);
    $('searchBtn').addEventListener('click', openSearch);
    $('searchClose').addEventListener('click', closeSearch);
    $('newChatBtn').addEventListener('click', openContacts);
    $('contactsClose').addEventListener('click', function () { $('contactsModal').hidden = true; });
    $('attachBtn').addEventListener('click', function () { $('fileInput').click(); });
    $('voiceBtn').addEventListener('click', toggleRecording);

    $('pinnedBar').addEventListener('click', function () {
        if (state.pinned.length > 0) jumpToMessage(state.pinned[0].id);
    });

    $('fileInput').addEventListener('change', function () {
        uploadFiles(this.files);
        this.value = '';
    });

    $('scrollBottomBtn').addEventListener('click', function () {
        if (state.atLatest) {
            scrollToBottom();
            markReadSoon();
        } else {
            stopSync();
            loadRoom();
        }
    });

    $('menuReply').addEventListener('click', function () {
        const msg = state.selected;
        if (!msg) return;
        state.editing = null;
        state.replyTo = msg;
        setComposerBar(msg.sender_name, msg.message || msg.file_name || '');
        input.focus();
    });

    $('menuCopy').addEventListener('click', function () {
        if (!state.selected) return;
        copyText(state.selected.message).then(function () {
            showToast('کپی شد');
        }).catch(function () {
            showToast('کپی انجام نشد');
        });
    });

    $('menuEdit').addEventListener('click', function () {
        const msg = state.selected;
        if (!msg) return;
        state.replyTo = null;
        state.editing = msg.id;
        setComposerBar('ویرایش پیام', msg.message);
        input.value = msg.message;
        input.dispatchEvent(new Event('input'));
        input.focus();
    });

    $('menuPin').addEventListener('click', function () {
        if (state.selected) postAction('pin_message.php', { message_id: state.selected.id });
    });

    $('menuDelete').addEventListener('click', function () {
        if (state.selected && window.confirm('این پیام حذف شود؟')) {
            postAction('delete_message.php', { message_id: state.selected.id });
        }
    });

    messagesEl.addEventListener('scroll', function () {
        const atBottom = isScrolledToBottom();
        $('scrollBottomBtn').classList.toggle('visible', !atBottom || !state.atLatest);
        if (messagesEl.scrollTop < 150) loadOlder();
        if (atBottom) markReadSoon();
    });

    input.addEventListener('keydown', function (event) {
        if (state.mentionMatches.length > 0) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const step = event.key === 'ArrowDown' ? 1 : -1;
                state.mentionIndex = (state.mentionIndex + step + state.mentionMatches.length) % state.mentionMatches.length;
                renderMentions();
                return;
            }
            if (event.key === 'Enter' || event.key === 'Tab') {
                event.preventDefault();
                insertMention(state.mentionMatches[state.mentionIndex]);
                return;
            }
        }
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            submitComposer();
        }
    });

    input.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        updateMentions();
    });

    input.addEventListener('paste', function (event) {
        const files = event.clipboardData ? event.clipboardData.files : null;
        if (files && files.length > 0) {
            event.preventDefault();
            uploadFiles(files);
        }
    });

    $('searchInput').addEventListener('input', function () {
        clearTimeout(runSearch.timer);
        runSearch.timer = setTimeout(runSearch, 300);
    });

    // کشیدن و رها کردن فایل
    let dragDepth = 0;
    document.addEventListener('dragenter', function (event) {
        if (!event.dataTransfer || Array.from(event.dataTransfer.types).indexOf('Files') === -1) return;
        event.preventDefault();
        dragDepth++;
        $('dropOverlay').hidden = false;
    });
    document.addEventListener('dragover', function (event) { event.preventDefault(); });
    document.addEventListener('dragleave', function () {
        dragDepth = Math.max(0, dragDepth - 1);
        if (dragDepth === 0) $('dropOverlay').hidden = true;
    });
    document.addEventListener('drop', function (event) {
        event.preventDefault();
        dragDepth = 0;
        $('dropOverlay').hidden = true;
        if (event.dataTransfer && event.dataTransfer.files.length > 0) uploadFiles(event.dataTransfer.files);
    });

    // خروج: پیام‌های در صف و هرچه از این کاربر روی دستگاه مانده پاک می‌شود
    $('logoutForm').addEventListener('submit', function () {
        state.leaving = true;
        writeQueue([]);
    });

    window.addEventListener('online', flushQueue);
    window.addEventListener('offline', function () { setConnectionState(false); });
    window.addEventListener('popstate', function () {
        const room = Number(new URLSearchParams(location.search).get('room'));
        if (room && room !== state.roomId) switchRoom(room, false);
    });
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) markReadSoon();
    });

    // هنگام بستن صفحه؛ sendBeacon هدر سفارشی ندارد پس توکن در فرم می‌رود
    window.addEventListener('beforeunload', function () {
        if (state.leaving) return;
        const form = new FormData();
        form.append(csrfName, csrfToken);
        form.append('room_id', state.roomId);
        form.append('status', 'offline');
        navigator.sendBeacon('update_status.php', form);
    });

    // ---------- راه‌اندازی ----------

    if ('serviceWorker' in navigator && window.isSecureContext) {
        navigator.serviceWorker.register('sw.js').catch(function (error) {
            console.error('Service worker registration failed:', error);
        });
    }

    history.replaceState({ room: state.roomId }, '', 'chat.php?room=' + state.roomId);
    loadRoom();
    setInterval(updateStatus, Number(config.heartbeatMs));
    setInterval(flushQueue, 5000);
})();
