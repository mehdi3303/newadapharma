<?php
/** Restore only the original Settings view. No edits, no tab injection. */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$view='/home/adapharm/erp/app/views/settings/index.php';
$src=$view.'.bak-settings-view-20260909-142326';
if(!is_file($src)){
 $all=glob($view.'.bak-settings-view-*')?:[];
 if(!$all){echo "ERROR: original backup not found\n";exit;}
 usort($all,function($a,$b){return filemtime($b)<=>filemtime($a);});$src=$all[0];
}
if(!is_file($view)){echo "ERROR: current view not found\n";exit;}
$backup=$view.'.bak-before-restore-'.date('Ymd-His');
if(!copy($view,$backup)){echo "ERROR: could not create safety backup\n";exit;}
$data=file_get_contents($src);
if($data===false||strlen($data)<10000){echo "ERROR: selected backup is invalid\n";exit;}
if(file_put_contents($view,$data)===false){echo "ERROR: restore failed\n";exit;}
echo "RESTORED: ".basename($src)."\n";
echo "BYTES: ".strlen($data)."\n";
echo "SHA256: ".hash('sha256',$data)."\n";
echo "Existing tabs and details restored without modification.\n";
echo "DONE — delete checkmodel.php now.\n";
