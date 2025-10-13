<?php
// backend/web/web_login.php

// -------- bootstrap ----------------------------------------------------------
$common = __DIR__ . '/web_common.php';
if (!is_file($common)) {
  header('Content-Type: text/plain; charset=utf-8', true, 500);
  echo "FATAL: web_common.php not found at: $common\n";
  exit;
}
require_once $common;

// Use the shared no-store headers (fallback if missing)
if (function_exists('send_no_store_headers')) {
  send_no_store_headers();
} else {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
}

if (session_status() === PHP_SESSION_NONE) session_start();

// -------- logout -------------------------------------------------------------
if (($_GET['logout'] ?? '') === '1') {
  web_clear_token();
  // extra hardening: clear session cookie + rotate id
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(
      session_name(),
      '',
      time() - 42000,
      $p['path'],
      $p['domain'] ?? '',
      $p['secure'] ?? false,
      $p['httponly'] ?? true
    );
  }
  session_regenerate_id(true);
  redirect(WEB_BASE . '/web_login.php');
}

// If already logged in, bounce to home (no cached flicker because of no-store)
if (web_token() !== '') {
  redirect(WEB_BASE . '/index.php');
}

$err  = '';
$raw  = null;
$next = isset($_GET['next']) ? (string)$_GET['next'] : (WEB_BASE . '/index.php');

// -------- submit -------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $id   = trim((string)($_POST['id'] ?? ''));
  $pass = trim((string)($_POST['password'] ?? ''));
  $next = (string)($_POST['next'] ?? $next);

  if ($id === '' || $pass === '') {
    $err = 'Please enter your email/username and password.';
  } else {
    // Email or username -> API payload
    $payload = ['password' => $pass];
    if (strpos($id, '@') !== false) { $payload['email'] = $id; }
    else                             { $payload['username'] = $id; }

    // Call your API
    $res = api_post_json('login.php', $payload);
    $raw = $res;

    // Extract token from common shapes
    $token = '';
    if (is_array($res)) {
      if (!empty($res['token']))             $token = (string)$res['token'];
      elseif (!empty($res['data']['token'])) $token = (string)$res['data']['token'];
      elseif (!empty($res['auth']['token'])) $token = (string)$res['auth']['token'];
    }

    if (($res['ok'] ?? false) || $token !== '') {
      if ($token === '' && isset($res['token'])) $token = (string)$res['token'];

      // Regenerate session id to prevent fixation and persist the token
      session_regenerate_id(true);
      web_set_token($token);

      redirect($next ?: (WEB_BASE . '/index.php'));
    } else {
      $http = isset($res['_http']) ? (' (HTTP ' . $res['_http'] . ')') : '';
      $err  = ($res['detail'] ?? $res['error'] ?? 'Login failed (SERVER).') . $http;
    }
  }
}

// -------- view ---------------------------------------------------------------
render_auth_header('Login');
?>
  <?php if ($err): ?>
    <div class="error"><b>Error:</b> <?= h($err) ?></div>
  <?php endif; ?>

  <form method="post" action="<?= h(WEB_BASE) ?>/web_login.php<?= isset($_GET['next']) ? ('?next=' . urlencode($_GET['next'])) : '' ?>">
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <div class="row">
      <label>Email or username</label>
      <input type="text" name="id" autocomplete="username" required />
    </div>
    <div class="row">
      <label>Password</label>
      <input type="password" name="password" autocomplete="current-password" required />
    </div>

    <div class="actions">
      <button class="btn" type="submit" style="flex:1">Login</button>
      <a class="btn out" href="<?= h(WEB_BASE) ?>/web_login.php">Cancel</a>
    </div>

    <div class="muted" style="margin-top:10px">
      Don’t have an account? <a href="<?= h(WEB_BASE) ?>/web_register.php">Create one</a>
    </div>
  </form>

  <?php if (WEB_DEBUG && $raw !== null) { debug_block('LOGIN API RAW RESPONSE', $raw); } ?>

  <!-- BFCache guard: if user navigates Back here after logging in, redirect to Home.
       Also, if this page was restored from the BFCache (persisted), force a reload
       so server-side no-store logic can redirect appropriately. -->
  <script>
    (function(){
      const LOGGED_IN = <?= json_encode(web_token() !== '') ?>;
      const HOME_URL  = <?= json_encode(WEB_BASE . '/index.php') ?>;

      if (LOGGED_IN) {
        // Avoid extra history entry; replaces current login page
        location.replace(HOME_URL);
      }

      window.addEventListener('pageshow', function (e) {
        if (LOGGED_IN) {
          location.replace(HOME_URL);
          return;
        }
        if (e.persisted) {
          // If page came from BFCache and user might have just logged in/out elsewhere,
          // do a hard reload so PHP can enforce redirects.
          location.reload();
        }
      }, { once: true });
    })();
  </script>
<?php render_auth_footer();
