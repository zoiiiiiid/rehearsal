<?php
// backend/web/web_common.php
// Shared helpers for the Web UI (no frameworks)

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* -------------------------------------------------------
 * Optionally load API .env.php so we can read overrides like ALLOW_WORKSHOP_CREATE
 * -----------------------------------------------------*/
if (!defined('ENV_LOADED')) {
  $envPath = __DIR__ . '/../api/.env.php';
  if (is_file($envPath)) {
    $cfg = include $envPath;
    if (is_array($cfg)) {
      // expose keys as getenv() too (best-effort)
      foreach ($cfg as $k => $v) {
        if (is_string($k) && getenv($k) === false) {
          @putenv($k . '=' . (is_scalar($v) ? (string)$v : ''));
        }
      }
    }
  }
  define('ENV_LOADED', true);
}

/* -------------------------------------------------------
 * Debug flag (set WEB_DEBUG=1 in env to enable)
 * -----------------------------------------------------*/
if (!defined('WEB_DEBUG')) {
  $e = getenv('WEB_DEBUG');
  define('WEB_DEBUG', $e === '1' || $e === 'true' || $e === 'on');
}

// -------- session ------------------------------------------------------------
function send_no_store_headers(): void {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
}
function send_no_store_headers_protected(): void {
  header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
}

/* -------------------------------------------------------
 * Tiny utils
 * -----------------------------------------------------*/
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url, int $code = 302): never {
  header('Location: '.$url, true, $code); exit;
}

/** Simple inline icons (expand as needed) */
function icon(string $name): string {
  $map = [
    'home'   => '<path d="M10 20v-6H6v6H2V10L12 3l10 7v10h-4v-6h-4v6z"/>',
    'bell'   => '<path d="M12 22a2 2 0 0 0 2-2H10a2 2 0 0 0 2 2zm6-6v-5a6 6 0 1 0-12 0v5l-2 2v1h16v-1l-2-2z"/>',
    'chat'   => '<path d="M21 15a4 4 0 0 1-4 4H9l-6 4V5a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4v10z"/>',
    'menu'   => '<path d="M3 6h18v2H3zM3 11h18v2H3zM3 16h18v2H3z"/>',
    'user'   => '<path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5zm0 2c-5 0-9 3-9 6v2h18v-2c0-3-4-6-9-6z"/>',
    'logout' => '<path d="M16 17l5-5-5-5v3H9v4h7v3zM4 4h5V2H4a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h5v-2H4V4z"/>',
    'heart'  => '<path d="M12 21s-8-4.35-8-10a5 5 0 0 1 9-3 5 5 0 0 1 9 3c0 5.65-8 10-8 10z"/>',
    'search' => '<path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zM9.5 14C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>',
    'comment'=> '<path d="M21 15a4 4 0 0 1-4 4H7l-4 3V5a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4v10z"/>',
    'plus'   => '<path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6z"/>',
    'flame'  => '<path d="M12 2C9 5 9 7.5 9 8.5c0 1.7 1.1 3.1 2.6 3.7-.1-.6-.1-1.2.2-1.9.7-1.6 2.5-2.8 4.2-2.8 0 4.2-1.3 6-3 7.5 2.6-.1 5-2.2 5-5.5 0-3.1-2.1-5.8-5-7.5zM8.5 11.5C6 12.7 5 14.8 5 16.6 5 19.6 7.4 22 10.5 22S16 19.6 16 16.6c0-.8-.1-1.6-.4-2.2-1.2 2-3.2 3.6-5.8 3.6-1.2 0-2.2-.3-3.3-.9.7-.8 1.7-1.8 2-3.6z"/>',
    'verify' => '<path d="M12 2l2.09 3.83L18 7l-1 4 3 3-4 .5L14 18l-2 4-2-4-2-3.5-4-.5 3-3-1-4 3.91-1.17L12 2zM11 14l6-6-1.41-1.42L11 11.17 8.41 8.59 7 10l4 4z"/>',
    'calendar-plus' => '<path d="M7 2v2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h9.5a4.5 4.5 0 1 0 0-9H5V8h14v2.5A4.48 4.48 0 0 0 17.5 10H19V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2H7zm10.5 12a3.5 3.5 0 1 1 0 7 3.5 3.5 0 0 1 0-7zm-.5 1.5v1.5h-1.5v1h1.5V18h1v-1.5H20v-1h-1.5V14h-1z"/>',
  ];
  $p = $map[$name] ?? '<circle cx="12" cy="12" r="10"/>';
  return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">'.$p.'</svg>';
}

