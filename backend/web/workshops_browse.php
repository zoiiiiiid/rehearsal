<?php
// backend/web/workshops_browse.php
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth();

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;

$resp  = api_get('workshops_list.php', ['status'=>'all','page'=>$page,'limit'=>$limit]);
$items = (array)($resp['items'] ?? []);
$total = (int)($resp['total'] ?? count($items));

render_header('Browse workshops');

$apiBase = rtrim(API_BASE_URL,'/').'/';
$token   = web_token();
?>
<style>
  .w-wrap   { max-width: 1100px; margin: 0 auto; }
  .w-head   { display:flex; align-items:center; gap:10px; margin:8px 0 16px; }
  .w-title  { font-size:20px; font-weight:800; }
  .w-spacer { flex:1; }
  .w-btn    { display:inline-flex; align-items:center; gap:8px; background:#111; color:#fff; border:0;
              border-radius:999px; padding:10px 14px; font-weight:700; text-decoration:none; cursor:pointer }
  .w-btn.out{ background:#fff; color:#111; border:1px solid var(--bd) }

  /* Responsive grid */
  .w-grid   { display:grid; gap:14px; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }

  /* Card (avoid site-wide .card to prevent inherited min-heights) */
  .w-card   { border:1px solid var(--bd); border-radius:14px; padding:14px; background:#fff;
              text-decoration:none; color:inherit; transition: box-shadow .15s ease, transform .15s ease; }
  .w-card:hover { box-shadow:0 10px 18px rgba(0,0,0,.05); transform: translateY(-1px); }

  .w-when   { display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#111; }
  .w-host   { font-size:12px; color:#6b7280; margin-top:6px; }
  .w-name   { margin-top:10px; font-weight:800; font-size:16px; line-height:1.25;
              overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
  .w-actions{ margin-top:12px; display:flex; gap:8px; }
  .w-chip   { border:1px solid var(--bd); border-radius:999px; padding:6px 10px; font-size:12px; color:#111; }

  .center   { text-align:center }
  .muted    { color:#6b7280 }
</style>

<div class="w-wrap">
  <div class="w-head">
    <div class="w-title">Browse workshops</div>
    <div class="w-spacer"></div>
    <a class="w-btn out" href="<?=WEB_BASE?>/workshops.php"><?=icon('arrow-left')?> Back</a>
  </div>

  <?php if (empty($items)): ?>
    <p class="muted" style="margin: 8px 0 18px;">No workshops yet.</p>
  <?php else: ?>
    <div id="w-list" class="w-grid">
      <?php foreach ($items as $w):
        $w=(array)$w;
        $wid   = (string)($w['id'] ?? '');
        $title = (string)($w['title'] ?? 'Workshop');
        $iso   = (string)($w['starts_at'] ?? '');
        $host  = (array)($w['host'] ?? []);
        $hostName = (string)($host['display_name'] ?? 'Host');
      ?>
        <a class="w-card" href="<?=WEB_BASE?>/workshop_detail.php?id=<?=urlencode($wid)?>">
          <?php if ($iso): ?>
            <div class="w-when"><?=icon('clock')?> <time class="dt" datetime="<?=h($iso)?>" data-iso="<?=h($iso)?>"><?=h($iso)?></time></div>
          <?php endif; ?>
          <div class="w-name"><?=h($title)?></div>
          <div class="w-host">with <?=h($hostName)?></div>
          <div class="w-actions">
            <span class="w-chip"><?=icon('video')?> Joinable</span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div id="w-msg" class="center" style="margin-top:12px"></div>

  <div class="center" style="margin-top:12px">
    <button id="w-more" class="w-btn"<?php if (count($items) >= $total) echo ' style="display:none"'; ?>>
      <?=icon('chevrons-down')?> Load more
    </button>
  </div>
</div>

<script>
  // Local time format: "M/D/YYYY • h:mm AM/PM"
  function fmtLocal(iso){
    const d = new Date(iso);
    if (isNaN(d)) return iso;
    const two = n => n<10 ? '0'+n : ''+n;
    const h12 = (d.getHours()%12) || 12;
    const am  = d.getHours()>=12 ? 'PM' : 'AM';
    return `${d.getMonth()+1}/${d.getDate()}/${d.getFullYear()} • ${h12}:${two(d.getMinutes())} ${am}`;
  }
  document.querySelectorAll('time.dt').forEach(el=>{
    const iso = el.dataset.iso || el.getAttribute('datetime');
    el.textContent = fmtLocal(iso);
  });

  const API_BASE = <?= json_encode($apiBase) ?>;
  const TOKEN    = <?= json_encode($token) ?>;
  let page       = <?= (int)$page ?>;
  const limit    = <?= (int)$limit ?>;
  let loaded     = <?= count($items) ?>;
  const total    = <?= (int)$total ?>;

  const list = document.getElementById('w-list');
  const btn  = document.getElementById('w-more');
  const msg  = document.getElementById('w-msg');

  const esc = s => String(s ?? '').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  const href = id => '<?=WEB_BASE?>/workshop_detail.php?id=' + encodeURIComponent(String(id ?? ''));

  function card(w){
    const id    = String(w.id ?? '');
    const title = String(w.title ?? 'Workshop');
    const iso   = String(w.starts_at ?? '');
    const host  = w.host ? (w.host.display_name ?? 'Host') : 'Host';
    const when  = iso ? fmtLocal(iso) : '';
    return `
      <a class="w-card" href="${href(id)}">
        ${ when ? `<div class="w-when"><?=icon('clock')?> <time>${esc(when)}</time></div>` : '' }
        <div class="w-name">${esc(title)}</div>
        <div class="w-host">with ${esc(host)}</div>
        <div class="w-actions"><span class="w-chip"><?=icon('video')?> Joinable</span></div>
      </a>`;
  }

  async function loadMore(){
    btn.disabled = true; msg.textContent = '';
    try {
      const url = `${API_BASE}workshops_list.php?status=all&page=${page+1}&limit=${limit}&token=${encodeURIComponent(TOKEN)}`;
      const r   = await fetch(url, {credentials:'same-origin'});
      const j   = await r.json().catch(()=>({}));
      if (j && Array.isArray(j.items) && j.items.length){
        page++; loaded += j.items.length;
        const frag = document.createDocumentFragment();
        j.items.forEach(w => { const div=document.createElement('div'); div.innerHTML=card(w); frag.appendChild(div.firstElementChild); });
        list.appendChild(frag);
        if (loaded >= (j.total ?? loaded)) btn.style.display = 'none';
      } else {
        btn.style.display = 'none';
        if (!loaded) msg.textContent = 'No workshops found.';
      }
    } catch(e){
      msg.textContent = 'Failed to load more.';
    } finally {
      btn.disabled = false;
    }
  }
  if (btn) btn.addEventListener('click', loadMore);
</script>

<?php render_footer(); ?>
