<?php require __DIR__.'/web_common.php'; require_web_auth();
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['user_id'])) {
  api_post_form('mentor_verify.php', ['user_id'=>$_POST['user_id']]);
}
header('Location: /backend/web/admin_approvals.php'); exit;
