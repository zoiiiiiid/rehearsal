<?php
// CORS (API)
header('Access-Control-Allow-Origin: *');                       // or restrict to your dev origin
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// Early return for preflight
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

if (!defined('APP_SECRET')) {
  define('APP_SECRET', getenv('APP_SECRET') ?: 'dev-secret-change-me');
}

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
if (!ob_get_level()) ob_start();

/* ----------------------------------------------------------------------------
 * Load local env (.env.php returns an array) and provide env/config helpers
 * --------------------------------------------------------------------------*/
if (!isset($GLOBALS['APP_CONFIG'])) {
  $cfg = [];
  $envFile = __DIR__.'/.env.php';
  if (is_file($envFile)) {
    $ret = require $envFile;           // returns array per your current file
    if (is_array($ret)) $cfg = $ret;
  }
  $GLOBALS['APP_CONFIG'] = $cfg;
}

/** env() – read from real env vars first, then .env.php array, else default */
if (!function_exists('env')) {
  function env(string $key, $default = null) {
    $v = getenv($key);
    if ($v !== false && $v !== '') return $v;
    $cfg = $GLOBALS['APP_CONFIG'] ?? [];
    if (isset($cfg[$key]) && $cfg[$key] !== '') return $cfg[$key];
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
    return $default;
  }
}

/** Optional: tiny config() wrapper for your array keys */
if (!function_exists('config')) {
  function config(string $key, $default = null) {
    $cfg = $GLOBALS['APP_CONFIG'] ?? [];
    return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
  }
}

/** Shared attendance HMAC secret (used by attendance_* endpoints) */
if (!function_exists('hmac_secret')) {
  function hmac_secret(): string {
    $s = env('ATTENDANCE_HMAC_SECRET', 'dev_demo_secret_change_me');
    if (strlen($s) < 16) $s = 'dev_demo_secret_change_me';
    return $s;
  }
}

/** Zoom helpers (server-to-server OAuth creds supplied via env or .env.php) */
if (!function_exists('zoom_config')) {
  function zoom_config(): array {
    return [
      'account_id'    => env('ZOOM_ACCOUNT_ID',    ''),
      'client_id'     => env('ZOOM_CLIENT_ID',     ''),
      'client_secret' => env('ZOOM_CLIENT_SECRET', ''),
    ];
  }
}
if (!function_exists('zoom_enabled')) {
  function zoom_enabled(): bool {
    $z = zoom_config();
    return $z['account_id'] !== '' && $z['client_id'] !== '' && $z['client_secret'] !== '';
  }
}

/* ----------------------------------------------------------------------------
 * NEW: tolerant booleans + verified/host checks (API side)
 * --------------------------------------------------------------------------*/

/** Treat a variety of common “truthy” values as true. */
if (!function_exists('truthy')) {
  function truthy($v): bool {
    if ($v === true || $v === 1) return true;
    if (is_numeric($v)) return (int)$v === 1;
    if (!is_string($v)) return false;
    $s = strtolower(trim($v));
    return in_array($s, ['1','true','yes','y','on'], true);
  }
}

/** Accept many shapes for "verified" so web & api agree */
if (!function_exists('is_verified_user_api')) {
  function is_verified_user_api(array $u): bool {
    if (!$u) return false;

    $status = strtolower(trim((string)($u['status'] ?? '')));
    if ($status === 'verified' || $status === 'approved' || strpos($status, 'verified') !== false) {
      return true;
    }

    foreach (['verified','is_verified','mentor_verified','creator_verified','is_mentor_verified','is_creator_verified'] as $k) {
      if (array_key_exists($k, $u) && truthy($u[$k])) return true;
    }

    foreach (['verified_at','mentor_verified_at','creator_verified_at'] as $k) {
      if (!empty($u[$k])) return true;
    }

    foreach (['verification_status','verify_status','verification','mentor_verification_status'] as $k) {
      $s = strtolower(trim((string)($u[$k] ?? '')));
      if (in_array($s, ['approved','verified','accepted','active'], true)) return true;
    }

    $badge = strtolower(trim((string)($u['badge'] ?? '')));
    if ($badge === 'verified') return true;

    return false;
  }
}

/** Single point of truth for who may create/host workshops */
if (!function_exists('can_host_workshops_api')) {
  function can_host_workshops_api(array $u): bool {
    if (strtolower((string)($u['role'] ?? '')) === 'admin') return true;
    if (is_verified_user_api($u)) return true;
    if (truthy($u['can_host_workshops'] ?? false)) return true;

    $ov = env('ALLOW_WORKSHOP_CREATE', null);
    if ($ov !== null && truthy($ov)) return true;

    return false;
  }
}

/** Cache list of existing columns for `users` table and return as array */
if (!function_exists('users_existing_columns')) {
  function users_existing_columns(PDO $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
      $rows = $db->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_ASSOC);
      $cache = array_map(fn($r) => (string)$r['Field'], $rows ?: []);
    } catch (Throwable $e) {
      $cache = [];
    }
    return $cache;
  }
}

