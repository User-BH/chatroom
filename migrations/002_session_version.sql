-- ===================================================
-- نسخه نشست: با تغییر رمز، نشست‌های قبلی حساب باطل می‌شوند
-- ===================================================

ALTER TABLE `users` ADD COLUMN `session_version` INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE `admins` ADD COLUMN `session_version` INT UNSIGNED NOT NULL DEFAULT 0;
