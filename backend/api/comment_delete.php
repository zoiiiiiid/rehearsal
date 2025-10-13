<?php
require __DIR__.'/db.php';
require __DIR__.'/util.php';
header('Content-Type: application/json');

try {
  $me = require_auth($pdo);
  $uid = $me['id'];
  $role = strtolower($me['role'] ?? '');

  $in = input_json_or_form();
  $cid = intval($in['comment_id'] ?? $_POST['comment_id'] ?? 0);
  if ($cid <= 0) json_out(['error'=>'COMMENT_ID_REQUIRED'], 400);

  $st = $pdo->prepare("SELECT id, user_id, post_id FROM comments WHERE id=? LIMIT 1");
  $st->execute([$cid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) json_out(['error'=>'COMMENT_NOT_FOUND'], 404);

  $canDelete = ($row['user_id'] === $uid) || ($role === 'admin');
  if (!$canDelete) json_out(['error'=>'FORBIDDEN'], 403);

  $pdo->prepare("DELETE FROM comments WHERE id=?")->execute([$cid]);
  json_out(['ok'=>true, 'post_id'=>$row['post_id']]);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER','detail'=>$e->getMessage()], 500);
}
