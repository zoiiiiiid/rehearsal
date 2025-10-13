<?php
require __DIR__.'/db.php';
require __DIR__.'/util.php';
header('Content-Type: application/json');

$t = bearer_token();
$out = ['seen_token' => $t ?: null];

if ($t) {
  $st = $pdo->prepare("SELECT u.id, u.name
                         FROM auth_tokens a JOIN users u ON u.id=a.user_id
                        WHERE a.token=:t AND a.expires_at>NOW() LIMIT 1");
  $st->execute([':t'=>$t]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  $out['valid'] = (bool)$u;
  if ($u) $out['user'] = $u;
} else {
  $out['valid'] = false;
}
echo json_encode($out);
