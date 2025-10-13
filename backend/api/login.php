<?php
// tolerant (JSON or form), email OR username; returns token or JSON error code
require __DIR__.'/util.php'; cors(); require __DIR__.'/db.php';

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in) || empty($in)) { $in = $_POST; }

// optional probe to inspect input
if (isset($_GET['echo'])) {
  json_out([
    'method'       => $_SERVER['REQUEST_METHOD'] ?? '',
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
    'in'           => $in,
  ]);
}

$login = trim((string)($in['login'] ?? $in['email'] ?? ''));
$pass  = (string)($in['password'] ?? '');
if ($login==='' || $pass==='') json_out(['error'=>'MISSING_FIELDS'],422);

try {
  if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
    $st = $pdo->prepare('SELECT id, password_hash FROM users WHERE email=? LIMIT 1');
    $st->execute([$login]);
  } else {
    $st = $pdo->prepare('SELECT u.id, u.password_hash
                         FROM users u JOIN profiles p ON p.user_id=u.id
                         WHERE p.username=? LIMIT 1');
    $st->execute([strtolower($login)]);
  }
  $user = $st->fetch(PDO::FETCH_ASSOC);
  if (!$user) json_out(['error'=>'INVALID_LOGIN'],401);

  $hash = $user['password_hash'] ?? '';
  if (!$hash || !password_verify($pass, $hash)) json_out(['error'=>'INVALID_PASSWORD'],401);

  $token = bin2hex(random_bytes(16));
  $pdo->prepare('INSERT INTO auth_tokens (user_id, token, expires_at, created_at)
                 VALUES (?,?, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())')
      ->execute([$user['id'], $token]);

  json_out(['token'=>$token]);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER_ERROR','detail'=>$e->getMessage()],500);
}