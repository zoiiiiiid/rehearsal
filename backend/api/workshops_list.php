<?php
require __DIR__.'/util.php'; cors();
require __DIR__.'/db.php';
if (!isset($db) && isset($pdo)) $db = $pdo;

function col_exists(PDO $db, string $table, string $col): bool {
  try { $st=$db->prepare("SHOW COLUMNS FROM `$table` LIKE :c"); $st->execute([':c'=>$col]); return (bool)$st->fetch(PDO::FETCH_ASSOC); }
  catch(Throwable $e){ return false; }
}
function pick_col(PDO $db, string $table, array $candidates): ?string {
  foreach ($candidates as $c) if (col_exists($db,$table,$c)) return $c;
  return null;
}
function user_name_expr(PDO $db): string {
  $parts=[]; foreach(['display_name','name','full_name','username'] as $c) if (col_exists($db,'users',$c)) $parts[]="u.`$c`";
  if (col_exists($db,'users','email')) $parts[]="SUBSTRING_INDEX(u.`email`,'@',1)";
  return 'COALESCE('.implode(',', $parts ?: ["'Host'"]).')';
}
function user_username_expr(PDO $db): string {
  foreach(['username','handle'] as $c) if (col_exists($db,'users',$c)) return "u.`$c`";
  return "NULL";
}

try {
  $status = strtolower(trim($_GET['status'] ?? 'ongoing'));
  $page   = max(1,(int)($_GET['page']??1));
  $limit  = max(1,min(50,(int)($_GET['limit']??12)));
  $off    = ($page-1)*$limit;

  $startCol = pick_col($db,'workshops',['starts_at','start_at','start_time','starts']);
  $endCol   = pick_col($db,'workshops',['ends_at','end_at','end_time','ends']);
  $created  = pick_col($db,'workshops',['created_at','created']);

  $priceCol = pick_col($db,'workshops',['price_cents','price']);
  $paidCol  = pick_col($db,'workshops',['paid_required']);
  $coverCol = pick_col($db,'workshops',['cover_url','image_url','thumbnail','thumb_url']);
  $hostCol  = pick_col($db,'workshops',['host_user_id','mentor_id','owner_id','host_id','created_by','user_id']) ?? 'host_user_id';

  $startExpr = $startCol ? "w.`$startCol`" : ($created ? "w.`$created`" : "NOW()");
  $endExpr   = $endCol   ? "w.`$endCol`"   : "DATE_ADD($startExpr, INTERVAL 2 HOUR)";

  $where="1=1"; $order="$startExpr ASC";
  switch($status){
    case 'ongoing':  $where="NOW() BETWEEN $startExpr AND $endExpr"; $order="$startExpr ASC"; break;
    case 'upcoming': $where="$startExpr > NOW()";                     $order="$startExpr ASC"; break;
    case 'past':     $where="$endExpr < NOW()";                       $order="$endExpr DESC"; break;
    case 'all': default: $where="1=1";                                $order="$startExpr DESC"; break;
  }

  $total = (int)$db->query("SELECT COUNT(*) FROM workshops w WHERE $where")->fetchColumn();

  $priceExpr = $priceCol ? "COALESCE(w.`$priceCol`,0)" : "0";
  $paidExpr  = $paidCol ? "COALESCE(w.`$paidCol`, 0 THEN 1 ELSE 0 END)"
                        : "(CASE WHEN $priceExpr > 0 THEN 1 ELSE 0 END)";
  $coverExpr = $coverCol ? "w.`$coverCol`" : "NULL";

  $nameExpr  = user_name_expr($db)." AS host_name";
  $unameExpr = user_username_expr($db)." AS host_username";

  $sql = "
    SELECT
      w.`id`,
      w.`title`,
      w.`description`,
      $startExpr AS starts_at,
      $endExpr   AS ends_at,
      w.`location`,
      w.`capacity`,
      $priceExpr AS price_cents,
      $paidExpr  AS paid_required,
      $coverExpr AS cover_url,
      u.`id` AS host_id,
      $nameExpr,
      $unameExpr
    FROM workshops w
    JOIN users u ON u.`id` = w.`$hostCol`
    WHERE $where
    ORDER BY $order
    LIMIT $limit OFFSET $off
  ";
  $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

  $items = [];
  foreach($rows as $r){
    $items[] = [
      'id'            => (int)$r['id'],
      'title'         => (string)($r['title'] ?? ''),
      'description'   => (string)($r['description'] ?? ''),
      'starts_at'     => $r['starts_at'],
      'ends_at'       => $r['ends_at'],
      'location'      => (string)($r['location'] ?? ''),
      'capacity'      => isset($r['capacity']) ? (int)$r['capacity'] : null,
      'price_cents'   => (int)($r['price_cents'] ?? 0),
      'paid_required' => ((int)($r['paid_required'] ?? 0)) === 1,
      'cover_url'     => $r['cover_url'],
      'host'          => [
        'id'           => (string)$r['host_id'],
        'display_name' => (string)$r['host_name'],
        'username'     => $r['host_username'],
      ],
    ];
  }

  json_out(['ok'=>true,'page'=>$page,'limit'=>$limit,'total'=>$total,'items'=>$items]);
} catch(Throwable $e){
  json_out(['error'=>'SERVER','detail'=>$e->getMessage()],500);
}
