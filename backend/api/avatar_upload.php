<?php
// backend/api/avatar_upload.php
// Receives multipart form-data field "avatar" and stores a public URL into profiles.avatar_url

require __DIR__ . '/db.php';
require __DIR__ . '/util.php';

// CORS + preflight (web)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
header('Content-Type: application/json');

if (!isset($db) && isset($pdo)) { $db = $pdo; }

try {
  if (!($db instanceof PDO)) json_out(['error' => 'DB_NOT_AVAILABLE'], 500);

  // Auth (accepts Authorization: Bearer ... or token via query/form; see util.php)
  $me = require_auth($db);
  $uid = $me['id'];

  // Helpful diagnostics when uploads are stripped by php.ini
  if (empty($_FILES)) {
    json_out([
      'error' => 'NO_FILES_SUPERGLOBAL',
      'detail' => 'Browser sent multipart, but PHP received no files. Likely post_max_size/upload_max_filesize too small or server blocking.',
      'php_post_max_size' => ini_get('post_max_size'),
      'php_upload_max_filesize' => ini_get('upload_max_filesize')
    ], 400);
  }

  if (empty($_FILES['avatar']) || !is_uploaded_file($_FILES['avatar']['tmp_name'])) {
    json_out(['error' => 'NO_FILE'], 400);
  }

  $file = $_FILES['avatar'];
  if ($file['error'] !== UPLOAD_ERR_OK) {
    json_out([
      'error'  => 'UPLOAD_ERROR',
      'detail' => (string)$file['error'],
      'php_post_max_size' => ini_get('post_max_size'),
      'php_upload_max_filesize' => ini_get('upload_max_filesize')
    ], 400);
  }
  if ($file['size'] > 5 * 1024 * 1024) { // 5 MB
    json_out(['error' => 'TOO_LARGE', 'max' => '5MB'], 413);
  }

  // MIME check
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = finfo_file($finfo, $file['tmp_name']);
  finfo_close($finfo);
  $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
  if (!isset($allowed[$mime])) json_out(['error' => 'BAD_TYPE', 'detail' => $mime], 415);

  // Ensure destination exists
  $root    = dirname(__DIR__);             // .../backend
  $destDir = $root . '/uploads/avatars';
  if (!is_dir($destDir)) { @mkdir($destDir, 0777, true); }
  if (!is_dir($destDir)) { json_out(['error' => 'CANNOT_CREATE_DIR'], 500); }

  // Generate filename (normalize to .jpg if we process)
  $ext   = $allowed[$mime];
  $name  = $uid . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
  $dest  = $destDir . '/' . $name;

  // Try to crop+resize square to 512x512 if GD exists; fallback to move_uploaded_file
  $wrote = false;
  if (function_exists('imagecreatetruecolor')) {
    switch ($mime) {
      case 'image/jpeg': $src = @imagecreatefromjpeg($file['tmp_name']); break;
      case 'image/png':  $src = @imagecreatefrompng($file['tmp_name']);  break;
      case 'image/webp': $src = @imagecreatefromwebp($file['tmp_name']); break;
      default: $src = null;
    }
    if ($src) {
      $w = imagesx($src); $h = imagesy($src); $side = min($w, $h);
      $sx = (int)(($w - $side)/2); $sy = (int)(($h - $side)/2);
      $dst = imagecreatetruecolor(512, 512);
      imagecopyresampled($dst, $src, 0, 0, $sx, $sy, 512, 512, $side, $side);
      // Normalize to JPEG
      $destJpg = preg_replace('/\.[a-z0-9]+$/i', '.jpg', $dest);
      $wrote = imagejpeg($dst, $destJpg, 88);
      if ($wrote) { $dest = $destJpg; $name = basename($destJpg); }
      imagedestroy($dst); imagedestroy($src);
    }
  }
  if (!$wrote) {
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
      json_out(['error' => 'MOVE_FAILED'], 500);
    }
  }

  // Build public URL (e.g., http://localhost/backend/uploads/avatars/xxx.jpg)
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $script = $_SERVER['SCRIPT_NAME'] ?? '/backend/api/avatar_upload.php';
  $base   = dirname(dirname($script));        // /backend
  $public = $base . '/uploads/avatars/' . $name;
  $url    = $scheme . '://' . $host . $public;

  // Ensure profile row exists; persist avatar_url
  $db->prepare('INSERT IGNORE INTO profiles (user_id, created_at) VALUES (:id, NOW())')
     ->execute([':id' => $uid]);
  $db->prepare('UPDATE profiles SET avatar_url = :u WHERE user_id = :id')
     ->execute([':u' => $url, ':id' => $uid]);

  json_out(['ok' => true, 'avatar_url' => $url]);
} catch (Throwable $e) {
  json_out(['error' => 'SERVER', 'detail' => $e->getMessage()], 500);
}