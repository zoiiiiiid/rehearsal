<?php
require __DIR__.'/db.php';
require __DIR__.'/util.php';
header('Content-Type: application/json');

try {
  $me = require_auth($pdo);                // you already return users.* here
  $uid = $me['id'];
  $role = strtolower($me['role'] ?? '');

  // accept JSON or form
  $in = input_json_or_form();
  $postId = intval($in['post_id'] ?? $_POST['post_id'] ?? 0);
  if ($postId <= 0) json_out(['error'=>'POST_ID_REQUIRED'], 400);

  // fetch owner + media
  $st = $pdo->prepare("SELECT id, user_id, media_url FROM posts WHERE id=? LIMIT 1");
  $st->execute([$postId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) json_out(['error'=>'POST_NOT_FOUND'], 404);

  $ownerId = $row['user_id'];
  $canDelete = ($ownerId === $uid) || ($role === 'admin');
  if (!$canDelete) json_out(['error'=>'FORBIDDEN'], 403);

  $pdo->beginTransaction();
  $pdo->prepare("DELETE FROM likes WHERE post_id=?")->execute([$postId]);
  $pdo->prepare("DELETE FROM comments WHERE post_id=?")->execute([$postId]);
  $pdo->prepare("DELETE FROM posts WHERE id=?")->execute([$postId]);
  $pdo->commit();

  // best-effort remove file if it lives under /uploads/posts
  $url = (string)$row['media_url'];
  if ($url !== '') {
    $path = parse_url($url, PHP_URL_PATH);                // /backend/uploads/posts/xxx
    $root = dirname(__DIR__);                             // .../backend
    $file = realpath($root . '/../' . ltrim($path, '/')); // map to disk
    $safe = realpath($root . '/uploads/posts');           // target folder
    if ($file && $safe && str_starts_with($file, $safe)) @unlink($file);
  }

  json_out(['ok'=>true]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch(Throwable $ignore){} }
  json_out(['error'=>'SERVER','detail'=>$e->getMessage()], 500);
}
