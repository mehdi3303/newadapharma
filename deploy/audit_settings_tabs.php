<?php
/** Exact audit of the live Settings view/controller. Read-only; delete after use. */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$root='/home/adapharm/erp';$v=$root.'/app/views/settings/index.php';$c=$root.'/app/controllers/SettingsController.php';
function sec($x){echo "\n===== $x =====\n";} function line($x){echo trim($x)."\n";}
if(is_file($v)){
 $s=file_get_contents($v); echo 'VIEW_BYTES='.strlen($s).' SHA256='.hash('sha256',$s)."\n";
 sec('TOP NAV TABS');
 if(preg_match_all('/<a[^>]+href=["\']([^"\']*settings[^"\']*)["\'][^>]*>(.*?)<\/a>/is',$s,$mm,PREG_SET_ORDER))foreach($mm as $m) line('URL='.$m[1].' LABEL='.trim(strip_tags($m[2])));
 sec('SECTIONS');
 if(preg_match_all('/<(?:div|section)[^>]+(?:class|id)=["\']([^"\']*(?:st-section|tab-pane)[^"\']*)["\'][^>]*>/i',$s,$mm))foreach($mm[1] as $x)line($x);
 sec('FORMS');
 if(preg_match_all('/<form\b[^>]*>/i',$s,$mm))foreach($mm[0] as $x)line($x);
 sec('SECTION VALUES');
 if(preg_match_all('/name=["\']section["\']\s+value=["\']([^"\']+)/i',$s,$mm))foreach($mm[1] as $x)line($x);
 sec('EMAIL MARKERS');
 foreach(preg_split('/\R/',$s) as $i=>$l)if(preg_match('/email|imap|smtp|settings\/update|tab-pane|st-tab/i',$l))echo ($i+1).': '.trim(strip_tags($l))."\n";
}else echo "VIEW_MISSING\n";
if(is_file($c)){
 $s=file_get_contents($c);sec('CONTROLLER');
 if(preg_match_all('/public function\s+([A-Za-z0-9_]+)\s*\(/',$s,$mm))foreach($mm[1] as $x)line('METHOD '.$x);
 if(preg_match_all('/case\s+[\'\"]([^\'\"]+)[\'\"]\s*:/',$s,$mm))foreach($mm[1] as $x)line('SECTION '.$x);
}else echo "CONTROLLER_MISSING\n";
sec('BACKUPS');
foreach(glob($v.'.bak-*')?:[] as $f)echo basename($f).' '.filesize($f)." bytes\n";
echo "\nDONE — delete checkmodel.php\n";
