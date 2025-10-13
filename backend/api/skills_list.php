<?php
// Returns the fixed, supported skills list (lowercase keys)
header('Content-Type: application/json');
echo json_encode([
  'skills' => [
    'dj','singer','guitarist','drummer','bassist','keyboardist','dancer','other'
  ]
]);
