<?php
/** Read-only: prints only active Communications entrypoint methods. */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$file='/home/adapharm/erp/app/controllers/CommunicationController.php';
if(!is_file($file))exit("MISSING\n");
$s=file_get_contents($file);
foreach(['index','v3','compose','accounts'] as $name){
 $pos=preg_match('/(?:public|private|protected) function\s+'.preg_quote($name,'/').'\s*\([^)]*\)[^{]*\{/',$s,$m,PREG_OFFSET_CAPTURE)?$m[0][1]:false;
 echo "\n===== $name =====\n";
 if($pos===false){echo "NOT_FOUND\n";continue;}
 $start=$pos; $brace=strpos($s,'{',$start); $depth=0; $end=strlen($s);
 for($i=$brace;$i<strlen($s);$i++){if($s[$i]==='{')$depth++;elseif($s[$i]==='}'){$depth--;if($depth===0){$end=$i+1;break;}}}
 $chunk=substr($s,$start,$end-$start); if(strlen($chunk)>30000)$chunk=substr($chunk,0,30000)."\n...[TRUNCATED]...\n";
 echo $chunk."\n";
}
echo "\nDONE — read-only; delete checkmodel.php\n";
