<?php
/**
 * ============================================================================
 * ADA PHARMA ERP — تشخیص ساختار سشن/لاگین  (برای اتصال ماژول چک به لاگین ERP)
 * ============================================================================
 * محل کپی:  /home/adapharm/erp/checkmodel.php   (این‌بار داخل پوشه erp، نه public_html)
 * اجرا (در همان مرورگری که در ERP لاگین هستید):
 *   https://erp.adapharmaco.com/checkmodel.php?token=MyStrongPass_2026_xyz
 * بعد از گرفتن خروجی، فایل را حذف کنید.
 * ============================================================================
 */

const TOKEN = 'MyStrongPass_2026_xyz';
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

if (!isset($_GET['token']) || !hash_equals(TOKEN, (string)$_GET['token'])) {
    http_response_code(403); echo "403 bad token\n"; exit;
}

function hr($t) { echo "\n==================== $t ====================\n"; }
function mask_dump($var, $indent = '') {
    $out = '';
    if (is_array($var)) {
        foreach ($var as $k => $v) {
            if (is_array($v)) {
                $out .= $indent . $k . ' => array(' . count($v) . ")\n" . mask_dump($v, $indent . '   ');
            } elseif (is_object($v)) {
                $out .= $indent . $k . ' => [object ' . get_class($v) . "]\n";
            } else {
                $sensitive = preg_match('/pass|token|secret|pwd|hash|code/i', (string)$k);
                $val = (string)$v;
                if ($sensitive) $val = '***(' . strlen($val) . ' chars)';
                elseif (strlen($val) > 70) $val = substr($val, 0, 67) . '...';
                $out .= $indent . $k . ' => ' . $val . "\n";
            }
        }
    }
    return $out;
}
function grepcode($root, $pattern, $label, $cap = 150) {
    hr($label);
    $hits = 0;
    if (!is_dir($root)) { echo "  (root not found: $root)\n"; return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
        $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $f->getPathname());
        if (preg_match('#/(uploads|vendor|node_modules|cache|logs)#', DIRECTORY_SEPARATOR . $rel)) continue;
        if ($f->getSize() > 800000) continue;
        $lines = @file($f->getPathname());
        if (!$lines) continue;
        foreach ($lines as $i => $line) {
            if (@preg_match($pattern, $line)) {
                echo '  ' . $rel . ':' . ($i + 1) . ': ' . trim(mb_substr($line, 0, 170)) . "\n";
                if (++$hits >= $cap) { echo "  [cap reached]\n"; return; }
            }
        }
    }
    echo "  ($hits matches)\n";
}

/* ---------- محیط ---------- */
hr('ENV');
echo 'PHP            : ' . PHP_VERSION . "\n";
echo 'HTTP_HOST      : ' . ($_SERVER['HTTP_HOST'] ?? '') . "\n";
echo 'SCRIPT         : ' . __FILE__ . "\n";
echo 'session.save   : ' . ini_get('session.save_handler') . ' / ' . (ini_get('session.save_path') ?: '(default)') . "\n";
echo 'default name   : ' . session_name() . "\n";

/* ---------- کوکی‌ها ---------- */
hr('COOKIES (نام کوکی‌ها — مقادیر مخفی)');
foreach ($_COOKIE as $k => $v) {
    echo '  ' . $k . '  (len ' . strlen((string)$v) . ")\n";
}
if (!$_COOKIE) echo "  (هیچ کوکی‌ای نیست — یعنی لاگین نیستید یا کوکی دامنه دیگری دارد)\n";

/* ---------- تلاش برای خواندن سشن با هر نام کوکی مشکوک ---------- */
hr('SESSION DETECTION');
$tried = array();
foreach (array_keys($_COOKIE) as $cname) {
    if (!preg_match('/sess|sid|auth|user|login/i', $cname) && $cname !== 'PHPSESSID') continue;
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    @session_name($cname);
    @session_start();
    echo "\n-- کوکی «$cname» → session_id: " . substr((string)session_id(), 0, 10) . "...\n";
    if (empty($_SESSION)) {
        echo "   $_SESSION خالی است\n";
    } else {
        echo "   کلیدها: " . implode(', ', array_keys($_SESSION)) . "\n";
        echo "   ساختار:\n";
        echo mask_dump($_SESSION, '     ');
    }
    $tried[] = $cname;
}
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
if (!$tried) echo "هیچ کوکی مشابه سشن پیدا نشد.\n";

/* ---------- ریشه ERP و فایل‌ها ---------- */
$erpRoot = '/home/adapharm/erp';
hr('ERP ROOT FILES');
foreach (scandir($erpRoot) as $f) {
    if ($f === '.' || $f === '..') continue;
    $p = $erpRoot . '/' . $f;
    echo (is_dir($p) ? '[DIR]  ' : '[FILE] ') . $f . (is_file($p) ? '  (' . filesize($p) . ' bytes)' : '') . "\n";
}
hr('app/core FILES');
$core = $erpRoot . '/app/core';
if (is_dir($core)) {
    foreach (scandir($core) as $f) { if ($f[0] === '.') continue; echo '  ' . $f . "\n"; }
} else echo "  (نیست)\n";

if (is_file($erpRoot . '/.htaccess')) {
    hr('.htaccess (ریشه erp)');
    echo file_get_contents($erpRoot . '/.htaccess');
}

/* ---------- جستجوی نحوه ذخیره لاگین در کد ---------- */
grepcode($erpRoot . '/app', '/\$_SESSION\[/', 'GREP: همه استفاده‌های $_SESSION در app/ (کلید لاگین اینجاست)');
grepcode($erpRoot,        '/session_name\s*\(|session_set_cookie_params/', 'GREP: تنظیمات نام/دامنه سشن');
grepcode($erpRoot . '/app', '/function\s+(isLoggedIn|isLogged|checkLogin|requireLogin|currentUser|current_user|user|checkAuth|auth)/i', 'GREP: توابع احراز هویت');
grepcode($erpRoot . '/app', '/(login|auth).*\$_SESSION|\$_SESSION.*(user_id|userid|uid|logged)/i', 'GREP: محل ست‌شدن سشن لاگین');

echo "\n[done]\n";
