<?php
// backend/web/workshop_detail.php
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth();

$id = (string)($_GET['id'] ?? '');
if ($id === '') {
  render_header('Workshop'); ?>
  <div class="card error">Missing workshop id.</div>
  <?php render_footer(); exit;
}

// Fetch workshop detail (now returns created_at + join URLs)
$res = api_get('workshop_detail.php', ['id'=>$id]);
$w   = (array)($res['workshop'] ?? []);
$viewer = (array)($w['viewer'] ?? []);
$host   = (array)($w['host']   ?? []);
$canManage = ($viewer['can_manage'] ?? false) ? true : false;

$title = (string)($w['title'] ?? 'Workshop');
$desc  = (string)($w['description'] ?? '');
$created = (string)($w['created_at'] ?? '');          // may be empty if old schema
$starts  = (string)($w['starts_at'] ?? '');
$whenIso = $created !== '' ? $created : $starts;      // prefer created, fallback to starts

$joinUrl = (string)($w['zoom_join_url'] ?? $w['zoom_link'] ?? '');

render_header('Workshop detail');
$apiBase = rtrim(API_BASE_URL,'/').'/';
$token   = web_token();
?>
<style>
  .ws-wrap { max-width: 960px }
  .ws-head { display:flex; align-items:center; gap:12px; margin-bottom:6px }
  .ws-title{ font-size:22px; font-weight:800 }
  .ws-host { display:flex; align-items:center; gap:10px; margin-top:10px }
  .ava     { width:44px; height:44px; border-radius:50%; object-fit:cover; background:#eee }
  .chip    { display:inline-flex; padding:7px 10px; border:1px solid var(--bd); border-radius:999px;
             align-items:center; gap:6px; font-size:13px; }
  .muted   { color:#6b7280 }
  .btn     { display:inline-flex; align-items:center; gap:8px; background:#111; color:#fff; border:0;
             border-radius:999px; padding:10px 14px; font-weight:700; cursor:pointer; text-decoration:none }
  .btn.out { background:#fff; color:#111; border:1px solid var(--bd) }
  .btn.dng { background:#b91c1c }
  .row-act { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px }
  .card.ok { background:#f0fff4; border:1px solid #b7f7c2; color:#065f46; padding:10px; border-radius:10px; margin:10px 0 }
  .card.err{ background:#fff0f0; border:1px solid #ffd1d1; color:#b00000; padding:10px; border-radius:10px; margin:10px 0 }
</style>

<div class="ws-wrap">
  <div class="ws-head">
    <div style="font-weight:600">Workshop detail</div>
  </div>

  <div class="card" style="padding:14px">
    <div class="ws-title"><?=h($title)?></div>

    <!-- time (created preferred) -->
    <?php if ($whenIso): ?>
      <div style="margin-top:6px">
        <span class="chip"><?=icon('clock')?>
          <time id="wsWhen" datetime="<?=h($whenIso)?>" data-iso="<?=h($whenIso)?>"><?=h($whenIso)?></time>
        </span>
      </div>
    <?php endif; ?>

    <!-- host -->
    <div class="ws-host">
      <?php avatar_img((string)($host['avatar_url'] ?? ''), ['class'=>'ava','alt'=>'']); ?>
      <div>
        <div style="font-weight:700"><?=h((string)($host['display_name'] ?? ''))?></div>
        <?php if (!empty($host['username'])): ?>
          <div class="muted">@<?=h($host['username'])?></div>
        <?php endif; ?>
      </div>
      <?php if (!empty($host['id'])): ?>
        <a class="btn out" href="<?=WEB_BASE?>/public_profile.php?id=<?=urlencode((string)$host['id'])?>">View</a>
      <?php endif; ?>
    </div>

    <?php if ($desc !== ''): ?>
      <div style="margin-top:12px"><?=nl2br(h($desc))?></div>
    <?php endif; ?>

    <div id="msg"></div>

    <!-- Actions -->
    <div class="row-act">
      <?php if ($canManage): ?>
        <button class="btn dng" id="btnDelete"><?=icon('trash')?> Delete workshop</button>
        <!-- You can optionally show another admin action here if you like -->
      <?php else: ?>
        <button class="btn" id="btnJoin"><?=icon('rocket')?> Join</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
  // Local time formatter (M/D/YYYY • h:mm AM/PM)
  (function(){
    const el = document.getElementById('wsWhen'); if (!el) return;
    const iso = el.dataset.iso || el.getAttribute('datetime') || '';
    const d = new Date(iso);
    if (!isNaN(d.getTime())) {
      const two = n => n < 10 ? '0'+n : ''+n;
      const h12 = (d.getHours() % 12) || 12;
      const ampm = d.getHours() >= 12 ? 'PM' : 'AM';
      el.textContent = `${d.getMonth()+1}/${d.getDate()}/${d.getFullYear()} • ${h12}:${two(d.getMinutes())} ${ampm}`;
    }
  })();

  const API_BASE = <?= json_encode($apiBase) ?>;
  const TOKEN    = <?= json_encode($token) ?>;
  const WID      = <?= json_encode($id) ?>;

  function flash(kind, text) {
    const box = document.getElementById('msg');
    box.innerHTML = `<div class="card ${kind==='ok'?'ok':'err'}">${text}</div>`;
  }

  // Join (opens meeting link). Uses workshop_access.php, which now allows all attendees.
  const joinBtn = document.getElementById('btnJoin');
  if (joinBtn) {
    joinBtn.addEventListener('click', async () => {
      try {
        const fd = new FormData();
        fd.set('workshop_id', WID);
        fd.set('token', TOKEN); // fallback, if API checks
        const r = await fetch(`${API_BASE}workshop_access.php?token=${encodeURIComponent(TOKEN)}`, {
          method:'POST', body: fd, credentials: 'same-origin'
        });
        const j = await r.json().catch(()=>({}));
        if ((j.ok === true) && (j.join_url || '').length) {
          window.open(j.join_url, '_blank');
        } else {
          // fallback to URL returned in detail payload (if present)
          const fallback = <?= json_encode($joinUrl) ?>;
          if (fallback) window.open(fallback, '_blank');
          else flash('err', 'No join link available.');
        }
      } catch(e) { flash('err','Join failed: '+e); }
    });
  }

  // Delete (host only)
  const delBtn = document.getElementById('btnDelete');
  if (delBtn) {
    delBtn.addEventListener('click', async () => {
      if (!confirm('Delete this workshop? This cannot be undone.')) return;
      try {
        const fd = new FormData();
        fd.set('id', WID);
        fd.set('token', TOKEN);
        const r = await fetch(`${API_BASE}workshop_delete.php?token=${encodeURIComponent(TOKEN)}`, {
          method:'POST', body: fd, credentials:'same-origin'
        });
        const j = await r.json().catch(()=>({}));
        if (j.ok === true) {
          flash('ok','Workshop deleted.');
          setTimeout(()=>{ location.href = '<?=WEB_BASE?>/workshops.php'; }, 750);
        } else {
          flash('err','Delete failed: '+(j.error||'ERROR'));
        }
      } catch(e) { flash('err','Delete failed: '+e); }
    });
  }
</script>

<?php render_footer(); ?>
