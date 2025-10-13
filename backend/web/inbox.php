<?php
/* =================================================================
 * backend/web/inbox.php — DMs with compact bubbles + robust media
 * ================================================================= */
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth();

/* me (for bubble alignment) */
$meResp = api_get('profile_overview.php');
$ME     = (array)($meResp['user'] ?? []);
$ME_ID  = (string)($ME['id'] ?? '');

/* target */
$peer = isset($_GET['peer_id']) ? (string)$_GET['peer_id'] : (isset($_GET['start']) ? (string)$_GET['start'] : null);

/* convos */
$convos = api_get('conversations_list.php');

/* resolve conversation id */
$CONV_ID = '';
if ($peer && !empty($convos['items']) && is_array($convos['items'])) {
  foreach ($convos['items'] as $c0) {
    $c = (array)$c0;
    $other = (string)($c['other_user_id'] ?? $c['peer_id'] ?? $c['id'] ?? '');
    if ($other === $peer) {
      $CONV_ID = (string)(
        $c['conversation_id'] ?? $c['thread_id'] ?? $c['chat_id'] ??
        $c['id'] ?? $c['thread'] ?? ''
      );
      break;
    }
  }
}

/* initial thread */
$thread = ['messages'=>[]];
if ($peer || $CONV_ID) {
  $thread = $CONV_ID
    ? api_get('messages_list.php', ['conversation_id'=>$CONV_ID])
    : api_get('messages_list.php', ['peer_id'=>$peer]);
}

/* helpers */
function pick($a, array $keys, $def=''){
  foreach ($keys as $k) {
    if (strpos($k, '.') !== false) {
      $cur = $a;
      foreach (explode('.', $k) as $seg) {
        if (!is_array($cur) || !array_key_exists($seg, $cur)) { $cur = null; break; }
        $cur = $cur[$seg];
      }
      if (!empty($cur)) return (string)$cur;
    } elseif (!empty($a[$k])) {
      return (string)$a[$k];
    }
  }
  return (string)$def;
}
function list_messages_array($payload): array {
  if (isset($payload['messages']) && is_array($payload['messages'])) return $payload['messages'];
  if (isset($payload['items'])     && is_array($payload['items']))     return $payload['items'];
  return [];
}
function infer_type_from_url(string $url): string {
  $p = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
  if (preg_match('~\.(mp4|mov|webm)$~', $p)) return 'video';
  if (preg_match('~\.(jpe?g|png|webp|gif|bmp|avif)$~', $p)) return 'image';
  return '';
}

/* peer avatar */
$PEER_AVA = '';
if ($peer) {
  $peerOv  = api_get('profile_overview.php', ['user_id'=>$peer]);
  $PEER_AVA= (string)($peerOv['user']['avatar_url'] ?? '');
}

