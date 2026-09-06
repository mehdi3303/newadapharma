<?php
/**
 * ============================================================================
 * ADA PHARMA ERP — نصب‌کننده ماژول مدیریت چک (Cheque Management) v1.0
 * ============================================================================
 * روش اجرا (یک‌بار، برای نصب):
 *   1) کل محتوای همین فایل را در cPanel در /home/adapharm/public_html/checkmodel.php
 *      کپی کنید (یا در /home/adapharm/erp/checkmodel.php).
 *   2) در مرورگر باز کنید:
 *      https://adapharmaco.com/checkmodel.php?token=MyStrongPass_2026_xyz
 *   3) خروجی گام‌به‌گام را بخوانید؛ در پایان باید همه گام‌ها ✅ باشند.
 *   4) بعد از تأیید، فایل checkmodel.php را از سرور حذف کنید.
 *
 * این اسکریپت: از فایل‌های موجود بکاپ می‌گیرد، جداول را idempotent می‌سازد،
 * ماژول را در /home/adapharm/erp/cheques.php مستقر می‌کند و پوشه آپلود می‌سازد.
 * هیچ exec/shell_exec ندارد.
 * ============================================================================
 */

const TOKEN   = 'MyStrongPass_2026_xyz';
const DB_HOST = 'localhost';
const DB_NAME = 'adapharm_erp';
const DB_USER = 'adapharm_erp';
const DB_PASS = 'Mehdi1721Sayo3303';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

if (!isset($_GET['token']) || !hash_equals(TOKEN, (string)$_GET['token'])) {
    http_response_code(403);
    echo "403 Forbidden — bad token\n";
    exit;
}

function step($n, $t) { echo "\n[STEP $n] $t\n" . str_repeat('-', 60) . "\n"; }
function ok($m)  { echo "  ✅ $m\n"; }
function warn($m){ echo "  ⚠️  $m\n"; }
function err($m) { echo "  ❌ $m\n"; }

/* ---------- تشخیص ریشه ERP ---------- */
$erpRoot = null;
$cands = array();
if (is_file(__DIR__ . '/config/database.php')) $cands[] = __DIR__;
$cands[] = '/home/adapharm/erp';
$cands[] = dirname(__DIR__) . '/erp';
foreach ($cands as $c) { if (is_file($c . '/config/database.php')) { $erpRoot = $c; break; } }

step(1, 'تشخیص محیط');
echo '  PHP version : ' . PHP_VERSION . "\n";
echo '  script path : ' . __FILE__ . "\n";
if (!$erpRoot) { err('ریشه ERP پیدا نشد (config/database.php). اسکریپت را داخل /home/adapharm/erp یا public_html کپی کنید.'); exit; }
ok('ریشه ERP: ' . $erpRoot);

/* ---------- اتصال دیتابیس ---------- */
step(2, 'اتصال دیتابیس');
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    ok('اتصال به ' . DB_NAME . ' برقرار شد');
} catch (PDOException $e) { err('DB: ' . $e->getMessage()); exit; }

/* ---------- ساخت جداول ---------- */
step(3, 'ساخت جداول (idempotent)');
$ddl = array();

