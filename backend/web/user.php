<?php
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth();

$uid = (string)($_GET['id'] ?? '');
if ($uid === '') redirect(WEB_BASE.'/index.php');

// ADAPT to your endpoint name/shape
$r = api_get('public_profile.php', ['id'=>$uid]);
$u = (array)($r['user'] ?? []);

$posts = (array)($r['posts'] ?? []); // or call a separate posts list for the user

render_header('Profile');
?>
<div class="card" style="max-width:940px;margin:0 auto">
  <div style="display:flex;gap:16px;padding:16px;border-bottom:1px solid #eee">
    <img class="ava" src="<?=h((string)($u['avatar_url'] ?? ''))?>" style="width:70px;height:70px">
    <div style="flex:1">
      <div style="display:flex;gap:10px;align-items:center">
        <h3 style="margin:0"><?=h((string)($u['display_name'] ?? $u['name'] ?? 'User'))?></h3>
        <span class="handle"><?=h('@'.(string)($u['username'] ?? ''))?></span>
      </div>
      <?php if (!empty($u['bio'])): ?>
        <div style="margin-top:4px"><?=nl2br(h((string)$u['bio']))?></div>
      <?php endif; ?>
      <div class="muted" style="margin-top:6px">
        Posts: <?= (int)($u['posts_count'] ?? 0) ?> · Followers: <?= (int)($u['followers'] ?? 0) ?> · Following: <?= (int)($u['following'] ?? 0) ?>
      </div>
      <?php if (empty($u['is_mentor'])): ?>
        <form method="post" action="<?=WEB_BASE?>/act_apply_mentor.php" style="margin-top:8px">
          <?php csrf_field(); ?>
          <input type="hidden" name="user_id" value="<?=h($uid)?>">
          <button class="btn">Apply for mentorship</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$posts): ?>
    <div class="muted" style="padding:12px">No posts yet.</div>
  <?php else: ?>
    <?php foreach ($posts as $p): $p=(array)$p; ?>
      <a href="<?=WEB_BASE?>/post.php?id=<?=urlencode((string)($p['id'] ?? ''))?>" class="card" style="display:block;margin:10px 16px;padding:0;border-color:#eee;text-decoration:none">
        <?php if (!empty($p['media_url'])): ?>
          <img src="<?=h((string)$p['media_url'])?>" style="width:100%;display:block">
        <?php endif; ?>
        <div class="post-body"><?=nl2br(h((string)($p['caption'] ?? ''))) ?></div>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
