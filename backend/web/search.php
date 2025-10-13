<?php
/* =================================================================
 * backend/web/search.php (avatar-fix)
 * ================================================================= */
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth();

render_header('Search');
?>
<div class="card" style="padding:12px;margin-bottom:12px">
  <form id="search-form" style="display:flex;gap:8px;flex-wrap:wrap" onsubmit="return false">
    <input id="q" type="text" placeholder="Search posts or users…" style="flex:1;min-width:260px;padding:10px;border:1px solid #ddd;border-radius:10px">
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <button class="btn out tab-btn on" data-type="posts" type="button">Posts</button>
      <button class="btn out tab-btn" data-type="users" type="button">Users</button>
    </div>
    <button id="btn-go" class="btn" type="button">Search</button>
  </form>
</div>

<section id="sec-posts" class="card" style="padding:12px;display:block">
  <div id="posts-grid" style="display:grid;grid-template-columns:repeat(3,minmax(160px,1fr));gap:10px"></div>
  <div id="posts-empty" class="muted" style="padding:8px;display:none">No posts found.</div>
  <div style="text-align:center;margin-top:10px">
    <button id="more-posts" class="btn out" type="button" style="display:none">Load more</button>
  </div>
</section>

<section id="sec-users" class="card" style="padding:12px;display:none">
  <div id="users-list"></div>
  <div id="users-empty" class="muted" style="padding:8px;display:none">No users found.</div>
  <div style="text-align:center;margin-top:10px">
    <button id="more-users" class="btn out" type="button" style="display:none">Load more</button>
  </div>
</section>

<script>
const API_BASE = <?= json_encode(API_BASE_URL) ?>;
const TOKEN    = <?= json_encode(web_token()) ?>;

const EP = { search:['search.php'], searchUsers:['search_users.php'] };

function epUrl(list){ return list.map(x => API_BASE + x); }
function withToken(u){ return u + (u.includes('?')?'&':'?') + 'token=' + encodeURIComponent(TOKEN); }
async function jget(urls, qs=''){
  for (const u of urls) {
    try { const r = await fetch(withToken(u+(qs||'')), { headers:{ 'Authorization':'Bearer '+TOKEN }}); if (!r.ok) continue; return await r.json(); } catch(_) {}
  }
  return null;
}
function esc(s){ return String(s??'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;', "'":'&#39;' }[m])); }

// ---------- post link fallbacks to avoid blank post.php ----------
function inferTypeFromUrl(url){ const p=(url||'').split('?')[0].toLowerCase(); if(/\.(mp4|mov|webm)$/.test(p))return'video'; if(/\.(jpg|jpeg|png|webp|gif|bmp|avif)$/.test(p))return'image'; return''; }
function postHref(pid, mediaUrl, mediaType, likes){
  const qs = new URLSearchParams({ id:String(pid) });
  if (mediaUrl) qs.set('media', mediaUrl);
  if (!mediaType) mediaType = inferTypeFromUrl(mediaUrl);
  if (mediaType) qs.set('type', mediaType);
  if (Number.isFinite(likes) && likes>0) qs.set('likes', String(likes|0));
  return <?= json_encode(WEB_BASE.'/post.php') ?> + '?' + qs.toString();
}
// -----------------------------------------------------------------

let STATE = { type:'posts', q:'', pagePosts:1, pageUsers:1, limitPosts:12, limitUsers:20, loading:false };

function switchTab(type){
  STATE.type = type;
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('on'));
  document.querySelector(`.tab-btn[data-type="${type}"]`)?.classList.add('on');
  document.getElementById('sec-posts').style.display = (type==='posts') ? 'block' : 'none';
  document.getElementById('sec-users').style.display = (type==='users') ? 'block' : 'none';
}

async function searchPosts(reset){
  if (STATE.loading) return;
  STATE.loading = true;
  if (reset){ STATE.pagePosts = 1; document.getElementById('posts-grid').innerHTML=''; }

  const qs = `?type=posts&q=${encodeURIComponent(STATE.q)}&page=${STATE.pagePosts}&limit=${STATE.limitPosts}`;
  let j = await jget(epUrl(EP.search), qs);
  if (!j || !j.posts) j = await jget(epUrl(EP.searchUsers), qs);

  const posts = (j && j.posts) || [];
  const grid = document.getElementById('posts-grid');

  for (const p of posts){
    const pid = p.id ?? p.post_id; if (!pid) continue;
    const media = String(p.media_url || '');
    const type  = String(p.media_type || '');
    const likes = Number.isFinite(p.likes) ? p.likes : (p.like_count|0);

    const a = document.createElement('a');
    a.href = postHref(pid, media, type, likes);
    a.className = 'card';
    a.style.display='block';
    a.style.overflow='hidden';
    a.innerHTML = `
      <div style="aspect-ratio:1/1;background:#f5f5f5;display:flex;align-items:center;justify-content:center;overflow:hidden">
        ${ media ? `<img src="${esc(media)}" alt="" style="width:100%;height:100%;object-fit:cover">` : `<div class="muted">No media</div>` }
      </div>
      <div style="padding:8px">
        <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(p.caption||'—')}</div>
        <div class="muted" style="font-size:12px">${esc(p.name||'—')} · @${esc(p.username||'')}</div>
        <div class="muted" style="font-size:12px;margin-top:4px">❤ ${(likes||0)} · 💬 ${(p.comments||0)}</div>
      </div>`;
    grid.appendChild(a);
  }

  document.getElementById('posts-empty').style.display = (STATE.pagePosts===1 && posts.length===0) ? 'block' : 'none';
  document.getElementById('more-posts').style.display = posts.length < STATE.limitPosts ? 'none' : 'inline-flex';

  STATE.pagePosts++; STATE.loading = false;
}

