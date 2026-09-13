<?php
/** Make the existing compose editor reliably editable. */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$file='/home/adapharm/erp/app/views/communications/compose.php';
if(!is_file($file))exit("ERROR: compose view not found\n");
$s=file_get_contents($file);
$marker='data-arena-compose-editor-unlock';
if(strpos($s,$marker)!==false)exit("ALREADY_FIXED: no files changed.\n");
$script=<<<'JS'

<script data-arena-compose-editor-unlock>
(function () {
    function unlockComposeEditor() {
        var editor = document.getElementById('compEditor');
        if (!editor) return;
        editor.contentEditable = 'true';
        editor.removeAttribute('readonly');
        editor.removeAttribute('disabled');
        editor.style.pointerEvents = 'auto';
        editor.style.userSelect = 'text';
        editor.style.webkitUserSelect = 'text';
    }
    document.addEventListener('DOMContentLoaded', unlockComposeEditor);
    document.addEventListener('click', function (event) {
        if (event.target && event.target.closest && event.target.closest('#compEditor, [data-mode="manual"], .comp-mode-manual, button')) {
            setTimeout(unlockComposeEditor, 0);
        }
    });
})();
</script>
JS;
$stamp=date('Ymd-His');
if(!copy($file,$file.'.bak-editor-unlock-'.$stamp))exit("ABORTED: backup failed. no files changed.\n");
file_put_contents($file,$s.$script);
echo "UPDATED: compose editor is forced editable\n";
echo "BACKUP_SUFFIX: .bak-editor-unlock-$stamp\n";
echo "Delete checkmodel.php now.\n";
