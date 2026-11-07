<?php
/**
 * منطق مشترک چت: رویدادها، پیام‌ها، روم‌ها و رسیدها
 * چت روم پیشرفته - نسخه 3.0
 * سازگار با PHP 8.5
 */

const REACTION_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

// ==============================================
// رویدادها
// ==============================================

/**
 * ثبت تغییر در فید رویداد روم؛ کلاینت‌ها با این فید همگام می‌شوند
 * @param int $roomId
 * @param string $type
 * @param int|null $messageId
 * @param int|null $actorId
 */
function recordRoomEvent($roomId, $type, $messageId = null, $actorId = null) {
    $stmt = getDB()->prepare("INSERT INTO room_events (room_id, type, message_id, actor_id) VALUES (:room, :type, :message, :actor)");
    $stmt->execute([':room' => $roomId, ':type' => $type, ':message' => $messageId, ':actor' => $actorId]);
    @touch(EVENT_SIGNAL_FILE);
}

/**
 * @return int
 */
function latestEventId() {
    return (int)getDB()->query("SELECT COALESCE(MAX(id), 0) FROM room_events")->fetchColumn();
}

/**
 * آیا رسید پیام برای این روم فعال است؟ در روم‌های بزرگ هر پیام هزاران رویداد می‌ساخت
 * @param int $roomId
 * @return bool
 */
function roomHasReceipts($roomId) {
    $stmt = getDB()->prepare("SELECT COUNT(*) FROM users_rooms WHERE room_id = :room");
    $stmt->execute([':room' => $roomId]);
    return (int)$stmt->fetchColumn() <= RECEIPT_ROOM_LIMIT;
}

// ==============================================
// پیام‌ها
// ==============================================

/**
 * بخش ثابت انتخاب پیام؛ متن پیام حذف‌شده از طریق پاسخ‌ها لو نمی‌رود
 */
const MESSAGE_SELECT = "
    SELECT
        m.id, m.room_id, m.user_id, m.message, m.reply_to, m.message_type,
        m.file_path, m.file_name, m.file_size, m.file_mime, m.created_at, m.is_edited, m.pinned_at,
        u.name AS sender_name,
        r.message AS reply_message,
        r.message_type AS reply_type,
        ru.name AS reply_sender_name
    FROM messages m
    JOIN users u ON m.user_id = u.id
    LEFT JOIN messages r ON m.reply_to = r.id AND r.is_deleted = 0
    LEFT JOIN users ru ON r.user_id = ru.id
";

/**
 * تبدیل ردیف‌های پیام به خروجی API برای یک بیننده مشخص
 * @param array $rows
 * @param int $viewerId
 * @param array $membership
 * @return array
 */
