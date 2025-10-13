<?php
// backend/api/follow_counts.php
// Return follower/following/posts counts for a user (or current user)
require __DIR__.'/db.php';
require __DIR__.'/util.php';

header('Content-Type: application/json');

$in = input_json_or_form();
$viewer = auth_user_or_null($db);
$userId = $_GET['user_id'] ?? ($in['user_id'] ?? ($viewer['id'] ?? null));
if (!$userId) json_out(['error' => 'USER_REQUIRED'], 400);

try {
  $st = $db->prepare('SELECT COUNT(*) FROM follows WHERE followed_id = :id');
  $st->execute([':id'=>$userId]);
  $followers = (int)$st->fetchColumn();

  $st = $db->prepare('SELECT COUNT(*) FROM follows WHERE follower_id = :id');
  $st->execute([':id'=>$userId]);
  $following = (int)$st->fetchColumn();

  $st = $db->prepare('SELECT COUNT(*) FROM posts WHERE user_id = :id');
  $st->execute([':id'=>$userId]);
  $posts = (int)$st->fetchColumn();

  json_out(['ok'=>true,'counts'=>[
    'posts'=>$posts,
    'followers'=>$followers,
    'following'=>$following,
  ]]);
} catch (Throwable $e) {
  json_out(['error'=>'DB','detail'=>$e->getMessage()], 500);
}
