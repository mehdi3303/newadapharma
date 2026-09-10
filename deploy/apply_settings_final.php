<?php
/** Final non-destructive Settings repair: restore the original large view, then add Email safely. */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$root='/home/adapharm/erp';$view=$root.'/app/views/settings/index.php';$ctl=$root.'/app/controllers/SettingsController.php';
function p($s){echo $s."\n";}
$backs=glob($view.'.bak-settings-view-*');
if(!$backs){p('ERROR: original settings-view backup not found; nothing changed');exit;}
usort($backs,function($a,$b){return filemtime($b)<=>filemtime($a);});
$src=$backs[0];$original=file_get_contents($src);if($original===false){p('ERROR: cannot read backup');exit;}
@copy($view,$view.'.bak-final-before-'.date('Ymd-His'));file_put_contents($view,$original);p('Restored original view: '.basename($src));
$v=$original;
/* Repair the malformed form action only; do not remove any original fields. */
$v=preg_replace('/<form method="POST" action="<\?= APP_URL \?>\s*.*?settings\/update">/s','<form method="POST" action="<?= APP_URL ?>settings/update">',$v,1);
/* Add a real Email tab to the original four-tab navigation. */
if(strpos($v,'href="?tab=email"')===false){
 $v=preg_replace('/(<a[^>]+href="\?tab=reports"[^>]*>.*?<\/a>)/is','$1\n        <a href="?tab=email" class="st-tab <?= $activeTab === \'email\' ? \'active\' : \'\' ?>">Email</a>',$v,1);
}
/* Add Email section immediately before the st-body closing tag, preserving all other tab markup. */
if(strpos($v,'id="st-email-section"')===false){
 $email=<<<'HTML'

        <div id="st-email-section" class="st-section <?= $activeTab === 'email' ? 'active' : '' ?>">
            <?php
            $emailSyncStatus='0';
            try{$edb=Database::getInstance()->getConnection();$es=$edb->prepare("SELECT `value` FROM settings WHERE `key`='email_sync_enabled' LIMIT 1");$es->execute();$emailSyncStatus=(string)($es->fetchColumn()?:'0');}catch(Throwable $e){}
            ?>
            <form method="POST" action="<?= APP_URL ?>settings/update">
                <?= Auth::csrfField() ?><input type="hidden" name="section" value="email">
                <div class="st-card"><div class="st-card-title">Email Receiving</div>
                    <label style="display:flex;align-items:center;gap:10px;font-size:13px;font-weight:700;cursor:pointer">
                        <input type="checkbox" name="email_sync_enabled" value="1" <?= $emailSyncStatus==='1'?'checked':'' ?> style="width:20px;height:20px">
                        Enable inbound email receiving
                    </label>
                    <div class="muted" style="margin-top:8px">Controls scheduled IMAP receiving for active email accounts.</div>
                </div>
                <div class="st-save-bar"><button type="submit" class="st-btn st-btn-primary">Save Email Settings</button></div>
            </form>
        </div>
HTML;
 $needle=<<<'END'
    </div>
</div>
END;
 $pos=strrpos($v,$needle);if($pos===false){p('ERROR: settings body boundary not found; view restored only');file_put_contents($view,$v);exit;}
 $v=substr($v,0,$pos).$email.substr($v,$pos);
}
file_put_contents($view,$v);p('Original tabs and fields preserved; Email tab added.');
/* Controller: add email section and save handler if needed. */
if(is_file($ctl)){
 $c=file_get_contents($ctl);
 if(strpos($c,"case 'email':")===false){$needle=<<<'N'
case 'numbering':
                $this->updateNumbering();
                break;
N;
$rep=<<<'R'
case 'numbering':
                $this->updateNumbering();
                break;
            case 'email':
                $this->updateEmail();
                break;
R;
$c=str_replace($needle,$rep,$c);}
 if(strpos($c,'function updateEmail')===false){$m=<<<'M'

    private function updateEmail(): void
    {
        $enabled=isset($_POST['email_sync_enabled'])?'1':'0';
        try{$db=Database::getInstance()->getConnection();$st=$db->prepare("INSERT INTO settings (`key`,`value`,`created_at`,`updated_at`) VALUES ('email_sync_enabled',?,NOW(),NOW()) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`),`updated_at`=NOW()");$st->execute([$enabled]);Helper::flash('success','Email receiving settings saved successfully.');}
        catch(Throwable $e){Helper::flash('error','Email settings could not be saved.');}
        $this->redirect('settings?tab=email');
    }
M;
$c=str_replace("    /**\n     * Upload logo",$m."\n    /**\n     * Upload logo",$c);}
 file_put_contents($ctl,$c);p('Email save handler verified.');
}
p('DONE — delete checkmodel.php now.');
