<?php
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$file='/home/adapharm/erp/app/views/communications/compose.php';
if(!is_file($file))exit("MISSING\n");
$s=file_get_contents($file);
if(strpos($s,'data-arena-direct-editor-fix')!==false)exit("ALREADY_FIXED\n");
$old='contenteditable="true" id="compEditor"';
if(substr_count($s,$old)!==1)exit("ABORTED: editor markup not found exactly once\n");
$new='contenteditable="true" tabindex="0" data-arena-direct-editor-fix="1" id="compEditor"';
$s=str_replace($old,$new,$s, $n);
$css=<<<'CSS'

<style data-arena-direct-editor-fix>
#compEditor { pointer-events: auto !important; user-select: text !important; -webkit-user-select: text !important; cursor: text !important; }
#compEditor[contenteditable="false"] { contenteditable: true; }
</style>
CSS;
$stamp=date('Ymd-His');
if(!copy($file,$file.'.bak-editor-direct-'.$stamp))exit("ABORTED: backup failed\n");
file_put_contents($file,$s.$css);
echo "UPDATED: direct compose editor editability\nBACKUP_SUFFIX: .bak-editor-direct-$stamp\nDelete checkmodel.php now.\n";
