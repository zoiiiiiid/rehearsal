<?php
require __DIR__.'/web_common.php';
require_web_auth();
$API_BASE = rtrim((getenv('API_BASE') ?: '/backend/api'), '/');
$token = urlencode(web_token());

$wid = $_GET['workshop_id'] ?? '';
$uid = $_GET['user_id'] ?? ''; // optional, default to me below

// fetch my user to show name (optional)
$me = json_decode(file_get_contents("$API_BASE/profile_overview.php?token=$token"),true);
$myId = (string)($me['user']['id'] ?? '');
if ($uid === '') $uid = $myId;

// ask server to issue payload
$payload = '';
if ($wid) {
  $ch = curl_init("$API_BASE/attendance_token.php?token=$token");
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>json_encode(['workshop_id'=>$wid,'user_id'=>$uid]),
  ]);
  $resp=curl_exec($ch); curl_close($ch);
  $j=json_decode($resp,true)?:[];
  $payload = $j['payload'] ?? '';
}

html_header('Workshop pass');
?>
<h1>Workshop Pass (QR)</h1>

<form method="get" class="card">
  <label>Workshop ID</label>
  <input name="workshop_id" value="<?=htmlspecialchars($wid)?>" required />
  <label class="mt8">User ID (blank = me)</label>
  <input name="user_id" value="<?=htmlspecialchars($uid)?>" />
  <button class="btn mt12" type="submit">Generate</button>
</form>

<?php if ($payload): ?>
  <div class="card mt16">
    <div class="muted mb8" style="word-break:break-all"><?=htmlspecialchars($payload)?></div>
    <img alt="QR" src="/backend/api/attendance_qr_png.php?payload=<?=urlencode($payload)?>" style="max-width:300px;width:100%;height:auto;border:1px solid #eee;border-radius:8px" />
  </div>
<?php endif; ?>
<?php html_footer(); ?>
