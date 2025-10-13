<?php
// backend/api/messages_list.php
// Why: list messages and stamp delivered_at for any newly seen inbound messages for this user.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';
cors(); global $pdo;

$me = require_auth($pdo);

$conversation_id = (int)($_GET['conversation_id'] ?? 0);
$since_id        = (int)($_GET['since_id'] ?? 0);
$limit           = (int)($_GET['limit'] ?? 50);
if ($limit <= 0 || $limit > 200) $limit = 50;

if ($conversation_id <= 0) json_out(['error'=>'conversation_id_required'], 422);

$own = $pdo->prepare("SELECT 1 FROM conversations WHERE id=? AND (user1_id=? OR user2_id=?)");
$own->execute([$conversation_id, $me['id'], $me['id']]);
if (!$own->fetch()) json_out(['error'=>'FORBIDDEN'], 403);

try {
  // Mark any of MY incoming messages in this conversation as delivered.
  // (Do this first so results include delivered_at.)
  $upd = $pdo->prepare(
    "UPDATE messages
       SET delivered_at = IFNULL(delivered_at, NOW())
     WHERE conversation_id = :cid
       AND receiver_id     = :me
       AND delivered_at IS NULL"
  );
  $upd->execute([':cid'=>$conversation_id, ':me'=>$me['id']]);

  if ($since_id > 0) {
    $st = $pdo->prepare(
      "SELECT id, conversation_id, sender_id, receiver_id, content, type, media_url,
              created_at, delivered_at, read_at, is_read
         FROM messages
        WHERE conversation_id=:cid AND id > :since
        ORDER BY id ASC
        LIMIT :lim"
    );
    $st->bindValue(':cid',   $conversation_id, PDO::PARAM_INT);
    $st->bindValue(':since', $since_id,        PDO::PARAM_INT);
    $st->bindValue(':lim',   $limit,           PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    json_out(['ok'=>true, 'items'=>$rows]);
  } else {
    $st = $pdo->prepare(
      "SELECT id, conversation_id, sender_id, receiver_id, content, type, media_url,
              created_at, delivered_at, read_at, is_read
         FROM messages
        WHERE conversation_id=:cid
        ORDER BY id DESC
        LIMIT :lim"
    );
    $st->bindValue(':cid', $conversation_id, PDO::PARAM_INT);
    $st->bindValue(':lim', $limit,           PDO::PARAM_INT);
    $st->execute();
    $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    json_out(['ok'=>true, 'items'=>$rows]);
  }
} catch (Throwable $e) {
  json_out(['error'=>'SQL','detail'=>$e->getMessage()], 500);
}
