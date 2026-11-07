<?php
/**
 * تست بار همگام‌سازی روی Nginx و PHP-FPM واقعی
 *
 *   docker compose run --rm tests php tests/load.php --users=100 --mode=sse --seconds=40
 *   docker compose run --rm tests php tests/load.php --users=100 --mode=poll --seconds=40
 *
 * N کاربر در یک روم می‌نشینند، یک فرستنده هر 2 ثانیه پیام می‌فرستد و زمان رسیدن پیام
 * به هر کاربر سنجیده می‌شود. پاسخ‌گویی پول اصلی هم هم‌زمان اندازه‌گیری می‌شود.
 * جزو CI نیست و فقط روی محیط تست اجرا شود.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require __DIR__ . '/Client.php';

$options = getopt('', ['users:', 'mode:', 'seconds:']);
$userCount = (int)($options['users'] ?? 50);
$mode = $options['mode'] ?? 'sse';
$seconds = (int)($options['seconds'] ?? 40);

$base = getenv('CHATROOM_TEST_WEB_BASE');
$dbHost = getenv('CHATROOM_TEST_DB_HOST');
if (!$base || !$dbHost) {
    fwrite(STDERR, "Run through docker compose (see docker-compose.yml).\n");
    exit(2);
}

$work = sys_get_temp_dir() . '/chatroom-load-' . bin2hex(random_bytes(4));
mkdir($work);

$pdo = new PDO("mysql:host=$dbHost;dbname=chatroom_secure;charset=utf8mb4", getenv('CHATROOM_TEST_DB_USER'), (string)getenv('CHATROOM_TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$tag = bin2hex(random_bytes(3));
$password = 'Load' . bin2hex(random_bytes(6)) . 'A1';
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);

$pdo->prepare("INSERT INTO rooms (name) VALUES (?)")->execute(["Load $tag"]);
$roomId = (int)$pdo->lastInsertId();

echo "Preparing $userCount users...\n";
$insertUser = $pdo->prepare("INSERT INTO users (username, password, name) VALUES (?, ?, ?)");
$insertMember = $pdo->prepare("INSERT INTO users_rooms (user_id, room_id) VALUES (?, ?)");
$clients = [];
for ($i = 0; $i <= $userCount; $i++) {
    $username = "load_{$tag}_$i";
    $insertUser->execute([$username, $hash, "Load $i"]);
    $insertMember->execute([(int)$pdo->lastInsertId(), $roomId]);
    $client = new Client($base, $work);
    if ($client->login($username, $password)['status'] !== 302) {
        fwrite(STDERR, "Login failed for $username\n");
        exit(1);
    }
    $clients[] = $client;
}
$sender = array_shift($clients);
$startCursor = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM room_events")->fetchColumn();

// ---------- اتصال‌ها ----------
$multi = curl_multi_init();
$streams = [];
$latencies = [];
$seen = [];
$statusCounts = [];
$bytes = 0;

$scanForMessages = function ($index, $chunk) use (&$latencies, &$seen) {
    if (preg_match_all('/load-msg-(\d+)-(\d+\.\d+)/', $chunk, $matches, PREG_SET_ORDER)) {
        $now = monotonicSeconds();
        foreach ($matches as $match) {
            $key = $index . ':' . $match[1];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $latencies[] = $now - (float)$match[2];
            }
        }
    }
};

$open = function ($index) use (&$streams, &$bytes, $multi, $clients, $base, $mode, $roomId, $scanForMessages) {
    $state = &$streams[$index];
    // کلاینت واقعی اگر SSE جا نداشته باشد به polling برمی‌گردد
    $polling = $mode !== 'sse' || $state['fallback'];
    $path = $polling ? 'sync.php' : 'events.php';
    $handle = curl_init("$base/$path?room_id=$roomId&cursor=" . $state['cursor']);
    curl_setopt_array($handle, [
        CURLOPT_COOKIEFILE => $clients[$index]->jarPath(),
        CURLOPT_USERAGENT => 'chatroom-test',
        CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $clients[$index]->csrf],
        CURLOPT_NOPROXY => '*',
        CURLOPT_TIMEOUT => 60,
        CURLOPT_WRITEFUNCTION => function ($h, $chunk) use ($index, &$state, &$bytes, $scanForMessages) {
            $bytes += strlen($chunk);
            if ($state['opened'] !== null) {
                $GLOBALS['connectTimes'][] = monotonicSeconds() - $state['opened'];
                $state['opened'] = null;
            }
            $state['buffer'] .= $chunk;
            $scanForMessages($index, $chunk);
            if (preg_match_all('/(?:^id: |"cursor":)(\d+)/m', $state['buffer'], $ids)) {
                $state['cursor'] = max($state['cursor'], (int)end($ids[1]));
            }
            if (strlen($state['buffer']) > 8192) {
                $state['buffer'] = substr($state['buffer'], -2048);
            }
            return strlen($chunk);
        },
    ]);
    $state['handle'] = $handle;
    $state['opened'] = monotonicSeconds();
    curl_multi_add_handle($multi, $handle);
};

foreach ($clients as $index => $client) {
    $streams[$index] = ['cursor' => $startCursor, 'buffer' => '', 'handle' => null, 'next' => null, 'fallback' => false, 'opened' => null];
    // پخش اتصال‌ها در زمان تا همه با هم شروع نکنند
    $streams[$index]['next'] = monotonicSeconds() + ($index / max(1, count($clients))) * ($mode === 'sse' ? 3 : 3);
}

echo "Running mode=$mode for {$seconds}s...\n";
$end = monotonicSeconds() + $seconds;
$nextSend = monotonicSeconds() + 4;
$nextProbe = monotonicSeconds() + 2;
$sequence = 0;
$sent = 0;
$sendErrors = 0;
$probeTimes = [];
$probeFailures = 0;
$fallbackCount = function () use (&$streams) {
    return count(array_filter($streams, function ($s) {
        return $s['fallback'];
    }));
};
$peakThreads = 0;
$handleToIndex = function ($handle) use (&$streams) {
    foreach ($streams as $index => $state) {
        if ($state['handle'] === $handle) {
            return $index;
        }
    }
    return null;
};

$probe = new Client($base, $work);

while (monotonicSeconds() < $end) {
    $now = monotonicSeconds();
    foreach ($streams as $index => $state) {
        if ($state['handle'] === null && $state['next'] !== null && $state['next'] <= $now) {
            $streams[$index]['next'] = null;
            $open($index);
        }
    }

    curl_multi_exec($multi, $running);
    curl_multi_select($multi, 0.05);

    while ($info = curl_multi_info_read($multi)) {
        $handle = $info['handle'];
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $key = $info['result'] === CURLE_OK ? (string)$status : 'curl_' . $info['result'];
        $statusCounts[$key] = ($statusCounts[$key] ?? 0) + 1;
        $index = $handleToIndex($handle);
        curl_multi_remove_handle($multi, $handle);
        if ($index !== null) {
            $streams[$index]['handle'] = null;
            // SSE بسته‌شده مثل مرورگر پس از مکث وصل می‌شود؛ polling مثل کلاینت هر 3 ثانیه
            if ($mode === 'sse' && $status === 503) {
                $streams[$index]['fallback'] = true;
            }
            $polls = $mode !== 'sse' || $streams[$index]['fallback'];
            $streams[$index]['next'] = monotonicSeconds() + ($polls ? 3 : 0.5);
        }
    }

    if ($now >= $nextSend) {
        $nextSend = $now + 2;
        $sequence++;
        $r = $sender->api('POST', 'send_message.php', ['room_id' => $roomId, 'message' => "load-msg-$sequence-" . sprintf('%.4f', monotonicSeconds())]);
        if ($r['status'] === 200) {
            $sent++;
        } else {
            $sendErrors++;
        }
    }

    if ($now >= $nextProbe) {
        $nextProbe = $now + 2;
        $t = monotonicSeconds();
        $peakThreads = max($peakThreads, (int)$pdo->query("SHOW STATUS LIKE 'Threads_connected'")->fetch(PDO::FETCH_NUM)[1]);
        $r = $probe->req('GET', 'login.php');
        if ($r['status'] === 200) {
            $probeTimes[] = monotonicSeconds() - $t;
        } else {
            $probeFailures++;
        }
    }
}

// ---------- نتیجه ----------
$percentile = function (array $values, $p) {
    if (!$values) {
        return 0;
    }
    sort($values);
    return $values[(int)min(count($values) - 1, floor(count($values) * $p))];
};

// پیام‌های چند ثانیه آخر فرصت رسیدن کامل نداشته‌اند
$expected = max(1, ($sent - 2)) * count($clients);
$receivedForExpected = 0;
foreach (array_keys($seen) as $key) {
    if ((int)explode(':', $key)[1] <= $sent - 2) {
        $receivedForExpected++;
    }
}

printf("users=%d mode=%s sent=%d send_errors=%d\n", count($clients), $mode, $sent, $sendErrors);
printf("delivered=%.1f%% latency_p50=%.2fs p95=%.2fs max=%.2fs\n",
    100 * $receivedForExpected / $expected, $percentile($latencies, 0.5), $percentile($latencies, 0.95), $latencies ? max($latencies) : 0);
printf("main_pool login.php p50=%.0fms p95=%.0fms failures=%d\n", 1000 * $percentile($probeTimes, 0.5), 1000 * $percentile($probeTimes, 0.95), $probeFailures);
ksort($statusCounts);
printf("first_byte p50=%.0fms p95=%.0fms max=%.0fms
", 1000 * $percentile($GLOBALS['connectTimes'] ?? [], 0.5), 1000 * $percentile($GLOBALS['connectTimes'] ?? [], 0.95), 1000 * (!empty($GLOBALS['connectTimes']) ? max($GLOBALS['connectTimes']) : 0));
echo 'polling_fallback_clients=' . $fallbackCount() . ' peak_mysql_connections=' . $peakThreads . "
";
echo 'responses=' . json_encode($statusCounts) . ' transferred=' . round($bytes / 1048576, 1) . "MB\n";

// ---------- پاکسازی ----------
foreach ($streams as $state) {
    if ($state['handle'] !== null) {
        curl_multi_remove_handle($multi, $state['handle']);
    }
}
$pdo->exec("DELETE FROM login_attempts WHERE username LIKE 'load\\_{$tag}\\_%'");
$pdo->exec("DELETE FROM users WHERE username LIKE 'load\\_{$tag}\\_%'");
$pdo->prepare("DELETE FROM rooms WHERE id = ?")->execute([$roomId]);