/**
 * Hydrate extra verification-related columns if they are missing on $me.
 * Now SAFE: selects only columns that actually exist in `users`.
 */
if (!function_exists('hydrate_user_verification_fields')) {
  function hydrate_user_verification_fields(PDO $db, array $me): array {
    $interestingKeys = [
      'verified','is_verified','mentor_verified','creator_verified',
      'verified_at','mentor_verified_at','creator_verified_at',
      'verification_status','verify_status','badge','can_host_workshops',
      'role','status',
    ];
    // If any already present, no need to hit DB.
    foreach ($interestingKeys as $k) {
      if (array_key_exists($k, $me)) return $me;
    }

    $have = users_existing_columns($db);
    if (empty($have)) return $me;

    // Only select columns that exist.
    $cols = array_values(array_intersect($have, $interestingKeys));
    if (empty($cols)) return $me;

    // Build quoted column list.
    $select = implode(',', array_map(fn($c) => "`$c`", $cols));
    $st = $db->prepare("SELECT $select FROM `users` WHERE `id` = :id LIMIT 1");
    $st->execute([':id' => $me['id'] ?? 0]);
    if ($extra = $st->fetch(PDO::FETCH_ASSOC)) {
      $me = array_merge($me, $extra);
    }
    return $me;
  }
}

/**
 * Convenience guard for endpoints that require hosting permission.
 * Usage in workshop_create.php:
 *   $me = require_host_or_403($db);
 */
if (!function_exists('require_host_or_403')) {
  function require_host_or_403(PDO $db): array {
    $me = require_auth($db);
    $me = hydrate_user_verification_fields($db, $me);
    if (!can_host_workshops_api($me)) {
      json_out(['error' => 'FORBIDDEN', 'detail' => 'Verified creators only'], 403);
    }
    return $me;
  }
}

/* ----------------------------------------------------------------------------
 * Existing helpers (unchanged behavior)
 * --------------------------------------------------------------------------*/
function cors(){
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, Authorization');
  if ($_SERVER['REQUEST_METHOD']==='OPTIONS'){ http_response_code(204); exit; }
  header('Content-Type: application/json');
}

function json_out($data,$code=200){
  if (ob_get_length()>0) @ob_clean();
  http_response_code($code);
  echo json_encode($data);
  exit;
}

// UPDATED: read token from Authorization header OR query/body fallbacks
function bearer_token(){
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
  if ($h && preg_match('/Bearer\s+(.*)$/i', $h, $m)) return $m[1];
  if (!empty($_GET['token']))  return $_GET['token'];
  if (!empty($_POST['token'])) return $_POST['token'];
  return null;
}

function input_json_or_form(): array {
  $in = [];
  $ct = $_SERVER['CONTENT_TYPE'] ?? '';
  $raw = file_get_contents('php://input');
  if ($raw && (stripos($ct, 'application/json') !== false || (isset($raw[0]) && ($raw[0] === '{' || $raw[0] === '[')))) {
    $j = json_decode($raw, true);
    if (is_array($j)) $in = $j;
  }
  if (!empty($_POST)) {
    foreach ($_POST as $k => $v) if (!array_key_exists($k, $in)) $in[$k] = $v;
  }
  if (!empty($_GET)) {
    foreach ($_GET as $k => $v) if (!array_key_exists($k, $in)) $in[$k] = $v;
  }
  return $in;
}

function input_any(){
  $raw = file_get_contents('php://input');
  $in = json_decode($raw,true); if(!is_array($in)) $in = [];
  if (empty($in) && !empty($_POST)) $in = $_POST;
  return $in;
}

function auth_user_or_null(PDO $db): ?array {
  $t = bearer_token();
  if (!$t) return null;
  $sql = "SELECT u.id, u.name, u.role, u.status
            FROM auth_tokens a
            JOIN users u ON u.id = a.user_id
           WHERE a.token = :t AND a.expires_at > NOW()
           LIMIT 1";
  $st = $db->prepare($sql);
  $st->execute([':t' => $t]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

// Require auth or emit error
function require_auth(PDO $db): array {
  $u = auth_user_or_null($db);
  if (!$u) json_out(['error' => 'NO_TOKEN'], 401);
  if (!empty($u['status']) && strtolower((string)$u['status']) === 'suspended') json_out(['error' => 'SUSPENDED'], 403);
  return $u;
}

function body_json(){ $in=input_any(); if(empty($in)) json_out(['error'=>'MISSING_FIELDS'],422); return $in; }

function require_token(){ $t=bearer_token(); if(!$t) json_out(['error'=>'NO_TOKEN'],401); return $t; }
function user_by_token(PDO $pdo,string $t){
  $st=$pdo->prepare('SELECT u.* FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE t.token=? AND t.expires_at>NOW() LIMIT 1');
  $st->execute([$t]); return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
