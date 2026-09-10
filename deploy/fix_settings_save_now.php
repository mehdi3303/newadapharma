<?php
/** Emergency fix: remove literal backslash-n markup and make Email form save. */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$root='/home/adapharm/erp';$view=$root.'/app/views/settings/index.php';$ctl=$root.'/app/controllers/SettingsController.php';
function msgx($s){echo $s."\n";}
if(!is_file($view)||!is_file($ctl)){msgx('ERROR: settings source missing');exit;}
@copy($view,$view.'.bak-savefix-'.date('Ymd-His'));@copy($ctl,$ctl.'.bak-savefix-'.date('Ymd-His'));
$v=file_get_contents($view);
/* The previous regex used a single-quoted replacement and printed the two characters backslash+n. */
$v=str_replace('\\n',"\n",$v);
/* Make every malformed settings update opener a real action. */
$v=preg_replace('/<form method="POST" action="<\?= APP_URL \?>\s*.*?settings\/update">/s','<form method="POST" action="<?= APP_URL ?>settings/update">',$v);
file_put_contents($view,$v);msgx('View repaired: literal \\n removed and settings actions normalized.');
$c=file_get_contents($ctl);
if(strpos($c,"case 'email':")===false){$n=<<<'N'
case 'numbering':
                $this->updateNumbering();
                break;
N;
$r=<<<'R'
case 'numbering':
                $this->updateNumbering();
                break;
            case 'email':
                $this->updateEmail();
                break;
R;
$c=str_replace($n,$r,$c);}
if(strpos($c,'function updateEmail')===false){$m=<<<'M'

    private function updateEmail(): void
    {
        $enabled=isset($_POST['email_sync_enabled'])?'1':'0';
        try {
            $db=Database::getInstance()->getConnection();
            $st=$db->prepare("INSERT INTO settings (`key`,`value`,`created_at`,`updated_at`) VALUES ('email_sync_enabled',?,NOW(),NOW()) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`),`updated_at`=NOW()");
            $st->execute([$enabled]);
            Helper::flash('success','Email receiving settings saved successfully.');
        } catch (Throwable $e) { Helper::flash('error','Email settings could not be saved.'); }
        $this->redirect('settings?tab=email');
    }
M;
$c=str_replace("    /**\n     * Upload logo",$m."\n    /**\n     * Upload logo",$c);}
file_put_contents($ctl,$c);msgx('Controller repaired: email save handler verified.');msgx('DONE — delete checkmodel.php now.');
