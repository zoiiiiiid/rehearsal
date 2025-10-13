<?php
// backend/web/public_profile.php
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth();

$uid = (string)($_GET['user_id'] ?? $_GET['id'] ?? '');
if ($uid === '') redirect(WEB_BASE.'/index.php');

// Load profile & spotlight status
$profile = api_get('profile_overview.php', ['user_id'=>$uid]);
$user    = (array)($profile['user'] ?? []);
$counts  = (array)($profile['counts'] ?? []);
if (!$user) { render_header('Profile'); echo '<div class="muted">Profile not found.</div>'; render_footer(); exit; }

$st     = api_get('spotlight_status.php', ['user_id'=>$uid, 'days'=>30]);
$score  = (int)($st['score'] ?? 0);
$voted  = ($st['voted'] ?? false) ? true : false;

$isMe    = !empty($user['is_me']);
$name    = (string)($user['display_name'] ?? $user['name'] ?? 'User');
$handle  = (string)($user['username'] ?? '');
$bio     = trim((string)($user['bio'] ?? ''));
$avatar  = (string)($user['avatar_url'] ?? '');
$role    = strtolower((string)($user['role'] ?? ''));
$status  = strtolower((string)($user['status'] ?? ''));

$posts      = (int)($counts['posts'] ?? 0);
$followers  = (int)($counts['followers'] ?? 0);
$following  = (int)($counts['following'] ?? 0);

// Try to detect initial follow state directly from the overview payload
$isFollowing = !empty($profile['is_following'])
            || !empty($user['is_following'])
            || (isset($profile['relation']) && stripos((string)$profile['relation'], 'follow') !== false);

render_header('Profile');
?>
<div class="card" style="max-width:920px;margin:0 0 16px 0;padding:16px">
  <div style="display:flex;gap:14px;align-items:flex-start">
    <?php avatar_img($avatar, ['class'=>'ava', 'alt'=>'', 'style'=>'width:68px;height:68px;border:1px solid #111']); ?>
    <div style="flex:1;min-width:0">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <div style="font-size:22px;font-weight:800"><?=h($name)?></div>
        <?php if ($handle): ?><div class="handle">@<?=h($handle)?></div><?php endif; ?>
        <?php if ($role==='mentor' && $status==='verified'): ?>
          <span class="chip" style="background:#DFF6DD;border-color:#DFF6DD">Verified mentor</span>
        <?php elseif ($role==='mentor'): ?>
          <span class="chip">Mentor</span>
        <?php endif; ?>
      </div>
      <?php if ($bio): ?><div style="margin-top:8px"><?=nl2br(h($bio))?></div><?php endif; ?>
    </div>

    <div style="display:flex;flex-direction:column;gap:8px">
      <?php if (!$isMe): ?>
        <!-- Follow/Unfollow button mounts here -->
        <button id="followBtn" class="btn<?= $isFollowing ? ' out' : '' ?>" type="button" data-following="<?= $isFollowing ? '1':'0' ?>" style="min-width:110px">
          <?= $isFollowing ? 'Following' : 'Follow' ?>
        </button>
      <?php endif; ?>

      <!-- Spotlight vote -->
      <button id="spotBtn" class="btn out" type="button" <?= $isMe ? 'disabled title="You can’t vote yourself"' : ''?>>
        <span style="display:inline-flex;align-items:center;gap:8px">
          <svg id="spotIcon" viewBox="0 0 24 24" width="18" height="18" fill="<?= $voted ? '#f43f5e' : 'currentColor' ?>" aria-hidden="true">
            <path d="M12 2C9 5 9 7.5 9 8.5c0 1.7 1.1 3.1 2.6 3.7-.1-.6-.1-1.2.2-1.9.7-1.6 2.5-2.8 4.2-2.8 0 4.2-1.3 6-3 7.5 2.6-.1 5-2.2 5-5.5 0-3.1-2.1-5.8-5-7.5zM8.5 11.5C6 12.7 5 14.8 5 16.6 5 19.6 7.4 22 10.5 22S16 19.6 16 16.6c0-.8-.1-1.6-.4-2.2-1.2 2-3.2 3.6-5.8 3.6-1.2 0-2.2-.3-3.3-.9.7-.8 1.7-1.8 2-3.6z"/>
          </svg>
          <b id="spotScore"><?=$score?></b>
        </span>
      </button>

      <?php if (!$isMe): ?>
        <a class="btn out" href="<?=WEB_BASE?>/inbox.php?start=<?=urlencode($uid)?>"><?=icon('chat')?> Message</a>
      <?php endif; ?>
    </div>
  </div>

  <div style="display:flex;gap:14px;margin-top:12px">
    <div style="flex:1"><div style="font-weight:800;font-size:18px"><?=$posts?></div><div class="muted">Posts</div></div>
    <div style="flex:1"><div id="followers-count" style="font-weight:800;font-size:18px"><?=$followers?></div><div class="muted">Followers</div></div>
    <div style="flex:1"><div style="font-weight:800;font-size:18px"><?=$following?></div><div class="muted">Following</div></div>
  </div>
</div>

<?php
$grid = (array)($profile['posts'] ?? []);
if (!$grid): ?>
  <div class="muted">No posts yet.</div>