// ---------- USERS: avatar tolerant render + lazy hydration ----------
const AVA_CACHE = Object.create(null);
function resolveAvatar(u){
  // why: APIs return different keys; scan common ones + nested
  return u.avatar_url || u.avatar || u.photo_url || u.profile_pic_url || u.image_url ||
         (u.user && (u.user.avatar_url || u.user.avatar)) || '';
}

async function hydrateUserAvatars(){
  const rows = document.querySelectorAll('#users-list img[data-missing="1"][data-uid]');
  if (!rows.length) return;
  const tokenQS = 'token=' + encodeURIComponent(TOKEN);
  for (const img of rows) {
    const uid = img.getAttribute('data-uid');
    if (!uid) continue;
    if (AVA_CACHE[uid]) { img.src = AVA_CACHE[uid]; img.removeAttribute('data-missing'); continue; }
    try {
      const r = await fetch(`${API_BASE}profile_overview.php?${tokenQS}&user_id=${encodeURIComponent(uid)}`);
      if (!r.ok) continue;
      const j = await r.json();
      const url = j?.user?.avatar_url || j?.user?.avatar || '';
      if (url) { AVA_CACHE[uid] = url; img.src = url; img.removeAttribute('data-missing'); }
    } catch {}
  }
}

async function searchUsers(reset){
  if (STATE.loading) return;
  STATE.loading = true;
  if (reset){ STATE.pageUsers = 1; document.getElementById('users-list').innerHTML=''; }

  const qs = `?type=users&q=${encodeURIComponent(STATE.q)}&page=${STATE.pageUsers}&limit=${STATE.limitUsers}`;
  let j = await jget(epUrl(EP.search), qs);
  if (!j || !j.users) j = await jget(epUrl(EP.searchUsers), qs);

  const users = (j && j.users) || [];
  const list = document.getElementById('users-list');

  for (const u of users){
    const uid   = u.id;
    const name  = esc(u.name || u.display_name || '');
    const uname = esc(u.username || '');
    const ava   = resolveAvatar(u);

    const row = document.createElement('div');
    row.className = 'card';
    row.style.padding = '10px';
    row.style.marginBottom = '8px';
    row.innerHTML = `
      <div style="display:flex;align-items:center;gap:10px">
        <img src="${ava ? esc(ava) : window.DEF_AVA}" alt="" class="ava" style="width:40px;height:40px"
             ${ava ? '' : `data-uid="${String(uid||'')}" data-missing="1"`}>
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${name || '—'}</div>
          <div class="muted" style="font-size:12px">@${uname}</div>
        </div>
        <a class="btn out" href="<?= h(WEB_BASE) ?>/public_profile.php?user_id=${encodeURIComponent(uid)}">View</a>
      </div>`;
    list.appendChild(row);
  }

  document.getElementById('users-empty').style.display = (STATE.pageUsers===1 && users.length===0) ? 'block' : 'none';
  document.getElementById('more-users').style.display = users.length < STATE.limitUsers ? 'none' : 'inline-flex';

  STATE.pageUsers++; STATE.loading = false;

  // fetch missing avatars in background
  hydrateUserAvatars();
}
// -------------------------------------------------------------------

function runSearch(){
  STATE.q = (document.getElementById('q').value || '').trim();
  if (STATE.type === 'posts') searchPosts(true); else searchUsers(true);
}

document.getElementById('btn-go').addEventListener('click', runSearch);
document.getElementById('q').addEventListener('keydown', (e)=>{ if (e.key==='Enter') runSearch(); });
document.querySelectorAll('.tab-btn').forEach(b=>{
  b.addEventListener('click', ()=>{ switchTab(b.dataset.type); runSearch(); });
});
document.getElementById('more-posts').addEventListener('click', ()=> searchPosts(false));
document.getElementById('more-users').addEventListener('click', ()=> searchUsers(false));

runSearch();
</script>
<?php render_footer();
