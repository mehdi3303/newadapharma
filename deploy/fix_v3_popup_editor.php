<?php
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$file='/home/adapharm/erp/app/views/communications/_v3_compose.php';
if(!is_file($file))exit("MISSING\n");
$s=file_get_contents($file);
if(strpos($s,'data-arena-v3-editor-fix')!==false)exit("ALREADY_FIXED\n");
$old='class="cpop-editor"';
if(substr_count($s,$old)!==1)exit("ABORTED: cpop-editor class not found exactly once\n");
$new='class="cpop-editor" data-arena-v3-editor-fix="1" contenteditable="true" tabindex="0" onmousedown="this.contentEditable=\'true\';this.focus();" onclick="this.contentEditable=\'true\';this.focus();"';
$stamp=date('Ymd-His');
if(!copy($file,$file.'.bak-editor-fix-'.$stamp))exit("ABORTED: backup failed\n");
file_put_contents($file,str_replace($old,$new,$s));
echo "UPDATED: v3 popup editor\nBACKUP_SUFFIX: .bak-editor-fix-$stamp\nDelete checkmodel.php now.\n";
