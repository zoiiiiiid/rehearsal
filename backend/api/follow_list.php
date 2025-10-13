<?php
// backend/api/follow_list.php
// Followers/Following list with search + pagination; safe JSON errors

require __DIR__.'/db.php';
require __DIR__.'/util.php';

header('Content-Type: application/json');

// Compatibility: your db.php exposes $pdo; other endpoints may use $db.
if (!isset($db) && isset($pdo)) { $db = $pdo; }

try {
  if (!isset($db) || !($db instanceof PDO)) {
    json_out(['error'=>'DB_NOT_AVAILABLE'], 500);
  }

  $in     = input_json_or_form();
  $viewer = auth_user_or_null($db); // can be null

  $type   = $_GET['type']    ?? ($in['type']    ?? 'followers');      // 'followers' | 'following'
  $userId = $_GET['user_id'] ?? ($in['user_id'] ?? ($viewer['id'] ?? null));
  $limit  = max(1, min(50, (int)($_GET['limit'] ?? ($in['limit'] ?? 20))));
  $page   = max(1, (int)($_GET['page']  ?? ($in['page']  ?? 1)));
  $q      = trim($_GET['q'] ?? ($in['q'] ?? ''));
  $off    = ($page - 1) * $limit;

  if (!$userId) json_out(['error' => 'USER_REQUIRED'], 400);

  // Join relationship
  $rel = ($type === 'following')
    ? 'f.follower_id = :uid AND u.id = f.followed_id'   // users that $userId is following
    : 'f.followed_id = :uid AND u.id = f.follower_id';  // followers of $userId

  $whereQ = '';
  $params = [':uid' => $userId];
  if ($q !== '') { $whereQ = ' AND (u.name LIKE :q OR p.username LIKE :q)'; $params[':q'] = "%$q%"; }

  // Total count
  $sqlCnt = "SELECT COUNT(*) FROM follows f
             JOIN users u ON ($rel)
             LEFT JOIN profiles p ON p.user_id = u.id
             WHERE 1=1 $whereQ";
  $st = $db->prepare($sqlCnt); $st->execute($params);
  $total = (int)$st->fetchColumn();

  // Paged list + viewer context
  $viewerId = $viewer['id'] ?? '00000000-0000-0000-0000-000000000000';
  $params2 = $params; // clone base (uid, q?)
  // NOTE: with emulate prepares OFF, reusing same named param twice throws HY093.
  // Bind separate names for each occurrence.
  $params2[':viewer1'] = $viewerId;
  $params2[':viewer2'] = $viewerId;

  $sql = "SELECT
            u.id,
            u.name AS display_name,
            p.username,
            p.avatar_url,
            CASE WHEN u.id = :viewer1 THEN 1 ELSE 0 END AS is_me,
            CASE WHEN EXISTS (
              SELECT 1 FROM follows fx
              WHERE fx.follower_id = :viewer2 AND fx.followed_id = u.id
            ) THEN 1 ELSE 0 END AS following
          FROM follows f
          JOIN users u ON ($rel)
          LEFT JOIN profiles p ON p.user_id = u.id
          WHERE 1=1 $whereQ
          ORDER BY u.name ASC
          LIMIT $limit OFFSET $off";

  $st = $db->prepare($sql); $st->execute($params2);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $items = array_map(function($r){
    $r['handle']    = !empty($r['username']) ? '@'.$r['username'] : '';
    $r['following'] = (bool)$r['following'];
    $r['is_me']     = (bool)$r['is_me'];
    return $r;
  }, $rows);

  json_out([
    'ok'    => true,
    'page'  => $page,
    'limit' => $limit,
    'total' => $total,
    'items' => $items,
  ]);
} catch (Throwable $e) {
  json_out(['error' => 'SERVER', 'detail' => $e->getMessage()], 500);
}
