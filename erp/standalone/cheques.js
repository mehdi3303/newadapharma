var BANK_SMS_MAP = {"ملت": "700700", "صادرات": "60060", "ملی": "700070", "سپه": "60000", "تجارت": "70070", "رفاه": "70080", "پارسیان": "7008", "پاسارگاد": "70000", "سامان": "700700", "آینده": "70070", "کشاورزی": "600060", "مسکن": "700080", "شهر": "70007"};
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

function gregMonthsEn(){ return ['January','February','March','April','May','June','July','August','September','October','November','December']; }
function formatGreg(g){
  if(!g) return '';
  return toFaDigits(g[2])+' '+gregMonthsEn()[g[1]-1]+' '+toFaDigits(g[0])+'  /  '+g[0]+'-'+pad(g[1])+'-'+pad(g[2]);
}
function initJDate(input){
  if(input.dataset.jdateReady==='1') return;
  input.dataset.jdateReady='1';
  /* فیلد مخفی میلادی، در همان کادر فیلد قرار دارد (برای فرم‌های متعدد با نام تکراری) */
  var hidden=null, hint=null;
  var p=input.parentElement;
  if(p){
    hidden=p.querySelector("input[name='"+input.name+"_g']");
    hint=p.querySelector('.greg-hint');
  }
  if(!hidden) hidden=document.getElementById('jdg_'+input.name);
  if(!hint) hint=document.getElementById('greg_'+input.name);
  function showHint(){
    if(!hint) return;
    if(hidden && hidden.value && /^\d{4}-\d{2}-\d{2}$/.test(hidden.value)){
      var pa=hidden.value.split('-');
      hint.textContent='برابر میلادی: '+formatGreg([+pa[0],+pa[1],+pa[2]]);
      hint.classList.remove('empty');
    } else {
      hint.textContent='معادل میلادی زیر تاریخ نشان داده می‌شود';
      hint.classList.add('empty');
    }
  }
  function setFromJal(jy,jm,jd){
    var g=jalToGreg(jy,jm,jd);
    input.value=toFaDigits(jy+'/'+pad(jm)+'/'+pad(jd));
    if(hidden) hidden.value=g[0]+'-'+pad(g[1])+'-'+pad(g[2]);
    showHint();
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
  showHint();
  var box=null;
  function closeBox(){ if(box){ box.remove(); box=null; } }
  function jalLeap(jy){ var r=((jy-474)%2820+2820)%2820; return ((r+474+38)*682)%2816<682; }
  function openBox(){
    if(box) return;
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
      var html='<div class="jp-head"><button type="button" id="jpy-">▶</button><div><span id="jpym">'+JMONTHS[jm-1]+' '+toFaDigits(jy)+'</span></div><button type="button" id="jpy+">◀</button></div>';
      html+='<div class="jp-grid">';
      for(var d=0;d<7;d++) html+='<div class="dow">'+JDOW[d]+'</div>';
      for(var i=0;i<lead;i++) html+='<div class="empty"></div>';
      for(var day=1;day<=dim;day++){
        var cls='day';
        if(jy===today[0]&&jm===today[1]&&day===today[2]) cls+=' today';
        if(cur&&jy===cur[0]&&jm===cur[1]&&day===cur[2]) cls+=' sel';
        html+='<div class="'+cls+'" data-d="'+day+'">'+toFaDigits(day)+'</div>';
      }
      html+='</div><div style="text-align:center;margin-top:6px"><button type="button" id="jptoday" style="width:100%;background:#eff6ff;border:0;color:#1d4ed8;border-radius:6px;padding:5px;cursor:pointer;font-weight:700;font-family:inherit">امروز</button></div>';
      box.innerHTML=html;
      box.querySelector('#jpy-').onclick=function(){ jm--; if(jm<1){jm=12;jy--;} render(); };
      box.querySelector('#jpy+').onclick=function(){ jm++; if(jm>12){jm=1;jy++;} render(); };
      box.querySelector('#jptoday').onclick=function(){ setFromJal(today[0],today[1],today[2]); closeBox(); input.dispatchEvent(new Event('change')); };
      box.querySelectorAll('.day').forEach(function(el){
        el.onmousedown=function(ev){ ev.preventDefault(); };
        el.onclick=function(){ setFromJal(jy,jm,+el.getAttribute('data-d')); closeBox(); input.dispatchEvent(new Event('change')); };
      });
      var r=input.getBoundingClientRect();
      var top=Math.round(window.scrollY+r.bottom+4);
      /* اگر پایین صفحه جا نبود، بالای فیلد باز کن */
      if(r.bottom+300 > window.innerHeight) top=Math.round(window.scrollY+r.top-300-4);
      box.style.top=top+'px';
      box.style.left=Math.round(window.scrollX+r.left)+'px';
    }
    render();
    /* بستن با کلیک بیرون تقویم (به‌جای blur که با کلیک روز تداخل داشت) */
    setTimeout(function(){
      document.addEventListener('mousedown',function h(ev){
        if(box && !box.contains(ev.target) && ev.target!==input){ closeBox(); document.removeEventListener('mousedown',h); }
      });
    },0);
  }
  input.addEventListener('focus',openBox);
  input.addEventListener('click',openBox);
  input.addEventListener('input',function(){ var pr=parseInput(); if(pr) setFromJal(pr[0],pr[1],pr[2]); else { if(hidden) hidden.value=''; showHint(); } });
  input.addEventListener('change',function(){ var pr=parseInput(); if(pr) setFromJal(pr[0],pr[1],pr[2]); });
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
  if(inp.dataset.amountReady==='1') return;
  inp.dataset.amountReady='1';
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
function syncPartyFields(){
  var pt=document.getElementById('party_type');
  if(!pt) return;
  var cust=document.getElementById('party_cust'), sup=document.getElementById('party_sup'), other=document.getElementById('party_other');
  if(cust) cust.style.display = pt.value==='customer' ? 'block' : 'none';
  if(sup) sup.style.display = pt.value==='supplier' ? 'block' : 'none';
  if(other) other.style.display = pt.value==='other' ? 'block' : 'none';
}

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
    syncPartyFields();
  }
  /* دفترچه چک → شماره بعدی (جایگزین onchange خراب) */
  if(t.id==='cb'){
    var n=t.options[t.selectedIndex].getAttribute('data-next');
    var cn=document.getElementById('cheque_number');
    if(n && cn){ cn.value=n; }
  }
});