/* -------------------------------------------------------
 * Token helpers (+ legacy names)
 * -----------------------------------------------------*/
function web_token(): string                { return isset($_SESSION['token']) ? (string)$_SESSION['token'] : ''; }
function web_set_token(string $t): void     { $_SESSION['token'] = $t; }
function web_clear_token(): void            { unset($_SESSION['token']); }

function current_token(): ?string           { $t = web_token(); return $t !== '' ? $t : null; } // legacy
function save_token(string $t): void        { web_set_token($t); }                               // legacy
function clear_token(): void                { web_clear_token(); }                               // legacy

/* -------------------------------------------------------
 * Auth guards
 * -----------------------------------------------------*/
function require_login_or_redirect(): void {
  if (web_token() !== '') return;
  $here = $_SERVER['REQUEST_URI'] ?? '/backend/web/index.php';
  redirect('/backend/web/web_login.php?next='.rawurlencode($here));
}
function require_web_auth(): void { require_login_or_redirect(); }

/* -------------------------------------------------------
 * Base path constants
 * -----------------------------------------------------*/
if (!defined('WEB_BASE')) {
  // e.g. /backend/web
  $self = dirname($_SERVER['SCRIPT_NAME'] ?? '/backend/web/index.php');
  define('WEB_BASE', rtrim($self, '/'));
}

/* Build base URL to /api/ */
function api_base(): string {
  $env = getenv('API_BASE');  // e.g. http://localhost/backend  (we’ll add /api)
  if ($env && $env !== '') {
    $env = rtrim($env, '/');
    if (!preg_match('~/api$~', $env)) $env .= '/api';
    return $env.'/';
  }
  $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
  $scheme = $https ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

  // assume web lives at /backend/web -> /backend/api
  $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/backend/web/index.php');
  $basePath  = preg_replace('~/web$~', '', rtrim($scriptDir, '/')); // -> /backend
  if (!$basePath) $basePath = '/backend';
  return $scheme.'://'.$host.$basePath.'/api/';
}
if (!defined('API_BASE_URL')) define('API_BASE_URL', api_base()); // some pages used a constant

/* -------------------------------------------------------
 * API helpers
 * -----------------------------------------------------*/
function api_get(string $endpoint, array $query = []) {
  $query['token'] = web_token();
  $url = rtrim(api_base(), '/').'/'.ltrim($endpoint, '/');
  $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);

  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20]);
  $out = curl_exec($ch);
  $err = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($out === false) return ['ok'=>false,'error'=>'CURL','detail'=>$err,'_http'=>$http];
  $json = json_decode($out, true);
  return is_array($json) ? $json : ['ok'=>false,'error'=>'BAD_JSON','detail'=>$out,'_http'=>$http];
}
function api_post_json(string $endpoint, array $payload) {
  $url = rtrim(api_base(), '/').'/'.ltrim($endpoint, '/');
  $url .= (str_contains($url, '?') ? '&' : '?').'token='.rawurlencode(web_token());

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT=>25,
  ]);
  $out = curl_exec($ch);
  $err = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($out === false) return ['ok'=>false,'error'=>'CURL','detail'=>$err,'_http'=>$http];
  $json = json_decode($out, true);
  return is_array($json) ? $json : ['ok'=>false,'error'=>'BAD_JSON','detail'=>$out,'_http'=>$http];
}
/** legacy alias some pages used */
function api_post(string $endpoint, array $payload) { return api_post_json($endpoint, $payload); }
/** x-www-form-urlencoded */
function api_post_form(string $endpoint, array $form) {
  $url = rtrim(api_base(), '/').'/'.ltrim($endpoint, '/');
  $url .= (str_contains($url, '?') ? '&' : '?').'token='.rawurlencode(web_token());

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>http_build_query($form),
    CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT=>25,
  ]);
  $out = curl_exec($ch);
  $err = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($out === false) return ['ok'=>false,'error'=>'CURL','detail'=>$err,'_http'=>$http];
  $json = json_decode($out, true);
  return is_array($json) ? $json : ['ok'=>false,'error'=>'BAD_JSON','detail'=>$out,'_http'=>$http];
}
/** multipart/form-data (for uploads) */
function api_post_multipart(string $endpoint, array $fields, array $files = []) {
  $url = rtrim(api_base(), '/').'/'.ltrim($endpoint, '/');
  $url .= (str_contains($url, '?') ? '&' : '?').'token='.rawurlencode(web_token());

  $payload = [];
  foreach ($fields as $k => $v) $payload[$k] = $v;
  foreach ($files as $k => $f) {
    if (is_array($f)) {
      $payload[$k] = new CURLFile($f['path'], $f['type'] ?? null, $f['name'] ?? null);
    } else {
      $payload[$k] = new CURLFile($f);
    }
  }

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>$payload,
    CURLOPT_TIMEOUT=>40,
  ]);
  $out = curl_exec($ch);
  $err = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($out === false) return ['ok'=>false,'error'=>'CURL','detail'=>$err,'_http'=>$http];
  $json = json_decode($out, true);
  return is_array($json) ? $json : ['ok'=>false,'error'=>'BAD_JSON','detail'=>$out,'_http'=>$http];
}

