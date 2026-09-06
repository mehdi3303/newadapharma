# فاز ۲ — Pharma Batch Tracker — طراحی فنی (پیش‌نویس)
وضعیت: در انتظار نتیجه Recon (`deploy/recon_inventory.php`) — ساختار نهایی بعد از تطبیق با اسکیمای واقعی قفل می‌شود.
مهاجرت هدف: **v2.6.0** در `schema_migrations`.

## ۱. هدف‌ها
1. ردیابی بچ‌های دارویی (lot/batch number، تاریخ تولید/انقضا، GMP/CAS) از لحظه ورود (خرید/واردات) تا تحویل مشتری.
2. اتصال عملیاتی بچ به اقلام فاکتور و پکینگ‌لیست (حلقه گمشده فاز ۲).
3. کسر خودکار موجودی هنگام قطعی‌شدن سند (paid / shipped) با تخصیص **FEFO** (First-Expired-First-Out).
4. لجر کامل حرکات انبار (ورود، خروج، برگشت، تعدیل، انتقال) برای ممیزی.
5. داشبورد هشدار انقضا (۹۰/۱۸۰ روز) + موجودی رو به اتمام.

## ۲. مدل داده (پیشنهادی)
جداول موجود طبق KT: `inventory_batches, inventory_movements, inventory_stock, batch_alerts, warehouses` — ساختار دقیق از recon می‌آید.

**الحاقات پیش‌بینی‌شده:**

```sql
-- جدول ربط چندبچی: یک آیتم فاکتور می‌تواند از چند بچ تأمین شود
CREATE TABLE invoice_item_batches (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  invoice_item_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NOT NULL,
  allocated_qty DECIMAL(14,3) NOT NULL,          -- مقدار تخصیصی از این بچ
  unit VARCHAR(20) NOT NULL DEFAULT 'unit',
  status ENUM('reserved','deducted','released') NOT NULL DEFAULT 'reserved',
  created_at DATETIME NULL, created_by BIGINT UNSIGNED NULL,
  deducted_at DATETIME NULL,
  UNIQUE KEY uq_item_batch (invoice_item_id, batch_id)
);
-- (به‌صورت مشابه packing_list_item_batches در صورت وجود جدول packing_list_items)

-- inventory_movements = لجر ترتیبی (append-only، هیچ‌وقت UPDATE/DELETE)
-- movement_type: 'in_purchase' | 'in_import' | 'out_invoice' | 'out_shipment'
--              | 'return_in' | 'return_out' | 'adjustment' | 'transfer'
-- ستون‌های کلیدی: batch_id, warehouse_id, product_id, qty_delta (+/-), unit,
--                 ref_type ('invoice'|'purchase_order'|'packing_list'|'manual'),
--                 ref_id, note, created_by, created_at

-- inventory_stock = موجودی لحظه‌ای (با هر movement از طریق تراکنش upsert می‌شود)
-- یا به‌جای جدول: VIEW تجمیعی از لجر (v_stock_on_hand) — تصمیم بعد از recon
```

## ۳. جریان‌های کلیدی

### ۳.۱ ورود کالا
مبدأ: تأیید دریافت Purchase Order یا ثبت Import Cost.
- ساخت/به‌روزرسانی `inventory_batches` (lot_no, expiry_date, mfg_date, qty_received).
- ثبت movement نوع `in_purchase` / `in_import` با +qty.
- موجودی انبار مقصد (`warehouses`) افزایش می‌یابد.

### ۳.۲ تخصیص FEFO (هنگام ساخت/ویرایش فاکتور یا پکینگ‌لیست)
`InventoryService::allocateFeFo(product_id, qty_needed, warehouse_id)`:
1. انتخاب بچ‌های همان محصول با `expiry_date >= CURDATE()` و موجودی کافی،
   مرتب بر اساس `expiry_date ASC` (انقضای نزدیک‌تر اول).
