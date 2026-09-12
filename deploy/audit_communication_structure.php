<?php
/** Read-only compact structure audit for Communications. */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$root='/home/adapharm/erp';
$files=[
 'app/controllers/CommunicationController.php',
 'app/views/communications/index.php',
 'app/views/communications/index_v3.php',
 'app/views/communications/accounts.php',
 'app/views/communications/compose.php',
 'app/views/communications/thread.php',
];
foreach($files as $rel){
 $p=$root.'/'.$rel;
 echo "\n===== $rel =====\n";
 if(!is_file($p)){echo "MISSING\n";continue;}
 echo 'BYTES='.filesize($p).' SHA256='.hash_file('sha256',$p)."\n";
 $lines=file($p);
 foreach($lines as $i=>$line){
  $trim=trim($line);
  if(preg_match('/(public|private|protected) function\s+|<form\b|</form>|<button\b|<input\b|<select\b|<textarea\b|action=|fetch\s*\(|ajax|XMLHttpRequest|href=.*(communication|email)|include|require|error|success|alert|modal|tab|account|compose|send|reply|forward|delete|archive|unread|folder|search)/i',$trim)){
   echo sprintf('%04d | %s',$i+1,$trim)."\n";
  }
 }
}
echo "\nREAD_ONLY_DONE — delete checkmodel.php\n";