/* -------------------------------------------------------
 * Skill labels
 * -----------------------------------------------------*/
function skill_labels(): array {
  return [
    'all'         => 'All',
    'dj'          => 'DJ',
    'singer'      => 'Singer',
    'guitarist'   => 'Guitarist',
    'drummer'     => 'Drummer',
    'bassist'     => 'Bassist',
    'keyboardist' => 'Keyboardist',
    'dancer'      => 'Dancer',
    'other'       => 'Other',
  ];
}
function skills_labels(): array { return skill_labels(); }

/* -------------------------------------------------------
 * Avatars
 * -----------------------------------------------------*/
function default_avatar_data_uri(): string {
  static $uri = null;
  if ($uri !== null) return $uri;
  $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
       . '<circle fill="#e5e7eb" cx="32" cy="32" r="32"/>'
       . '<circle fill="#cbd5e1" cx="32" cy="26" r="12"/>'
       . '<path fill="#cbd5e1" d="M12 54c4-10 16-14 20-14s16 4 20 14"/>'
       . '</svg>';
  $uri = 'data:image/svg+xml;base64,'.base64_encode($svg);
  return $uri;
}
function avatar_url(?string $url): string {
  $u = trim((string)$url);
  if ($u === '' || strtolower($u) === 'null' || strtolower($u) === 'none') return default_avatar_data_uri();
  return $u;
}
function user_avatar_url(array $user): string {
  $cand = $user['avatar_url'] ?? $user['photo'] ?? $user['image_url'] ?? $user['picture'] ?? '';
  return avatar_url(is_string($cand) ? $cand : '');
}
function avatar_img($srcOrUser, array $attrs = []): void {
  $src = is_array($srcOrUser) ? user_avatar_url($srcOrUser) : avatar_url((string)$srcOrUser);
  $attrStr = '';
  $attrs = array_merge(['alt'=>'', 'class'=>'ava'], $attrs);
  foreach ($attrs as $k => $v) {
    if ($v === null || $v === '') continue;
    $attrStr .= ' '.htmlspecialchars($k, ENT_QUOTES).'="' . htmlspecialchars((string)$v, ENT_QUOTES) . '"';
  }
  echo '<img src="'.h($src).'"'.$attrStr.'>';
}

/* -------------------------------------------------------
 * Verified helpers (web)
 * -----------------------------------------------------*/
function _truthy($v): bool {
  if ($v === true || $v === 1) return true;
  if (is_numeric($v)) return (int)$v === 1;
  if (!is_string($v)) return false;
  $s = strtolower(trim($v));
  return in_array($s, ['1','true','yes','y','on'], true);
}
function env_truthy(string $name): bool {
  $v = getenv($name);
  if ($v === false) return false;
  return _truthy($v);
}

