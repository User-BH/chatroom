-- ===================================================
-- ارتقای دیتابیس موجود به نسخه 2.1
-- با scripts/migrate.php اجرا می‌شود؛ نصب تازه با database.sql همه این‌ها را دارد.
-- قبل از اجرا پشتیبان بگیرید.
-- ===================================================

-- ادمین پیش‌فرض قدیمی؛ پس از این مرحله با scripts/admin-password.php ادمین بسازید
DELETE FROM `admins`
WHERE `password` = '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/X4rLTDBgbLHPcYjGi';

-- پاکسازی حالا داخل برنامه انجام می‌شود و به Event Scheduler وابسته نیست
DROP EVENT IF EXISTS `cleanup_expired_tokens`;
DROP EVENT IF EXISTS `cleanup_old_logs`;

-- توکن‌های CSRF در session نگهداری می‌شوند و این جدول استفاده نمی‌شد
DROP TABLE IF EXISTS `csrf_tokens`;

-- ایندکس‌های محدودیت ورود و دریافت پیام‌های جدید
ALTER TABLE `login_attempts` ADD INDEX `idx_username_attempt` (`username`, `attempted_at`);
ALTER TABLE `messages` ADD INDEX `idx_room_message` (`room_id`, `id`);
