<?php
require __DIR__.'/util.php'; cors();
require __DIR__.'/db.php';
$tok = bearer_token(); if(!$tok) json_out(['error'=>'NO_TOKEN'],401);
$stmt=$pdo->prepare('SELECT u.id FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE t.token=? AND t.expires_at>NOW() LIMIT 1');
$stmt->execute([$tok]); $uid=$stmt->fetchColumn(); if(!$uid) json_out(['error'=>'TOKEN_INVALID'],401);
$pdo->prepare('INSERT IGNORE INTO profiles (user_id, username, bio) VALUES (?,?,?)')
    ->execute([$uid, NULL, NULL]);
$q = $pdo->prepare('SELECT u.id,u.email,u.name,pr.username,pr.bio,pr.avatar_url FROM users u JOIN profiles pr ON pr.user_id=u.id WHERE u.id=? LIMIT 1');
$q->execute([$uid]);
json_out(['profile'=>$q->fetch()]);