/* استعلام: به‌روزرسانی شماره پیامک با انتخاب بانک */
function bankSmsChange(){
  var sel=document.getElementById('inq_bank'); if(!sel) return;
  var bank=sel.value, info=BANK_SMS_MAP[bank]||{};
  var n=document.getElementById('inq_smsnum'); if(n) n.value=info.num||'';
  var n2=document.getElementById('inq_smsnum2'); if(n2) n2.value=info.num||'';
  var bn=document.getElementById('inq_bankname'); if(bn) bn.value=bank;
}
function copySms(){
  var el=document.getElementById('sms_text'); if(!el) return;
  var t=el.textContent.trim();
  if(navigator.clipboard){ navigator.clipboard.writeText(t); } else {
    var ta=document.createElement('textarea'); ta.value=t; document.body.appendChild(ta); ta.select();
    try{document.execCommand('copy');}catch(e){} document.body.removeChild(ta);
  }
  alert('متن کپی شد');
}

/* ریسک لحظه‌ای طرف حساب در فرم ثبت چک */
function refreshRisk(){
  var box=document.getElementById('riskbox'); if(!box) return;
  var pt=document.getElementById('party_type'); if(!pt) return;
  var type=pt.value, pid='', name='';
  if(type==='customer'){ var c=document.getElementById('customer_id'); pid=c?c.value:''; }
  else if(type==='supplier'){ var s=document.getElementById('supplier_id'); pid=s?s.value:''; }
  else { var n=document.getElementById('party_name'); name=n?n.value.trim():''; }
  if(!pid && !name){ box.style.display='none'; return; }
  var url='cheques.php?api=risk&party_type='+encodeURIComponent(type)+'&party_id='+encodeURIComponent(pid)+'&party_name='+encodeURIComponent(name);
  box.style.display='block'; box.innerHTML='<div class="muted">در حال محاسبه ریسک...</div>';
  fetch(url).then(r=>r.json()).then(d=>{
    if(!d.ok){ box.style.display='none'; return; }
    var col = d.level==='high' ? '#dc2626' : (d.level==='medium' ? '#ca8a04' : (d.level==='unknown' ? '#64748b' : '#16a34a'));
    var scoreText = d.level==='unknown' ? 'بدون سابقه ثبت‌شده' : (toFaDigits(d.score)+'/100');
    box.innerHTML='<div class="card" style="border-right:4px solid '+col+'">'
      + '<b>🧮 امتیاز اعتبار طرف: '+scoreText+'</b> '
      + (d.banned? ' <span class="tag tag-red">محروم از دسته‌چک!</span>' : '')
      + '<div class="muted" style="margin-top:4px">چک‌های ثبت‌شده: '+toFaDigits(d.total||0)+' · برگشتی: '+toFaDigits(d.bounced||0)+' · باز سررسید: '+toFaDigits(d.overdue||0)+'</div>'
      + '<div style="margin-top:6px;color:'+col+';font-weight:700">'+d.advice+'</div></div>';
  }).catch(()=>{ box.style.display='none'; });
}

