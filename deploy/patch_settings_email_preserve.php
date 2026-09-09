<?php
/** Non-destructive Settings/Email repair. Preserves all existing Settings tabs and fields. */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$root='/home/adapharm/erp';
function sayx($s){echo $s."\n";}
function backupx($p){$b=$p.'.bak-preserve-'.date('Ymd-His'); @copy($p,$b); sayx('Backup: '.basename($b));}
$view=$root.'/app/views/settings/index.php'; $ctl=$root.'/app/controllers/SettingsController.php';
if(!is_file($view)||!is_file($ctl)){sayx('Missing Settings source file');exit;}
backupx($view); backupx($ctl);
$v=file_get_contents($view);
/* Remove the accidentally injected Bootstrap block and repair the original form action. */
$v=preg_replace('/<form method="POST" action="<\?= APP_URL \?>\s*.*?settings\/update">/s','<form method="POST" action="<?= APP_URL ?>settings/update">',$v,1);
/* Remove the injected email tab pane if it exists; we add a clean standalone section below. */
$v=preg_replace('/\s*<div class="tab-pane fade" id="email-pane".*?\n<\/div>\s*\n<\/div>\s*\n<\/form>\s*\n<\/div>/s','',$v,1);
/* Add an Email tab to the existing navigation, without removing any existing tabs. */
if(strpos($v,'href="?tab=email"')===false){
 $anchor='<a href="?tab=reports" class="st-tab <?= $activeTab === \'reports\' ? \'active\' : \'\' ?>">📊 Reports</a>';
 $v=str_replace($anchor,$anchor.'\n        <a href="?tab=email" class="st-tab <?= $activeTab === \'email\' ? \'active\' : \'\' ?>">✉ Email</a>',$v);
}
/* Add clean Email section immediately before the existing st-body closing area. */
if(strpos($v,'id="st-email-section"')===false){
 $email=<<<'HTML'

        <!-- EMAIL TAB: added non-destructively; all original tabs remain intact -->
        <div id="st-email-section" class="st-section <?= $activeTab === 'email' ? 'active' : '' ?>">
            <?php
            $emailSyncEnabled = '0';
            try {
                $edb = Database::getInstance()->getConnection();
                $eq = $edb->prepare("SELECT `value` FROM settings WHERE `key`='email_sync_enabled' LIMIT 1");
                $eq->execute(); $emailSyncEnabled = (string)($eq->fetchColumn() ?: '0');
            } catch (Throwable $ignore) {}
            ?>
            <form method="POST" action="<?= APP_URL ?>settings/update">
                <?= Auth::csrfField() ?><input type="hidden" name="section" value="email">
                <div class="st-card">
                    <div class="st-card-title">Email Receiving</div>
                    <label style="display:flex;align-items:center;gap:12px;cursor:pointer;font-size:13px;font-weight:700">
                        <input type="checkbox" name="email_sync_enabled" value="1" <?= $emailSyncEnabled === '1' ? 'checked' : '' ?> style="width:20px;height:20px">
                        Enable inbound email receiving
                    </label>
                    <div class="muted" style="margin-top:8px">Controls scheduled IMAP receiving for active email accounts. SMTP sending remains available separately.</div>
                </div>
                <div class="st-save-bar"><button type="submit" class="st-btn st-btn-primary">Save Email Settings</button></div>
            </form>
        </div>
HTML;
 $needle=<<<'END'
    </div>
</div>
END;
 $pos=strrpos($v,$needle); if($pos!==false)$v=substr($v,0,$pos).$email.substr($v,$pos);
}
file_put_contents($view,$v); sayx('Settings view repaired; existing tabs preserved.');
/* Add controller handler for section=email, if absent. */
$c=file_get_contents($ctl);
if(strpos($c,"case 'email':")===false){
 $needle=<<<'N'
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
 $c=str_replace($needle,$rep,$c);
}
if(strpos($c,'function updateEmail')===false){
 $method=<<<'M'

    private function updateEmail(): void
    {
        $enabled = isset($_POST['email_sync_enabled']) ? '1' : '0';
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare("INSERT INTO settings (`key`,`value`,`created_at`,`updated_at`) VALUES ('email_sync_enabled',?,NOW(),NOW()) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`),`updated_at`=NOW()");
            $st->execute([$enabled]);
            Helper::flash('success', 'Email receiving settings saved successfully.');
        } catch (Throwable $e) { Helper::flash('error', 'Email settings could not be saved.'); }
        $this->redirect('settings?tab=email');
    }
M;
 $c=str_replace("    /**\n     * Upload logo",$method."\n    /**\n     * Upload logo",$c);
}
file_put_contents($ctl,$c); sayx('Settings controller repaired.');
sayx('DONE — delete checkmodel.php now.');
