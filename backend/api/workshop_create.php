<?php
// backend/api/workshop_create.php
// Create a workshop (Admin or Verified). Stores Zoom link and hashes optional access token.

require __DIR__.'/util.php'; cors(); require __DIR__.'/db.php';
header('Content-Type: application/json');

function table_has_col(PDO $pdo, string $t, string $c): bool {
  static $C=[]; $k="$t.$c"; if(isset($C[$k])) return $C[$k];
  $st=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
  $st->execute([':t'=>$t, ':c'=>$c]); return $C[$k] = ((int)$st->fetchColumn() > 0);
}
function pick_col(PDO $db, string $table, array $candidates, string $fallback): string {
  foreach ($candidates as $c) if (table_has_col($db, $table, $c)) return $c;
  return $fallback;
}
function boolish($v): bool {
  if (is_bool($v)) return $v;
  if (is_numeric($v)) return ((int)$v) > 0;
  $s = strtolower(trim((string)$v));
  return in_array($s, ['1','true','yes','on','paid','paid_only'], true);
}

try {
  if (!isset($db) && isset($pdo)) $db = $pdo;
  if (!($db instanceof PDO)) json_out(['error'=>'DB_NOT_AVAILABLE'],500);

  // Admin/verified guard from util.php
  $me  = require_host_or_403($db);
  $uid = (string)$me['id'];

  $in = input_json_or_form();

  // Core fields
  $title        = trim((string)($in['title'] ?? ''));
  $description  = trim((string)($in['description'] ?? $in['desc'] ?? ''));
  $skill        = strtolower(trim((string)($in['skill'] ?? $in['skills'] ?? 'other')));
  $location     = trim((string)($in['location'] ?? 'Online'));
  $starts_at    = trim((string)($in['starts_at'] ?? $in['start_at'] ?? $in['start_time'] ?? ''));
  $ends_at      = trim((string)($in['ends_at']   ?? $in['end_at']   ?? $in['end_time']   ?? ''));
  $capacity     = max(0,  (int)($in['capacity'] ?? 0));
  $price_cents  = max(0,  (int)($in['price_cents'] ?? $in['price'] ?? 0));
  $zoom_link    = trim((string)($in['zoom_link'] ?? $in['zoom'] ?? $in['url'] ?? ''));

  // Access token (paid only) – many possible keys
  $access_token = '';
  foreach (['access_token','access_code','paid_code','secret','code','token'] as $k) {
    if (!empty($in[$k])) { $access_token = (string)$in[$k]; break; }
  }

  if ($title === '' || $starts_at === '' || $zoom_link === '') {
    json_out(['error'=>'MISSING_FIELDS','detail'=>'title, starts_at, zoom_link required'], 422);
  }

  // Normalize/validate skill
  $allowedSkills = ['dj','singer','guitarist','drummer','bassist','keyboardist','dancer','other'];
  if (!in_array($skill, $allowedSkills, true)) $skill = 'other';

  // Determine paid/free from many shapes
  $paid_required = null;
  foreach (['paid_required','paid','is_paid','type','workshop_type'] as $k) {
    if (array_key_exists($k, $in)) { $paid_required = boolish($in[$k]); break; }
  }
  if ($paid_required === null) $paid_required = ($price_cents > 0);
  // If a token was provided, force paid
  if ($access_token !== '') $paid_required = true;

  // Optional cover upload
  $coverUrl = '';
  if (!empty($_FILES['cover']) && is_uploaded_file($_FILES['cover']['tmp_name'])) {
    $f = $_FILES['cover'];
    if ($f['error'] !== UPLOAD_ERR_OK) json_out(['error'=>'UPLOAD_ERROR','detail'=>$f['error']], 400);
    if ($f['size'] > 12*1024*1024)   json_out(['error'=>'TOO_LARGE'], 413);

    $fi   = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fi->file($f['tmp_name']);
    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
      json_out(['error'=>'BAD_TYPE','detail'=>$mime], 415);
    }

    $root = dirname(__DIR__); $dir = $root.'/uploads/workshops';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = $uid.'_'.date('YmdHis').'_'.bin2hex(random_bytes(3)).'.jpg';
    $path = $dir.'/'.$name;

    $did=false;
    if (function_exists('imagecreatetruecolor')) {
      switch ($mime) {
        case 'image/jpeg': $src=@imagecreatefromjpeg($f['tmp_name']); break;
        case 'image/png' : $src=@imagecreatefrompng($f['tmp_name']);  break;
        case 'image/webp': $src=@imagecreatefromwebp($f['tmp_name']); break;
        default:$src=null;
      }
      if ($src) {
        $w=imagesx($src); $h=imagesy($src);
        $max=1600.0; $scale=min(1.0, $max/max($w,$h));
        $nw=(int)round($w*$scale); $nh=(int)round($h*$scale);
        $dst=imagecreatetruecolor($nw,$nh);
        imagecopyresampled($dst,$src,0,0,0,0,$nw,$nh,$w,$h);
        imagejpeg($dst,$path,86);
        imagedestroy($dst); imagedestroy($src);
        $did=true;
      }
    }
    if(!$did){ if(!move_uploaded_file($f['tmp_name'],$path)) json_out(['error'=>'MOVE_FAILED'],500); }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https':'http';
    $host   = $_SERVER['HTTP_HOST'];
    $coverUrl = $scheme.'://'.$host.'/backend/uploads/workshops/'.$name;
  }

  // Column picks (your schema)
  $ownerCol = pick_col($db, 'workshops',
              ['host_user_id','mentor_id','owner_id','host_id','created_by','user_id'],
              'host_user_id');
  $startCol = pick_col($db, 'workshops', ['start_at','starts_at','begin_at'], 'start_at');
  $endCol   = pick_col($db, 'workshops', ['end_at','ends_at','finish_at'], 'end_at');

  // Build INSERT
  $cols = [$ownerCol,'title','description','location','capacity','price_cents','created_at'];
  $vals = [':owner',    ':title',':desc',     ':loc',    ':cap',    ':price',    'NOW()'];
  $cols[] = $startCol; $vals[]=':starts';
  if (!empty($ends_at)) { $cols[] = $endCol; $vals[]=':ends'; }

  if (table_has_col($db,'workshops','skill'))             { $cols[]='skill';             $vals[]=':skill'; }
  if (table_has_col($db,'workshops','cover_url'))         { $cols[]='cover_url';         $vals[]=':cover'; }
  if (table_has_col($db,'workshops','zoom_link'))         { $cols[]='zoom_link';         $vals[]=':zoom'; }
  if (table_has_col($db,'workshops','paid_required'))     { $cols[]='paid_required';     $vals[]=':paid'; }
  if (table_has_col($db,'workshops','access_token_hash')) { $cols[]='access_token_hash'; $vals[]=':tokh'; }

  $sql = "INSERT INTO workshops (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
  $st  = $db->prepare($sql);

  $params = [
    ':owner'=>$uid, ':title'=>$title, ':desc'=>$description, ':loc'=>$location,
    ':cap'=>$capacity, ':price'=>$price_cents, ':starts'=>$starts_at,
  ];
  if (in_array(':ends',$vals,true))   $params[':ends']  = $ends_at;
  if (in_array(':skill',$vals,true))  $params[':skill'] = $skill;
  if (in_array(':cover',$vals,true))  $params[':cover'] = $coverUrl;
  if (in_array(':zoom',$vals,true))   $params[':zoom']  = $zoom_link;
  if (in_array(':paid',$vals,true))   $params[':paid']  = $paid_required ? 1 : 0;

  $access_token_hash = null;
  if ($paid_required && $access_token !== '') $access_token_hash = hash('sha256', $access_token);
  if (in_array(':tokh',$vals,true)) $params[':tokh'] = $access_token_hash;

  $st->execute($params);
  $wid = (string)$db->lastInsertId();

  json_out(['ok'=>true, 'workshop'=>[
    'id'=>$wid,
    'title'=>$title,
    'starts_at'=>$starts_at,
    'ends_at'=>$ends_at ?: null,
    'capacity'=>$capacity,
    'price_cents'=>$price_cents,
    'paid_required'=>$paid_required ? true : false,
    'cover_url'=>$coverUrl,
    'zoom_link'=>$zoom_link,
    'requires_token'=>($paid_required && $access_token_hash!==null),
  ]]);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER','detail'=>$e->getMessage()], 500);
}
