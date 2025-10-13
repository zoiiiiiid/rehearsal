<?php
/* =================================================================
 * File: backend/web/web_register.php
 * Uses: /api/register.php then /api/login.php
 * ================================================================= */
declare(strict_types=1);
require __DIR__.'/web_common.php';

$err = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  csrf_check();
  $name  = trim($_POST['name'] ?? '');
  $user  = strtolower(trim($_POST['username'] ?? ''));
  $email = trim($_POST['email'] ?? '');
  $pass  = (string)($_POST['password'] ?? '');
  $pass2 = (string)($_POST['password2'] ?? '');
  $dob   = trim($_POST['birthdate'] ?? '');

  if (!$name || !$user || !$email || !$pass || !$pass2 || !$dob) {
    $err = 'All fields are required.';
  } elseif ($pass !== $pass2) {
    $err = 'Passwords do not match.';
  } else {
    // quick 18+ guard (server still validates in /api/register.php)
    try {
      $d = new DateTime($dob);
      $today = new DateTime('today');
      if ((int)$d->diff($today)->y < 18) $err = 'You must be at least 18 years old.';
    } catch (Throwable $e) {
      $err = 'Invalid birthdate.';
    }
  }

  if (!$err) {
    // Send to API (token passed is harmless; API may ignore it)
    $r = api_post_json('register.php', [
      'name'      => $name,
      'username'  => $user,
      'email'     => $email,
      'password'  => $pass,
      'birthdate' => $dob,  // API stores it if the column exists (optional)
    ]);

    if (!empty($r['ok']) && empty($r['error'])) {
      $login = api_post_json('login.php', ['login'=>$email, 'password'=>$pass]);
      if (!empty($login['token'])) {
        web_set_token($login['token']);
        header('Location: '.WEB_BASE.'/index.php');
        exit;
      }
      $err = $login['error'] ?? 'Auto-login failed.';
    } else {
      $err = $r['error'] ?? 'Registration failed.';
    }
  }
}

