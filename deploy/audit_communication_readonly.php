<?php
/** Read-only audit of Communications page files. No files are changed. */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$root='/home/adapharm/erp';
function section($title){echo "\n===== $title =====\n";}
function redact($s){return preg_replace('/(password|passwd|secret|token|api[_-]?key)\s*[=:]\s*[\'\"]?[^\s,;\'\"]+/i','$1=[REDACTED]',$s);}
function dumpFile($path,$cap=50000){
    if(!is_file($path)){echo "MISSING: $path\n";return;}
    $s=file_get_contents($path); if(strlen($s)>$cap)$s=substr($s,0,$cap)."\n...[TRUNCATED]...\n";
    echo "--- $path (".filesize($path)." bytes) ---\n".redact($s)."\n";
}
$matches=[];
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app',FilesystemIterator::SKIP_DOTS));
foreach($it as $f){
    if(!$f->isFile())continue;
    $p=$f->getPathname();
    if(preg_match('/communication|communications|email|mail/i',$p))$matches[]=$p;
}
section('RELATED FILES');
foreach($matches as $p)echo str_replace($root.'/','',$p)."\n";
section('RELATED SOURCE');
foreach($matches as $p){if(preg_match('/\.(php|js|css|html)$/i',$p))dumpFile($p,35000);}
section('ROUTES AND REFERENCES');
foreach([$root.'/app',$root.'/routes',$root.'/config'] as $dir){
    if(!is_dir($dir))continue;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));
    foreach($it as $f){
        if(!$f->isFile()||!preg_match('/\.(php|js|json)$/i',$f->getFilename()))continue;
        $lines=@file($f->getPathname()); if(!$lines)continue;
        foreach($lines as $n=>$line){
            if(preg_match('/communication|communications|email|mail|imap|smtp/i',$line))echo str_replace($root.'/','',$f->getPathname()).':'.($n+1).': '.redact(trim($line))."\n";
        }
    }
}
echo "\nREAD_ONLY_DONE — delete checkmodel.php\n";
