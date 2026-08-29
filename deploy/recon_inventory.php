<?php
/**
 * ============================================================================
 * ADA PHARMA ERP — RECON TOOLKIT  (Phase 2: Pharma Batch Tracker — آماده‌سازی)
 * ============================================================================
 * روش استفاده (مطابق روال همیشگی):
 *   1) محتوای همین فایل را در cPanel → File Manager داخل
 *      /home/adapharm/public_html/checkmodel.php  کپی کنید
 *      (یا داخل /home/adapharm/erp/checkmodel.php — اسکریپت هر دو را تشخیص می‌دهد)
 *   2) در مرورگر باز کنید:
 *      https://adapharmaco.com/checkmodel.php?token=TOKEN&a=ACTION
 *   3) خروجی متنی را کپی و در چت پیست کنید
 *   4) بین هر رفرش ۵-۱۰ ثانیه صبر کنید (BitNinja rate limit)
 *   5) بعد از اتمام کار، فایل checkmodel.php را از سرور حذف کنید
 *
 * ACTION ها:
 *   env                            محیط PHP + تشخیص مسیر ERP
 *   tables                         لیست همه جدول‌ها + تعداد ردیف تقریبی
 *   schema&like=inventory%         SHOW CREATE TABLE برای جدول‌های منطبق با LIKE
 *   cols&t=TABLE                   SHOW COLUMNS یک جدول
 *   data&t=TABLE&n=10              نمونه ردیف‌ها (پیش‌فرض ۱۰، سقف ۵۰)
 *   migrations                     تاریخچه schema_migrations
 *   files                          لیست فایل‌های کد ERP (app/config/ریشه)
 *   read&f=app/models/Foo.php      محتوای یک فایل (فقط داخل ریشه ERP)
 *   lint&f=app/.../file.php        بررسی سینتکس PHP بدون exec (با tokenizer)
 *   grep&q=paid                    جستجوی متنی (case-insensitive) در فایل‌های PHP
 *
 * نکته: هیچ exec/shell_exec استفاده نشده (روی CloudLinux غیرفعال‌اند).
 * ============================================================================
 */

const TOKEN  = 'MyStrongPass_2026_xyz';
const DB_HOST = 'localhost';
const DB_NAME = 'adapharm_erp';
const DB_USER = 'adapharm_erp';
const DB_PASS = 'Mehdi1721Sayo3303';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

if (!isset($_GET['token']) || !hash_equals(TOKEN, (string)$_GET['token'])) {
    http_response_code(403);
    echo "403 Forbidden — bad or missing token\n";
    exit;
}

$a = isset($_GET['a']) ? $_GET['a'] : 'env';

/* ---------- تشخیص ریشه ERP ---------- */
$erpRoot = null;
$candidates = array();
if (is_file(__DIR__ . '/config/database.php')) {
    $candidates[] = __DIR__;                       // اسکریپت داخل /home/adapharm/erp
}
$candidates[] = '/home/adapharm/erp';
$candidates[] = dirname(__DIR__) . '/erp';
foreach ($candidates as $c) {
    if (is_file($c . '/config/database.php')) { $erpRoot = $c; break; }
}

/* ---------- توابع کمکی ---------- */
function db() {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
        );
        return $pdo;
    } catch (PDOException $e) {
        echo "DB CONNECT FAILED: " . $e->getMessage() . "\n";
        return null;
    }
}

function hr($t) { echo "\n==================== $t ====================\n"; }

function cleanIdent($s) {
    return preg_replace('/[^a-zA-Z0-9_]/', '', (string)$s);
}

$skipDirs = array('uploads', '.git', 'vendor', 'node_modules', 'cache', 'logs', 'tmp');

