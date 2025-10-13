<?php
declare(strict_types=1);
require __DIR__.'/web_common.php'; require_web_auth();

render_header('Spotlight');
?>
<div class="chips" id="rangeChips">
  <a href="#" data-r="today"  class="chip on">Today</a>
  <a href="#" data-r="week"   class="chip">Week</a>
  <a href="#" data-r="month"  class="chip">Month</a>
  <a href="#" data-r="all"    class="chip">All-time</a>
</div>

<div class="grid" id="topGrid" style="margin-bottom:10px"></div>

<div class="card" style="padding:0;overflow:hidden">
  <div style="display:flex;align-items:center;gap:10px;padding:10px;border-bottom:1px solid #e5e7eb">
    <b>Leaderboard</b>
    <span class="muted" id="rangeLabel">Today</span>
  </div>
  <div style="overflow:auto">
    <table style="width:100%;border-collapse:collapse">
      <thead style="background:#fafafa">
        <tr>
          <th style="text-align:left;padding:10px;border-bottom:1px solid #e5e7eb;width:60px">#</th>
          <th style="text-align:left;padding:10px;border-bottom:1px solid #e5e7eb">Artist / Post</th>
          <th style="text-align:right;padding:10px;border-bottom:1px solid #e5e7eb;width:120px">🔥 Votes</th>
          <th style="text-align:right;padding:10px;border-bottom:1px solid #e5e7eb;width:160px">Actions</th>
        </tr>
      </thead>
      <tbody id="rows"></tbody>
    </table>
  </div>
</div>

<script>
(async function(){
  const chips = document.querySelectorAll('#rangeChips .chip');
  const rows  = document.getElementById('rows');
  const top   = document.getElementById('topGrid');
  const label = document.getElementById('rangeLabel');

  let range = 'today';

  async function load(){
    // Change endpoint path if needed:
    const r = await WEB.j('spotlight_leaderboard.php?range='+encodeURIComponent(range));
    if (!r.ok || !Array.isArray(r.data?.items)) {
      rows.innerHTML = `<tr><td colspan="4" style="padding:14px">No data.</td></tr>`;
      top.innerHTML  = '';
      return;
    }
    const items = r.data.items;

    // top grid (top 3 mini-cards)
    const top3 = items.slice(0,3);
    top.innerHTML = top3.map((it,i)=>{
      const u  = it.user||{};
      const p  = it.post||{};
      const nm = u.display_name||u.name||'User';
      const hd = u.handle||(u.username?('@'+u.username):'');
      const mu = p.media_url||'';
      return `
      <a class="card" href="${WEB.base}/post.php?id=${encodeURIComponent(p.id||'')}"
         style="text-decoration:none;overflow:hidden">
        <div style="height:160px;background:#f1f1f1;${mu?'background-image:url('+mu+');background-size:cover;background-position:center':''}"></div>
        <div style="padding:10px">
          <div style="font-weight:700">#${i+1} • ${nm}</div>
          <div class="muted">${hd}</div>
          <div style="margin-top:6px">🔥 ${it.votes ?? it.spotlights ?? it.flames ?? 0}</div>
        </div>
      </a>`;
    }).join('');

    // table rows
    rows.innerHTML = items.map((it,i)=>{
      const u  = it.user||{};
      const p  = it.post||{};
      const nm = u.display_name||u.name||'User';
      const hd = u.handle||(u.username?('@'+u.username):'');
      const votes = it.votes ?? it.spotlights ?? it.flames ?? 0;
      return `
        <tr>
          <td style="padding:10px;border-bottom:1px solid #eee">#${i+1}</td>
          <td style="padding:10px;border-bottom:1px solid #eee">
            <div style="font-weight:700">${nm} <span class="handle">${hd}</span></div>
            <div class="muted" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:560px">${(p.caption||'')}</div>
          </td>
          <td style="padding:10px;border-bottom:1px solid #eee;text-align:right">🔥 ${votes}</td>
          <td style="padding:10px;border-bottom:1px solid #eee;text-align:right">
            <a class="btn sm out" href="${WEB.base}/post.php?id=${encodeURIComponent(p.id||'')}">Open</a>
            <button class="btn sm out js-spot" data-id="${p.id||''}">Vote</button>
          </td>
        </tr>`;
    }).join('');

    // quick vote from leaderboard rows
    rows.querySelectorAll('.js-spot').forEach(btn=>{
      btn.addEventListener('click', async ()=>{
        const id = btn.dataset.id;
        if (!id) return;
        const r = await WEB.j('spotlight_vote.php','POST',{ post_id:id, vote:true });
        if (r.ok) { WEB.toast('Voted'); load(); } else { WEB.toast('Failed'); }
      });
    });
  }

  chips.forEach(c=>{
    c.addEventListener('click', (e)=>{
      e.preventDefault();
      chips.forEach(x=>x.classList.remove('on'));
      c.classList.add('on');
      range = c.dataset.r;
      label.textContent = c.textContent;
      load();
    });
  });

  load();
})();
</script>
<?php render_footer(); ?>
