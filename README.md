# چت‌روم پیشرفته - نسخه 2.2

## نیازمندی‌ها
- PHP 7.4 یا بالاتر با افزونه‌های `pdo_mysql`، `mbstring` و `fileinfo`
- MySQL 5.7 یا بالاتر
- Nginx یا Apache 2.4

## نصب و راه‌اندازی

### 1. قرار دادن فایل‌ها
تمام فایل‌های پروژه را در پوشه وب سرور قرار دهید.

### 2. ساخت دیتابیس و کاربر اختصاصی
```bash
mysql -u root -p < database.sql
```
برنامه نباید با کاربر root به دیتابیس وصل شود:
```sql
CREATE USER 'chatroom'@'localhost' IDENTIFIED BY 'A_STRONG_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON chatroom_secure.* TO 'chatroom'@'localhost';
```

### 3. تنظیمات
```bash
cp config.local.example.php config.local.php
```
رمز دیتابیس را در `config.local.php` بنویسید. این فایل نباید وارد مخزن git شود.

### 4. ساخت ادمین
ادمین پیش‌فرض وجود ندارد. همین دستور برای تغییر رمز ادمین هم استفاده می‌شود:
```bash
php scripts/admin-password.php YOUR_ADMIN_USERNAME
```

### 5. مجوز پوشه‌ها
```bash
mkdir -p uploads logs
chown -R www-data:www-data uploads logs
chmod 750 uploads logs
```

### 6. وب‌سرور
- **Nginx:** از `deploy/nginx-chatroom.conf.example` استفاده کنید. قوانین `.htaccess` روی Nginx اثر ندارند.
- **Apache:** فایل‌های `.htaccess` پروژه باید با `AllowOverride All` فعال باشند. دقت کنید این فایل‌ها هنگام انتقال جا نمانند (نام آن‌ها با نقطه شروع می‌شود).
- **HTTPS:** بدون HTTPS رمز عبور و پیام‌ها روی شبکه قابل شنود هستند.

### 7. فونت‌ها
فونت Vazirmatn داخل پروژه است و از `assets/fonts/` لود می‌شود. برای نصب مرحله دانلودی وجود ندارد.

## عملیات روزمره

### مایگریشن
روی نصب قبلی، پس از گرفتن پشتیبان:
```bash
CHATROOM_DB_USER=root CHATROOM_DB_PASS=... php scripts/migrate.php
```

### پشتیبان و بازیابی
```bash
php scripts/backup.php /var/backups/chatroom --keep=14
CHATROOM_DB_USER=root CHATROOM_DB_PASS=... php scripts/restore.php ARCHIVE.tar.gz --yes
```
به `mysqldump`، `mysql` و `tar` نیاز دارد. متن پیام‌ها داخل فایل پشتیبان رمزنگاری نشده است.

### سلامت و لاگ
- `health.php` برای مانیتورینگ است و وضعیت دیتابیس و پوشه آپلود را می‌سنجد.
- نمونه چرخش لاگ: `deploy/logrotate-chatroom.conf.example`

## تست و CI
تست‌ها روی MySQL یا MariaDB واقعی اجرا می‌شوند و یک دیتابیس موقت با پیشوند `chatroom_test_` می‌سازند:
```bash
CHATROOM_TEST_DB_HOST=127.0.0.1 CHATROOM_TEST_DB_USER=root CHATROOM_TEST_DB_PASS=... php tests/run.php
```
GitHub Actions همین تست‌ها و ارتقا از schema اولیه را روی PHP 7.4 و 8.1 و 8.3 با MySQL 5.7 و 8.0 و MariaDB 10.11 اجرا می‌کند.
پس از قبولی روی `main` یا تگ، بسته انتشار (`tar.gz` و `sha256`) ساخته می‌شود؛ برای تگ `v*` به صورت GitHub Release هم منتشر می‌شود. استقرار روی سرور خودکار نیست.

