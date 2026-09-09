<?php
/**
 * ADA PHARMA ERP — settings/email page recon
 * Copy to /home/adapharm/erp/checkmodel.php, run with ?token=..., then delete.
 */
const TOKEN = 'MyStrongPass_2026_xyz';
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
if (!isset($_GET['token']) || !hash_equals(TOKEN, (string)$_GET['token'])) { http_response_code(403); echo "403 bad token\n"; exit; }
$root = '/home/adapharm/erp';
function sec($x){ echo "\n\n===== $x =====\n"; }
function safe($s){
    $s = preg_replace('/(password|pass|secret|token|oauth|access[_-]?token)\s*[=:]\s*[\'\"]?[^\s,;\'\"]+/i', '$1=[REDACTED]', $s);
    return $s;
}
function dump_file($rel, $cap=60000){
    global $root;
    $p=$root.'/'.$rel;
    if (!is_file($p)) { echo "MISSING $rel\n"; return; }
    $s=file_get_contents($p); if (strlen($s)>$cap) $s=substr($s,0,$cap)."\n...[TRUNCATED]...\n";
    echo "--- $rel ---\n".safe($s)."\n";
}
function grep_tree($needle){
    global $root;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS));
    foreach($it as $f){
        if(!$f->isFile() || strtolower($f->getExtension())!=='php') continue;
        $lines=@file($f->getPathname()); if(!$lines) continue;
        foreach($lines as $i=>$line){ if(preg_match($needle,$line)) echo str_replace($root.'/','',$f->getPathname()).':'.($i+1).': '.safe(trim($line))."\n"; }
    }
}
sec('FILES RELATED TO SETTINGS/EMAIL');
grep_tree('/settings|email|imap|smtp|mailbox|sync|company/i');
sec('LIKELY CONTROLLERS AND VIEWS');
foreach(array('app/controllers/SettingsController.php','app/controllers/Settings.php','app/views/settings/index.php','app/views/settings.php','app/views/settings/company.php','app/views/settings/email.php','app/views/settings/documents.php','app/views/layouts/main.php') as $f) dump_file($f,45000);
sec('ROUTING / FORM ACTIONS');
grep_tree('/settings\/update|settings_update|Save Company|Company Settings|form[^>]+action|Email Sync/i');
sec('DATABASE EMAIL-RELATED TABLES');
try{
 $pdo=new PDO('mysql:host=localhost;dbname=adapharma_erp;charset=utf8mb4','adapharma_erp','Mehdi1721Sayo3303',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
 foreach($pdo->query("SHOW TABLES") as $r){$t=array_values($r)[0]; if(preg_match('/email|mail|setting|company/i',$t)) echo "$t\n";}
}catch(Exception $e){echo 'DB ERR: '.get_class($e)."\n";}
echo "\n[DONE — delete checkmodel.php now]\n";
