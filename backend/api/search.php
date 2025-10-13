<?php
require __DIR__.'/util.php'; cors();
require __DIR__.'/db.php';

$type  = strtolower(trim($_GET['type'] ?? 'posts'));
$q     = trim((string)($_GET['q'] ?? ''));
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(1, (int)($_GET['limit'] ?? ($type==='users' ? 20 : 12))));
$off   = ($page-1)*$limit;

// Optional auth to compute `liked`
$uid = null; $tok = bearer_token();
if ($tok) {
  $s=$pdo->prepare('SELECT u.id FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE t.token=? AND t.expires_at>NOW() LIMIT 1');
  $s->execute([$tok]); $uid=$s->fetchColumn() ?: null;
}

if ($type === 'users') {
  if ($q === '') {
    $sql = 'SELECT u.id, u.name, COALESCE(p.username, "") AS username
            FROM users u LEFT JOIN profiles p ON p.user_id=u.id
            ORDER BY u.created_at DESC LIMIT ?, ?';
    $st = $pdo->prepare($sql);
    $st->bindValue(1, $off, PDO::PARAM_INT);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
  } else {
    $like = "%$q%";
    $sql = 'SELECT u.id, u.name, COALESCE(p.username, "") AS username
            FROM users u LEFT JOIN profiles p ON p.user_id=u.id
            WHERE u.name LIKE ? OR p.username LIKE ? OR u.email LIKE ?
            ORDER BY u.created_at DESC LIMIT ?, ?';
    $st = $pdo->prepare($sql);
    $st->bindValue(1, $like);
    $st->bindValue(2, $like);
    $st->bindValue(3, $like);
    $st->bindValue(4, $off, PDO::PARAM_INT);
    $st->bindValue(5, $limit, PDO::PARAM_INT);
    $st->execute();
  }
  json_out(['type'=>'users','page'=>$page,'limit'=>$limit,'users'=>$st->fetchAll()]);
  exit;
}

// Posts (default)
$select = 'SELECT p.id, p.media_url, p.caption, p.created_at,
                  u.id AS user_id, u.name, COALESCE(pr.username, "") AS username,
                  (SELECT COUNT(*) FROM likes l WHERE l.post_id=p.id) AS likes,
                  (SELECT COUNT(*) FROM comments c WHERE c.post_id=p.id) AS comments';
if ($uid) $select .= ', EXISTS(SELECT 1 FROM likes l2 WHERE l2.post_id=p.id AND l2.user_id=?) AS liked';
else $select .= ', 0 AS liked';

if ($q === '') {
  $sql = $select.' FROM posts p JOIN users u ON u.id=p.user_id LEFT JOIN profiles pr ON pr.user_id=u.id
                   ORDER BY p.created_at DESC LIMIT ?, ?';
  $st = $pdo->prepare($sql);
  $i=1;
  if ($uid) { $st->bindValue($i++, $uid); }
  $st->bindValue($i++, $off, PDO::PARAM_INT);
  $st->bindValue($i++, $limit, PDO::PARAM_INT);
  $st->execute();
} else {
  $like = "%$q%";
  $sql = $select.' FROM posts p JOIN users u ON u.id=p.user_id LEFT JOIN profiles pr ON pr.user_id=u.id
                   WHERE p.caption LIKE ? OR u.name LIKE ? OR pr.username LIKE ?
                   ORDER BY p.created_at DESC LIMIT ?, ?';
  $st = $pdo->prepare($sql);
  $i=1;
  if ($uid) { $st->bindValue($i++, $uid); }
  $st->bindValue($i++, $like);
  $st->bindValue($i++, $like);
  $st->bindValue($i++, $like);
  $st->bindValue($i++, $off, PDO::PARAM_INT);
  $st->bindValue($i++, $limit, PDO::PARAM_INT);
  $st->execute();
}
json_out(['type'=>'posts','page'=>$page,'limit'=>$limit,'posts'=>$st->fetchAll()]);