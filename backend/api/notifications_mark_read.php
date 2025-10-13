<?php
// backend/api/notifications_mark_read.php
require __DIR__.'/db.php';
require __DIR__.'/util.php';
header('Content-Type: application/json');

if (!isset($db) && isset($pdo)) $db = $pdo;

try {
  if (!($db instanceof PDO)) json_out(['error'=>'DB_NOT_AVAILABLE'], 500);

  $me = require_auth($db);
  $uid = $me['id'];

  // Mark all unread as read
  $st = $db->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = :u AND read_at IS NULL");
  $st->execute([':u' => $uid]);
  $n = $st->rowCount();

  json_out(['ok'=>true, 'marked'=>$n]);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER', 'detail'=>$e->getMessage()], 500);
}
