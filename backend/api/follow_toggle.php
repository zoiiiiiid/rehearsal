<?php
// backend/api/follow_toggle.php
require __DIR__.'/db.php';
require __DIR__.'/util.php';
header('Content-Type: application/json');

// compat: db.php exposes $pdo; some code uses $db
if (!isset($db) && isset($pdo)) $db = $pdo;

try {
  $viewer = require_auth($db);          // ['id',...]
  $in     = input_json_or_form();

  $targetId =
      ($in['target_id'] ?? null)
   ?? ($in['target']    ?? null)
   ?? ($in['user_id']   ?? null);

  if (!$targetId || !is_string($targetId) || $targetId === '') {
    json_out(['error'=>'TARGET_REQUIRED'], 400);
  }
  if ($targetId === $viewer['id']) {
    json_out(['error'=>'SELF_NOT_ALLOWED'], 400);
  }

  // already following?
  $st  = $db->prepare("SELECT id FROM follows WHERE follower_id=:f AND followed_id=:t LIMIT 1");
  $st->execute([':f'=>$viewer['id'], ':t'=>$targetId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  $following = false;
  if ($row) {
    // UNFOLLOW
    $db->prepare("DELETE FROM follows WHERE id=:id")->execute([':id'=>$row['id']]);
  } else {
    // FOLLOW
    $db->prepare("INSERT INTO follows (follower_id, followed_id, created_at) VALUES (:f,:t,NOW())")
       ->execute([':f'=>$viewer['id'], ':t'=>$targetId]);
    $following = true;

    // notify followee
    $db->prepare("INSERT INTO notifications (user_id, actor_id, type) VALUES (:u,:a,'follow')")
       ->execute([':u'=>$targetId, ':a'=>$viewer['id']]);
  }

  // fresh counts
  $cntFollowers = (int)$db->query("SELECT COUNT(*) FROM follows WHERE followed_id=".$db->quote($targetId))->fetchColumn();
  $cntFollowing = (int)$db->query("SELECT COUNT(*) FROM follows WHERE follower_id=".$db->quote($targetId))->fetchColumn();

  json_out([
    'ok' => true,
    'following' => $following,
    'target_counts' => ['followers'=>$cntFollowers, 'following'=>$cntFollowing],
  ]);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER', 'detail'=>$e->getMessage()], 500);
}
