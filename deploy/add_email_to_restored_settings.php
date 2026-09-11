<?php
/** Surgical Email-tab addition to the verified restored Settings files. */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$root='/home/adapharm/erp';
$view=$root.'/app/views/settings/index.php';
$controller=$root.'/app/controllers/SettingsController.php';
if(!is_file($view)||!is_file($controller)){exit("ERROR: required file missing\n");}
$v=file_get_contents($view); $c=file_get_contents($controller);
if(strpos($v,'href="?tab=email"')!==false || strpos($v,'name="email_sync_enabled"')!==false){exit("ABORTED: Email markup already exists; no files changed.\n");}
if(strpos($c,'private function updateEmail(): void')!==false){exit("ABORTED: updateEmail already exists; no files changed.\n");}
$tabAnchor = <<<'TABANCHOR'
        <a href="?tab=reports" class="st-tab <?= $activeTab === 'reports' ? 'active' : '' ?>">📊 Reports</a>
TABANCHOR;
$tabAdd = $tabAnchor . "\n" . <<<'TAB'
        <a href="?tab=email" class="st-tab <?= $activeTab === 'email' ? 'active' : '' ?>">✉️ Email</a>
TAB;
if(substr_count($v,$tabAnchor)!==1){exit("ABORTED: Reports tab anchor not found exactly once. no files changed.\n");}
$viewEnd="    </div>\n</div>";
$emailSection=<<<'HTML'

        <!-- EMAIL TAB -->
        <div class="st-section <?= $activeTab === 'email' ? 'active' : '' ?>">
            <form method="POST" action="<?= APP_URL ?>settings/update">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="section" value="email">

                <div class="st-card">
                    <div class="st-card-title">✉️ Email Receiving</div>
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:20px;padding:16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">
                        <div>
                            <strong>Enable inbound email receiving</strong>
                            <div style="color:#6b7280;font-size:12px;margin-top:6px;">Enables scheduled IMAP sync to receive unread messages from active email accounts.</div>
                        </div>
                        <label style="display:flex;align-items:center;gap:8px;white-space:nowrap;">
                            <input type="checkbox" name="email_sync_enabled" value="1" <?= ($emailEnabled ?? '0') === '1' ? 'checked' : '' ?>>
                            <span>Enabled</span>
                        </label>
                    </div>
                    <div style="color:#6b7280;font-size:12px;margin-top:12px;">Configure IMAP and SMTP credentials in Communications → Email Accounts.</div>
                </div>

                <div class="st-save-bar">
                    <button type="submit" class="st-btn st-btn-primary">💾 Save Email Settings</button>
                </div>
            </form>
        </div>
HTML;
if(substr_count($v,$viewEnd)!==1){exit("ABORTED: view end anchor not found exactly once. no files changed.\n");}
$indexAnchor = <<<'INDEXANCHOR'
        unset($_SESSION['form_errors']);
INDEXANCHOR;
$indexAdd = $indexAnchor . <<<'INDEX'

        $emailEnabled = '0';
        try {
            $db = Database::getInstance()->getConnection();
            $q = $db->prepare("SELECT `value` FROM settings WHERE `key` = 'email_sync_enabled' LIMIT 1");
            $q->execute();
            $emailEnabled = (string)($q->fetchColumn() ?: '0');
        } catch (Throwable $e) {
            $emailEnabled = '0';
        }
INDEX;
if(substr_count($c,$indexAnchor)!==1){exit("ABORTED: controller index anchor not found exactly once. no files changed.\n");}
$method=<<<'METHOD'

    /** Save the global inbound email sync switch. */
    private function updateEmail(): void
    {
        $enabled = isset($_POST['email_sync_enabled']) ? '1' : '0';

        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare("INSERT INTO settings (`key`, `value`, `created_at`, `updated_at`) VALUES ('email_sync_enabled', ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = NOW()");
            $st->execute([$enabled]);
            Helper::flash('success', 'Email receiving settings saved successfully.');
        } catch (Throwable $e) {
            Helper::flash('error', 'Email settings could not be saved.');
        }

        $this->redirect('settings?tab=email');
    }
METHOD;
$methodAnchor = <<<'METHODANCHOR'
    /**
     * Upload logo
METHODANCHOR;
if(substr_count($c,$methodAnchor)!==1){exit("ABORTED: controller method anchor not found exactly once. no files changed.\n");}
$newV=str_replace($tabAnchor,$tabAdd,$v);
$newV=str_replace($viewEnd,$emailSection."\n\n".$viewEnd,$newV);
$newC=str_replace($indexAnchor,$indexAdd,$c);
$newC=str_replace($methodAnchor,$method.$methodAnchor,$newC);
if($newV===$v||$newC===$c){exit("ABORTED: no complete change produced. no files changed.\n");}
$stamp=date('Ymd-His');
if(!copy($view,$view.'.bak-email-surgical-'.$stamp)||!copy($controller,$controller.'.bak-email-surgical-'.$stamp)){exit("ABORTED: could not create backups. no live files changed.\n");}
file_put_contents($view,$newV); file_put_contents($controller,$newC);
echo "UPDATED: Settings view and controller\n";
echo "PRESERVED: Company, Documents, Branding, Reports\n";
echo "ADDED: Email tab, CSRF form, section=email, 1/0 persistence, redirect settings?tab=email\n";
echo "BACKUP_SUFFIX: .bak-email-surgical-$stamp\n";
echo "Delete checkmodel.php now.\n";
