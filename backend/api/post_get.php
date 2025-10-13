<?php
// backend/api/post_view.php
require __DIR__ . '/util.php'; cors(); require __DIR__ . '/db.php';

try {
  // allow anonymous view if you want; or require_auth($pdo) if private
  $me = auth_user_or_null($pdo); // nullable
  $myId = $me ? (string)$me['id'] : null;

  $id = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
  if ($id === '') json_out(['error' => 'MISSING_ID'], 422);

  // Adjust table/column names to your schema.
  // Expected tables:
  //   posts(id,user_id,caption,media_url,media_type,created_at)
  //   post_likes(id,post_id,user_id)
  //   comments(id,post_id,body,created_at)
  //
  // If your names differ, tweak the SQL below.
  $sql = "SELECT p.id, p.user_id, p.caption, p.media_url, p.media_type, p.created_at
          FROM posts p
          WHERE p.id = :id
          LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([':id' => $id]);
  $post = $st->fetch(PDO::FETCH_ASSOC);
  if (!$post) json_out(['error' => 'NOT_FOUND'], 404);

  // Counts
  $likes = (int)$pdo->query("SELECT COUNT(*) FROM likes WHERE post_id=" . $pdo->quote($id))->fetchColumn();
  $comments = (int)$pdo->query("SELECT COUNT(*) FROM comments   WHERE post_id=" . $pdo->quote($id))->fetchColumn();

  // liked by me?
  $liked = false;
  if ($myId) {
    $st = $pdo->prepare("SELECT 1 FROM likes WHERE post_id=? AND user_id=? LIMIT 1");
    $st->execute([$id, $myId]);
    $liked = (bool)$st->fetchColumn();
  }

  // Optional: include minimal author blob if you want
  $author = null;
  try {
    $st = $pdo->prepare("SELECT u.id, u.name AS display_name, p.username, p.avatar_url
                         FROM users u
                         LEFT JOIN profiles p ON p.user_id=u.id
                         WHERE u.id=? LIMIT 1");
    $st->execute([$post['user_id']]);
    $author = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (\Throwable $e) { /* optional */ }

  $out = [
    'id'         => (int)$post['id'],
    'caption'    => $post['caption'] ?? '',
    'media_url'  => $post['media_url'] ?? '',
    'media_type' => $post['media_type'] ?? '',
    'likes'      => $likes,
    'comments'   => $comments,
    'liked'      => $liked,
    'user'       => $author,
  ];

  json_out(['ok' => true, 'post' => $out]);
} catch (Throwable $e) {
  json_out(['error' => 'SERVER', 'detail' => $e->getMessage()], 500);
}
