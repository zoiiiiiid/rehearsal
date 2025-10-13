<?php
require __DIR__.'/web_common.php'; require_web_auth();
$cid = (int)($_GET['conversation_id'] ?? 0);
if (!$cid) { header('Location: /backend/web/inbox.php'); exit; }
html_header('Chat');
?>
<h1>Chat</h1>
<div class="card">
  <div id="msgs" class="list" style="max-height:60vh;overflow:auto"></div>
  <form id="f" class="row mt12" onsubmit="return sendText();">
    <input id="t" placeholder="Message…" />
    <button class="btn" type="submit">Send</button>
    <input type="file" id="file" />
    <button class="btn outline" type="button" onclick="sendFile()">Send media</button>
  </form>
</div>

<script>
const CID = <?=$cid?>;
const API = "<?= $API_BASE ?>";
const TOKEN = "<?= htmlspecialchars(web_token()) ?>";
let lastId = 0;

async function load() {
  const r = await fetch(`${API}/messages_list.php?conversation_id=${CID}&limit=50&token=${encodeURIComponent(TOKEN)}`).then(r=>r.json());
  const box = document.getElementById('msgs'); box.innerHTML='';
  (r.items||[]).forEach(m=>{
    lastId = Math.max(lastId, Number(m.id||0));
    const el=document.createElement('div');
    el.className='card'; el.style.border='1px solid #eee';
    el.innerHTML = `<div><strong>${(m.sender && (m.sender.display_name||m.sender.username))||'User'}</strong></div>
      <div>${m.content||''}</div>
      ${m.media_url?`<div class="mt8"><img src="${m.media_url}" style="max-width:280px;border-radius:8px"/></div>`:''}
      <div class="muted" style="font-size:12px">${m.time_ago||''}</div>`;
    box.appendChild(el);
  });
  box.scrollTop = box.scrollHeight;
  if (lastId) await fetch(`${API}/messages_mark_read.php?token=${encodeURIComponent(TOKEN)}`, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({conversation_id: CID, last_id: lastId})});
}
async function sendText(){
  const v = document.getElementById('t').value.trim(); if(!v) return false;
  await fetch(`${API}/messages_send.php?token=${encodeURIComponent(TOKEN)}`, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({conversation_id: CID, content: v})});
  document.getElementById('t').value='';
  await load();
  return false;
}
async function sendFile(){
  const f = document.getElementById('file').files[0]; if(!f) return;
  const fd = new FormData(); fd.append('conversation_id', String(CID)); fd.append('file', f, f.name);
  const r = await fetch(`${API}/messages_send_media.php?token=${encodeURIComponent(TOKEN)}`, {method:'POST', body: fd}).then(r=>r.json());
  if (!r.ok) alert(r.error||'upload failed');
  await load();
}
load(); setInterval(load, 4000);
</script>
<?php html_footer(); ?>
