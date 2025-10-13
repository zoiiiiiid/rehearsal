<?php
require __DIR__.'/util.php'; cors(); require __DIR__.'/db.php';
try {
  $me = require_auth($pdo);
  if (($me['role'] ?? '') !== 'admin') json_out(['error'=>'FORBIDDEN'],403);

  $in = input_json_or_form();
  $target = trim((string)($in['user_id'] ?? ''));
  if ($target === '') json_out(['error'=>'BAD_REQUEST','detail'=>'user_id required'],422);

  $st = $pdo->prepare("SELECT id, role, status FROM users WHERE id=:id LIMIT 1");
  $st->execute([':id'=>$target]); $u = $st->fetch(PDO::FETCH_ASSOC);
  if (!$u) json_out(['error'=>'NOT_FOUND'],404);

  $upd = $pdo->prepare("UPDATE users SET status='none' WHERE id=:id");
  $upd->execute([':id'=>$target]);

  $st = $pdo->prepare("SELECT id, role, status FROM users WHERE id=:id LIMIT 1");
  $st->execute([':id'=>$target]); $updated = $st->fetch(PDO::FETCH_ASSOC);
  json_out(['ok'=>true, 'user'=>$updated]);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER','detail'=>$e->getMessage()],500);
}