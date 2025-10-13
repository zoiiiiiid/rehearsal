<?php
// backend/api/qr_issue.php
// Issue a short-lived QR token for check-in. Host/Admin only.
// Works for paid workshops (or any, if you want — currently blocks free).

require __DIR__.'/util.php'; cors(); require __DIR__.'/db.php';
header('Content-Type: application/json');

function col_exists(PDO $db, string $table, string $col): bool {
  try { $st=$db->prepare("SHOW COLUMNS FROM `$table` LIKE :c"); $st->execute([':c'=>$col]); return (bool)$st->fetch(PDO::FETCH_ASSOC); }
  catch(Throwable $e){ return false; }
}
function pick_col(PDO $db, string $table, array $cands, ?string $fallback=null): ?string {
  foreach ($cands as $c) if (col_exists($db,$table,$c)) return $c;
  return $fallback;
}
function base_origin(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return $scheme.'://'.$host;
}
function b64url(string $bin): string {
  return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

try {
  if (!isset($db) && isset($pdo)) $db = $pdo;
  if (!($db instanceof PDO)) json_out(['error'=>'DB_NOT_AVAILABLE'],500);

  // must be logged in; must be host or admin
  $me  = require_auth($db);
  $uid = (string)$me['id'];
  $isAdmin = strtolower((string)($me['role'] ?? '')) === 'admin';

  // tolerate id/workshop_id via JSON, form, or query
  $in = input_json_or_form();
  $wid = (string)(
            $in['workshop_id'] ?? $in['id'] ??
            ($_POST['workshop_id'] ?? $_POST['id'] ?? '') ??
            ($_GET['workshop_id']   ?? $_GET['id']   ?? '')
         );
  if ($wid === '') json_out(['error'=>'WORKSHOP_ID_REQUIRED'], 422);

  // column picks
  $ownerCol = pick_col($db,'workshops',['host_user_id','mentor_id','owner_id','host_id','created_by','user_id'], 'host_user_id');
  $priceCol = pick_col($db,'workshops',['price_cents']);
  $paidCol  = pick_col($db,'workshops',['paid_required']);

  // coalesced paid expression
  $priceExpr = $priceCol ? "COALESCE(w.`$priceCol`,0)" : "0";
  if ($paidCol && $priceCol) {
    $paidExpr = "COALESCE(w.`$paidCol`, CASE WHEN $priceExpr > 0 THEN 1 ELSE 0 END)";
  } elseif ($paidCol) {
    $paidExpr = "COALESCE(w.`$paidCol`,0)";
  } else {
    $paidExpr = "(CASE WHEN $priceExpr > 0 THEN 1 ELSE 0 END)";
  }

  $st = $db->prepare("
    SELECT w.`id`, w.`$ownerCol` AS host_user_id,
           $priceExpr AS price_cents,
           $paidExpr  AS paid_required
      FROM workshops w
     WHERE w.`id` = :id
     LIMIT 1
  ");
  $st->execute([':id'=>$wid]);
  $w = $st->fetch(PDO::FETCH_ASSOC);
  if (!$w) json_out(['error'=>'NOT_FOUND'], 404);

  $isHost = !empty($w['host_user_id']) && (string)$w['host_user_id'] === $uid;
  if (!$isAdmin && !$isHost) json_out(['error'=>'FORBIDDEN'], 403);

  $paid = ((int)($w['paid_required'] ?? 0)) === 1;
  if (!$paid) {
    // Block QR on free — change to a warning if you want to allow it.
    json_out(['error'=>'FREE_WORKSHOP_NO_QR'], 422);
  }

  // Build short-lived JWT-like token: header.payload.signature (HS256)
  $secret = hmac_secret();
  $hdr = b64url(json_encode(['alg'=>'HS256','typ'=>'JWT']));
  $exp = time() + 5*60; // 5 min
  $payload = ['wid'=>$wid, 'iss'=>'rehearsal', 'exp'=>$exp, 'aid'=>$uid];
  $pl = b64url(json_encode($payload));
  $sig = b64url(hash_hmac('sha256', "$hdr.$pl", $secret, true));
  $token = "$hdr.$pl.$sig";

  $qrUrl = base_origin().'/backend/api/workshop_access.php?token='.$token;

  json_out(['ok'=>true, 'token'=>$token, 'qr_url'=>$qrUrl, 'expires'=>$exp]);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER','detail'=>$e->getMessage()], 500);
}
