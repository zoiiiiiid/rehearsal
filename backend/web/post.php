<?php
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth();

$id = (string)($_GET['id'] ?? '');
if ($id === '') redirect(WEB_BASE.'/index.php');

/* who am i (admin/owner can delete) */
$me    = api_get('profile_overview.php');
$userC = (array)($me['user'] ?? []);
$isAdmin = strtolower((string)($userC['role'] ?? '')) === 'admin';

/* ---------- helpers ---------- */
function first_ok(array $arr) {
  foreach ($arr as $x) if (is_array($x) && $x) return $x;
  return [];
}

/** Try to pull a URL & type from many shapes */
function extract_media(array $core): array {
  $url = (string)(
    $core['media_url'] ?? $core['image_url'] ?? $core['video_url'] ??
    $core['url'] ?? $core['image'] ?? $core['file_url'] ?? $core['src'] ?? ''
  );

  if ($url === '' && isset($core['media']) && is_array($core['media'])) {
    $m   = $core['media'];
    $url = (string)($m['url'] ?? $m['image_url'] ?? $m['video_url'] ?? '');
    $typ = strtolower((string)($m['type'] ?? ''));
    if ($typ === '') $typ = (isset($m['video_url']) ? 'video' : 'image');
    if ($url !== '') return [$url, $typ];
  }

  $type = strtolower((string)(
    $core['media_type'] ?? ($core['video_url'] ?? '') ? 'video' : 'image'
  ));
  $type = ($type === 'video') ? 'video' : 'image';

  return [$url, $type];
}

function post_from_payload(array $p): array {
  $core = first_ok([
    (array)($p['post'] ?? []),
    (array)($p['item'] ?? []),
    (array)($p['data'] ?? []),
    $p,
  ]);

  $out = [];
  $out['id']         = (string)($core['id'] ?? $core['post_id'] ?? $core['pid'] ?? '');
  $out['user']       = (array)($core['user'] ?? []);
  $out['user_id']    = (string)($core['user_id'] ?? $out['user']['id'] ?? '');
  $out['caption']    = (string)($core['caption'] ?? $core['text'] ?? $core['body'] ?? '');

  $likes = $core['likes'] ?? $core['like_count'] ?? $core['likes_count'] ?? $core['total_likes'] ?? null;
  if ($likes === null && !empty($core['likers']) && is_array($core['likers'])) $likes = count($core['likers']);
  $out['likes']      = (int)($likes ?? 0);
  $out['liked']      = (bool)($core['liked'] ?? $core['is_liked'] ?? $core['has_liked'] ?? false);
  $out['time_ago']   = (string)($core['time_ago'] ?? $core['created_at'] ?? $core['created'] ?? '');
  $out['skill']      = (string)($core['skill'] ?? '');

  [$url, $type]      = extract_media($core);
  $out['media_url']  = $url;
  $out['media_type'] = $type;

  $u = &$out['user'];
  $u['display_name'] = (string)($u['display_name'] ?? $u['name'] ?? '');
  $u['username']     = (string)($u['username'] ?? $u['handle'] ?? '');
  $u['avatar_url']   = (string)($u['avatar_url'] ?? '');

  return $out;
}

/* ---------- load post detail ---------- */
$detail = first_ok([
  api_get('post_detail.php', ['id'=>$id]),
  api_get('post_get.php',    ['id'=>$id]),
  api_get('post_view.php',   ['id'=>$id]),
  api_get('post.php',        ['id'=>$id]),
]);
$post = post_from_payload($detail);

/* ---- GET fallbacks from profile grid (media/type/likes passed in link) ---- */
$qsMedia = trim((string)($_GET['media'] ?? ''));
$qsType  = strtolower(trim((string)($_GET['type']  ?? '')));
$qsLikes = (int)($_GET['likes'] ?? 0);
if (($post['media_url'] ?? '') === '' && $qsMedia !== '') {
  $infer = (preg_match('/\.(mp4|mov|webm)(\?|$)/i', $qsMedia) ? 'video' : 'image');
  $post['media_url']  = $qsMedia;
  $post['media_type'] = in_array($qsType, ['video','image'], true) ? $qsType : $infer;
}
if ((int)($post['likes'] ?? 0) === 0 && $qsLikes > 0) {
  $post['likes'] = $qsLikes;
}

