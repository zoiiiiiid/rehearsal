<?php
// backend/api/workshop_delete.php
// Delete a workshop (host or admin only)
require __DIR__.'/util.php'; cors(); require __DIR__.'/db.php';
header('Content-Type: application/json');

try {
  if (!isset($db) && isset($pdo)) $db = $pdo;
  if (!($db instanceof PDO)) json_out(['error'=>'DB_NOT_AVAILABLE'],500);

  $me = require_auth($db);
  $uid = (string)$me['id'];
  $id = (string)($_POST['id'] ?? $_GET['id'] ?? '');
  if ($id==='') json_out(['error'=>'WORKSHOP_ID_REQUIRED'],422);

  // load owner
  $st = $db->prepare("SELECT id, host_user_id FROM workshops WHERE id=:id LIMIT 1");
  $st->execute([':id'=>$id]);
  $w = $st->fetch(PDO::FETCH_ASSOC);
  if (!$w) json_out(['error'=>'NOT_FOUND'],404);

  $isAdmin = (strtolower((string)($me['role'] ?? '')) === 'admin');
  if (!$isAdmin && (string)$w['host_user_id'] !== $uid) {
    json_out(['error'=>'FORBIDDEN'],403);
  }

  // delete attendance (if exists), then the workshop
  $db->prepare("DELETE FROM workshop_attendance WHERE workshop_id=:id")->execute([':id'=>$id]);
  $db->prepare("DELETE FROM workshops WHERE id=:id")->execute([':id'=>$id]);

  json_out(['ok'=>true, 'deleted_id'=>(string)$id]);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER','detail'=>$e->getMessage()],500);
}
