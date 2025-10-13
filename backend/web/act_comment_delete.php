<?php
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth(); csrf_check();

$postId    = (string)($_POST['post_id'] ?? '');
$commentId = (string)($_POST['comment_id'] ?? '');
if ($postId === '' || $commentId === '') redirect(WEB_BASE.'/post.php?id='.rawurlencode($postId).'#comments');

api_post_form('comment_delete.php', ['comment_id'=>$commentId]);
redirect(WEB_BASE.'/post.php?id='.rawurlencode($postId).'#comments');
