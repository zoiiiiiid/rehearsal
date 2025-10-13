<?php
// backend/api/workshop_detail.php
require __DIR__.'/util.php'; cors(); require __DIR__.'/db.php';
header('Content-Type: application/json');

function col_exists(PDO $db, string $table, string $col): bool {
  try { $st=$db->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $st->execute([':c'=>$col]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { return false; }
}
function pick_col(PDO $db, string $table, array $cands): ?string {
  foreach ($cands as $c) if (col_exists($db,$table,$c)) return $c;
  return null;
}
function table_exists(PDO $db, string $t): bool {
  try { $st=$db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
        $st->execute([':t'=>$t]);
        return (int)$st->fetchColumn()>0;
  } catch(Throwable $e){ return false; }
}

try {
  if (!isset($db) && isset($pdo)) $db = $pdo;
  if (!($db instanceof PDO)) json_out(['error'=>'DB_NOT_AVAILABLE'],500);

  $me = auth_user_or_null($db);
  $viewerId = $me['id'] ?? null;

  $wid = (string)($_GET['id'] ?? $_GET['workshop_id'] ?? '');
  if ($wid === '') json_out(['error'=>'WORKSHOP_ID_REQUIRED'], 422);

  $startCol = pick_col($db,'workshops',['starts_at','start_at','start_time','starts']);
  $endCol   = pick_col($db,'workshops',['ends_at','end_at','end_time','ends']);
  $locCol   = pick_col($db,'workshops',['location']);
  $capCol   = pick_col($db,'workshops',['capacity']);
  $priceCol = pick_col($db,'workshops',['price_cents']);
  $paidCol  = pick_col($db,'workshops',['paid_required']);
  $coverCol = pick_col($db,'workshops',['cover_url','image_url','thumb_url','thumbnail']);
  $zoomJoin = pick_col($db,'workshops',['zoom_join_url']);
  $zoomLink = pick_col($db,'workshops',['zoom_link']);
  $ownerCol = pick_col($db,'workshops',['host_user_id','mentor_id','owner_id','host_id','created_by','user_id']);

  $priceExpr = $priceCol ? "COALESCE(w.`$priceCol`,0)" : "0";
  if ($paidCol && $priceCol) {
    $paidExpr = "COALESCE(w.`$paidCol`, CASE WHEN $priceExpr > 0 THEN 1 ELSE 0 END)";
  } elseif ($paidCol) {
    $paidExpr = "COALESCE(w.`$paidCol`,0)";
  } else {
    $paidExpr = "(CASE WHEN $priceExpr > 0 THEN 1 ELSE 0 END)";
  }

  $select = [
  "w.`id`",
  "w.`title`",
  "w.`description`",
  $startCol ? "w.`$startCol` AS starts_at" : "NULL AS starts_at",
  $endCol   ? "w.`$endCol`   AS ends_at"   : "NULL AS ends_at",
  $locCol   ? "w.`$locCol`   AS location"  : "NULL AS location",
  $capCol   ? "w.`$capCol`   AS capacity"  : "0 AS capacity",
  // force sane numbers
  $priceCol ? "COALESCE(w.`$priceCol`,0) AS price_cents" : "0 AS price_cents",
  // coalesce NULL paid_required to price-based truth
  $paidCol  ? "COALESCE(w.`$paidCol`, CASE WHEN ".($priceCol ? "COALESCE(w.`$priceCol`,0)" : "0")." > 0 THEN 1 ELSE 0 END) AS paid_required"
            : "CASE WHEN ".($priceCol ? "COALESCE(w.`$priceCol`,0)" : "0")." > 0 THEN 1 ELSE 0 END AS paid_required",
  $coverCol ? "w.`$coverCol` AS cover_url" : "NULL AS cover_url",
  $zoomJoin ? "w.`$zoomJoin` AS zoom_join_url" : "NULL AS zoom_join_url",
  $zoomLink ? "w.`$zoomLink` AS zoom_link"     : "NULL AS zoom_link",
  $ownerCol ? "w.`$ownerCol` AS host_user_id"  : "NULL AS host_user_id",
  
];


  $sql = "SELECT ".implode(',', $select)." FROM workshops w WHERE w.`id`=:id LIMIT 1";
  $st = $db->prepare($sql);
  $st->execute([':id'=>$wid]);
  $w = $st->fetch(PDO::FETCH_ASSOC);
  if (!$w) json_out(['error'=>'NOT_FOUND'], 404);

  $host = ['id'=>null,'display_name'=>null,'username'=>null,'avatar_url'=>null];
  if (!empty($w['host_user_id']) && table_exists($db,'users')) {
    $hst = $db->prepare("
      SELECT
        u.`id`,
        COALESCE(u.`display_name`,u.`name`,u.`full_name`,u.`username`) AS display_name,
        u.`username` AS username,
        COALESCE(u.`avatar_url`,u.`photo`,u.`image_url`,u.`picture`,u.`avatar`) AS avatar_url
      FROM users u WHERE u.`id`=:id LIMIT 1
    ");
    $hst->execute([':id'=>$w['host_user_id']]);
    if ($row = $hst->fetch(PDO::FETCH_ASSOC)) {
      $host['id']           = (string)$row['id'];
      $host['display_name'] = $row['display_name'];
      $host['username']     = $row['username'];
      $host['avatar_url']   = $row['avatar_url'];
    }
  }

  $counts = ['rsvps'=>0,'attendance'=>0,'claims'=>0];
  if (table_exists($db,'workshop_rsvps')) {
    $counts['rsvps'] = (int)$db->query("SELECT COUNT(*) FROM workshop_rsvps WHERE workshop_id=".$db->quote($wid))->fetchColumn();
  }
  if (table_exists($db,'workshop_attendance')) {
    $counts['attendance'] = (int)$db->query("SELECT COUNT(*) FROM workshop_attendance WHERE workshop_id=".$db->quote($wid))->fetchColumn();
    $counts['claims'] = $counts['attendance'];
  }

  $viewer = ['can_manage'=>false];
  if ($viewerId !== null) {
    $isAdmin = (strtolower((string)($me['role'] ?? '')) === 'admin');
    $viewer['can_manage'] = $isAdmin || (!empty($w['host_user_id']) && (string)$w['host_user_id'] === (string)$viewerId);
    if (table_exists($db,'workshop_rsvps')) {
      $vr = $db->prepare("SELECT status FROM workshop_rsvps WHERE workshop_id=:wid AND user_id=:uid LIMIT 1");
      $vr->execute([':wid'=>$wid, ':uid'=>$viewerId]);
      if ($row = $vr->fetch(PDO::FETCH_ASSOC)) $viewer['rsvp_status'] = (string)$row['status'];
    }
  }

  $out = [
    'id'            => (string)$w['id'],
    'title'         => (string)($w['title'] ?? ''),
    'description'   => (string)($w['description'] ?? ''),
    'starts_at'     => $w['starts_at'],
    'ends_at'       => $w['ends_at'],
    'location'      => $w['location'],
    'capacity'      => (int)$w['capacity'],
    'price_cents'   => (int)$w['price_cents'],
    'paid_required' => ((int)$w['paid_required'] === 1),
    'cover_url'     => $w['cover_url'],
    'counts'        => $counts,
    'viewer'        => $viewer,
    'host'          => $host,
  ];

  json_out(['ok'=>true, 'workshop'=>$out]);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER', 'detail'=>$e->getMessage()], 500);
}
