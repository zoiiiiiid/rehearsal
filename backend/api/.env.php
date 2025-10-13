<?php

return [
  // --- Database ---
  'DB_HOST'   => getenv('DB_HOST') ?: '127.0.0.1',
  'DB_NAME'   => getenv('DB_NAME') ?: 'rehersal_db',
  'DB_USER'   => getenv('DB_USER') ?: 'root',
  'DB_PASS'   => getenv('DB_PASS') ?: '',
  'DB_CHARSET'=> 'utf8mb4',

  // --- Auth token TTL (minutes) ---
  'TOKEN_TTL_MIN' => getenv('TOKEN_TTL_MIN') ?: 43200, // 30 days

  // Optional flags for web (some pages read getenv directly)
  'WEB_DEBUG' => getenv('WEB_DEBUG') ?: '0',
];
