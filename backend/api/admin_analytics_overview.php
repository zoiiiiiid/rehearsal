<?php
// backend/api/admin_analytics_overview.php
require __DIR__.'/util.php'; cors(); require __DIR__.'/db.php';

function table_has_col(PDO $pdo, string $t, string $c): bool {
  static $m = []; $k="$t.$c"; if (isset($m[$k])) return $m[$k];
  $st=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
  $st->execute([':t'=>$t, ':c'=>$c]); return $m[$k]=($st->fetchColumn()>0);
}

try {
  $me = require_auth($pdo); // admin only (optional)
  // if (!is_admin($me)) json_out(['error'=>'FORBIDDEN'],403);

  $range = ($_GET['range'] ?? '30d');
  $days = $range==='7d' ? 7 : ($range==='90d' ? 90 : 30);
  $since = (new DateTime("-$days days"))->format('Y-m-d 00:00:00');

  $out = [
    'ok'=>true, 'range'=>$range,
    'active_users'=>0, 'new_users'=>0, 'posts'=>0, 'comments'=>0, 'likes'=>0,
    'workshops'=>0, 'attendance'=>0, 'revenue'=>0.0,
  ];

  // Users
  if (table_has_col($pdo,'users','created_at')) {
    $st=$pdo->prepare("SELECT COUNT(*) FROM users WHERE created_at>=?");
    $st->execute([$since]); $out['new_users']=(int)$st->fetchColumn();
  } else {
    $st=$pdo->query("SELECT COUNT(*) FROM users"); $out['new_users']=(int)$st->fetchColumn();
  }

  // Active users (any post/comment/like in range)
  $active = 0;
  if (table_has_col($pdo,'posts','user_id') && table_has_col($pdo,'posts','created_at')) {
    $st=$pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM posts WHERE created_at>=?");
    $st->execute([$since]); $active += (int)$st->fetchColumn();
  }
  if (table_has_col($pdo,'comments','user_id') && table_has_col($pdo,'comments','created_at')) {
    $st=$pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM comments WHERE created_at>=?");
    $st->execute([$since]); $active += (int)$st->fetchColumn();
  }
  if (table_has_col($pdo,'likes','user_id') && table_has_col($pdo,'likes','created_at')) {
    $st=$pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM likes WHERE created_at>=?");
    $st->execute([$since]); $active += (int)$st->fetchColumn();
  }
  $out['active_users']=$active;

  // Posts / comments / likes
  if (table_has_col($pdo,'posts','created_at')) {
    $st=$pdo->prepare("SELECT COUNT(*) FROM posts WHERE created_at>=?");
    $st->execute([$since]); $out['posts']=(int)$st->fetchColumn();
  }
  if (table_has_col($pdo,'comments','created_at')) {
    $st=$pdo->prepare("SELECT COUNT(*) FROM comments WHERE created_at>=?");
    $st->execute([$since]); $out['comments']=(int)$st->fetchColumn();
  }
  if (table_has_col($pdo,'likes','created_at')) {
    $st=$pdo->prepare("SELECT COUNT(*) FROM likes WHERE created_at>=?");
    $st->execute([$since]); $out['likes']=(int)$st->fetchColumn();
  }

  // Workshops (start time best-effort)
  if (table_has_col($pdo,'workshops','starts_at')) {
    $st=$pdo->prepare("SELECT COUNT(*) FROM workshops WHERE starts_at>=?");
    $st->execute([$since]); $out['workshops']=(int)$st->fetchColumn();
  } else {
    $st=$pdo->query("SELECT COUNT(*) FROM workshops"); $out['workshops']=(int)$st->fetchColumn();
  }

  // Attendance
  if (table_has_col($pdo,'workshop_attendance','checked_in_at')) {
    $st=$pdo->prepare("SELECT COUNT(*) FROM workshop_attendance WHERE checked_in_at>=?");
    $st->execute([$since]); $out['attendance']=(int)$st->fetchColumn();
  }

  // Revenue
  if (table_has_col($pdo,'workshop_payments','amount_cents') && table_has_col($pdo,'workshop_payments','created_at')) {
    $st=$pdo->prepare("SELECT COALESCE(SUM(amount_cents),0) FROM workshop_payments WHERE created_at>=? AND status IN ('paid','success')");
    $st->execute([$since]); $out['revenue']=((int)$st->fetchColumn())/100.0;
  }

  json_out($out);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER','detail'=>$e->getMessage()],500);
}
