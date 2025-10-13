<?php
// backend/web/workshops.php
declare(strict_types=1);
require __DIR__.'/web_common.php';
require_web_auth();

$skill = preg_replace('~[^a-z]+~','', strtolower($_GET['skill'] ?? 'all')) ?: 'all';

// Data
$ongoing = api_get('workshops_list.php', ['status'=>'ongoing','page'=>1,'limit'=>20]);
$artists = api_get('spotlight_leaderboard.php', [
  'days'=>30,
  'limit'=>12
] + ($skill==='all' ? [] : ['skill'=>$skill]));

render_header('Workshops');
?>
<style>
  .ws-row      { display:flex; align-items:center; gap:10px; margin-bottom:6px }
  .ws-track    { overflow:auto; padding-bottom:4px; margin-bottom:12px }
  .ws-track > .rail { display:flex; gap:12px }
  .ws-card     { min-width:240px; height:120px; text-decoration:none; color:inherit;
                 border:1px solid var(--bd); border-radius:16px; background:#fff; }
  .ws-card > .in { padding:12px; display:flex; flex-direction:column; height:100% }
  .ws-when     { font-size:12px; display:inline-flex; align-items:center; gap:6px; color:#111 }
  .ws-title    { margin-top:auto; font-weight:800; font-size:16px; line-height:1.2;
                 display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden }
  .muted       { color:#6b7280 }
  .chip        { display:inline-flex; padding:6px 10px; border-radius:999px; border:1px solid var(--bd); text-decoration:none; color:inherit }
  .chip.on     { background:#111; color:#fff; border-color:#111 }
  .btn.out     { background:#fff; color:#111; border:1px solid var(--bd); border-radius:999px; padding:8px 12px; text-decoration:none; display:inline-flex; align-items:center; gap:8px }
</style>

<!-- Info banner -->
<div class="card" style="padding:12px;margin-bottom:12px;background:#F7F8FA">
  Workshops are hosted live via Zoom/Meet. Tap a session to view details and join.
</div>

<!-- Ongoing (peek) -->
<div class="ws-row" style="flex-wrap:wrap">
  <b>Ongoing (peek)</b>
  <a class="btn out" href="<?=WEB_BASE?>/workshops_browse.php" title="Browse workshops"><?=icon('menu')?> Browse</a>
  <a href="<?=WEB_BASE?>/workshops.php" style="margin-left:auto">Refresh</a>
</div>

<div class="ws-track">
  <div class="rail">
    <?php foreach ((array)($ongoing['items'] ?? []) as $w):
      $w     = (array)$w;
      $wid   = (string)($w['id'] ?? '');
      $title = (string)($w['title'] ?? 'Session');
      $start = (string)($w['starts_at'] ?? '');
    ?>
      <a class="ws-card" href="<?=WEB_BASE?>/workshop_detail.php?id=<?=urlencode($wid)?>">
        <div class="in">
          <?php if ($start): ?>
            <div class="ws-when">
              <?=icon('clock')?> <time class="when" datetime="<?=h($start)?>" data-iso="<?=h($start)?>"><?=h($start)?></time>
            </div>
          <?php endif; ?>
          <div class="ws-title"><?=h($title)?></div>
        </div>
      </a>
    <?php endforeach; if (empty($ongoing['items'])): ?>
      <div class="muted">No current sessions</div>
    <?php endif; ?>
  </div>
</div>

<!-- Spotlight artists -->
<div style="border-top:1px solid var(--bd);padding-top:10px">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;flex-wrap:wrap">
    <div>
      <div style="font-weight:800">Spotlight artists</div>
      <div class="muted" style="font-size:12px">Applaud your favorites</div>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
      <?php foreach (skills_labels() as $k=>$lbl): $on = $skill===$k ? 'chip on' : 'chip'; ?>
        <a class="<?=$on?>" href="?skill=<?=h($k)?>"><?=h($lbl)?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div style="overflow:auto">
    <div style="display:flex;gap:12px;padding-bottom:4px">
      <?php foreach ((array)($artists['items'] ?? []) as $u):
        $u=(array)$u; $id=(string)($u['id']??'');
        $nm=(string)($u['display_name'] ?? $u['name'] ?? 'User');
        $hd=(string)($u['handle'] ?? ($u['username'] ? '@'.$u['username'] : ''));
        $av=(string)($u['avatar_url'] ?? ''); $score=(int)($u['score'] ?? 0); ?>
        <div class="card" style="min-width:160px;padding:12px;background:#F7F7F8">
          <a href="<?=WEB_BASE?>/public_profile.php?id=<?=urlencode($id)?>" style="text-decoration:none;color:inherit">
            <?php avatar_img($av, ['class'=>'ava', 'alt'=>'', 'style'=>'width:56px;height:56px;display:block;margin:0 auto']); ?>
            <div style="text-align:center;margin-top:10px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=h($nm)?></div>
            <?php if ($hd): ?><div class="muted" style="text-align:center;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=h($hd)?></div><?php endif; ?>
          </a>
          <form method="post" action="<?=WEB_BASE?>/public_profile.php?id=<?=urlencode($id)?>" style="margin-top:10px;text-align:center">
            <input type="hidden" name="action" value="spot_vote">
            <button class="btn out" type="submit" style="padding:6px 10px">
              <span style="display:inline-flex;gap:6px;align-items:center"><?=icon('flame')?> <b><?=$score?></b></span>
            </button>
          </form>
        </div>
      <?php endforeach; if (empty($artists['items'])): ?>
        <div class="muted">Nothing to show</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
// Format ISO timestamps into local time like "M/D • h:mm AM/PM"
(function(){
  const two = n => n < 10 ? '0'+n : ''+n;
  document.querySelectorAll('time.when').forEach(el => {
    const iso = el.dataset.iso || el.getAttribute('datetime') || '';
    const d = new Date(iso);
    if (!isNaN(d.getTime())) {
      const h12 = (d.getHours() % 12) || 12;
      const ampm = d.getHours() >= 12 ? 'PM' : 'AM';
      el.textContent = `${d.getMonth()+1}/${d.getDate()} • ${h12}:${two(d.getMinutes())} ${ampm}`;
    }
  });
})();
</script>

<?php render_footer(); ?>
