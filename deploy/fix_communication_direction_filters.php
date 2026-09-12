<?php
/** Fix needsreply/awaiting filters using inbound/outbound message direction. */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$root='/home/adapharm/erp';
$controller=$root.'/app/controllers/CommunicationController.php';
$model=$root.'/app/models/CommunicationThreadModel.php';
if(!is_file($controller)||!is_file($model))exit("ERROR: required file missing\n");
$c=file_get_contents($controller); $m=file_get_contents($model);
$old="return (\$t['last_message_from'] ?? '') === \$directionFilter;";
$new="return (\$t['latest_direction'] ?? '') === (\$directionFilter === 'them' ? 'inbound' : 'outbound');";
$count=substr_count($c,$old);
if($count===0)exit("ABORTED: controller direction filter not found. no files changed.\n");
$modelOld=<<<'MODEL'
                latest.from_address AS latest_from,
MODEL;
$modelNew=<<<'MODELNEW'
                latest.from_address AS latest_from,
                latest.direction AS latest_direction,
MODELNEW;
if(strpos($m,'latest.direction AS latest_direction')===false && substr_count($m,$modelOld)!==1)exit("ABORTED: model select anchor not found. no files changed.\n");
$subOld=<<<'SUB'
SELECT thread_id, ai_intent, ai_sentiment, ai_summary, from_address, subject, body_text,
SUB;
$subNew=<<<'SUBNEW'
SELECT thread_id, ai_intent, ai_sentiment, ai_summary, from_address, direction, subject, body_text,
SUBNEW;
if(strpos($m,'latest.direction AS latest_direction')===false && substr_count($m,$subOld)!==1)exit("ABORTED: model latest subquery anchor not found. no files changed.\n");
$stamp=date('Ymd-His');
if(!copy($controller,$controller.'.bak-direction-'.$stamp)||!copy($model,$model.'.bak-direction-'.$stamp))exit("ABORTED: backup failed. no files changed.\n");
$c=str_replace($old,$new,$c);
if(strpos($m,'latest.direction AS latest_direction')===false){$m=str_replace($modelOld,$modelNew,$m);$m=str_replace($subOld,$subNew,$m);}
file_put_contents($controller,$c); file_put_contents($model,$m);
echo "UPDATED: needsreply/awaiting direction filters\n";
echo "CHANGED_CONTROLLER_FILTERS: $count\n";
echo "ADDED_MODEL_FIELD: latest_direction\n";
echo "BACKUP_SUFFIX: .bak-direction-$stamp\n";
echo "Delete checkmodel.php now.\n";