render_header('Create account');
?>
<style>
  .reg-wrap{max-width:560px;margin:0 auto}
  .reg-card{background:var(--card);border:1px solid var(--bd);border-radius:14px;padding:18px}
  .brand-hero{font-size:28px;font-weight:800;text-align:center;margin:8px 0 2px}
  .subtitle{color:var(--mut);text-align:center;margin-bottom:14px}
  .field{display:flex;align-items:center;gap:8px;border:1px solid #e5e7eb;border-radius:12px;padding:10px 12px;background:#fff}
  .field input{border:0;outline:0;width:100%;font:inherit;background:transparent}
  .row{margin-top:10px}
  .help{font-size:12px;margin-top:6px}
  .help.muted{color:var(--mut)}
  .help.ok{color:#0a7}
  .help.bad{color:#b00000}
  .btn-wide{width:100%;margin-top:12px}
  .notice{background:#fff0f0;border:1px solid #ffd1d1;color:#b00000;padding:10px;border-radius:10px;margin-bottom:10px}
  .eye{cursor:pointer}
  .two{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  @media (max-width:520px){.two{grid-template-columns:1fr}}
  .icon{width:18px;height:18px;flex:0 0 18px;color:#999}
</style>

<div class="reg-wrap">
  <div class="brand-hero">RE:HEARSAL</div>
  <div class="subtitle">Register</div>

  <?php if ($err): ?><div class="notice"><?=h($err)?></div><?php endif; ?>

  <form method="post" class="reg-card" id="regForm" novalidate>
    <?php csrf_field(); ?>

    <!-- Full name -->
    <div class="row">
      <label class="field">
        <svg class="icon" viewBox="0 0 24 24" fill="none"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-5 0-9 3-9 6v2h18v-2c0-3-4-6-9-6Z" stroke="currentColor" stroke-width="1"/></svg>
        <input name="name" id="name" placeholder="Full name" required value="<?=h($_POST['name'] ?? '')?>">
      </label>
    </div>

    <!-- Username -->
    <div class="row">
      <label class="field">
        <svg class="icon" viewBox="0 0 24 24" fill="none"><path d="M4 8a4 4 0 0 1 8 0v1h5a3 3 0 0 1 0 6h-5v1a4 4 0 0 1-8 0" stroke="currentColor" stroke-width="1"/></svg>
        <input name="username" id="username" placeholder="Username" minlength="3" maxlength="20" required autocomplete="off" value="<?=h($_POST['username'] ?? '')?>">
      </label>
      <div id="u-help" class="help muted">Letters, numbers or underscore. 3–20 characters.</div>
    </div>

    <!-- Email -->
    <div class="row">
      <label class="field">
        <svg class="icon" viewBox="0 0 24 24" fill="none"><path d="M3 6h18v12H3zM3 6l9 7 9-7" stroke="currentColor" stroke-width="1"/></svg>
        <input type="email" name="email" id="email" placeholder="Email" required value="<?=h($_POST['email'] ?? '')?>">
      </label>
      <div id="e-help" class="help muted"></div>
    </div>

    <!-- Birthdate (18+) -->
    <div class="row">
      <label class="field">
        <svg class="icon" viewBox="0 0 24 24" fill="none"><path d="M7 3v3M17 3v3M3 8h18v13H3z" stroke="currentColor" stroke-width="1"/></svg>
        <input type="date" name="birthdate" id="birthdate" required value="<?=h($_POST['birthdate'] ?? '')?>">
      </label>
      <div id="b-help" class="help muted">You must be at least 18 years old.</div>
    </div>

    <!-- Passwords -->
    <div class="row two">
      <label class="field">
        <svg class="icon" viewBox="0 0 24 24" fill="none"><path d="M6 10h12v10H6zM8 10V7a4 4 0 1 1 8 0v3" stroke="currentColor" stroke-width="1"/></svg>
        <input type="password" name="password" id="password" placeholder="Password (min 6)" minlength="6" required>
        <svg class="icon eye" id="pw1" viewBox="0 0 24 24" fill="none" title="Show/hide"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Zm11 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" stroke="currentColor" stroke-width="1"/></svg>
      </label>

      <label class="field">
        <svg class="icon" viewBox="0 0 24 24" fill="none"><path d="M6 10h12v10H6zM8 10V7a4 4 0 1 1 8 0v3" stroke="currentColor" stroke-width="1"/></svg>
        <input type="password" name="password2" id="password2" placeholder="Confirm password" minlength="6" required>
        <svg class="icon eye" id="pw2" viewBox="0 0 24 24" fill="none" title="Show/hide"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Zm11 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" stroke="currentColor" stroke-width="1"/></svg>
      </label>
    </div>
    <div id="p-help" class="help muted"></div>

    <button class="btn btn-wide">Create account</button>
  </form>

  <div style="text-align:center;margin-top:10px">
    Already have an account? <a href="<?=WEB_BASE?>/web_login.php">Log in</a>
  </div>
</div>

<script>
const API_BASE = <?= json_encode(API_BASE_URL) ?>;
const TOKEN    = <?= json_encode(web_token()) ?>;

const $ = (s, c=document)=>c.querySelector(s);
function debounce(fn,ms){let t;return(...a)=>{clearTimeout(t);t=setTimeout(()=>fn(...a),ms);}}

/* -------- Set 18+ constraint on date input -------- */
(function(){
  const inp = $('#birthdate'); if(!inp) return;
  const d = new Date(); d.setHours(0,0,0,0);
  d.setFullYear(d.getFullYear()-18);
  const yyyy=d.getFullYear(), mm=String(d.getMonth()+1).padStart(2,'0'), dd=String(d.getDate()).padStart(2,'0');
  const max = `${yyyy}-${mm}-${dd}`;
  inp.setAttribute('max', max);
  // (optional) sensible minimum
  inp.setAttribute('min', '1900-01-01');
})();

/* -------- Password show/hide + match hint -------- */
function togglePw(btnId, inpId){
  const btn=$(btnId), inp=$(inpId); if(!btn||!inp) return;
  btn.addEventListener('click',()=>{ inp.type = inp.type==='password' ? 'text' : 'password'; });
}
togglePw('#pw1','#password'); togglePw('#pw2','#password2');

function updatePwHelp(){
  const a=$('#password'), b=$('#password2'), h=$('#p-help'); if(!a||!b||!h) return;
  if(!a.value && !b.value){ h.textContent=''; return; }
  if(a.value.length<6){ h.textContent='Password must be at least 6 characters.'; h.className='help bad'; return; }
  if(a.value!==b.value){ h.textContent='Passwords do not match.'; h.className='help bad'; return; }
  h.textContent='Passwords look good.'; h.className='help ok';
}
$('#password')?.addEventListener('input',updatePwHelp);
$('#password2')?.addEventListener('input',updatePwHelp);

/* -------- Username availability (your username_check.php?u=) -------- */
const uHelp = $('#u-help');
const checkUsername = debounce(async ()=>{
  const el = $('#username'); if(!el||!uHelp) return;
  const v = (el.value||'').trim().toLowerCase();
  if (!v){ uHelp.textContent='Letters, numbers or underscore. 3–20 characters.'; uHelp.className='help muted'; return; }
  if (!/^[a-z0-9_]{3,20}$/.test(v)){ uHelp.textContent='Invalid format.'; uHelp.className='help bad'; return; }
  try{
    const r = await fetch(API_BASE + 'username_check.php?u='+encodeURIComponent(v), {headers:{'Accept':'application/json','Authorization':'Bearer '+TOKEN}});
    const j = await r.json();
    if (j && typeof j.available==='boolean') {
      uHelp.textContent = j.available ? 'Username is available.' : 'Username is already taken.';
      uHelp.className = 'help ' + (j.available ? 'ok' : 'bad');
    } else { uHelp.textContent=''; uHelp.className='help muted'; }
  }catch{ uHelp.textContent=''; uHelp.className='help muted'; }
}, 300);
$('#username')?.addEventListener('input', checkUsername);

/* -------- Email availability (tries email_available.php then email_check.php if present) -------- */
const eHelp = $('#e-help');
async function emailAvailable(v){
  const eps = [ 'email_available.php', 'email_check.php' ];
  for (const ep of eps){
    try{
      const r = await fetch(API_BASE + ep + '?email='+encodeURIComponent(v), {headers:{'Accept':'application/json'}});
      if (!r.ok) continue;
      const j = await r.json();
      if (j && typeof j.available==='boolean') return j.available;
    }catch{}
  }
  return null; // unknown
}
const checkEmail = debounce(async ()=>{
  const el=$('#email'); if(!el||!eHelp) return;
  const v=(el.value||'').trim();
  if(!v){ eHelp.textContent=''; eHelp.className='help muted'; return; }
  if(!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v)){ eHelp.textContent='Invalid email.'; eHelp.className='help bad'; return; }
  const ok = await emailAvailable(v);
  if (ok === true){ eHelp.textContent='Email is available.'; eHelp.className='help ok'; }
  else if (ok === false){ eHelp.textContent='Email already in use.'; eHelp.className='help bad'; }
  else { eHelp.textContent=''; eHelp.className='help muted'; }
}, 300);
$('#email')?.addEventListener('input', checkEmail);

/* -------- Final client guard on submit -------- */
$('#regForm')?.addEventListener('submit', (e)=>{
  const nm=$('#name')?.value.trim(), un=$('#username')?.value.trim(), em=$('#email')?.value.trim();
  const pw=$('#password')?.value, pw2=$('#password2')?.value, bd=$('#birthdate')?.value;
  if (!nm||!un||!em||!pw||!pw2||!bd) { e.preventDefault(); alert('Please complete all fields.'); return; }
  if (!/^[a-z0-9_]{3,20}$/i.test(un)) { e.preventDefault(); alert('Invalid username.'); return; }
  if (pw.length<6 || pw!==pw2)       { e.preventDefault(); alert('Please fix your password.'); return; }
  // Age check
  try{
    const d=new Date(bd), today=new Date(); let age=today.getFullYear()-d.getFullYear();
    const m=today.getMonth()-d.getMonth(); if(m<0 || (m===0 && today.getDate()<d.getDate())) age--;
    if (age<18){ e.preventDefault(); alert('You must be at least 18 to register.'); return; }
  }catch{ e.preventDefault(); alert('Invalid birthdate.'); }
});
</script>

<?php render_footer();
