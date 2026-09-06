<?php
/**
 * ============================================================================
 * ADA PHARMA ERP — ماژول مدیریت چک (Cheque Management) نسخه 1.0  (Level 1)
 * ============================================================================
 * محل استقرار: /home/adapharm/erp/cheques.php  (کنار index.php)
 * دسترسی:       https://erp.adapharmaco.com/cheques.php  (بعد از لاگین ERP)
 *
 * امکانات:
 *   - چک دریافتی / صادره × چک پرداختی / تضمینی (وثیقه)
 *   - شناسه صیاد ۱۶ رقمی با یکتایی‌سنجی، دفترچه چک صادره با شماره بعدی خودکار
 *   - ماشین وضعیت قفل‌شده + Event Sourcing (لجر رویدادها، append-only)
 *   - زنجیره Hash روی رویدادها (مقاوم در برابر دستکاری)
 *   - Maker–Checker: رویدادهای مالی حساس نیاز به تأیید کاربر دوم دارند
 *   - انتقال/خرج چک (Endorsement) با ثبت ذی‌نفع
 *   - اسکن پشت/رو، تقویم سررسید شمسی، Aging چک دریافتی، هشدار کسری موجودی
 *
 * این فایل کاملاً مستقل است (اتصال PDO داخلی) تا با هر نسخه از فریم‌ورک کار کند؛
 * بعد از دریافت اسنپ‌شات کد، به‌صورت MVC بومی (Model/Controller/View) بازآرایی می‌شود.
 * ============================================================================
 */

session_start();

const DB_HOST = 'localhost';
const DB_NAME = 'adapharm_erp';
const DB_USER = 'adapharm_erp';
const DB_PASS = 'Mehdi1721Sayo3303';

date_default_timezone_set('Asia/Tehran');

/* ------------------------------------------------------------------ اتصال */
function db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
        );
    }
    return $pdo;
}

/* ------------------------------------------------------------------ کاربر */
function current_user() {
    $keys = array('user_id', 'uid', 'id');
    $id   = null;
    foreach ($keys as $k) { if (!empty($_SESSION[$k]) && is_numeric($_SESSION[$k])) { $id = (int)$_SESSION[$k]; break; } }
    if ($id === null && !empty($_SESSION['user']['id']))    { $id = (int)$_SESSION['user']['id']; }
    if ($id === null && !empty($_SESSION['auth']['id']))    { $id = (int)$_SESSION['auth']['id']; }
    if ($id === null && !empty($_SESSION['user_id']))       { $id = (int)$_SESSION['user_id']; }

    $name = null;
    foreach (array('user_name', 'name', 'full_name', 'username', 'email') as $k) {
        if (!empty($_SESSION[$k]) && is_string($_SESSION[$k])) { $name = $_SESSION[$k]; break; }
    }
    if ($name === null && !empty($_SESSION['user']['name']))     { $name = $_SESSION['user']['name']; }
    if ($name === null && !empty($_SESSION['user']['username'])) { $name = $_SESSION['user']['username']; }
    if ($name === null && $id !== null) {
        try {
            $col = pick_col('users', array('name', 'full_name', 'username', 'email'));
            if ($col) { $name = db()->query("SELECT `$col` FROM users WHERE id=" . (int)$id)->fetchColumn(); }
        } catch (Exception $e) { /* ignore */ }
    }
    $role = null;
    foreach (array('role', 'user_type', 'user_role') as $k) { if (!empty($_SESSION[$k])) { $role = (string)$_SESSION[$k]; break; } }
    if ($role === null && !empty($_SESSION['user']['role'])) { $role = (string)$_SESSION['user']['role']; }
    return array('id' => $id, 'name' => $name ?: ('کاربر #' . $id), 'role' => $role);
}

$me = current_user();
if (!$me['id']) {
    http_response_code(403);
    echo '<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><title>دسترسی غیرمجاز</title>'
       . '<style>body{font-family:Tahoma,sans-serif;background:#f4f6f9;text-align:center;padding:80px}'
       . '.box{background:#fff;border:1px solid #e3e6ea;border-radius:12px;padding:40px;max-width:480px;margin:auto}'
       . 'a{display:inline-block;margin-top:16px;background:#2563eb;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none}</style></head>'
       . '<body><div class="box"><h2>🔒 ابتدا وارد سیستم ERP شوید</h2>'
       . '<p>برای استفاده از مدیریت چک‌ها باید اول در erp.adapharmaco.com لاگین کرده باشید.</p>'
       . '<a href="./">ورود به ERP</a></div></body></html>';
    exit;
}

/* ------------------------------------------------------------------ CSRF */
if (empty($_SESSION['csrf_cheques'])) { $_SESSION['csrf_cheques'] = bin2hex(random_bytes(16)); }
function csrf_field() { return '<input type="hidden" name="csrf" value="' . $_SESSION['csrf_cheques'] . '">'; }
function csrf_check() {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf_cheques'], (string)$_POST['csrf'])) {
        die('درخواست نامعتبر (CSRF). صفحه را تازه کنید و دوباره تلاش کنید.');
    }
}

/* ------------------------------------------------------------------ کمکی‌ها */
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fa($s) { return str_replace(range(0, 9), array('۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'), (string)$s); }
function money($n) { return fa(number_format((float)$n)) . ' ریال'; }
function pick_col($table, $candidates) {
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table][implode(',', $candidates)] ?? null;
    try {
        $rows = db()->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA='" . DB_NAME . "' AND TABLE_NAME=" . db()->quote($table))
                    ->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $ex) { $rows = array(); }
    $cache[$table] = array();
    foreach ($candidates as $c) {
        if (in_array($c, $rows, true)) { $cache[$table][implode(',', $candidates)] = $c; return $c; }
    }
    $cache[$table][implode(',', $candidates)] = null;
    return null;
}
function q_all($sql, $params = array()) {
    $st = db()->prepare($sql); $st->execute($params); return $st->fetchAll();
}
function q_one($sql, $params = array()) {
    $st = db()->prepare($sql); $st->execute($params); return $st->fetch();
}

/* تبدیل میلادی به شمسی (الگوریتم استاندارد) */
function gregorian_to_jalali($gy, $gm, $gd) {
    $g_d_m = array(0,31,59,90,120,151,181,212,243,273,304,334);
    $gy2 = ($gy > 1600) ? $gy - 1600 : $gy;
    $gy3 = ($gy > 1600) ? 979 : 0;
    $days = 365*$gy2 + (int)(($gy2+3)/4) - (int)(($gy2+99)/100) + (int)(($gy2+399)/400) - 80
          + $gd + $g_d_m[$gm-1] + ($gm > 2 && (($gy%4==0 && $gy%100!=0) || $gy%400==0) ? 1 : 0);
    $jy = -1595 + 33*(int)($days/12053); $days %= 12053;
    $jy += 4*(int)($days/1461); $days %= 1461;
    if ($days > 365) { $jy += (int)(($days-1)/365); $days = ($days-1)%365; }
    $jm = ($days < 186) ? 1+(int)($days/31) : 7+(int)(($days-186)/30);
    $jd = 1 + (($days < 186) ? ($days%31) : (($days-186)%30));
    return array($jy+$gy3, $jm, $jd);
}
function jdate($gregorian) {
    if (!$gregorian || $gregorian === '0000-00-00') return '—';
    $t = strtotime($gregorian);
    if (!$t) return '—';
    list($jy, $jm, $jd) = gregorian_to_jalali((int)date('Y', $t), (int)date('n', $t), (int)date('j', $t));
    return fa($jy . '/' . str_pad($jm,2,'0',STR_PAD_LEFT) . '/' . str_pad($jd,2,'0',STR_PAD_LEFT));
}
$JA_MONTHS = array('','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند');
function jdate_long($gregorian) {
    global $JA_MONTHS;
    if (!$gregorian || $gregorian === '0000-00-00') return '—';
    $t = strtotime($gregorian); if (!$t) return '—';
    list($jy, $jm, $jd) = gregorian_to_jalali((int)date('Y', $t), (int)date('n', $t), (int)date('j', $t));
    return fa($jd) . ' ' . $JA_MONTHS[$jm] . ' ' . fa($jy);
}
function days_until($gregorian) {
    if (!$gregorian || $gregorian === '0000-00-00') return null;
    $t = strtotime($gregorian . ' 00:00:00');
    return (int)round(($t - strtotime(date('Y-m-d') . ' 00:00:00')) / 86400);
}
function due_badge($gregorian) {
    $d = days_until($gregorian);
    if ($d === null) return '';
    if ($d < 0)  return '<span class="tag tag-red">سررسید گذشته (' . fa(-$d) . ' روز)</span>';
    if ($d == 0) return '<span class="tag tag-red">امروز سررسید</span>';
    if ($d <= 7) return '<span class="tag tag-orange">' . fa($d) . ' روز مانده</span>';
    if ($d <= 30) return '<span class="tag tag-yellow">' . fa($d) . ' روز مانده</span>';
    return '<span class="tag tag-green">' . fa($d) . ' روز مانده</span>';
}

