<?php
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$file='/home/adapharm/erp/app/views/communications/compose.php';
if(!is_file($file))exit("MISSING\n");
foreach(file($file) as $i=>$line){
 $t=trim($line);
 if(preg_match('/textarea|contenteditable|readonly|disabled|iframe|editor|draft|body|message|script|rich|quill|tinymce/i',$t))echo sprintf('%04d | %s',$i+1,$t)."\n";
}
echo "DONE — read-only; delete checkmodel.php\n";
