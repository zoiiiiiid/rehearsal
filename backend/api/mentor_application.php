<?php
// backend/api/mentor_application.php  (idempotent + safe transitions)
require __DIR__.'/util.php'; cors(); require __DIR__.'/db.php';

try {
  if (!($pdo instanceof PDO)) {
    json_out(['error' => 'DB_NOT_AVAILABLE', 'detail' => 'Database connection issue.'], 500);
  }

  $me = require_auth($pdo);
  $uid = $me['id'];

  $st = $pdo->prepare("SELECT role, status FROM users WHERE id = :id LIMIT 1");
  $st->execute([':id' => $uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) json_out(['error' => 'USER_NOT_FOUND'], 404);

  $role   = strtolower((string)($row['role'] ?? ''));
  $status = strtolower((string)($row['status'] ?? ''));

  if ($status === 'verified') {
    // Already verified (mentor or admin) — nothing to apply for.
    json_out(['error' => 'ALREADY_VERIFIED', 'detail' => 'You are already a verified mentor.'], 400);
  }

  if ($status === 'pending') {
    // Idempotent success so the client flow is simpler.
    json_out(['ok' => true, 'status' => 'pending', 'message' => 'Your application is already pending.']);
  }

  // Allowed transitions to "pending": empty/null/none, rejected, anything not verified/pending
  $upd = $pdo->prepare("UPDATE users SET status = 'pending' WHERE id = :id");
  $upd->execute([':id' => $uid]);

  json_out(['ok' => true, 'status' => 'pending', 'message' => 'Your mentor application has been submitted.']);
} catch (Throwable $e) {
  json_out(['error' => 'SERVER', 'detail' => $e->getMessage()], 500);
}
