<?php

require_once __DIR__ . '/../../lib/file_processor.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'ERROR', 'mensaje' => 'Use GET']);
    exit;
}

$startTime = microtime(true);

$scriptPath = __DIR__ . '/../../scripts/precios.sh';

$output = [];
$returnCode = 0;
$realScript = realpath($scriptPath);
exec("sudo " . escapeshellarg($realScript), $output, $returnCode);

if ($returnCode !== 0 && $returnCode !== 2) {
    http_response_code(500);
    echo json_encode([
        'status' => 'ERROR',
        'mensaje' => "precios.sh falló (código $returnCode)"
    ]);
    exit;
}

$changedFiles = array_values(array_filter($output, fn($line) => trim($line) !== ''));

$results = [];

foreach ($changedFiles as $relPath) {
    $results[] = processFile($relPath);
}

$elapsed = round(microtime(true) - $startTime, 2);

$ok = count(array_filter($results, fn($r) => $r['status'] === 'OK'));
$skip = count(array_filter($results, fn($r) => $r['status'] === 'SKIP'));
$err = count(array_filter($results, fn($r) => $r['status'] === 'ERROR'));

echo json_encode([
    'status' => 'OK',
    'elapsed' => $elapsed . 's',
    'total_files' => count($changedFiles),
    'inserted_updated' => $ok,
    'skipped' => $skip,
    'errors' => $err,
    'files' => $changedFiles,
    'results' => $results
]);
