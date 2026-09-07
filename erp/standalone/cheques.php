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

/* ERP سشن اختصاصی دارد: index.php → session_name(SESSION_NAME) که نام کوکی‌اش
   «adapharma_erp_session» است. باید قبل از session_start همان نام را ست کنیم. */
function erp_session_name() {
    if (!empty($_COOKIE['adapharma_erp_session'])) return 'adapharma_erp_session';
    foreach (array_keys($_COOKIE) as $k) {
        if (preg_match('/erp/i', $k) && preg_match('/sess|sid/i', $k)) return $k;
    }
    return 'adapharma_erp_session';
}
session_name(erp_session_name());
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

/* ------------------------------------------------------------------ کاربر
   کلیدهای فریم‌ورک ERP (طبق app/core/Auth.php):
   $_SESSION['user_id'] ، $_SESSION['user']['id'|'name'|'email'|'role']          */
function current_user() {
    $id = null;
    if (!empty($_SESSION['user_id']) && is_numeric($_SESSION['user_id']))   $id = (int)$_SESSION['user_id'];
    elseif (!empty($_SESSION['user']['id']))                                $id = (int)$_SESSION['user']['id'];
    else {
        foreach (array('uid', 'id', 'auth') as $k) {
            if (!empty($_SESSION[$k]) && is_numeric($_SESSION[$k])) { $id = (int)$_SESSION[$k]; break; }
        }
        if ($id === null && !empty($_SESSION['auth']['id'])) $id = (int)$_SESSION['auth']['id'];
    }

    $name = null;
    if (!empty($_SESSION['user']['name']))      $name = $_SESSION['user']['name'];
    elseif (!empty($_SESSION['user']['full_name'])) $name = $_SESSION['user']['full_name'];
    elseif (!empty($_SESSION['user']['email'])) $name = $_SESSION['user']['email'];
    if ($name === null) {
        foreach (array('user_name', 'full_name', 'name', 'username') as $k) {
            if (!empty($_SESSION[$k]) && is_string($_SESSION[$k])) { $name = $_SESSION[$k]; break; }
        }
    }
    if ($name === null && $id !== null) {
        try {
            $col = pick_col('users', array('name', 'full_name', 'username', 'email'));
            if ($col) { $name = db()->query("SELECT `$col` FROM users WHERE id=" . (int)$id)->fetchColumn(); }
        } catch (Exception $e) { /* ignore */ }
    }
    $role = null;
    if (!empty($_SESSION['user']['role']))      $role = (string)$_SESSION['user']['role'];
    elseif (!empty($_SESSION['role']))          $role = (string)$_SESSION['role'];
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
/* خواندن امن پارامتر GET (هشدار Undefined array key ندهد) */
function g($key, $default = '') {
    if (!is_array($_GET)) return $default;
    if (!array_key_exists($key, $_GET) || $_GET[$key] === null || $_GET[$key] === '') return $default;
    return $_GET[$key];
}
/* مبالغ داخلی همه «ریال» ذخیره می‌شوند؛ نمایش به «تومان» است. */
function money($n) { return fa(number_format((float)$n / 10)) . ' تومان'; }

function read_toman($key) {
    $v = str_replace(array(',', '،', ' '), '', (string)($_POST[$key] ?? ''));
    return is_numeric($v) && (float)$v > 0 ? (float)$v * 10 : null; /* → ریال */
}
function post_gregorian($name) {
    /* مقدار میلادی ساخته‌شده توسط تقویم شمسی (hidden)؛ در نبودش، تبدیل سمت سرور */
    $g = trim((string)($_POST[$name . '_g'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $g)) return $g;
    $j = trim((string)($_POST[$name] ?? ''));
    $j = strtr($j, array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','/'=>'-',' '=>''));
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $j, $m)) {
        list($gy, $gm, $gd) = jalali_to_gregorian((int)$m[1], (int)$m[2], (int)$m[3]);
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }
    return null;
}

/* حروف‌نویسی عدد فارسی */
function to_words($n) {
    $n = (int)$n;
    if ($n === 0) return 'صفر';
    $ones = array('','یک','دو','سه','چهار','پنج','شش','هفت','هشت','نه','ده','یازده','دوازده',
                  'سیزده','چهارده','پانزده','شانزده','هفده','هجده','نوزده');
    $tens = array('','','بیست','سی','چهل','پنجاه','شصت','هفتاد','هشتاد','نود');
    $hund = array('','صد','دویست','سیصد','چهارصد','پانصد','ششصد','هفتصد','هشتصد','نهصد');
    $scale = array('', ' هزار', ' میلیون', ' میلیارد', ' هزار میلیارد');
    $groups = array(); while ($n > 0) { $groups[] = $n % 1000; $n = intdiv($n, 1000); }
    $parts = array();
    for ($g = count($groups) - 1; $g >= 0; $g--) {
        $v = $groups[$g]; if (!$v) continue;
        $t = ''; $h = intdiv($v, 100); $r = $v % 100;
        if ($h) $t .= $hund[$h];
        if ($r) {
            if ($t) $t .= ' و ';
            if ($r < 20) $t .= $ones[$r];
            else { $t .= $tens[intdiv($r, 10)]; if ($r % 10) $t .= ' و ' . $ones[$r % 10]; }
        }
        $t .= $scale[$g] ?? '';
        $parts[] = $t;
    }
    return implode(' و ', $parts);
}
function toman_words($rial) { $t = (int)round(((float)$rial) / 10); return to_words($t) . ' تومان'; }

/* تبدیل شمسی به میلادی (fallback سمت سرور) */
function jalali_to_gregorian($jy, $jm, $jd) {
    $jy = (int)$jy - 979;
    $jdays = 365 * $jy + intdiv($jy, 33) * 8 + intdiv(($jy % 33) + 3, 4) - 1
           + ($jd - 1) + ($jm < 7 ? ($jm - 1) * 31 : ($jm - 7) * 30 + 186) + 31 * 3 + 106;
    $gy = 1600 + 400 * intdiv($jdays, 146097); $jdays %= 146097;
    if ($jdays > 36524) { $gy += 100 * intdiv(--$jdays, 36524); $jdays %= 36524; if ($jdays >= 365) $jdays++; }
    $gy += 4 * intdiv($jdays, 1461); $jdays %= 1461;
    if ($jdays > 365) { $gy += intdiv($jdays - 1, 365); $jdays = ($jdays - 1) % 365; }
    $gd = $jdays + 1;
    $sal = array(0,31,($gy%4===0 && $gy%100!==0)||$gy%400===0 ? 29:28,31,30,31,30,31,31,30,31,30,31);
    $gm = 0;
    foreach ($sal as $m => $dim) { if ($m === 0) continue; if ($gd <= $dim) { $gm = $m; break; } $gd -= $dim; }
    return array($gy, $gm, $gd);
}
function gregorian_to_jalali_str($g) {
    if (!$g || $g === '0000-00-00') return '';
    $t = strtotime($g); if (!$t) return '';
    list($jy, $jm, $jd) = gregorian_to_jalali((int)date('Y',$t), (int)date('n',$t), (int)date('j',$t));
    return fa(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
}
/* فیلد تاریخ شمسی با تقویم (مقدار ورودی میلادی است) */
function jinput_html($name, $valueGreg, $label, $required = false) {
    $jval = gregorian_to_jalali_str($valueGreg);
    return '<div><label>' . e($label) . ($required ? ' *' : '') . '</label>'
       . '<input type="text" class="jdate" name="' . e($name) . '" id="jd_' . e($name) . '" value="' . e($jval) . '"'
       . ' placeholder="مثلاً ۱۴۰۵/۰۶/۱۷" autocomplete="off" inputmode="numeric"' . ($required ? ' required' : '') . '>'
       . '<input type="hidden" name="' . e($name) . '_g" id="jdg_' . e($name) . '" value="' . e($valueGreg ?: '') . '">'
       . '</div>';
}
function jinput($name, $valueGreg, $label, $required = false) { echo jinput_html($name, $valueGreg, $label, $required); }
function jinput_ret($name, $valueGreg, $label, $required = false) { return jinput_html($name, $valueGreg, $label, $required); }
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
    if (move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $name)) return '/uploads/cheques/' . $name;
    return null;
}
/* مسیر امن برای نمایش فایل (مطلق‌کردن مسیرهای نسبی قدیمی) */
function asset_url($p) {
    $p = (string)$p;
    if ($p === '') return '';
    if (preg_match('#^https?://#', $p) || $p[0] === '/') return $p;
    return '/' . ltrim($p, '/');
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
        $amount    = read_toman('amount_toman');  /* ورودی تومان → ریال */
        $sayyad    = trim((string)($_POST['sayyad_id'] ?? ''));
        $sayyad    = str_replace(array(' ', '-', '_'), '', $sayyad);
        $chequeNo  = trim((string)($_POST['cheque_number'] ?? ''));
        $dueDate   = post_gregorian('due_date');
        $issueDate = post_gregorian('issue_date') ?: date('Y-m-d');
        $gReturn   = post_gregorian('guarantee_return_date');

        if ($amount === null) { flash('مبلغ را به تومان وارد کنید (بزرگ‌تر از صفر).'); }
        elseif ($sayyad !== '' && (!preg_match('/^[0-9]{8,20}$/', $sayyad))) { flash('شناسه صیاد باید فقط عدد (۱۶ رقم) باشد.'); }
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
                        $kind === 'guarantee' ? $gReturn : null,
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
                $evAmount = (($_POST['amount_toman'] ?? '') !== '') ? read_toman('amount_toman') : null;
                $evDate = post_gregorian('event_date') ?: date('Y-m-d');
                $eid = record_event($cid, $etype, $evDate, $evAmount,
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
                            $evDate,
                            $_POST['ref_type'] ?? null, $_POST['ref_id'] ?? null,
                            read_toman('amount_toman') ?: $chk['amount'],
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
                (int)str_replace(',', '', (string)($_POST['start_number'] ?? '0')),
                (int)str_replace(',', '', (string)($_POST['end_number'] ?? '0')),
                post_gregorian('cb_received') ?: date('Y-m-d'), trim((string)($_POST['notes'] ?? '')) ?: null,
                $me['id']));
        flash('✅ دفترچه چک ثبت شد.');
        header('Location: cheques.php?p=create&direction=issued');
        exit;
    }

    if ($action === 'bank') {
        try {
            $formMap = array(
                'bank_name'      => trim((string)($_POST['bank_name'] ?? '')),
                'holder'         => trim((string)($_POST['account_name'] ?? '')),
                'account_no'     => trim((string)($_POST['account_number'] ?? '')),
                'card'           => trim((string)($_POST['card_number'] ?? '')),
                'iban'           => trim((string)($_POST['iban'] ?? '')),
                'branch'         => trim((string)($_POST['branch_name'] ?? '')),
                'balance'        => trim((string)($_POST['balance'] ?? '')),
            );
            $set = array();
            foreach ($formMap as $role => $val) {
                if ($val === '') continue;
                $col = bank_field($role);
                if (!$col) continue;
                if ($role === 'balance') { $val = (float)str_replace(array(',', '،'), '', $val); }
                $set[$col] = $val;
            }
            /* ارز پیش‌فرض */
            $curCol = bank_field('currency');
            if ($curCol && !isset($set[$curCol])) $set[$curCol] = 'IRR';
            foreach (array('status'=>'active','is_active'=>1,'active'=>1) as $c=>$dv) {
                if (in_array($c, bank_cols(), true) && !isset($set[$c])) $set[$c] = $dv;
            }
            if (empty($set) || !bank_field('bank_name')) {
                flash('ساختار جدول bank_accounts قابل تطبیق نیست؛ لطفاً خروجی اسکریپت recon_bank را بفرستید.');
            } else {
                if (!isset($set[ bank_field('bank_name') ])) {
                    flash('نام بانک ذخیره نشد (ستون نام پیدا نشد). خروجی recon_bank را بفرستید.');
                } else {
                    $colsSql = implode(',', array_map(function($k){return "`$k`";}, array_keys($set)));
                    $ph = implode(',', array_fill(0, count($set), '?'));
                    db()->prepare("INSERT INTO bank_accounts ($colsSql) VALUES ($ph)")->execute(array_values($set));
                    flash('✅ حساب بانکی ثبت شد.');
                }
            }
        } catch (Exception $ex) { flash('خطا در ثبت حساب: ' . $ex->getMessage()); }
        header('Location: cheques.php?p=banks');
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
/* تشخیص هوشمند ستون جدول bank_accounts بر اساس نقش (الگومند) */
function bank_cols() {
    static $cols = null;
    if ($cols === null) {
        try { $cols = db()->query("SHOW COLUMNS FROM bank_accounts")->fetchAll(PDO::FETCH_COLUMN); }
        catch (Exception $e) { $cols = array(); }
    }
    return $cols;
}
function bank_field($role) {
    $roles = array(
        'bank_name'  => array('/bank.*name/','/^(bank|title|name)$/'),
        'holder'     => array('/holder|owner|in_?name|account_?name|company|title/'),
        'account_no' => array('/account_?(no|num|number)/','/^(number|account)$/'),
        'card'       => array('/card/'),
        'iban'       => array('/iban|shaba|sheba/'),
        'branch'     => array('/branch/'),
        'currency'   => array('/curr/'),
        'balance'    => array('/balance|opening|initial|cash|credit|remaining|amount/'),
    );
    if (!isset($roles[$role])) return null;
    foreach ($roles[$role] as $pat) {
        foreach (bank_cols() as $c) { if (preg_match($pat, $c)) return $c; }
    }
    return null;
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
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css">
    <style>
    :root{
      --navy:#0f2347; --navy2:#16294f; --navy-line:#1f3563;
      --blue:#2563eb; --blue2:#3b82f6; --bg:#f3f5fa; --ink:#1f2937; --muted:#6b7280;
      --card-border:#e9edf3;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:"Vazirmatn",Vazir,IRANSans,"Segoe UI",Tahoma,sans-serif;background:var(--bg);color:var(--ink);font-size:14px}
    a{color:var(--blue);text-decoration:none} a:hover{text-decoration:underline}

    /* ---------- چیدمان: سایدبار سرمه‌ای (مثل ERP) ---------- */
    .shell{display:flex;min-height:100vh}
    .sidebar{width:232px;min-width:232px;background:var(--navy);color:#c7d3e8;display:flex;flex-direction:column;position:sticky;top:0;height:100vh}
    .brand{display:flex;align-items:center;gap:10px;padding:16px 16px;border-bottom:1px solid var(--navy-line)}
    .brand .logo{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,#22d3ee,#2563eb);display:flex;align-items:center;justify-content:center;font-size:18px}
    .brand .bt{color:#fff;font-weight:800;font-size:15px;line-height:1.1}
    .brand .bs{color:#7f92b5;font-size:10px;letter-spacing:1px}
    .nav{padding:10px 12px;flex:1;overflow-y:auto}
    .nav-sec{color:#6f82a6;font-size:10px;font-weight:700;letter-spacing:1px;margin:14px 10px 6px}
    .nav-item{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:9px;color:#c7d3e8;font-weight:600;font-size:13px;margin:2px 0}
    .nav-item:hover{background:rgba(255,255,255,.06);text-decoration:none;color:#fff}
    .nav-item.active{background:rgba(37,99,235,.22);color:#fff}
    .nav-item .ic{width:18px;text-align:center;font-size:15px}
    .nav-item .bdg{margin-right:auto;background:var(--blue2);color:#fff;border-radius:999px;font-size:10px;font-weight:700;padding:1px 8px;min-width:20px;text-align:center}
    .nav-user{display:flex;align-items:center;gap:9px;padding:12px 16px;border-top:1px solid var(--navy-line);font-size:12px;color:#dbe4f3}
    .nav-user .av{width:30px;height:30px;border-radius:50%;background:var(--blue2);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700}

    .main{flex:1;min-width:0;display:flex;flex-direction:column}
    .topbar{background:#fff;border-bottom:1px solid var(--card-border);padding:12px 24px;display:flex;align-items:center;gap:12px}
    .topbar h1{font-size:17px;font-weight:800;margin:0;flex:1}
    .body{padding:22px 24px;max-width:1180px;width:100%;margin:0 auto}
    @media(max-width:820px){ .sidebar{position:fixed;z-index:50;transform:translateX(100%);transition:.2s} .sidebar.open{transform:none} .menu-toggle{display:inline-block!important} }
    .menu-toggle{display:none;background:var(--navy);color:#fff;border:0;border-radius:8px;padding:7px 12px;font-size:16px;cursor:pointer}

    /* هیرو گرادینت (مثل صفحات ERP) */
    .hero{border-radius:16px;padding:26px 30px;color:#fff;display:flex;align-items:center;gap:18px;margin-bottom:20px;box-shadow:0 6px 18px rgba(16,35,71,.12)}
    .hero.green{background:linear-gradient(135deg,#16a34a,#0f9d8f)}
    .hero.blue{background:linear-gradient(135deg,#2563eb,#1e40af)}
    .hero.navy{background:linear-gradient(135deg,#1f3563,#0f2347)}
    .hero.purple{background:linear-gradient(135deg,#7c3aed,#5b21b6)}
    .hero .hico{font-size:34px}
    .hero .hbody{flex:1}
    .hero h2{margin:0;font-size:24px;font-weight:800}
    .hero .hsub{margin-top:4px;font-size:13px;opacity:.92}
    .hero .hbtn{background:#fff;color:#0f172a;border-radius:10px;padding:11px 22px;font-weight:800;font-size:13px;display:inline-flex;gap:6px;align-items:center}
    .hero .hbtn:hover{text-decoration:none;transform:translateY(-1px)}

    .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:18px}
    /* کارت آماری با نوار رنگی بالا */
    .stat{background:#fff;border:1px solid var(--card-border);border-radius:14px;padding:16px 18px;border-top:3px solid #2563eb;box-shadow:0 1px 2px rgba(16,35,71,.04)}
    .stat.g{border-top-color:#16a34a}.stat.b{border-top-color:#2563eb}.stat.p{border-top-color:#7c3aed}.stat.o{border-top-color:#f59e0b}.stat.r{border-top-color:#dc2626}.stat.c{border-top-color:#0891b2}
    .stat .lab{font-size:11px;color:var(--muted);font-weight:800;letter-spacing:.4px}
    .stat .val{font-size:24px;font-weight:800;margin-top:6px}
    .stat .vsub{font-size:12px;color:var(--muted);margin-top:4px}
    .card{background:#fff;border:1px solid var(--card-border);border-radius:14px;padding:16px;box-shadow:0 1px 2px rgba(16,35,71,.04)}
    .card h4{margin:0 0 8px;font-size:13px;color:var(--muted);font-weight:700}
    /* قرص‌های فیلتر */
    .pills{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
    .pill{display:inline-flex;align-items:center;gap:7px;background:#fff;border:1px solid var(--card-border);border-radius:999px;padding:8px 18px;font-weight:700;font-size:13px;color:#475569}
    .pill:hover{text-decoration:none;border-color:#cbd5e1}
    .pill.active{background:#16a34a;color:#fff;border-color:#16a34a}
    .pill .n{background:#eef2f7;border-radius:999px;padding:0 9px;font-size:11px;font-weight:800;color:#475569}
    .pill.active .n{background:rgba(255,255,255,.25);color:#fff}
    .tablecard{background:#fff;border:1px solid var(--card-border);border-radius:14px;padding:6px 16px 16px;box-shadow:0 1px 2px rgba(16,35,71,.04)}
    .tablecard table{border-radius:10px}
    .card .big{font-size:22px;font-weight:800} .card .sub{color:var(--muted);font-size:12px;margin-top:4px}
    table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden}
    th,td{padding:10px 12px;border-bottom:1px solid #eef1f4;text-align:right;font-size:13px}
    th{background:#f8fafc;color:#475569;font-weight:700} tr:hover td{background:#fafcff}
    .tag{display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;color:#fff}
    .tag-blue{background:#2563eb}.tag-green{background:#16a34a}.tag-red{background:#dc2626}.tag-orange{background:#ea580c}
    .tag-yellow{background:#ca8a04}.tag-gray{background:#6b7280}.tag-cyan{background:#0891b2}.tag-purple{background:#7c3aed}
    .btn{display:inline-block;padding:9px 18px;border-radius:9px;border:0;cursor:pointer;font-family:inherit;font-size:13px;font-weight:700}
    .btn-primary{background:var(--blue);color:#fff}.btn-green{background:#16a34a;color:#fff}.btn-red{background:#dc2626;color:#fff}
    .btn-gray{background:#eef1f6;color:#374151}.btn-sm{padding:5px 11px;font-size:12px}
    .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}
    label{display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px}
    input,select,textarea{width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:9px;font-family:inherit;font-size:13px;background:#fff}
    input:focus,select:focus,textarea:focus{outline:none;border-color:var(--blue2);box-shadow:0 0 0 3px rgba(59,130,246,.15)}
    .flash{background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;padding:10px 14px;border-radius:10px;margin-bottom:10px;font-weight:600}
    .muted{color:var(--muted);font-size:12px}.danger-box{background:#fef2f2;border:1px solid #fca5a5;border-radius:12px;padding:12px 16px;margin-bottom:14px;font-weight:600}
    .timeline{border-right:3px solid #e5e7eb;padding-right:16px}
    .tl-item{position:relative;margin-bottom:16px}.tl-item:before{content:"";position:absolute;right:-24px;top:4px;width:11px;height:11px;border-radius:50%;background:var(--blue)}
    .tl-pending{background:#fff;border:1px dashed #f59e0b;border-radius:10px;padding:10px 14px;margin:8px 0}
    .seg{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
    .seg a{padding:8px 16px;border-radius:9px;background:#fff;border:1px solid var(--card-border);font-weight:700;color:#374151}
    .seg a.active{background:var(--navy);color:#fff}
    .amount-words{font-size:12px;color:#15803d;margin-top:4px;min-height:16px;font-weight:700}
    .jdate{text-align:left;direction:ltr;cursor:pointer;background:#fff}
    .jp-wrap{position:absolute;z-index:9999;background:#fff;border:1px solid #cbd5e1;border-radius:12px;box-shadow:0 12px 32px rgba(15,35,71,.2);padding:10px;width:250px;direction:rtl}
    .jp-head{display:flex;justify-content:space-between;align-items:center;font-weight:700;margin-bottom:8px}
    .jp-head button{background:#f1f5f9;border:0;border-radius:6px;padding:2px 9px;cursor:pointer;font-size:15px}
    .jp-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center}
    .jp-grid .dow{font-size:11px;color:#64748b;padding:4px 0}
    .jp-grid .day{padding:6px 0;border-radius:6px;cursor:pointer;font-size:12px}
    .jp-grid .day:hover{background:#dbeafe}
    .jp-grid .today{background:#fef3c7;font-weight:700}
    .jp-grid .sel{background:var(--blue);color:#fff;font-weight:700}
    .jp-grid .empty{visibility:hidden}
    .scan-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-top:10px}
    .scan-row figure{margin:0;border:1px solid var(--card-border);border-radius:12px;padding:10px;background:#fafcff}
    .scan-row figcaption{font-size:12px;font-weight:700;color:#475569;margin-bottom:8px}
    .scan-row img{width:100%;max-height:320px;object-fit:contain;border-radius:8px;border:1px solid #eee;display:block}
    .img-preview{margin-top:8px}
    .img-preview img{max-width:180px;max-height:120px;border:1px solid #cbd5e1;border-radius:8px;margin:4px}
    </style></head><body>
    <div class="shell">';
    global $me;
    $pnow = $_GET['p'] ?? 'dashboard';
    $act = function($key) use ($pnow) { return ($pnow === $key) ? ' active' : ''; };
    echo '<aside class="sidebar" id="sidebar">
      <div class="brand"><div class="logo">🏦</div><div><div class="bt">مدیریت چک‌ها</div><div class="bs">CHEQUE SYSTEM</div></div></div>
      <nav class="nav">
        <div class="nav-sec">MAIN</div>
        <a class="nav-item' . $act('dashboard') . '" href="cheques.php?p=dashboard"><span class="ic">▦</span> داشبورد</a>
        <a class="nav-item' . $act('list') . '" href="cheques.php?p=list"><span class="ic">📄</span> فهرست چک‌ها</a>
        <a class="nav-item' . $act('create') . '" href="cheques.php?p=create"><span class="ic">＋</span> ثبت چک جدید</a>
        <a class="nav-item' . $act('approvals') . '" href="cheques.php?p=approvals"><span class="ic">✓</span> تأییدها'
          . ($pending ? '<span class="bdg">' . fa($pending) . '</span>' : '') . '</a>
        <div class="nav-sec">REPORTS &amp; BANKS</div>
        <a class="nav-item' . $act('reports') . '" href="cheques.php?p=reports"><span class="ic">📊</span> گزارش‌ها</a>
        <a class="nav-item' . $act('banks') . '" href="cheques.php?p=banks"><span class="ic">🏦</span> حساب‌های بانکی</a>
        <div class="nav-sec">BACK</div>
        <a class="nav-item" href="./"><span class="ic">←</span> بازگشت به ERP</a>
      </nav>
      <div class="nav-user"><span class="av">' . e(mb_substr((string)($me['name'] ?: 'A'), 0, 1)) . '</span><span>' . e($me['name']) . '</span></div>
    </aside>
    <div class="main">
      <div class="topbar">
        <button class="menu-toggle" onclick="document.getElementById(\'sidebar\').classList.toggle(\'open\')">☰</button>
        <h1>' . e($title) . '</h1>
        <a class="btn btn-primary" href="cheques.php?p=create">＋ ثبت چک جدید</a>
      </div>
      <div class="body">';
    foreach (get_flash() as $f) { echo '<div class="flash">' . e($f) . '</div>'; }
}
function render_footer() {
echo <<<'JS'
<script>
/* ====================== توابع تاریخ شمسی (Jalali) ====================== */
function toEnDigits(s){return (s||'').replace(/[۰-۹]/g,d=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g,d=>'٠١٢٣٤٥٦٧٨٩'.indexOf(d));}
function toFaDigits(s){return (s||'').replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[d]);}
function div(a,b){return Math.floor(a/b);}
function gregToJal(gy,gm,gd){
  var g_d_m=[0,31,59,90,120,151,181,212,243,273,304,334];
  var gy2=gy>1600?gy-1600:gy, gy3=gy>1600?979:0;
  var days=365*gy2+div(gy2+3,4)-div(gy2+99,100)+div(gy2+399,400)-80+gd+g_d_m[gm-1]+(gm>2&&((gy%4===0&&gy%100!==0)||gy%400===0)?1:0);
  var jy=-1595+33*div(days,12053); days%=12053;
  jy+=4*div(days,1461); days%=1461;
  if(days>365){jy+=div(days-1,365);days=(days-1)%365;}
  var jm=days<186?1+div(days,31):7+div(days-186,30);
  var jd=1+(days<186?days%31:(days-186)%30);
  return [jy+gy3,jm,jd];
}
function jalToGreg(jy,jm,jd){
  var gy2=jy>979?jy-979:jy, gy3=jy>979?1600:0;
  var days=365*gy2+div(gy2,33)*8+div((gy2%33)+3,4)-1+jd+(jm<7?(jm-1)*31:(jm-7)*30+186);
  var gy=400*div(days,146097); days%=146097;
  if(days>36524){gy+=100*div(days,36524);days%=36524;}
  gy+=4*div(days,1461);days%=1461;
  if(days>365){gy+=div(days-1,365);days=(days-1)%365;}
  var gd=days+1;
  var sal=[0,31,(gy%4===0&&gy%100!==0)||gy%400===0?29:28,31,30,31,30,31,31,30,31,30,31];
  var gm=1; for(;gm<=12&&gd>sal[gm];gm++) gd-=sal[gm];
  return [gy+gy3,gm,gd];
}
var JMONTHS=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
var JDOW=['ش','ی','د','س','چ','پ','ج'];
function pad(n){return (n<10?'0':'')+n;}

function initJDate(input){
  var hidden=document.getElementById('jdg_'+input.name);
  function setFromJal(jy,jm,jd){
    var g=jalToGreg(jy,jm,jd);
    input.value=toFaDigits(jy+'/'+pad(jm)+'/'+pad(jd));
    if(hidden) hidden.value=g[0]+'-'+pad(g[1])+'-'+pad(g[2]);
  }
  function parseInput(){
    var t=toEnDigits(input.value||'').trim().replace(/\s/g,'');
    var m=t.match(/^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$/);
    if(m) return [+m[1],+m[2],+m[3]];
    return null;
  }
  if(hidden && hidden.value){
    var parts=hidden.value.split('-');
    if(parts.length===3){ var j=gregToJal(+parts[0],+parts[1],+parts[2]); input.value=toFaDigits(j[0]+'/'+pad(j[1])+'/'+pad(j[2])); }
  }
  var box=null;
  function closeBox(){ if(box){ box.remove(); box=null; } }
  function jalLeap(jy){ var r=((jy-474)%2820+2820)%2820; return ((r+474+38)*682)%2816<682; }
  function openBox(){
    closeBox();
    var cur=parseInput();
    var today=gregToJal(new Date().getFullYear(),new Date().getMonth()+1,new Date().getDate());
    var jy=cur?cur[0]:today[0], jm=cur?cur[1]:today[1], jd=cur?cur[2]:today[2];
    box=document.createElement('div'); box.className='jp-wrap';
    document.body.appendChild(box);
    function render(){
      var g0=jalToGreg(jy,jm,1);
      var firstDay=new Date(g0[0],g0[1]-1,g0[2]);
      var lead=(firstDay.getDay()+1)%7;
      var dim = jm<=6 ? 31 : (jm<=11 ? 30 : (jalLeap(jy)?30:29));
      var html='<div class="jp-head"><button type="button" id="jpy-">◀</button><div><span id="jpym">'+JMONTHS[jm-1]+' '+toFaDigits(jy)+'</span></div><button type="button" id="jpy+">▶</button></div>';
      html+='<div class="jp-grid">';
      for(var d=0;d<7;d++) html+='<div class="dow">'+JDOW[d]+'</div>';
      for(var i=0;i<lead;i++) html+='<div class="empty"></div>';
      for(var day=1;day<=dim;day++){
        var cls='day';
        if(jy===today[0]&&jm===today[1]&&day===today[2]) cls+=' today';
        if(cur&&jy===cur[0]&&jm===cur[1]&&day===cur[2]) cls+=' sel';
        html+='<div class="'+cls+'" data-d="'+day+'">'+toFaDigits(day)+'</div>';
      }
      html+='</div>';
      box.innerHTML=html;
      box.querySelector('#jpy-').onclick=function(){ jm--; if(jm<1){jm=12;jy--;} render(); };
      box.querySelector('#jpy+').onclick=function(){ jm++; if(jm>12){jm=1;jy++;} render(); };
      box.querySelectorAll('.day').forEach(function(el){
        el.onclick=function(){ setFromJal(jy,jm,+el.getAttribute('data-d')); closeBox(); input.dispatchEvent(new Event('change')); };
      });
      var r=input.getBoundingClientRect();
      box.style.top=Math.round(window.scrollY+r.bottom+4)+'px';
      box.style.left=Math.round(window.scrollX+r.left)+'px';
    }
    render();
  }
  input.addEventListener('focus',openBox);
  input.addEventListener('click',openBox);
  input.addEventListener('change',function(){ var p=parseInput(); if(p) setFromJal(p[0],p[1],p[2]); });
  input.addEventListener('blur',function(){ setTimeout(closeBox,200); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape') closeBox(); });
}

function faNumWords(n){
  var ones=['','یک','دو','سه','چهار','پنج','شش','هفت','هشت','نه','ده','یازده','دوازده','سیزده','چهارده','پانزده','شانزده','هفده','هجده','نوزده'];
  var tens=['','','بیست','سی','چهل','پنجاه','شصت','هفتاد','هشتاد','نود'];
  var hund=['','صد','دویست','سیصد','چهارصد','پانصد','ششصد','هفتصد','هشتصد','نهصد'];
  var scale=['',' هزار',' میلیون',' میلیارد',' هزار میلیارد'];
  n=Math.floor(n);
  if(n===0) return 'صفر';
  var groups=[]; while(n>0){ groups.push(n%1000); n=Math.floor(n/1000); }
  var parts=[];
  for(var g=groups.length-1;g>=0;g--){
    var v=groups[g]; if(!v) continue; var t='';
    var h=Math.floor(v/100), r=v%100;
    if(h) t+=hund[h];
    if(r){ if(t) t+=' و ';
      if(r<20) t+=ones[r];
      else { t+=tens[Math.floor(r/10)]; if(r%10) t+=' و '+ones[r%10]; } }
    t+=scale[g]||''; parts.push(t);
  }
  return parts.join(' و ');
}
function initAmount(inp){
  var words = inp.id==='amount_toman'
      ? document.getElementById('amount_words')
      : inp.parentElement.querySelector('.amount-words');
  function refresh(){
    var raw=toEnDigits(inp.value).replace(/[^0-9]/g,'');
    var grouped=raw?toFaDigits(raw.replace(/\B(?=(\d{3})+(?!\d))/g,',')):'';
    inp.value=grouped;
    if(words){ words.textContent=raw?('= '+faNumWords(parseInt(raw,10))+' تومان'):''; }
  }
  inp.addEventListener('input',refresh);
  inp.addEventListener('change',refresh);
  refresh();
}

/* پیش‌نمایش تصویر قبل از آپلود */
document.addEventListener('change',function(ev){
  var t=ev.target;
  if(t.type==='file'){
    var holder=t.parentElement.querySelector('.img-preview');
    if(!holder){ holder=document.createElement('div'); holder.className='img-preview'; t.parentElement.appendChild(holder); }
    holder.innerHTML='';
    Array.prototype.forEach.call(t.files||[],function(f){
      if(!/^image\//.test(f.type)) return;
      var img=document.createElement('img'); img.src=URL.createObjectURL(f); holder.appendChild(img);
    });
  }
  /* انتخاب طرف حساب (جایگزین onchange خراب) */
  if(t.id==='party_type'){
    var cust=document.getElementById('party_cust'), sup=document.getElementById('party_sup');
    if(cust) cust.style.display = t.value==='customer' ? 'block' : 'none';
    if(sup)  sup.style.display  = t.value==='supplier' ? 'block' : 'none';
  }
  /* دفترچه چک → شماره بعدی (جایگزین onchange خراب) */
  if(t.id==='cb'){
    var n=t.options[t.selectedIndex].getAttribute('data-next');
    var cn=document.getElementById('cheque_number');
    if(n && cn){ cn.value=n; }
  }
});

document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('.jdate').forEach(initJDate);
  document.querySelectorAll('.amount-input').forEach(initAmount);
  /* وضعیت اولیه بخش طرف حساب */
  var pt=document.getElementById('party_type');
  if(pt){ pt.dispatchEvent(new Event('change')); }
});
</script>
JS;
echo '</div></div></div></body></html>'; }
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

    echo '<div class="hero blue"><span class="hico">🏦</span><div class="hbody"><h2>داشبورد چک‌ها</h2>'
       . '<div class="hsub">مرور کلی اسناد دریافتی، صادره و تضمینی</div></div>'
       . '<a class="hbtn" href="cheques.php?p=create">＋ ثبت چک جدید</a></div>';

    echo '<div class="cards">';
    echo '<div class="stat g"><div class="lab">چک دریافتی در جریان</div><div class="val">' . money($rec['s']) . '</div><div class="vsub">' . fa($rec['c']) . ' فقره</div></div>';
    echo '<div class="stat o"><div class="lab">سررسید ۷ روز آینده (دریافتی)</div><div class="val">' . money($due7['s']) . '</div><div class="vsub">' . fa($due7['c']) . ' فقره — برای سپرده‌گذاری</div></div>';
    echo '<div class="stat r"><div class="lab">چک صادره ۷ روز آینده</div><div class="val">' . money($iss7['s']) . '</div><div class="vsub">' . fa($iss7['c']) . ' فقره — موجودی را کنترل کنید</div></div>';
    echo '<div class="stat r"><div class="lab">برگشتی‌ها</div><div class="val">' . money($bounced['s']) . '</div><div class="vsub">' . fa($bounced['c']) . ' فقره — نیاز به پیگیری</div></div>';
    echo '<div class="stat c"><div class="lab">چک تضمینی در وثیقه</div><div class="val">' . fa($guar['c']) . ' <span style="font-size:14px;color:#6b7280">فقره</span></div><div class="vsub">جمع ' . money($guar['s']) . '</div></div>';
    echo '<div class="stat p"><div class="lab">در انتظار تأیید</div><div class="val">' . fa($pending['c']) . '</div><div class="vsub"><a href="cheques.php?p=approvals">مشاهده صف تأیید</a></div></div>';
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
    $dir = g('dir'); $kindF = g('kind'); $statusF = g('status'); $q = g('q');
    $where = "c.deleted_at IS NULL"; $p = array();
    if ($dir !== '')    { $where .= " AND c.direction=" . db()->quote($dir); }
    if ($kindF !== '')  { $where .= " AND c.kind=" . db()->quote($kindF); }
    if ($statusF !== ''){ $where .= " AND c.status=" . db()->quote($statusF); }
    if ($q !== '')      { $where .= " AND (c.cheque_number LIKE ? OR c.sayyad_id LIKE ? OR c.party_name LIKE ?)"; $like='%'.$q.'%'; $p=array($like,$like,$like); }
    $rows = q_all("SELECT c.* FROM checks c WHERE $where ORDER BY c.id DESC LIMIT 300", $p);

    $cnt = function($cond) {
        try { $r = q_one("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM checks WHERE deleted_at IS NULL AND $cond"); return $r; }
        catch (Exception $e) { return array('c'=>0,'s'=>0); }
    };
    $all   = $cnt("1=1");
    $rec   = $cnt("kind='payment' AND direction='received'");
    $iss   = $cnt("kind='payment' AND direction='issued'");
    $guar  = $cnt("kind='guarantee'");
    $bounc = $cnt("status='bounced'");

    echo '<div class="hero green"><span class="hico">🏦</span><div class="hbody"><h2>مدیریت چک‌ها</h2>'
       . '<div class="hsub">همه چک‌های دریافتی، صادره و تضمینی در یک نگاه</div></div>'
       . '<a class="hbtn" href="cheques.php?p=create">＋ چک جدید</a></div>';

    echo '<div class="pills">
        <a class="pill' . ($dir==='' ? ' active' : '') . '" href="cheques.php?p=list">همه <span class="n">' . fa($all['c']) . '</span></a>
        <a class="pill' . ($dir==='received' ? ' active' : '') . '" href="cheques.php?p=list&dir=received&kind=payment">💵 دریافتی <span class="n">' . fa($rec['c']) . '</span></a>
        <a class="pill' . ($dir==='issued' ? ' active' : '') . '" href="cheques.php?p=list&dir=issued&kind=payment">💸 صادره <span class="n">' . fa($iss['c']) . '</span></a>
        <a class="pill' . ($kindF==='guarantee' ? ' active' : '') . '" href="cheques.php?p=list&kind=guarantee">🤝 تضمینی <span class="n">' . fa($guar['c']) . '</span></a>
        <a class="pill' . ($statusF==='bounced' ? ' active' : '') . '" href="cheques.php?p=list&status=bounced" style="border-color:#fecaca;color:#b91c1c">❌ برگشتی <span class="n">' . fa($bounc['c']) . '</span></a>
      </div>';

    echo '<form method="get" style="margin-bottom:14px;display:flex;gap:8px"><input type="hidden" name="p" value="list">
          ' . ($dir!==''?'<input type="hidden" name="dir" value="'.e($dir).'">':'') . ($kindF!==''?'<input type="hidden" name="kind" value="'.e($kindF).'">':'') . ($statusF!==''?'<input type="hidden" name="status" value="'.e($statusF).'">':'') . '
          <input name="q" placeholder="🔍 جستجو با شماره چک، صیاد یا نام طرف..." value="' . e($q) . '">
          <button class="btn btn-primary">فیلتر</button></form>';

    echo '<div class="tablecard"><table><tr><th>#</th><th>نوع</th><th>شماره/صیاد</th><th>بانک</th><th>طرف حساب</th><th>مبلغ</th><th>سررسید</th><th>وضعیت</th><th></th></tr>';
    if (!$rows) echo '<tr><td colspan="9" class="muted" style="text-align:center;padding:30px">چکی یافت نشد.</td></tr>';
    foreach ($rows as $c) {
        $d = $c['kind'] === 'guarantee' ? $c['guarantee_return_date'] : $c['due_date'];
        echo '<tr><td>' . fa($c['id']) . '</td><td>' . kind_label($c['kind'], $c['direction']) . '</td>'
           . '<td><a href="cheques.php?p=detail&id=' . $c['id'] . '">' . e($c['cheque_number'] ?: '#' . $c['id']) . '</a><div class="muted">صیاد: ' . e($c['sayyad_id'] ?: '—') . '</div></td>'
           . '<td>' . e($c['bank_name'] ?: '—') . '</td><td>' . e($c['party_name'] ?: '—') . '</td>'
           . '<td><b>' . money($c['amount']) . '</b></td><td>' . jdate_long($d) . ' ' . due_badge($d) . '</td>'
           . '<td>' . status_tag($c['status']) . ($c['locked_at'] ? ' 🔒' : '') . '</td>'
           . '<td><a class="btn btn-gray btn-sm" href="cheques.php?p=detail&id=' . $c['id'] . '">مشاهده</a></td></tr>';
    }
    echo '</table></div><div class="muted" style="margin-top:8px">' . fa(count($rows)) . ' ردیف</div>';
    render_footer();
}

/* ---------- فرم ثبت ---------- */
function page_create() {
    global $me;
    render_header('ثبت چک جدید');
    $direction = in_array(g('direction','received'), array('received','issued'), true) ? g('direction') : 'received';
    $kind      = in_array(g('kind','payment'), array('payment','guarantee'), true) ? g('kind') : 'payment';
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
    echo '<div style="grid-column:1/-1"><label>مبلغ (تومان) *</label>'
       . '<input name="amount_toman" id="amount_toman" class="amount-input" inputmode="numeric" autocomplete="off" required placeholder="مثلاً ۵۰,۰۰۰,۰۰۰" style="font-size:16px;font-weight:700">'
       . '<div id="amount_words" class="amount-words"></div></div>';
    echo '<div><label>شماره چک</label><input name="cheque_number" id="cheque_number"></div>';
    echo '<div><label>شناسه صیاد (۱۶ رقم)</label><input name="sayyad_id" maxlength="20" inputmode="numeric" placeholder="در صورت داشتن"></div>';
    echo '<div><label>سری / سریال</label><div style="display:flex;gap:6px"><input name="series" placeholder="سری"><input name="serial" placeholder="سریال"></div></div>';
    echo '<div><label>نام بانک</label><input name="bank_name" list="banklist"><datalist id="banklist">';
    foreach (array('ملت','صادرات','ملی','سپه','تجارت','رفاه','پارسیان','پاسارگاد','سامان','آینده','کشاورزی','مسکن','شهر') as $bn) echo '<option>' . $bn . '</option>';
    echo '</datalist></div>';
    echo '<div><label>شعبه / کد شعبه</label><div style="display:flex;gap:6px"><input name="branch_name" placeholder="نام شعبه"><input name="branch_code" placeholder="کد" style="max-width:110px"></div></div>';
    echo '<div><label>حساب بانکی ما ' . ($direction === 'issued' ? '(حساب صادرکننده)' : '(حساب وصول)')
       . ' — <a href="cheques.php?p=banks" target="_blank" style="font-size:11px">＋ افزودن حساب جدید</a></label>'
       . '<select name="bank_account_id"><option value="">—</option>';
    foreach ($banks as $b) echo '<option value="' . $b['id'] . '">' . e($b['bank_name'] . ($b['account_no'] ? ' / ' . $b['account_no'] : '')) . '</option>';
    echo '</select></div>';

    /* طرف حساب */
    /* مقدار اولیه طرف حساب: برای صادره پیش‌فرض تأمین‌کننده، برای دریافتی مشتری */
    $defaultParty = $direction === 'issued' ? 'supplier' : 'customer';
    $partyLabel = $direction === 'received' ? 'صادرکننده چک (مشتری)' : 'ذی‌نفع (تأمین‌کننده)';
    echo '<div><label>' . $partyLabel . '</label><select name="party_type" id="party_type">'
       . '<option value="customer"' . ($defaultParty==='customer'?' selected':'') . '>مشتری</option>'
       . '<option value="supplier"' . ($defaultParty==='supplier'?' selected':'') . '>تأمین‌کننده</option>'
       . '<option value="other">سایر (نام دستی)</option></select></div>';
    echo '<div id="party_cust"' . ($defaultParty!=='customer'?' style="display:none"':'') . '><label>انتخاب مشتری</label><select name="customer_id"><option value="">—</option>';
    foreach ($customers as $c) echo '<option value="' . $c['id'] . '">' . e($c['name']) . '</option>';
    echo '</select></div>';
    echo '<div id="party_sup"' . ($defaultParty!=='supplier'?' style="display:none"':'') . '><label>انتخاب تأمین‌کننده</label><select name="supplier_id"><option value="">—</option>';
    foreach ($suppliers as $s) echo '<option value="' . $s['id'] . '">' . e($s['name']) . '</option>';
    echo '</select></div>';
    echo '<div><label>نام طرف (در صورت سایر)</label><input name="party_name"></div>';

    if ($kind === 'payment') {
        jinput('issue_date', date('Y-m-d'), 'تاریخ صدور');
        jinput('due_date', null, 'تاریخ سررسید', true);
    } else {
        echo '<div><label>بابت تضمین/وثیقه</label><input name="guarantee_reason" placeholder="مثلاً: تضمین قرارداد، گمرک، اجاره"></div>';
        jinput('guarantee_return_date', null, 'تاریخ استرداد مورد انتظار');
    }

    if ($direction === 'issued' && $kind === 'payment') {
        echo '<div><label>دفترچه چک (شماره بعدی خودکار)</label><select name="checkbook_id" id="cb"><option value="">— بدون دفترچه —</option>';
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
        <div><label>از شماره</label><input type="text" name="start_number" inputmode="numeric" required></div>
        <div><label>تا شماره</label><input type="text" name="end_number" inputmode="numeric" required></div>
        ' . jinput_ret('cb_received', date('Y-m-d'), 'تاریخ دریافت') . '
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
    echo '<div><b>اسکن:</b> ' . ($c['image_front'] ? '<a target="_blank" href="' . e(asset_url($c['image_front'])) . '">رو</a>' : '—') . ' | '
        . ($c['image_back'] ? '<a target="_blank" href="' . e(asset_url($c['image_back'])) . '">پشت</a>' : '—') . '</div>';
    if ($c['description']) echo '<div style="grid-column:1/-1" class="muted">' . e($c['description']) . '</div>';
    echo '</div></div>';

    /* تصویر چک (اسکن پشت/رو) */
    if ($c['image_front'] || $c['image_back']) {
        echo '<div class="card cheque-scan" style="margin-top:14px"><h4>🖼️ تصویر چک</h4><div class="scan-row">';
        foreach (array('image_front' => 'روی چک', 'image_back' => 'پشت چک') as $imgCol => $cap) {
            if (!empty($c[$imgCol])) {
                $href = e(asset_url($c[$imgCol]));
                $isPdf = preg_match('/\.pdf$/i', $href);
                echo '<figure><figcaption>' . $cap . '</figcaption>';
                if ($isPdf) echo '<a target="_blank" href="' . $href . '">📎 مشاهده PDF</a>';
                else echo '<a target="_blank" href="' . $href . '"><img src="' . $href . '" alt="' . $cap . '" loading="lazy" onerror="this.style.opacity=.3;this.title=\'فایل یافت نشد\'"></a>';
                echo '</figure>';
            }
        }
        echo '</div></div>';
    }

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
                    ' . jinput_ret('event_date', date('Y-m-d'), 'تاریخ رویداد');
                if (in_array($etype, array('clear','pass','deposit'), true))
                    echo '<div><label>مبلغ (تومان — در صورت وصول جزئی)</label><input name="amount_toman" class="amount-input" inputmode="numeric" autocomplete="off" placeholder="خالی = مبلغ کامل چک"><div class="amount-words"></div></div>';
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

/* ---------- حساب‌های بانکی ---------- */
function page_banks() {
    render_header('حساب‌های بانکی');
    $F = array(
        'bank_name'  => bank_field('bank_name'),
        'holder'     => bank_field('holder'),
        'account_no' => bank_field('account_no'),
        'card'       => bank_field('card'),
        'iban'       => bank_field('iban'),
        'branch'     => bank_field('branch'),
        'balance'    => bank_field('balance'),
        'currency'   => bank_field('currency'),
    );
    $cols = bank_cols();
    $used = array_filter($F);
    $banks = q_all("SELECT * FROM bank_accounts ORDER BY id DESC");

    echo '<div class="hero blue"><span class="hico">🏦</span><div class="hbody"><h2>حساب‌های بانکی</h2>'
       . '<div class="hsub">مدیریت حساب‌ها، شماره‌ها و مانده‌ها برای چک‌های صادره و وصولی</div></div></div>';

    echo '<div class="tablecard"><h4 style="margin:12px 0">حساب‌های ثبت‌شده (' . fa(count($banks)) . ')</h4>';
    if (!$banks) echo '<div class="muted" style="padding:20px;text-align:center">هنوز حسابی ثبت نشده — فرم پایین را پر کنید.</div>';
    else {
        $labels = array('bank_name'=>'بانک','holder'=>'دارنده','account_no'=>'شماره حساب','card'=>'کارت','iban'=>'شبا','branch'=>'شعبه','balance'=>'مانده','currency'=>'ارز');
        echo '<table><tr><th>#</th>';
        foreach ($F as $role=>$col) { if ($col) echo '<th>' . $labels[$role] . '</th>'; }
        /* سایر ستون‌های شناسایی‌نشده هم نمایش داده شوند */
        $extra = array_values(array_diff($cols, array_values($used)));
        foreach ($extra as $ec) { if (preg_match('/^(id|created_at|updated_at|deleted_at|user_id|company_id)$/i',$ec)) continue; echo '<th>' . e($ec) . '</th>'; }
        echo '</tr>';
        foreach ($banks as $b) {
            echo '<tr><td>' . fa($b['id']) . '</td>';
            foreach ($F as $role=>$col) {
                if (!$col) continue;
                $v = $b[$col] ?? null;
                if ($role === 'balance' && $v !== null && $v !== '') $v = fa(number_format((float)$v)) . ' ' . e($b[$F['currency']] ?? '');
                echo '<td>' . ($v === null || $v === '' ? '<span class="muted">—</span>' : e($v)) . '</td>';
            }
            foreach ($extra as $ec) { if (preg_match('/^(id|created_at|updated_at|deleted_at|user_id|company_id)$/i',$ec)) continue; echo '<td class="muted">' . e($b[$ec] ?? '—') . '</td>'; }
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    echo '<form method="post" class="card" style="margin-top:16px">' . csrf_field();
    echo '<input type="hidden" name="action" value="bank">';
    echo '<h4>＋ افزودن حساب بانکی جدید</h4><div class="form-grid">';
    echo '<div><label>نام بانک *</label><input name="bank_name" list="banklist2" required><datalist id="banklist2">';
    foreach (array('ملت','صادرات','ملی','سپه','تجارت','رفاه','پارسیان','پاسارگاد','سامان','آینده','کشاورزی','مسکن','شهر') as $bn) echo '<option>' . $bn . '</option>';
    echo '</datalist></div>';
    echo '<div><label>دارنده حساب</label><input name="account_name" placeholder="نام شرکت/شخص"></div>';
    echo '<div><label>شماره حساب</label><input name="account_number"></div>';
    echo '<div><label>شماره کارت</label><input name="card_number" inputmode="numeric" maxlength="20"></div>';
    echo '<div><label>شبا (IBAN)</label><input name="iban" placeholder="IR..."></div>';
    echo '<div><label>شعبه</label><input name="branch_name"></div>';
    echo '<div><label>مانده فعلی (همان واحد ذخیره سیستم)</label><input name="balance" class="amount-input" inputmode="numeric" autocomplete="off"><div class="amount-words"></div></div>';
    echo '</div><button class="btn btn-primary" style="margin-top:12px">💾 ثبت حساب</button>';
    echo '<div class="muted" style="margin-top:8px">فیلدها به‌صورت خودکار با ستون‌های جدول bank_accounts شما تطبیق داده می‌شوند.</div>';
    echo '</form>';
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
    case 'banks':      page_banks(); break;
    default:           page_dashboard();
}
