<?php
// backend/api/workshop_claim.php
require __DIR__.'/util.php'; cors(); require __DIR__.'/db.php';

function table_has_col(PDO $pdo, string $t, string $c): bool {
  static $C=[]; $k="$t.$c"; if(isset($C[$k])) return $C[$k];
  $st=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
  $st->execute([':t'=>$t,':c'=>$c]); return $C[$k] = ((int)$st->fetchColumn() > 0);
}
try {
  $me=require_auth($pdo); $uid=(string)$me['id'];
  $wid=(string)($_POST['workshop_id']??$_GET['workshop_id']??'');
  if($wid==='') json_out(['error'=>'WORKSHOP_ID_REQUIRED'],422);

  $cols="id, ".
        (table_has_col($pdo,'workshops','price_cents')?'price_cents':'0')." AS price_cents, ".
        (table_has_col($pdo,'workshops','capacity')?'capacity':'0')." AS capacity, ".
        (table_has_col($pdo,'workshops','zoom_join_url')?'zoom_join_url':'NULL')." AS zoom_join_url, ".
        (table_has_col($pdo,'workshops','zoom_link')?'zoom_link':'NULL')." AS zoom_link";
  $st=$pdo->prepare("SELECT $cols FROM workshops WHERE id=? LIMIT 1");
  $st->execute([$wid]); $ws=$st->fetch(PDO::FETCH_ASSOC);
  if(!$ws) json_out(['error'=>'NOT_FOUND'],404);

  if((int)$ws['price_cents']>0) json_out(['error'=>'PAYMENT_REQUIRED'],402);

  $cap=(int)$ws['capacity'];
  if($cap>0){
    $cnt=(int)$pdo->query("SELECT COUNT(*) FROM workshop_attendance WHERE workshop_id=".$pdo->quote($wid))->fetchColumn();
    if($cnt >= $cap) json_out(['error'=>'FULL'],409);
  }

  $pdo->prepare("INSERT IGNORE INTO workshop_attendance (workshop_id,user_id,checked_in_at) VALUES (?,?,NOW())")
      ->execute([$wid,$uid]);

  $join = $ws['zoom_join_url'] ?: $ws['zoom_link'] ?: null;

  json_out(['ok'=>true,'status'=>'checked_in','join_url'=>$join]);
} catch(Throwable $e){
  json_out(['error'=>'SERVER','detail'=>$e->getMessage()],500);
}
