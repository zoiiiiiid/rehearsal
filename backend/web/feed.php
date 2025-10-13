<?php
require __DIR__.'/web_common.php'; require_web_auth();
$page = max(1, (int)($_GET['page'] ?? 1));
$res = api_get("posts_list.php?page=$page&limit=20");
$items = is_array($res['items'] ?? null) ? $res['items'] : [];
html_header('Feed');
?>
<h1>Feed</h1>
<div class="list mt12">
<?php foreach ($items as $p): $p = (array)$p;
  $id = (string)($p['id'] ?? '');
  $url = (string)($p['media_url'] ?? '');
  $type = (string)($p['media_type'] ?? '');
  $cap = (string)($p['caption'] ?? '');
  $u   = (array)($p['user'] ?? []);
  $name = $u['display_name'] ?? ($u['username'] ?? 'User');
?>
  <div class="post-tile">
    <?php if ($url): ?>
      <a href="/backend/web/post.php?id=<?=urlencode($id)?>">
        <?php if ($type==='video'): ?>
          <div style="aspect-ratio:16/9;background:#eee;display:flex;align-items:center;justify-content:center">Video</div>
        <?php else: ?>
          <img src="<?=htmlspecialchars($url)?>" alt="media" />
        <?php endif; ?>
      </a>
    <?php endif; ?>
    <div class="card" style="border:0;border-top:1px solid #eee;border-radius:0">
      <div><strong><?=htmlspecialchars($name)?></strong></div>
      <?php if ($cap): ?><div class="mt8"><?=htmlspecialchars($cap)?></div><?php endif; ?>
      <div class="row mt8">
        <a class="btn outline" href="/backend/web/post.php?id=<?=urlencode($id)?>">Open</a>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<div class="row mt16">
  <?php if ($page>1): ?><a class="btn outline" href="?page=<?=$page-1?>">Prev</a><?php endif; ?>
  <a class="btn" href="?page=<?=$page+1?>">Next</a>
</div>
<?php html_footer(); ?>