---

## ساختار پوشه‌ها
```
chatroom/
├── index.php              # صفحه اصلی
├── login.php              # ورود کاربر
├── logout.php             # خروج کاربر
├── chat.php               # صفحه چت
├── config.php             # تنظیمات
├── config.local.example.php # نمونه تنظیمات محلی
├── db.php                 # اتصال دیتابیس
├── functions.php          # توابع کمکی
├── database.sql           # ساختار دیتابیس
│
├── get_messages.php       # API دریافت پیام
├── send_message.php       # API ارسال پیام
├── update_status.php      # API وضعیت کاربر
├── edit_message.php       # API ویرایش پیام
├── delete_message.php     # API حذف پیام
├── upload_file.php        # API آپلود فایل
├── download.php           # دانلود کنترل‌شده فایل
├── health.php             # بررسی سلامت
├── change-password.php    # تغییر رمز کاربر
│
├── admin-login.php        # ورود ادمین
├── admin-logout.php       # خروج ادمین
├── admin.php              # داشبورد ادمین
├── admin-create-user.php  # ایجاد کاربر
├── admin-create-room.php  # ایجاد روم
├── admin-assign-user.php  # تخصیص کاربر به روم
├── admin-users.php        # فهرست و جست‌وجوی کاربران
├── admin-user-edit.php    # ویرایش کاربر و تعیین رمز
├── admin-rooms.php        # فهرست روم‌ها
├── admin-room-edit.php    # ویرایش روم
├── admin-room-members.php # نقش، سکوت و حذف عضو
├── admin-security-logs.php # لاگ امنیتی
├── admin-change-password.php # تغییر رمز ادمین
│
├── includes/
│   └── init.php           # فایل راه‌اندازی
│
├── scripts/
│   ├── admin-password.php # ساخت ادمین و تغییر رمز
│   ├── migrate.php        # اجرای مایگریشن‌ها
│   ├── backup.php         # پشتیبان دیتابیس و فایل‌ها
│   └── restore.php        # بازیابی پشتیبان
├── migrations/            # ارتقای دیتابیس
├── deploy/                # نمونه پیکربندی Nginx و logrotate
├── tests/                 # تست خودکار
│
├── assets/
│   ├── css/
│   │   ├── fonts.css      # تعریف فونت‌ها
│   │   └── admin.css      # ظاهر پنل مدیریت
│   ├── js/
│   │   └── chat-app.js    # منطق صفحه چت
│   └── fonts/
│       └── (فونت‌های Vazirmatn)
│
├── uploads/               # فایل‌های آپلود شده (بدون دسترسی مستقیم از وب)
└── logs/                  # فایل‌های لاگ
```

---

## ویژگی‌های امنیتی
- رمزنگاری BCrypt برای پسوردها
- محافظت CSRF در فرم‌ها و APIها
- محدودیت تلاش ورود سمت سرور (IP، نام کاربری و ترکیب هر دو)
- پیام خطای یکسان برای کاربر ناموجود، غیرفعال و رمز اشتباه
- نمایش نام روم‌ها فقط پس از ورود
- Session Fingerprinting و انقضای بیکاری
- بسته شدن نشست‌های قبلی پس از تغییر رمز
- Prepared Statements (PDO)
- CSP سخت‌گیرانه که هر اتصال خارجی مرورگر را مسدود می‌کند
- ذخیره فایل با پسوند فهرست سفید و دانلود فقط برای اعضای همان روم

### محدودیت‌های فعلی
پیام‌ها روی سرور رمزنگاری نمی‌شوند؛ هر کسی که به سرور یا دیتابیس دسترسی داشته باشد متن پیام‌ها را می‌بیند.

---

## سازگاری
- PHP 7.4+
- MySQL 5.7+
- بدون نیاز به لینک‌های خارجی (کاملاً لوکال)

---

## مجوز
این پروژه برای استفاده آزاد است.
