<?php

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'ERROR', 'mensaje' => 'Use GET']);
    exit;
}

$startTime = microtime(true);

$baseScripts = realpath(__DIR__ . '/../../scripts');
$preciosScript = $baseScripts . '/precios.sh';
$updateScript  = $baseScripts . '/updatePrecios.sh';

$rsyncOutput = [];
exec("sudo " . escapeshellarg($preciosScript) . " 2>&1", $rsyncOutput, $rsyncCode);

$upOutput = [];
$upCode = 0;
exec("sudo " . escapeshellarg($updateScript) . " 2>&1", $upOutput, $upCode);

$elapsed = round(microtime(true) - $startTime, 2);

$outputText = implode("\n", $rsyncOutput);
if (!empty($upOutput)) {
    if (!empty($outputText)) $outputText .= "\n";
    $outputText .= implode("\n", $upOutput);
}

echo json_encode([
    'status' => 'OK',
    'elapsed' => $elapsed . 's',
    'rsync_code' => $rsyncCode,
    'update_code' => $upCode,
    'output' => $outputText
]);
