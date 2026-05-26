<?php

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'ERROR', 'mensaje' => 'Use POST']);
    exit;
}

require_once __DIR__ . '/../../lib/sync_helper.php';

$input = json_decode(file_get_contents('php://input'), true);
$files = $input['files'] ?? [];

if (empty($files)) {
    http_response_code(400);
    echo json_encode(['status' => 'ERROR', 'mensaje' => 'No se enviaron archivos']);
    exit;
}

$startTime = microtime(true);

$baseScripts = realpath(__DIR__ . '/../../scripts');
$fuenteFile = $baseScripts . '/selected.ls';
$getAllScript = $baseScripts . '/getAll.sh';

$lines = [];
foreach ($files as $f) {
    $lines[] = $f['ruta'] . '/' . $f['nombre'];
}
file_put_contents($fuenteFile, implode("\n", $lines) . "\n");

$rsyncOutput = [];
exec("sudo " . escapeshellarg($getAllScript) . " --fast " . escapeshellarg('selected.ls') . " 2>&1", $rsyncOutput, $exitCode);

$resultados = [];
$procesados = 0;
$omitidos = 0;
$errores = 0;

foreach ($files as $f) {
    $r = $f['ruta'];
    $n = $f['nombre'];
    $fullPath = '/srv/precios/' . $r . '/' . $n;

    if (!file_exists($fullPath)) {
        $resultados[] = [
            'ruta' => $r,
            'nombre' => $n,
            'sync' => 'AUSENTE',
            'compresion' => null,
            'mensaje' => 'No encontrado en disco tras rsync',
        ];
        $errores++;
        continue;
    }

    $res = processAndCompressFile($r, $n);
    $resultados[] = [
        'ruta' => $r,
        'nombre' => $n,
        'sync' => 'OK',
        'compresion' => $res['status'],
        'mensaje' => $res['mensaje'] ?? '',
    ];
    if ($res['status'] === 'OK') $procesados++;
    elseif ($res['status'] === 'SKIP') $omitidos++;
    else $errores++;
}

$elapsed = round(microtime(true) - $startTime, 2);

$transferidos = extractTransferidos($rsyncOutput);
logSync($pdo, 'selected', '--fast selected.ls', count($files), $transferidos, $procesados, $omitidos, $errores, $exitCode, $elapsed);

echo json_encode([
    'status' => $exitCode === 0 ? 'OK' : 'WARNING',
    'elapsed' => $elapsed . 's',
    'rsync_exit' => $exitCode,
    'total' => count($files),
    'transferidos' => $transferidos,
    'procesados' => $procesados,
    'omitidos' => $omitidos,
    'errores' => $errores,
    'resultados' => $resultados,
]);
