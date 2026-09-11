<?php
/** Surgical persistence repair for existing Settings forms. */
const TOKEN='MyStrongPass_2026_xyz';
header('Content-Type:text/plain; charset=utf-8');
if(!isset($_GET['token'])||!hash_equals(TOKEN,(string)$_GET['token'])){http_response_code(403);exit("403\n");}
$root='/home/adapharm/erp'; $view=$root.'/app/views/settings/index.php'; $controller=$root.'/app/controllers/SettingsController.php';
if(!is_file($view)||!is_file($controller))exit("ERROR: required file missing\n");
$v=file_get_contents($view); $c=file_get_contents($controller);
// Reports must have its own section; replace only the second defaults marker.
$needle='                <input type="hidden" name="section" value="defaults">';
$first=strpos($v,$needle); $second=$first===false?false:strpos($v,$needle,$first+strlen($needle));
if($second===false)exit("ABORTED: expected two defaults section markers. no files changed.\n");
$v=substr($v,0,$second).str_replace('value="defaults"','value="reports"',$needle).substr($v,$second+strlen($needle));
// Company identity: persist the existing bank_details textarea too.
$old = <<<'IDENTITY'
            'tax_number'   => $this->post('tax_number', ''),
IDENTITY;
$new=$old.'            \'bank_details\' => $this->post(\'bank_details\', \'\'),' . PHP_EOL;
if(substr_count($c,$old)!==1)exit("ABORTED: identity anchor not found. no files changed.\n");
$c=str_replace($old,$new,$c);
// Documents: persist the fields already present in the restored form.
$old = <<<'DEFAULTS'
            'default_notes'    => $this->post('default_notes', ''),
DEFAULTS;
$new=$old . <<<'DOCFIELDS'
            'quotation_prefix' => strtoupper(trim($this->post('quotation_prefix', 'QTN'))),
            'quotation_counter' => (int)$this->post('quotation_counter', 0),
            'invoice_prefix' => strtoupper(trim($this->post('invoice_prefix', 'INV'))),
            'invoice_counter' => (int)$this->post('invoice_counter', 0),
DOCFIELDS;
if(substr_count($c,$old)!==1)exit("ABORTED: defaults anchor not found. no files changed.\n");
$c=str_replace($old,$new,$c);
// Add reports dispatch.
$old=<<<'SWITCH'
            case 'defaults':
                $this->updateDefaults();
                break;
SWITCH;
$new=$old.<<<'SWITCHADD'
            case 'reports':
                $this->updateReports();
                break;
SWITCHADD;
if(substr_count($c,$old)!==1)exit("ABORTED: switch anchor not found. no files changed.\n");
$c=str_replace($old,$new,$c);
// Load report settings for the existing view.
$anchor=<<<'EMAILLOAD'
        $emailEnabled = '0';
EMAILLOAD;
$load=<<<'REPORTLOAD'
        $reportSettings = [];
        try {
            $db = Database::getInstance()->getConnection();
            $q = $db->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'report_%'");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $reportSettings[substr($row['key'], 7)] = $row['value'];
            }
        } catch (Throwable $e) {
            $reportSettings = [];
        }
REPORTLOAD;
if(substr_count($c,$anchor)!==1)exit("ABORTED: index email anchor not found. no files changed.\n");
$c=str_replace($anchor,$load."\n".$anchor,$c);
// Add report handler before the existing email handler.
$anchor=<<<'EMAILMETHOD'
    /** Save the global inbound email sync switch. */
EMAILMETHOD;
$method=<<<'REPORTMETHOD'
    /** Save report settings in the existing settings table. */
    private function updateReports(): void
    {
        $values = [
            'base_currency' => $this->post('base_currency', 'EUR'),
            'fiscal_year_start' => $this->post('fiscal_year_start', '01-01'),
            'auto_send_monthly' => $this->post('auto_send_monthly', '0') === '1' ? '1' : '0',
            'send_day_of_month' => (string)max(1, min(28, (int)$this->post('send_day_of_month', 1))),
            'admin_emails' => $this->post('admin_emails', ''),
        ];
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare("INSERT INTO settings (`key`, `value`, `created_at`, `updated_at`) VALUES (?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = NOW()");
            foreach ($values as $key => $value) $st->execute(['report_'.$key, $value]);
            Helper::flash('success', 'Report settings updated successfully.');
        } catch (Throwable $e) {
            Helper::flash('error', 'Report settings could not be saved.');
        }
        $this->redirect('settings?tab=reports');
    }

REPORTMETHOD;
if(substr_count($c,$anchor)!==1)exit("ABORTED: email method anchor not found. no files changed.\n");
$c=str_replace($anchor,$method.$anchor,$c);
$stamp=date('Ymd-His');
if(!copy($view,$view.'.bak-persistence-'.$stamp)||!copy($controller,$controller.'.bak-persistence-'.$stamp))exit("ABORTED: backup failed. no live files changed.\n");
file_put_contents($view,$v); file_put_contents($controller,$c);
echo "UPDATED: Company bank, Documents, Reports persistence\n";
echo "PRESERVED: existing layout and tabs\n";
echo "BACKUP_SUFFIX: .bak-persistence-$stamp\n";
echo "Delete checkmodel.php now.\n";
