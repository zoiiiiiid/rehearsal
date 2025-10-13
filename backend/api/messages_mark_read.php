<?php
// backend/api/messages_mark_read.php
// Why: set read receipt up to last_id for this viewer.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';
cors(); global $pdo;

$me = require_auth($pdo);
$in = input_json_or_form();

$conv = (int)($in['conversation_id'] ?? 0);
$last = (int)($in['last_id'] ?? 0);
if ($conv <= 0 || $last <= 0) json_out(['error'=>'bad_payload'], 422);

$own = $pdo->prepare("SELECT 1 FROM conversations WHERE id=? AND (user1_id=? OR user2_id=?)");
$own->execute([$conv, $me['id'], $me['id']]);
if (!$own->fetch()) json_out(['error'=>'FORBIDDEN'], 403);

try {
  $st = $pdo->prepare(
    "UPDATE messages
        SET read_at = IFNULL(read_at, NOW()), is_read = 1
      WHERE conversation_id = :cid
        AND receiver_id     = :me
        AND id <= :last
        AND read_at IS NULL"
  );
  $st->execute([':cid'=>$conv, ':me'=>$me['id'], ':last'=>$last]);
  json_out(['ok'=>true, 'updated'=>$st->rowCount()]);
} catch (Throwable $e) {
  json_out(['error'=>'SQL','detail'=>$e->getMessage()], 500);
}
