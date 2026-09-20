<?php
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$file='/home/adapharm/erp/app/views/communications/_v3_compose.php';
$backs=glob($file.'.bak-editor-fix-*');
if(!$backs)exit("NO_BACKUP_FOUND\n");
sort($backs,SORT_STRING);$backup=end($backs);
if(!copy($backup,$file))exit("RESTORE_FAILED\n");
echo "RESTORED: ".basename($backup)."\nDelete checkmodel.php now.\n";
