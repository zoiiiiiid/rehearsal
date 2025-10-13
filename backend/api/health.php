<?php
require __DIR__.'/db.php';
header('Content-Type: application/json');
echo json_encode(['ok'=>true, 'db'=>true, 'time'=>date('c')]);