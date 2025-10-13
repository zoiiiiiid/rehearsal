<?php
require __DIR__.'/util.php'; cors(); require __DIR__.'/db.php';
$post_id = intval($_GET['post_id'] ?? 0);
if($post_id<=0) json_out(['error'=>'POST_ID_REQUIRED'], 400);

$limit = max(1, min(50, intval($_GET['limit'] ?? 20)));
$page  = max(1, intval($_GET['page'] ?? 1));
$off   = ($page-1)*$limit;

$t=bearer_token(); $me=user_by_token($pdo,$t);
$viewerId = $me['id'] ?? null; $isAdmin = strtolower($me['role'] ?? '')==='admin';

$sql = "SELECT c.id, c.body, c.created_at,
               u.id AS user_id, u.name, u.email,
               p.username, p.avatar_url
        FROM comments c
        JOIN users u ON u.id=c.user_id
        LEFT JOIN profiles p ON p.user_id=u.id
        WHERE c.post_id=?
        ORDER BY c.id DESC
        LIMIT $limit OFFSET $off";
$st=$pdo->prepare($sql); $st->execute([$post_id]);
$rows=$st->fetchAll(PDO::FETCH_ASSOC);

function nice_name($r){ return $r['name'] ?: explode('@',$r['email'])[0]; }
$items=[];
foreach($rows as $r){
  $items[]=[
    'id'=>(int)$r['id'],
    'body'=>$r['body'],
    'time'=>$r['created_at'],
    'can_delete'=> ($viewerId && $viewerId===$r['user_id']) || $isAdmin,
    'user'=>[
      'id'=>$r['user_id'],
      'display_name'=>nice_name($r),
      'username'=>$r['username'],
      'handle'=>$r['username']? '@'.strtolower($r['username']) : null,
      'avatar_url'=>$r['avatar_url']
    ]
  ];
}
$cnt=$pdo->prepare('SELECT COUNT(*) FROM comments WHERE post_id=?'); $cnt->execute([$post_id]);
json_out(['items'=>$items,'page'=>$page,'count'=>(int)$cnt->fetchColumn()]);
