<?php
/* =================================================================
 * File: backend/web/notifications.php
 * Uses: /api/notifications_list.php
 * ================================================================= */
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth();

$res = api_get('/notifications_list.php');
$items = (array)($res['items'] ?? []);

render_header('Notifications'); ?>
<div class="card" style="padding:12px"><b>Notifications</b></div>
<?php foreach ($items as $n): $a=(array)($n['actor'] ?? []); ?>
  <div class="card" style="padding:12px">
    <div><b><?=h($a['display_name'] ?? 'Someone')?></b> <span class="handle"><?=h($a['handle'] ?? '')?></span></div>
    <div class="handle" style="font-size:12px"><?=h($n['type'] ?? '')?> · <?=h($n['time_ago'] ?? '')?></div>
  </div>
<?php endforeach; ?>
<?php if (!$items): ?><div class="card" style="padding:12px">No notifications.</div><?php endif; ?>
<?php debug_block('NOTIFS RAW', $res); render_footer();
