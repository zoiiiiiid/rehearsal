<?php
// ============================================================================
// backend/api/username_check.php  (DROP-IN)
// - Works for both registration (no token) and editing (has token).
// - Valid: 3–20 chars [a-z0-9_], lowercased, unique across profiles.
// ============================================================================
require __DIR__ . '/db.php';
require __DIR__ . '/util.php';
header('Content-Type: application/json');

// support $pdo or $db
if (!isset($db) && isset($pdo)) { $db = $pdo; }

try {
  if (!isset($db) || !($db instanceof PDO)) {
    json_out(['available' => false, 'error'=>'DB_NOT_AVAILABLE'], 500);
  }

  $viewer = auth_user_or_null($db);               // nullable for registration
  $uid = $viewer['id'] ?? null;

  $u = strtolower(trim((string)($_GET['u'] ?? $_POST['u'] ?? '')));
  if ($u === '' || !preg_match('/^[a-z0-9_]{3,20}$/', $u)) {
    json_out(['available' => false, 'error' => 'USERNAME_INVALID']);
  }

  // Exclude self only if we know who self is
  if ($uid) {
    $sql = "SELECT 1 FROM profiles WHERE username = :u AND user_id <> :id LIMIT 1";
    $st  = $db->prepare($sql);
    $st->execute([':u'=>$u, ':id'=>$uid]);
  } else {
    $sql = "SELECT 1 FROM profiles WHERE username = :u LIMIT 1";
    $st  = $db->prepare($sql);
    $st->execute([':u'=>$u]);
  }

  $exists = (bool)$st->fetchColumn();
  json_out(['available' => !$exists]);
} catch (Throwable $e) {
  json_out(['available' => false, 'error'=>'SERVER', 'detail'=>$e->getMessage()], 500);
}
