<?php
/**
 * استخراج دقیق بلوک منوی سایدبار از layouts/main.php (برای درج صحیح لینک چک‌ها)
 * محل کپی: /home/adapharm/public_html/checkmodel.php
 * اجرا: https://adapharmaco.com/checkmodel.php?token=MyStrongPass_2026_xyz
 */
const TOKEN = 'MyStrongPass_2026_xyz';
header('Content-Type: text/plain; charset=utf-8');
if (!isset($_GET['token']) || !hash_equals(TOKEN, (string)$_GET['token'])) { http_response_code(403); echo "403\n"; exit; }

$main = '/home/adapharm/erp/app/views/layouts/main.php';
if (!is_file($main)) { echo "main.php not found\n"; exit; }
$lines = file($main);
echo "total lines: " . count($lines) . "\n\n";

$hits = array();
foreach ($lines as $i => $l) {
    if (preg_match('/Payment Receipts|Official Letters|Export Bundles|nav-link|sidebar|Quotations|Pro Forma|Invoices|Order Tracking/i', $l)) {
        $hits[] = $i;
    }
}
/* چاپ بازه‌های اطراف هر محل منو */
$shown = array();
foreach ($hits as $i) {
    $start = max(0, $i - 4); $end = min(count($lines) - 1, $i + 4);
    for ($k = $start; $k <= $end; $k++) {
        if (isset($shown[$k])) continue; $shown[$k] = true;
        echo str_pad($k + 1, 4, ' ', STR_PAD_LEFT) . '| ' . rtrim($lines[$k]) . "\n";
    }
    echo "   ----\n";
}
echo "\n[done]\n";
