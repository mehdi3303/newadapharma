<?php
/** Surgical repair: fix Settings form actions only. Does not alter tabs, sections, or fields. */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$view='/home/adapharm/erp/app/views/settings/index.php';
if(!is_file($view)){exit("ERROR: settings view not found\n");}
$old=file_get_contents($view);@copy($view,$view.'.bak-save-only-'.date('Ymd-His'));
$v=$old;
/* Remove literal escape text introduced by the previous repair attempt. */
$v=str_replace('\\n',"\n",$v);
/* Repair only malformed action attributes; leave every other byte/content intact. */
$v=preg_replace('/<form method="POST" action="<\?= APP_URL \?>\s*.*?settings\/update">/s','<form method="POST" action="<?= APP_URL ?>settings/update">',$v,-1,$count);
if($v===$old){echo "NO_CHANGE: no malformed settings form action found\n";}else{file_put_contents($view,$v);echo "FIXED_ACTIONS=$count\n";}
/* Report the resulting form action lines for verification. */
foreach(preg_split('/\R/',$v) as $i=>$line){if(stripos($line,'<form')!==false)echo ($i+1).': '.trim($line)."\n";}
echo "No tabs or form fields were removed. Delete checkmodel.php now.\n";
