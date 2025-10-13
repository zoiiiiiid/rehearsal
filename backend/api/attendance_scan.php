<?php
// backend/api/attendance_scan.php
require __DIR__.'/util.php'; cors(); require __DIR__.'/db.php';

/** Polyfill for PHP < 8 */
if (!function_exists('str_starts_with')) {
  function str_starts_with(string $haystack, string $needle): bool {
    if ($needle === '') return true;
    return strncmp($haystack, $needle, strlen($needle)) === 0;
  }
}

function hmac_secret(): string {
  $env = getenv('ATTENDANCE_HMAC_SECRET');
  return $env && strlen($env) >= 16 ? $env : 'dev_demo_secret_change_me';
}

function table_has_col(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $key = $table.'.'.$column;
  if (array_key_exists($key, $cache)) return $cache[$key];
  $sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = :t
            AND COLUMN_NAME = :c";
  $st = $pdo->prepare($sql);
  $st->execute([':t'=>$table, ':c'=>$column]);
  $cache[$key] = ($st->fetchColumn() > 0);
  return $cache[$key];
}

/** True if the current user looks like an admin (works with multiple schemas). */
function is_admin_user(array $me): bool {
  $role = strtolower((string)($me['role'] ?? ''));
  if ($role === 'admin') return true;
  $ia = $me['is_admin'] ?? 0;
  return $ia === true || $ia === 1 || $ia === '1';
}

/** Loosely check if $userId is allowed to scan for this workshop. */
function user_is_workshop_staff(PDO $pdo, string $workshopId, string $userId): bool {
  // Try common owner fields on workshops
  $cols = ['mentor_id','owner_id','host_id','created_by'];
  $fields = [];
  foreach ($cols as $c) if (table_has_col($pdo,'workshops',$c)) $fields[] = $c;
  if (!empty($fields)) {
    $sql = "SELECT id FROM workshops
            WHERE id=:id AND (".implode(' = :uid OR ', $fields)." = :uid)
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':id'=>$workshopId, ':uid'=>$userId]);
    if ($st->fetchColumn()) return true;
  }

  // Optional staff table
  if (table_has_col($pdo,'workshop_staff','user_id') && table_has_col($pdo,'workshop_staff','workshop_id')) {
    $st = $pdo->prepare("SELECT 1 FROM workshop_staff WHERE workshop_id=? AND user_id=? LIMIT 1");
    $st->execute([$workshopId, $userId]);
    if ($st->fetchColumn()) return true;
  }
  return false;
}

