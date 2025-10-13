<?php
// backend/api/attendance_token.php
require __DIR__.'/util.php'; cors(); require __DIR__.'/db.php';

function hmac_secret(): string {
  // Put this in a safe place; fallback keeps dev moving
  $env = getenv('ATTENDANCE_HMAC_SECRET');
  return $env && strlen($env) >= 16 ? $env : 'dev_demo_secret_change_me';
}

try {
  $me   = require_auth($pdo);
  $uid  = (string)$me['id'];

  $workshopId = trim((string)($_GET['workshop_id'] ?? $_POST['workshop_id'] ?? ''));
  if ($workshopId === '') json_out(['error'=>'BAD_REQUEST','detail'=>'workshop_id required'], 422);

  // Optional: validate workshop exists & is joinable
  $st = $pdo->prepare("SELECT id, title FROM workshops WHERE id = ? LIMIT 1");
  $st->execute([$workshopId]);
  $ws = $st->fetch(PDO::FETCH_ASSOC);
  if (!$ws) json_out(['error'=>'NOT_FOUND','detail'=>'workshop not found'], 404);

  // Token: valid for 10 minutes (adjust to taste)
  $now = time();
  $ttl = 10 * 60;
  $exp = $now + $ttl;

  $nonce = bin2hex(random_bytes(6));
  // payload format (human-friendly, versioned):
  // ATT:v1|<workshop_id>|<user_id>|<exp_epoch>|<nonce>|<sig>
  $base = $workshopId.'|'.$uid.'|'.$exp.'|'.$nonce;
  $sig  = hash_hmac('sha256', $base, hmac_secret());
  $payload = 'ATT:v1|'.$base.'|'.$sig;

  json_out([
    'ok'          => true,
    'workshop_id' => (int)$workshopId,
    'payload'     => $payload,
    'expires_at'  => gmdate('c', $exp),
  ]);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER','detail'=>$e->getMessage()], 500);
}