/* ------------------------------------------------------------------ متادیتا */
$STATUS_META = array(
    'in_hand'   => array('در دست',        'blue'),
    'deposited' => array('سپرده‌شده',     'cyan'),
    'cleared'   => array('وصول شد',       'green'),
    'passed'    => array('پاس شد',        'green'),
    'bounced'   => array('برگشت خورد',    'red'),
    'endorsed'  => array('خرج/منتقل شد',  'purple'),
    'cancelled' => array('باطل',          'gray'),
    'replaced'  => array('جایگزین شد',    'gray'),
    'issued'    => array('صادر شد',       'blue'),
    'held'      => array('در وثیقه',      'orange'),
    'returned'  => array('مسترد شد',      'green'),
    'forfeited' => array('ضبط/اجرا شد',   'red'),
);
/* رویدادهای مجاز هر وضعیت: event_type => [برچسب، وضعیت مقصد، حساس؟] */
$TRANSITIONS = array(
    'received|payment' => array(
        'in_hand'   => array('deposit' => array('سپرده به بانک', 'deposited', false),
                             'endorse' => array('خرج/انتقال چک', 'endorsed', true),
                             'cancel'  => array('ابطال چک', 'cancelled', true),
                             'replace' => array('جایگزینی با چک جدید', 'replaced', true)),
        'deposited' => array('clear'   => array('ثبت وصول', 'cleared', true),
                             'bounce'  => array('ثبت برگشت', 'bounced', true)),
        'bounced'   => array('deposit' => array('سپرده مجدد', 'deposited', false),
                             'replace' => array('جایگزینی با چک جدید', 'replaced', true),
                             'cancel'  => array('ابطال چک', 'cancelled', true)),
    ),
    'issued|payment' => array(
        'issued'  => array('pass'    => array('ثبت پاس‌شدن (کسر از حساب)', 'passed', true),
                           'bounce'  => array('ثبت برگشت چک', 'bounced', true),
                           'cancel'  => array('ابطال چک', 'cancelled', true),
                           'replace' => array('جایگزینی/تمدید', 'replaced', true)),
        'bounced' => array('pass'    => array('پاس‌شدن پس از رفع برگشت', 'passed', true),
                           'replace' => array('جایگزینی/تمدید', 'replaced', true),
                           'cancel'  => array('ابطال چک', 'cancelled', true)),
    ),
    'received|guarantee' => array(
        'held' => array('g_return' => array('مسترد شدن وثیقه', 'returned', true),
                        'forfeit'  => array('ضبط/اجرا (وصول وثیقه)', 'forfeited', true)),
    ),
    'issued|guarantee' => array(
        'held' => array('g_return' => array('بازپس‌گیری وثیقه از طرف', 'returned', true),
                        'forfeit'  => array('ضبط وثیقه توسط طرف', 'forfeited', true)),
    ),
);
$TERMINAL = array('cleared','passed','cancelled','endorsed','replaced','returned','forfeited');
$SENSITIVE = array('clear','pass','bounce','endorse','cancel','forfeit','g_return','replace');

$EVENT_LABELS = array(
    'register' => 'ثبت اولیه', 'deposit' => 'سپرده به بانک', 'clear' => 'وصول',
    'bounce' => 'برگشت', 'endorse' => 'خرج/انتقال', 'cancel' => 'ابطال',
    'replace' => 'جایگزینی', 'pass' => 'پاس‌شدن', 'g_return' => 'استرداد وثیقه',
    'forfeit' => 'ضبط وثیقه', 'note' => 'یادداشت',
);

/* ------------------------------------------------------------------ آپلود */
function upload_cheque_file($field, $prefix) {
    if (empty($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) { flash('فایل ' . $field . ' بزرگ‌تر از ۵ مگابایت است.'); return null; }
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, array('jpg','jpeg','png','webp','pdf'), true)) { flash('فرمت فایل مجاز نیست (jpg/png/webp/pdf).'); return null; }
    $dir = __DIR__ . '/uploads/cheques';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $name = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $name)) return 'uploads/cheques/' . $name;
    return null;
}
function flash($msg) { $_SESSION['cheque_flash'][] = $msg; }
function get_flash() { $f = $_SESSION['cheque_flash'] ?? array(); unset($_SESSION['cheque_flash']); return $f; }