/* ---- If still missing media/likes, look in profile_posts ---- */
if (($post['media_url'] ?? '') === '' && ($post['user_id'] ?? '') !== '') {
  $pp = api_get('/profile_posts.php', ['user_id'=>$post['user_id']]);
  $plist = (array)($pp['posts'] ?? $pp['items'] ?? []);
  foreach ($plist as $it0) {
    $it = (array)$it0;
    $pid = (string)($it['id'] ?? $it['post_id'] ?? '');
    if ($pid === $post['id']) {
      [$url2, $type2] = extract_media($it);
      if ($url2 !== '') { $post['media_url'] = $url2; $post['media_type'] = $type2; }
      if (empty($post['caption'])) $post['caption'] = (string)($it['caption'] ?? $it['text'] ?? '');
      if ((int)$post['likes'] === 0) {
        $l2 = $it['likes'] ?? $it['like_count'] ?? $it['likes_count'] ?? $it['total_likes'] ?? 0;
        $post['likes'] = (int)$l2;
      }
      break;
    }
  }
}

/* ---- Ensure we have display name + username (fetch overview by user_id) ---- */
if (empty($post['user']['display_name']) || empty($post['user']['username'])) {
  $uid = (string)($post['user_id'] ?? '');
  if ($uid !== '') {
    $ov = api_get('/profile_overview.php', ['user_id'=>$uid]);
    $ou = (array)($ov['user'] ?? []);
    if ($ou) {
      $post['user']['display_name'] = (string)($post['user']['display_name'] ?: ($ou['display_name'] ?? $ou['name'] ?? 'User'));
      $post['user']['username']     = (string)($post['user']['username']     ?: ($ou['username'] ?? ''));
      $post['user']['avatar_url']   = (string)($post['user']['avatar_url']   ?: ($ou['avatar_url'] ?? ''));
    }
  }
}

/* ---------- load comments ---------- */
$cm = first_ok([
  api_get('comments_list.php', ['post_id'=>$id, 'limit'=>200]),
  api_get('comment_list.php',  ['post_id'=>$id, 'limit'=>200]),
]);
$comments = (array)($cm['items'] ?? $cm['comments'] ?? []);

/* Permissions for delete button */
$canDeletePost = $isAdmin || ((string)($post['user_id'] ?? '') !== '' && (string)($post['user_id']) === (string)($userC['id'] ?? ''));

