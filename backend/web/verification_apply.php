<?php
/* =================================================================
 * File: backend/web/verification_apply.php
 * User-facing “Apply for Verification” (mentor)
 * Uses: /api/profile_overview.php  (status)
 *       /api/mentor_application.php (apply/transition -> pending)
 * ================================================================= */
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth();

$err = $ok = '';
$me  = api_get('profile_overview.php');
$user = (array)($me['user'] ?? []);
$status = strtolower((string)($user['status'] ?? ''));   // '', 'pending', 'verified', 'rejected', etc.

/* ---------------- submit -> hit mentor_application.php ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  csrf_check();

  // nothing required by your endpoint; it's idempotent and only needs auth
  $res = api_post_json('mentor_application.php', []);

  if (is_array($res) && ($res['ok'] ?? false)) {
    $ok = (string)($res['message'] ?? 'Your application was submitted.');
    // refresh status from profile after the transition
    $me  = api_get('profile_overview.php');
    $user = (array)($me['user'] ?? []);
    $status = strtolower((string)($user['status'] ?? 'pending'));
  } else {
    $err = (string)($res['error'] ?? 'Submit failed.');
    if (!empty($res['detail'])) $err .= ' — ' . (string)$res['detail'];
    // ALREADY_VERIFIED should be shown nicely:
    if (($res['error'] ?? '') === 'ALREADY_VERIFIED') {
      $ok = 'You are already verified.';
      $status = 'verified';
      $err = '';
    }
  }
}

/* ---------------- view ---------------- */
render_header('Verification');
?>
<div class="card" style="padding:14px;max-width:760px">
  <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap">
    <div>
      <div style="font-weight:800">Apply for verification</div>
      <div class="muted">Get your verification badge!</div>
    </div>
    <div>
      <?php
        $label = $status ? ucfirst($status) : 'Not applied';
        $color = ($status==='verified' ? '#0a7' : ($status==='pending' ? '#6b7280' : '#444'));
        echo '<span class="badge" style="border-color:'.$color.';color:'.$color.'">'.$label.'</span>';
      ?>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="error" style="margin-top:10px"><b>Error:</b> <?= h($err) ?></div>
  <?php endif; ?>
  <?php if ($ok): ?>
    <div style="margin-top:10px;color:#0a7"><?= h($ok) ?></div>
  <?php endif; ?>

  <?php if ($status === 'verified'): ?>
    <div style="margin-top:12px;color:#0a7">
      You’re verified! <?= function_exists('verified_badge_html') ? verified_badge_html() : '✅' ?>
    </div>

  <?php elseif ($status === 'pending'): ?>
    <div class="muted" style="margin-top:12px">
      Your application is under review. You’ll be notified when a decision is made.
    </div>

  <?php else: ?>
    <!-- Simple one-click apply (your API doesn’t need fields) -->
    <form method="post" style="margin-top:12px">
      <?php csrf_field(); ?>
      <div class="muted" style="margin-bottom:8px">
        Click apply to submit your mentor verification request. You can include more context in your
        profile bio and links — admins will review those too.
      </div>
      <button class="btn" type="submit">Apply for verification</button>
      <a class="btn out" href="<?= h(WEB_BASE) ?>/profile.php">Cancel</a>
    </form>
  <?php endif; ?>
</div>
<?php render_footer();
