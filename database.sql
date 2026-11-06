-- ===================================================
-- ChatRoom Database Schema - Secure Version
-- ===================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+03:30";

CREATE DATABASE IF NOT EXISTS `chatroom_secure` 
DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `chatroom_secure`;

-- ===================================================
-- جدول ادمین‌ها
-- ===================================================
CREATE TABLE `admins` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_login` TIMESTAMP NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `login_attempts` INT DEFAULT 0,
    `locked_until` TIMESTAMP NULL,
    INDEX `idx_username` (`username`),
    INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================
-- جدول کاربران
-- ===================================================
CREATE TABLE `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `avatar` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('online', 'offline', 'away') DEFAULT 'offline',
    `last_seen` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `is_active` TINYINT(1) DEFAULT 1,
    `login_attempts` INT DEFAULT 0,
    `locked_until` TIMESTAMP NULL,
    INDEX `idx_username` (`username`),
    INDEX `idx_status` (`status`),
    INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================
-- جدول روم‌ها
-- ===================================================
CREATE TABLE `rooms` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `avatar` VARCHAR(255) DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `is_active` TINYINT(1) DEFAULT 1,
    INDEX `idx_name` (`name`),
    INDEX `idx_is_active` (`is_active`),
    FOREIGN KEY (`created_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================
-- جدول عضویت کاربران در روم‌ها
-- ===================================================
CREATE TABLE `users_rooms` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `room_id` INT UNSIGNED NOT NULL,
    `joined_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `role` ENUM('member', 'moderator', 'admin') DEFAULT 'member',
    `is_muted` TINYINT(1) DEFAULT 0,
    `muted_until` TIMESTAMP NULL,
    UNIQUE KEY `unique_user_room` (`user_id`, `room_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_room_id` (`room_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================
-- جدول پیام‌ها (با قابلیت ریپلای)
-- ===================================================
CREATE TABLE `messages` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `room_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `message` TEXT NOT NULL,
    `reply_to` INT UNSIGNED DEFAULT NULL,
    `message_type` ENUM('text', 'image', 'file', 'voice', 'system') DEFAULT 'text',
    `file_path` VARCHAR(500) DEFAULT NULL,
    `file_name` VARCHAR(255) DEFAULT NULL,
    `file_size` INT UNSIGNED DEFAULT NULL,
    `file_mime` VARCHAR(100) DEFAULT NULL,
    `is_edited` TINYINT(1) DEFAULT 0,
    `edited_at` TIMESTAMP NULL,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `deleted_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_room_id` (`room_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_reply_to` (`reply_to`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_message_type` (`message_type`),
    FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`reply_to`) REFERENCES `messages`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================
-- جدول وضعیت خوانده شدن پیام‌ها
-- ===================================================
CREATE TABLE `message_reads` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_message_user` (`message_id`, `user_id`),
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================
-- جدول توکن‌های CSRF
-- ===================================================
CREATE TABLE `csrf_tokens` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `token` VARCHAR(64) NOT NULL UNIQUE,
    `session_id` VARCHAR(128) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NOT NULL,
    INDEX `idx_token` (`token`),
    INDEX `idx_session_id` (`session_id`),
    INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================
-- جدول لاگ‌های امنیتی
-- ===================================================
CREATE TABLE `security_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `admin_id` INT UNSIGNED DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` TEXT,
    `details` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_admin_id` (`admin_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_ip_address` (`ip_address`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================
-- جدول تلاش‌های ورود
-- ===================================================
CREATE TABLE `login_attempts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL,
    `username` VARCHAR(50) DEFAULT NULL,
    `attempt_type` ENUM('user', 'admin') DEFAULT 'user',
    `success` TINYINT(1) DEFAULT 0,
    `attempted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ip_address` (`ip_address`),
    INDEX `idx_attempt_type` (`attempt_type`),
    INDEX `idx_attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================
-- جدول تنظیمات سیستم
-- ===================================================
CREATE TABLE `settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key_name` VARCHAR(100) NOT NULL UNIQUE,
    `value` TEXT,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================
-- درج داده‌های اولیه
-- ===================================================

-- ادمین پیش‌فرض (password: Admin@123)
INSERT INTO `admins` (`username`, `password`, `email`) VALUES
('admin', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/X4rLTDBgbLHPcYjGi', 'admin@example.com');

-- تنظیمات پیش‌فرض
INSERT INTO `settings` (`key_name`, `value`) VALUES
('max_login_attempts', '5'),
('lockout_duration', '900'),
('session_lifetime', '3600'),
('max_message_length', '5000'),
('max_file_size', '10485760'),
('allowed_file_types', 'jpg,jpeg,png,gif,webp,pdf,doc,docx,mp3,wav,ogg');

-- ===================================================
-- ایجاد Event برای پاکسازی خودکار
-- ===================================================
DELIMITER //

CREATE EVENT IF NOT EXISTS `cleanup_expired_tokens`
ON SCHEDULE EVERY 1 HOUR
DO
BEGIN
    DELETE FROM `csrf_tokens` WHERE `expires_at` < NOW();
END //

CREATE EVENT IF NOT EXISTS `cleanup_old_logs`
ON SCHEDULE EVERY 1 DAY
DO
BEGIN
    DELETE FROM `security_logs` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 90 DAY);
END //

DELIMITER ;

-- فعال‌سازی Event Scheduler
SET GLOBAL event_scheduler = ON;
