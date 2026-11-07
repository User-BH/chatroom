<?php
/**
 * ===================================================
 * ChatRoom - اتصال به پایگاه داده
 * ===================================================
 * نسخه: 2.1
 * سازگار با: PHP 8.5
 * استفاده از: PDO
 */

require_once __DIR__ . '/config.php';

class Database {
    private static $instance = null;
    private $pdo = null;

    /**
     * سازنده خصوصی برای Singleton Pattern
     */
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

            // منطقه زمانی اتصال با PHP یکی می‌شود تا NOW() و خواندن TIMESTAMP
            // حتی روی سرور UTC، همان ساعتی را بدهد که PHP نمایش می‌دهد
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                Pdo\Mysql::ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE utf8mb4_unicode_ci, time_zone = '" . date('P') . "'"
            ];

            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            chatroomFatalResponse(503, 'خطا در برقراری ارتباط با سرور. لطفاً بعداً تلاش کنید.');
        }
    }

    /**
     * دریافت نمونه Singleton
     * @return Database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * بستن اتصال؛ getDB بعدی اتصال تازه می‌سازد
     */
    public static function disconnect() {
        self::$instance = null;
    }
    
    /**
     * دریافت اتصال PDO
     * @return PDO
     */
    public function getConnection() {
        return $this->pdo;
    }

    /**
     * جلوگیری از کلون کردن
     */
    private function __clone() {}

    /**
     * جلوگیری از unserialize
     */
    public function __unserialize(array $data): void {
        throw new Exception("Cannot unserialize singleton");
    }
}

/**
 * تابع کمکی برای دریافت اتصال PDO
 * @return PDO
 */
function getDB() {
    return Database::getInstance()->getConnection();
}
