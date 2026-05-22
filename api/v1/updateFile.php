<?php

require_once __DIR__ . '/../../lib/file_processor.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'ERROR', 'mensaje' => 'Use GET']);
    exit;
}

if (!isset($urlPath) || $urlPath === '') {
    http_response_code(400);
    echo json_encode(['status' => 'ERROR', 'mensaje' => 'Falta path del archivo']);
    exit;
}

$result = processFile($urlPath);

if ($result['status'] === 'ERROR') {
    http_response_code(500);
}
echo json_encode($result);
