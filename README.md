# newadapharma

مخزن کد و مستندات پروژه **ADA PHARMA ERP + TradePilot AI** (PHP خام / MVC اختصاصی)
که روی هاست CloudLinux در آدرس `erp.adapharmaco.com` اجرا می‌شود.

## ساختار مخزن
```
docs/       مستندات — دانش‌فنی (Knowledge Transfer) و نقشه راه
deploy/     اسکریپت‌های مستقل PHP برای استقرار از طریق checkmodel.php
erp/        نسخه‌بندی کد واقعی ERP (بعد از آپلود اولین اسنپ‌شات پر می‌شود)
```

## وضعیت فعلی (شهریور ۱۴۰۵ / V4)
- Smoke Test: 10/10 سبز
- فازهای ۰، ۱، ۳، ۵، ۶ کامل · فاز ۴ نیمه‌تمام (فقط A/R Aging) · **فاز ۲ (Batch Tracker) در دست اقدام**

مستند کامل: [`docs/KT_V4_1405-06.md`](docs/KT_V4_1405-06.md)
اسکریپت شروع فاز ۲: [`deploy/recon_inventory.php`](deploy/recon_inventory.php)

## 🔒 امنیت
این مخزن شامل اطلاعات حساس (توکن استقرار، رمز اتصال دیتابیس) است و باید
**PRIVATE** بماند. بعد از هر session، `checkmodel.php` از سرور حذف یا توکن آن
تعویض شود.

## جریان کار session جاری
1. اجرای اسکریپت Recon روی سرور و برگرداندن خروجی‌ها (شناسایی وضعیت جداول انبار).
2. آپلود اسنپ‌شات کد ERP (zip پوشه app + config + رانرها) برای نسخه‌بندی.
3. پیاده‌سازی فاز ۲ (Pharma Batch Tracker) روی کد واقعی + ساخت اسکریپت استقرار.
