<?php
require __DIR__.'/util.php'; cors();
require __DIR__.'/db.php';

$t = bearer_token(); if(!$t) json_out(['error'=>'NO_TOKEN'],401);
$me = user_by_token($pdo,$t); if(!$me) json_out(['error'=>'TOKEN_INVALID'],401);

$st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL");
$st->execute([$me['id']]);
$cnt = (int)$st->fetchColumn();

json_out(['ok'=>true,'unread'=>$cnt]);
