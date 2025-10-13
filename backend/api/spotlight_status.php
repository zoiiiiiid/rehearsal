<?php
// backend/api/spotlight_status.php
require __DIR__.'/util.php'; cors();
require __DIR__.'/db.php';

try {
  $viewer = auth_user_or_null($pdo);             // may be null (then voted=false)
  $viewerId = $viewer['id'] ?? null;

  // accept user_id / target_user_id
  $target = $_GET['user_id'] ?? $_GET['target_user_id']
         ?? $_POST['user_id'] ?? $_POST['target_user_id'] ?? null;
  if (!$target || !is_string($target)) json_out(['error'=>'TARGET_REQUIRED'], 400);

  $days = max(1, min(365, intval($_GET['days'] ?? $_POST['days'] ?? 30)));

  // score in last N days
  $score = (int)$pdo->query(
    "SELECT COUNT(*) FROM spotlight_votes
      WHERE target_user_id = ".$pdo->quote($target)."
        AND created_at >= (NOW() - INTERVAL $days DAY)"
  )->fetchColumn();

  // did the viewer vote today?
  $voted = false;
  if ($viewerId) {
    $st = $pdo->prepare(
      "SELECT 1 FROM spotlight_votes
        WHERE voter_id = :v AND target_user_id = :t
          AND created_at >= CURDATE()
          AND created_at < (CURDATE() + INTERVAL 1 DAY)
        LIMIT 1"
    );
    $st->execute([':v'=>$viewerId, ':t'=>$target]);
    $voted = (bool)$st->fetchColumn();
  }

  json_out(['ok'=>true, 'score'=>$score, 'voted'=>$voted, 'days'=>$days]);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER','detail'=>$e->getMessage()], 500);
}
