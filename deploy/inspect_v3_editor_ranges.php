<?php
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$file='/home/adapharm/erp/app/views/communications/_v3_compose.php';
if(!is_file($file))exit("MISSING\n");
$lines=file($file);
foreach([[440,490],[690,715],[485,505]] as $range){echo "\n===== {$range[0]}-{$range[1]} =====\n";for($i=$range[0];$i<=$range[1]&&$i<=count($lines);$i++)echo sprintf('%04d | %s',$i,$lines[$i-1]);}
echo "DONE — delete checkmodel.php\n";
