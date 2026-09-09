<?php
/** ADA PHARMA ERP — repair Settings page and email receiving switch.
 * Copy to /home/adapharm/erp/checkmodel.php, run once with ?token=..., then delete.
 */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit('403\n');}
$root='/home/adapharm/erp';
function okx($s){echo "✅ $s\n";} function errx($s){echo "❌ $s\n";}
function putx($p,$s){if(file_put_contents($p,$s)!==false)okx('written '.basename($p));else errx('cannot write '.basename($p));}
$controller=$root.'/app/controllers/SettingsController.php';
if(is_file($controller)){
 $c=file_get_contents($controller);
 if(strpos($c,"case 'email':")===false){
  $needle=<<<'N'
case 'numbering':
                $this->updateNumbering();
                break;
N;
  $replacement=<<<'R'
case 'numbering':
                $this->updateNumbering();
                break;
            case 'email':
                $this->updateEmail();
                break;
R;
  $c=str_replace($needle,$replacement,$c);
 }
 if(strpos($c,'function updateEmail')===false){
  $method=<<<'METHOD'

    /** Save the global inbound email sync switch. */
    private function updateEmail(): void
    {
        $enabled = isset($_POST['email_sync_enabled']) ? '1' : '0';
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare("INSERT INTO settings (`key`,`value`,`created_at`,`updated_at`) VALUES ('email_sync_enabled',?,NOW(),NOW()) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`),`updated_at`=NOW()");
            $st->execute([$enabled]);
            Helper::flash('success', 'Email receiving settings saved successfully.');
        } catch (Throwable $e) {
            Helper::flash('error', 'Email settings could not be saved.');
        }
        $this->redirect('settings?tab=email');
    }
METHOD;
  $c=str_replace("    /**\n     * Upload logo",$method."\n    /**\n     * Upload logo",$c);
 }
 /* Enforce the same switch when a manual/cron sync endpoint is called. */
$c2=$c;
$guard=<<<'GUARD'
        $db=Database::getInstance()->getConnection();
        $flag=$db->query("SELECT `value` FROM settings WHERE `key`='email_sync_enabled' LIMIT 1")->fetchColumn();
        if ($flag !== '1') { echo json_encode(['ok'=>false,'disabled'=>true,'message'=>'Inbound email receiving is disabled.']); return; }
