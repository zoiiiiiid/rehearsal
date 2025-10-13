<?php
$env = require __DIR__ . '/.env.php';

$dsn = "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset={$env['DB_CHARSET']}";

$options = [
PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
PDO::ATTR_EMULATE_PREPARES => false,
];

try {
$pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], $options);
$pdo->query("SET sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

} catch (Throwable $e) {
http_response_code(500);
header('Content-Type: application/json');
echo json_encode(['error' => 'DB_CONNECTION_FAILED']);
exit;
}