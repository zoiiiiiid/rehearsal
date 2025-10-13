<?php
/* =================================================================
 * backend/web/profile.php — IG-like profile using your APIs
 * ================================================================= */
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth();

/* Which profile? If none passed, profile_overview will use the viewer from token */
$userId = isset($_GET['user_id']) ? (string)$_GET['user_id'] : null;

/* Load overview (has user, counts, posts, skills) */
$ov = api_get('/profile_overview.php', $userId ? ['user_id'=>$userId] : []);
$user = (array)($ov['user'] ?? []);
if (!$user) { render_header('Profile'); echo '<div class="card" style="padding:16px">Profile not found.</div>'; render_footer(); exit; }

$uid   = (string)($user['id'] ?? '');
$isMe  = !empty($user['is_me']);
$name  = (string)($user['display_name'] ?? $user['name'] ?? 'User');
$handle= (string)($user['username'] ?? '');
$bio   = trim((string)($user['bio'] ?? ''));
$role  = strtolower((string)($user['role'] ?? ''));
$status= strtolower((string)($user['status'] ?? ''));

/* Counts – prefer overview, fallback to follow_counts.php */
$counts = (array)($ov['counts'] ?? []);
if (!isset($counts['posts'], $counts['followers'], $counts['following'])) {
  $fc = api_get('/follow_counts.php', ['user_id'=>$uid]);
  $counts = array_merge(['posts'=>0,'followers'=>0,'following'=>0], (array)($fc['counts'] ?? []), $counts);
}
$postsCount     = (int)($counts['posts'] ?? 0);
$followersCount = (int)($counts['followers'] ?? 0);
$followingCount = (int)($counts['following'] ?? 0);

/* Posts grid – prefer overview, fallback to profile_posts.php */
$posts = is_array($ov['posts'] ?? null) ? $ov['posts'] : [];
if (!$posts) {
  $pr = api_get('/profile_posts.php', ['user_id'=>$uid]);
  $posts = is_array($pr['posts'] ?? null) ? $pr['posts'] : [];
}

/* Skills (objects from overview: [{key,label}]) */
$skills = [];
foreach ((array)($user['skills'] ?? []) as $s) {
  $skills[] = [
    'key'   => strtolower((string)($s['key'] ?? '')),
    'label' => (string)($s['label'] ?? ''),
  ];
}

render_header('Profile');
?>
<style>
  .prof-wrap{display:flex;gap:24px;align-items:center}
  .prof-ava{width:96px;height:96px;border-radius:999px;object-fit:cover;border:1px solid #ddd;background:#eee}
  .prof-main{flex:1;min-width:0}
  .prof-top{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
  .prof-username{font-size:22px;font-weight:800}
  .prof-btn{display:inline-flex;align-items:center;justify-content:center;padding:8px 12px;border:1px solid var(--bd);border-radius:10px;background:#fff}
  .prof-stats{display:flex;gap:18px;margin-top:8px}
  .prof-stats .st b{font-weight:800;margin-right:6px}
  .prof-handle{color:var(--mut);font-size:12px}
  .prof-bio{margin-top:8px;white-space:pre-wrap}

  .skills-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
  .chip{padding:6px 12px;border:1px solid var(--bd);border-radius:999px}
  .chip.on{background:#111;color:#fff;border-color:#111}

  .pgrid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:2px;margin-top:12px}
  .pgrid a{display:block;position:relative;background:#f2f3f5}
  .pgrid .tile{position:relative;width:100%;aspect-ratio:1/1;overflow:hidden}
  .pgrid img,.pgrid video{width:100%;height:100%;object-fit:cover;display:block}
  .pgrid .tag{position:absolute;left:6px;bottom:6px;background:rgba(0,0,0,.65);color:#fff;font-size:11px;padding:2px 6px;border-radius:8px}
</style>

<div class="card" style="padding:16px">
  <div class="prof-wrap">
    <?php avatar_img($user, ['class'=>'prof-ava','alt'=>'']); ?>
    <div class="prof-main">
      <div class="prof-top">
        <div class="prof-username"><?=h($name)?></div>
        <?php if ($isMe): ?>
          <a class="prof-btn" href="<?=WEB_BASE?>/profile_edit.php">Edit profile</a>
        <?php else: ?>
          <a class="prof-btn" href="<?=WEB_BASE?>/inbox.php?peer_id=<?=urlencode($uid)?>">Message</a>
        <?php endif; ?>
      </div>

      <div class="prof-stats">
        <div class="st"><b><?= $postsCount ?></b> posts</div>
        <div class="st"><b><?= $followersCount ?></b> followers</div>
        <div class="st"><b><?= $followingCount ?></b> following</div>
      </div>

      <div style="display:flex;gap:8px;align-items:center;margin-top:6px">
        <div class="prof-handle">@<?=h($handle)?></div>
        <?php if ($role==='mentor' && $status==='verified'): ?>
          <span class="chip" style="background:#DFF6DD;border-color:#DFF6DD">Verified mentor</span>
        <?php elseif ($role==='mentor'): ?>
          <span class="chip">Mentor</span>
        <?php endif; ?>
      </div>

      <?php if ($bio): ?><div class="prof-bio"><?=nl2br(h($bio))?></div><?php endif; ?>

      <!-- Skillsets (display like mobile) -->
      <div class="skills-row">
        <?php foreach ($skills as $s): if (!$s['label']) continue; ?>
          <span class="chip on"><?=h($s['label'])?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- Grid -->
<div class="pgrid">
  <?php foreach ($posts as $p0):
    $p     = (array)$p0;
    $pid   = (string)($p['id'] ?? $p['post_id'] ?? '');
    if ($pid==='') continue;

    $url   = (string)($p['media_url'] ?? '');
    $ptype = strtolower((string)($p['media_type'] ?? ''));
    if ($ptype === '' && $url) {
      $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
      $ptype = in_array($ext, ['mp4','mov','webm'], true) ? 'video' : 'image';
    }
    $likes = (int)($p['likes'] ?? $p['like_count'] ?? $p['likes_count'] ?? 0);

    // Pass media/type/likes as FALLBACK hints to post.php
    $href = WEB_BASE.'/post.php'
          . '?id='.rawurlencode($pid)
          . ($url ? '&media='.rawurlencode($url) : '')
          . ($ptype ? '&type='.rawurlencode($ptype) : '')
          . ($likes ? '&likes='.$likes : '');
  ?>
    <a href="<?=$href?>">
      <div class="tile">
        <?php if ($url): ?>
          <?php if ($ptype === 'video'): ?>
            <video src="<?=h($url)?>" muted playsinline></video>
            <span class="tag">VIDEO</span>
          <?php else: ?>
            <img src="<?=h($url)?>" alt="">
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </a>
  <?php endforeach; ?>
  <?php if (!$posts): ?>
    <div class="card" style="padding:16px">No posts yet.</div>
  <?php endif; ?>
</div>

<?php render_footer();