function serializeMessages(array $rows, $viewerId, array $membership) {
    if (!$rows) {
        return [];
    }

    $db = getDB();
    $ids = implode(',', array_map('intval', array_column($rows, 'id')));

    $reactions = [];
    $stmt = $db->prepare("
        SELECT message_id, emoji, COUNT(*) AS total, SUM(user_id = :viewer) AS mine
        FROM message_reactions
        WHERE message_id IN ($ids)
        GROUP BY message_id, emoji
    ");
    $stmt->execute([':viewer' => $viewerId]);
    foreach ($stmt->fetchAll() as $row) {
        $reactions[(int)$row['message_id']][] = [
            'emoji' => $row['emoji'],
            'count' => (int)$row['total'],
            'mine' => (int)$row['mine'] > 0
        ];
    }

    $stmt = $db->prepare("SELECT message_id FROM message_mentions WHERE user_id = :viewer AND message_id IN ($ids)");
    $stmt->execute([':viewer' => $viewerId]);
    $mentions = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

    $isModerator = in_array($membership['role'], ['admin', 'moderator'], true);
    $isDirect = ($membership['type'] ?? 'group') === 'direct';
    $muted = isMembershipMuted($membership);

    $messages = [];
    foreach ($rows as $msg) {
        $id = (int)$msg['id'];
        $isMine = (int)$msg['user_id'] === (int)$viewerId;
        $messages[] = [
            'id' => $id,
            'room_id' => (int)$msg['room_id'],
            'user_id' => (int)$msg['user_id'],
            'message' => $msg['message'],
            'reply_to' => $msg['reply_to'] ? (int)$msg['reply_to'] : null,
            'reply_message' => $msg['reply_message'],
            'reply_type' => $msg['reply_type'],
            'reply_sender_name' => $msg['reply_sender_name'],
            'message_type' => $msg['message_type'],
            // فایل فقط از مسیر کنترل‌شده دانلود می‌شود، نه با آدرس مستقیم
            'file_url' => $msg['file_path'] ? 'download.php?id=' . $id : null,
            'file_name' => $msg['file_name'],
            'file_size' => $msg['file_size'] !== null ? (int)$msg['file_size'] : null,
            'sender_name' => $msg['sender_name'],
            'time' => formatTime($msg['created_at']),
            'full_time' => $msg['created_at'],
            'is_edited' => (bool)$msg['is_edited'],
            'is_mine' => $isMine,
            'is_pinned' => $msg['pinned_at'] !== null,
            'mentions_me' => isset($mentions[$id]),
            'reactions' => $reactions[$id] ?? [],
            'can_edit' => $isMine && !$muted && $msg['message_type'] === 'text'
                && time() - strtotime($msg['created_at']) <= MESSAGE_EDIT_WINDOW,
            'can_delete' => $isMine || ($isModerator && !$isDirect),
            'can_pin' => $isModerator || $isDirect,
            'avatar_color' => getAvatarColor($msg['sender_name']),
            'initials' => getInitials($msg['sender_name'])
        ];
    }
    return $messages;
}

/**
 * پیام‌های متنی منشن‌شده را ثبت می‌کند؛ فقط اعضای همان روم
 * @param int $messageId
 * @param int $roomId
 * @param string $text
 */
function saveMessageMentions($messageId, $roomId, $text) {
    $db = getDB();
    $db->prepare("DELETE FROM message_mentions WHERE message_id = :id")->execute([':id' => $messageId]);

    if (!preg_match_all('/(?<![a-zA-Z0-9_])@([a-zA-Z0-9_]{3,50})/', $text, $matches)) {
        return;
    }

    $find = $db->prepare("
        SELECT u.id FROM users u
        JOIN users_rooms ur ON ur.user_id = u.id AND ur.room_id = :room
        WHERE u.username = :username AND u.is_active = 1
    ");
    $insert = $db->prepare("INSERT IGNORE INTO message_mentions (message_id, user_id) VALUES (:message, :user)");
    foreach (array_slice(array_unique($matches[1]), 0, 20) as $username) {
        $find->execute([':room' => $roomId, ':username' => $username]);
        $userId = $find->fetchColumn();
        if ($userId) {
            $insert->execute([':message' => $messageId, ':user' => $userId]);
        }
    }
}

/**
 * پیام‌های سنجاق‌شده روم
 * @param int $roomId
 * @return array
 */
function getPinnedMessages($roomId) {
    $stmt = getDB()->prepare("
        SELECT m.id, m.message, m.message_type, m.file_name, u.name AS sender_name
        FROM messages m
        JOIN users u ON u.id = m.user_id
        WHERE m.room_id = :room AND m.pinned_at IS NOT NULL AND m.is_deleted = 0
        ORDER BY m.pinned_at DESC
        LIMIT 5
    ");
    $stmt->execute([':room' => $roomId]);
    return array_map(function ($row) {
        $text = $row['message'] !== '' ? $row['message'] : (string)$row['file_name'];
        return [
            'id' => (int)$row['id'],
            'text' => mb_substr($text, 0, 120, 'UTF-8'),
            'sender_name' => $row['sender_name']
        ];
    }, $stmt->fetchAll());
}

// ==============================================
// روم‌ها و رسیدها
// ==============================================

/**
 * فهرست روم‌های کاربر برای نوار کناری
 * @param int $userId
 * @return array
 */
function getRoomList($userId) {
    $stmt = getDB()->prepare("
        SELECT r.id, r.type, r.name, ur.role,
            (SELECT COUNT(*) FROM messages m
             WHERE m.room_id = r.id AND m.id > ur.last_read_message_id AND m.is_deleted = 0 AND m.user_id <> :u1) AS unread,
            (SELECT COUNT(*) FROM message_mentions mm
             JOIN messages m2 ON m2.id = mm.message_id
             WHERE mm.user_id = :u2 AND m2.room_id = r.id AND m2.id > ur.last_read_message_id AND m2.is_deleted = 0) AS mentions,
            (SELECT MAX(m3.id) FROM messages m3 WHERE m3.room_id = r.id AND m3.is_deleted = 0) AS last_message_id,
            (SELECT u.name FROM users_rooms p JOIN users u ON u.id = p.user_id
             WHERE p.room_id = r.id AND p.user_id <> :u3 LIMIT 1) AS peer_name
        FROM users_rooms ur
        JOIN rooms r ON r.id = ur.room_id AND r.is_active = 1
        WHERE ur.user_id = :u4
        ORDER BY last_message_id DESC, r.id DESC
    ");
    $stmt->execute([':u1' => $userId, ':u2' => $userId, ':u3' => $userId, ':u4' => $userId]);

    return array_map(function ($row) {
        $name = $row['type'] === 'direct' ? ($row['peer_name'] ?? 'گفتگوی خصوصی') : $row['name'];
        return [
            'id' => (int)$row['id'],
            'type' => $row['type'],
            'name' => $name,
            'initials' => getInitials($name),
            'color' => getAvatarColor($name),
            'role' => $row['role'],
            'unread' => (int)$row['unread'],
            'mentions' => (int)$row['mentions']
        ];
    }, $stmt->fetchAll());
}

/**
 * نشانگرهای تحویل و خواندن سایر اعضا
 * @param int $roomId
 * @param int $viewerId
 * @return array|null null یعنی رسید برای این روم فعال نیست
 */
function getRoomReceipts($roomId, $viewerId) {
    if (!roomHasReceipts($roomId)) {
        return null;
    }
    $stmt = getDB()->prepare("
        SELECT user_id, last_read_message_id, last_delivered_message_id
        FROM users_rooms
        WHERE room_id = :room AND user_id <> :viewer
    ");
    $stmt->execute([':room' => $roomId, ':viewer' => $viewerId]);
    return array_map(function ($row) {
        return [
            'user_id' => (int)$row['user_id'],
            'read' => (int)$row['last_read_message_id'],
            'delivered' => max((int)$row['last_read_message_id'], (int)$row['last_delivered_message_id'])
        ];
    }, $stmt->fetchAll());
}

/**
 * جلو بردن نشانگر خواندن یا تحویل؛ فقط رو به جلو و فقط تا آخرین پیام واقعی روم
 * @param int $userId
 * @param int $roomId
 * @param int $messageId
 * @param string $kind read|delivered
 * @return bool آیا نشانگر جلو رفت
 */
function advanceReadCursor($userId, $roomId, $messageId, $kind) {
    $db = getDB();
    $column = $kind === 'read' ? 'last_read_message_id' : 'last_delivered_message_id';

    $stmt = $db->prepare("
        UPDATE users_rooms ur
        JOIN (SELECT COALESCE(MAX(id), 0) AS newest FROM messages WHERE room_id = :room1) x
        SET ur.$column = LEAST(:id1, x.newest)
        WHERE ur.user_id = :user AND ur.room_id = :room2 AND ur.$column < LEAST(:id2, x.newest)
    ");
    $stmt->execute([':room1' => $roomId, ':id1' => $messageId, ':user' => $userId, ':room2' => $roomId, ':id2' => $messageId]);

    $moved = $stmt->rowCount() > 0;
    if ($moved && roomHasReceipts($roomId)) {
        recordRoomEvent($roomId, 'receipt', null, $userId);
    }
    return $moved;
}

// ==============================================
// همگام‌سازی
// ==============================================

/**
 * تغییرات پس از cursor برای یک کاربر؛ مشترک بین SSE و polling
 * @param int $userId
 * @param int $activeRoomId
 * @param array $membership عضویت کاربر در روم فعال
 * @param int $cursor
 * @return array
 */
function buildSyncPayload($userId, $activeRoomId, array $membership, $cursor) {
    $db = getDB();

    // فاصله بیش از حد: رویدادهای لازم پاک شده‌اند و کلاینت باید از نو بارگذاری کند
    $oldest = (int)$db->query("SELECT COALESCE(MIN(id), 0) FROM room_events")->fetchColumn();
    $payload = [
        'cursor' => $cursor,
        'resync' => $cursor > 0 && $oldest > $cursor + 1,
        'messages' => [],
        'deleted_ids' => [],
        'online_count' => count(getOnlineUsers($activeRoomId))
    ];

    $stmt = $db->prepare("
        SELECT e.id, e.room_id, e.type, e.message_id, e.actor_id
        FROM room_events e
        JOIN users_rooms ur ON ur.room_id = e.room_id AND ur.user_id = :user
        WHERE e.id > :cursor
        ORDER BY e.id ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':user', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':cursor', $cursor, PDO::PARAM_INT);
    $stmt->bindValue(':limit', MAX_EVENTS_PER_SYNC, PDO::PARAM_INT);
    $stmt->execute();
    $events = $stmt->fetchAll();

    if (!$events) {
        return $payload;
    }

    $payload['cursor'] = (int)end($events)['id'];

    $changedIds = [];
    $pinChanged = false;
    $receiptChanged = false;
    $newestByRoom = [];

    foreach ($events as $event) {
        $roomId = (int)$event['room_id'];
        if ($event['type'] === 'message_new' && (int)$event['actor_id'] !== (int)$userId) {
            $newestByRoom[$roomId] = max($newestByRoom[$roomId] ?? 0, (int)$event['message_id']);
        }
        if ($roomId !== (int)$activeRoomId) {
            continue;
        }
        if ($event['type'] === 'receipt') {
            $receiptChanged = true;
        } elseif ($event['type'] === 'pin' || $event['type'] === 'message_edit') {
            // متن نوار سنجاق هم باید با ویرایش پیام به‌روز شود
            $pinChanged = true;
            $changedIds[(int)$event['message_id']] = true;
        } elseif ($event['message_id'] !== null) {
            $changedIds[(int)$event['message_id']] = true;
        }
    }

    // هر پیام جدیدی که به این کاربر رسیده تحویل‌شده است؛ در روم بزرگ رسید خاموش است و
    // این نوشتن به ازای هر کاربر و هر پیام، ارسال بلادرنگ را کند می‌کرد
    foreach ($newestByRoom as $roomId => $messageId) {
        if (roomHasReceipts($roomId)) {
            advanceReadCursor($userId, $roomId, $messageId, 'delivered');
        }
    }

    if ($changedIds) {
        $ids = implode(',', array_map('intval', array_keys($changedIds)));
        $rows = $db->query(MESSAGE_SELECT . " WHERE m.id IN ($ids) AND m.room_id = " . (int)$activeRoomId . " AND m.is_deleted = 0 ORDER BY m.id ASC")->fetchAll();
        $payload['messages'] = serializeMessages($rows, $userId, $membership);
        $payload['deleted_ids'] = array_values(array_diff(array_keys($changedIds), array_map('intval', array_column($rows, 'id'))));
    }
    if ($pinChanged) {
        $payload['pinned'] = getPinnedMessages($activeRoomId);
    }
    if ($receiptChanged) {
        $payload['receipts'] = getRoomReceipts($activeRoomId, $userId);
    }
    $payload['rooms'] = getRoomList($userId);

    return $payload;
}

// ==============================================
// دعوت
// ==============================================

/**
 * دعوت معتبر بر اساس توکن خام؛ خود توکن هرگز در دیتابیس ذخیره نمی‌شود
 * @param mixed $token
 * @return array|null
 */
function findValidInvite($token) {
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = getDB()->prepare("
        SELECT i.id, i.room_id, r.name AS room_name
        FROM room_invites i
        JOIN rooms r ON r.id = i.room_id AND r.is_active = 1 AND r.type = 'group'
        WHERE i.token_hash = :hash
        AND i.revoked_at IS NULL
        AND (i.expires_at IS NULL OR i.expires_at > NOW())
        AND (i.max_uses IS NULL OR i.uses < i.max_uses)
    ");
    $stmt->execute([':hash' => hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

/**
 * پیوستن کاربر به روم با دعوت
 * سقف استفاده با UPDATE شرطی کنترل می‌شود تا درخواست‌های همزمان از آن رد نشوند
 * @param int $userId
 * @param array $invite
 * @return bool
 */
function redeemInvite($userId, array $invite) {
    $db = getDB();
    $roomId = (int)$invite['room_id'];

    $stmt = $db->prepare("SELECT 1 FROM users_rooms WHERE user_id = :user AND room_id = :room");
    $stmt->execute([':user' => $userId, ':room' => $roomId]);
    if ($stmt->fetch()) {
        return true; // از قبل عضو است و ظرفیت دعوت مصرف نمی‌شود
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("UPDATE room_invites SET uses = uses + 1 WHERE id = :id AND (max_uses IS NULL OR uses < max_uses)");
        $stmt->execute([':id' => $invite['id']]);
        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            return false;
        }
        // عضو جدید پیام‌های قبلی را خوانده‌نشده نمی‌بیند
        $stmt = $db->prepare("
            INSERT INTO users_rooms (user_id, room_id, role, last_read_message_id, last_delivered_message_id)
            SELECT :user, :room1, 'member', COALESCE(MAX(id), 0), COALESCE(MAX(id), 0) FROM messages WHERE room_id = :room2
        ");
        $stmt->execute([':user' => $userId, ':room1' => $roomId, ':room2' => $roomId]);
        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();
        throw $e;
    }

    logSecurityEvent('room_joined_by_invite', $userId, null, ['room_id' => $roomId, 'invite_id' => (int)$invite['id']]);
    return true;
}
