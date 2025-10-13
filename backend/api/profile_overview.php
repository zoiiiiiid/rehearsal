<?php
// backend/api/profile_overview.php
// Public-friendly snapshot of a user's profile.

require __DIR__ . '/db.php';
require __DIR__ . '/util.php';

header('Content-Type: application/json');
if (!isset($db) && isset($pdo)) { $db = $pdo; }

/** small helper so we can accept many shapes */
function _truthy($v): bool {
  if ($v === true || $v === 1) return true;
  if (is_numeric($v)) return ((int)$v) === 1;
  if (!is_string($v)) return false;
  $s = strtolower(trim($v));
  return in_array($s, ['1','true','yes','y','on'], true);
}

try {
  if (!($db instanceof PDO)) json_out(['error'=>'DB_NOT_AVAILABLE'], 500);

  $in     = input_json_or_form();
  $viewer = auth_user_or_null($db); // may be null

  $targetId = $_GET['user_id'] ?? ($in['user_id'] ?? ($viewer['id'] ?? null));
  if (!$targetId) json_out(['error'=>'USER_REQUIRED'], 400);

  // -- basic user + profile
  $st = $db->prepare("
    SELECT u.id, u.name, u.role, u.status,
           p.username, p.bio, p.avatar_url
      FROM users u
      LEFT JOIN profiles p ON p.user_id = u.id
     WHERE u.id = :id
     LIMIT 1
  ");
  $st->execute([':id'=>$targetId]);
  $user = $st->fetch(PDO::FETCH_ASSOC);
  if (!$user) json_out(['error'=>'USER_NOT_FOUND'], 404);

  // -- viewer flags
  $isMe = ($viewer && $viewer['id'] === $targetId);
  $isFollowing = false;
  if ($viewer && !$isMe) {
    $st = $db->prepare("SELECT 1 FROM follows WHERE follower_id=:v AND followed_id=:u LIMIT 1");
    $st->execute([':v'=>$viewer['id'], ':u'=>$targetId]);
    $isFollowing = (bool)$st->fetchColumn();
  }

  // -- counts
  $st = $db->prepare("SELECT COUNT(*) FROM posts WHERE user_id=:id");
  $st->execute([':id'=>$targetId]); $postsCount = (int)$st->fetchColumn();

  $st = $db->prepare("SELECT COUNT(*) FROM follows WHERE followed_id=:id");
  $st->execute([':id'=>$targetId]); $followersCount = (int)$st->fetchColumn();

  $st = $db->prepare("SELECT COUNT(*) FROM follows WHERE follower_id=:id");
  $st->execute([':id'=>$targetId]); $followingCount = (int)$st->fetchColumn();

  // -- recent posts
  $st = $db->prepare("
    SELECT id, media_url, caption, created_at
      FROM posts
     WHERE user_id = :id
     ORDER BY created_at DESC
     LIMIT 12
  ");
  $st->execute([':id'=>$targetId]);
  $posts = $st->fetchAll(PDO::FETCH_ASSOC);

  // -- skills
  $skillsObjs = [];        // [{key,label}]
  $skillsLabels = [];      // ["Singer","DJ",...]
  $skillsOther = '';
  $skillsDebug = null;

  try {
    $st = $db->prepare("SELECT skill, other_label
                      FROM profile_skills
                     WHERE user_id = :id
                  ORDER BY 1, other_label ASC");
    $st->execute([':id'=>$targetId]);

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $key = strtolower(trim($r['skill'] ?? ''));
      $label = '';
      switch ($key) {
        case 'dj':          $label = 'DJ'; break;
        case 'singer':      $label = 'Singer'; break;
        case 'guitarist':   $label = 'Guitarist'; break;
        case 'drummer':     $label = 'Drummer'; break;
        case 'bassist':     $label = 'Bassist'; break;
        case 'keyboardist': $label = 'Keyboardist'; break;
        case 'dancer':      $label = 'Dancer'; break;
        case 'other':
          $label = trim($r['other_label'] ?? '');
          if ($label === '') $label = 'Other';
          $skillsOther = $label; // expose separately too
          break;
        default:
          $label = ucfirst($key ?: 'Other');
      }
      $skillsObjs[] = ['key' => ($key ?: 'other'), 'label' => $label];
      $skillsLabels[] = $label;
    }
  } catch (Throwable $e) {
    $skillsDebug = $e->getMessage();
  }

  /* ------------------------------------------------------------------
   * VERIFICATION: try a few sources, all optional/safe with try-catch.
   * We’ll set:
   *   $verified (bool), $verificationStatus (string), $badge (string)
   * and also compute $canHostWorkshops.
   * ----------------------------------------------------------------*/
  $verified = false;
  $verificationStatus = '';
  $badge = '';

  // 1) profiles table (if columns exist)
  try {
    $st = $db->prepare("SELECT verification_status, verified, is_verified, badge, mentor_verified_at, creator_verified_at
                          FROM profiles
                         WHERE user_id = :id
                         LIMIT 1");
    $st->execute([':id'=>$targetId]);
    if ($pf = $st->fetch(PDO::FETCH_ASSOC)) {
      if (_truthy($pf['verified'] ?? 0) || _truthy($pf['is_verified'] ?? 0)) $verified = true;
      if (!empty($pf['mentor_verified_at']) || !empty($pf['creator_verified_at'])) $verified = true;
      $vs = strtolower(trim((string)($pf['verification_status'] ?? '')));
      if ($vs !== '') $verificationStatus = $vs;
      if (!empty($pf['badge'])) $badge = (string)$pf['badge'];
    }
  } catch (Throwable $e) { /* ignore if cols not present */ }

  // 2) users table (if columns exist)
  try {
    $st = $db->prepare("SELECT verified AS u_verified, is_verified AS u_is_verified, verification_status AS u_ver_status
                          FROM users
                         WHERE id=:id
                         LIMIT 1");
    $st->execute([':id'=>$targetId]);
    if ($uu = $st->fetch(PDO::FETCH_ASSOC)) {
      if (_truthy($uu['u_verified'] ?? 0) || _truthy($uu['u_is_verified'] ?? 0)) $verified = true;
      $vs = strtolower(trim((string)($uu['u_ver_status'] ?? '')));
      if ($vs !== '') $verificationStatus = $vs;
    }
  } catch (Throwable $e) { /* ignore missing cols */ }

  // 3) dedicated verifications table (optional)
  foreach (['creator_verifications','mentor_verifications','verifications'] as $tbl) {
    try {
      $st = $db->prepare("SELECT status FROM {$tbl} WHERE user_id=:id ORDER BY id DESC LIMIT 1");
      $st->execute([':id'=>$targetId]);
      if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $vs = strtolower(trim((string)$row['status']));
        if ($vs) $verificationStatus = $vs;
        if (in_array($vs, ['approved','verified','active','accepted'], true)) $verified = true;
        break;
      }
    } catch (Throwable $e) { /* table may not exist; ignore */ }
  }

  // Admins are implicitly allowed to host
  if (strtolower((string)($user['role'] ?? '')) === 'admin') {
    $verified = true;
    if ($verificationStatus === '') $verificationStatus = 'approved';
  }

  $canHostWorkshops = ($verified === true);

  $out = [
    'ok' => true,
    'user' => [
      'id'           => $user['id'],
      'name'         => $user['name'],
      'display_name' => $user['name'],
      'username'     => $user['username'],
      'bio'          => $user['bio'],
      'avatar_url'   => $user['avatar_url'],
      'role'         => $user['role'],
      'status'       => $user['status'],
      // NEW fields the web expects:
      'verified'             => $verified ? 1 : 0,
      'is_verified'          => $verified ? 1 : 0,
      'verification_status'  => $verificationStatus,
      'badge'                => ($verified ? ($badge ?: 'verified') : ($badge ?: '')),
      'can_host_workshops'   => $canHostWorkshops ? 1 : 0,

      'is_me'        => $isMe,
      'is_following' => $isFollowing,
      'skills'       => $skillsObjs,   // preferred shape
    ],
    // compatibility for clients expecting top-level skills
    'skills'        => $skillsLabels,  // ["Singer","DJ",...]
    'skills_other'  => $skillsOther,
    'counts' => [
      'posts'     => $postsCount,
      'followers' => $followersCount,
      'following' => $followingCount,
    ],
    'posts' => $posts,
  ];

  if ($skillsDebug) $out['skills_debug'] = $skillsDebug;

  json_out($out);
} catch (Throwable $e) {
  json_out(['error'=>'SERVER', 'detail'=>$e->getMessage()], 500);
}