/* ------------------------------------------------------------------ لجر */
function record_event($checkId, $type, $eventDate, $amount = null, $bankRef = null, $note = null, $attachment = null, $forceApproved = false) {
    global $SENSITIVE;
    $uid = current_user()['id'];
    $prev = q_one("SELECT hash FROM check_events WHERE check_id=? ORDER BY id DESC LIMIT 1", array($checkId));
    $prevHash = $prev['hash'] ?? null;
    $approval = (in_array($type, $SENSITIVE, true) && !$forceApproved) ? 'pending' : 'approved';
    $now = date('Y-m-d H:i:s');
    $payload = implode('|', array($prevHash ?: 'GENESIS', $checkId, $type, $eventDate, (string)$amount, (string)$bankRef, (string)$note, (int)$uid, $now));
    $hash = hash('sha256', $payload);
    db()->prepare("INSERT INTO check_events
        (check_id, event_type, event_date, amount, bank_ref, note, attachment, approval_status, created_by, created_at, prev_hash, hash)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute(array($checkId, $type, $eventDate, $amount, $bankRef, $note, $attachment, $approval, $uid, $now, $prevHash, $hash));
    $eventId = (int)db()->lastInsertId();
    if ($approval === 'approved') { apply_event($eventId); }
    return $eventId;
}
function apply_event($eventId) {
    global $TRANSITIONS, $TERMINAL;
    $ev  = q_one("SELECT * FROM check_events WHERE id=?", array($eventId));
    $chk = q_one("SELECT * FROM checks WHERE id=?", array($ev['check_id']));
    if (!$ev || !$chk) return false;
    if ($ev['event_type'] === 'register') {
        // وضعیت اولیه هنگام ثبت چک تنظیم شده؛ فقط قفل اولیه نمی‌گذاریم.
        return true;
    }
    $key = $chk['direction'] . '|' . $chk['kind'];
    $to  = $TRANSITIONS[$key][$chk['status']][$ev['event_type']][1] ?? null;
    if (!$to) return false;
    $uid = current_user()['id'];
    if (in_array($to, $TERMINAL, true)) {
        db()->prepare("UPDATE checks SET status=?, locked_at=NOW(), locked_by=?, updated_at=NOW() WHERE id=?")
            ->execute(array($to, $uid, $chk['id']));
    } else {
        db()->prepare("UPDATE checks SET status=?, updated_at=NOW() WHERE id=?")->execute(array($to, $chk['id']));
    }
    return true;
}

/* ------------------------------------------------------------------ POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $kind      = in_array($_POST['kind'] ?? '', array('payment','guarantee'), true) ? $_POST['kind'] : 'payment';
        $direction = in_array($_POST['direction'] ?? '', array('received','issued'), true) ? $_POST['direction'] : 'received';
        $amount    = (float)str_replace(array(',', '،'), '', (string)($_POST['amount'] ?? '0'));
        $sayyad    = trim((string)($_POST['sayyad_id'] ?? ''));
        $chequeNo  = trim((string)($_POST['cheque_number'] ?? ''));
        $dueDate   = $_POST['due_date'] ?? null;
        $issueDate = $_POST['issue_date'] ?? date('Y-m-d');

        if ($amount <= 0) { flash('مبلغ باید بزرگ‌تر از صفر باشد.'); }
        elseif ($sayyad !== '' && (!preg_match('/^[0-9]{8,20}$/', $sayyad))) { flash('شناسه صیاد باید عددی (۱۶ رقم) باشد.'); }
        else {
            if ($sayyad !== '') {
                $dup = q_one("SELECT id FROM checks WHERE sayyad_id=? AND deleted_at IS NULL", array($sayyad));
                if ($dup) { flash('این شناسه صیاد قبلاً برای چک دیگری ثبت شده است.'); }
            }
            if (empty($_SESSION['cheque_flash'])) {
                if ($kind === 'payment' && $direction === 'received') { $initStatus = 'in_hand'; }
                elseif ($kind === 'payment' && $direction === 'issued') { $initStatus = 'issued'; }
                else { $initStatus = 'held'; }

                $partyType = in_array($_POST['party_type'] ?? '', array('customer','supplier','other'), true) ? $_POST['party_type'] : 'other';
                $imgF = upload_cheque_file('image_front', 'front');
                $imgB = upload_cheque_file('image_back', 'back');

                db()->prepare("INSERT INTO checks
                    (kind, direction, cheque_number, sayyad_id, series, serial, bank_name, branch_name, branch_code,
                     bank_account_id, party_type, customer_id, supplier_id, party_name, amount, issue_date, due_date,
                     guarantee_reason, guarantee_return_date, status, ref_type, ref_id, image_front, image_back,
                     description, checkbook_id, created_by, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                    ->execute(array(
                        $kind, $direction, $chequeNo ?: null, $sayyad ?: null,
                        trim((string)($_POST['series'] ?? '')) ?: null, trim((string)($_POST['serial'] ?? '')) ?: null,
                        trim((string)($_POST['bank_name'] ?? '')) ?: null, trim((string)($_POST['branch_name'] ?? '')) ?: null,
                        trim((string)($_POST['branch_code'] ?? '')) ?: null,
                        $_POST['bank_account_id'] ?: null,
                        $partyType,
                        $partyType === 'customer' ? ($_POST['customer_id'] ?: null) : null,
                        $partyType === 'supplier' ? ($_POST['supplier_id'] ?: null) : null,
                        trim((string)($_POST['party_name'] ?? '')) ?: null,
                        $amount, $issueDate, ($kind === 'payment' ? $dueDate : null),
                        $kind === 'guarantee' ? trim((string)($_POST['guarantee_reason'] ?? '')) : null,
                        $kind === 'guarantee' ? ($_POST['guarantee_return_date'] ?: null) : null,
                        $initStatus,
                        $_POST['ref_type'] ?: null, $_POST['ref_id'] ?: null,
                        $imgF, $imgB,
                        trim((string)($_POST['description'] ?? '')) ?: null,
                        $_POST['checkbook_id'] ?: null,
                        $me['id']
                    ));
                $newId = (int)db()->lastInsertId();
                record_event($newId, 'register', $issueDate, $amount, null, 'ثبت اولیه چک', null, true);
                flash('✅ چک با شماره داخلی ' . fa($newId) . ' ثبت شد.');
                header('Location: cheques.php?p=detail&id=' . $newId);
                exit;
            }
        }
    }

    if ($action === 'event') {
        $cid   = (int)($_POST['check_id'] ?? 0);
        $etype = (string)($_POST['event_type'] ?? '');
        $chk   = q_one("SELECT * FROM checks WHERE id=? AND deleted_at IS NULL", array($cid));
        if (!$chk) { flash('چک یافت نشد.'); }
        elseif ($chk['locked_at']) { flash('این چک قفل است و رویداد جدید نمی‌پذیرد.'); }
        else {
            $key = $chk['direction'] . '|' . $chk['kind'];
            $allowed = $TRANSITIONS[$key][$chk['status']] ?? array();
            if (!isset($allowed[$etype])) { flash('این عملیات برای وضعیت فعلی چک مجاز نیست.'); }
            else {
                $att = upload_cheque_file('attachment', 'ev');
                $eid = record_event($cid, $etype, $_POST['event_date'] ?? date('Y-m-d'),
                        ($_POST['amount'] ?? null) !== '' ? (float)str_replace(',', '', (string)$_POST['amount']) : null,
                        trim((string)($_POST['bank_ref'] ?? '')) ?: null,
                        trim((string)($_POST['note'] ?? '')) ?: null, $att);
                flash(in_array($etype, $SENSITIVE, true)
                      ? '⏳ رویداد ثبت شد و در انتظار تأیید کاربر دوم است.'
                      : '✅ رویداد ثبت و اعمال شد.');

                if ($etype === 'endorse') {
                    db()->prepare("INSERT INTO check_endorsements
                        (check_id, to_party_type, to_supplier_id, to_customer_id, to_name, endorsement_date,
                         ref_type, ref_id, amount, note, created_by, created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())")
                        ->execute(array(
                            $cid,
                            in_array($_POST['to_party_type'] ?? '', array('supplier','customer','other'), true) ? $_POST['to_party_type'] : 'other',
                            ($_POST['to_supplier_id'] ?? '') ?: null,
                            ($_POST['to_customer_id'] ?? '') ?: null,
                            trim((string)($_POST['to_name'] ?? '')) ?: null,
                            $_POST['event_date'] ?? date('Y-m-d'),
                            $_POST['ref_type'] ?? null, $_POST['ref_id'] ?? null,
                            (float)str_replace(',', '', (string)($_POST['amount'] ?? '0')) ?: $chk['amount'],
                            trim((string)($_POST['note'] ?? '')) ?: null,
                            $me['id']
                        ));
                }
                header('Location: cheques.php?p=detail&id=' . $cid);
                exit;
            }
        }
    }

    if ($action === 'approve' || $action === 'reject') {
        $eid = (int)($_POST['event_id'] ?? 0);
        $ev  = q_one("SELECT * FROM check_events WHERE id=?", array($eid));
        if (!$ev) { flash('رویداد یافت نشد.'); }
        elseif ($ev['approval_status'] !== 'pending') { flash('این رویداد قبلاً تعیین تکلیف شده.'); }
        elseif ((int)$ev['created_by'] === (int)$me['id']) { flash('تأیید باید توسط کاربری غیر از ثبت‌کننده انجام شود (Maker–Checker).'); }
        else {
            if ($action === 'approve') {
                db()->prepare("UPDATE check_events SET approval_status='approved', approved_by=?, approved_at=NOW() WHERE id=?")
                    ->execute(array($me['id'], $eid));
                apply_event($eid);
                flash('✅ رویداد تأیید و در وضعیت چک اعمال شد.');
            } else {
                db()->prepare("UPDATE check_events SET approval_status='rejected', approved_by=?, approved_at=NOW() WHERE id=?")
                    ->execute(array($me['id'], $eid));
                flash('رویداد رد شد؛ وضعیت چک تغییر نکرد.');
            }
            header('Location: cheques.php?p=detail&id=' . $ev['check_id']);
            exit;
        }
    }

    if ($action === 'checkbook') {
        db()->prepare("INSERT INTO checkbooks (bank_account_id, series, start_number, end_number, received_date, notes, created_by, created_at)
                       VALUES (?,?,?,?,?,?,?,NOW())")
            ->execute(array(
                $_POST['bank_account_id'] ?: null, trim((string)($_POST['series'] ?? '')) ?: null,
                (int)$_POST['start_number'], (int)$_POST['end_number'],
                $_POST['received_date'] ?: date('Y-m-d'), trim((string)($_POST['notes'] ?? '')) ?: null,
                $me['id']));
        flash('✅ دفترچه چک ثبت شد.');
        header('Location: cheques.php?p=create&direction=issued');
        exit;
    }
}

/* ------------------------------------------------------------------ داده‌ها */
function bank_accounts() {
    $nameCol = pick_col('bank_accounts', array('bank_name','bank','title','name'));
    $numCol  = pick_col('bank_accounts', array('account_number','account_no','number','card_number'));
    $balCol  = pick_col('bank_accounts', array('balance','current_balance','account_balance','available_balance'));
    if (!$nameCol) return array();
    $sql = "SELECT id, `$nameCol` AS bank_name" . ($numCol ? ", `$numCol` AS account_no" : ', NULL AS account_no')
         . ($balCol ? ", `$balCol` AS balance" : ', NULL AS balance') . " FROM bank_accounts";
    return q_all($sql);
}
function parties($type) {
    $table = $type === 'customer' ? 'customers' : 'suppliers';
    $nameCol = pick_col($table, array('name','company_name','full_name','title', $type . '_name'));
    if (!$nameCol) return array();
    return q_all("SELECT id, `$nameCol` AS name FROM `$table` ORDER BY name");
}
function checkbooks_active() {
    return q_all("SELECT * FROM checkbooks WHERE status='active' ORDER BY id DESC");
}
function next_cheque_number($checkbookId) {
    $cb = q_one("SELECT * FROM checkbooks WHERE id=?", array($checkbookId));
    if (!$cb) return null;
    $max = q_one("SELECT MAX(CAST(cheque_number AS UNSIGNED)) AS m FROM checks
                  WHERE checkbook_id=? AND cheque_number REGEXP '^[0-9]+$'", array($checkbookId));
    $next = max((int)($max['m'] ?? 0) + 1, (int)$cb['start_number']);
    return $next <= (int)$cb['end_number'] ? $next : null;
}

/* ================================================================ صفحات */
function render_header($title) {
    $pending = 0;
    try { $pending = (int)q_one("SELECT COUNT(*) c FROM check_events WHERE approval_status='pending'")['c']; } catch (Exception $e) {}
    echo '<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>' . e($title) . ' — مدیریت چک‌ها</title>
    <style>
    *{box-sizing:border-box} body{margin:0;font-family:Tahoma,"Segoe UI",sans-serif;background:#f1f4f8;color:#1f2937;font-size:14px}
    a{color:#2563eb;text-decoration:none} a:hover{text-decoration:underline}
    .topbar{background:#0f2a4a;color:#fff;padding:12px 20px;display:flex;gap:18px;align-items:center;flex-wrap:wrap}
    .topbar a{color:#dbeafe;font-weight:600} .topbar .brand{font-size:17px;font-weight:700;color:#fff}
    .wrap{max-width:1200px;margin:20px auto;padding:0 16px}
    .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:18px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px}
    .card h4{margin:0 0 8px;font-size:13px;color:#6b7280;font-weight:600}
    .card .big{font-size:22px;font-weight:700} .card .sub{color:#6b7280;font-size:12px;margin-top:4px}
    table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden}
    th,td{padding:10px 12px;border-bottom:1px solid #eef1f4;text-align:right;font-size:13px}
    th{background:#f8fafc;color:#475569;font-weight:700} tr:hover td{background:#fafcff}
    .tag{display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;color:#fff}
    .tag-blue{background:#2563eb}.tag-green{background:#16a34a}.tag-red{background:#dc2626}.tag-orange{background:#ea580c}
    .tag-yellow{background:#ca8a04}.tag-gray{background:#6b7280}.tag-cyan{background:#0891b2}.tag-purple{background:#7c3aed}
    .btn{display:inline-block;padding:8px 16px;border-radius:8px;border:0;cursor:pointer;font-family:inherit;font-size:13px;font-weight:700}
    .btn-primary{background:#2563eb;color:#fff}.btn-green{background:#16a34a;color:#fff}.btn-red{background:#dc2626;color:#fff}
    .btn-gray{background:#e5e7eb;color:#374151}.btn-sm{padding:4px 10px;font-size:12px}
    .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}
    label{display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px}
    input,select,textarea{width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:8px;font-family:inherit;font-size:13px}
    .flash{background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;padding:10px 14px;border-radius:8px;margin-bottom:10px}
    .flash-w{background:#fffbeb;border-color:#fcd34d;color:#92400e}
    .muted{color:#6b7280;font-size:12px}.danger-box{background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:14px}
    .timeline{border-right:3px solid #e5e7eb;padding-right:16px}
    .tl-item{position:relative;margin-bottom:16px}.tl-item:before{content:"";position:absolute;right:-24px;top:4px;width:11px;height:11px;border-radius:50%;background:#2563eb}
    .tl-pending{background:#fff;border:1px dashed #f59e0b;border-radius:10px;padding:10px 14px;margin:8px 0}
    .seg{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
    .seg a{padding:7px 15px;border-radius:8px;background:#fff;border:1px solid #e5e7eb;font-weight:600}
    .seg a.active{background:#0f2a4a;color:#fff}
    </style></head><body>
    <div class="topbar">
        <span class="brand">🏦 مدیریت چک‌ها</span>
        <a href="cheques.php?p=dashboard">داشبورد</a>
        <a href="cheques.php?p=list">فهرست چک‌ها</a>
        <a href="cheques.php?p=create">＋ ثبت چک جدید</a>
        <a href="cheques.php?p=reports">گزارش‌ها</a>
        <a href="cheques.php?p=approvals">تأییدها' . ($pending ? ' <span class="tag tag-red">' . fa($pending) . '</span>' : '') . '</a>
        <a href="./" style="margin-right:auto">← بازگشت به ERP</a>
    </div><div class="wrap">';
    foreach (get_flash() as $f) { echo '<div class="flash">' . e($f) . '</div>'; }
}
function render_footer() { echo '</div></body></html>'; }
function status_tag($s) { global $STATUS_META; $m = $STATUS_META[$s] ?? array($s, 'gray'); return '<span class="tag tag-' . $m[1] . '">' . e($m[0]) . '</span>'; }
function kind_label($k, $d) {
    return ($k === 'guarantee' ? '🤝 تضمینی' : '💵 پرداختی') . ' / ' . ($d === 'received' ? 'دریافتی' : 'صادره');
}

/* ---------- داشبورد ---------- */
function page_dashboard() {
    render_header('داشبورد');
    $today = date('Y-m-d');
    $stat = function($sql, $p = array()) { try { $r = q_one($sql, $p); return $r; } catch (Exception $e) { return array('c'=>0,'s'=>0); } };

    $rec  = $stat("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM checks WHERE kind='payment' AND direction='received'
                   AND status IN ('in_hand','deposited','bounced') AND deleted_at IS NULL");
    $due7 = $stat("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM checks WHERE kind='payment' AND status IN ('in_hand','deposited')
                   AND due_date IS NOT NULL AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND deleted_at IS NULL");
    $iss7 = $stat("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM checks WHERE kind='payment' AND direction='issued'
                   AND status='issued' AND due_date IS NOT NULL AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND deleted_at IS NULL");
    $overIssued = $stat("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM checks WHERE kind='payment' AND direction='issued'
                   AND status='issued' AND due_date IS NOT NULL AND due_date < CURDATE() AND deleted_at IS NULL");
    $bounced = $stat("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM checks WHERE status='bounced' AND deleted_at IS NULL");
    $guar    = $stat("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM checks WHERE kind='guarantee' AND status='held' AND deleted_at IS NULL");
    $pending = $stat("SELECT COUNT(*) c FROM check_events WHERE approval_status='pending'");

    echo '<div class="cards">';
    echo '<div class="card"><h4>چک دریافتی در جریان</h4><div class="big">' . money($rec['s']) . '</div><div class="sub">' . fa($rec['c']) . ' فقره</div></div>';
    echo '<div class="card"><h4>سررسید ۷ روز آینده (دریافتی)</h4><div class="big" style="color:#ea580c">' . money($due7['s']) . '</div><div class="sub">' . fa($due7['c']) . ' فقره — برای سپرده‌گذاری</div></div>';
    echo '<div class="card"><h4>چک صادره ۷ روز آینده</h4><div class="big" style="color:#dc2626">' . money($iss7['s']) . '</div><div class="sub">' . fa($iss7['c']) . ' فقره — موجودی حساب را کنترل کنید</div></div>';
    echo '<div class="card"><h4>برگشتی‌ها</h4><div class="big" style="color:#dc2626">' . money($bounced['s']) . '</div><div class="sub">' . fa($bounced['c']) . ' فقره — نیاز به پیگیری</div></div>';
    echo '<div class="card"><h4>چک تضمینی در وثیقه</h4><div class="big">' . fa($guar['c']) . ' فقره</div><div class="sub">جمع ' . money($guar['s']) . '</div></div>';
    echo '<div class="card"><h4>در انتظار تأیید</h4><div class="big" style="color:#ca8a04">' . fa($pending['c']) . '</div><div class="sub"><a href="cheques.php?p=approvals">مشاهده صف تأیید</a></div></div>';
    echo '</div>';

    if ((int)$overIssued['c'] > 0) {
        echo '<div class="danger-box">🚨 <b>هشدار بحرانی:</b> ' . fa($overIssued['c']) . ' فقره چک صادره به مبلغ '
           . '<b>' . money($overIssued['s']) . '</b> سررسیدش گذشته و هنوز پاس نشده — خطر برگشت چک شرکت!</div>';
    }

    /* هشدار کسری موجودی به تفکیک بانک */
    echo '<div class="card"><h4>پیش‌بینی کسری موجودی حساب‌ها (چک‌های صادره ۳۰ روز آینده)</h4>';
    $banks = bank_accounts();
    $bankMap = array(); foreach ($banks as $b) { $bankMap[$b['id']] = $b; }
    $rows = q_all("SELECT bank_account_id, COUNT(*) c, COALESCE(SUM(amount),0) s
                   FROM checks WHERE kind='payment' AND direction='issued' AND status='issued'
                   AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND deleted_at IS NULL
                   GROUP BY bank_account_id");
    if (!$rows) { echo '<div class="muted">چک صادره‌ای در ۳۰ روز آینده نیست.</div>'; }
    else {
        echo '<table><tr><th>حساب بانکی</th><th>تعداد چک</th><th>جمع مبالغ سررسیدشده</th><th>مانده فعلی (در سیستم)</th><th>وضعیت</th></tr>';
        foreach ($rows as $r) {
            $b = $bankMap[$r['bank_account_id']] ?? null;
            $bal = $b && $b['balance'] !== null ? (float)$b['balance'] : null;
            $shortfall = $bal !== null ? $bal - (float)$r['s'] : null;
            $tag = $shortfall === null ? '<span class="muted">مانده حساب در سیستم ثبت نشده — دستی کنترل شود</span>'
                 : ($shortfall < 0 ? '<span class="tag tag-red">کسری ' . money(-$shortfall) . '</span>'
                                   : '<span class="tag tag-green">کافی است</span>');
            echo '<tr><td>' . e($b['bank_name'] ?? 'نامشخص') . '</td><td>' . fa($r['c']) . '</td><td>' . money($r['s'])
               . '</td><td>' . ($bal !== null ? money($bal) : '<span class="muted">—</span>') . '</td><td>' . $tag . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    /* سررسیدهای نزدیک */
    echo '<div class="card" style="margin-top:14px"><h4>سررسیدهای نزدیک (۷ روز)</h4>';
    $up = q_all("SELECT * FROM checks WHERE deleted_at IS NULL
                 AND ((kind='payment' AND status IN ('in_hand','deposited','issued'))
                      OR (kind='guarantee' AND status='held' AND guarantee_return_date IS NOT NULL))
                 AND COALESCE(IF(kind='guarantee', guarantee_return_date, due_date), due_date) <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                 ORDER BY COALESCE(IF(kind='guarantee', guarantee_return_date, due_date), due_date) ASC LIMIT 20");
    if (!$up) { echo '<div class="muted">موردی نیست.</div>'; }
    else {
        echo '<table><tr><th>چک</th><th>نوع</th><th>طرف</th><th>مبلغ</th><th>تاریخ</th><th>وضعیت</th></tr>';
        foreach ($up as $c) {
            $d = $c['kind'] === 'guarantee' ? $c['guarantee_return_date'] : $c['due_date'];
            echo '<tr><td><a href="cheques.php?p=detail&id=' . $c['id'] . '">' . e($c['cheque_number'] ?: '#' . $c['id']) . '</a></td>'
               . '<td>' . kind_label($c['kind'], $c['direction']) . '</td><td>' . e($c['party_name'] ?: '—') . '</td>'
               . '<td>' . money($c['amount']) . '</td><td>' . jdate_long($d) . ' ' . due_badge($d) . '</td>'
               . '<td>' . status_tag($c['status']) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';
    render_footer();
}

/* ---------- فهرست ---------- */
function page_list() {
    render_header('فهرست چک‌ها');
    $where = "c.deleted_at IS NULL"; $p = array();
    if (!empty($_GET['dir']))   { $where .= " AND c.direction=" . db()->quote($_GET['dir']); }
    if (!empty($_GET['kind']))  { $where .= " AND c.kind=" . db()->quote($_GET['kind']); }
    if (!empty($_GET['status'])){ $where .= " AND c.status=" . db()->quote($_GET['status']); }
    if (!empty($_GET['q']))     { $where .= " AND (c.cheque_number LIKE ? OR c.sayyad_id LIKE ? OR c.party_name LIKE ?)"; $like='%'.$_GET['q'].'%'; $p=array($like,$like,$like); }
    $rows = q_all("SELECT c.* FROM checks c WHERE $where ORDER BY c.id DESC LIMIT 300", $p);
    echo '<div class="seg">
        <a href="cheques.php?p=list" class="' . (empty($_GET['dir']) ? 'active' : '') . '">همه</a>
        <a href="cheques.php?p=list&dir=received&kind=payment" class="' . (($_GET['dir']??'')==='received' ? 'active' : '') . '">دریافتی پرداختی</a>
        <a href="cheques.php?p=list&dir=issued&kind=payment" class="' . (($_GET['dir']??'')==='issued' ? 'active' : '') . '">صادره پرداختی</a>
        <a href="cheques.php?p=list&kind=guarantee" class="' . (($_GET['kind']??'')==='guarantee' ? 'active' : '') . '">تضمینی</a>
        <a href="cheques.php?p=list&status=bounced" class="' . (($_GET['status']??'')==='bounced' ? 'active' : '') . '">برگشتی‌ها</a>
      </div>';
    echo '<form method="get" style="margin-bottom:12px;display:flex;gap:8px"><input type="hidden" name="p" value="list">
          <input name="q" placeholder="جستجو: شماره چک، صیاد، نام طرف..." value="' . e($_GET['q'] ?? '') . '">
          <button class="btn btn-primary">جستجو</button></form>';
    echo '<table><tr><th>#</th><th>نوع</th><th>شماره/صیاد</th><th>بانک</th><th>طرف حساب</th><th>مبلغ</th><th>سررسید</th><th>وضعیت</th><th></th></tr>';
    foreach ($rows as $c) {
        $d = $c['kind'] === 'guarantee' ? $c['guarantee_return_date'] : $c['due_date'];
        echo '<tr><td>' . fa($c['id']) . '</td><td>' . kind_label($c['kind'], $c['direction']) . '</td>'
           . '<td>' . e($c['cheque_number'] ?: '—') . '<div class="muted">صیاد: ' . e($c['sayyad_id'] ?: '—') . '</div></td>'
           . '<td>' . e($c['bank_name'] ?: '—') . '</td><td>' . e($c['party_name'] ?: '—') . '</td>'
           . '<td>' . money($c['amount']) . '</td><td>' . jdate($d) . '</td>'
           . '<td>' . status_tag($c['status']) . ($c['locked_at'] ? ' 🔒' : '') . '</td>'
           . '<td><a class="btn btn-gray btn-sm" href="cheques.php?p=detail&id=' . $c['id'] . '">مشاهده</a></td></tr>';
    }
    echo '</table><div class="muted" style="margin-top:8px">' . fa(count($rows)) . ' ردیف (حداکثر ۳۰۰)</div>';
    render_footer();
}

/* ---------- فرم ثبت ---------- */
function page_create() {
    global $me;
    render_header('ثبت چک جدید');
    $direction = in_array($_GET['direction'] ?? 'received', array('received','issued'), true) ? $_GET['direction'] : 'received';
    $kind      = in_array($_GET['kind'] ?? 'payment', array('payment','guarantee'), true) ? $_GET['kind'] : 'payment';
    $banks = bank_accounts();
    $customers = parties('customer');
    $suppliers = parties('supplier');
    $cbs = checkbooks_active();

    echo '<div class="seg">
        <a href="cheques.php?p=create&direction=received&kind=payment" class="active">💵 چک دریافتی</a>
        <a href="cheques.php?p=create&direction=issued&kind=payment">💸 چک صادره</a>
        <a href="cheques.php?p=create&direction=received&kind=guarantee">🤝 تضمینی دریافتی</a>
        <a href="cheques.php?p=create&direction=issued&kind=guarantee">🤝 تضمینی صادره</a>
      </div>';

    echo '<form method="post" enctype="multipart/form-data" class="card">';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="create">';
    echo '<input type="hidden" name="direction" value="' . e($direction) . '">';
    echo '<input type="hidden" name="kind" value="' . e($kind) . '">';
    echo '<div class="form-grid">';
    echo '<div><label>مبلغ (ریال) *</label><input name="amount" required placeholder="مثلاً 500000000"></div>';
    echo '<div><label>شماره چک</label><input name="cheque_number" id="cheque_number"></div>';
    echo '<div><label>شناسه صیاد (۱۶ رقم)</label><input name="sayyad_id" maxlength="20" inputmode="numeric" placeholder="در صورت داشتن"></div>';
    echo '<div><label>سری / سریال</label><div style="display:flex;gap:6px"><input name="series" placeholder="سری"><input name="serial" placeholder="سریال"></div></div>';
    echo '<div><label>نام بانک</label><input name="bank_name" list="banklist"><datalist id="banklist">';
    foreach (array('ملت','صادرات','ملی','سپه','تجارت','رفاه','پارسیان','پاسارگاد','سامان','آینده','کشاورزی','مسکن','شهر') as $bn) echo '<option>' . $bn . '</option>';
    echo '</datalist></div>';
    echo '<div><label>شعبه / کد شعبه</label><div style="display:flex;gap:6px"><input name="branch_name" placeholder="نام شعبه"><input name="branch_code" placeholder="کد" style="max-width:110px"></div></div>';
    echo '<div><label>حساب بانکی ما ' . ($direction === 'issued' ? '(حساب صادرکننده)' : '(حساب وصول)') . '</label><select name="bank_account_id"><option value="">—</option>';
    foreach ($banks as $b) echo '<option value="' . $b['id'] . '">' . e($b['bank_name'] . ($b['account_no'] ? ' / ' . $b['account_no'] : '')) . '</option>';
    echo '</select></div>';

    /* طرف حساب */
    $partyLabel = $direction === 'received' ? 'صادرکننده چک (مشتری)' : 'ذی‌نفع (تأمین‌کننده)';
    echo '<div><label>' . $partyLabel . '</label><select name="party_type" id="party_type" onchange="document.getElementById(\'party_cust\').style.display=this.value===\'customer\'?\'block\':\'none\';document.getElementById(\'party_sup\').style.display=this.value===\'supplier\'?\'block\':\'none\';">
        <option value="customer">مشتری</option><option value="supplier">تأمین‌کننده</option><option value="other">سایر (نام دستی)</option></select></div>';
    echo '<div id="party_cust"><label>انتخاب مشتری</label><select name="customer_id"><option value="">—</option>';
    foreach ($customers as $c) echo '<option value="' . $c['id'] . '">' . e($c['name']) . '</option>';
    echo '</select></div>';
    echo '<div id="party_sup" style="display:none"><label>انتخاب تأمین‌کننده</label><select name="supplier_id"><option value="">—</option>';
    foreach ($suppliers as $s) echo '<option value="' . $s['id'] . '">' . e($s['name']) . '</option>';
    echo '</select></div>';
    echo '<div><label>نام طرف (در صورت سایر)</label><input name="party_name"></div>';

    if ($kind === 'payment') {
        echo '<div><label>تاریخ صدور</label><input type="date" name="issue_date" value="' . date('Y-m-d') . '"></div>';
        echo '<div><label>تاریخ سررسید *</label><input type="date" name="due_date" required></div>';
    } else {
        echo '<div><label>بابت تضمین/وثیقه</label><input name="guarantee_reason" placeholder="مثلاً: تضمین قرارداد، گمرک، اجاره"></div>';
        echo '<div><label>تاریخ استرداد مورد انتظار</label><input type="date" name="guarantee_return_date"></div>';
    }

    if ($direction === 'issued' && $kind === 'payment') {
        echo '<div><label>دفترچه چک (شماره بعدی خودکار)</label><select name="checkbook_id" id="cb" onchange="var n=this.options[this.selectedIndex].getAttribute(\'data-next\');if(n){document.getElementById(\'cheque_number\').value=n;}"><option value="">— بدون دفترچه —</option>';
        foreach ($cbs as $cb) {
            $next = next_cheque_number($cb['id']);
            $label = ($cb['series'] ?: 'دفترچه') . ' (' . $cb['start_number'] . '-' . $cb['end_number'] . ')'
                   . ($next ? ' — بعدی: ' . $next : ' — پر شده');
            echo '<option value="' . $cb['id'] . '" data-next="' . ($next ?: '') . '">' . e($label) . '</option>';
        }
        echo '</select></div>';
    }
    echo '<div><label>اسکن روی چک</label><input type="file" name="image_front" accept="image/*,.pdf"></div>';
    echo '<div><label>اسکن پشت چک</label><input type="file" name="image_back" accept="image/*,.pdf"></div>';
    echo '<div style="grid-column:1/-1"><label>توضیحات / اتصال به سند (نوع و شماره فاکتور، سفارش خرید...)</label>
        <div style="display:flex;gap:6px"><select name="ref_type" style="max-width:170px"><option value="">سند</option>
        <option value="invoice">فاکتور</option><option value="proforma">پیش‌فاکتور</option><option value="deal">معامله</option>
        <option value="purchase_order">سفارش خرید</option></select>
        <input name="ref_id" placeholder="شماره/ID سند" style="max-width:140px"></div></div>';
    echo '<div style="grid-column:1/-1"><label>توضیحات</label><textarea name="description" rows="2"></textarea></div>';
    echo '</div>';
    echo '<button class="btn btn-primary" style="margin-top:14px">💾 ثبت چک</button>';
    echo '</form>';

    /* فرم مستقل ثبت دفترچه چک (بیرون از فرم اصلی تا action تداخل نکند) */
    if ($direction === 'issued' && $kind === 'payment') {
        echo '<form method="post" class="card" style="margin-top:16px" onsubmit="return true">' . csrf_field();
        echo '<details><summary style="cursor:pointer;font-weight:700">➕ ثبت دفترچه چک جدید</summary>
        <input type="hidden" name="action" value="checkbook">
        <div class="form-grid" style="margin-top:10px">
        <div><label>حساب</label><select name="bank_account_id"><option value="">—</option>';
        foreach ($banks as $b) echo '<option value="' . $b['id'] . '">' . e($b['bank_name']) . '</option>';
        echo '</select></div><div><label>سری</label><input name="series"></div>
        <div><label>از شماره</label><input type="number" name="start_number" required></div>
        <div><label>تا شماره</label><input type="number" name="end_number" required></div>
        <div><label>تاریخ دریافت</label><input type="date" name="received_date" value="' . date('Y-m-d') . '"></div>
        <div style="align-self:end"><button class="btn btn-gray">ثبت دفترچه</button></div>
        </div></details></form>';
    }
    render_footer();
}

/* ---------- جزئیات + تایم‌لاین ---------- */
function page_detail() {
    $id = (int)($_GET['id'] ?? 0);
    $c = q_one("SELECT * FROM checks WHERE id=? AND deleted_at IS NULL", array($id));
    if (!$c) { render_header('یافت نشد'); echo '<div class="card">چک موردنظر وجود ندارد.</div>'; render_footer(); return; }
    render_header('چک #' . $id);
    global $TRANSITIONS, $EVENT_LABELS, $me;

    echo '<div class="card"><h4>' . kind_label($c['kind'], $c['direction']) . ' — ' . status_tag($c['status']) . ($c['locked_at'] ? ' 🔒' : '') . '</h4>
        <div class="form-grid" style="margin-top:10px">
        <div><b>شماره چک:</b> ' . e($c['cheque_number'] ?: '—') . '</div>
        <div><b>صیاد:</b> ' . e($c['sayyad_id'] ?: '—') . '</div>
        <div><b>بانک/شعبه:</b> ' . e(trim(($c['bank_name']?:'') . ' ' . ($c['branch_name']?:'')) ?: '—') . '</div>
        <div><b>طرف حساب:</b> ' . e($c['party_name'] ?: '—') . '</div>
        <div><b>مبلغ:</b> ' . money($c['amount']) . '</div>
        <div><b>تاریخ صدور:</b> ' . jdate_long($c['issue_date']) . '</div>
        <div><b>سررسید:</b> ' . jdate_long($c['kind']==='guarantee' ? $c['guarantee_return_date'] : $c['due_date']) . ' ' . due_badge($c['kind']==='guarantee' ? $c['guarantee_return_date'] : $c['due_date']) . '</div>';
    if ($c['kind'] === 'guarantee') echo '<div><b>بابت:</b> ' . e($c['guarantee_reason'] ?: '—') . '</div>';
    echo '<div><b>اسکن:</b> ' . ($c['image_front'] ? '<a target="_blank" href="' . e($c['image_front']) . '">رو</a>' : '—') . ' | '
        . ($c['image_back'] ? '<a target="_blank" href="' . e($c['image_back']) . '">پشت</a>' : '—') . '</div>';
    if ($c['description']) echo '<div style="grid-column:1/-1" class="muted">' . e($c['description']) . '</div>';
    echo '</div></div>';

    /* عملیات مجاز */
    if (!$c['locked_at']) {
        $key = $c['direction'] . '|' . $c['kind'];
        $allowed = $TRANSITIONS[$key][$c['status']] ?? array();
        if ($allowed) {
            echo '<div class="card" style="margin-top:14px"><h4>عملیات جدید</h4>';
            foreach ($allowed as $etype => $info) {
                list($label, , $sensitive) = $info;
                echo '<details style="margin:8px 0;border:1px solid #e5e7eb;border-radius:8px;padding:8px 12px">
                    <summary style="cursor:pointer;font-weight:700">' . ($sensitive ? '⚠️ ' : '') . e($label) . '</summary>
                    <form method="post" enctype="multipart/form-data" style="margin-top:10px">' . csrf_field() . '
                    <input type="hidden" name="action" value="event">
                    <input type="hidden" name="check_id" value="' . $c['id'] . '">
                    <input type="hidden" name="event_type" value="' . $etype . '">
                    <div class="form-grid">
                    <div><label>تاریخ رویداد</label><input type="date" name="event_date" value="' . date('Y-m-d') . '"></div>';
                if (in_array($etype, array('clear','pass','deposit'), true))
                    echo '<div><label>مبلغ (در صورت وصول جزئی)</label><input name="amount" placeholder="خالی = مبلغ کامل چک"></div>';
                echo '<div><label>شماره پیگیری بانک</label><input name="bank_ref"></div>
                    <div><label>ضمیمه (رسید/برگه برگشت)</label><input type="file" name="attachment" accept="image/*,.pdf"></div>';
                if ($etype === 'endorse') {
                    echo '<div><label>انتقال به (نوع طرف)</label><select name="to_party_type"><option value="supplier">تأمین‌کننده</option><option value="customer">مشتری</option><option value="other">سایر</option></select></div>
                    <div><label>نام دریافت‌کننده چک *</label><input name="to_name" required></div>
                    <div><label>بابت (سند)</label><div style="display:flex;gap:6px"><select name="ref_type" style="max-width:160px"><option value="purchase_order">سفارش خرید</option><option value="deal">معامله</option><option value="other">سایر</option></select><input name="ref_id" placeholder="شماره سند" style="max-width:130px"></div></div>';
                }
                echo '<div style="grid-column:1/-1"><label>توضیح</label><input name="note"></div>
                    </div><button class="btn btn-primary" style="margin-top:10px">ثبت عملیات</button>
                    ' . ($sensitive ? '<div class="muted" style="margin-top:6px">⚠️ این عملیات مالی پس از تأیید کاربر دوم اعمال می‌شود.</div>' : '') . '
                    </form></details>';
            }
            echo '</div>';
        }
    }

    /* انتقال‌های انجام‌شده */
    $ends = q_all("SELECT * FROM check_endorsements WHERE check_id=? ORDER BY id", array($id));
    if ($ends) {
        echo '<div class="card" style="margin-top:14px"><h4>زنجیره انتقال (خرج چک)</h4><table>
            <tr><th>تاریخ</th><th>منتقل‌شده به</th><th>مبلغ</th><th>بابت</th></tr>';
        foreach ($ends as $en) {
            echo '<tr><td>' . jdate($en['endorsement_date']) . '</td><td>' . e($en['to_name'] ?: '—') . '</td><td>' . money($en['amount']) . '</td><td>' . e(($en['ref_type']?:'') . ' ' . ($en['ref_id']?:'#'.$en['ref_id'])) . '</td></tr>';
        }
        echo '</table></div>';
    }

    /* تایم‌لاین رویدادها */
    $events = q_all("SELECT ev.*, u.name AS creator_name FROM check_events ev
                     LEFT JOIN (SELECT id, " . (pick_col('users', array('name','full_name','username')) ?: 'id') . " AS name FROM users) u
                       ON u.id = ev.created_by
                     WHERE ev.check_id=? ORDER BY ev.id DESC", array($id));
    echo '<div class="card" style="margin-top:14px"><h4>سابقه رویدادها</h4><div class="timeline">';
    foreach ($events as $ev) {
        $badge = $ev['approval_status'] === 'pending' ? ' <span class="tag tag-yellow">در انتظار تأیید</span>'
               : ($ev['approval_status'] === 'rejected' ? ' <span class="tag tag-red">رد شد</span>' : ' <span class="tag tag-green">تأییدشده</span>');
        echo '<div class="tl-item"><b>' . e($EVENT_LABELS[$ev['event_type']] ?? $ev['event_type']) . '</b> ' . $badge
           . '<div class="muted">' . jdate_long($ev['event_date']) . ' · ثبت: ' . e($ev['creator_name'] ?? ('کاربر #' . $ev['created_by']))
           . ($ev['amount'] ? ' · مبلغ: ' . money($ev['amount']) : '')
           . ($ev['bank_ref'] ? ' · پیگیری: ' . e($ev['bank_ref']) : '') . '</div>'
           . ($ev['note'] ? '<div>' . e($ev['note']) . '</div>' : '')
           . ($ev['attachment'] ? '<div><a target="_blank" href="' . e($ev['attachment']) . '">📎 ضمیمه</a></div>' : '');
        if ($ev['approval_status'] === 'pending' && (int)$ev['created_by'] !== (int)$me['id']) {
            echo '<form method="post" style="display:inline-flex;gap:6px;margin-top:6px">' . csrf_field()
               . '<input type="hidden" name="event_id" value="' . $ev['id'] . '">'
               . '<button name="action" value="approve" class="btn btn-green btn-sm">✓ تأیید و اعمال</button>'
               . '<button name="action" value="reject" class="btn btn-red btn-sm">✕ رد</button></form>';
        } elseif ($ev['approval_status'] === 'pending') {
            echo '<div class="muted" style="margin-top:4px">در انتظار تأیید توسط کاربری دیگر.</div>';
        }
        echo '</div>';
    }
    echo '</div><div class="muted" style="margin-top:8px">🔐 زنجیره Hash آخرین رویداد: <code style="font-size:10px">' . e($events[0]['hash'] ?? '—') . '</code></div></div>';
    render_footer();
}

/* ---------- صف تأیید ---------- */
function page_approvals() {
    render_header('تأیید رویدادها');
    global $me, $EVENT_LABELS;
    $rows = q_all("SELECT ev.*, c.cheque_number, c.amount AS chk_amount, c.direction, c.kind
                   FROM check_events ev JOIN checks c ON c.id = ev.check_id
                   WHERE ev.approval_status='pending' ORDER BY ev.id DESC");
    if (!$rows) echo '<div class="card">موردی در انتظار تأیید نیست.</div>';
    foreach ($rows as $ev) {
        echo '<div class="tl-pending"><b>' . e($EVENT_LABELS[$ev['event_type']] ?? $ev['event_type']) . '</b> — چک '
           . e($ev['cheque_number'] ?: '#' . $ev['check_id']) . ' (' . money($ev['chk_amount']) . ') · ' . jdate_long($ev['event_date'])
           . ' · ثبت‌کننده: کاربر #' . fa($ev['created_by']);
        if ((int)$ev['created_by'] === (int)$me['id']) {
            echo '<div class="muted" style="margin-top:6px">شما ثبت‌کننده‌اید؛ تأیید باید توسط همکار دیگری انجام شود.</div>';
        } else {
            echo '<form method="post" style="display:inline-flex;gap:6px;margin-top:8px">' . csrf_field()
               . '<input type="hidden" name="event_id" value="' . $ev['id'] . '">'
               . '<button name="action" value="approve" class="btn btn-green btn-sm">✓ تأیید و اعمال</button>'
               . '<button name="action" value="reject" class="btn btn-red btn-sm">✕ رد</button></form>';
        }
        echo '</div>';
    }
    render_footer();
}

/* ---------- گزارش‌ها ---------- */
function page_reports() {
    render_header('گزارش‌ها');
    /* Aging چک دریافتی */
    echo '<div class="card"><h4>📊 Aging چک‌های دریافتی (بر اساس سررسید)</h4><table>
        <tr><th>بازه</th><th>تعداد</th><th>مبلغ</th></tr>';
    $buckets = array(
        'سررسید آینده'        => "due_date >= CURDATE()",
        'سررسید گذشته ۱-۳۰'   => "due_date < CURDATE() AND due_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
        'سررسید گذشته ۳۱-۶۰'  => "due_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND due_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)",
        'سررسید گذشته ۶۱-۹۰'  => "due_date < DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND due_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)",
        'سررسید گذشته +۹۰'    => "due_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)",
    );
    foreach ($buckets as $label => $cond) {
        $r = q_one("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM checks
                    WHERE kind='payment' AND direction='received' AND status IN ('in_hand','deposited','bounced')
                    AND deleted_at IS NULL AND $cond");
        echo '<tr><td>' . $label . '</td><td>' . fa($r['c']) . '</td><td>' . money($r['s']) . '</td></tr>';
    }
    echo '</table></div>';

    /* برنامه چک صادره ۳۰ روز */
    echo '<div class="card" style="margin-top:14px"><h4>📅 برنامه چک‌های صادره — ۳۰ روز آینده</h4><table>
        <tr><th>تاریخ سررسید</th><th>تعداد</th><th>مبلغ</th></tr>';
    $rows = q_all("SELECT due_date, COUNT(*) c, COALESCE(SUM(amount),0) s FROM checks
                   WHERE kind='payment' AND direction='issued' AND status='issued' AND deleted_at IS NULL
                   AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                   GROUP BY due_date ORDER BY due_date");
    foreach ($rows as $r) echo '<tr><td>' . jdate_long($r['due_date']) . ' ' . due_badge($r['due_date']) . '</td><td>' . fa($r['c']) . '</td><td>' . money($r['s']) . '</td></tr>';
    if (!$rows) echo '<tr><td colspan="3" class="muted">موردی نیست.</td></tr>';
    echo '</table></div>';

    /* تضمینی‌ها */
    echo '<div class="card" style="margin-top:14px"><h4>🤝 چک‌های تضمینی</h4><table>
        <tr><th>#</th><th>جهت</th><th>طرف</th><th>بابت</th><th>مبلغ</th><th>تاریخ استرداد</th><th>وضعیت</th></tr>';
    $rows = q_all("SELECT * FROM checks WHERE kind='guarantee' AND deleted_at IS NULL ORDER BY id DESC LIMIT 100");
    foreach ($rows as $c) {
        echo '<tr><td><a href="cheques.php?p=detail&id=' . $c['id'] . '">' . fa($c['id']) . '</a></td>'
           . '<td>' . ($c['direction'] === 'received' ? 'گرفته‌شده از طرف' : 'داده‌شده به طرف') . '</td>'
           . '<td>' . e($c['party_name'] ?: '—') . '</td><td>' . e($c['guarantee_reason'] ?: '—') . '</td>'
           . '<td>' . money($c['amount']) . '</td><td>' . jdate_long($c['guarantee_return_date']) . ' ' . due_badge($c['guarantee_return_date']) . '</td>'
           . '<td>' . status_tag($c['status']) . '</td></tr>';
    }
    echo '</table></div>';
    render_footer();
}

/* ------------------------------------------------------------------ مسیریابی */
$p = $_GET['p'] ?? 'dashboard';
switch ($p) {
    case 'list':       page_list(); break;
    case 'create':     page_create(); break;
    case 'detail':     page_detail(); break;
    case 'approvals':  page_approvals(); break;
    case 'reports':    page_reports(); break;
    default:           page_dashboard();
}
