<?php
// backend/api/notifications_list.php
require __DIR__.'/db.php';
require __DIR__.'/util.php';
header('Content-Type: application/json');

// compat: some endpoints use $db; your db.php exposes $pdo.
if (!isset($db) && isset($pdo)) $db = $pdo;

try {
  if (!($db instanceof PDO)) json_out(['error'=>'DB_NOT_AVAILABLE'], 500);

  $me = require_auth($db);
  $uid = $me['id'];

  $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
  $page  = max(1, (int)($_GET['page']  ?? 1));
  $off   = ($page - 1) * $limit;

  // Totals (unread + all)
  $st = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :u");
  $st->execute([':u' => $uid]);
  $total = (int)$st->fetchColumn();

  $st = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :u AND read_at IS NULL");
  $st->execute([':u' => $uid]);
  $unread = (int)$st->fetchColumn();

  // List (actor info + optional post)
  $sql = "SELECT
            n.id, n.type, n.post_id, n.comment_id, n.created_at, n.read_at,
            a.id AS actor_id, a.name AS actor_name, a.email AS actor_email,
            ap.username AS actor_username, ap.avatar_url AS actor_avatar
          FROM notifications n
          JOIN users a         ON a.id = n.actor_id
          LEFT JOIN profiles ap ON ap.user_id = a.id
          WHERE n.user_id = :u1
          ORDER BY n.id DESC
          LIMIT $limit OFFSET $off";
  $st = $db->prepare($sql);
  $st->execute([':u1' => $uid]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  function nice_name($name, $email) {
    if (!empty($name)) return $name;
    if (!empty($email)) return explode('@', $email)[0];
    return 'Someone';
  }
  function time_ago($ts) {
    $d = time() - strtotime($ts);
    if ($d < 60) return $d.'s';
    if ($d < 3600) return intval($d/60).'m';
    if ($d < 86400) return intval($d/3600).'h';
    return intval($d/86400).'d';
  }

  $items = [];
  foreach ($rows as $r) {
    $items[] = [
      'id'        => (int)$r['id'],
      'type'      => $r['type'],                      // like|comment|follow
      'post_id'   => $r['post_id'] ? (int)$r['post_id'] : null,
      'comment_id'=> $r['comment_id'] ? (int)$r['comment_id'] : null,
      'time_ago'  => time_ago($r['created_at']),
      'read'      => !empty($r['read_at']),
      'actor'     => [
        'id'          => $r['actor_id'],
        'display_name'=> nice_name($r['actor_name'], $r['actor_email']),
        'username'    => $r['actor_username'],
        'avatar_url'  => $r['actor_avatar'],
        'handle'      => $r['actor_username'] ? '@'.strtolower($r['actor_username']) : null,
      ],
    ];
  }

  json_out(['ok'=>true, 'page'=>$page, 'limit'=>$limit, 'total'=>$total, 'unread'=>$unread, 'items'=>$items]);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER', 'detail'=>$e->getMessage()], 500);
}
