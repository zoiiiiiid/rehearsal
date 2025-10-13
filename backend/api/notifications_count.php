<?php
// backend/api/notifications_count.php
require __DIR__.'/db.php';
require __DIR__.'/util.php';
header('Content-Type: application/json');

if (!isset($db) && isset($pdo)) $db = $pdo;

try {
  if (!($db instanceof PDO)) json_out(['error'=>'DB_NOT_AVAILABLE'], 500);

  $me = require_auth($db);
  $uid = $me['id'];

  $st = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :u AND read_at IS NULL");
  $st->execute([':u' => $uid]);
  $unread = (int)$st->fetchColumn();

  json_out(['ok'=>true, 'unread'=>$unread]);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER', 'detail'=>$e->getMessage()], 500);
}
