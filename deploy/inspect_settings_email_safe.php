<?php
/** Read-only inspection. No file is created or modified. */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$view='/home/adapharm/erp/app/views/settings/index.php';
$controller='/home/adapharm/erp/app/controllers/SettingsController.php';
function printLines($file,$from,$to){
 if(!is_file($file)){echo "MISSING: $file\n";return;}
 $lines=file($file);
 $to=min($to,count($lines));
 for($i=max(1,$from);$i<=$to;$i++) echo sprintf('%04d | %s',$i,$lines[$i-1]);
}
echo "===== VIEW NAVIGATION AND SECTIONS =====\n";
printLines($view,1,130);
echo "===== VIEW REMAINDER / FORM BOUNDARIES =====\n";
printLines($view,131,380);
echo "===== CONTROLLER UPDATE =====\n";
if(!is_file($controller)){echo "MISSING: $controller\n";}else{
 $c=file_get_contents($controller);
 if(preg_match('/public function update\b.*?(?=\n\s*(?:public|protected|private) function\b|\z)/s',$c,$m)) echo $m[0]; else echo "UPDATE METHOD NOT FOUND\n";
}
echo "\nREAD_ONLY_DONE — delete checkmodel.php\n";
