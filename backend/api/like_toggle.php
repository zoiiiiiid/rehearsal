<?php
// backend/api/like_toggle.php
require __DIR__.'/util.php'; cors();
require __DIR__.'/db.php';

$tok = bearer_token(); if(!$tok) json_out(['error'=>'NO_TOKEN'],401);
$in = body_json(); $post_id = (int)($in['post_id'] ?? 0);
if ($post_id<=0) json_out(['error'=>'BAD_POST'],422);

// resolve user id
$s=$pdo->prepare('SELECT u.id FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE t.token=? AND t.expires_at>NOW() LIMIT 1');
$s->execute([$tok]); $uid=$s->fetchColumn(); if(!$uid) json_out(['error'=>'TOKEN_INVALID'],401);

// find post owner
$o=$pdo->prepare('SELECT user_id FROM posts WHERE id=? LIMIT 1');
$o->execute([$post_id]); $owner=$o->fetchColumn();
if(!$owner) json_out(['error'=>'POST_NOT_FOUND'],404);

$pdo->beginTransaction();
try{
  $chk=$pdo->prepare('SELECT id FROM likes WHERE post_id=? AND user_id=? LIMIT 1');
  $chk->execute([$post_id,$uid]);
  if ($row=$chk->fetch(PDO::FETCH_ASSOC)) {
    $pdo->prepare('DELETE FROM likes WHERE id=?')->execute([$row['id']]);
    $liked=false;
  } else {
    $pdo->prepare('INSERT INTO likes (post_id,user_id) VALUES (?,?)')->execute([$post_id,$uid]);
    $liked=true;

    if ($owner !== $uid) {
      $pdo->prepare("INSERT INTO notifications (user_id, actor_id, type, post_id)
                     VALUES (?,?, 'like', ?)")
          ->execute([$owner, $uid, $post_id]);
    }
  }
  $cnt=$pdo->prepare('SELECT COUNT(*) FROM likes WHERE post_id=?'); $cnt->execute([$post_id]); $likes=(int)$cnt->fetchColumn();
  $pdo->commit();
  json_out(['ok'=>true,'liked'=>$liked,'likes'=>$likes]);
}catch(Throwable $e){
  $pdo->rollBack();
  json_out(['error'=>'SERVER_ERROR','detail'=>$e->getMessage()],500);
}
