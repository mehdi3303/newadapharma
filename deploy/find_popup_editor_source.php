<?php
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$root='/home/adapharm/erp/app/views';
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
foreach($it as $f){if(!$f->isFile()||$f->getExtension()!=='php')continue;$s=file_get_contents($f->getPathname());if(stripos($s,'cpop-editor')!==false||stripos($s,'cpop_1_editor')!==false)echo $f->getPathname()." BYTES=".strlen($s)."\n";}
echo "DONE — delete checkmodel.php\n";
