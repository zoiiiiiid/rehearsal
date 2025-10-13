<?php
// backend/web/workshop_qr.php
// For PAID workshops only. Host types the access token they set at creation,
// we render a QR as ACCESS:v1|<id>|<token>. (We don't ever read the stored hash.)

require __DIR__.'/web_common.php'; require_web_auth();

$wid = (string)($_GET['id'] ?? '');
render_header('Paid Workshop • Check-in QR');

if ($wid==='') {
  echo '<div class="card" style="padding:12px"><b>Missing workshop id.</b></div>';
  render_footer(); exit;
}
?>
<div class="card" style="max-width:600px;padding:16px">
  <div style="display:grid;grid-template-columns:260px 1fr;gap:16px;align-items:start">
    <img id="qr" src="" width="260" height="260" alt="QR"
         style="border:1px solid #e5e7eb;border-radius:8px;background:#fff">
    <div>
      <div><b>Workshop ID:</b> <?=h($wid)?></div>
      <label style="margin-top:8px">Access token (the password you set when creating)</label>
      <input id="tok" class="input" type="text" placeholder="e.g. RH-2025"
             style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:10px">
      <div class="muted" style="margin:6px 0 10px">We never send this to the web server; it’s embedded into the QR only.</div>

      <button id="gen" class="btn">Generate QR</button>
      <button id="copy" class="btn out" style="margin-left:8px">Copy payload</button>
      <div class="hr"></div>
      <code id="payload" style="word-break:break-all"></code>
    </div>
  </div>
</div>
<script>
const wid = <?= json_encode($wid) ?>;
const qr  = document.getElementById('qr');
const tok = document.getElementById('tok');
const gen = document.getElementById('gen');
const out = document.getElementById('payload');
const copy= document.getElementById('copy');

function build() {
  const t = (tok.value || '').trim();
  if (!t) { alert('Enter the access token you set for this workshop.'); return; }
  const payload = `ACCESS:v1|${wid}|${t}`;
  out.textContent = payload;
  const url = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=' + encodeURIComponent(payload);
  qr.src = url + '&_=' + Date.now();
}
gen.onclick  = build;
copy.onclick = () => { if (out.textContent) navigator.clipboard.writeText(out.textContent); };
</script>
<?php render_footer(); ?>