/* ====================== جستجوی سند/فاکتور ====================== */
var docState={timer:null};
function fmtDocAmt(r){
  if(r.amt===null||r.amt===undefined) return 'مبلغ ثبت‌نشده';
  var s=toFaDigits(String(Math.round(r.amt)).replace(/\B(?=(\d{3})+(?!\d))/g,','));
  return 'مبلغ: '+s+(r.cur?(' '+r.cur):'');
}
function docSearch(){
  var kind=document.getElementById('doc_type');
  var q=document.getElementById('doc_q');
  var res=document.getElementById('doc_results');
  if(!kind||!q||!res) return;
  if(!kind.value){ res.innerHTML='<div class="di muted">اول نوع سند را انتخاب کنید…</div>'; res.style.display='block'; return; }
  var term=q.value.trim();
  if(term.length<1){ res.style.display='none'; return; }
  res.innerHTML='<div class="di muted">در حال جستجو…</div>'; res.style.display='block';
  fetch('cheques.php?api=doc_search&kind='+encodeURIComponent(kind.value)+'&q='+encodeURIComponent(term))
    .then(function(r){return r.json();})
    .then(function(d){
      if(!d.ok||!d.rows||!d.rows.length){ res.innerHTML='<div class="di muted">سندی پیدا نشد — شماره/نوع را بررسی کنید</div>'; return; }
      res.innerHTML='';
      d.rows.forEach(function(r){
        var di=document.createElement('div'); di.className='di';
        di.innerHTML='<b>'+r.kind+' #'+(r.num||r.id)+'</b> '+(r.party?(' · '+r.party):'')+' <span class="damt" style="float:left">'+fmtDocAmt(r)+'</span>';
        di.onmousedown=function(ev){ ev.preventDefault(); };
        di.onclick=function(){ docPick(r,kind.options[kind.selectedIndex].text); };
        res.appendChild(di);
      });
    }).catch(function(){ res.innerHTML='<div class="di muted">خطا در جستجو</div>'; });
}
function docPick(r,kindLabel){
  document.getElementById('ref_type').value=document.getElementById('doc_type').value;
  document.getElementById('ref_id').value=r.id;
  document.getElementById('doc_results').style.display='none';
  document.getElementById('doc_q').value='';
  var chip=document.getElementById('doc_chip');
  chip.innerHTML='<div class="docchip">✅ متصل به: '+kindLabel+' #'+(r.num||r.id)+(r.party?(' — '+r.party):'')
    +' <span style="margin-right:auto">'+fmtDocAmt(r)+'</span><button type="button" onclick="docClear()">× حذف اتصال</button></div>';
}
function docClear(){
  document.getElementById('ref_type').value='';
  document.getElementById('ref_id').value='';
  document.getElementById('doc_chip').innerHTML='';
}
function initDocPick(){
  var root=document.getElementById('docpick');
  if(root && root.dataset.docReady==='1') return;
  if(root) root.dataset.docReady='1';
  var kind=document.getElementById('doc_type');
  var q=document.getElementById('doc_q');
  var res=document.getElementById('doc_results');
  if(!kind||!q) return;
  kind.addEventListener('change',function(){ res.style.display='none'; if(q.value.trim()) docSearch(); });
  q.addEventListener('input',function(){ clearTimeout(docState.timer); docState.timer=setTimeout(docSearch,250); });
  q.addEventListener('keydown',function(ev){ if(ev.key==='Enter'){ ev.preventDefault(); docSearch(); } });
  q.addEventListener('focus',function(){ if(q.value.trim()) docSearch(); });
  document.addEventListener('mousedown',function(ev){
    if(res && !document.getElementById('docpick').contains(ev.target)) res.style.display='none';
  });
}