$title = 'Post';
render_header($title);
?>
<div class="card" style="max-width:720px;margin:0 auto">
  <header class="post-head">
    <?php avatar_img((array)($post['user'] ?? []), ['class'=>'ava','alt'=>'']); ?>
    <div style="flex:1">
      <div>
        <b><?=h((string)($post['user']['display_name'] ?? 'User'))?></b>
        <?php if (!empty($post['skill'])): ?>
          <span class="badge"><?=h(strtoupper((string)$post['skill']))?></span>
        <?php endif; ?>
      </div>
      <div class="handle">
        @<?=h((string)($post['user']['username'] ?? ''))?>
        <?php if (!empty($post['time_ago'])): ?> · <?=h($post['time_ago'])?><?php endif; ?>
      </div>
    </div>
    <div><?=icon('menu')?></div>
  </header>

  <?php if (!empty($post['media_url'])): ?>
    <div class="post-media" style="background:#000">
      <?php if (($post['media_type'] ?? 'image') === 'video'): ?>
        <video src="<?=h((string)$post['media_url'])?>" controls playsinline preload="metadata" style="width:100%;height:auto;display:block"></video>
      <?php else: ?>
        <img src="<?=h((string)$post['media_url'])?>" alt="" style="width:100%;height:auto;display:block;object-fit:contain;background:#000">
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="post-body">
    <?php if (!empty($post['caption'])): ?>
      <div style="margin-bottom:8px"><?=nl2br(h((string)$post['caption']))?></div>
    <?php endif; ?>

    <div class="actions-row" style="justify-content:space-between">
      <!-- Like (AJAX) -->
      <button id="btn-like" class="btn out" type="button" data-liked="<?= !empty($post['liked']) ? '1':'0' ?>">
        <?=icon('heart')?> <span id="like-label"><?= !empty($post['liked']) ? 'Unlike' : 'Like' ?></span>
      </button>

      <div class="counts">
        <span><b id="likes-count"><?= (int)($post['likes'] ?? 0) ?></b> likes</span>
        <span><b id="comments-count"><?= count($comments) ?></b> comments</span>
      </div>
    </div>

    <?php if ($canDeletePost): ?>
      <div class="hr"></div>
      <form method="post" action="<?=WEB_BASE?>/act_post_delete.php" onsubmit="return confirm('Delete this post?')" style="margin:0">
        <?php csrf_field(); ?>
        <input type="hidden" name="post_id" value="<?=h($id)?>">
        <button class="btn out" style="color:#b00;border-color:#f3c">Delete post</button>
      </form>
    <?php endif; ?>

    <div class="hr"></div>
    <h3 style="margin:0 0 8px 0">Comments</h3>

    <!-- create comment (AJAX) -->
    <form id="c-form" class="row" style="display:flex;gap:8px;margin-bottom:10px">
      <?php csrf_field(); ?>
      <input type="text" id="c-text" name="text" placeholder="Add a comment…" required style="flex:1;padding:10px;border:1px solid #ddd;border-radius:10px">
      <button class="btn" type="submit">Post</button>
    </form>

    <?php if (!$comments): ?>
      <div class="muted" id="c-empty">No comments yet.</div>
    <?php endif; ?>

    <div id="c-list-wrap">
      <?php foreach ($comments as $c0): $c=(array)$c0; $cu=(array)($c['user'] ?? []); ?>
        <div class="card c-item" style="border:0;border-top:1px solid #eee;border-radius:0;padding:10px 0">
          <div style="display:flex;gap:8px;align-items:center">
            <?php avatar_img($cu, ['class'=>'ava','alt'=>'']); ?>
            <div style="flex:1">
              <b><?=h((string)($cu['display_name'] ?? $cu['name'] ?? 'User'))?></b>
              <div class="muted" style="font-size:12px"><?=h((string)($c['time_ago'] ?? $c['created_at'] ?? ''))?></div>
            </div>
          </div>
          <div style="margin-top:6px"><?=nl2br(h((string)($c['text'] ?? $c['content'] ?? $c['body'] ?? ''))) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
const API_BASE    = <?= json_encode(API_BASE_URL) ?>;
const TOKEN       = <?= json_encode(web_token()) ?>;
const POST_ID     = <?= json_encode($id) ?>;
const DEFAULT_AVA = <?= json_encode(default_avatar_data_uri()) ?>;

/* Endpoints */
const COMMENTS_LIST  = API_BASE + 'comments_list.php';
const COMMENT_CREATE = API_BASE + 'comment_create.php'; // expects Authorization: Bearer and body=
const LIKE_ENDPOINT  = API_BASE + 'like_toggle.php';

const $ = (s,ctx=document)=>ctx.querySelector(s);

const btnLike   = $('#btn-like');
const likeLabel = $('#like-label');
const likeCount = $('#likes-count');
const cForm     = $('#c-form');
const cText     = $('#c-text');
const cEmpty    = $('#c-empty');
const cListWrap = $('#c-list-wrap');

