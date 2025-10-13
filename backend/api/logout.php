<?php
require __DIR__.'/util.php'; cors();
require __DIR__.'/db.php';
$tok = bearer_token();
if (!$tok) json_out(['error'=>'NO_TOKEN'],401);
$pdo->prepare('DELETE FROM auth_tokens WHERE token=?')->execute([$tok]);
json_out(['ok'=>true]);