<?php
require __DIR__.'/web_common.php';
require_web_auth();
$token = htmlspecialchars(web_token());
$API_BASE = rtrim((getenv('API_BASE') ?: '/backend/api'), '/');
$wid = isset($_GET['workshop_id']) ? htmlspecialchars($_GET['workshop_id']) : '';
html_header('Scanner');
?>
<h1>QR Attendance Scanner</h1>

<form class="card" onsubmit="event.preventDefault();">
  <label>Workshop ID</label>
  <input id="wid" value="<?=$wid?>" placeholder="enter workshop id" required />
  <div class="row mt12">
    <button class="btn" type="button" onclick="startScan()">Start camera</button>
    <button class="btn outline" type="button" onclick="stopScan()">Stop</button>
  </div>
</form>

<div class="card mt16">
  <video id="vid" playsinline style="width:100%;max-width:480px;border-radius:8px"></video>
  <canvas id="cnv" style="display:none"></canvas>
  <div class="mt8">
    <label>Fallback paste (if camera unsupported)</label>
    <input id="manual" placeholder="ATT:v1|..." />
    <button class="btn mt8" onclick="submitPayload(document.getElementById('manual').value)">Submit</button>
  </div>
</div>

<div id="log" class="mt16"></div>

<script>
const TOKEN = "<?=$token?>";
const API_BASE = "<?=$API_BASE?>";

let stream=null, det=null, rafId=null;
async function startScan(){
  const wid = document.getElementById('wid').value.trim();
  if(!wid){ alert('Workshop ID required'); return; }

  try {
    if ('BarcodeDetector' in window) {
      const formats = await BarcodeDetector.getSupportedFormats();
      if (!formats.includes('qr_code')) throw new Error('qr_code not supported');
      det = new BarcodeDetector({formats:['qr_code']});
    } else {
      det = null;
    }

    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }});
    const vid = document.getElementById('vid');
    vid.srcObject = stream; await vid.play();

    if (det) scanLoopBarcode();
    else scanLoopCanvas(); // manual canvas decode not implemented; fallback is manual text box
  } catch(e){ log('Camera error: '+e); }
}

function stopScan(){
  if (rafId) cancelAnimationFrame(rafId);
  rafId=null;
  const vid=document.getElementById('vid');
  vid.pause(); vid.srcObject=null;
  if (stream){ stream.getTracks().forEach(t=>t.stop()); stream=null; }
}

function log(msg){
  const div=document.getElementById('log');
  const p=document.createElement('div'); p.textContent=msg;
  div.prepend(p);
}

async function scanLoopBarcode(){
  const vid=document.getElementById('vid');
  const wid=document.getElementById('wid').value.trim();
  if (!stream || !det) return;

  try{
    const codes = await det.detect(vid);
    for (const c of codes){
      const payload = c.rawValue || '';
      if (payload.startsWith('ATT:v1|')){
        stopScan();
        await submitPayload(payload, wid);
        break;
      }
    }
  }catch(e){ /* ignore */ }
  rafId=requestAnimationFrame(scanLoopBarcode);
}

async function submitPayload(payload, widOpt){
  const wid = (widOpt || document.getElementById('wid').value.trim());
  if (!wid){ alert('Workshop ID required'); return; }
  if (!payload){ alert('No payload'); return; }
  log('Submitting…');

  const r = await fetch(`${API_BASE}/attendance_scan.php?token=${encodeURIComponent(TOKEN)}`, {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ workshop_id: wid, payload })
  });
  const j = await r.json();
  if (j.ok){
    if (j.status === 'checked_in') log(`✓ Checked-in: ${(j.user && (j.user.display_name || j.user.username || j.user.id))}`);
    else if (j.status === 'already') log(`ℹ Already in: ${(j.user && (j.user.display_name || j.user.username || j.user.id))}`);
    else if (j.status === 'paid_required') log(`⚠ Payment required for ${(j.user && (j.user.display_name || j.user.username || j.user.id))}`);
    else log(JSON.stringify(j));
  }else{
    log('Err: '+(j.error || 'SERVER'));
  }
}
</script>
<?php html_footer(); ?>
