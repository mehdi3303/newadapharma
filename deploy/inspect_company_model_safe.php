<?php
/** Read-only: prints the live CompanyModel source. */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$file='/home/adapharm/erp/app/models/CompanyModel.php';
if(!is_file($file)){http_response_code(404);exit("MISSING: $file\n");}
$lines=file($file);
echo "FILE=$file BYTES=".filesize($file)."\n\n";
foreach($lines as $i=>$line) echo sprintf('%04d | %s',$i+1,$line);
echo "\nREAD_ONLY_DONE — delete checkmodel.php\n";
