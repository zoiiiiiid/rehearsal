<?php
require __DIR__.'/util.php'; cors(); require __DIR__.'/db.php';

$raw = file_get_contents('php://input');
$in = json_decode($raw, true); if(!is_array($in) || empty($in)) $in = $_POST;

$name = trim((string)($in['name'] ?? ''));
$email = trim((string)($in['email'] ?? ''));
$username = strtolower(trim((string)($in['username'] ?? '')));
$pass = (string)($in['password'] ?? '');
if ($name==='' || $email==='' || $username==='' || $pass==='') json_out(['error'=>'MISSING_FIELDS'],422);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error'=>'INVALID_EMAIL'],422);
if (!preg_match('/^[a-z0-9_\.]{3,20}$/i', $username)) json_out(['error'=>'INVALID_USERNAME'],422);

// Unique checks
$st=$pdo->prepare('SELECT 1 FROM users WHERE email=? LIMIT 1'); $st->execute([$email]); if($st->fetch()) json_out(['error'=>'EMAIL_TAKEN'],409);
$st=$pdo->prepare('SELECT 1 FROM profiles WHERE username=? LIMIT 1'); $st->execute([$username]); if($st->fetch()) json_out(['error'=>'USERNAME_TAKEN'],409);

$hash = password_hash($pass, PASSWORD_BCRYPT);
$pdo->beginTransaction();
try{
  // UUID PK + created_at for your schema
  $pdo->prepare('INSERT INTO users (id,email,password_hash,name,created_at)
                 VALUES (UUID(),?,?,?,NOW())')
      ->execute([$email,$hash,$name]);
  // Fetch the UUID we just created
  $uid = $pdo->query('SELECT id FROM users WHERE email='.$pdo->quote($email).' LIMIT 1')->fetchColumn();

  $pdo->prepare('INSERT INTO profiles (user_id,username,bio,avatar_url,created_at)
                 VALUES (?,?,"","",NOW())')->execute([$uid,$username]);
  $pdo->commit();
  json_out(['ok'=>true]);
}catch(PDOException $e){
  $pdo->rollBack();
  if ($e->getCode()==='23000') {
    $msg = $e->getMessage();
    if (stripos($msg,'users')!==false && stripos($msg,'email')!==false) json_out(['error'=>'EMAIL_TAKEN'],409);
    if (stripos($msg,'profiles')!==false && stripos($msg,'username')!==false) json_out(['error'=>'USERNAME_TAKEN'],409);
  }
  json_out(['error'=>'SERVER_ERROR','detail'=>$e->getMessage()],500);
}
