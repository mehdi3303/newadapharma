<?php
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$file='/home/adapharm/erp/app/views/communications/_v3_compose.php';
if(!is_file($file))exit("MISSING\n");
$s=file_get_contents($file);
if(strpos($s,'data-arena-manual-mode-fix')!==false)exit("ALREADY_FIXED\n");
$old='    if (mode === "template") { cpopShowTemplates(id); }';
$new=<<<'JS'
    if (mode === "template") { cpopShowTemplates(id); }
    if (mode === "manual") {
      var manualEditor = document.getElementById(id + "_editor");
      if (manualEditor) {
        manualEditor.setAttribute("data-arena-manual-mode-fix", "1");
        manualEditor.contentEditable = "true";
        manualEditor.style.pointerEvents = "auto";
        manualEditor.style.userSelect = "text";
        manualEditor.focus();
      }
    }
JS;
if(substr_count($s,$old)!==1)exit("ABORTED: mode anchor not found\n");
$stamp=date('Ymd-His');
if(!copy($file,$file.'.bak-manual-mode-'.$stamp))exit("ABORTED: backup failed\n");
file_put_contents($file,str_replace($old,$new,$s));
echo "UPDATED: manual mode focuses editable popup editor\nBACKUP_SUFFIX: .bak-manual-mode-$stamp\nDelete checkmodel.php now.\n";
