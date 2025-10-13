<?php
// backend/api/conversations_list.php  (HY093-safe; positional placeholders)
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';

cors();                 // CORS + JSON headers
global $pdo;
$me = require_auth($pdo);   // ['id' => CHAR(36)]

try {
  $sql = "
    SELECT
      c.id,
      CASE WHEN c.user1_id = ? THEN c.user2_id ELSE c.user1_id END AS other_user_id,
      u.name AS other_display_name,
      NULL AS other_username,
      NULL AS other_avatar,
      (SELECT m.content
         FROM messages m
        WHERE m.conversation_id = c.id
        ORDER BY m.id DESC
        LIMIT 1) AS last_content,
      (SELECT m.type
         FROM messages m
        WHERE m.conversation_id = c.id
        ORDER BY m.id DESC
        LIMIT 1) AS last_type,
      (SELECT m.created_at
         FROM messages m
        WHERE m.conversation_id = c.id
        ORDER BY m.id DESC
        LIMIT 1) AS last_at,
      (SELECT COUNT(*)
         FROM messages m
        WHERE m.conversation_id = c.id
          AND m.receiver_id = ?
          AND m.is_read = 0) AS unread
    FROM conversations c
    JOIN users u
      ON u.id = CASE WHEN c.user1_id = ? THEN c.user2_id ELSE c.user1_id END
    WHERE c.user1_id = ? OR c.user2_id = ?
    ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
  ";

  $st = $pdo->prepare($sql);
  // IMPORTANT: bind the same value for each ?
  $st->execute([$me['id'], $me['id'], $me['id'], $me['id'], $me['id']]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  json_out(['ok' => true, 'items' => $rows]);
} catch (Throwable $e) {
  // Visible in dev; hide in prod
  json_out(['error' => 'SQL', 'detail' => $e->getMessage()], 500);
}