render_header('Inbox'); ?>
<style>
  .dm-wrap{display:grid;grid-template-columns:320px 1fr;gap:16px}
  .dm-list .row{display:flex;gap:10px;align-items:center;padding:10px 12px;border-bottom:1px solid var(--bd)}
  .dm-list .row.active{background:#f6f6f6}
  .dm-ava{width:36px;height:36px;border-radius:999px;object-fit:cover;background:#eee}
  .dm-name{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .dm-prev{color:var(--mut);font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

  .dm-thread{display:flex;flex-direction:column;height:65vh;min-height:360px;overflow:auto;padding:10px;scroll-behavior:smooth}
  .dm-row{display:flex;gap:8px;margin:6px 0;align-items:flex-end}

  /* compact, predictable bubble */
  .dm-bubble{
    display:inline-block;         /* shrink-wrap to content */
    box-sizing:border-box;
    max-width:420px;              /* hard cap so it won't stretch */
    padding:8px 12px;
    border-radius:18px;
    word-wrap:break-word;
    white-space:pre-wrap;
  }
  /* media inside bubble */
  .dm-bubble figure{margin:0}
  .dm-bubble img,.dm-bubble video{
    display:block;
    max-width:320px;
    max-height:320px;
    border-radius:12px;
  }
  /* media-only bubble – no pill background/padding */
  .dm-bubble.only-media{ padding:0; background:transparent }

  .me   {justify-content:flex-end}
  .me .dm-bubble{background:#111;color:#fff;border-top-right-radius:6px}
  .them {justify-content:flex-start}
  .them .dm-bubble{background:#f2f3f5;color:#111;border-top-left-radius:6px}
  .dm-time{font-size:11px;color:var(--mut);margin:0 6px}
  .dm-input{position:sticky;bottom:16px;background:var(--bg)}
  .dm-input .field{flex:1;padding:10px;border:1px solid #ddd;border-radius:10px}
  .dm-ava-sm{width:26px;height:26px;border-radius:999px;object-fit:cover;background:#eee}
  .uploader{display:flex;align-items:center;gap:8px}
  .file-pill{font-size:12px;border:1px solid var(--bd);border-radius:999px;padding:4px 8px}
</style>

<div class="dm-wrap">
  <!-- left -->
  <div>
    <div class="card" style="padding:10px"><b>Messages</b></div>
    <div class="card dm-list" style="padding:0">
      <?php foreach ((array)($convos['items'] ?? []) as $c0):
        $c    = (array)$c0;
        $cid  = pick($c, ['other_user_id','peer_id','id']);
        $nm   = pick($c, ['other_display_name','name','username'], 'User '.$cid);
        $prev = pick($c, ['last_content','preview','last_message','message']);
        $ava  = pick($c, [
          'other_avatar_url','avatar_url','avatar','profile_pic_url','profile_pic','photo_url',
          'other_user.avatar_url','user.avatar_url','other.avatar_url'
        ]);
        $active = ($peer === $cid) ? 'active' : '';
      ?>
        <a class="row <?=$active?>" href="?peer_id=<?=urlencode($cid)?>" style="text-decoration:none;color:inherit">
          <img class="dm-ava" alt="" src="<?= h($ava ?: default_avatar_data_uri()) ?>" data-uid="<?= h($cid) ?>" data-missing="<?= $ava ? '0' : '1' ?>" />
          <div style="min-width:0">
            <div class="dm-name"><?=h($nm)?></div>
            <?php if ($prev): ?><div class="dm-prev"><?=h($prev)?></div><?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
      <?php if (empty($convos['items'])): ?>
        <div style="padding:12px" class="dm-prev">No conversations yet.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- right -->
  <div>
    <div class="card" style="padding:10px"><b>Thread</b></div>
    <div id="thread" class="card dm-thread">
      <?php
        $msgs = list_messages_array($thread);
        if (!$msgs) echo '<div class="dm-prev">No messages.</div>';
        foreach ($msgs as $m0):
          $m     = (array)$m0;
          $sid   = pick($m, ['sender_id','from_id','user_id']);
          $who   = ($sid !== '' && $sid === $ME_ID) ? 'me' : 'them';
          $text  = pick($m, ['content','message','text','body'], '');

          // tolerant media keys (+ nested)
          $media = pick($m, [
            'media_url','url','file_url','attachment_url','attachment','media',
            'image_url','video_url',
            'file.path','file.url','image.path','image.url','video.path','video.url'
          ]);
          $type  = strtolower(pick($m, ['media_type','type','mime_type'], ''));
          if ($type === '' && $media !== '') $type = infer_type_from_url($media);

          $when  = pick($m, ['time_ago','created_at','created'], '');
          $u     = (array)($m['sender'] ?? []);
          $ava   = pick($u, ['avatar_url','avatar','profile_pic_url','profile_pic'], '');
          if ($who === 'them' && $ava === '') $ava = $PEER_AVA;

          $mediaOnly = $media !== '' && trim($text) === '';
      ?>
        <div class="dm-row <?=$who?>">
          <?php if ($who === 'them'): ?><img class="dm-ava-sm" src="<?= h($ava ?: default_avatar_data_uri()) ?>" alt=""><?php endif; ?>
          <div class="dm-bubble<?= $mediaOnly ? ' only-media' : '' ?>">
            <?php if ($media): ?>
              <?php if (($type ?: infer_type_from_url($media)) === 'video'): ?>
                <figure><video src="<?=h($media)?>" controls playsinline></video></figure>
              <?php else: ?>
                <figure><a href="<?=h($media)?>" target="_blank" rel="noopener"><img src="<?=h($media)?>" alt=""></a></figure>
              <?php endif; ?>
            <?php endif; ?>
            <?php if (trim($text)!==''): ?><?=nl2br(h($text))?><?php endif; ?>
          </div>
          <div class="dm-time"><?=h($when)?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($peer): ?>
    <form id="composer" class="card dm-input" style="padding:10px;margin-top:8px" enctype="multipart/form-data">
      <?php csrf_field(); ?>
      <div class="uploader" style="margin-bottom:8px">
        <input id="file" type="file" accept="image/jpeg,image/png,image/webp,video/mp4" style="display:none">
        <button id="btn-attach" class="btn out" type="button">Attach</button>
        <span id="file-pill" class="file-pill" style="display:none"></span>
        <button id="btn-clear" class="btn out" type="button" style="display:none">Remove</button>
      </div>
      <div style="display:flex;gap:8px">
        <textarea id="msg" rows="2" class="field" placeholder="Message (or caption)…"></textarea>
        <button class="btn" type="submit">Send</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>

<script>
(() => {
const API_BASE = <?= json_encode(API_BASE_URL) ?>;
const TOKEN    = <?= json_encode(web_token()) ?>;
const PEER     = <?= json_encode($peer) ?>;
const CONV_ID  = <?= json_encode($CONV_ID) ?>;
const ME_ID    = <?= json_encode($ME_ID) ?>;
const PEER_AVA = <?= json_encode($PEER_AVA) ?>;
const DEF_AVA  = (window.DEF_AVA || '');

const EP_LIST       = API_BASE + 'messages_list.php';
const EP_SEND       = API_BASE + 'messages_send.php';
const EP_SEND_MEDIA = API_BASE + 'messages_send_media.php';
const EP_PROF       = API_BASE + 'profile_overview.php';

const threadEl = document.getElementById('thread');
const formEl   = document.getElementById('composer');
const msgEl    = document.getElementById('msg');
const fileEl   = document.getElementById('file');
const pillEl   = document.getElementById('file-pill');
const attachBtn= document.getElementById('btn-attach');
const clearBtn = document.getElementById('btn-clear');

function esc(s){ return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;') }
function inferType(url){
  const p=(url||'').split('?')[0].toLowerCase();
  if(/\.(mp4|mov|webm)$/.test(p))return'video';
  if(/\.(jpg|jpeg|png|webp|gif|bmp|avif)$/.test(p))return'image';
  return'';
}
function mediaHTML(url, type){
  if (!url) return '';
  const t = (type || inferType(url)).toLowerCase();
  if (t === 'video') return `<figure><video src="${esc(url)}" controls playsinline></video></figure>`;
  return `<figure><a href="${esc(url)}" target="_blank" rel="noopener"><img src="${esc(url)}" alt=""></a></figure>`;
}
function bubbleHTML(m){
  const me  = (m.senderId && m.senderId === ME_ID);
  const ava = !me ? (m.avatar || PEER_AVA || DEF_AVA) : '';
  const hasMedia = !!(m.media && String(m.media).trim());
  const hasText  = !!(m.text && m.text.trim());
  const bubbleClass = 'dm-bubble' + (hasMedia && !hasText ? ' only-media' : '');
  const mediaBlock = hasMedia ? mediaHTML(m.media, m.type) : '';
  const textBlock  = hasText ? esc(m.text).replace(/\n/g,'<br>') : '';
  return `
    <div class="dm-row ${me ? 'me' : 'them'}">
      ${me ? '' : `<img class="dm-ava-sm" src="${esc(ava)}" alt="">`}
      <div class="${bubbleClass}">${mediaBlock}${textBlock}</div>
      <div class="dm-time">${esc(m.when||'')}</div>
    </div>`;
}

/* --- robust normaliser --- */
function getPath(obj, path){
  const segs = path.split('.'); let cur = obj;
  for (const s of segs){ if (cur == null || typeof cur !== 'object') return undefined; cur = cur[s]; }
  return cur;
}
function firstNonEmpty(obj, paths){
  for (const p of paths){
    const v = p.includes('.') ? getPath(obj, p) : obj[p];
    if (v !== undefined && v !== null && String(v).trim() !== '') return String(v);
  }
  return '';
}
function normaliseMessages(j){
  const arr = Array.isArray(j?.messages) ? j.messages : (Array.isArray(j?.items) ? j.items : []);
  return arr.map(m => {
    const senderId = firstNonEmpty(m, ['sender_id','from_id','user_id']);
    const text     = firstNonEmpty(m, ['content','message','text','body']);
    const when     = firstNonEmpty(m, ['time_ago','created_at','created']);
    const media    = firstNonEmpty(m, [
      'media_url','url','file_url','attachment_url','attachment','media',
      'image_url','video_url',
      'file.url','file.path','image.url','image.path','video.url','video.path'
    ]);
    const type     = (firstNonEmpty(m, ['media_type','type','mime_type']) || inferType(media)).toLowerCase();
    const sender   = m.sender || {};
    const avatar   = firstNonEmpty(sender, ['avatar_url','avatar','profile_pic_url','profile_pic']);
    return {senderId, text, when, type, media, avatar};
  });
}

function scrollToBottom(){ if (threadEl) threadEl.scrollTop = threadEl.scrollHeight + 1000; }
let lastThreadHTML = threadEl ? threadEl.innerHTML : '';

async function loadThread(){
  if (!threadEl || (!PEER && !CONV_ID)) return;
  const tokenQS = 'token=' + encodeURIComponent(TOKEN);
  let r = null;

  if (CONV_ID) for (const k of ['conversation_id','thread_id','chat_id','id','thread']) {
    try { r = await fetch(`${EP_LIST}?${tokenQS}&${k}=${encodeURIComponent(CONV_ID)}`); } catch(_) {}
    if (r && r.ok) break;
  }
  if ((!r || !r.ok) && PEER) for (const k of ['peer_id','other_user_id','to_user_id','recipient_id','peer','other','to','recipient']) {
    try { r = await fetch(`${EP_LIST}?${tokenQS}&${k}=${encodeURIComponent(PEER)}`); } catch(_) {}
    if (r && r.ok) break;
  }
  if (!r || !r.ok) return;

  const j = await r.json().catch(()=> ({}));
  const list = normaliseMessages(j);
  const html = list.length ? list.map(bubbleHTML).join('') : '<div class="dm-prev">No messages.</div>';
  threadEl.innerHTML = html;
  lastThreadHTML = html;
  scrollToBottom();
}

async function sendTextMessage(text){
  const body = new URLSearchParams({ receiver_id: PEER||'', conversation_id: CONV_ID||'', content: text, token: TOKEN });
  const r = await fetch(EP_SEND, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'}, body });
  if (!r.ok) return false;
  const ct = r.headers.get('content-type') || '';
  if (!ct.includes('json')) return true;
  try { const j = await r.json(); return !!(j && (j.ok === true || j.status === 'ok')); } catch { return true; }
}

async function sendMediaMessage(file, caption){
  const fd = new FormData();
  fd.append('file', file);
  if (caption) fd.append('caption', caption);
  if (CONV_ID) fd.append('conversation_id', CONV_ID); else if (PEER) fd.append('receiver_id', PEER);
  fd.append('token', TOKEN);
  const r = await fetch(EP_SEND_MEDIA, { method:'POST', body: fd });
  if (!r.ok) return false;
  const ct = r.headers.get('content-type') || '';
  if (!ct.includes('json')) return true;
  try { const j = await r.json(); return !!(j && (j.ok === true || j.status === 'ok')); } catch { return true; }
}

/* attach/remove */
if (attachBtn && fileEl) attachBtn.addEventListener('click', ()=> fileEl.click());
if (fileEl) fileEl.addEventListener('change', ()=>{
  const f = fileEl.files && fileEl.files[0];
  if (f) { pillEl.textContent = f.name + ' • ' + Math.ceil(f.size/1024) + ' KB'; pillEl.style.display='inline-block'; clearBtn.style.display='inline-flex'; }
  else   { pillEl.style.display='none'; clearBtn.style.display='none'; }
});
if (clearBtn) clearBtn.addEventListener('click', ()=>{ fileEl.value=''; pillEl.style.display='none'; clearBtn.style.display='none'; });

/* composer */
if (formEl && msgEl) {
  formEl.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const txt = (msgEl.value || '').trim();
    const f = fileEl.files && fileEl.files[0];
    if (!txt && !f) return;

    // optimistic preview
    if (f) {
      const url=URL.createObjectURL(f);
      const t  = /^video\//.test(f.type) ? 'video' : 'image';
      threadEl.insertAdjacentHTML('beforeend', bubbleHTML({senderId:ME_ID, text:txt, when:'now', media:url, type:t, avatar:''}));
    } else {
      threadEl.insertAdjacentHTML('beforeend', bubbleHTML({senderId:ME_ID, text:txt, when:'now', avatar:''}));
    }
    scrollToBottom();

    msgEl.value=''; pillEl.style.display='none'; clearBtn.style.display='none';

    const ok = f ? await sendMediaMessage(f, txt) : await sendTextMessage(txt);
    if (!ok) { alert('Failed to send. Please try again.'); if (lastThreadHTML) threadEl.innerHTML = lastThreadHTML; }
    else { await loadThread(); }

    try{ if(f) URL.revokeObjectURL(f); }catch{}
    fileEl.value='';
  });
}

/* avatars */
async function hydrateMissingAvatars(){
  const rows = document.querySelectorAll('.dm-list img.dm-ava[data-missing="1"][data-uid]');
  if (!rows.length) return;
  const tokenQS = 'token=' + encodeURIComponent(TOKEN);
  for (const img of rows) {
    const uid = img.getAttribute('data-uid'); if (!uid) continue;
    try {
      const r = await fetch(`${EP_PROF}?${tokenQS}&user_id=${encodeURIComponent(uid)}`);
      if (!r.ok) continue;
      const j = await r.json();
      const url = j?.user?.avatar_url || j?.user?.avatar || '';
      img.src = url || (window.DEF_AVA || ''); if (url) img.setAttribute('data-missing','0');
    } catch { img.src = (window.DEF_AVA || ''); }
  }
}

/* init */
hydrateMissingAvatars();
setInterval(loadThread, 6000);
})();
</script>

<?php render_footer();
