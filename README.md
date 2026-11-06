# چت‌روم پیشرفته - نسخه 2.0

## نیازمندی‌ها
- PHP 7.4 یا بالاتر
- MySQL 5.7 یا بالاتر
- وب سرور (Apache/Nginx)

## نصب و راه‌اندازی

### 1. آپلود فایل‌ها
تمام فایل‌های پروژه را در پوشه وب سرور خود آپلود کنید.

### 2. پیکربندی دیتابیس
فایل `config.php` را ویرایش کرده و اطلاعات دیتابیس خود را وارد کنید:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'chatroom_secure');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
```

### 3. ایجاد دیتابیس
فایل `database.sql` را در MySQL اجرا کنید:
```bash
mysql -u root -p < database.sql
```

### 4. تنظیم مجوزها
```bash
chmod 755 uploads/
chmod 755 logs/
mkdir -p uploads logs
```

### 5. دانلود فونت‌ها
فونت **Vazirmatn** را دانلود کرده و در پوشه `assets/fonts/` قرار دهید.

---

## 📦 لیست فونت‌های مورد نیاز

### فونت Vazirmatn
**آدرس دانلود:** https://github.com/rastikerdar/vazirmatn/releases

**فایل‌های مورد نیاز:**
```
assets/fonts/
├── Vazirmatn-Light.woff2
├── Vazirmatn-Light.woff
├── Vazirmatn-Regular.woff2
├── Vazirmatn-Regular.woff
├── Vazirmatn-Medium.woff2
├── Vazirmatn-Medium.woff
├── Vazirmatn-SemiBold.woff2
├── Vazirmatn-SemiBold.woff
├── Vazirmatn-Bold.woff2
└── Vazirmatn-Bold.woff
```

**مراحل دانلود:**
1. به https://github.com/rastikerdar/vazirmatn/releases بروید
2. آخرین نسخه را دانلود کنید (فایل zip)
3. فایل zip را باز کنید
4. به پوشه `fonts/webfonts/` بروید
5. فایل‌های `.woff2` و `.woff` را کپی کنید
6. در پوشه `assets/fonts/` پروژه قرار دهید

---

## ساختار پوشه‌ها
```
chatroom/
├── index.php              # صفحه اصلی
├── login.php              # ورود کاربر
├── logout.php             # خروج کاربر
├── chat.php               # صفحه چت
├── config.php             # تنظیمات
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
│
├── admin-login.php        # ورود ادمین
├── admin-logout.php       # خروج ادمین
├── admin.php              # داشبورد ادمین
├── admin-create-user.php  # ایجاد کاربر
├── admin-create-room.php  # ایجاد روم
├── admin-assign-user.php  # تخصیص کاربر به روم
│
├── includes/
│   └── init.php           # فایل راه‌اندازی
│
├── assets/
│   ├── css/
│   │   └── fonts.css      # تعریف فونت‌ها
│   └── fonts/
│       └── (فونت‌های Vazirmatn)
│
├── uploads/               # فایل‌های آپلود شده
└── logs/                  # فایل‌های لاگ
```

---

## اطلاعات ورود پیش‌فرض

### پنل ادمین:
- **نام کاربری:** admin
- **رمز عبور:** Admin@123

⚠️ **مهم:** بلافاصله پس از نصب، رمز عبور ادمین را تغییر دهید!

---

## ویژگی‌های امنیتی
- ✅ رمزنگاری BCrypt برای پسوردها
- ✅ محافظت CSRF در تمام فرم‌ها
- ✅ Rate Limiting برای جلوگیری از حملات
- ✅ قفل شدن حساب پس از تلاش‌های ناموفق
- ✅ Session Fingerprinting
- ✅ Prepared Statements (PDO)
- ✅ XSS Prevention
- ✅ هدرهای امنیتی HTTP

---

## سازگاری
- PHP 7.4+
- MySQL 5.7+
- بدون نیاز به لینک‌های خارجی (کاملاً لوکال)

---

## مجوز
این پروژه برای استفاده آزاد است.
