# deploy/ — اسکریپت‌های استقرار روی سرور

این پوشه اسکریپت‌های مستقل PHP را نگه می‌دارد که مطابق روال همیشگی روی سرور
CloudLinux از طریق `checkmodel.php` اجرا می‌شوند.

## ⚠️ قواعد امنیتی
- این ریپو باید **PRIVATE** باشد (اسکریپت‌ها حاوی توکن و رمز دیتابیس هستند).
- بعد از پایان هر session فایل `checkmodel.php` را از سرور **حذف** کنید
  (یا توکن `MyStrongPass_2026_xyz` را عوض کنید).
- بین هر دو اجرا **۵-۱۰ ثانیه** صبر کنید (BitNinja WAF به رفرش مکرر حساس است).
- هرگز `exec()` یا `shell_exec()` در اسکریپت‌ها ننویسید — غیرفعال‌اند.

## 📋 روال اجرا
1. محتوای اسکریپت را در cPanel → File Manager در
   `/home/adapharm/public_html/checkmodel.php` کپی کنید.
2. در مرورگر: `https://adapharmaco.com/checkmodel.php?token=MyStrongPass_2026_xyz&a=ACTION`
3. خروجی متنی را کپی کرده و در چت Arena پیست کنید.
4. اسکریپت بعدی را جایگزین کنید.

## 🔍 recon_inventory.php — شناسایی فاز ۲ (Pharma Batch Tracker)

| اکشن | URL |
|---|---|
| محیط PHP + مسیر ERP | `&a=env` |
| لیست جدول‌ها | `&a=tables` |
| ساختار جداول انبار | `&a=schema&like=inventory%` |
| ساختار انبارها/هشدارها | `&a=schema&like=wareh%` · `&a=schema&like=batch_%` |
| ساختار فاکتور/پکینگ/پرفورما | `&a=schema&like=invoice_%` · `&a=schema&like=packing_%` · `&a=schema&like=proforma_%` |
| ستون‌های یک جدول | `&a=cols&t=inventory_batches` |
| نمونه داده | `&a=data&t=inventory_batches&n=10` |
| تاریخچه مهاجرت‌ها | `&a=migrations` |
| لیست فایل‌های کد | `&a=files` |
| خواندن فایل | `&a=read&f=app/models/InvoiceModel.php` |
| چک سینتکس | `&a=lint&f=followup_reminder_runner.php` |
| جستجوی متنی | `&a=grep&q=inventory` |

> خروجی‌ها کوتاه و متن‌محورند؛ اگر خطای `403` گرفتید یعنی توکن درست نیست،
> اگر صفحه خالی/سفید بود ۱۰ ثانیه صبر و یک‌بار دیگر تلاش کنید.

## 📦 آپلود اسنپ‌شات کد ERP (برای نسخه‌بندی Git)
از cPanel → File Manager:
1. پوشه `/home/adapharm/erp/app` را zip کنید (راست‌کلیک → Compress → `.zip`).
2. همین کار را برای `/home/adapharm/erp/config` و فایل‌های `*_runner.php` و
   `*_cron*.php` در ریشه `/home/adapharm/erp` انجام دهید.
3. فایل(های) zip را در همین چت آپلود کنید تا در ریپو استخراج و نسخه‌بندی شوند
   (پوشه `erp-snapshot/` در .gitignore است و وارد Git نمی‌شود، ولی کد واقعی
   داخل `erp/` منتقل و کامیت می‌شود).
