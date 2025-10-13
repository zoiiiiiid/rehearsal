<?php
// backend/web/web_ping.php
// Quick diagnostics for web <-> API connectivity.

require_once __DIR__ . '/web_common.php'; // defines api_base(), html helpers, etc.

header('Content-Type: application/json; charset=utf-8');

// If web_common.php failed to load (or syntax error), report that instead of fataling.
if (!function_exists('api_base')) {
  $common = __DIR__ . '/web_common.php';
  echo json_encode([
    'ok'         => false,
    'error'      => 'COMMON_NOT_LOADED',
    'detail'     => 'api_base() is not defined. web_common.php was not included or crashed.',
    'file'       => $common,
    'exists'     => file_exists($common),
    'readable'   => is_readable($common),
    'php_errors_hint' => 'Open backend/web/web_common.php and check for syntax errors. Ensure the file is in backend/web/.',
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  exit;
}

// If we’re here, helpers exist — return useful environment info.
echo json_encode([
  'ok'          => true,
  'api_base'    => api_base(),
  'has_token'   => web_token() !== '',
  'WEB_DEBUG'   => defined('WEB_DEBUG') ? (WEB_DEBUG ? 1 : 0) : 0,
  'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? '',
  'SCRIPT_DIR'  => dirname($_SERVER['SCRIPT_NAME'] ?? '') ?: '',
  'HTTP_HOST'   => $_SERVER['HTTP_HOST'] ?? '',
  'API_BASE_env'=> getenv('API_BASE') ?: null,
  'curl'        => function_exists('curl_version') ? curl_version()['version'] : 'disabled',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