<?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;max-width:920px">
    <?php foreach ($grid as $p):
      $p    = (array)$p;
      $pid  = (string)($p['id'] ?? $p['post_id'] ?? '');
      if ($pid === '') continue;

      $url  = (string)($p['media_url'] ?? '');
      $type = strtolower((string)($p['media_type'] ?? ''));
      if ($type === '' && $url) {
        $ext  = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        $type = in_array($ext, ['mp4','mov','webm'], true) ? 'video' : 'image'; // why: ensure post.php can render without extra API reads
      }
      $likes = (int)($p['likes'] ?? $p['like_count'] ?? $p['likes_count'] ?? 0);

      $href = WEB_BASE.'/post.php'
            . '?id='.rawurlencode($pid)
            . ($url   ? '&media='.rawurlencode($url)  : '')
            . ($type  ? '&type='.rawurlencode($type) : '')
            . ($likes ? '&likes='.$likes : '');
    ?>
      <a class="card" href="<?= $href ?>" style="overflow:hidden">
        <div style="aspect-ratio:1/1;background:#f1f1f1;<?= $url ? "background-image:url('".h($url)."');background-size:cover;background-position:center" : '' ?>"></div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script>
// ------- Spotlight (unchanged) -------
(function(){
  const btn   = document.getElementById('spotBtn');
  if (!btn) return;
  const icon  = document.getElementById('spotIcon');
  const score = document.getElementById('spotScore');
  const userId = <?= json_encode($uid) ?>;

  let voted = <?= $voted ? 'true' : 'false' ?>;
  let sc = parseInt(score.textContent || '0', 10);

  btn.addEventListener('click', async () => {
    if (btn.disabled) return;
    voted = !voted;
    sc = Math.max(0, sc + (voted ? 1 : -1));
    icon.setAttribute('fill', voted ? '#f43f5e' : 'currentColor');
    score.textContent = sc.toString();

    try {
      const resp = await fetch(<?= json_encode(API_BASE_URL.'spotlight_vote.php') ?>, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({target_user_id: userId, days: '30', token: <?= json_encode(web_token()) ?>})
      });
      const j = await resp.json();
      if (!j || j.ok !== true) throw new Error(j && j.error || 'Vote failed');
      voted = !!j.voted;
      sc = (j.score|0);
      icon.setAttribute('fill', voted ? '#f43f5e' : 'currentColor');
      score.textContent = sc.toString();
    } catch (e) {
      voted = !voted;
      sc = Math.max(0, sc + (voted ? 1 : -1));
      icon.setAttribute('fill', voted ? '#f43f5e' : 'currentColor');
      score.textContent = sc.toString();
      alert('Vote failed. Please try again.');
    }
  });
})();

// ------- Follow / Unfollow (uses follow_toggle.php only) -------
(function(){
  const btn = document.getElementById('followBtn');
  if (!btn) return;

  const API   = <?= json_encode(API_BASE_URL.'follow_toggle.php') ?>;
  const TOKEN = <?= json_encode(web_token()) ?>;
  const TARGET= <?= json_encode($uid) ?>;

  const followersEl = document.getElementById('followers-count');

  function setBtn(following, busy=false){
    btn.dataset.following = following ? '1' : '0';
    btn.className = following ? 'btn out' : 'btn';
    btn.textContent = busy ? (following ? 'Unfollowing…' : 'Following…')
                           : (following ? 'Following' : 'Follow');
    btn.disabled = !!busy;
  }

  function setFollowers(n){
    if (!followersEl) return;
    const v = parseInt((followersEl.textContent||'').replace(/[^\d-]/g,''),10);
    followersEl.textContent = isFinite(n) ? String(n)
                           : isFinite(v) ? String(Math.max(0, v)) : '0';
  }

  btn.addEventListener('click', async ()=>{
    const following = btn.dataset.following === '1';
    // optimistic bump
    const v = parseInt((followersEl?.textContent||'0').replace(/[^\d-]/g,''),10) || 0;
    setBtn(following, true);
    if (followersEl) followersEl.textContent = String(Math.max(0, v + (following ? -1 : +1)));

    try{
      const url = API + (API.includes('?') ? '&' : '?') + 'token=' + encodeURIComponent(TOKEN);
      const r = await fetch(url, {
        method:'POST',
        headers:{
          'Content-Type':'application/x-www-form-urlencoded',
          'Authorization':'Bearer '+TOKEN
        },
        body: new URLSearchParams({
          target_id: TARGET
        }).toString()
      });
      const j = await r.json().catch(()=>({}));
      if (!r.ok || !j || j.ok !== true) throw new Error(j && j.error || 'NOT_AVAILABLE');

      // server says whether we're following after toggle
      const nowFollowing = !!j.following;
      setBtn(nowFollowing, false);

      // prefer authoritative counts from API when available
      if (j.target_counts && typeof j.target_counts.followers !== 'undefined') {
        setFollowers(parseInt(j.target_counts.followers,10));
      }
    }catch(e){
      // rollback optimistic
      const wasFollowing = btn.dataset.following === '1';
      setBtn(wasFollowing, false);
      // restore follower count the other way
      const v2 = parseInt((followersEl?.textContent||'0').replace(/[^\d-]/g,''),10) || 0;
      if (followersEl) followersEl.textContent = String(Math.max(0, v2 + (wasFollowing ? 0 : -2) + (wasFollowing ? 1 : 1)));
      alert(e.message || 'Follow action failed.');
    }
  });
})();
</script>

<?php render_footer(); ?>
