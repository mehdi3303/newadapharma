<?php
/** Very small read-only Communications audit. Output is intentionally compact. */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$root='/home/adapharm/erp';
$files=['app/controllers/CommunicationController.php','app/views/communications/index.php','app/views/communications/index_v3.php','app/views/communications/accounts.php'];
function report($rel,$root){
 $p=$root.'/'.$rel; echo "\n[$rel]\n";
 if(!is_file($p)){echo "MISSING\n";return;}
 $s=file_get_contents($p); echo 'bytes='.strlen($s).' sha256='.hash('sha256',$s)."\n";
 $out=[];
 foreach(preg_split('/\R/',$s) as $line){
  $t=trim($line);
  if(preg_match('/(?:public|private|protected) function\s+([A-Za-z0-9_]+)|<form\b[^>]*|<button\b[^>]*>[^<]{0,80}|name=["\']([^"\']+)|action=["\']([^"\']+)|fetch\s*\(|href=["\'][^"\']*(?:communication|email)[^"\']*/i',$t,$m)){
   $x=$m[0]; $x=preg_replace('/\s+/',' ',$x); if(strlen($x)>180)$x=substr($x,0,180).'...'; $out[$x]=true;
  }
 }
 foreach(array_keys($out) as $x)echo $x."\n";
}
foreach($files as $f)report($f,$root);
echo "\nDONE — read-only; delete checkmodel.php\n";