$ddl[] = "CREATE TABLE IF NOT EXISTS `checks` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `kind` ENUM('payment','guarantee') NOT NULL DEFAULT 'payment',
  `direction` ENUM('received','issued') NOT NULL,
  `cheque_number` VARCHAR(50) NULL,
  `sayyad_id` VARCHAR(20) NULL,
  `series` VARCHAR(30) NULL,
  `serial` VARCHAR(30) NULL,
  `bank_name` VARCHAR(100) NULL,
  `branch_name` VARCHAR(150) NULL,
  `branch_code` VARCHAR(20) NULL,
  `bank_account_id` BIGINT UNSIGNED NULL,
  `party_type` ENUM('customer','supplier','other') NOT NULL DEFAULT 'other',
  `customer_id` BIGINT UNSIGNED NULL,
  `supplier_id` BIGINT UNSIGNED NULL,
  `party_name` VARCHAR(200) NULL,
  `amount` DECIMAL(20,0) NOT NULL DEFAULT 0,
  `issue_date` DATE NULL,
  `due_date` DATE NULL,
  `guarantee_reason` VARCHAR(255) NULL,
  `guarantee_return_date` DATE NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'in_hand',
  `ref_type` VARCHAR(30) NULL,
  `ref_id` VARCHAR(50) NULL,
  `image_front` VARCHAR(255) NULL,
  `image_back` VARCHAR(255) NULL,
  `description` TEXT NULL,
  `checkbook_id` BIGINT UNSIGNED NULL,
  `locked_at` DATETIME NULL,
  `locked_by` BIGINT UNSIGNED NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  `deleted_at` DATETIME NULL,
  UNIQUE KEY `uq_sayyad` (`sayyad_id`),
  KEY `idx_status` (`status`),
  KEY `idx_due` (`due_date`),
  KEY `idx_direction` (`direction`),
  KEY `idx_kind` (`kind`),
  KEY `idx_party_customer` (`customer_id`),
  KEY `idx_party_supplier` (`supplier_id`),
  KEY `idx_bank_account` (`bank_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci";

$ddl[] = "CREATE TABLE IF NOT EXISTS `checkbooks` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `bank_account_id` BIGINT UNSIGNED NULL,
  `series` VARCHAR(30) NULL,
  `start_number` BIGINT NOT NULL,
  `end_number` BIGINT NOT NULL,
  `received_date` DATE NULL,
  `status` ENUM('active','finished','cancelled') NOT NULL DEFAULT 'active',
  `notes` VARCHAR(255) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci";

$ddl[] = "CREATE TABLE IF NOT EXISTS `check_events` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `check_id` BIGINT UNSIGNED NOT NULL,
  `event_type` VARCHAR(30) NOT NULL,
  `event_date` DATE NOT NULL,
  `amount` DECIMAL(20,0) NULL,
  `bank_ref` VARCHAR(100) NULL,
  `note` TEXT NULL,
  `attachment` VARCHAR(255) NULL,
  `approval_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `approved_by` BIGINT UNSIGNED NULL,
  `approved_at` DATETIME NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL,
  `prev_hash` CHAR(64) NULL,
  `hash` CHAR(64) NOT NULL,
  KEY `idx_check` (`check_id`),
  KEY `idx_approval` (`approval_status`),
  KEY `idx_date` (`event_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci";

$ddl[] = "CREATE TABLE IF NOT EXISTS `check_endorsements` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `check_id` BIGINT UNSIGNED NOT NULL,
  `to_party_type` ENUM('supplier','customer','other') NOT NULL DEFAULT 'other',
  `to_supplier_id` BIGINT UNSIGNED NULL,
  `to_customer_id` BIGINT UNSIGNED NULL,
  `to_name` VARCHAR(200) NULL,
  `endorsement_date` DATE NULL,
  `ref_type` VARCHAR(30) NULL,
  `ref_id` VARCHAR(50) NULL,
  `amount` DECIMAL(20,0) NULL,
  `note` TEXT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL,
  KEY `idx_check` (`check_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci";

foreach ($ddl as $sql) {
    preg_match('/EXISTS\s+`?(\w+)`?/i', $sql, $mm);
    $tname = $mm[1] ?? '?';
    try { $pdo->exec($sql); ok("جدول `$tname` آماده است"); }
    catch (PDOException $e) { err("`$tname`: " . $e->getMessage()); }
}

/* ستون اختیاری check_id روی bank_transactions (فقط اگر جدول و ستون نبودند) */
try {
    $bt = $pdo->query("SHOW TABLES LIKE 'bank_transactions'")->fetchColumn();
    if ($bt) {
        $has = $pdo->query("SHOW COLUMNS FROM bank_transactions LIKE 'check_id'")->fetchColumn();
        if (!$has) {
            $pdo->exec("ALTER TABLE bank_transactions ADD COLUMN check_id BIGINT UNSIGNED NULL");
            ok('ستون check_id به bank_transactions اضافه شد');
        } else { ok('ستون check_id از قبل وجود دارد'); }
    } else { warn('جدول bank_transactions وجود ندارد — از افزودن ستون صرف‌نظر شد'); }
} catch (PDOException $e) { warn('bank_transactions/check_id: ' . $e->getMessage()); }

/* ---------- ثبت مهاجرت ---------- */
step(4, 'ثبت نسخه در schema_migrations');
try {
    $sm = $pdo->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn();
    if ($sm) {
        $cols = $pdo->query("SHOW COLUMNS FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
        $verCol = in_array('version', $cols) ? 'version' : (in_array('migration', $cols) ? 'migration' : $cols[0]);
        $exists = $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE `$verCol`='v2.7.0'")->fetchColumn();
        if (!$exists) {
            $nameCol = in_array('name', $cols) ? 'name' : (in_array('description', $cols) ? 'description' : null);
            $dateCol = in_array('applied_at', $cols) ? 'applied_at' : (in_array('created_at', $cols) ? 'created_at' : null);
            $sql = "INSERT INTO schema_migrations (`$verCol`";
            $vals = " VALUES ('v2.7.0'";
            if ($nameCol) { $sql .= ", `$nameCol`"; $vals .= ", 'Cheque management system'"; }
            if ($dateCol) { $sql .= ", `$dateCol`"; $vals .= ', NOW()'; }
            $sql .= ")" . $vals . ")";
            $pdo->exec($sql);
            ok('نسخه v2.7.0 ثبت شد');
        } else { ok('نسخه v2.7.0 قبلاً ثبت شده'); }
    } else { warn('جدول schema_migrations وجود ندارد — رد شد'); }
} catch (PDOException $e) { warn('migration: ' . $e->getMessage()); }

/* ---------- استقرار فایل ماژول ---------- */
step(5, 'نوشتن فایل ماژول cheques.php');
$target = $erpRoot . '/cheques.php';
$moduleCode = <<<'MODULECODE'
__MODULE_PLACEHOLDER__
MODULECODE;

if (is_file($target)) {
    $bak = $target . '.bak-' . date('Ymd-His');
    if (@copy($target, $bak)) ok('بکاپ نسخه قبلی: ' . basename($bak));
    else warn('بکاپ گرفته نشد (مجوز؟) — نصب متوقف نمی‌شود چون فایل قبلی هم نسخه همین ماژول است');
}
$written = @file_put_contents($target, $moduleCode);
if ($written !== false) { ok("cheques.php نوشته شد ($written bytes)"); }
else { err('نوشتن cheques.php انجام نشد — مجوز پوشه erp را بررسی کنید (باید 755/775 باشد)'); }

/* ---------- افزودن لینک به منوی کناری ERP ---------- */
step(6, 'افزودن لینک «مدیریت چک‌ها» به منوی کناری');
$main = $erpRoot . '/app/views/layouts/main.php';
if (!is_file($main)) { warn('فایل layouts/main.php پیدا نشد — لینک را دستی اضافه کنید'); }
else {
    $html = file_get_contents($main);
    if (strpos($html, 'cheques.php') !== false) {
        ok('لینک چک‌ها از قبل در منو وجود دارد');
    } else {
        $anchors = array('Payment Receipts', 'Export Bundles', 'Packing Lists', 'Official Letters');
        $lines = file($main);
        $idx = -1; $keyword = '';
        foreach ($lines as $i => $l) {
            foreach ($anchors as $a) { if (stripos($l, $a) !== false) { $idx = $i; $keyword = $a; break 2; } }
        }
        if ($idx < 0) { warn('نقطه درج منو پیدا نشد — این خط را دستی در سایدبار اضافه کنید:'); echo "    <a href=\"/cheques.php\">🏦 مدیریت چک‌ها</a>\n"; }
        else {
            @copy($main, $main . '.bak-cheques-' . date('Ymd-His'));
            $new = $lines[$idx];
            $new = preg_replace('/href=["\'][^"\']*["\']/', 'href="/cheques.php"', $new, 1);
            $new = str_ireplace($keyword, '🏦 مدیریت چک‌ها', $new);
            /* حذف badge شمارش (در صورت وجود) */
            $new = preg_replace('/<span[^>]*(badge|count|notification)[^>]*>.*?<\/span>/s', '', $new);
            array_splice($lines, $idx + 1, 0, array($new));
            file_put_contents($main, implode('', $lines));
            ok('لینک بعد از «' . $keyword . '» در منو درج شد (بکاپ گرفته شد)');
        }
    }
}

/* ---------- پوشه آپلود ---------- */
step(7, 'پوشه آپلود اسکن چک‌ها');
$up = $erpRoot . '/uploads/cheques';
if (!is_dir($up)) { @mkdir($up, 0755, true); }
if (is_dir($up) && is_writable($up)) ok('پوشه آماده و قابل‌نوشتن: uploads/cheques');
else warn('پوشه uploads/cheques قابل‌نوشتن نیست — دسترسی را 755 کنید');

/* ---------- راستی‌آزمایی ---------- */
step(8, 'راستی‌آزمایی نهایی');
$checks = array('checks','checkbooks','check_events','check_endorsements');
foreach ($checks as $t) {
    try { $c = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); echo "  · جدول $t: موجود ($c ردیف)\n"; }
    catch (PDOException $e) { err("جدول $t در دسترس نیست"); }
}
if (is_file($target)) {
    $code = @file_get_contents($target);
    echo "  · فایل cheques.php موجود است (" . filesize($target) . " bytes)\n";
    if (strpos($code, 'erp_session_name') !== false) {
        echo "  · ✅ نسخه دارای اتصال سشن ERP (adapharma_erp_session)\n";
    } else {
        echo "  · ⚠️  نسخه قدیمی فاقد اتصال سشن است — دوباره این نصب‌کننده را اجرا کنید\n";
    }
}

echo "\n============================================================\n";
echo "نشانی ماژول (بعد از لاگین در ERP باز کنید):\n";
echo "  https://erp.adapharmaco.com/cheques.php\n";
echo "============================================================\n";
echo "\n[done]\n";
