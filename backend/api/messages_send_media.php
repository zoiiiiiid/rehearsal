<?php
// backend/api/messages_send_media.php
require __DIR__.'/util.php'; cors(); require __DIR__.'/db.php';

function table_has_col(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $key = $table.'.'.$column;
  if (array_key_exists($key, $cache)) return $cache[$key];
  $sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = :t
            AND COLUMN_NAME = :c";
  $st = $pdo->prepare($sql);
  $st->execute([':t'=>$table, ':c'=>$column]);
  $cache[$key] = ($st->fetchColumn() > 0);
  return $cache[$key];
}

try {
  $me   = require_auth($pdo);
  $myId = (string)$me['id'];

  $in         = input_json_or_form();
  $convId     = trim((string)($in['conversation_id'] ?? ''));
  $receiverId = trim((string)($in['receiver_id']    ?? ''));
  $caption    = trim((string)($in['caption']        ?? ''));

  if ($convId === '' && $receiverId === '') {
    json_out(['error'=>'BAD_REQUEST','detail'=>'conversation_id or receiver_id required'], 422);
  }

  if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    json_out(['error'=>'NO_FILE','detail'=>'No file field present'], 422);
  }

  // Detect conversation columns
  $hasConvUsersA = table_has_col($pdo, 'conversations', 'user_a_id');
  $hasConvUsersB = table_has_col($pdo, 'conversations', 'user_b_id');
  $hasConvIsGrp  = table_has_col($pdo, 'conversations', 'is_group');

  // ---- Resolve/create conversation if only receiver_id provided ----
  if ($convId === '' && $receiverId !== '') {
    if ($receiverId === $myId) {
      json_out(['error'=>'BAD_REQUEST','detail'=>'cannot DM yourself'], 422);
    }
    if (!($hasConvUsersA && $hasConvUsersB)) {
      json_out(['error'=>'UNSUPPORTED','detail'=>'conversations has no user_a_id/user_b_id; pass conversation_id instead'], 422);
    }

    // Find or create 1:1
    $sql = "SELECT id FROM conversations
            WHERE ".($hasConvIsGrp ? "is_group=0 AND " : "")."
                  ((user_a_id=:a AND user_b_id=:b) OR (user_a_id=:b AND user_b_id=:a))
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':a'=>$myId, ':b'=>$receiverId]);
    $cid = $st->fetchColumn();

    if (!$cid) {
      $ins = "INSERT INTO conversations ("
           . ($hasConvIsGrp ? "is_group, " : "")
           . "user_a_id, user_b_id, created_at"
           . (table_has_col($pdo,'conversations','updated_at') ? ", updated_at" : "")
           . ") VALUES ("
           . ($hasConvIsGrp ? "0, " : "")
           . ":a, :b, NOW()"
           . (table_has_col($pdo,'conversations','updated_at') ? ", NOW()" : "")
           . ")";
      $st = $pdo->prepare($ins);
      $st->execute([':a'=>$myId, ':b'=>$receiverId]);
      $cid = $pdo->lastInsertId();
    }
    $convId = (string)$cid;
  }

  // ---- Optional: fetch conv meta if we can ----
  $isGroup = false;
  $derivedReceiver = ($receiverId !== '') ? $receiverId : null;

  if ($convId !== '') {
    if ($hasConvIsGrp || ($hasConvUsersA && $hasConvUsersB)) {
      $fields = ['id'];
      if ($hasConvIsGrp)  $fields[] = 'is_group';
      if ($hasConvUsersA) $fields[] = 'user_a_id';
      if ($hasConvUsersB) $fields[] = 'user_b_id';

      $st = $pdo->prepare("SELECT ".implode(',', $fields)." FROM conversations WHERE id=? LIMIT 1");
      $st->execute([$convId]);
      $conv = $st->fetch(PDO::FETCH_ASSOC);
      if (!$conv) json_out(['error'=>'NOT_FOUND','detail'=>'conversation not found'], 404);

      $isGroup = $hasConvIsGrp ? (bool)$conv['is_group'] : false;

      if (!$isGroup && $derivedReceiver === null && $hasConvUsersA && $hasConvUsersB) {
        $a = (string)$conv['user_a_id'];
        $b = (string)$conv['user_b_id'];
        $derivedReceiver = ($a === $myId) ? $b : $a;
      }
    }
  }

  // ---- If receiver_id still unknown and schema requires it, try infer from last message ----
  $hasReceiverId = table_has_col($pdo, 'messages', 'receiver_id');
  if ($hasReceiverId && $derivedReceiver === null) {
    // Look at last message in this conversation to find the opposite party
    $st = $pdo->prepare("SELECT sender_id, receiver_id FROM messages WHERE conversation_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$convId]);
    $last = $st->fetch(PDO::FETCH_ASSOC);
    if ($last) {
      $senderLast   = (string)($last['sender_id'] ?? '');
      $receiverLast = (string)($last['receiver_id'] ?? '');
      if ($senderLast === $myId && $receiverLast !== '') {
        $derivedReceiver = $receiverLast;
      } elseif ($senderLast !== '' && $senderLast !== $myId) {
        $derivedReceiver = $senderLast;
      }
    }
  }

  // If messages.receiver_id is NOT NULL and we still don't know it, fail early with a clear error
  if ($hasReceiverId) {
    // Check nullability: simplest is try to insert null => DB would fail; give a friendly message instead.
    if ($derivedReceiver === null && ! $isGroup) {
      json_out(['error'=>'BAD_REQUEST','detail'=>'receiver_id required for this schema; pass it explicitly or add user_a_id/user_b_id in conversations'], 422);
    }
  }

  // ---- File validation & save ---------------------------------------------
  $f    = $_FILES['file'];
  $tmp  = $f['tmp_name'];
  $orig = $f['name'] ?? 'upload.bin';

  $fi   = new finfo(FILEINFO_MIME_TYPE);
  $mime = $fi->file($tmp) ?: 'application/octet-stream';

  $allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'video/mp4'  => 'mp4',
  ];
  if (!isset($allowed[$mime])) {
    json_out(['error'=>'UNSUPPORTED_MEDIA','detail'=>$mime], 415);
  }
  $ext  = $allowed[$mime];
  $type = str_starts_with($mime, 'image/') ? 'image' : 'video';

  $dir = __DIR__ . '/../uploads/messages';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  $base     = bin2hex(random_bytes(8));
  $filename = $base . '.' . $ext;
  $path     = $dir . '/' . $filename;

  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $apiPath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
  $webRoot = rtrim(dirname($apiPath), '/\\');
  $url    = sprintf('%s://%s%s/uploads/messages/%s', $scheme, $host, $webRoot, $filename);

  if (!move_uploaded_file($tmp, $path)) {
    json_out(['error'=>'UPLOAD_MOVE_FAILED'], 500);
  }

  $content = $caption !== '' ? $caption : ($type === 'image' ? '[image]' : '[video]');

  // messages table dynamic columns
  $hasMediaUrl  = table_has_col($pdo, 'messages', 'media_url');
  $hasMediaType = table_has_col($pdo, 'messages', 'media_type');
  $hasCreatedAt = table_has_col($pdo, 'messages', 'created_at');

  $cols = ['conversation_id', 'sender_id', 'content'];
  $vals = [':cid', ':sid', ':content'];
  $args = [':cid'=>$convId, ':sid'=>$myId, ':content'=>$content];

  if ($hasMediaUrl)   { $cols[]='media_url';  $vals[]=':url';  $args[':url']=$url; }
  if ($hasMediaType)  { $cols[]='media_type'; $vals[]=':type'; $args[':type']=$type; }
  if ($hasReceiverId) { $cols[]='receiver_id'; $vals[]=':rid';  $args[':rid']=$isGroup ? null : $derivedReceiver; }
  if ($hasCreatedAt)  { $cols[]='created_at'; $vals[]='NOW()'; }

  $pdo->beginTransaction();
  try {
    $sql = 'INSERT INTO messages ('.implode(',', $cols).') VALUES ('.implode(',', $vals).')';
    $st  = $pdo->prepare($sql);
    $st->execute($args);
    $messageId = (int)$pdo->lastInsertId();

    if (table_has_col($pdo,'conversations','updated_at')) {
      $pdo->prepare("UPDATE conversations SET updated_at=NOW() WHERE id=?")->execute([$convId]);
    }

    $pdo->commit();
    json_out([
      'ok'              => true,
      'conversation_id' => (int)$convId,
      'message_id'      => $messageId,
      'url'             => $url,
      'type'            => $type,
    ]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @unlink($path);
    json_out(['error'=>'SERVER','detail'=>$e->getMessage()], 500);
  }
} catch (Throwable $e) {
  json_out(['error'=>'SERVER','detail'=>$e->getMessage()], 500);
}