2. پر کردن مقدار از بچ‌ها به‌ترتیب تا سقف نیاز.
3. ثبت رکورد در `invoice_item_batches` با وضعیت `reserved`.
4. اگر مجموع موجودی کافی نبود → هشدار در UI (کسر موجودی)، نه خطای سخت (رویه فعلی کسب‌وکار: پیش‌فاکتور قبل از paid قابل ویرایش است).

### ۳.۳ کسر قطعی موجودی (نقطه ماشه)
با توجه به منطق قفل اسناد (پیش‌فاکتور تا قبل از paid قابل ویرایش):
- **رزرو (reserved):** هنگام ساخت فاکتور/پکینگ — موجودی «در دسترس» کم می‌شود ولی «فیزیکی» نه.
- **کسر (deducted):** هنگام `paid` یا علامت‌گذاری `shipped` (هرکدام اول در جریان فعلی شد — بعد از recon کد InvoiceModel/Order Tracking قطعی می‌شود).
- **آزادسازی (released):** اگر فاکتور قبل از paid حذف/ابطال شود → reservation برمی‌گردد.
- همه این‌ها در یک `TRANSACTION` دیتابیسی با قفل‌گذاری ردیف بچ انجام می‌شود تا دو فاکتور همزمان یک بچ را تخصیص ندهند.

### ۳.۴ هشدار انقضا (cron روزانه)
`cron-batch-alert.php` (هر روز ساعت ۷:۳۰ صبح):
- بچ‌هایی که `expiry_date` در بازه ۹۰ یا ۱۸۰ روز آینده است → upsert در `batch_alerts`
  (سطح: `warning` ≤۱۸۰ روز، `critical` ≤۹۰ روز، `expired` گذشته).
- ایمیل خلاصه به مدیر + widget در داشبورد TradePilot/ERP.

## ۴. معماری فایل‌ها
```
app/models/InventoryBatchModel.php        # جدید
app/models/InventoryMovementModel.php     # جدید (لجر)
app/models/WarehouseModel.php             # جدید/تکمیل
app/services/InventoryService.php         # جدید: allocateFeFo / receive / deduct / adjust / stockOnHand
app/controllers/InventoryController.php   # جدید: index، batches، batchCreate، movements، alerts
app/views/inventory/index.php             # موجودی لحظه‌ای + جستجو
app/views/inventory/batches.php           # لیست بچ‌ها با رنگ انقضا
app/views/inventory/movements.php         # لجر
app/views/inventory/alerts.php            # داشبورد هشدارها
cron-batch-alert.php                      # ریشه ERP — جدید
migrations/2026-09-phase2-batch-tracker.sql
```
**هوک‌ها (نقاط دقیق بعد از recon):**
- `InvoiceModel` / سرویس پرداخت: ماشه کسر هنگام paid.
- Order Tracking Auto-Advance: ماشه کسر/آزادسازی هنگام shipped/cancelled.
- فرم فاکتور/پکینگ: انتخاب بچ (خودکار FEFO + دستی) و نمایش موجودی.
- منوی `layouts/main.php`: آیتم «انبار / Inventory».

## ۵. سؤالاتی که Recon باید جواب بدهد
1. ستون‌های واقعی `inventory_batches` (product_id؟ lot_no؟ expiry؟ qty_on_hand؟ warehouse؟)
2. آیا `packing_lists` جدول آیتم جدا دارد یا JSON ذخیره می‌شود؟
3. نقطه دقیق تغییر وضعیت فاکتور به paid/shipped در کد (گrep: `paid`, `shipped`, `status`).
4. یکتایی واحد شمارش محصول (KG / drum / vial) و تبدیل واحد بین بچ و آیتم.
5. آیا `inventory_stock` جدول فیزیکی است یا باید view بسازیم؟
6. الگوی migration موجود (نام‌گذاری فایل‌ها، نحوه ثبت در schema_migrations).

## ۶. تست
- افزودن ۲ تست به Smoke Test (۱۰ → ۱۲):
  - «FEFO allocation منقضی‌نزدیک‌تر را اول برمی‌دارد»
  - «کسر در paid + آزادسازی در cancel، موجودی را درست نگه می‌دارد»
- ثبت نسخه `v2.6.0` در `schema_migrations`.
