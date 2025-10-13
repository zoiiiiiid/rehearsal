<?php
// backend/api/feed.php  (skill filter added)
require __DIR__.'/util.php'; cors(); require __DIR__.'/db.php';

$t = bearer_token(); if(!$t) json_out(['error'=>'NO_TOKEN'],401);
$me = user_by_token($pdo,$t); if(!$me) json_out(['error'=>'TOKEN_INVALID'],401);

$limit = max(1, min(50, intval($_GET['limit'] ?? 10)));
$page  = max(1, intval($_GET['page'] ?? 1));
$off   = ($page-1)*$limit;

$skill = strtolower(trim($_GET['skill'] ?? ''));  // e.g. dj, singer, ...
$bind  = [':uid' => $me['id']];

$sql = "SELECT p.id, p.media_url, p.caption, p.skill, p.created_at,
               u.id AS user_id, u.name, u.email,
               pr.username, pr.bio, pr.avatar_url,
               (SELECT COUNT(*) FROM likes    WHERE post_id=p.id) AS likes,
               (SELECT COUNT(*) FROM comments WHERE post_id=p.id) AS comments,
               EXISTS(SELECT 1 FROM likes WHERE post_id=p.id AND user_id=:uid) AS liked
        FROM posts p
        JOIN users u         ON u.id = p.user_id
        LEFT JOIN profiles pr ON pr.user_id = u.id
        WHERE 1=1";

if ($skill !== '' && $skill !== 'all') {
  $sql .= " AND p.skill = :skill";
  $bind[':skill'] = $skill;
}

$sql .= " ORDER BY p.created_at DESC LIMIT $limit OFFSET $off";

$st = $pdo->prepare($sql);
$st->execute($bind);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

function time_ago($ts){
  $d=time()-strtotime($ts);
  if($d<60)   return $d.'s';
  if($d<3600) return intval($d/60).'m';
  if($d<86400)return intval($d/3600).'h';
  return intval($d/86400).'d';
}
$isAdmin = strtolower($me['role'] ?? '') === 'admin';

$items = [];
foreach($rows as $r){
  $items[] = [
    'id'        => (int)$r['id'],
    'media_url' => $r['media_url'],
    'caption'   => $r['caption'],
    'skill'     => $r['skill'],
    'time_ago'  => time_ago($r['created_at']),
    'likes'     => (int)$r['likes'],
    'comments'  => (int)$r['comments'],
    'liked'     => !!$r['liked'],
    'can_delete'=> ($r['user_id'] === $me['id']) || $isAdmin,
    'user'      => [
      'id'           => $r['user_id'],
      'name'         => $r['name'],
      'display_name' => $r['name'] ?: explode('@',$r['email'])[0],
      'username'     => $r['username'],
      'handle'       => $r['username'] ? '@'.strtolower($r['username']) : null,
      'avatar_url'   => $r['avatar_url'],
    ],
  ];
}
json_out(['items'=>$items,'page'=>$page,'limit'=>$limit]);
