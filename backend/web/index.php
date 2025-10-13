<?php
/* =================================================================
 * File: backend/web/index.php  (Home / Feed with IG-like actions)
 * ================================================================= */
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth();

$skill = $_GET['skill'] ?? 'all';

// who am I? (for permissions)
$me    = api_get('profile_overview.php');
$meU   = (array)($me['user'] ?? []);
$ME_ID = (string)($meU['id'] ?? '');
$IS_ADMIN = strtolower((string)($meU['role'] ?? '')) === 'admin';

// feed
$feed  = api_get('feed.php', ['skill'=>$skill]);
$items = is_array($feed['items'] ?? null) ? $feed['items'] : [];

render_header('Home');
?>
<!-- Filter chips -->
<div class="chips sticky-chips">
  <?php foreach (skills_labels() as $k=>$label):
        $on = $skill===$k ? 'chip on' : 'chip'; ?>
    <a class="<?= $on ?>" href="?skill=<?= h($k) ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$items): ?>
  <div class="card" style="padding:16px">No posts yet.</div>
<?php endif; ?>

<?php foreach ($items as $p):
  $p = (array)$p;
  $u = (array)($p['user'] ?? []);
  $pid    = (string)($p['id'] ?? '');
  $uid    = (string)($u['id'] ?? '');
  $name   = (string)($u['display_name'] ?? $u['name'] ?? 'User');
  $handle = (string)($u['username'] ?? '');
  $ava    = (string)($u['avatar_url'] ?? '');
  $badge  = strtoupper((string)($p['skill'] ?? ''));
  $cap    = (string)($p['caption'] ?? '');
  $media  = (string)($p['media_url'] ?? '');
  // --- IMPORTANT: infer media type like profile.php when API doesn't send it ---
  $mtype  = strtolower((string)($p['media_type'] ?? ''));
  if ($mtype === '' && $media !== '') {
    $path = parse_url($media, PHP_URL_PATH) ?: '';
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    // Keep list conservative to maximize native browser support.
    $mtype = in_array($ext, ['mp4','webm','mov','m4v'], true) ? 'video' : 'image';
  }
  if ($mtype === '') { $mtype = 'image'; } // final guard
  // ---------------------------------------------------------------------------
  $likes  = (int)($p['likes'] ?? 0);
  $cmts   = (int)($p['comments'] ?? 0);
  $ago    = (string)($p['time_ago'] ?? '');
  $liked  = !empty($p['liked']);
  $CAN_DELETE = $IS_ADMIN || ($uid !== '' && $uid === $ME_ID);
?>
  <article class="card post-card" data-post-id="<?= h($pid) ?>" style="margin:0 auto 14px;overflow:hidden">
    <header class="post-head">
      <a href="<?=WEB_BASE?>/public_profile.php?user_id=<?=urlencode($uid)?>" style="display:inline-block">
        <?php avatar_img($u, ['class'=>'ava', 'alt'=>'']); ?>
      </a>
      <div style="flex:1;min-width:0">
        <div>
          <a href="<?=WEB_BASE?>/public_profile.php?user_id=<?=urlencode($uid)?>" style="color:inherit;text-decoration:none">
            <b><?=h($name)?></b>
          </a>
          <?php if($badge): ?><span class="badge"><?=h($badge)?></span><?php endif; ?>
        </div>
        <div class="handle">@<?=h($handle)?> · <?=h($ago)?></div>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <?php if ($CAN_DELETE): ?>
          <button class="icon-btn btn-del-post" type="button" data-id="<?=h($pid)?>" title="Delete post" aria-label="Delete post" style="color:#b00000">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">
              <path d="M6 7h12l-1 13H7L6 7zm2-3h8l1 2H7l1-2z"/>
            </svg>
          </button>
        <?php endif; ?>
        <?=icon('menu')?>
      </div>
    </header>

    <?php if ($media): ?>
      <div class="post-media media-natural">
        <?php if ($mtype === 'video'): ?>
          <video
            src="<?=h($media)?>"
            controls
            playsinline
            preload="metadata"
            class="media-el"
          ></video>
        <?php else: ?>
          <img
            src="<?=h($media)?>"
            alt=""
            loading="lazy"
            class="media-el"
          />
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="post-body">
      <div class="actions-row" style="margin-top:6px">
        <button class="icon-btn btn-like" type="button" data-liked="<?= $liked ? '1':'0' ?>" aria-label="<?= $liked ? 'Unlike':'Like' ?>" title="<?= $liked ? 'Unlike':'Like' ?>">
          <svg class="heart" viewBox="0 0 24 24" width="26" height="26" fill="<?= $liked ? '#111' : 'none' ?>" stroke="#111" stroke-width="1.7">
            <path d="M12 21s-8-4.35-8-10a5 5 0 0 1 9-3 5 5 0 0 1 9 3c0 5.65-8 10-8 10z"/>
          </svg>
        </button>

        <button class="icon-btn btn-comments" type="button" aria-label="Comments" title="Comments" style="margin-left:6px">
          <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="#111" stroke-width="1.7">
            <path d="M21 15a4 4 0 0 1-4 4H7l-4 3V5a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4v10z"/>
          </svg>
        </button>
      </div>

      <div class="likes-line" style="margin-top:6px"><b class="like-count"><?= $likes ?></b> likes</div>

      <?php if ($cap): ?>
        <div style="margin-top:6px"><b><a href="<?=WEB_BASE?>/public_profile.php?user_id=<?=urlencode($uid)?>" style="color:inherit;text-decoration:none"><?=h($name)?></a></b> <?=nl2br(h($cap))?></div>
      <?php endif; ?>

      <div class="view-comments handle" style="margin-top:6px;cursor:pointer">
        <?php if ($cmts>0): ?>
          View all <span class="c-count"><?= $cmts ?></span> comments
        <?php else: ?>
          Add a comment
        <?php endif; ?>
      </div>

      <div class="comments" style="display:none;margin-top:8px">
        <div class="c-list" style="display:flex;flex-direction:column;gap:10px"></div>
        <form class="c-form" style="display:flex;gap:8px;margin-top:10px">
          <input type="text" name="text" placeholder="Add a comment…" required
                 style="flex:1;padding:10px;border:1px solid #ddd;border-radius:10px">
          <button class="btn">Post</button>
        </form>
      </div>
    </div>
  </article>