try {
  $me   = require_auth($pdo);
  $myId = (string)$me['id'];

  $in = input_json_or_form();
  $workshopId = trim((string)($in['workshop_id'] ?? ''));
  $payload    = (string)($in['payload'] ?? '');

  if ($workshopId === '' || $payload === '') {
    json_out(['error'=>'BAD_REQUEST','detail'=>'workshop_id and payload required'], 422);
  }

  // Must be host/owner/staff (admins allowed too)
  if (!user_is_workshop_staff($pdo, $workshopId, $myId) && !is_admin_user($me)) {
    json_out(['error'=>'FORBIDDEN','detail'=>'not authorized to scan for this workshop'], 403);
  }

  // Parse payload: ATT:v1|<workshop_id>|<user_id>|<exp>|<nonce>|<sig>
  if (!str_starts_with($payload, 'ATT:v1|')) {
    json_out(['error'=>'INVALID','detail'=>'bad_prefix'], 422);
  }
  $parts = explode('|', substr($payload, strlen('ATT:v1|')));
  if (count($parts) !== 5) json_out(['error'=>'INVALID','detail'=>'bad_parts'], 422);

  [$wid, $uid, $exp, $nonce, $sig] = $parts;
  if ($wid !== $workshopId) json_out(['error'=>'INVALID','detail'=>'workshop_mismatch'], 422);
  if (!ctype_digit($exp))   json_out(['error'=>'INVALID','detail'=>'bad_exp'], 422);

  // Verify signature  (fixed: no stray quote)
  $base   = $wid.'|'.$uid.'|'.$exp.'|'.$nonce;
  $expect = hash_hmac('sha256', $base, hmac_secret());
  if (!hash_equals($expect, $sig)) {
    json_out(['error'=>'INVALID','detail'=>'bad_sig'], 422);
  }

  // Check expiry (allow small clock drift)
  if ((int)$exp < time() - 120) { // 2 minutes leeway
    json_out(['error'=>'INVALID','detail'=>'expired'], 422);
  }

  // Confirm workshop exists + whether it requires payment
  $paidRequired = false;
  $st = $pdo->prepare("SELECT id,
          ".(table_has_col($pdo,'workshops','paid_required') ? 'paid_required' :
              (table_has_col($pdo,'workshops','price_cents') ? 'price_cents' : '0'))." as paycol
        FROM workshops WHERE id=? LIMIT 1");
  $st->execute([$workshopId]);
  $ws = $st->fetch(PDO::FETCH_ASSOC);
  if (!$ws) json_out(['error'=>'NOT_FOUND','detail'=>'workshop not found'], 404);

  if (isset($ws['paycol'])) {
    // bool paid_required OR non-zero price_cents
    $paidRequired = (int)$ws['paycol'] > 0;
  }

  // If paid, check if attendee marked paid
  if ($paidRequired) {
    $isPaid = false;

    // Try enrollments: workshop_enrollments(workshop_id, user_id, paid)
    if (table_has_col($pdo,'workshop_enrollments','workshop_id') &&
        table_has_col($pdo,'workshop_enrollments','user_id') &&
        table_has_col($pdo,'workshop_enrollments','paid')) {
      $st = $pdo->prepare("SELECT paid FROM workshop_enrollments WHERE workshop_id=? AND user_id=? LIMIT 1");
      $st->execute([$workshopId, $uid]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row && (int)$row['paid'] === 1) $isPaid = true;
    }

    // Or payments: workshop_payments(workshop_id, user_id, status in ('paid','success'))
    if (!$isPaid && table_has_col($pdo,'workshop_payments','status')) {
      $st = $pdo->prepare("SELECT 1 FROM workshop_payments WHERE workshop_id=? AND user_id=? AND status IN ('paid','success') LIMIT 1");
      $st->execute([$workshopId, $uid]);
      if ($st->fetchColumn()) $isPaid = true;
    }

    if (!$isPaid) {
      // Return attendee to show who failed payment check
      $user = $pdo->prepare("SELECT id, display_name, username, avatar_url FROM users WHERE id=? LIMIT 1");
      $user->execute([$uid]);
      $ud = $user->fetch(PDO::FETCH_ASSOC) ?: ['id'=>$uid];
      json_out(['ok'=>true, 'status'=>'paid_required', 'user'=>$ud]);
    }
  }

  // Idempotent attendance insert (CREATE TABLE fallback in dev)
  $pdo->beginTransaction();
  try {
    if (!table_has_col($pdo,'workshop_attendance','workshop_id')) {
      $pdo->exec("CREATE TABLE IF NOT EXISTS workshop_attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        workshop_id VARCHAR(64) NOT NULL,
        user_id VARCHAR(64) NOT NULL,
        checked_in_at DATETIME NOT NULL,
        UNIQUE KEY uniq_ws_user (workshop_id, user_id)
      )");
    }

    $stmt = $pdo->prepare("INSERT IGNORE INTO workshop_attendance (workshop_id, user_id, checked_in_at)
                           VALUES (?, ?, NOW())");
    $stmt->execute([$workshopId, $uid]);
    $wasNew = $stmt->rowCount() > 0;

    $pdo->commit();

    // Return attendee info for UI
    $u = $pdo->prepare("SELECT id, display_name, username, avatar_url FROM users WHERE id=? LIMIT 1");
    $u->execute([$uid]);
    $ud = $u->fetch(PDO::FETCH_ASSOC) ?: ['id'=>$uid];

    json_out([
      'ok'     => true,
      'status' => $wasNew ? 'checked_in' : 'already',
      'user'   => $ud,
    ]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['error'=>'SERVER','detail'=>$e->getMessage()], 500);
  }
} catch (Throwable $e) {
  json_out(['error'=>'SERVER','detail'=>$e->getMessage()], 500);
}