/* ---- Like (AJAX) ---- */
if (btnLike) {
  btnLike.addEventListener('click', async ()=>{
    const liked = btnLike.dataset.liked === '1';
    // optimistic UI
    btnLike.dataset.liked = liked ? '0' : '1';
    likeLabel.textContent = liked ? 'Like' : 'Unlike';
    likeCount.textContent = String(Math.max(0, (parseInt(likeCount.textContent,10)||0) + (liked ? -1 : 1)));

    try{
      const body = new URLSearchParams({ post_id: POST_ID, like: liked ? '0' : '1', token: TOKEN });
      const r = await fetch(LIKE_ENDPOINT, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
      const j = await r.json().catch(()=>({}));
      if (!r.ok && !(j && (j.ok===true || j.status==='ok'))) throw new Error('Like failed');
      if (typeof j.likes === 'number') likeCount.textContent = String(j.likes);
      if (typeof j.liked === 'boolean') {
        btnLike.dataset.liked = j.liked ? '1':'0';
        likeLabel.textContent = j.liked ? 'Unlike' : 'Like';
      }
    }catch(e){
      // rollback
      btnLike.dataset.liked = liked ? '1' : '0';
      likeLabel.textContent = liked ? 'Unlike' : 'Like';
      likeCount.textContent = String(Math.max(0, (parseInt(likeCount.textContent,10)||0) + (liked ? 1 : -1)));
      alert('Could not update like. Please try again.');
    }
  });
}

function escapeHtml(s){return String(s).replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;', "'":'&#39;' }[m]));}

function prependComment(item){
  if (!cListWrap || !item) return;
  const user = item.user || {};
  const name = user.display_name || user.name || 'User';
  const when = item.time || item.created_at || '';
  const body = item.body || item.text || '';
  const ava  = user.avatar_url || DEFAULT_AVA;

  const wrap = document.createElement('div');
  wrap.className = 'card c-item';
  wrap.style.cssText = 'border:0;border-top:1px solid #eee;border-radius:0;padding:10px 0';
  wrap.innerHTML = `
    <div style="display:flex;gap:8px;align-items:center">
      <img src="${ava}" class="ava" alt="" style="width:34px;height:34px;border-radius:999px;object-fit:cover;background:#eee">
      <div style="flex:1">
        <b>${escapeHtml(name)}</b>
        <div class="muted" style="font-size:12px">${escapeHtml(when)}</div>
      </div>
    </div>
    <div style="margin-top:6px">${escapeHtml(body)}</div>`;
  cListWrap.prepend(wrap);
}

async function reloadComments(){
  try{
    const r = await fetch(COMMENTS_LIST + '?post_id='+encodeURIComponent(POST_ID)+'&limit=200&token='+encodeURIComponent(TOKEN));
    const j = await r.json().catch(()=>({}));
    const items = Array.isArray(j.items) ? j.items : (Array.isArray(j.comments) ? j.comments : []);
    cListWrap.innerHTML = '';
    let count = 0;
    for (const c of (items||[])) {
      count++;
      prependComment({
        body: c.text ?? c.content ?? c.body ?? '',
        time: c.time_ago || c.created_at || '',
        user: c.user || {}
      });
    }
    const cnt = document.getElementById('comments-count');
    if (cnt) cnt.textContent = String(count);
  }catch(_){}
}

/* ---- Create comment (AJAX) ---- */
if (cForm) {
  cForm.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const txt = (cText.value || '').trim();
    if (!txt) return;

    try {
      const r = await fetch(COMMENT_CREATE, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          // your API reads bearer_token() from this header:
          'Authorization': 'Bearer ' + TOKEN
        },
        body: new URLSearchParams({ post_id: POST_ID, body: txt })
      });

      const j = await r.json().catch(()=>({}));

      if (!r.ok || !(j && j.ok === true)) {
        throw new Error('CREATE_FAILED');
      }

      // Success: clear field, remove "no comments", prepend item, update count
      cText.value = '';
      if (cEmpty) cEmpty.remove();
      prependComment(j.item || { body: txt, time: '', user: {} });

      const cnt = document.getElementById('comments-count');
      if (cnt) cnt.textContent = String(j.count ?? (parseInt(cnt.textContent,10)||0) + 1);
    } catch (e) {
      alert('Could not post comment.');
    }
  });
}
</script>

<?php render_footer(); ?>
