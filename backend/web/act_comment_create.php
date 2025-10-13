<?php
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth(); csrf_check();

$postId = (string)($_POST['post_id'] ?? '');
$text   = trim((string)($_POST['text'] ?? ''));
if ($postId === '' || $text === '') redirect(WEB_BASE.'/post.php?id='.rawurlencode($postId).'#comments');

api_post_form('comment_create.php', ['post_id'=>$postId, 'text'=>$text]);
redirect(WEB_BASE.'/post.php?id='.rawurlencode($postId).'#comments');