GUARD;
if (strpos($c2, "public function syncNow(): void") !== false && strpos($c2, "Inbound email receiving is disabled") === false) {
    $c2=preg_replace('/(public function syncNow\(\): void\s*\{)/', '$1\n'.$guard, $c2, 1);
}
putx($controller.'.bak-settings-'.date('Ymd-His'),$c2); putx($controller,$c2);
}else errx('SettingsController.php missing');
$view=<<<'VIEW'
<?php
$company=$company??[]; $activeTab=$_GET['tab']??'company';
$get=fn($f,$d='')=>$company[$f]??$d;
$emailEnabled='0';
try{$db=Database::getInstance()->getConnection();$q=$db->prepare("SELECT `value` FROM settings WHERE `key`='email_sync_enabled' LIMIT 1");$q->execute();$emailEnabled=(string)($q->fetchColumn()?:'0');}catch(Throwable $e){}
?>
<style>
.st-wrap{max-width:1120px;margin:24px auto;padding:0 18px;color:#172033}.st-head{background:#fff;border:1px solid #e5e7eb;border-radius:14px 14px 0 0;padding:24px 28px}.st-head h1{margin:0;font-size:24px}.st-sub{margin-top:6px;color:#6b7280;font-size:13px}.st-tabs{display:flex;gap:4px;flex-wrap:wrap;background:#fff;border:1px solid #e5e7eb;border-top:0;padding:0 18px}.st-tab{padding:14px 18px;color:#64748b;text-decoration:none;font-size:13px;font-weight:700;border-bottom:3px solid transparent}.st-tab:hover{color:#2563eb}.st-tab.active{color:#2563eb;border-bottom-color:#2563eb}.st-body{background:#fff;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 14px 14px;padding:24px 28px}.st-card{border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:18px;background:#fff}.st-title{font-size:15px;font-weight:800;margin:0 0 16px}.st-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.st-field label{display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:6px}.st-field input,.st-field textarea,.st-field select{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:8px;padding:10px 12px;font:inherit;font-size:13px}.st-field textarea{min-height:100px;resize:vertical}.st-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px;padding-top:16px;border-top:1px solid #eef1f5}.st-btn{border:0;border-radius:8px;padding:10px 18px;font-weight:700;cursor:pointer}.st-primary{background:#2563eb;color:white}.st-muted{background:#f1f5f9;color:#334155}.st-switch-row{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px}.st-switch{width:48px;height:26px;position:relative}.st-switch input{opacity:0;width:0;height:0}.st-slider{position:absolute;inset:0;background:#94a3b8;border-radius:99px;cursor:pointer}.st-slider:before{content:"";position:absolute;width:20px;height:20px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}.st-switch input:checked+.st-slider{background:#16a34a}.st-switch input:checked+.st-slider:before{transform:translateX(22px)}.st-note{color:#64748b;font-size:12px;line-height:1.6;margin-top:6px}@media(max-width:700px){.st-grid{grid-template-columns:1fr}.st-wrap{padding:0 10px;margin:10px auto}.st-body,.st-head{padding:16px}.st-tab{padding:12px 10px}}
</style>
<div class="st-wrap">
 <div class="st-head"><h1>Settings</h1><div class="st-sub">Manage company, document, branding, report, and email preferences.</div></div>
 <nav class="st-tabs">
  <a class="st-tab <?= $activeTab==='company'?'active':'' ?>" href="<?= APP_URL ?>settings?tab=company">Company</a>
  <a class="st-tab <?= $activeTab==='documents'?'active':'' ?>" href="<?= APP_URL ?>settings?tab=documents">Documents</a>
  <a class="st-tab <?= $activeTab==='branding'?'active':'' ?>" href="<?= APP_URL ?>settings?tab=branding">Branding</a>
  <a class="st-tab <?= $activeTab==='reports'?'active':'' ?>" href="<?= APP_URL ?>settings?tab=reports">Reports</a>
  <a class="st-tab <?= $activeTab==='email'?'active':'' ?>" href="<?= APP_URL ?>settings?tab=email">Email</a>
 </nav>
 <main class="st-body">
 <?php if($activeTab==='company'): ?>
  <form method="post" action="<?= APP_URL ?>settings/update"><input type="hidden" name="section" value="identity"><?= Auth::csrfField() ?>
   <div class="st-card"><h2 class="st-title">Company Information</h2><div class="st-grid">
    <div class="st-field"><label>Display Name *</label><input name="display_name" required value="<?= Helper::e($get('display_name')) ?>"></div>
    <div class="st-field"><label>Legal Name</label><input name="legal_name" value="<?= Helper::e($get('legal_name')) ?>"></div>
    <div class="st-field"><label>Email</label><input type="email" name="email" value="<?= Helper::e($get('email')) ?>"></div>
    <div class="st-field"><label>Phone</label><input name="phone" value="<?= Helper::e($get('phone')) ?>"></div>
    <div class="st-field"><label>Website</label><input type="url" name="website" value="<?= Helper::e($get('website')) ?>"></div>
    <div class="st-field"><label>Tax Number / VAT</label><input name="tax_number" value="<?= Helper::e($get('tax_number')) ?>"></div>
   </div><div class="st-field" style="margin-top:16px"><label>Address</label><textarea name="address"><?= Helper::e($get('address')) ?></textarea></div></div>
   <div class="st-actions"><button class="st-btn st-primary" type="submit">Save Company Settings</button></div>
  </form>
 <?php elseif($activeTab==='email'): ?>
  <form method="post" action="<?= APP_URL ?>settings/update"><input type="hidden" name="section" value="email"><?= Auth::csrfField() ?>
   <div class="st-card"><h2 class="st-title">Email Receiving</h2><div class="st-switch-row"><div><strong>Enable inbound email receiving</strong><div class="st-note">When enabled, the scheduled IMAP sync can receive unread messages from active email accounts.</div></div><label class="st-switch"><input type="checkbox" name="email_sync_enabled" value="1" <?= $emailEnabled==='1'?'checked':'' ?>><span class="st-slider"></span></label></div><div class="st-note" style="margin-top:14px">Configure IMAP and SMTP accounts in Communications → Email Accounts.</div></div><div class="st-actions"><button class="st-btn st-primary" type="submit">Save Email Settings</button></div>
  </form>
 <?php else: ?><div class="st-card"><h2 class="st-title"><?= ucfirst($activeTab) ?></h2><div class="st-note">Use the existing <?= ucfirst($activeTab) ?> tools below or select Company / Email to edit settings.</div></div><?php endif; ?>
 </main>
</div>
VIEW;
putx($root.'/app/views/settings/index.php.bak-settings-'.date('Ymd-His'),file_get_contents($root.'/app/views/settings/index.php'));
putx($root.'/app/views/settings/index.php',$view);
echo "DONE — delete checkmodel.php\n";
