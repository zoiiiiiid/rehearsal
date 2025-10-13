<?php
require __DIR__.'/util.php'; cors();
require __DIR__.'/db.php';
$tok = bearer_token(); if(!$tok) json_out(['error'=>'NO_TOKEN'],401);
$in = body_json();
$name = trim($in['name'] ?? '');
$username = trim($in['username'] ?? '');
$bio = trim($in['bio'] ?? '');
if ($username!=='' && !preg_match('/^[a-zA-Z0-9_]{3,20}$/',$username)) json_out(['error'=>'BAD_USERNAME'],422);
$stmt=$pdo->prepare('SELECT u.id FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE t.token=? AND t.expires_at>NOW() LIMIT 1');
$stmt->execute([$tok]); $uid=$stmt->fetchColumn(); if(!$uid) json_out(['error'=>'TOKEN_INVALID'],401);
$pdo->beginTransaction();
try{
  if ($name!=='') {
    $pdo->prepare('UPDATE users SET name=? WHERE id=?')->execute([$name,$uid]);
  }
  if ($username!=='' || $bio!=='') {
    if($username!==''){
      $chk=$pdo->prepare('SELECT 1 FROM profiles WHERE username=? AND user_id<>? LIMIT 1');
      $chk->execute([$username,$uid]); if($chk->fetchColumn()) { $pdo->rollBack(); json_out(['error'=>'USERNAME_TAKEN'],409);}    
    }
    $pdo->prepare('INSERT IGNORE INTO profiles (user_id) VALUES (?)')->execute([$uid]);
    $pdo->prepare('UPDATE profiles SET username=COALESCE(NULLIF(?,""),username), bio=COALESCE(NULLIF(?,""),bio) WHERE user_id=?')
        ->execute([$username,$bio,$uid]);
  }
  $pdo->commit();
  json_out(['ok'=>true]);
}catch(Throwable $e){ $pdo->rollBack(); json_out(['error'=>'SERVER_ERROR'],500);}