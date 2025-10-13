<?php
// backend/api/spotlight_artists.php
require __DIR__.'/util.php'; cors();
require __DIR__.'/db.php';
if (!isset($db) && isset($pdo)) $db = $pdo;

function col_exists(PDO $db, string $table, string $col): bool {
  try {
    $st = $db->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
    $st->execute([':c' => $col]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { return false; }
}
function pick_col(PDO $db, string $table, array $candidates): ?string {
  foreach ($candidates as $c) if (col_exists($db, $table, $c)) return $c;
  return null;
}

try {
  $days  = max(1, min(90, (int)($_GET['days'] ?? 30)));
  $limit = max(1, min(30, (int)($_GET['limit'] ?? 12)));

  // Which timestamp columns exist?
  $postsCreated    = pick_col($db, 'posts',    ['created_at','created','time']);
  $likesCreated    = pick_col($db, 'likes',    ['created_at','created','time']);
  $commentsCreated = pick_col($db, 'comments', ['created_at','created','time']);

  // Build optional time filters
  $postsTime   = $postsCreated    ? "WHERE $postsCreated    >= DATE_SUB(NOW(), INTERVAL :d DAY)" : "";
  $likesTime   = $likesCreated    ? "WHERE l.$likesCreated  >= DATE_SUB(NOW(), INTERVAL :d DAY)" : "";
  $commentTime = $commentsCreated ? "WHERE c.$commentsCreated>= DATE_SUB(NOW(), INTERVAL :d DAY)" : "";

  $sql = "
    SELECT
      u.id,
      u.name,
      pr.username,
      pr.avatar_url,
      COALESCE(p.post_cnt,0) +
      COALESCE(l.like_cnt,0) +
      COALESCE(c.comment_cnt,0) AS score
    FROM users u
    LEFT JOIN profiles pr ON pr.user_id = u.id
    LEFT JOIN (
      SELECT user_id, COUNT(*) AS post_cnt
      FROM posts
      $postsTime
      GROUP BY user_id
    ) p ON p.user_id = u.id
    LEFT JOIN (
      SELECT p.user_id, COUNT(*) AS like_cnt
      FROM likes l
      JOIN posts p ON p.id = l.post_id
      $likesTime
      GROUP BY p.user_id
    ) l ON l.user_id = u.id
    LEFT JOIN (
      SELECT p.user_id, COUNT(*) AS comment_cnt
      FROM comments c
      JOIN posts p ON p.id = c.post_id
      $commentTime
      GROUP BY p.user_id
    ) c ON c.user_id = u.id
    ORDER BY score DESC, u.name ASC
    LIMIT :lim
  ";

  $st = $db->prepare($sql);
  // Bind :d only if any subquery used it
  if ($postsCreated || $likesCreated || $commentsCreated) {
    if ($postsCreated)    $st->bindValue(':d', $days, PDO::PARAM_INT);
    if ($likesCreated)    $st->bindValue(':d', $days, PDO::PARAM_INT);
    if ($commentsCreated) $st->bindValue(':d', $days, PDO::PARAM_INT);
  }
  $st->bindValue(':lim', $limit, PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $items = [];
  foreach ($rows as $r) {
    $items[] = [
      'id'           => (string)$r['id'],
      'display_name' => (string)$r['name'],
      'username'     => $r['username'],
      'handle'       => $r['username'] ? '@'.strtolower($r['username']) : null,
      'avatar_url'   => $r['avatar_url'],
      'score'        => (int)$r['score'],
    ];
  }

  json_out(['ok'=>true, 'items'=>$items, 'days'=>$days]);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER', 'detail'=>$e->getMessage()], 500);
}
