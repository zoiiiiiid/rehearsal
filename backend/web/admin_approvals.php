<?php
require __DIR__.'/web_common.php'; require_web_auth();
$page=max(1,(int)($_GET['page']??1));
$q=$_GET['q']??'';
$res = api_get('admin_pending_list.php?page='.$page.'&limit=50'.($q!==''?'&q='.rawurlencode($q):''));
$items = is_array($res)?$res:[];
html_header('Admin approvals');
?>
<h1>Mentor approvals</h1>
<form class="row" method="get"><input name="q" value="<?=htmlspecialchars($q)?>" placeholder="Search"/><button class="btn">Search</button></form>
<div class="list mt12">
<?php foreach ($items as $u): $u=(array)$u; $id=(string)($u['id']??''); ?>
  <div class="card">
    <div><strong><?=htmlspecialchars($u['display_name'] ?? $u['username'] ?? 'User')?></strong></div>
    <div class="row mt8">
      <form method="post" action="admin_approve_do.php" class="row">
        <input type="hidden" name="user_id" value="<?=htmlspecialchars($id)?>" />
        <button class="btn" type="submit">Verify</button>
      </form>
      <form method="post" action="admin_reject_do.php" class="row">
        <input type="hidden" name="user_id" value="<?=htmlspecialchars($id)?>" />
        <button class="btn outline" type="submit">Reject</button>
      </form>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php html_footer(); ?>