/** tolerant verified detection */
function is_verified_user(array $user): bool {
  if (!$user) return false;

  // ✅ Many installs mark verified via users.status
  $status = strtolower(trim((string)($user['status'] ?? '')));
  if ($status === 'verified' || $status === 'approved' || str_contains($status, 'verified')) {
    return true;
  }

  // Boolean-ish flags that may exist
  foreach (['verified','is_verified','mentor_verified','creator_verified','is_mentor_verified','is_creator_verified'] as $k) {
    if (array_key_exists($k, $user) && _truthy($user[$k])) return true;
  }

  // Timestamps as verification markers
  foreach (['verified_at','mentor_verified_at','creator_verified_at'] as $k) {
    if (!empty($user[$k])) return true;
  }

  // Status text fields returned by some APIs
  foreach (['verification_status','verify_status','verification','mentor_verification_status'] as $k) {
    $s = strtolower(trim((string)($user[$k] ?? '')));
    if (in_array($s, ['approved','verified','accepted','active'], true)) return true;
  }

  // Badge label sometimes used
  $badge = strtolower(trim((string)($user['badge'] ?? '')));
  if ($badge === 'verified') return true;

  return false;
}

/** single point for "who can host workshops" */
function can_host_workshops(array $user, array $rawMe = []): bool {
  if (is_admin_user($user)) return true;
  if (is_verified_user($user)) return true;

  // support API-provided flag if it ever appears
  $f = $user['can_host_workshops'] ?? ($rawMe['can_host_workshops'] ?? ($rawMe['user']['can_host_workshops'] ?? null));
  if (_truthy($f)) return true;

  // local override for testing
  if (env_truthy('ALLOW_WORKSHOP_CREATE')) return true;

  return false;
}

function verified_badge_html(): string {
  return '<span title="Verified mentor" class="badge" style="border-color:#0a7;color:#0a7;display:inline-flex;gap:4px;align-items:center;padding:2px 6px">'
       . '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M12 2l2.09 3.83L18 7l-1 4 3 3-4 .5L14 18l-2 4-2-4-2-3.5-4-.5 3-3-1-4 3.91-1.17L12 2zM11 14l6-6-1.41-1.42L11 11.17 8.41 8.59 7 10l4 4z"/></svg>'
       . 'Verified</span>';
}
function name_with_verified(array $user, string $name): string {
  return '<b>'.h($name).'</b>'.(is_verified_user($user) ? ' '.verified_badge_html() : '');
}

/* -------------------------------------------------------
 * Layout (white/black) – App layout with left nav
 * -----------------------------------------------------*/
