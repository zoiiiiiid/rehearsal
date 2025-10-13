<?php
// backend/api/admin_pending_list.php
require __DIR__ . '/util.php'; cors(); require __DIR__ . '/db.php';

try {
  $me = require_auth($pdo);
  if (($me['role'] ?? '') !== 'admin') {
    json_out(['error' => 'FORBIDDEN'], 403);
  }

  $q     = trim((string)($_GET['q'] ?? ''));
  $page  = max(1, (int)($_GET['page'] ?? 1));
  $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
  $offset = ($page - 1) * $limit;

  // Base SELECT
  $sql = "SELECT u.id,
                 u.role,
                 u.status,
                 u.name            AS display_name,
                 p.username        AS username,
                 p.avatar_url      AS avatar_url
          FROM users u
          LEFT JOIN profiles p ON p.user_id = u.id
          WHERE u.status = 'pending'";

  // Build optional search
  $params = [];
  if ($q !== '') {
    // Support @username shorthand; otherwise search both name & username
    $needle = ltrim($q, '@');
    $like   = "%$needle%";
    $sql   .= " AND (u.name LIKE :q1 OR p.username LIKE :q2)";
    $params[':q1'] = $like;
    $params[':q2'] = $like;
  }

  $sql .= " ORDER BY u.created_at DESC
            LIMIT :limit OFFSET :offset";

  $st = $pdo->prepare($sql);
  foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
  $st->bindValue(':limit',  $limit,  PDO::PARAM_INT);
  $st->bindValue(':offset', $offset, PDO::PARAM_INT);
  $st->execute();
  $items = $st->fetchAll(PDO::FETCH_ASSOC);

  // Total for pagination
  $countSql = "SELECT COUNT(*) 
               FROM users u
               LEFT JOIN profiles p ON p.user_id = u.id
               WHERE u.status = 'pending'";
  if ($q !== '') {
    $countSql .= " AND (u.name LIKE :q1 OR p.username LIKE :q2)";
  }
  $ct = $pdo->prepare($countSql);
  if ($q !== '') {
    $needle = ltrim($q, '@');
    $like   = "%$needle%";
    $ct->bindValue(':q1', $like, PDO::PARAM_STR);
    $ct->bindValue(':q2', $like, PDO::PARAM_STR);
  }
  $ct->execute();
  $total = (int)$ct->fetchColumn();

  json_out(['ok' => true, 'items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit]);
} catch (Throwable $e) {
  json_out(['error' => 'SERVER', 'detail' => $e->getMessage()], 500);
}
