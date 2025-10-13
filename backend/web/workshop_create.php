<?php
// backend/web/workshop_create.php
require __DIR__.'/web_common.php';
require_web_auth(); // ensures session + token
html_header('New Workshop');

$api = API_BASE_URL . 'workshop_create.php'; // e.g. http://localhost/backend/api/workshop_create.php
$sessionToken = web_token();
?>
<style>
  .form {max-width: 900px}
  .row {display:grid; grid-template-columns: 1fr 1fr; gap:12px}
  .row-1 {display:grid; grid-template-columns: 1fr; gap:12px}
  label{display:block; font-size:12px; color:#444; margin:8px 0 4px}
  input[type=text], input[type=number], textarea, select, input[type=datetime-local]{
    width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; background:#fff
  }
  textarea{min-height:120px; resize:vertical}
  .help{font-size:12px; color:#6b7280}
  .actions{margin-top:14px; display:flex; gap:8px; align-items:center}
  .btn{display:inline-flex; align-items:center; gap:8px; background:#111; color:#fff; border:0; border-radius:999px; padding:10px 14px; font-weight:700; cursor:pointer}
  .btn.out{background:#fff; color:#111; border:1px solid var(--bd)}
  .error{background:#fff0f0; border:1px solid #ffd1d1; color:#b00000; padding:10px; border-radius:10px; margin:10px 0}
  .ok{background:#f0fff4; border:1px solid #b7f7c2; color:#065f46; padding:10px; border-radius:10px; margin:10px 0}
</style>

<div class="card form" style="padding:14px">
  <form id="wsForm" onsubmit="return false">
    <input type="hidden" name="token" value="<?=h($sessionToken)?>">

    <div class="row-1">
      <div>
        <label>Title *</label>
        <input type="text" name="title" required>
      </div>
      <div>
        <label>Description</label>
        <textarea name="description" placeholder="What will attendees learn? Any prep needed?"></textarea>
      </div>
    </div>

    <div class="row">
      <div>
        <label>Starts at (local time) *</label>
        <input type="datetime-local" name="starts_at" required>
        <div class="help">If your backend expects UTC you can send UTC via API; this picker uses the browser’s local time.</div>
      </div>
      <div>
        <label>Ends at (optional)</label>
        <input type="datetime-local" name="ends_at">
      </div>
    </div>

    <div class="row">
      <div>
        <label>Duration (minutes)</label>
        <input type="number" name="duration_min" min="15" step="5" value="60">
      </div>
      <div>
        <label>Location</label>
        <input type="text" name="location" value="Online">
      </div>
    </div>

    <div class="row">
      <div>
        <label>Capacity (0 = unlimited)</label>
        <input type="number" name="capacity" min="0" value="0">
      </div>
      <div>
        <label>Meeting link (Zoom / Google Meet) *</label>
        <input type="text" name="zoom_link" required placeholder="https://zoom.us/j/… or https://meet.google.com/…">
        <div class="help">All workshops are accessible. If you want payment, lock your meeting and let users message you for access.</div>
      </div>
    </div>

    <div class="row-1">
      <div>
        <label>Skillset</label>
        <select name="skill">
          <option value="dj">DJ</option>
          <option value="singer">Singer</option>
          <option value="guitarist">Guitarist</option>
          <option value="drummer">Drummer</option>
          <option value="bassist">Bassist</option>
          <option value="keyboardist">Keyboardist</option>
          <option value="dancer">Dancer</option>
          <option value="other" selected>Other</option>
        </select>
      </div>
    </div>

    <div id="msg"></div>

    <div class="actions">
      <button class="btn" id="submitBtn"><?=icon('calendar-plus')?> Publish Workshop</button>
      <button type="reset" class="btn out">Reset</button>
    </div>
  </form>
</div>

<script>
const API_URL = <?= json_encode($api) ?> + '?token=' + encodeURIComponent(<?= json_encode($sessionToken) ?>);

function showMsg(kind, text) {
  const el = document.getElementById('msg');
  if (!text) { el.innerHTML = ''; return; }
  el.innerHTML = '<div class="'+(kind==='ok'?'ok':'error')+'">'+text+'</div>';
}

document.getElementById('submitBtn').addEventListener('click', async (ev) => {
  ev.preventDefault();
  showMsg('', '');

  const f = document.getElementById('wsForm');
  const fd = new FormData(f);

  // Force open-access semantics: never send any paid fields.
  fd.delete('price_cents');
  fd.delete('access_token');

  // Ensure token present (API fallback)
  if (!fd.get('token')) fd.set('token', <?= json_encode($sessionToken) ?>);

  try {
    const r = await fetch(API_URL, { method:'POST', body: fd, credentials:'same-origin' });
    const j = await r.json().catch(()=>({error:'BAD_JSON'}));

    if (r.ok && j.ok === true) {
      const id = (j.workshop && j.workshop.id) ? j.workshop.id : '';
      showMsg('ok','Workshop created! '+ (id ? ('ID: '+id) : ''));
      // Optionally redirect:
      // location.href = 'workshops.php';
    } else {
      const code = j.error || ('HTTP_'+r.status);
      const detail = j.detail ? (' – '+String(j.detail)) : '';
      showMsg('err','Create failed: '+code+detail);
    }
  } catch (e) {
    showMsg('err','Network error: '+e);
  }
});
</script>

<?php html_footer(); ?>
