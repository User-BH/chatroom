-- ===================================================
-- فید رویداد، گفتگوی خصوصی، رسید، واکنش، سنجاق، منشن، دعوت و نگهداری پیام
-- ===================================================

ALTER TABLE `rooms`
    ADD COLUMN `type` ENUM('group', 'direct') NOT NULL DEFAULT 'group',
    ADD COLUMN `direct_key` VARCHAR(32) DEFAULT NULL,
    ADD COLUMN `retention_days` INT UNSIGNED DEFAULT NULL,
    ADD UNIQUE KEY `uq_direct_key` (`direct_key`);

ALTER TABLE `users_rooms`
    ADD COLUMN `last_read_message_id` INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN `last_delivered_message_id` INT UNSIGNED NOT NULL DEFAULT 0;

-- پیام‌های قدیمی نصب‌های قبلی خوانده‌نشده حساب نمی‌شوند
UPDATE `users_rooms` ur
SET ur.`last_read_message_id` = (SELECT COALESCE(MAX(m.`id`), 0) FROM `messages` m WHERE m.`room_id` = ur.`room_id`),
    ur.`last_delivered_message_id` = (SELECT COALESCE(MAX(m.`id`), 0) FROM `messages` m WHERE m.`room_id` = ur.`room_id`);

ALTER TABLE `messages`
    ADD COLUMN `client_token` CHAR(36) DEFAULT NULL,
    ADD COLUMN `pinned_at` TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN `pinned_by` INT UNSIGNED DEFAULT NULL,
    ADD UNIQUE KEY `uq_user_client_token` (`user_id`, `client_token`),
    ADD INDEX `idx_room_pinned` (`room_id`, `pinned_at`);

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

-- وضعیت خوانده شدن با نشانگر last_read_message_id نگهداری می‌شود
DROP TABLE IF EXISTS `message_reads`;
