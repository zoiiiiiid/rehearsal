<?php
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth(); csrf_check();

$postId = (string)($_POST['post_id'] ?? '');
if ($postId === '') redirect(WEB_BASE.'/index.php');

api_post_form('post_delete.php', ['post_id'=>$postId]);
redirect(WEB_BASE.'/index.php');
