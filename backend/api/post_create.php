<?php
// backend/api/post_create.php
// Multipart create: media (required), caption (optional), skill (REQUIRED)

require __DIR__.'/db.php';
require __DIR__.'/util.php';

header('Content-Type: application/json');
if (!isset($db) && isset($pdo)) { $db = $pdo; }

try {
  if (!($db instanceof PDO)) json_out(['error'=>'DB_NOT_AVAILABLE'], 500);

  // must be logged in
  $me = require_auth($db);
  $uid = $me['id'];

  // read fields from multipart/JSON/form
  $in = input_json_or_form();
  $caption = trim((string)($in['caption'] ?? ''));

  // ---- REQUIRED: skill -----------------------------------------------------
  // Make sure your DB has: ALTER TABLE posts ADD COLUMN skill VARCHAR(32) NULL;
  $allowedSkills = ['dj','singer','guitarist','drummer','bassist','keyboardist','dancer','other'];
  $skill = strtolower(trim((string)($in['skill'] ?? '')));
  if ($skill === '' || !in_array($skill, $allowedSkills, true)) {
    json_out([
      'error'  => 'SKILL_REQUIRED',
      'detail' => 'Provide one of: '.implode(', ', $allowedSkills)
    ], 422);
  }

  // ---- File ---------------------------------------------------------------
  if (empty($_FILES['media']) || !is_uploaded_file($_FILES['media']['tmp_name'])) {
    json_out(['error'=>'NO_FILE'], 400);
  }
  $file = $_FILES['media'];
  if ($file['error'] !== UPLOAD_ERR_OK) json_out(['error'=>'UPLOAD_ERROR','detail'=>'code='.$file['error']], 400);
  if ($file['size'] > 50*1024*1024)      json_out(['error'=>'TOO_LARGE'], 413);

  $fi = new finfo(FILEINFO_MIME_TYPE);
  $mime = $fi->file($file['tmp_name']);

  // allow jpeg/png/webp images and mp4 videos (safe default)
  $isImage = in_array($mime, ['image/jpeg','image/png','image/webp'], true);
  $isVideo = in_array($mime, ['video/mp4'], true);
  if (!$isImage && !$isVideo) json_out(['error'=>'BAD_TYPE','detail'=>$mime], 415);

  $root = dirname(__DIR__);                 // .../backend
  $dir  = $root.'/uploads/posts';
  if (!is_dir($dir)) mkdir($dir, 0775, true);

  // We store images as JPEG for simplicity; videos keep .mp4
  $ext = $isImage ? 'jpg' : 'mp4';
  $name = $uid.'_'.date('YmdHis').'_'.bin2hex(random_bytes(3)).'.'.$ext;
  $path = $dir.'/'.$name;

  // Basic downscale for images if GD available; else move as-is
  $didProcess = false;
  if ($isImage && function_exists('imagecreatetruecolor')) {
    switch ($mime) {
      case 'image/jpeg': $src = @imagecreatefromjpeg($file['tmp_name']); break;
      case 'image/png':  $src = @imagecreatefrompng($file['tmp_name']);  break;
      case 'image/webp': $src = @imagecreatefromwebp($file['tmp_name']); break;
      default:           $src = null;
    }
    if ($src) {
      $w = imagesx($src); $h = imagesy($src);
      $max = 1080.0; $scale = min(1.0, $max / max($w, $h));
      $nw = (int)round($w * $scale); $nh = (int)round($h * $scale);
      $dst = imagecreatetruecolor($nw, $nh);
      imagecopyresampled($dst, $src, 0,0,0,0, $nw,$nh, $w,$h);
      imagejpeg($dst, $path, 88);
      imagedestroy($dst); imagedestroy($src);
      $didProcess = true;
    }
  }
  if (!$didProcess) {
    if (!move_uploaded_file($file['tmp_name'], $path)) {
      json_out(['error'=>'MOVE_FAILED'], 500);
    }
  }

  // public URL
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'];
  $url    = $scheme.'://'.$host.'/backend/uploads/posts/'.$name;

  // insert
  $st = $db->prepare("INSERT INTO posts (user_id, media_url, caption, skill, created_at)
                      VALUES (:u,:m,:c,:s,NOW())");
  $st->execute([':u'=>$uid, ':m'=>$url, ':c'=>$caption, ':s'=>$skill]);
  $postId = (int)$db->lastInsertId();

  json_out(['ok'=>true, 'post'=>[
    'id'=>$postId, 'media_url'=>$url, 'caption'=>$caption, 'skill'=>$skill
  ]]);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER','detail'=>$e->getMessage()], 500);
}
