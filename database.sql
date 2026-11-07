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
    `session_version` INT UNSIGNED NOT NULL DEFAULT 0,
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
    `session_version` INT UNSIGNED NOT NULL DEFAULT 0,
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
    `type` ENUM('group', 'direct') NOT NULL DEFAULT 'group',
    `direct_key` VARCHAR(32) DEFAULT NULL,
    `retention_days` INT UNSIGNED DEFAULT NULL,
    UNIQUE KEY `uq_direct_key` (`direct_key`),
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
    `last_read_message_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_delivered_message_id` INT UNSIGNED NOT NULL DEFAULT 0,
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
    `client_token` CHAR(36) DEFAULT NULL,
    `pinned_at` TIMESTAMP NULL DEFAULT NULL,
    `pinned_by` INT UNSIGNED DEFAULT NULL,
    UNIQUE KEY `uq_user_client_token` (`user_id`, `client_token`),
    INDEX `idx_room_pinned` (`room_id`, `pinned_at`),
    INDEX `idx_room_id` (`room_id`),
    INDEX `idx_room_message` (`room_id`, `id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_reply_to` (`reply_to`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_message_type` (`message_type`),
    FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`reply_to`) REFERENCES `messages`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================
-- فید رویداد، واکنش، منشن و دعوت
-- ===================================================
CREATE TABLE `room_events` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `room_id` INT UNSIGNED NOT NULL,
    `type` ENUM('message_new', 'message_edit', 'message_delete', 'reaction', 'pin', 'receipt') NOT NULL,
    `message_id` INT UNSIGNED DEFAULT NULL,
    `actor_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_room_event` (`room_id`, `id`),
    INDEX `idx_created_at` (`created_at`),
    FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `message_reactions` (
    `message_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `emoji` VARCHAR(16) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`message_id`, `user_id`, `emoji`),
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE `message_mentions` (
    `message_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`message_id`, `user_id`),
    INDEX `idx_user_message` (`user_id`, `message_id`),
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `room_invites` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `room_id` INT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) NOT NULL UNIQUE,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `max_uses` INT UNSIGNED DEFAULT NULL,
    `uses` INT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` TIMESTAMP NULL DEFAULT NULL,
    `revoked_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_room_id` (`room_id`),
    FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
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
    INDEX `idx_attempted_at` (`attempted_at`),
    INDEX `idx_username_attempt` (`username`, `attempted_at`)
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

-- ===================================================
-- مایگریشن‌هایی که این schema از قبل شامل آن‌هاست
-- ===================================================
CREATE TABLE `schema_migrations` (
    `name` VARCHAR(190) NOT NULL PRIMARY KEY,
    `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`name`) VALUES
('001_security_hardening.sql'),
('002_session_version.sql'),
('003_chat_features.sql');

-- ادمین پیش‌فرض عمداً وجود ندارد. پس از نصب اجرا کنید:
--   php scripts/admin-password.php YOUR_ADMIN_USERNAME

-- تنظیمات پیش‌فرض
INSERT INTO `settings` (`key_name`, `value`) VALUES
('max_login_attempts', '5'),
('lockout_duration', '900'),
('session_lifetime', '3600'),
('max_message_length', '5000'),
('max_file_size', '10485760'),
('allowed_file_types', 'jpg,jpeg,png,gif,webp,pdf,doc,docx,mp3,wav,ogg');
