<?php
/**
 * جریان SSE تغییرات
 * هر اتصال یک worker از پول جدای chatroom-sse می‌گیرد و عمر کوتاهی دارد؛ مرورگر با
 * Last-Event-ID خودکار وصل می‌شود. اگر ظرفیت پر باشد Nginx پاسخ 503 می‌دهد و کلاینت
 * به polling برمی‌گردد، پس SSE هرگز جلوی بقیه درخواست‌ها را نمی‌گیرد.
 * فقط خواندنی است و مرورگر پاسخ آن را به سایت دیگری نمی‌دهد، پس توکن CSRF ندارد.
 * چت روم پیشرفته - نسخه 3.0
 * سازگار با PHP 8.5
 */

define('API_REQUEST', true);
require_once __DIR__ . '/functions.php';

$access = requireChatApiAccess('GET', 'events', 60, 60, false);
session_write_close();

$userId = $access['user_id'];
$roomId = $access['room_id'];
$cursor = validatePositiveInt($_SERVER['HTTP_LAST_EVENT_ID'] ?? ($_GET['cursor'] ?? 0)) ?: 0;

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');
while (ob_get_level() > 0) {
    ob_end_clean();
}

/**
 * @param array $payload
 */
function sendSyncEvent(array $payload) {
    echo 'id: ' . (int)$payload['cursor'] . "\nevent: sync\ndata: "
        . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
    flush();
}

echo "retry: 2000\n\n";
flush();

/**
 * زمان لمس فایل سیگنال؛ false یعنی سیگنال در دسترس نیست
 * @return int|false
 */
function eventSignal() {
    clearstatcache(true, EVENT_SIGNAL_FILE);
    return @filemtime(EVENT_SIGNAL_FILE);
}

// در حالت بیکار هیچ اتصال دیتابیسی نگه داشته نمی‌شود. وگرنه چند صد SSE باز تمام
// max_connections را می‌گرفت و بقیه برنامه از کار می‌افتاد.
Database::disconnect();

// ساعت یکنوا: تنظیم ساعت سیستم (NTP یا VM) نباید اتصال را زودتر ببندد
$startedAt = hrtime(true) / 1e9;
$lastOnlineUpdate = null;
$lastSignal = null;
$recheckTicks = 0;

while ((hrtime(true) / 1e9) - $startedAt < SSE_MAX_SECONDS && !connection_aborted()) {
    $now = hrtime(true) / 1e9;
    $signal = eventSignal();
    
    // دقت زمان فایل یک ثانیه است و سیگنال ممکن است پیش از commit لمس شده باشد؛
    // پس از هر تغییر چند بار دیگر هم بررسی می‌شود تا رویدادی جا نماند
    if ($signal === false || $signal !== $lastSignal) {
        $lastSignal = $signal;
        $recheckTicks = 3;
    }
    
    $onlineDue = $lastOnlineUpdate === null || $now - $lastOnlineUpdate >= SSE_ONLINE_INTERVAL;
    
    if ($recheckTicks > 0 || $onlineDue) {
        $recheckTicks = max(0, $recheckTicks - 1);
        $hasEvents = latestEventId() > $cursor;
        if ($hasEvents || $onlineDue) {
            $payload = buildSyncPayload($userId, $roomId, $access['membership'], $cursor);
            $cursor = (int)$payload['cursor'];
            $lastOnlineUpdate = $now;
            sendSyncEvent($payload);
        }
        Database::disconnect();
    }
    
    // خط توضیح تا قطع شدن اتصال کشف شود و پروکسی آن را نبندد
    echo ": keep-alive\n\n";
    flush();
    
    sleep(SSE_POLL_SECONDS);
}
