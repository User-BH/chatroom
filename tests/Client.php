<?php
/**
 * کلاینت HTTP و ابزار گزارش مشترک تست‌ها
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

// ---------- کلاینت HTTP ----------
class Client {
    private $jar;
    public function __construct($base, $work) {
        $this->base = $base;
        $this->jar = tempnam($work, 'jar');
    }
    public $base;
    public $csrf = '';
    public function req($method, $path, $data = null, $headers = [], $follow = false) {
        $c = curl_init($this->base . '/' . $path);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_USERAGENT => 'chatroom-test',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_NOPROXY => '*',
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($data !== null) {
            curl_setopt($c, CURLOPT_POSTFIELDS, $data);
        }
        $raw = curl_exec($c);
        $status = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE);
        $h = substr($raw, 0, $hs);
        $b = substr($raw, $hs);
        return ['status' => $status, 'headers' => $h, 'body' => $b, 'json' => json_decode($b, true)];
    }
    public function token($path = 'login.php') {
        $r = $this->req('GET', $path);
        preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $r['body'], $m);
        return $m[1] ?? '';
    }
    public function login($user, $pass = 'Str0ngPass1', $room = null, $headers = []) {
        $t = $this->token();
        $r = $this->req('POST', 'login.php', ['csrf_token' => $t, 'step' => 'password', 'username' => $user, 'password' => $pass], $headers);
        if ($room !== null && $r['status'] === 200) {
            preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $r['body'], $m);
            $r = $this->req('POST', 'login.php', ['csrf_token' => $m[1] ?? '', 'step' => 'room', 'room_id' => $room], $headers);
        }
        if ($r['status'] === 302) {
            $this->loadChat();
        }
        return $r;
    }
    public function loadChat() {
        $r = $this->req('GET', 'chat.php');
        if (preg_match('/data-csrf-token="([a-f0-9]+)"/', $r['body'], $m)) {
            $this->csrf = $m[1];
        }
        return $r;
    }
    public function api($method, $path, $data = null) {
        return $this->req($method, $path, $data, ['X-CSRF-Token: ' . $this->csrf]);
    }
}

$passed = 0;
$failed = 0;
function check($name, $ok, $detail = '') {
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  PASS  $name\n";
    } else {
        $failed++;
        echo "  FAIL  $name" . ($detail !== '' ? "  => $detail" : '') . "\n";
    }
}
function section($t) {
    echo "\n== $t\n";
}
