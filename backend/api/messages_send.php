<?php
// backend/api/messages_send.php
// Why: create message (text/image) with receipts left NULL (pending).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';
cors(); global $pdo;

$me = require_auth($pdo);

$isMultipart = isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false;
$in = $isMultipart ? $_POST : input_json_or_form();

$receiver_id = trim((string)($in['receiver_id'] ?? ''));
$content     = trim((string)($in['content'] ?? ''));
$type        = $isMultipart ? 'image' : 'text';

if ($receiver_id === '' || $receiver_id === $me['id']) json_out(['error'=>'invalid_receiver'], 422);

$u = $pdo->prepare("SELECT id FROM users WHERE id=? LIMIT 1");
$u->execute([$receiver_id]);
if (!$u->fetch()) json_out(['error'=>'receiver_not_found'], 404);

// canonicalize pair (CHAR(36) friendly)
[$u1,$u2] = (strcmp($me['id'], $receiver_id) <= 0) ? [$me['id'], $receiver_id] : [$receiver_id, $me['id']];

try {
  $pdo->beginTransaction();

  $sel = $pdo->prepare("SELECT id FROM conversations WHERE user1_id=? AND user2_id=? FOR UPDATE");
  $sel->execute([$u1, $u2]);
  $row = $sel->fetch();
  if ($row) {
    $convId = (int)$row['id'];
  } else {
    $ins = $pdo->prepare("INSERT INTO conversations (user1_id, user2_id, created_at, last_message_at) VALUES (?, ?, NOW(), NOW())");
    $ins->execute([$u1, $u2]);
    $convId = (int)$pdo->lastInsertId();
  }

  $media_url = null;
  if ($type === 'image') {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('upload_failed');
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp'], true) || $_FILES['image']['size'] > 2*1024*1024) throw new RuntimeException('bad_image');
    $dir = __DIR__ . '/../uploads/messages';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = 'msg_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], "$dir/$name")) throw new RuntimeException('save_fail');
    $media_url = '/uploads/messages/' . $name;
  } else {
    if ($content === '') json_out(['error'=>'empty_message'], 422);
  }

  // receipts left NULL intentionally
  $insM = $pdo->prepare(
    "INSERT INTO messages
      (conversation_id, sender_id, receiver_id, content, type, media_url, created_at, delivered_at, read_at, is_read)
     VALUES (?, ?, ?, ?, ?, ?, NOW(), NULL, NULL, 0)"
  );
  $insM->execute([$convId, $me['id'], $receiver_id, $content, $type, $media_url]);

  $pdo->prepare("UPDATE conversations SET last_message_at=NOW() WHERE id=?")->execute([$convId]);

  $pdo->commit();
  json_out(['ok'=>true, 'conversation_id'=>$convId, 'message_id'=>(int)$pdo->lastInsertId()], 201);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_out(['error'=>'SQL','detail'=>$e->getMessage()], 500);
}
