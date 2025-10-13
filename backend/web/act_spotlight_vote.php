<?php
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth();
csrf_check();

$uid = (string)($_POST['target_user_id'] ?? '');
if ($uid !== '') {
  api_post_json('spotlight_vote.php', ['user_id'=>$uid]); // ADAPT
}
redirect(WEB_BASE.'/spotlight.php');
