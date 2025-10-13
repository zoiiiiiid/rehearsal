<?php
// backend/api/profile_avatar_upload.php
require __DIR__ . '/db.php';
require __DIR__ . '/util.php';

header('Content-Type: application/json');

// $db might be $pdo in your setup
if (!isset($db) && isset($pdo)) { $db = $pdo; }

try {
  $user = require_auth($db);
  $uid = $user['id'];

  if (empty($_FILES['avatar']) || !is_uploaded_file($_FILES['avatar']['tmp_name'])) {
    json_out(['error' => 'NO_FILE'], 400);
  }

  $file = $_FILES['avatar'];
  if ($file['error'] !== UPLOAD_ERR_OK) {
    json_out(['error' => 'UPLOAD_ERROR', 'detail' => (string)$file['error']], 400);
  }

  // Basic checks
  $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
  if (!isset($allowed[$mime])) {
    json_out(['error' => 'BAD_TYPE', 'detail' => $mime], 415);
  }

  if ($file['size'] > 5 * 1024 * 1024) { // 5MB
    json_out(['error' => 'TOO_LARGE'], 413);
  }

  // Destination
  $root    = dirname(__DIR__);            // ..../backend
  $destDir = $root . '/uploads/avatars';
  if (!is_dir($destDir)) { @mkdir($destDir, 0777, true); }
  if (!is_dir($destDir)) { json_out(['error'=>'CANNOT_CREATE_DIR'], 500); }

  $ext   = $allowed[$mime];
  $fname = $uid . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $dest  = $destDir . '/' . $fname;

  if (!move_uploaded_file($file['tmp_name'], $dest)) {
    json_out(['error' => 'MOVE_FAILED'], 500);
  }

  // Store URL in profiles.avatar_url (create profile row if missing)
  $db->prepare("INSERT IGNORE INTO profiles (user_id, created_at) VALUES (:id, NOW())")
     ->execute([':id' => $uid]);

  // Build absolute URL like http://localhost/backend/uploads/avatars/xxx.jpg
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $public = '/backend/uploads/avatars/' . $fname;
  $full   = $scheme . '://' . $host . $public;

  $db->prepare("UPDATE profiles SET avatar_url = :u WHERE user_id = :id")
     ->execute([':u' => $full, ':id' => $uid]);

  json_out(['ok' => true, 'avatar_url' => $full]);
} catch (Throwable $e) {
  json_out(['error' => 'SERVER', 'detail' => $e->getMessage()], 500);
}
