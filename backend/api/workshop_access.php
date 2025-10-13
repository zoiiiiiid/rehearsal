<?php
// backend/api/workshop_access.php
// New: Always allow joining. No payment or token checks.
// Optionally enforces capacity; returns a join URL (Zoom/Meet).

require __DIR__.'/util.php'; cors(); require __DIR__.'/db.php';
header('Content-Type: application/json');

function table_has_col(PDO $pdo, string $t, string $c): bool {
  static $C=[]; $k="$t.$c"; if(isset($C[$k])) return $C[$k];
  $st=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
  $st->execute([':t'=>$t, ':c'=>$c]); return $C[$k] = ((int)$st->fetchColumn() > 0);
}
function first_url(array $w): ?string {
  foreach (['zoom_join_url','zoom_link','zoom_start_url'] as $k)
    if (!empty($w[$k])) return (string)$w[$k];
  return null;
}

try {
  if (!isset($db) && isset($pdo)) $db = $pdo;
  if (!($db instanceof PDO)) json_out(['error'=>'DB_NOT_AVAILABLE'],500);

  // still require auth to attribute attendance
  $me = require_auth($db);
  $uid = (string)$me['id'];

  // robust read of workshop id
  $in = input_json_or_form();
  $wid = (string)($in['workshop_id'] ?? $in['id'] ??
                  $_POST['workshop_id'] ?? $_POST['id'] ??
                  $_GET['workshop_id'] ?? $_GET['id'] ?? '');
  if ($wid === '') {
    $raw = file_get_contents('php://input');  // final fallback
    $j = json_decode($raw ?: '', true);
    if (is_array($j)) $wid = (string)($j['workshop_id'] ?? $j['id'] ?? '');
  }
  if ($wid === '') json_out(['error'=>'WORKSHOP_ID_REQUIRED'], 422);

  // load workshop
  $cols = ['w.id'];
  foreach (['capacity','zoom_link','zoom_join_url','zoom_start_url'] as $c) {
    $cols[] = table_has_col($db,'workshops',$c) ? "w.`$c` AS `$c`" : "NULL AS `$c`";
  }
  $st = $db->prepare("SELECT ".implode(',', $cols)." FROM workshops w WHERE w.id=? LIMIT 1");
  $st->execute([$wid]);
  $w = $st->fetch(PDO::FETCH_ASSOC);
  if (!$w) json_out(['error'=>'NOT_FOUND'], 404);

  // optional capacity enforcement (keep it – or comment out this block)
  $cap = ($w['capacity'] === null) ? 0 : (int)$w['capacity'];
  if ($cap > 0) {
    $cnt = (int)$db->query(
      "SELECT COUNT(*) FROM workshop_attendance WHERE workshop_id=".$db->quote($wid)
    )->fetchColumn();
    if ($cnt >= $cap) json_out(['error'=>'FULL'], 409);
  }

  // idempotent attendance record
  if (table_has_col($db,'workshop_attendance','workshop_id')) {
    $db->prepare("INSERT IGNORE INTO workshop_attendance (workshop_id,user_id,checked_in_at) VALUES (?,?,NOW())")
       ->execute([$wid, $uid]);
  }

  $joinUrl = first_url($w);
  json_out(['ok'=>true, 'workshop_id'=>$wid, 'join_url'=>$joinUrl]);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER','detail'=>$e->getMessage()], 500);
}
