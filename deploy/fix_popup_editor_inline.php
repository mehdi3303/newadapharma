<?php
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$file='/home/adapharm/erp/app/views/communications/compose.php';
if(!is_file($file))exit("MISSING\n");
$s=file_get_contents($file);
if(strpos($s,'data-arena-popup-editor-inline')!==false)exit("ALREADY_FIXED\n");
$old='contenteditable="true" tabindex="0" data-arena-direct-editor-fix="1" id="compEditor"';
if(substr_count($s,$old)!==1)exit("ABORTED: editor markup not found\n");
$new='contenteditable="true" tabindex="0" data-arena-direct-editor-fix="1" data-arena-popup-editor-inline="1" id="compEditor" onmousedown="this.contentEditable=\'true\';this.focus();" onclick="this.contentEditable=\'true\';this.focus();"';
$stamp=date('Ymd-His');
if(!copy($file,$file.'.bak-editor-popup-'.$stamp))exit("ABORTED: backup failed\n");
file_put_contents($file,str_replace($old,$new,$s));
echo "UPDATED: popup editor inline focus\nBACKUP_SUFFIX: .bak-editor-popup-$stamp\nDelete checkmodel.php now.\n";
