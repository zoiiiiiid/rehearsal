<?php
// backend/api/email_available.php
// Simple email availability checker for registration/profile edit.
require __DIR__.'/util.php'; cors(); require __DIR__.'/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
  $email = trim((string)($_GET['email'] ?? $_POST['email'] ?? ''));
  if ($email === '') {
    json_out(['available'=>false, 'error'=>'MISSING_EMAIL'], 422);
  }
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(['available'=>false, 'error'=>'INVALID_FORMAT'], 422);
  }

  $st = $pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
  $st->execute([$email]);
  $exists = (bool)$st->fetchColumn();

  json_out(['available'=>!$exists]); // true = can use, false = already taken
} catch (Throwable $e) {
  json_out(['available'=>false, 'error'=>'SERVER', 'detail'=>$e->getMessage()], 500);
}
