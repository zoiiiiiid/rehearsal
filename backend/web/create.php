<?php
/* =========================================================================
 * backend/web/create.php — Create a post (media + skill + caption)
 * ========================================================================= */
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth();

render_header('Create');
$skills = skill_labels(); // ['all'=>'All', 'dj'=>'DJ', ...]
unset($skills['all']);    // not a valid post skill
?>
<style>
  .create-wrap{max-width:760px;margin:0 auto}
  .card.pad{padding:14px}
  .h-row{display:flex;align-items:center;gap:8px}
  .muted{color:var(--mut)}
  .chips{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 6px}
  .chip{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border:1px solid var(--bd);border-radius:999px;cursor:pointer;user-select:none}
  .chip.on{background:#111;color:#fff;border-color:#111}
  .hint{font-size:12px;color:var(--mut)}
  .dz{position:relative;border:1.5px dashed var(--bd);border-radius:12px;height:220px;display:grid;place-items:center;background:#fafafa}
  .dz.drag{border-color:#111;background:#f5f5f5}
  .dz .preview{width:100%;height:100%;display:flex;align-items:center;justify-content:center;overflow:hidden;border-radius:10px}
  .dz img,.dz video{max-width:100%;max-height:100%;display:block;object-fit:contain;background:#000}
  .row{display:flex;gap:8px;flex-wrap:wrap}
  .field{flex:1;padding:10px;border:1px solid var(--bd);border-radius:10px}
  .btn.wide{width:100%;justify-content:center}
  .stack{display:flex;flex-direction:column;gap:8px}
  .right{margin-left:auto}
  .err{background:#fff0f0;border:1px solid #ffd2d2;color:#a60000;padding:10px;border-radius:10px;margin-bottom:10px}
  .ok{background:#f4fff4;border:1px solid #cfe9cf;color:#0a7;padding:10px;border-radius:10px;margin-bottom:10px}
</style>

<div class="create-wrap">
  <div class="card pad">
    <div class="h-row" style="margin-bottom:6px">
      <h3 style="margin:0;font-size:18px">Create</h3>
      <span class="muted right" id="status"></span>
    </div>

    <div id="alert"></div>

    <!-- Dropzone / Preview -->
    <div id="dz" class="dz" aria-label="Select a photo or video">
      <div class="preview">
        <div class="muted" id="dzText">No file selected</div>
      </div>
      <input id="file" type="file" accept="image/jpeg,image/png,image/webp,video/mp4"
             style="position:absolute;inset:0;opacity:0;cursor:pointer">
    </div>

    <!-- Skills -->
    <div class="stack" style="margin-top:12px">
      <div class="muted">Choose a skill <b>(required)</b></div>
      <div id="skillChips" class="chips">
        <?php foreach ($skills as $key=>$label): ?>
          <button class="chip" type="button" data-skill="<?=h($key)?>"><?=h($label)?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Caption + Choose file button -->
    <div class="row" style="margin-top:8px">
      <button id="btnChoose" class="btn out" type="button">Choose file</button>
      <input id="caption" class="field" placeholder="Write a caption… (optional)" maxlength="2000">
    </div>
    <div class="hint" style="margin-top:4px">Supported: JPG · PNG · WEBP · MP4</div>

    <div style="margin-top:12px">
      <button id="btnPost" class="btn wide" type="button" disabled>Post</button>
    </div>
  </div>
</div>

<script>
(() => {
  const API_BASE = <?= json_encode(API_BASE_URL) ?>;
  const TOKEN    = <?= json_encode(web_token()) ?>;
  const EP_CREATE= API_BASE + 'post_create.php';

  const dz       = document.getElementById('dz');
  const dzText   = document.getElementById('dzText');
  const fileEl   = document.getElementById('file');
  const captionEl= document.getElementById('caption');
  const chipsEl  = document.getElementById('skillChips');
  const btnPost  = document.getElementById('btnPost');
  const btnChoose= document.getElementById('btnChoose');
  const statusEl = document.getElementById('status');
  const alertBox = document.getElementById('alert');

  let state = { file:null, url:'', type:'', skill:'' };

  function setAlert(kind, msg){
    alertBox.innerHTML = msg ? `<div class="${kind}">${escapeHtml(msg)}</div>` : '';
  }
  function escapeHtml(s){ return String(s||'').replace(/[&<>"]/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[m])); }

  function inferType(name=''){
    const p = name.toLowerCase();
    if (/\.(mp4)$/.test(p)) return 'video';
    if (/\.(jpg|jpeg|png|webp)$/.test(p)) return 'image';
    return '';
  }

  function refreshUI(){
    const ready = !!(state.file && state.skill);
    btnPost.disabled = !ready;
    statusEl.textContent = ready ? '' : 'Select media and skill to post';
  }

  function setPreview(file){
    // revoke old blob
    if (state.url) { try{ URL.revokeObjectURL(state.url); }catch{} }
    state.file = file || null;
    state.url  = file ? URL.createObjectURL(file) : '';
    state.type = file ? (/^video\//.test(file.type) ? 'video' : 'image') : '';

    const prev = dz.querySelector('.preview');
    prev.innerHTML = '';
    if (!file){
      prev.innerHTML = '<div class="muted" id="dzText">No file selected</div>';
      refreshUI();
      return;
    }
    if (state.type === 'video'){
      const v = document.createElement('video');
      v.src = state.url; v.controls = true; v.playsInline = true;
      prev.appendChild(v);
    } else {
      const img = document.createElement('img');
      img.src = state.url; img.alt = '';
      prev.appendChild(img);
    }
    refreshUI();
  }

  // Drag and drop styling
  ['dragenter','dragover'].forEach(ev =>
    dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('drag'); })
  );
  ['dragleave','drop'].forEach(ev =>
    dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('drag'); })
  );
  dz.addEventListener('drop', (e)=>{
    const f = e.dataTransfer.files && e.dataTransfer.files[0];
    if (f) setPreview(f);
  });

  // File chooser
  fileEl.addEventListener('change', ()=>{
    const f = fileEl.files && fileEl.files[0];
    if (f) setPreview(f);
  });
  btnChoose.addEventListener('click', ()=> fileEl.click());

  // Skill chips (single select)
  chipsEl.addEventListener('click', (e)=>{
    const btn = e.target.closest('.chip');
    if (!btn) return;
    chipsEl.querySelectorAll('.chip').forEach(x=>x.classList.remove('on'));
    btn.classList.add('on');
    state.skill = btn.dataset.skill || '';
    refreshUI();
  });

  async function createPost(){
    if (!state.file || !state.skill) return;
    setAlert('', '');
    btnPost.disabled = true;
    statusEl.textContent = 'Uploading…';

    const fd = new FormData();
    fd.append('media', state.file);
    fd.append('skill', state.skill);
    const cap = (captionEl.value || '').trim();
    if (cap) fd.append('caption', cap);

    try{
      const url = EP_CREATE + '?token=' + encodeURIComponent(TOKEN); // query param fallback
      const r = await fetch(url, {
        method:'POST',
        headers:{ 'Authorization': 'Bearer '+TOKEN },
        body: fd
      });
      const j = await r.json().catch(()=> ({}));
      if (!r.ok || !j || j.ok !== true) {
        const msg = (j && (j.detail || j.error)) ? String(j.detail || j.error) : 'Upload failed.';
        throw new Error(msg);
      }
      setAlert('ok', 'Posted successfully.');
      statusEl.textContent = '';
      // optional: go to the new post if you have post.php
      try{
        if (j.post && j.post.id) {
          location.href = <?= json_encode(WEB_BASE.'/post.php?id=') ?> + encodeURIComponent(j.post.id);
          return;
        }
      }catch{}
      // else, back to home
      location.href = <?= json_encode(WEB_BASE.'/index.php') ?>;
    }catch(e){
      setAlert('err', e && e.message ? e.message : 'Something went wrong.');
      btnPost.disabled = false;
      statusEl.textContent = '';
    }
  }

  btnPost.addEventListener('click', createPost);
  refreshUI();
})();
</script>

<?php render_footer();
