<?php
/**
 * استخراج ساختار دقیق جدول(های) بانکی برای اتصال ماژول چک
 * محل کپی: /home/adapharm/public_html/checkmodel.php
 * اجرا: https://adapharmaco.com/checkmodel.php?token=MyStrongPass_2026_xyz
 */
const TOKEN   = 'MyStrongPass_2026_xyz';
const DB_NAME = 'adapharm_erp';
const DB_USER = 'adapharm_erp';
const DB_PASS = 'Mehdi1721Sayo3303';
header('Content-Type: text/plain; charset=utf-8');
if (!isset($_GET['token']) || !hash_equals(TOKEN, (string)$_GET['token'])) { http_response_code(403); echo "403\n"; exit; }

$pdo = new PDO('mysql:host=localhost;dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
    array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));

echo "===== جدول‌های مرتبط با bank/account/wallet =====\n";
foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    if (preg_match('/bank|account|wallet|cash|treasury/i', $t)) echo "  * $t\n";
}

foreach (array('bank_accounts') as $table) {
    echo "\n===== SHOW COLUMNS FROM `$table` =====\n";
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM `$table`") as $r) {
            echo str_pad($r['Field'],30).str_pad($r['Type'],28).str_pad($r['Null'],6).
                 'def='.($r['Default']===null?'NULL':$r['Default']).'  '.$r['Extra']."\n";
        }
        echo "\n----- نمونه ردیف‌ها (مقادیر حساس ماسک) -----\n";
        foreach ($pdo->query("SELECT * FROM `$table` LIMIT 5") as $row) {
            foreach ($row as $k=>$v) {
                if (preg_match('/number|card|iban|shaba|account/i',$k) && $v) $v = substr((string)$v,0,4).'****'.substr((string)$v,-2);
                echo "   $k = " . substr((string)$v,0,70) . "\n";
            }
            echo "   ----\n";
        }
    } catch (Exception $e) { echo "ERR: ".$e->getMessage()."\n"; }
}
echo "\n[done]\n";