/* ---------- اجرای اکشن ---------- */
switch ($a) {

case 'env':
    hr('ENV');
    echo 'PHP version  : ' . PHP_VERSION . "\n";
    echo 'SAPI         : ' . PHP_SAPI . "\n";
    echo 'script       : ' . __FILE__ . "\n";
    echo 'open_basedir : ' . ini_get('open_basedir') . "\n";
    echo 'disabled fn  : ' . ini_get('disable_functions') . "\n";
    echo 'ERP root     : ' . ($erpRoot ? $erpRoot : 'NOT FOUND') . "\n";
    if ($erpRoot) {
        echo 'config read  : ' . (is_readable($erpRoot . '/config/database.php') ? 'yes' : 'NO') . "\n";
        echo 'root writable: ' . (is_writable($erpRoot) ? 'yes' : 'NO') . "\n";
        echo "--- listing of ERP root ---\n";
        foreach (scandir($erpRoot) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $erpRoot . '/' . $f;
            echo (is_dir($p) ? '[DIR]  ' : '[FILE] ') . $f
               . (is_file($p) ? '  (' . filesize($p) . ' bytes)' : '') . "\n";
        }
    }
    break;

case 'tables': {
    $pdo = db(); if (!$pdo) break;
    hr('TABLES in ' . DB_NAME);
    $sql = "SELECT TABLE_NAME, TABLE_ROWS, TABLE_COMMENT
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = '" . DB_NAME . "'
            ORDER BY TABLE_NAME";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo str_pad($r['TABLE_NAME'], 44)
           . str_pad(number_format((int)$r['TABLE_ROWS']), 10, ' ', STR_PAD_LEFT)
           . '  ' . $r['TABLE_COMMENT'] . "\n";
    }
    echo "\nTOTAL: " . count($rows) . " tables\n";
    break;
}

case 'schema': {
    $pdo = db(); if (!$pdo) break;
    $like = preg_replace('/[^a-zA-Z0-9_%]/', '', isset($_GET['like']) ? $_GET['like'] : 'inventory%');
    hr('SHOW CREATE TABLE  LIKE ' . $like);
    $tabs = $pdo->query("SHOW TABLES LIKE '" . $like . "'")->fetchAll(PDO::FETCH_COLUMN);
    if (!$tabs) { echo "(no tables match)\n"; break; }
    foreach ($tabs as $t) {
        echo "\n########## TABLE: `$t` ##########\n";
        $row = $pdo->query("SHOW CREATE TABLE `" . $t . "`")->fetch(PDO::FETCH_ASSOC);
        $key = isset($row['Create Table']) ? 'Create Table' : 'Create View';
        echo $row[$key] . ";\n";
    }
    break;
}

case 'cols': {
    $pdo = db(); if (!$pdo) break;
    $t = cleanIdent(isset($_GET['t']) ? $_GET['t'] : '');
    hr("SHOW COLUMNS FROM `$t`");
    if ($t === '') { echo "usage: cols&t=TABLE\n"; break; }
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            echo str_pad($r['Field'], 32)
               . str_pad($r['Type'], 30)
               . str_pad($r['Null'], 6)
               . str_pad($r['Key'], 5)
               . str_pad($r['Default'] === null ? 'NULL' : (string)$r['Default'], 14)
               . $r['Extra'] . "\n";
        }
    } catch (PDOException $e) {
        echo "ERR: " . $e->getMessage() . "\n";
    }
    break;
}

case 'data': {
    $pdo = db(); if (!$pdo) break;
    $t = cleanIdent(isset($_GET['t']) ? $_GET['t'] : '');
    $n = (int)(isset($_GET['n']) ? $_GET['n'] : 10);
    if ($n < 1)   $n = 10;
    if ($n > 50)  $n = 50;
    hr("SAMPLE DATA: `$t` LIMIT $n");
    if ($t === '') { echo "usage: data&t=TABLE[&n=10]\n"; break; }
    try {
        $rows = $pdo->query("SELECT * FROM `$t` LIMIT $n")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { echo "(table is empty)\n"; break; }
        echo implode(' | ', array_keys($rows[0])) . "\n";
        echo str_repeat('-', 90) . "\n";
        foreach ($rows as $r) {
            $parts = array();
            foreach ($r as $v) {
                if ($v === null) $v = 'NULL';
                $v = str_replace(array("\n", "\r"), ' ', (string)$v);
                if (strlen($v) > 50) $v = substr($v, 0, 47) . '...';
                $parts[] = $v;
            }
            echo implode(' | ', $parts) . "\n";
        }
        $cnt = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "\nTOTAL ROWS: $cnt\n";
    } catch (PDOException $e) {
        echo "ERR: " . $e->getMessage() . "\n";
    }
    break;
}

