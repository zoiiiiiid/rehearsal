<?php
// Search users by name/username. Returns follow-context.
// Reuses NO named placeholder; uses :q1,:q2 and :v1,:v2.

require __DIR__ . '/db.php';
require __DIR__ . '/util.php';
header('Content-Type: application/json');

// compat: some endpoints use $db, your db.php exposes $pdo
if (!isset($db) && isset($pdo)) $db = $pdo;

try {
  $viewer = auth_user_or_null($db); // optional
  $q     = trim($_GET['q'] ?? '');
  $page  = max(1, (int)($_GET['page'] ?? 1));
  $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
  $off   = ($page - 1) * $limit;

  if ($q === '') {
    json_out(['ok'=>true, 'page'=>$page, 'limit'=>$limit, 'total'=>0, 'items'=>[]]);
  }

  // total (use distinct names for LIKE)
  $sqlCnt = "SELECT COUNT(*)
             FROM users u
             LEFT JOIN profiles p ON p.user_id = u.id
             WHERE u.name LIKE :q1 OR p.username LIKE :q2";
  $st = $db->prepare($sqlCnt);
  $st->execute([':q1' => "%$q%", ':q2' => "%$q%"]);
  $total = (int)$st->fetchColumn();

  // viewer context
  $viewerId = $viewer['id'] ?? '00000000-0000-0000-0000-000000000000';

  // list (again, no param reuse)
  $sql = "SELECT
            u.id,
            u.name AS display_name,
            p.username,
            p.avatar_url,
            CASE WHEN u.id = :v1 THEN 1 ELSE 0 END AS is_me,
            CASE WHEN EXISTS (
              SELECT 1 FROM follows f WHERE f.follower_id = :v2 AND f.followed_id = u.id
            ) THEN 1 ELSE 0 END AS is_following
          FROM users u
          LEFT JOIN profiles p ON p.user_id = u.id
          WHERE u.name LIKE :q1 OR p.username LIKE :q2
          ORDER BY u.name ASC
          LIMIT $limit OFFSET $off";
  $st = $db->prepare($sql);
  $st->execute([
    ':q1' => "%$q%",
    ':q2' => "%$q%",
    ':v1' => $viewerId,
    ':v2' => $viewerId,
  ]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as &$r) {
    $r['handle']        = !empty($r['username']) ? '@'.$r['username'] : '';
    $r['is_me']         = (bool)$r['is_me'];
    $r['is_following']  = (bool)$r['is_following'];
  }

  json_out(['ok'=>true, 'page'=>$page, 'limit'=>$limit, 'total'=>$total, 'items'=>$rows]);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER', 'detail'=>$e->getMessage()], 500);
}
