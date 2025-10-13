<?php
require __DIR__.'/util.php'; cors(); require __DIR__.'/db.php';

$h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = null; if (preg_match('/Bearer\s+(.*)$/i', $h, $m)) $token = $m[1];
if (!$token && isset($_GET['token']))  $token = $_GET['token'];
if (!$token && isset($_POST['token'])) $token = $_POST['token'];
if (!$token) json_out(['error'=>'NO_TOKEN'], 401);

$st = $pdo->prepare('SELECT u.id,u.email,u.name,p.username,p.bio,p.avatar_url
                     FROM auth_tokens t
                     JOIN users u ON u.id=t.user_id
                     LEFT JOIN profiles p ON p.user_id=u.id
                     WHERE t.token=? AND t.expires_at > NOW()
                     LIMIT 1');
$st->execute([$token]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) json_out(['error'=>'TOKEN_INVALID'], 401);

json_out(['user'=>$row]);
