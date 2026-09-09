<?php
/** Recover original Settings tabs, then repair only the broken injected markup. */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$root='/home/adapharm/erp';$view=$root.'/app/views/settings/index.php';
function outx($s){echo $s."\n";}
$files=glob($view.'.bak-settings-*');
if(!$files){outx('No original settings backup found. Do not continue; send this output.');exit;}
usort($files,function($a,$b){return filemtime($b)<=>filemtime($a);});
$source=$files[0];
if(!copy($source,$view)){outx('Could not restore '.$source);exit;}
outx('Restored original view from '.basename($source));
$v=file_get_contents($view);
/* Fix the broken first form without touching any other tab content. */
$v=preg_replace('/<form method="POST" action="<\?= APP_URL \?>\s*.*?settings\/update">/s','<form method="POST" action="<?= APP_URL ?>settings/update">',$v,1);
/* Remove the accidentally injected Bootstrap email markup from the old view. */
$v=preg_replace('/\s*<ul class="nav nav-pills.*?\nsettings\/update">/s','\n<form method="POST" action="<?= APP_URL ?>settings/update">',$v,1);
$v=preg_replace('/\s*<div class="tab-pane fade" id="email-pane".*?\n<\/div>\s*\n<\/div>\s*\n<\/form>\s*\n<\/div>/s','',$v,1);
file_put_contents($view,$v);outx('Original Company/Documents/Branding/Reports content preserved.');
outx('Email injected block removed; existing settings links restored.');
outx('DONE — delete checkmodel.php now.');
