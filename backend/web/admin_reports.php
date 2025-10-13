<?php
/* =================================================================
 * File: backend/web/admin_reports.php
 * Web-first admin console (Moderation + Verification).
 * ================================================================= */
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth();
require_admin_or_403(); // <- HARD GATE

render_header('Admin');
?>
<div class="card" style="padding:14px;margin-bottom:12px">
  <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
    <div>
      <div style="font-weight:800;margin-bottom:6px">Moderation Dashboard</div>
      <div class="muted">Overview for the selected period.</div>
    </div>

    <!-- Range selector -->
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <button class="btn out rng" data-range="7d">7d</button>
      <button class="btn out rng on" data-range="30d">30d</button>
      <button class="btn out rng" data-range="90d">90d</button>
      <button id="btn-refresh" class="btn out" type="button">Refresh</button>
    </div>
  </div>

  <!-- Stat tiles (Posts & Revenue removed) -->
  <div style="display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:10px;margin-top:12px">
    <div class="card" style="padding:12px">
      <div class="muted">New users</div>
      <div style="display:flex;align-items:baseline;gap:10px">
        <div id="m-new-users" class="bignum">–</div>
        <div id="d-new-users" class="delta muted"></div>
      </div>
    </div>
    <div class="card" style="padding:12px">
      <div class="muted">Active users</div>
      <div style="display:flex;align-items:baseline;gap:10px">
        <div id="m-active" class="bignum">–</div>
        <div id="d-active" class="delta muted"></div>
      </div>
    </div>
    <div class="card" style="padding:12px">
      <div class="muted">Pending verifications</div>
      <div id="m-pending" class="bignum">–</div>
    </div>
    <div class="card" style="padding:12px">
      <div class="muted">Range</div>
      <div id="m-range" class="bignum">–</div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:10px;margin-top:10px">
    <div class="card" style="padding:12px">
      <div class="muted">Comments</div>
      <div style="display:flex;align-items:baseline;gap:10px">
        <div id="m-comments" class="bignum">–</div>
        <div id="d-comments" class="delta muted"></div>
      </div>
    </div>
    <div class="card" style="padding:12px">
      <div class="muted">Likes</div>
      <div style="display:flex;align-items:baseline;gap:10px">
        <div id="m-likes" class="bignum">–</div>
        <div id="d-likes" class="delta muted"></div>
      </div>
    </div>
    <div class="card" style="padding:12px">
      <div class="muted">Workshops</div>
      <div id="m-workshops" class="bignum">–</div>
    </div>
    <div class="card" style="padding:12px">
      <div class="muted">Attendance</div>
      <div style="display:flex;align-items:baseline;gap:10px">
        <div id="m-attendance" class="bignum">–</div>
        <div id="d-attendance" class="delta muted"></div>
      </div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:10px;margin-top:10px">
    <div class="card" style="padding:12px">
      <div class="muted">Posts / Active user</div>
      <div id="m-ppu" class="bignum">–</div>
    </div>
    <div class="card" style="padding:12px">
      <div class="muted">Interactions / Post</div>
      <div id="m-intpp" class="bignum">–</div>
    </div>
    <!-- two empty spots kept for future metrics -->
    <div class="card" style="padding:12px"><div class="muted">—</div><div class="bignum">—</div></div>
    <div class="card" style="padding:12px"><div class="muted">—</div><div class="bignum">—</div></div>
  </div>
</div>

<div class="card" style="padding:0;overflow:hidden;margin-bottom:12px">
  <div style="display:flex;gap:6px;border-bottom:1px solid var(--bd);padding:8px;align-items:center;flex-wrap:wrap">
    <button class="btn out tab-btn on" data-tab="verify">Verification</button>
    <div style="margin-left:auto;display:flex;gap:8px">
      <input id="global-search" type="text" placeholder="Search applicants…" style="padding:8px 10px;border:1px solid #ddd;border-radius:10px;min-width:260px">
      <button id="btn-search-v" class="btn out" type="button">Search</button>
    </div>
  </div>

  <!-- Verification -->
  <section id="tab-verify" class="tab on" style="padding:10px">
    <div class="muted" style="margin-bottom:8px">
      Applicants ordered newest first. Approve to grant the Verified Mentor badge.
    </div>
    <div style="overflow:auto">
      <table class="admin-table" style="width:100%;border-collapse:collapse">
        <thead>
          <tr style="text-align:left;border-bottom:1px solid var(--bd)">
            <th style="padding:8px">When</th>
            <th style="padding:8px">User</th>
            <th style="padding:8px">Username</th>
            <th style="padding:8px">Status</th>
            <th style="padding:8px">Actions</th>
          </tr>
        </thead>
        <tbody id="t-verify"></tbody>
      </table>
    </div>
  </section>
