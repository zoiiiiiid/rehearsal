<?php
require __DIR__.'/util.php'; cors();
require __DIR__.'/db.php';

$uid = (int)($_GET['user_id'] ?? 0);
if ($uid<=0) json_out(['error'=>'BAD_USER'], 422);

$auth = null; $tok = bearer_token();
if ($tok) { $s=$pdo->prepare('SELECT u.id FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE t.token=? AND t.expires_at>NOW() LIMIT 1'); $s->execute([$tok]); $auth=(int)$s->fetchColumn(); }

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = min(20, max(1, (int)($_GET['limit'] ?? 12)));
$off   = ($page-1)*$limit;

$select = 'SELECT p.id, p.media_url, p.caption, p.created_at,
                  (SELECT COUNT(*) FROM likes l WHERE l.post_id=p.id) AS likes,
                  (SELECT COUNT(*) FROM comments c WHERE c.post_id=p.id) AS comments';
if ($auth) $select .= ', EXISTS(SELECT 1 FROM likes l2 WHERE l2.post_id=p.id AND l2.user_id=?) AS liked';
else $select .= ', 0 AS liked';

$sql = $select.' FROM posts p WHERE p.user_id=? ORDER BY p.created_at DESC LIMIT ?, ?';
$ps = $pdo->prepare($sql); $i=1; if ($auth) { $ps->bindValue($i++, $auth, PDO::PARAM_INT); }
$ps->bindValue($i++, $uid, PDO::PARAM_INT); $ps->bindValue($i++, $off, PDO::PARAM_INT); $ps->bindValue($i++, $limit, PDO::PARAM_INT);
$ps->execute();
json_out(['posts'=>$ps->fetchAll(), 'page'=>$page, 'limit'=>$limit]);