case 'migrations': {
    $pdo = db(); if (!$pdo) break;
    hr('schema_migrations');
    try {
        $rows = $pdo->query("SELECT * FROM schema_migrations ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { echo "(empty)\n"; break; }
        foreach ($rows as $r) {
            echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }
    } catch (PDOException $e) {
        echo "ERR: " . $e->getMessage() . "\n";
    }
    break;
}

case 'files': {
    if (!$erpRoot) { echo "ERP root not found — run a=env first\n"; break; }
    hr('ERP SOURCE FILES');
    $allowedExt = array('php', 'html', 'js', 'sql', 'md', 'json', 'css');
    $out = array();
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($erpRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $rel = str_replace($erpRoot . DIRECTORY_SEPARATOR, '', $file->getPathname());
        $top = explode(DIRECTORY_SEPARATOR, $rel);
        if (in_array($top[0], $skipDirs, true)) continue;
        $ext = strtolower($file->getExtension());
        if (in_array($ext, $allowedExt, true)) {
            $out[] = str_pad((string)$file->getSize(), 9, ' ', STR_PAD_LEFT) . '  '
                  . date('Y-m-d', $file->getMTime()) . '  ' . $rel;
        }
    }
    sort($out);
    echo implode("\n", $out) . "\n\nTOTAL: " . count($out) . " files\n";
    break;
}

case 'read':
case 'lint': {
    if (!$erpRoot) { echo "ERP root not found — run a=env first\n"; break; }
    $f = ltrim((string)(isset($_GET['f']) ? $_GET['f'] : ''), '/');
    if ($f === '' || strpos($f, '..') !== false) {
        echo "bad path\n"; break;
    }
    $full = $erpRoot . '/' . $f;
    $real = realpath($full);
    $rootReal = realpath($erpRoot);
    if ($real === false || $rootReal === false || strpos($real, $rootReal) !== 0 || !is_file($real)) {
        echo "file not found under ERP root: $f\n"; break;
    }
    $code = file_get_contents($real);
    if ($a === 'lint') {
        hr("LINT: $f");
        echo 'size: ' . strlen($code) . " bytes\n";
        try {
            if (defined('TOKEN_PARSE')) {
                token_get_all($code, TOKEN_PARSE);
            } else {
                token_get_all($code);
            }
            echo "SYNTAX OK\n";
        } catch (ParseError $e) {
            echo "PARSE ERROR: " . $e->getMessage() . "\n";
        } catch (Error $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
        }
    } else {
        hr("READ: $f  (" . strlen($code) . " bytes)");
        if (strlen($code) > 200000) {
            echo "[NOTE] file > 200KB — showing first 200KB only\n\n";
            $code = substr($code, 0, 200000);
        }
        echo $code;
    }
    break;
}

case 'grep': {
    if (!$erpRoot) { echo "ERP root not found — run a=env first\n"; break; }
    $q = (string)(isset($_GET['q']) ? $_GET['q'] : '');
    if ($q === '') { echo "usage: grep&q=TEXT\n"; break; }
    hr('GREP (case-insensitive): ' . $q);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($erpRoot, FilesystemIterator::SKIP_DOTS)
    );
    $hits = 0;
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $rel = str_replace($erpRoot . DIRECTORY_SEPARATOR, '', $file->getPathname());
        $top = explode(DIRECTORY_SEPARATOR, $rel);
        if (in_array($top[0], $skipDirs, true)) continue;
        if (strtolower($file->getExtension()) !== 'php') continue;
        if ($file->getSize() > 600000) continue;
        $lines = file($file->getPathname());
        if ($lines === false) continue;
        foreach ($lines as $i => $line) {
            if (stripos($line, $q) !== false) {
                echo $rel . ':' . ($i + 1) . ': ' . rtrim($line) . "\n";
                $hits++;
                if ($hits >= 300) { echo "[cap 300 reached]\n"; break 2; }
            }
        }
    }
    echo "\nTOTAL MATCHES: $hits\n";
    break;
}

default:
    echo "Unknown action '$a'.\n"
       . "Available: env, tables, schema, cols, data, migrations, files, read, lint, grep\n";
}

echo "\n[done]\n";
