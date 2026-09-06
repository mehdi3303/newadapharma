<?php
/**
 * ============================================================================
 * ADA PHARMA ERP — جمع‌آوری اطلاعات طراحی/قالب/حساب بانکی (برای هماهنگ‌کردن ماژول چک)
 * ============================================================================
 * محل کپی: /home/adapharm/public_html/checkmodel.php
 * اجرا:
 *   https://adapharmaco.com/checkmodel.php?token=MyStrongPass_2026_xyz
 * بعد از گرفتن خروجی فایل را حذف کنید.
 * ============================================================================
 */
const TOKEN   = 'MyStrongPass_2026_xyz';
const DB_HOST = 'localhost';
const DB_NAME = 'adapharm_erp';
const DB_USER = 'adapharm_erp';
const DB_PASS = 'Mehdi1721Sayo3303';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
if (!isset($_GET['token']) || !hash_equals(TOKEN, (string)$_GET['token'])) { http_response_code(403); echo "403 bad token\n"; exit; }

$erp = '/home/adapharm/erp';
function section($t){ echo "\n\n############################################################\n# $t\n############################################################\n"; }
function rd($file, $cap = 90000) {
    global $erp;
    $p = $erp . '/' . ltrim($file, '/');
    if (!is_file($p)) { echo "(missing: $file)\n"; return; }
    $c = file_get_contents($p);
    echo "-- $file  (" . strlen($c) . " bytes) --\n";
    if (strlen($c) > $cap) { echo substr($c, 0, $cap) . "\n...[TRUNCATED " . (strlen($c)-$cap) . " bytes]...\n"; }
    else echo $c . "\n";
}
function ls($dir) {
    global $erp;
    $p = $erp . '/' . trim($dir, '/');
    echo "-- $dir --\n";
    if (!is_dir($p)) { echo "(missing dir)\n"; return; }
    foreach (scandir($p) as $f) { if ($f[0] === '.') continue; echo '  ' . (is_dir($p.'/'.$f) ? '[D] ' : '    ') . $f . "\n"; }
}

/* ---------- بانک‌ها: ساختار + نمونه (شماره‌ها ماسک) ---------- */
section('BANK_ACCOUNTS — STRUCTURE');
try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
    foreach ($pdo->query("SHOW COLUMNS FROM bank_accounts") as $r) {
        echo str_pad($r['Field'],28).str_pad($r['Type'],28).str_pad($r['Null'],6).($r['Default']===null?'NULL':$r['Default'])."\n";
    }
    section('BANK_ACCOUNTS — SAMPLE (masked)');
    foreach ($pdo->query("SELECT * FROM bank_accounts LIMIT 3") as $row) {
        foreach ($row as $k=>$v) {
            if (preg_match('/number|card|iban|shaba|account/i',$k) && $v) $v = substr((string)$v,0,4).'****'.substr((string)$v,-3);
            echo "  $k = ".substr((string)$v,0,60)."\n";
        }
        echo "  ----\n";
    }
} catch (Exception $e) { echo "DB ERR: ".$e->getMessage()."\n"; }

/* ---------- ساختار پوشه‌ها ---------- */
section('CONTROLLERS'); ls('app/controllers');
section('VIEWS (top dirs)'); ls('app/views');
section('LAYOUTS'); ls('app/views/layouts');
section('PUBLIC ASSETS'); ls('public');
@ls('public/css'); @ls('public/js'); @ls('assets');

/* ---------- فایل‌های بوت‌استرپ و قالب ---------- */
section('BOOTSTRAP: index.php');          rd('index.php', 30000);
section('CORE: App.php');                rd('app/core/App.php', 40000);
section('CORE: Controller.php');         rd('app/core/Controller.php', 30000);
section('VIEW LAYOUT: main.php');        rd('app/views/layouts/main.php', 120000);

/* ---------- آیا مدیریت بانک وجود دارد؟ ---------- */
section('GREP: bank in controllers/views');
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($erp.'/app', FilesystemIterator::SKIP_DOTS));
$n=0;
foreach ($it as $f) {
    if (!$f->isFile() || strtolower($f->getExtension())!=='php') continue;
    $rel = str_replace($erp.'/','',$f->getPathname());
    foreach (preg_grep('/bank/i', file($f->getPathname()) ?: array()) as $i=>$line) {}
    $lines = file($f->getPathname());
    foreach ($lines as $i=>$l) {
        if (preg_match('/bank/i',$l)) { echo '  '.$rel.':'.($i+1).': '.trim(substr($l,0,120))."\n"; if(++$n>=60) break 2; }
    }
}

/* ---------- مسیر CSS/JS ---------- */
section('GREP: css/js asset links in layouts & index');
foreach (array('app/views/layouts/main.php','index.php') as $vf) {
    $p = $erp.'/'.$vf; if (!is_file($p)) continue;
    foreach (file($p) as $i=>$l) {
        if (preg_match('/\.(css|js)|<link|<script|stylesheet/i',$l)) echo '  '.$vf.':'.($i+1).': '.trim(substr($l,0,160))."\n";
    }
}

/* ---------- config (رمز ماسک) ---------- */
section('CONFIG (masked)');
$cfg = $erp.'/config/config.php';
if (is_file($cfg)) {
    $c = file_get_contents($cfg);
    $c = preg_replace('/(pass|password|secret|key)(["\']?\s*[=:]\s*["\']?)([^"\'\s;]+)/i','$1$2***MASKED***',$c);
    echo substr($c, 0, 20000)."\n";
} else echo "(no config.php)\n";

echo "\n[done]\n";