function render_header(string $title, string $rightHtml = ''): void {
  send_no_store_headers_protected();

  // who am I (for nav decisions)
  $active = $_SERVER['SCRIPT_NAME'] ?? '';
  $me     = api_get('profile_overview.php');
  $meUser = (array)($me['user'] ?? ($me['profile'] ?? []));
  $isAdmin = is_admin_user($meUser);
  $isVerified = is_verified_user($meUser);
  $canHost = can_host_workshops($meUser, $me);

  // show verification link if cannot host (and not admin)
  $showVerifyLink = !$canHost && !$isAdmin;
  $verifyLabel = 'Verification';
  $verifyStatus = strtolower((string)($meUser['verification_status'] ?? $meUser['verify_status'] ?? ''));
  if ($showVerifyLink && $verifyStatus === 'pending') $verifyLabel = 'Verification (pending)';

  ?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($title)?> • Re:hearsal</title>
<style>
  :root{--txt:#111;--bg:#fff;--mut:#6b7280;--bd:#e5e7eb;--card:#fff}
  *{box-sizing:border-box}
  body{margin:0;color:var(--txt);background:var(--bg);font:14px/1.35 system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial,sans-serif}
  a{color:#111;text-decoration:none}
  a:hover{text-decoration:underline}
  .wrap{display:grid;grid-template-columns:220px 1fr;min-height:100vh}
  .side{border-right:1px solid var(--bd);padding:18px 14px;position:sticky;top:0;align-self:start;height:100vh;overflow:auto;display:flex;flex-direction:column;gap:10px}
  .brand{font-weight:800;letter-spacing:.2px;margin:4px 0 6px}
  .nav{display:flex;flex-direction:column;gap:2px}
  .nav a{display:flex;align-items:center;gap:10px;padding:10px 10px;border-radius:10px}
  .nav a.active{background:#f5f5f5}
  .nav-bottom{margin-top:auto;padding-top:10px;border-top:1px solid var(--bd)}
  .main{padding:18px}
  .chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
  .chip{display:inline-flex;padding:6px 12px;border:1px solid var(--bd);border-radius:999px}
  .chip.on{background:#111;color:#fff;border-color:#111}
  .card{background:var(--card);border:1px solid var(--bd);border-radius:12px}
  .post-head{display:flex;align-items:center;gap:10px;padding:12px;border-bottom:1px solid var(--bd)}
  .ava{width:34px;height:34px;border-radius:999px;object-fit:cover;background:#eee}
  .handle{color:var(--mut);font-size:12px}
  .badge{font-size:11px;border:1px solid #222;border-radius:6px;padding:2px 6px;margin-left:8px}
  .post-media img{width:100%;height:auto;display:block}
  .post-body{padding:12px}
  .hr{height:1px;background:var(--bd);margin:10px 0}
  .actions-row{display:flex;align-items:center;gap:8px}
  .counts{display:flex;gap:10px;color:var(--mut);font-size:12px}
  .btn{display:inline-flex;align-items:center;gap:8px;background:#111;color:#fff;border:0;border-radius:999px;padding:9px 14px;font-weight:700}
  .btn.out{background:#fff;color:#111;border:1px solid var(--bd)}
  .muted{color:var(--mut)}
  .feed-card{max-width:760px;margin:0 auto}
  .post-media{max-height:70vh;overflow:hidden}
  .post-media img{max-height:520px;object-fit:cover}
</style>

<script>
  window.DEF_AVA = <?= json_encode(default_avatar_data_uri()) ?>;
</script>
</head><body>
<div class="wrap">
  <aside class="side">
    <div class="brand">RE:HEARSAL</div>
    <?php if (WEB_DEBUG): ?>
      <div class="muted" style="font-size:11px">
        dbg: role=<?=h((string)($meUser['role'] ?? ''))?>,
        status=<?=h((string)($meUser['status'] ?? ''))?>,
        admin=<?=$isAdmin?1:0?>, verified=<?=$isVerified?1:0?>, canHost=<?=$canHost?1:0?>
      </div>
    <?php endif; ?>

    <!-- Top section -->
    <nav class="nav nav-top" aria-label="Main navigation">
      <a href="<?=WEB_BASE?>/index.php"         class="<?= $active===WEB_BASE.'/index.php'         ? 'active' : '' ?>"><?=icon('home')?> Home</a>
      <a href="<?=WEB_BASE?>/search.php"        class="<?= $active===WEB_BASE.'/search.php'        ? 'active' : '' ?>"><?=icon('search')?> Search</a>
      <a href="<?=WEB_BASE?>/notifications.php" class="<?= $active===WEB_BASE.'/notifications.php' ? 'active' : '' ?>"><?=icon('bell')?> Notifications</a>
      <a href="<?=WEB_BASE?>/create.php"        class="<?= $active===WEB_BASE.'/create.php'        ? 'active' : '' ?>"><?=icon('plus')?> Create</a>
      <a href="<?=WEB_BASE?>/inbox.php"         class="<?= $active===WEB_BASE.'/inbox.php'         ? 'active' : '' ?>"><?=icon('chat')?> Inbox</a>
      <a href="<?=WEB_BASE?>/workshops.php"     class="<?= $active===WEB_BASE.'/workshops.php'     ? 'active' : '' ?>"><?=icon('menu')?> Workshops</a>
      <?php if ($canHost): ?>
        <a href="<?=WEB_BASE?>/workshop_create.php" class="<?= $active===WEB_BASE.'/workshop_create.php' ? 'active' : '' ?>"><?=icon('calendar-plus')?> New Workshop</a>
      <?php endif; ?>
    </nav>

    <!-- Bottom anchored account section -->
    <nav class="nav nav-bottom" aria-label="Account">
      <?php if ($isAdmin): ?>
        <a href="<?=WEB_BASE?>/admin_reports.php" class="<?= $active===WEB_BASE.'/admin_reports.php' ? 'active' : '' ?>"><?=icon('menu')?> Admin Reports</a>
      <?php endif; ?>
      <?php if ($showVerifyLink): ?>
        <a href="<?=WEB_BASE?>/verification_apply.php" class="<?= $active===WEB_BASE.'/verification_apply.php' ? 'active' : '' ?>"><?=icon('verify')?> <?=h($verifyLabel)?></a>
      <?php endif; ?>
      <a href="<?=WEB_BASE?>/profile.php" class="<?= $active===WEB_BASE.'/profile.php' ? 'active' : '' ?>"><?=icon('user')?> Profile</a>
      <a href="<?=WEB_BASE?>/web_login.php?logout=1"><?=icon('logout')?> Logout</a>
    </nav>
  </aside>

  <main class="main">
    <h2 style="margin:0 0 12px 0"><?=h($title)?></h2>
<?php
}
function render_footer(): void { echo "</main></div></body></html>"; }

/* Legacy aliases */
function html_header(string $title, string $rightHtml = ''): void { render_header($title, $rightHtml); }
function html_footer(): void { render_footer(); }

/* -------------------------------------------------------
 * Auth layout (NO sidebar)
 * -----------------------------------------------------*/
function render_auth_header(string $title): void {
  send_no_store_headers();
  ?>
  <!doctype html><html lang="en"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?=h($title)?> • Re:hearsal</title>
  <style>
    :root{--txt:#111;--bg:#fff;--mut:#6b7280;--bd:#e5e7eb;--card:#fff}
    *{box-sizing:border-box}
    body{margin:0;color:var(--txt);background:var(--bg);font:14px/1.35 system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial,sans-serif}
    .center{min-height:100vh;display:grid;place-items:start center;padding-top:60px}
    .auth-card{background:var(--card);border:1px solid var(--bd);border-radius:12px;max-width:520px;width:92%;padding:14px 14px 18px}
    .brand{font-weight:800;text-align:center;margin:6px 0 10px}
    label{display:block;font-size:12px;color:#444;margin:6px 0 4px}
    input[type=text],input[type=password]{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;background:#fff}
    .row{margin-top:8px}
    .muted{color:var(--mut);font-size:12px;text-align:center}
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;background:#111;color:#fff;border:0;border-radius:999px;padding:10px 14px;font-weight:700;cursor:pointer}
    .btn.out{background:#fff;color:#111;border:1px solid var(--bd)}
    .actions{display:flex;gap:8px;align-items:center;justify-content:space-between;margin-top:10px}
    .error{background:#fff0f0;border:1px solid #ffd1d1;color:#b00000;padding:10px;border-radius:10px;margin-bottom:10px}
  </style>
  </head><body>
  <div class="center">
    <div class="auth-card">
      <div class="brand">RE:HEARSAL</div>
  <?php
}
function render_auth_footer(): void { echo "</div></div></body></html>"; }

/* -------------------------------------------------------
 * CSRF (simple, per-session token)
 * -----------------------------------------------------*/
function csrf_token(): string {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}
function csrf_field(): void { echo '<input type="hidden" name="_csrf" value="'.h(csrf_token()).'">'; }
function csrf_check(): void {
  $ok = !empty($_POST['_csrf']) && hash_equals(csrf_token(), (string)$_POST['_csrf']);
  if (!$ok) {
    http_response_code(400);
    echo '<div class="card" style="padding:12px;color:#a00;border-color:#f3c"><b>CSRF failed</b></div>';
    render_footer(); exit;
  }
}

/* -------------------------------------------------------
 * Admin checks
 * -----------------------------------------------------*/
function is_admin_user(array $user): bool {
  return strtolower((string)($user['role'] ?? '')) === 'admin';
}
function require_admin_or_403(): void {
  $me = api_get('profile_overview.php');
  $user = (array)($me['user'] ?? []);
  if (!is_admin_user($user)) {
    http_response_code(403);
    render_header('Forbidden');
    echo '<div class="card" style="padding:16px"><b>403</b> — Admins only.</div>';
    render_footer();
    exit;
  }
}

/* -------------------------------------------------------
 * Debug helper (pretty JSON)
 * -----------------------------------------------------*/
function debug_block(string $title, $data): void {
  if (!WEB_DEBUG) return;
  $content = is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
  echo '<div class="card" style="margin-top:12px;padding:12px"><div class="muted" style="margin-bottom:6px">'.h($title).'</div><pre style="white-space:pre-wrap">'.h($content).'</pre></div>';
}
