<?php
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth();
csrf_check();

$uid = (string)($_POST['user_id'] ?? '');
if ($uid !== '') {
  api_post_json('mentor_apply.php', ['user_id'=>$uid]); // ADAPT
}
redirect(WEB_BASE.'/user.php?id='.rawurlencode($uid));
