<?php
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth();
require_admin_or_403();

// pending mentor applications (ADAPT endpoint name/shape)
$app = api_get('admin_mentor_applications.php', ['status'=>'pending', 'limit'=>50]);
$pending = (array)($app['items'] ?? []);

// reported or latest posts for moderation (optional)
$pp = api_get('admin_posts_queue.php', ['limit'=>50]); // ADAPT
$posts = (array)($pp['items'] ?? []);

render_header('Admin Panel');
?>
<h3 style="margin:0 0 8px 0">Mentor applications</h3>
<?php if (!$pending): ?>
  <div class="muted">None.</div>
<?php else: ?>
  <?php foreach ($pending as $a): $a=(array)$a; $u=(array)($a['user'] ?? []); ?>
    <div class="card" style="display:flex;gap:10px;align-items:center;padding:10px;margin-bottom:8px">
      <img class="ava" src="<?=h((string)($u['avatar_url'] ?? ''))?>">
      <div style="flex:1">
        <div><b><?=h((string)($u['display_name'] ?? $u['name'] ?? 'User'))?></b> <span class="handle"><?=h('@'.(string)($u['username'] ?? ''))?></span></div>
        <div class="muted"><?=h((string)($a['reason'] ?? ''))?></div>
      </div>
      <form method="post" action="<?=WEB_BASE?>/admin_approve_do.php" style="margin:0">
        <?php csrf_field(); ?>
        <input type="hidden" name="application_id" value="<?=h((string)($a['id'] ?? ''))?>">
        <button class="btn">Approve</button>
        <button class="btn out" name="deny" value="1">Deny</button>
      </form>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="hr" style="margin:12px 0"></div>

<h3 style="margin:0 0 8px 0">Post moderation</h3>
<?php if (!$posts): ?>
  <div class="muted">No posts in queue.</div>
<?php else: ?>
  <?php foreach ($posts as $p): $p=(array)$p; ?>
    <div class="card" style="margin-bottom:8px">
      <?php if (!empty($p['media_url'])): ?>
        <img src="<?=h((string)$p['media_url'])?>" style="width:100%;display:block">
      <?php endif; ?>
      <div class="post-body">
        <?=nl2br(h((string)($p['caption'] ?? '')))?>
        <div class="hr"></div>
        <form method="post" action="<?=WEB_BASE?>/act_post_delete.php" onsubmit="return confirm('Delete this post?')">
          <?php csrf_field(); ?>
          <input type="hidden" name="post_id" value="<?=h((string)($p['id'] ?? ''))?>">
          <button class="btn out" style="color:#b00;border-color:#f3c">Delete</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php render_footer(); ?>
