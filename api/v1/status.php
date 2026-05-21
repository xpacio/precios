<?php

/**
 * Endpoint: GET /api/v1/status
 * Health check con verificación de DB y almacenamiento.
 */

header('Content-Type: text/plain');

$dbOk = true;
$storageOk = true;
$messages = [];

try {
    $pdo = getDB();
    $pdo->query('SELECT 1');
    $messages[] = 'DB: OK';
} catch (Exception $e) {
    $dbOk = false;
    $messages[] = 'DB: ERROR - ' . $e->getMessage();
}

$storagePath = __DIR__ . '/../../storage';
if (is_dir($storagePath) && is_writable($storagePath)) {
    $messages[] = 'STORAGE: OK';
} else {
    $storageOk = false;
    $messages[] = 'STORAGE: ERROR - No accesible o no escribible';
}

if (!$dbOk || !$storageOk) {
    http_response_code(503);
    echo "STATUS: DEGRADED\n";
} else {
    http_response_code(200);
    echo "STATUS: OK\n";
}

echo "VERSION: 1.0.0\n";
echo implode("\n", $messages) . "\n";
echo "TIMESTAMP: " . date('Y-m-d H:i:s') . "\n";