/* ====================== پیش‌نمایش زنده چک صیادی ====================== */
function cpEl(id){ return document.getElementById(id); }
function cpText(id,txt,ph){ var el=cpEl(id); if(!el) return; el.innerHTML=txt?txt:('<span class="cp-ph">'+ph+'</span>'); }
function updateChequePreview(){
  if(!cpEl('cheque_preview')) return;
  /* مبلغ */
  var amt=document.getElementById('amount_toman');
  var raw=amt?toEnDigits(amt.value).replace(/[^0-9]/g,''):'';
  cpText('cp_amount', raw?toFaDigits(raw.replace(/\B(?=(\d{3})+(?!\d))/g,',')):'', '0');
  cpText('cp_words', raw?(faNumWords(parseInt(raw,10))+' تومان'):'', 'مبلغ چک به حروف اینجا نوشته می‌شود…');
  var ni=document.getElementById('national_id');
  cpText('cp_national', ni&&ni.value.trim()?toFaDigits(toEnDigits(ni.value.trim()).replace(/[^0-9]/g,'')):'', '—');
  /* شماره چک */
  var cn=document.getElementById('cheque_number');
  cpText('cp_cheque', cn&&cn.value.trim()?toFaDigits(cn.value.trim()):'', '_ _ _ _ _ _');
  /* صیاد */
  var sy=document.getElementsByName('sayyad_id')[0];
  if(sy && sy.value.trim()){ cpText('cp_sayyad', toFaDigits(toEnDigits(sy.value.trim()).replace(/[^0-9]/g,''))); }
  else cpText('cp_sayyad','','_ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _');
  /* بانک */
  var bk=document.getElementsByName('bank_name')[0];
  cpText('cp_bank', bk&&bk.value.trim()?bk.value.trim():'', 'بانک …');
  /* تاریخ سرصدور */
  var due=document.querySelector("input[name='due_date']");
  var iss=document.querySelector("input[name='issue_date']");
  var grt=document.querySelector("input[name='guarantee_return_date']");
  var dt = (grt && grt.value.trim()) ? grt : ((due && due.value.trim()) ? due : iss);
  cpText('cp_date', dt&&dt.value.trim()?dt.value.trim():'', '۱۴۰_/__/__');
  /* ذی‌نفع */
  var pt=document.getElementById('party_type');
  var payee='';
  if(pt){
    if(pt.value==='customer'){ var c=document.getElementById('customer_id'); if(c&&c.selectedIndex>0) payee=c.options[c.selectedIndex].text; }
    else if(pt.value==='supplier'){ var s=document.getElementById('supplier_id'); if(s&&s.selectedIndex>0) payee=s.options[s.selectedIndex].text; }
  }
  var pn=document.getElementById('party_name');
  if(!payee && pn && pn.value.trim()) payee=pn.value.trim();
  cpText('cp_payee', payee, 'نام ذی‌نفع / صادرکننده…');
}
function initChequePreview(){
  if(!cpEl('cheque_preview')) return;
  if(cpEl('cheque_preview').dataset.previewReady==='1') return;
  cpEl('cheque_preview').dataset.previewReady='1';
  var ids=['amount_toman','national_id','cheque_number','party_name','party_type','customer_id','supplier_id'];
  ids.forEach(function(id){ var el=document.getElementById(id); if(el){ el.addEventListener('input',updateChequePreview); el.addEventListener('change',updateChequePreview); } });
  ['sayyad_id','bank_name'].forEach(function(nm){ var el=document.getElementsByName(nm)[0]; if(el){ el.addEventListener('input',updateChequePreview); el.addEventListener('change',updateChequePreview); } });
  /* تاریخ‌ها: بعد از تغییر مقدار مخفی میلادی توسط تقویم */
  setInterval(updateChequePreview, 400);
  updateChequePreview();
}

document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('.jdate').forEach(initJDate);
  document.querySelectorAll('.amount-input').forEach(initAmount);
  /* پیش‌نمایش باید مستقل از خطاهای احتمالی جستجوی سند اجرا شود. */
  try { initChequePreview(); } catch(e) { console.error('cheque preview init', e); }
  try { initDocPick(); } catch(e) { console.error('document picker init', e); }
  var pt=document.getElementById('party_type');
  if(pt){
    syncPartyFields();
    pt.dispatchEvent(new Event('change'));
    ['party_type','customer_id','supplier_id','party_name'].forEach(function(id){
      var el=document.getElementById(id);
      if(el) el.addEventListener('change',refreshRisk);
    });
    var pn=document.getElementById('party_name');
    if(pn) pn.addEventListener('blur',refreshRisk);
    refreshRisk();
  }
});
/* fallback: حتی اگر یک widget دیگر خطا داد، پیش‌نمایش با تغییر فرم به‌روز بماند */
document.addEventListener('DOMContentLoaded',function(){
  try { updateChequePreview(); } catch(e) { console.error('cheque preview update', e); }
  document.addEventListener('input',function(ev){
    if(ev.target && (ev.target.id==='amount_toman' || ev.target.id==='national_id' || ev.target.name==='bank_name' || ev.target.name==='sayyad_id' || ev.target.id==='cheque_number')) {
      try { updateChequePreview(); } catch(e) {}
    }
  });
});
/* اجرای پشتیبان برای هاست‌هایی که اسکریپت را بعد از DOMContentLoaded تزریق می‌کنند */
window.addEventListener('load',function(){
  try { document.querySelectorAll('.jdate').forEach(initJDate); } catch(e) {}
  try { document.querySelectorAll('.amount-input').forEach(initAmount); } catch(e) {}
  try { initDocPick(); } catch(e) {}
  try { initChequePreview(); updateChequePreview(); } catch(e) {}
});
