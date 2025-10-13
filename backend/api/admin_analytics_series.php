<?php
// backend/api/admin_analytics_series.php
require __DIR__.'/util.php'; cors(); require __DIR__.'/db.php';

function table_has_col(PDO $pdo, string $t, string $c): bool {
  static $m=[]; $k="$t.$c"; if(isset($m[$k]))return $m[$k];
  $st=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
  $st->execute([':t'=>$t, ':c'=>$c]); return $m[$k]=($st->fetchColumn()>0);
}

try {
  $me = require_auth($pdo);
  $metric = ($_GET['metric'] ?? 'posts');
  $range  = ($_GET['range'] ?? '30d');
  $days   = $range==='7d' ? 7 : ($range==='90d' ? 90 : 30);
  $since  = (new DateTime("-$days days"))->format('Y-m-d 00:00:00');

  $q = null;
  switch ($metric) {
    case 'active_users':
      // distinct users posting/commenting/liking per day
      $items=[];
      if (table_has_col($pdo,'posts','created_at')) {
        $st=$pdo->prepare("SELECT DATE(created_at) d, COUNT(DISTINCT user_id) c
                           FROM posts WHERE created_at>=? GROUP BY DATE(created_at)");
        $st->execute([$since]); foreach($st as $r){ $items[$r['d']]=($items[$r['d']]??0)+(int)$r['c']; }
      }
      if (table_has_col($pdo,'comments','created_at')) {
        $st=$pdo->prepare("SELECT DATE(created_at) d, COUNT(DISTINCT user_id) c
                           FROM comments WHERE created_at>=? GROUP BY DATE(created_at)");
        $st->execute([$since]); foreach($st as $r){ $items[$r['d']]=($items[$r['d']]??0)+(int)$r['c']; }
      }
      if (table_has_col($pdo,'likes','created_at')) {
        $st=$pdo->prepare("SELECT DATE(created_at) d, COUNT(DISTINCT user_id) c
                           FROM likes WHERE created_at>=? GROUP BY DATE(created_at)");
        $st->execute([$since]); foreach($st as $r){ $items[$r['d']]=($items[$r['d']]??0)+(int)$r['c']; }
      }
      ksort($items);
      $out = [];
      foreach ($items as $d=>$v) $out[]=['date'=>$d,'value'=>$v];
      json_out(['ok'=>true,'items'=>$out,'metric'=>$metric,'range'=>$range]);
      exit;
    case 'new_users':
      if (table_has_col($pdo,'users','created_at')) {
        $q="SELECT DATE(created_at) d, COUNT(*) c FROM users WHERE created_at>=?
            GROUP BY DATE(created_at) ORDER BY d";
      }
      break;
    case 'posts':
      if (table_has_col($pdo,'posts','created_at')) {
        $q="SELECT DATE(created_at) d, COUNT(*) c FROM posts WHERE created_at>=?
            GROUP BY DATE(created_at) ORDER BY d";
      }
      break;
    case 'comments':
      if (table_has_col($pdo,'comments','created_at')) {
        $q="SELECT DATE(created_at) d, COUNT(*) c FROM comments WHERE created_at>=?
            GROUP BY DATE(created_at) ORDER BY d";
      }
      break;
    case 'likes':
      if (table_has_col($pdo,'likes','created_at')) {
        $q="SELECT DATE(created_at) d, COUNT(*) c FROM likes WHERE created_at>=?
            GROUP BY DATE(created_at) ORDER BY d";
      }
      break;
    case 'attendance':
      if (table_has_col($pdo,'workshop_attendance','checked_in_at')) {
        $q="SELECT DATE(checked_in_at) d, COUNT(*) c FROM workshop_attendance WHERE checked_in_at>=?
            GROUP BY DATE(checked_in_at) ORDER BY d";
      }
      break;
    case 'revenue':
      if (table_has_col($pdo,'workshop_payments','created_at')) {
        $q="SELECT DATE(created_at) d, COALESCE(SUM(amount_cents),0)/100.0 c
            FROM workshop_payments
            WHERE created_at>=? AND status IN ('paid','success')
            GROUP BY DATE(created_at) ORDER BY d";
      }
      break;
  }

  if (!$q) { json_out(['ok'=>true,'items'=>[],'metric'=>$metric,'range'=>$range]); exit; }

  $st=$pdo->prepare($q); $st->execute([$since]);
  $items=[]; foreach($st as $r){ $items[]=['date'=>$r['d'], 'value'=> is_numeric($r['c'])?+$r['c']:$r['c']]; }
  json_out(['ok'=>true,'items'=>$items,'metric'=>$metric,'range'=>$range]);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER','detail'=>$e->getMessage()],500);
}
