<?php
require __DIR__.'/web_common.php';
require_web_auth();
$API_BASE = rtrim((getenv('API_BASE') ?: '/backend/api'), '/');
$token = urlencode(web_token());
$msg = ''; $ok=false; $createdId = null; $zoom = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title = trim($_POST['title'] ?? 'Workshop');
  $starts = trim($_POST['starts_at'] ?? '');
  $duration = (int)($_POST['duration'] ?? 60);
  $price = (int)($_POST['price_cents'] ?? 0);
  $skill = trim($_POST['skill'] ?? 'other');

  $body = json_encode([
    'title'=>$title,
    'starts_at'=>$starts,  // ISO8601 UTC (e.g., 2025-01-01T10:00:00Z)
    'duration'=>$duration,
    'price_cents'=>$price,
    'skill'=>$skill,
  ]);

  $ch = curl_init("$API_BASE/workshop_create_zoom.php?token=$token");
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>$body,
  ]);
  $resp = curl_exec($ch);
  curl_close($ch);
  $j = json_decode($resp,true) ?: [];
  if (!empty($j['ok'])) { $ok=true; $createdId=$j['workshop_id']; $zoom=$j['zoom']??[]; }
  else { $msg = $j['error'] ?? 'Failed'; }
}

html_header('Create workshop');
?>
<h1>Create workshop</h1>

<?php if ($msg): ?>
  <div class="card" style="border-color:#ef4444;color:#ef4444"><?=htmlspecialchars($msg)?></div>
<?php endif; ?>

<?php if ($ok): ?>
  <div class="card">
    <h3>Created!</h3>
    <p>ID: <strong><?=htmlspecialchars($createdId)?></strong></p>
    <p>Zoom join: <a href="<?=htmlspecialchars($zoom['join_url']??'#')?>"><?=htmlspecialchars($zoom['join_url']??'')?></a></p>
    <div class="row mt12">
      <a class="btn" href="/backend/web/workshop_scan.php?workshop_id=<?=urlencode($createdId)?>">Open Scanner</a>
      <a class="btn outline" href="/backend/web/workshop_pass.php?workshop_id=<?=urlencode($createdId)?>">Generate Pass (QR)</a>
    </div>
  </div>
<?php endif; ?>

<form method="post" class="card mt16">
  <div class="grid2">
    <div>
      <label>Title</label>
      <input name="title" required />
    </div>
    <div>
      <label>Skill</label>
      <select name="skill">
        <option value="dj">DJ</option><option value="singer">Singer</option>
        <option value="guitarist">Guitarist</option><option value="drummer">Drummer</option>
        <option value="bassist">Bassist</option><option value="keyboardist">Keyboardist</option>
        <option value="dancer">Dancer</option><option value="other" selected>Other</option>
      </select>
    </div>
  </div>

  <div class="grid2 mt12">
    <div>
      <label>Starts at (UTC ISO8601)</label>
      <input name="starts_at" placeholder="2025-01-01T10:00:00Z" required />
    </div>
    <div>
      <label>Duration (minutes)</label>
      <input name="duration" type="number" value="60" min="1" required />
    </div>
  </div>

  <div class="grid2 mt12">
    <div>
      <label>Price (cents)</label>
      <input name="price_cents" type="number" value="0" min="0" />
    </div>
  </div>

  <button class="btn mt16" type="submit">Create</button>
</form>
<?php html_footer(); ?>
