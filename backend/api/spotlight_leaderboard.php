<?php
// backend/api/spotlight_leaderboard.php  (fixed: no HY093)
require __DIR__.'/util.php'; cors();
require __DIR__.'/db.php';
header('Content-Type: application/json');

try {
  $viewer   = user_by_token($pdo, bearer_token() ?: '');
  $viewerId = (int)($viewer['id'] ?? 0);

  $days  = max(1, min(365, (int)($_GET['days']  ?? $_POST['days']  ?? 30)));
  $limit = max(1, min(100, (int)($_GET['limit'] ?? $_POST['limit'] ?? 12)));
  $skill = strtolower(trim((string)($_GET['skill'] ?? $_POST['skill'] ?? 'all')));
  $uidQ  = (string)($_GET['user_id'] ?? $_POST['user_id'] ?? '');

  $since = date('Y-m-d H:i:s', time() - $days * 86400);
  $useSkillFilter = ($skill !== '' && $skill !== 'all');

  // Safe under ONLY_FULL_GROUP_BY
  $displayExpr = "COALESCE(NULLIF(MAX(u.name),''), NULLIF(MAX(pr.username),''), MAX(SUBSTRING_INDEX(u.email,'@',1)))";

  // ---------------- Single-user snapshot ----------------
  if ($uidQ !== '') {
    $whereSkill = $useSkillFilter
      ? " AND EXISTS (SELECT 1 FROM profile_skills ps WHERE ps.user_id=u.id AND ps.skill=:skill) "
      : "";

    $sql = "
      SELECT
        u.id,
        $displayExpr AS display_name,
        MAX(pr.username)   AS username,
        MAX(pr.avatar_url) AS avatar_url,
        /* NOTE: distinct placeholders to avoid HY093 */
        SUM(CASE WHEN v.created_at >= :since_score THEN 1 ELSE 0 END) AS score,
        MAX(CASE WHEN v.voter_id = :viewer AND v.created_at >= :since_voted THEN 1 ELSE 0 END) AS voted
      FROM users u
      LEFT JOIN profiles pr       ON pr.user_id = u.id
      LEFT JOIN spotlight_votes v ON v.target_user_id = u.id
      WHERE u.id = :uid
      $whereSkill
      GROUP BY u.id
      LIMIT 1";

    $params = [
      ':uid'          => $uidQ,
      ':viewer'       => $viewerId,
      ':since_score'  => $since,
      ':since_voted'  => $since,
    ];
    if ($useSkillFilter) $params[':skill'] = $skill;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      json_out(['ok'=>true, 'item'=>null, 'days'=>$days, 'skill'=>$skill]);
    }

    $item = [
      'id'           => $row['id'],
      'display_name' => $row['display_name'],
      'username'     => $row['username'],
      'avatar_url'   => $row['avatar_url'],
      'score'        => (int)$row['score'],
      'voted'        => ((int)$row['voted']) === 1,
    ];

    json_out(['ok'=>true, 'item'=>$item, 'days'=>$days, 'skill'=>$skill]);
  }

  // ---------------- Leaderboard list ----------------
  $joinSkill = $useSkillFilter
    ? "JOIN (SELECT DISTINCT user_id FROM profile_skills WHERE skill = :skill) ps ON ps.user_id = u.id"
    : "";

  $sql = "
    SELECT
      u.id,
      $displayExpr AS display_name,
      MAX(pr.username)   AS username,
      MAX(pr.avatar_url) AS avatar_url,
      /* NOTE: distinct placeholders to avoid HY093 */
      SUM(CASE WHEN v.created_at >= :since_score THEN 1 ELSE 0 END) AS score,
      MAX(CASE WHEN v.voter_id = :viewer AND v.created_at >= :since_voted THEN 1 ELSE 0 END) AS voted
    FROM users u
    LEFT JOIN profiles pr       ON pr.user_id = u.id
    LEFT JOIN spotlight_votes v ON v.target_user_id = u.id
    $joinSkill
    GROUP BY u.id
    ORDER BY score DESC, display_name ASC
    LIMIT $limit";

  $params = [
    ':since_score' => $since,
    ':since_voted' => $since,
    ':viewer'      => $viewerId,
  ];
  if ($useSkillFilter) $params[':skill'] = $skill;

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $items = [];
  foreach ($rows as $r) {
    $items[] = [
      'id'           => $r['id'],
      'display_name' => $r['display_name'],
      'username'     => $r['username'],
      'avatar_url'   => $r['avatar_url'],
      'score'        => (int)$r['score'],
      'voted'        => ((int)$r['voted']) === 1,
    ];
  }

  json_out(['ok'=>true, 'items'=>$items, 'days'=>$days, 'skill'=>$skill]);
} catch (Throwable $e) {
  json_out(['ok'=>false, 'error'=>'SERVER', 'detail'=>$e->getMessage()], 500);
}