</div>

<style>
  .admin-table td,.admin-table th{padding:8px;border-bottom:1px solid #eee;vertical-align:top}
  .tab-btn.on{background:#111;color:#fff;border-color:#111}
  .rng.on{background:#111;color:#fff;border-color:#111}
  .badge-mini{display:inline-block;padding:2px 6px;border:1px solid #ddd;border-radius:999px;font-size:11px}
  .bignum{font-size:26px;font-weight:800}
  .delta{font-size:12px}
</style>

<script>
const API_BASE = <?= json_encode(API_BASE_URL) ?>;
const TOKEN    = <?= json_encode(web_token()) ?>;

/* ------- Endpoints ------- */
const EP = {
  overview: ['admin_analytics_overview.php'],
  series:   ['admin_analytics_series.php'],
  pending:  ['admin_pending_list.php'],
  verify:   ['mentor_verify.php'],
  reject:   ['mentor_reject.php']
};

function epUrl(list){ return list.map(x => API_BASE + x); }
function withToken(url){
  return url + (url.includes('?') ? '&' : '?') + 'token=' + encodeURIComponent(TOKEN);
}
async function jget(urls, qs=''){
  for(const u of urls){
    try{
      const r = await fetch(withToken(u+(qs||'')), { headers:{ 'Authorization':'Bearer '+TOKEN }});
      if(!r.ok) continue;
      return await r.json();
    }catch(_){}
  }
  return null;
}
async function jpost(urls, body){
  for(const u of urls){
    try{
      const r = await fetch(u, {
        method:'POST',
        headers:{ 'Content-Type':'application/x-www-form-urlencoded', 'Authorization':'Bearer '+TOKEN },
        body: (new URLSearchParams(body)).toString()
      });
      const j = await r.json().catch(()=>({}));
      if(r.ok && (j.ok===true || !j.error)) return j;
    }catch(_){}
  }
  return { ok:false, error:'NOT_AVAILABLE' };
}

/* ------- Dashboard (overview + deltas) ------- */
let RANGE = '30d';

async function loadOverview(){
  // core overview
  const ov = await jget(epUrl(EP.overview), `?range=${encodeURIComponent(RANGE)}`) || {};
  const active = num(ov.active_users);
  const newUsers = num(ov.new_users);
  const comments = num(ov.comments);
  const likes = num(ov.likes);
  const workshops = num(ov.workshops);
  const attendance = num(ov.attendance);

  // pending verifications (count)
  const pend = await jget(epUrl(EP.pending), '?limit=1') || {};
  const pendingCount = num(pend.total || (pend.items ? pend.items.length : 0));

  // Fill tiles
  setTxt('m-new-users', fmtInt(newUsers));
  setTxt('m-active', fmtInt(active));
  setTxt('m-pending', fmtInt(pendingCount));
  setTxt('m-comments', fmtInt(comments));
  setTxt('m-likes', fmtInt(likes));
  setTxt('m-workshops', fmtInt(workshops));
  setTxt('m-attendance', fmtInt(attendance));
  setTxt('m-range', RANGE.toUpperCase());

  // Deltas vs period start (first vs last value from series)
  renderDelta('d-new-users', await deltaPct('new_users'));
  renderDelta('d-active',    await deltaPct('active_users'));
  renderDelta('d-comments',  await deltaPct('comments'));
  renderDelta('d-likes',     await deltaPct('likes'));
  renderDelta('d-attendance',await deltaPct('attendance'));

  // Ratios (use series totals; if missing, show "–")
  const postsTotal = await seriesTotal('posts');
  const interactionsPerPost = (postsTotal > 0) ? ((likes + comments) / postsTotal) : null;
  const ppu = (active > 0 && postsTotal > 0) ? (postsTotal / active) : null;

  setTxt('m-ppu', ppu == null ? '–' : ppu.toFixed(ppu >= 10 ? 1 : 2));
  setTxt('m-intpp', interactionsPerPost == null ? '–' : interactionsPerPost.toFixed(interactionsPerPost >= 10 ? 1 : 2));
}

/* ------- Verification list (data-light: available fields only) ------- */
async function loadVerifications(q=''){
  const qs = `?limit=200${q ? '&q='+encodeURIComponent(q) : ''}`;
  const j = await jget(epUrl(EP.pending), qs);
  const items = (j && j.items) || [];
  const tb = document.getElementById('t-verify'); tb.innerHTML = '';

  for (const it of items){
    const uid   = it.id || it.user_id || '';
    const name  = esc(it.display_name || it.name || '');
    const uname = esc(it.username || '');
    const when  = esc(it.created_at || it.joined || it.time_ago || '—');
    const status = esc(it.status || 'pending');

    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${when}</td>
      <td>${name || '—'}</td>
      <td>@${uname || '—'}</td>
      <td><span class="badge-mini">${status}</span></td>
      <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <a class="btn out" href="<?=WEB_BASE?>/public_profile.php?user_id=${encodeURIComponent(uid)}" target="_blank">Profile</a>
          <button class="btn out btn-v-appr" data-user="${uid}">Approve</button>
          <button class="btn out btn-v-rej"  data-user="${uid}">Reject</button>
        </div>
      </td>
    `;
    tb.appendChild(tr);
  }

  setTxt('m-pending', fmtInt(items.length));
}

/* ------- Series helpers (for deltas & ratios) ------- */
async function fetchSeries(metric){
  const j = await jget(epUrl(EP.series), `?metric=${encodeURIComponent(metric)}&range=${encodeURIComponent(RANGE)}`);
  return (j && Array.isArray(j.items)) ? j.items : [];
}
async function seriesTotal(metric){
  const items = await fetchSeries(metric);
  return items.reduce((s,it)=> s + (num(it.value)||0), 0);
}
async function deltaPct(metric){
  const items = await fetchSeries(metric);
  if (!items.length) return null;
  const first = num(items[0].value);
  const last  = num(items[items.length-1].value);
  if (first === 0) {
    if (last === 0) return 0;
    return 100; // treat as +100% (new activity) to avoid /0
  }
  return ((last - first) / first) * 100;
}
function renderDelta(id, pct){
  const el = document.getElementById(id);
  if (!el) return;
  if (pct == null) { el.textContent = ''; return; }
  const p = Math.round(pct);
  const arrow = p > 0 ? '▲' : (p < 0 ? '▼' : '—');
  el.textContent = `${arrow} ${Math.abs(p)}% vs start`;
}

/* ------- Actions ------- */
document.addEventListener('click', async (e)=>{
  const t = e.target;

  if (t.classList.contains('tab-btn')){
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('on'));
    t.classList.add('on');
    const tab = t.dataset.tab;
    document.querySelectorAll('.tab').forEach(x=> x.classList.remove('on'));
    document.getElementById('tab-'+tab).classList.add('on');
  }

  if (t.classList.contains('rng')){
    document.querySelectorAll('.rng').forEach(b=>b.classList.remove('on'));
    t.classList.add('on');
    RANGE = t.dataset.range || '30d';
    loadOverview();
  }

  if (t.classList.contains('btn-v-appr')){
    const uid = t.dataset.user;
    if (!uid) return;
    if (!confirm('Approve this verification?')) return;
    const j = await jpost(epUrl(EP.verify), { user_id: uid });
    if (j && j.ok) { loadVerifications(document.getElementById('global-search').value||''); loadOverview(); }
    else alert(j && j.error ? j.error : 'Approve failed.');
  }
  if (t.classList.contains('btn-v-rej')){
    const uid = t.dataset.user;
    if (!uid) return;
    const reason = prompt('Reason for rejection (optional):','');
    const j = await jpost(epUrl(EP.reject), { user_id: uid, reason: reason||'' });
    if (j && j.ok) { loadVerifications(document.getElementById('global-search').value||''); loadOverview(); }
    else alert(j && j.error ? j.error : 'Reject failed.');
  }
});

/* ------- Search & refresh ------- */
document.getElementById('btn-search-v').addEventListener('click', ()=>{
  loadVerifications(document.getElementById('global-search').value||'');
});
document.getElementById('btn-refresh').addEventListener('click', ()=>{
  loadOverview();
  loadVerifications(document.getElementById('global-search').value||'');
});

/* ------- Utils ------- */
function esc(s){ return String(s??'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;', "'":'&#39;' }[m])); }
function num(n){ const x = +n; return Number.isFinite(x) ? x : 0; }
function setTxt(id, v){ const el = document.getElementById(id); if (el) el.textContent = v; }
function fmtInt(n){ return Number(n||0).toLocaleString(); }

/* ------- Init ------- */
loadOverview();
loadVerifications('');
</script>
<?php render_footer();
