<?php
/** Make index_v3.php the single canonical Communications page view. */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$file='/home/adapharm/erp/app/controllers/CommunicationController.php';
if(!is_file($file))exit("ERROR: controller not found\n");
$c=file_get_contents($file);
$old=<<<'OLD'
        require VIEW_PATH . (isset($_GET['ui']) && $_GET['ui'] === 'old' ? 'communications/index.php' : 'communications/index_v3.php');
OLD;
$new=<<<'NEW'
        require VIEW_PATH . 'communications/index_v3.php';
NEW;
if(strpos($c,$new)!==false){exit("ALREADY_CANONICAL: no files changed.\n");}
if(substr_count($c,$old)!==1){exit("ABORTED: expected legacy view selector not found exactly once. no files changed.\n");}
$stamp=date('Ymd-His');
if(!copy($file,$file.'.bak-v3-canonical-'.$stamp))exit("ABORTED: backup failed. no files changed.\n");
file_put_contents($file,str_replace($old,$new,$c));
echo "UPDATED: index_v3.php is now the canonical /communication view\n";
echo "PRESERVED: old index.php as an untouched backup/source file\n";
echo "NOTE: ui=old no longer selects the legacy view\n";
echo "BACKUP_SUFFIX: .bak-v3-canonical-$stamp\n";
echo "Delete checkmodel.php now.\n";
