<?php
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$file='/home/adapharm/erp/app/views/communications/compose.php';
if(!is_file($file))exit("MISSING\n");
$s=file_get_contents($file);
if(strpos($s,'data-arena-force-editor')!==false)exit("ALREADY_FIXED\n");
$js=<<<'JS'

<script data-arena-force-editor>
(function(){
 function unlock(){var e=document.getElementById('compEditor');if(!e)return;e.contentEditable='true';e.removeAttribute('readonly');e.removeAttribute('disabled');e.style.setProperty('pointer-events','auto','important');e.style.setProperty('user-select','text','important');}
 document.addEventListener('mousedown',function(e){if(e.target.closest&&e.target.closest('#compEditor')){unlock();setTimeout(function(){var x=document.getElementById('compEditor');if(x)x.focus();},0);}},true);
 new MutationObserver(unlock).observe(document.documentElement,{subtree:true,attributes:true,attributeFilter:['contenteditable','readonly','disabled','style']});
 setInterval(unlock,250);
 unlock();
})();
</script>
JS;
$stamp=date('Ymd-His');
if(!copy($file,$file.'.bak-editor-force-'.$stamp))exit("ABORTED: backup failed\n");
file_put_contents($file,$s.$js);
echo "UPDATED\nBACKUP_SUFFIX: .bak-editor-force-$stamp\nDelete checkmodel.php now.\n";