<?php endforeach; ?>

<style>
  .post-card{max-width:600px}
  .media-natural{background:#000;border-top:1px solid var(--bd);border-bottom:1px solid var(--bd)}
  .media-natural .media-el{
    display:block;max-width:100%;height:auto;margin:0 auto;object-fit:contain;background:#000;
  }
  .post-media img.media-el,.post-media video.media-el{max-height:none;object-fit:contain}
  .icon-btn{appearance:none;background:transparent;border:0;cursor:pointer;padding:2px;border-radius:8px}
  .icon-btn:active{transform:scale(.98)}
  .sticky-chips{position: sticky;top: 0;z-index: 10;padding-top: 8px;padding-bottom: 8px;margin-bottom: 12px;background: var(--bg);border-bottom: 1px solid var(--bd);}
</style>

<script>
/* ---------- Config from PHP ---------- */
const API_BASE = <?= json_encode(API_BASE_URL) ?>;
const TOKEN    = <?= json_encode(web_token()) ?>;
const ME_ID    = <?= json_encode($ME_ID) ?>;
const IS_ADMIN = <?= $IS_ADMIN ? 'true' : 'false' ?>;
const DEFAULT_AVA = <?= json_encode(default_avatar_data_uri()) ?>;
const WEB_BASE = <?= json_encode(WEB_BASE) ?>;
window.DEF_AVA = DEFAULT_AVA; // used when a comment has no avatar

/* ---------- Endpoints ---------- */
const COMMENTS_LIST   = API_BASE + 'comments_list.php';
const COMMENT_CREATE  = API_BASE + 'comment_create.php';
const COMMENT_DELETE  = API_BASE + 'comment_delete.php';
const LIKE_ENDPOINT   = API_BASE + 'like_toggle.php';
const POST_DELETE_EP  = API_BASE + 'post_delete.php';

/* ---------- Helpers ---------- */
const $  = (sel, ctx=document) => ctx.querySelector(sel);
const $$ = (sel, ctx=document) => Array.from(ctx.querySelectorAll(sel));
function el(tag, cls){ const x=document.createElement(tag); if(cls) x.className=cls; return x; }

/* ---------- Click handlers ---------- */
document.addEventListener('click', (ev)=>{
  const t = ev.target;

  if (t.closest('.btn-comments') || t.closest('.view-comments')) {
    const card = t.closest('article');
    const tray = $('.comments', card);
    tray.style.display = (tray.style.display === 'none' || !tray.style.display) ? 'block' : 'none';
    if (tray.style.display === 'block') { loadComments(card); }
  }

  if (t.closest('.btn-like')) {
    const card = t.closest('article');
    const btn  = t.closest('.btn-like');
    likeToggle(card, btn);
  }

  if (t.closest('.c-del')) {
    ev.preventDefault();
    const btn  = t.closest('.c-del');
    const card = t.closest('article');
    const cid  = btn.dataset.id;
    if (!cid) return;
    if (!confirm('Delete this comment?')) return;
    deleteComment(card, cid, btn);
  }

  if (t.closest('.btn-del-post')) {
    const btn = t.closest('.btn-del-post');
    const card = t.closest('article');
    const pid  = btn?.dataset.id;
    if (!pid || !card) return;
    if (!confirm('Delete this post?')) return;
    deletePost(card, pid, btn);
  }
});

// submit comment
$$('article').forEach(card=>{
  const form = $('.c-form', card);
  if (form) {
    form.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const input = $('input[name="text"]', form);
      const txt = (input.value || '').trim();
      if (!txt) return;
      const ok = await createComment(card, txt);
      if (ok) { input.value=''; loadComments(card); }
    });
  }
});

