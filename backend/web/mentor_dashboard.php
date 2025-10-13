<?php
require __DIR__.'/web_common.php';
require_web_auth();
$API_BASE = rtrim((getenv('API_BASE') ?: '/backend/api'), '/');
$token = urlencode(web_token());

// Ask server for the Zoom authorize URL so we can link the button
$auth = file_get_contents("$API_BASE/zoom_connect_start.php?token=$token");
$j = json_decode($auth, true) ?: [];
$authorize = $j['authorize_url'] ?? '#';

html_header('Mentor');
?>
<h1>Mentor / Host</h1>

<div class="card">
  <h3>Zoom</h3>
  <p class="muted">Connect your Zoom account to auto-create meetings.</p>
  <a class="btn mt8" href="<?=htmlspecialchars($authorize)?>">Connect Zoom</a>
</div>

<div class="row mt16">
  <a class="btn" href="/backend/web/workshop_new.php">Create Workshop</a>
  <a class="btn outline" href="/backend/web/workshop_scan.php">Open Live Scanner</a>
</div>
<?php html_footer(); ?>
