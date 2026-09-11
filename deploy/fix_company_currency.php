<?php
/** Surgical fix: persist the existing Company default_currency field. */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$file='/home/adapharm/erp/app/controllers/SettingsController.php';
if(!is_file($file))exit("ERROR: controller not found\n");
$c=file_get_contents($file);
$currencyLine = <<<'CURRENCY'
            'default_currency' => $this->post('default_currency', 'EUR'),
CURRENCY;
if(strpos($c,$currencyLine)!==false){exit("ALREADY_FIXED: no files changed.\n");}
$anchor = <<<'BANK'
            'bank_details' => $this->post('bank_details', ''),
BANK;
if(substr_count($c,$anchor)!==1)exit("ABORTED: identity data anchor not found exactly once. no files changed.\n");
$replacement=$anchor.$currencyLine;
$stamp=date('Ymd-His');
if(!copy($file,$file.'.bak-currency-'.$stamp))exit("ABORTED: backup failed. no files changed.\n");
file_put_contents($file,str_replace($anchor,$replacement,$c));
echo "UPDATED: Company default currency persistence\n";
echo "BACKUP_SUFFIX: .bak-currency-$stamp\n";
echo "Delete checkmodel.php now.\n";