/* ---------- Likes ---------- */
async function likeToggle(article, btn){
  const pid = article?.dataset.postId;
  if (!pid) return;
  const heart = $('.heart', btn);
  const likeLine = $('.like-count', article);
  const liked = btn.dataset.liked === '1';

  btn.dataset.liked = liked ? '0' : '1';
  heart.setAttribute('fill', liked ? 'none' : '#111');
  const cur = parseInt((likeLine.textContent||'0'),10)||0;
  likeLine.textContent = String(Math.max(0, cur + (liked ? -1 : 1)));

  try{
    const body = new URLSearchParams({ post_id: pid, like: liked ? '0':'1', token: TOKEN });
    const r = await fetch(LIKE_ENDPOINT, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
    const j = await r.json();
    if (!j || (j.ok !== true && j.status !== 'ok')) throw new Error(j && j.error || 'Like failed');
    if (typeof j.likes === 'number') likeLine.textContent = String(j.likes);
    if (typeof j.liked === 'boolean') {
      btn.dataset.liked = j.liked ? '1':'0';
      heart.setAttribute('fill', j.liked ? '#111' : 'none');
    }
  }catch(e){
    btn.dataset.liked = liked ? '1' : '0';
    heart.setAttribute('fill', liked ? '#111' : 'none');
    likeLine.textContent = String(Math.max(0, cur));
    alert('Could not update like. Please try again.');
  }
}

/* ---------- Comments: list / render ---------- */
async function loadComments(article){
  const tray = $('.comments', article);
  const list = $('.c-list', tray);
  const id   = article?.dataset.postId;
  if (!id || !list) return;
  list.innerHTML = '<div class="muted">Loading…</div>';
  try{
    const r = await fetch(COMMENTS_LIST + '?post_id='+encodeURIComponent(id)+'&limit=100'+'&token='+encodeURIComponent(TOKEN));
    const j = await r.json();
    const items = Array.isArray(j.items) ? j.items
                : (Array.isArray(j.comments) ? j.comments : []);
    renderComments(article, list, items);
  }catch(e){
    list.innerHTML = '<div class="muted">Failed to load comments.</div>';
  }
}

function renderComments(article, list, items){
  list.innerHTML='';
  if(!items || items.length===0){
    list.innerHTML='<div class="muted">No comments yet.</div>';
    return;
  }
  for(const c of items){
    const user = c.user || {};
    const name = (user.display_name || user.name || 'User');
    const when = c.time_ago || c.created_at || c.created || '';
    const ava  = (user.avatar_url || '').trim();
    const body = c.text ?? c.content ?? c.body ?? c.message ?? c.caption ?? c.comment ?? '';

    // get commenter id from either c.user_id or user.id
    const ownerId = (c.user_id !== undefined && c.user_id !== null) ? String(c.user_id)
                    : (user.id !== undefined && user.id !== null) ? String(user.id)
                    : '';
    const canDelete = IS_ADMIN || (ownerId && ME_ID && ownerId === ME_ID);
    const profileUrl = ownerId ? `${WEB_BASE}/public_profile.php?user_id=${encodeURIComponent(ownerId)}` : null;

    const row  = el('div','c-item'); row.style.padding='6px 0';

    // header
    const head = el('div','c-head');
    head.style.display='flex';
    head.style.alignItems='center';
    head.style.gap='8px';

    // avatar (link if we know user id)
    const img  = el('img','c-ava');
    img.src = ava !== '' ? ava : DEFAULT_AVA;
    img.alt=''; img.style.width='26px'; img.style.height='26px';
    img.style.borderRadius='999px'; img.style.objectFit='cover'; img.style.background='#eee';

    if (profileUrl) {
      const aAva = el('a');
      aAva.href = profileUrl;
      aAva.style.display='inline-block';
      aAva.appendChild(img);
      head.appendChild(aAva);
    } else {
      head.appendChild(img);
    }

    // name (link if we know user id)
    if (profileUrl) {
      const aName = el('a');
      aName.href = profileUrl;
      aName.textContent = name;
      aName.className = 'c-name';
      aName.style.fontWeight='700';
      aName.style.color='inherit';
      aName.style.textDecoration='none';
      head.appendChild(aName);
    } else {
      const nm = el('div','c-name');
      nm.textContent = name;
      nm.style.fontWeight='700';
      head.appendChild(nm);
    }

    const ts   = el('div','c-when');
    ts.textContent = when;
    ts.className='handle';
    ts.style.marginLeft='auto';
    head.appendChild(ts);

    // body text
    const txt = el('div','c-text');
    txt.textContent = body;
    txt.style.marginTop='4px';

    row.appendChild(head);
    row.appendChild(txt);

    // delete action
    if (canDelete && c.id) {
      const del = el('a','c-del');
      del.href='#'; del.dataset.id = String(c.id);
      del.textContent = 'Delete';
      del.style.color = '#b00000';
      del.style.fontSize='12px';
      row.appendChild(del);
    }

    list.appendChild(row);
  }
}


/* ---------- Comments: create/delete (Bearer + ?token= + body) ---------- */
async function createComment(article, text){
  const id = article?.dataset.postId; if(!id) return false;
  const countLine = $('.view-comments .c-count', article) || $('.c-count', article);

  try{
    // also pass token in query param to survive stripped Authorization headers
    const url = COMMENT_CREATE + '?token=' + encodeURIComponent(TOKEN);
    const payload = new URLSearchParams({ post_id:id, body:text });

    const r = await fetch(url, {
      method:'POST',
      headers:{
        'Content-Type':'application/x-www-form-urlencoded',
        'Authorization':'Bearer ' + TOKEN
      },
      body: payload
    });

    const j = await r.json().catch(()=> ({}));
    if(!r.ok || j.ok!==true) throw new Error(j && j.error || 'Create failed');

    if (countLine) {
      const cur = parseInt((countLine.textContent||'0'),10)||0;
      countLine.textContent = String(j.count ?? (cur+1));
      const vc = $('.view-comments', article);
      if (vc && vc.firstChild && vc.firstChild.nodeType === 3) vc.firstChild.textContent = 'View all ';
    }
    return true;
  }catch(e){
    alert('Could not post comment.');
    return false;
  }
}

async function deleteComment(article, commentId, btn){
  const id = article?.dataset.postId; if(!id) return;
  const countLine = $('.view-comments .c-count', article) || $('.c-count', article);
  try{
    const url = COMMENT_DELETE + '?token=' + encodeURIComponent(TOKEN);
    const payload = new URLSearchParams({ comment_id: commentId, post_id:id });

    const r = await fetch(url, {
      method:'POST',
      headers:{
        'Content-Type':'application/x-www-form-urlencoded',
        'Authorization':'Bearer ' + TOKEN
      },
      body: payload
    });

    const j = await r.json().catch(()=> ({}));
    if(!r.ok || j.ok!==true) throw new Error(j && j.error || 'Delete failed');

    const row = btn.closest('.c-item');
    if (row && row.parentElement) row.parentElement.removeChild(row);

    if (countLine) {
      const cur = parseInt((countLine.textContent||'0'),10)||0;
      countLine.textContent = String(Math.max(0, j.count ?? (cur-1)));
    }
  } catch(e){
    alert('Could not delete comment.');
  }
}

/* ---------- Delete post ---------- */
async function deletePost(article, postId, btn){
  try{
    const body = new URLSearchParams({ post_id: postId, token: TOKEN });
    const r = await fetch(POST_DELETE_EP, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body
    });
    const j = await r.json();
    if(!j || j.ok !== true){
      throw new Error((j && (j.error || j.detail)) || 'Delete failed');
    }
    article.parentElement && article.parentElement.removeChild(article);
  }catch(e){
    alert('Could not delete post. '+ (e && e.message ? e.message : ''));
  }
}
</script>

<?php render_footer();
