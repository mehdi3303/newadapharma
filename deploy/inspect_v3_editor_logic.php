<?php
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$file='/home/adapharm/erp/app/views/communications/_v3_compose.php';
if(!is_file($file))exit("MISSING\n");
$lines=file($file);
foreach($lines as $i=>$line){
 $t=trim($line);
 if(preg_match('/cpop-editor|cpop-sig|contenteditable|addEventListener|keydown|keypress|keyup|preventDefault|pointer-events|user-select|mode|manual/i',$t))echo sprintf('%04d | %s',$i+1,$t)."\n";
}
echo "DONE — delete checkmodel.php\n";
