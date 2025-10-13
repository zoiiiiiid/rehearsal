<?php
// backend/api/comment_create.php
require __DIR__.'/util.php'; cors();
require __DIR__.'/db.php';

$t = bearer_token(); if(!$t) json_out(['error'=>'NO_TOKEN'],401);
$me = user_by_token($pdo,$t); if(!$me) json_out(['error'=>'TOKEN_INVALID'],401);

$in = input_any();
$post_id = intval($in['post_id'] ?? 0);
$body    = trim($in['body'] ?? '');
if ($post_id<=0 || $body==='') json_out(['error'=>'MISSING_FIELDS'],400);

// find owner
$st0 = $pdo->prepare('SELECT user_id FROM posts WHERE id=? LIMIT 1');
$st0->execute([$post_id]);
$owner_id = $st0->fetchColumn();
if (!$owner_id) json_out(['error'=>'POST_NOT_FOUND'],404);

// insert comment
$st = $pdo->prepare('INSERT INTO comments(post_id,user_id,body,created_at) VALUES(?,?,?,NOW())');
$ok = $st->execute([$post_id,$me['id'],$body]);
if(!$ok) json_out(['error'=>'DB_ERROR'],500);
$comment_id = (int)$pdo->lastInsertId();

// notify owner (skip self)
if ($owner_id !== $me['id']) {
  $pdo->prepare("INSERT INTO notifications (user_id, actor_id, type, post_id, comment_id)
                 VALUES (?,?,?,?,?)")
      ->execute([$owner_id, $me['id'], 'comment', $post_id, $comment_id]);
}

// build item for client (matches your sheet adapter)
$u = $pdo->prepare('SELECT u.name, u.email, p.username, p.avatar_url
                    FROM users u LEFT JOIN profiles p ON p.user_id=u.id
                    WHERE u.id=? LIMIT 1');
$u->execute([$me['id']]);
$usr = $u->fetch(PDO::FETCH_ASSOC) ?: ['name'=>'', 'email'=>'', 'username'=>null, 'avatar_url'=>null];
$display = $usr['name'] ?: explode('@', (string)$usr['email'])[0];

$item = [
  'id'   => $comment_id,
  'body' => $body,
  'time' => date('Y-m-d H:i:s'),
  'user' => [
    'id'           => $me['id'],
    'display_name' => $display,
    'username'     => $usr['username'],
    'handle'       => $usr['username'] ? '@'.strtolower($usr['username']) : null,
    'avatar_url'   => $usr['avatar_url'],
  ],
];

// current count
$cnt=$pdo->prepare('SELECT COUNT(*) FROM comments WHERE post_id=?');
$cnt->execute([$post_id]);

json_out(['ok'=>true,'item'=>$item,'count'=>(int)$cnt->fetchColumn()]);
