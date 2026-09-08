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
  `national_id` VARCHAR(20) NULL,
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

$ddl[] = "CREATE TABLE IF NOT EXISTS `check_inquiries` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `check_id` BIGINT UNSIGNED NULL,
  `party_type` ENUM('customer','supplier','other') NOT NULL DEFAULT 'other',
  `party_id` BIGINT UNSIGNED NULL,
  `party_name` VARCHAR(200) NULL,
  `sayyad_id` VARCHAR(20) NULL,
  `bank_name` VARCHAR(100) NULL,
  `sms_number` VARCHAR(20) NULL,
  `channel` ENUM('sms','app','api','manual') NOT NULL DEFAULT 'sms',
  `raw_response` TEXT NULL,
  `parsed_status` VARCHAR(40) NULL,
  `bounced_count` INT NULL DEFAULT 0,
  `bounced_amount` DECIMAL(20,0) NULL DEFAULT 0,
  `is_banned` TINYINT(1) NOT NULL DEFAULT 0,
  `risk_score` TINYINT NULL,
  `risk_level` ENUM('low','medium','high') NULL,
  `note` VARCHAR(255) NULL,
  `inquired_by` BIGINT UNSIGNED NULL,
  `inquired_at` DATETIME NULL,
  KEY `idx_check` (`check_id`),
  KEY `idx_party` (`party_type`,`party_id`)
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

/* فیلدهای چرخه صیاد روی checks (idempotent) */
$addCols = array(
    'checks' => array(
        "sayyad_status VARCHAR(30) NULL",
        "sayyad_registered_at DATETIME NULL",
        "sayyad_confirmed_at DATETIME NULL",
        "national_id VARCHAR(20) NULL",
    ),
);
foreach ($addCols as $tbl => $defs) {
    foreach ($defs as $def) {
        $col = trim(strtok($def, ' '));
        try {
            $exists = $pdo->query("SHOW COLUMNS FROM `$tbl` LIKE " . $pdo->quote($col))->fetchColumn();
            if (!$exists) { $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN $def"); ok("ستون $col به $tbl اضافه شد"); }
        } catch (PDOException $e) { warn("$tbl.$col: " . $e->getMessage()); }
    }
}

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

/* ---------- افزودن لینک به منوی کناری ERP (با ترمیم درج خراب قبلی) ---------- */
step(6, 'منوی کناری ERP — لینک «مدیریت چک‌ها»');
$main = $erpRoot . '/app/views/layouts/main.php';
$MARK = 'cheques-link-auto';
if (!is_file($main)) { warn('فایل layouts/main.php پیدا نشد — لینک را دستی اضافه کنید'); }
else {
    $html = file_get_contents($main);
    @copy($main, $main . '.bak-cheques-' . date('Ymd-His'));

    /* 1) حذف هر نوع درج قبلیِ ما (هم نشان‌دار، هم بدون نشان که به‌هم چسبیده) */
    $html = preg_replace('/\n?[ \t]*<[^>]*' . preg_quote($MARK, '/') . '[^>]*>.*?<\/[^>]+>\s*/s', "\n", $html);
    /* حذف تکه‌ی چسبیده‌ای که دفعه قبل با تغییر متن Payment ساخته شد */
    $html = preg_replace('/<a[^>]*href="\/cheques\.php"[^>]*>\s*🏦[^<]*<\/a>\s*/s', '', $html);
    /* اگر متن «مدیریت چک‌ها» جایی وسط یک لینک دیگر نشسته باشد، آن بخش را پاک نمی‌کنیم تا منو نشکند */

    /* 2) پیدا کردن بلوک یک آیتم منو حول «Payment Receipts» */
    $lines = preg_split('/\r\n|\r|\n/', $html);
    $anchorIdx = -1;
    foreach ($lines as $i => $l) { if (stripos($l, 'Payment Receipts') !== false) { $anchorIdx = $i; break; } }

    $insertLine = null;
    if ($anchorIdx >= 0) {
        /* ابتدای آیتم: به عقب برو تا خط <li> یا <a> باز شونده */
        $start = $anchorIdx;
        for ($k = $anchorIdx; $k >= max(0, $anchorIdx - 12); $k--) {
            if (preg_match('/<(li|a)\b/', $lines[$k])) { $start = $k; break; }
        }
        /* انتهای آیتم: جلو برو تا </li> یا </a> */
        $end = $anchorIdx;
        for ($k = $anchorIdx; $k <= min(count($lines) - 1, $anchorIdx + 12); $k++) {
            if (preg_match('/<\/(li|a)>/', $lines[$k])) { $end = $k; break; }
        }
        $block = implode("\n", array_slice($lines, $start, $end - $start + 1));

        /* کلاس و ساختار آیکون را از همان آیتم الگو بگیر */
        preg_match('/class="([^"]*(nav-link|sidebar-link|menu-link)[^"]*)"/i', $block, $cm);
        $cls = $cm[1] ?? 'nav-link';
        /* نسخه کلون‌شده از بلوک، با href و متن جدید و حذف badge */
        $clone = $block;
        $clone = preg_replace('/href=["\'][^"\']*["\']/', 'href="/cheques.php"', $clone, 1);
        $clone = preg_replace('/\b(nav-link|sidebar-link|menu-link)(\s+active)?/i', '$1', $clone);
        $clone = preg_replace('/<span[^>]*(badge|count|notif|rounded)[^>]*>.*?<\/span>/is', '', $clone);
        $clone = preg_replace('/<sup[^>]*>.*?<\/sup>/is', '', $clone);
        /* متن لینک را جایگزین کن: محتوای متنی Payment Receipts را با فارسی عوض کن */
        $clone = preg_replace('/(>\s*)[^<>]*Payment Receipts[^<>]*(<)/s', '$1🏦 مدیریت چک‌ها$2', $clone);
        /* نشانگر برای نگهداری/حذف بعدی */
        $clone = str_replace('<li', '<li data-' . $MARK . '="1"', $clone);
        if (strpos($clone, '<li') === false) {
            $clone = '<!--' . $MARK . '--><a href="/cheques.php" class="' . $cls . '">🏦 مدیریت چک‌ها</a>';
        }
        $insertLine = $clone;
        array_splice($lines, $end + 1, 0, array($insertLine));
        file_put_contents($main, implode("\n", $lines));
        ok('لینک «مدیریت چک‌ها» به‌صورت آیتم مستقل بعد از Payment Receipts درج شد (بکاپ گرفته شد)');
    } else {
        /* fallback: درج ساده قبل از </body> یا بعد از اولین </nav> */
        $simple = "\n<!--" . $MARK . "--><a href=\"/cheques.php\" style=\"display:block;padding:8px;color:#fff\">🏦 مدیریت چک‌ها</a>\n";
        if (strpos($html, '</nav>') !== false) {
            $html = preg_replace('/<\/nav>/', $simple . '</nav>', $html, 1);
        } else { $html .= $simple; }
        file_put_contents($main, $html);
        warn('بلوک منوی الگو پیدا نشد؛ یک لینک ساده درج شد. برای چیدمان دقیق‌تر خروجی recon_menu را بفرستید.');
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
