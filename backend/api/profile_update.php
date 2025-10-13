<?php
// backend/api/profile_update.php
require __DIR__ . '/db.php';
require __DIR__ . '/util.php';

header('Content-Type: application/json');
if (!isset($db) && isset($pdo)) { $db = $pdo; }

try {
  if (!($db instanceof PDO)) json_out(['error' => 'DB_NOT_AVAILABLE'], 500);

  $me  = require_auth($db);
  $uid = $me['id'];

  $in = input_json_or_form();

  // Flags: update only what the client actually sent
  $hasName     = array_key_exists('name', $in);
  $hasUsername = array_key_exists('username', $in);
  $hasBio      = array_key_exists('bio', $in);
  $hasSkills   = array_key_exists('skills', $in);

  $name     = $hasName ? trim((string)$in['name']) : '';
  $username = $hasUsername ? trim((string)$in['username']) : '';
  if ($hasUsername && $username !== '' && $username[0] === '@') $username = substr($username, 1);
  $username = $hasUsername ? strtolower($username) : '';
  $bio      = $hasBio ? trim((string)$in['bio']) : '';

  // Normalize skills only if provided
  $skills = [];
  $otherLabel = '';
  if ($hasSkills) {
    $skills = is_array($in['skills']) ? $in['skills'] : [];
    $otherLabel = trim((string)($in['other_skill'] ?? ''));
    $allow = ['dj','singer','guitarist','drummer','bassist','keyboardist','dancer','other'];
    $skills = array_values(array_unique(array_filter(array_map(function($s){
      $s = strtolower((string)$s);
      return preg_replace('/[^a-z0-9_]/','',$s);
    }, $skills), function($s) use($allow){ return in_array($s, $allow, true); })));
  }

  if ($hasUsername && $username !== '' && !preg_match('/^[a-z0-9_]{3,20}$/', $username)) {
    json_out(['error' => 'BAD_USERNAME', 'detail' => 'Use 3–20 chars [a–z0–9_]'], 422);
  }

  // Ensure skills table exists OUTSIDE any transaction (DDL causes implicit commits)
  if ($hasSkills) {
    try {
      $db->exec("
        CREATE TABLE IF NOT EXISTS profile_skills (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          user_id CHAR(36) NOT NULL,
          skill VARCHAR(32) NOT NULL,
          other_label VARCHAR(64) DEFAULT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX(user_id),
          FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      ");
    } catch (\Throwable $ignore) {
      // Non-fatal in dev; we’ll just skip skills write below if table truly missing
    }
  }

  // ---- transactional DML only ----
  $db->beginTransaction();

  // Ensure profile row
  $db->prepare("INSERT IGNORE INTO profiles (user_id, created_at) VALUES (:id, NOW())")
     ->execute([':id' => $uid]);

  if ($hasName && $name !== '') {
    $db->prepare("UPDATE users SET name = :n WHERE id = :id")
       ->execute([':n' => $name, ':id' => $uid]);
  }

  if ($hasUsername) {
    if ($username !== '') {
      $st = $db->prepare("SELECT 1 FROM profiles WHERE username = :u AND user_id <> :id LIMIT 1");
      $st->execute([':u' => $username, ':id' => $uid]);
      if ($st->fetchColumn()) {
        if ($db->inTransaction()) { $db->rollBack(); }
        json_out(['error' => 'USERNAME_TAKEN'], 409);
      }
    }
    $db->prepare("UPDATE profiles SET username = NULLIF(:u,'') WHERE user_id = :id")
       ->execute([':u' => $username, ':id' => $uid]);
  }

  if ($hasBio) {
    $db->prepare("UPDATE profiles SET bio = :b WHERE user_id = :id")
       ->execute([':b' => $bio, ':id' => $uid]);
  }

  if ($hasSkills) {
    // Only attempt if the table exists (quick check)
    $exists = $db->query("SHOW TABLES LIKE 'profile_skills'")->fetchColumn();
    if ($exists) {
      $db->prepare("DELETE FROM profile_skills WHERE user_id = :id")->execute([':id' => $uid]);
      if (!empty($skills)) {
        $ins = $db->prepare("INSERT INTO profile_skills (user_id, skill, other_label) VALUES (:id, :s, :o)");
        foreach ($skills as $s) {
          $ins->execute([
            ':id' => $uid,
            ':s'  => $s,
            ':o'  => ($s === 'other' && $otherLabel !== '') ? $otherLabel : null,
          ]);
        }
      }
    }
  }

  $db->commit();

  json_out(['ok' => true]);

} catch (\Throwable $e) {
  if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
    try { $db->rollBack(); } catch (\Throwable $ignore) {}
  }
  json_out(['error' => 'SERVER', 'detail' => $e->getMessage()], 500);
}
