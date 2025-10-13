<?php
/* =================================================================
 * File: backend/web/profile_edit.php
 * Uses: /api/profile_update.php (+ tolerant avatar upload endpoints)
 * ================================================================= */
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth();

$err = $ok = null;

/* Load my profile info */
$me  = api_get('/profile_me.php');
$prof = (array)($me['profile'] ?? []);
$currSkills = array_map('strtolower', array_filter((array)($prof['skills'] ?? [])));

/* ---------- handle POST (text fields) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($_FILES)) {
  csrf_check();
  $name   = trim((string)($_POST['name'] ?? ''));
  $user   = trim((string)($_POST['username'] ?? ''));
  $user   = ltrim($user, '@'); // allow "@handle" input
  $bio    = trim((string)($_POST['bio'] ?? ''));
  $skills = array_filter(array_map('trim', explode(',', (string)($_POST['skills'] ?? ''))));
  $skills = array_values(array_unique(array_map('strtolower', $skills)));

  $res = api_post('/profile_update.php', [
    'name'     => $name,
    'username' => $user,
    'bio'      => $bio,
    'skills'   => $skills ?: null,
  ]);

  if (!empty($res['ok'])) {
    $ok = 'Saved.';
    // refresh local copy
    $me  = api_get('/profile_me.php');
    $prof = (array)($me['profile'] ?? []);
    $currSkills = array_map('strtolower', array_filter((array)($prof['skills'] ?? [])));
  } else {
    $err = (string)($res['error'] ?? 'Save failed.');
  }
}

/* ---------- handle avatar upload (multipart) ---------- */
/* We post back to the same page with file input named "avatar". */
if (!empty($_FILES['avatar']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
  csrf_check();

  $file = $_FILES['avatar'];
  $uploadTries = [
    'profile_avatar_upload.php',
    'profile_photo_upload.php',
    'avatar_upload.php',
    'profile_avatar.php',
  ];
  $uOk = false; $uErr = null;

  foreach ($uploadTries as $ep) {
    $resp = api_post_multipart('/'.$ep, [], [
      'avatar' => [
        'path' => $file['tmp_name'],
        'name' => $file['name'],
        'type' => $file['type'] ?: 'image/jpeg'
      ]
    ]);
    if (!empty($resp['ok'])) { $uOk = true; break; }
    $uErr = $resp['error'] ?? $resp['detail'] ?? 'UPLOAD_FAILED';
  }

  if ($uOk) {
    $ok = 'Photo updated.';
    $me  = api_get('/profile_me.php');
    $prof = (array)($me['profile'] ?? []);
    $currSkills = array_map('strtolower', array_filter((array)($prof['skills'] ?? [])));
  } else {
    $err = 'Photo upload failed'.($uErr ? " ($uErr)" : '').'.';
  }
}

render_header('Edit profile');

$skillsAll = skills_labels(); // ['dj'=>'DJ', ...]
$name  = (string)($prof['name'] ?? '');
$user  = (string)($prof['username'] ?? '');
$bio   = (string)($prof['bio'] ?? '');
$ava   = user_avatar_url($prof);
?>
<style>
  .edit-wrap{max-width:720px;margin:0 auto}
  .row{margin:10px 0}
  .label{font-size:12px;color:#555;margin-bottom:4px}
  .input, textarea.input{width:100%;padding:11px 12px;border:1px solid #d1d5db;border-radius:12px;font-size:14px;background:#fff}
  .chip{display:inline-flex;align-items:center;gap:8px;padding:7px 12px;border:1px solid var(--bd);border-radius:999px;margin:4px 6px 0 0;background:#fff;cursor:pointer}
  .chip.on{background:#111;color:#fff;border-color:#111}
  .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
  .ava-lg{width:64px;height:64px;border-radius:999px;object-fit:cover;background:#fff;color:#111;display:inline-flex;border:1px solid #ddd}
  .btn.small{padding:8px 12px;border-radius:999px;border:1px solid var(--bd);background:#fff}
  .ok{color:#128a29;font-weight:600}
  .muted{color:#6b7280}
  .note{font-size:12px;color:#666;margin-top:6px}
  .right-actions{display:flex;gap:8px}
  .check{display:inline-block;width:18px;height:18px;border-radius:999px;background:#10b981;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px}
</style>

<div class="edit-wrap">
  <div class="topbar">
    <h3 style="margin:0">Edit profile</h3>
    <div class="right-actions">
      <button form="form-main" class="btn">Save</button>
      <a class="btn out" href="<?=WEB_BASE?>/profile.php">Cancel</a>
    </div>
  </div>

  <?php if ($err): ?><div class="card" style="padding:12px;border-color:#ffd1d1;background:#fff0f0;color:#b00000;margin-bottom:10px"><?=h($err)?></div><?php endif; ?>
  <?php if ($ok):  ?><div class="card" style="padding:12px;border-color:#c8f4d2;background:#effcf2;color:#128a29;margin-bottom:10px"><?=h($ok)?></div><?php endif; ?>

  <!-- avatar -->
  <div class="card" style="padding:14px;margin-bottom:12px">
    <div style="display:flex;align-items:center;gap:12px">
      <img src="<?=h($ava)?>" alt="" class="ava-lg">
      <form id="form-avatar" method="post" enctype="multipart/form-data">
        <?php csrf_field(); ?>
        <input type="file" id="avatar" name="avatar" accept="image/*" style="display:none">
        <button type="button" class="btn out small" onclick="document.getElementById('avatar').click()">Change photo</button>
      </form>
    </div>
  </div>

  <!-- main form -->
  <form id="form-main" method="post" class="card" style="padding:14px">
    <?php csrf_field(); ?>
    <div class="row">
      <div class="label">Name</div>
      <input class="input" name="name" value="<?=h($name)?>" required>
    </div>

    <div class="row">
      <div class="label">Username</div>
      <div style="display:flex;align-items:center;gap:8px">
        <input class="input" id="username" name="username" value="<?=h($user)?>" minlength="3" maxlength="20" style="flex:1">
        <span id="ucheck" class="muted" style="min-width:20px;text-align:center"></span>
      </div>
      <div class="note">You can type with or without “@”.</div>
    </div>

    <div class="row">
      <div class="label">Skillset</div>
      <div id="skills" style="display:flex;flex-wrap:wrap">
        <?php foreach ($skillsAll as $key=>$label):
          if ($key==='all') continue;
          $on = in_array(strtolower($key), $currSkills, true) ? 'chip on':'chip';
        ?>
          <span class="<?= $on ?>" data-key="<?= h(strtolower($key)) ?>"><?= h($label) ?></span>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="skills" id="skills-hidden" value="<?= h(implode(',', $currSkills)) ?>">
    </div>

    <div class="row">
      <div class="label">Experience / Achievements</div>
      <textarea class="input" name="bio" rows="6" placeholder="Tell people about your background…"><?=h($bio)?></textarea>
    </div>
  </form>
</div>

<script>
const API_BASE = <?= json_encode(API_BASE_URL) ?>;
const TOKEN    = <?= json_encode(web_token()) ?>;

/* ----- Avatar auto-submit ----- */
document.getElementById('avatar')?.addEventListener('change', ()=>{
  if (!document.getElementById('avatar').files.length) return;
  document.getElementById('form-avatar').submit();
});

/* ----- Skill chips -> hidden CSV ----- */
const wrap = document.getElementById('skills');
const hidden = document.getElementById('skills-hidden');
wrap?.addEventListener('click', (e)=>{
  const chip = e.target.closest('.chip'); if (!chip) return;
  chip.classList.toggle('on');
  const selected = Array.from(wrap.querySelectorAll('.chip.on')).map(x=>x.dataset.key);
  hidden.value = selected.join(',');
});

/* ----- Username availability (debounced) ----- */
const u = document.getElementById('username');
const ucheck = document.getElementById('ucheck');
let tmr = null;

const CHECK_EPS = [
  API_BASE + 'username_check.php',
  API_BASE + 'profile_username_check.php'
];

async function checkUsername(val){
  const username = String(val || '').trim().replace(/^@/, '');
  if (username.length < 3) { ucheck.textContent = ''; return; }

  ucheck.textContent = '…';
  const body = new URLSearchParams({ username });
  for (const ep of CHECK_EPS) {
    try{
      const r = await fetch(ep, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body
      });
      const j = await r.json().catch(()=> ({}));
      // Accept shapes: {available:true} OR {ok:true, available:true} OR {taken:false}
      const available = (j.available === true) || (j.ok === true && j.available === true) || (j.taken === false);
      const taken     = (j.available === false) || (j.taken === true);
      if (available || taken) {
        if (available) {
          ucheck.innerHTML = '<span class="check">✓</span>';
          ucheck.classList.remove('muted');
        } else {
          ucheck.textContent = 'taken';
          ucheck.classList.add('muted');
        }
        return;
      }
    }catch(_){}
  }
  ucheck.textContent = ''; // unknown endpoint -> no indicator
}

u?.addEventListener('input', ()=>{
  clearTimeout(tmr);
  tmr = setTimeout(()=>checkUsername(u.value), 350);
});
</script>

<?php
render_footer();
