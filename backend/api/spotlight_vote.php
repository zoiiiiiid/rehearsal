<?php
// backend/api/spotlight_vote.php
// Toggle a spotlight vote in the last ?days (default 30)
// Accepts: target_user_id or target_id

require __DIR__.'/util.php'; cors();
require __DIR__.'/db.php';
header('Content-Type: application/json');

// compat
if (!isset($db) && isset($pdo)) $db = $pdo;
if (!isset($pdo) && isset($db)) $pdo = $db;

try {
  if (!($pdo instanceof PDO)) json_out(['error'=>'DB_NOT_AVAILABLE'], 500);

  $me = require_auth($pdo);
  $viewerId = $me['id'];

  $in = input_json_or_form();
  $targetId = (string)(
      $in['target_user_id']
      ?? $in['target_id']
      ?? $_POST['target_user_id']
      ?? $_POST['target_id']
      ?? ''
  );
  $days  = max(1, (int)($in['days'] ?? $_GET['days'] ?? 30));
  $since = date('Y-m-d H:i:s', time() - $days * 86400);

  if ($targetId === '') json_out(['error'=>'TARGET_REQUIRED'], 400);
  if ($targetId === $viewerId) json_out(['error'=>'SELF_NOT_ALLOWED'], 400);

  // ensure target exists
  $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
  $chk->execute([$targetId]);
  if (!$chk->fetchColumn()) json_out(['error'=>'TARGET_NOT_FOUND'], 404);

  // already voted in window?
  $st = $pdo->prepare("SELECT id FROM spotlight_votes WHERE target_user_id=? AND voter_id=? AND created_at>=? LIMIT 1");
  $st->execute([$targetId, $viewerId, $since]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if ($row) {
    // UNVOTE (toggle off)
    $pdo->prepare("DELETE FROM spotlight_votes WHERE id=?")->execute([$row['id']]);
    $voted = false;
  } else {
    // VOTE
    $ins = $pdo->prepare("INSERT INTO spotlight_votes (target_user_id, voter_id, created_at) VALUES (:t,:v,NOW())");
    $ins->execute([':t'=>$targetId, ':v'=>$viewerId]);
    $voted = true;
  }

  // fresh score within window
  $cnt = $pdo->prepare("SELECT COUNT(*) FROM spotlight_votes WHERE target_user_id=? AND created_at>=?");
  $cnt->execute([$targetId, $since]);
  $score = (int)$cnt->fetchColumn();

  json_out(['ok'=>true, 'voted'=>$voted, 'score'=>$score, 'days'=>$days]);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER','detail'=>$e->getMessage()], 500);
}
