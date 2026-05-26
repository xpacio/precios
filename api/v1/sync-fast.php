<?php

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'ERROR', 'mensaje' => 'Use GET']);
    exit;
}

require_once __DIR__ . '/../../lib/sync_helper.php';

$startTime = microtime(true);

$pdo = getDB();

$pdo->exec("UPDATE archivos SET status = 'updating' WHERE enabled = TRUE");

$stmt = $pdo->query("SELECT ruta, nombre FROM archivos WHERE enabled = TRUE ORDER BY ruta, nombre");
$archivos = $stmt->fetchAll();

$baseScripts = realpath(__DIR__ . '/../../scripts');
$fuenteFile = $baseScripts . '/all.ls';
$getAllScript = $baseScripts . '/getAll.sh';

$lines = [];
foreach ($archivos as $a) {
    $rutaRel = preg_replace('#^/srv/precios/#', '', $a['ruta']);
    $lines[] = $rutaRel . '/' . $a['nombre'];
}
file_put_contents($fuenteFile, implode("\n", $lines) . "\n");

$rsyncOutput = [];
exec("sudo " . escapeshellarg($getAllScript) . " --fast " . escapeshellarg('all.ls') . " 2>&1", $rsyncOutput, $exitCode);

$rsyncTexto = implode("\n", $rsyncOutput);

$procesados = 0;
$omitidos = 0;
$errores = 0;
$compresionLog = [];
$compresionLog[] = "--- Compresión brotli ---";

foreach ($archivos as $a) {
    $r = preg_replace('#^/srv/precios/#', '', $a['ruta']);
    $n = $a['nombre'];
    if (!file_exists('/srv/precios/' . $r . '/' . $n)) {
        $compresionLog[] = "$r/$n → AUSENTE (no está en disco)";
        $omitidos++;
        continue;
    }
    $result = processAndCompressFile($r, $n);
    $compresionLog[] = "$r/$n → {$result['status']} ({$result['mensaje']})";
    if ($result['status'] === 'OK') $procesados++;
    elseif ($result['status'] === 'SKIP') $omitidos++;
    else $errores++;
}

$compresionLog[] = "Resumen: $procesados comprimidos, $omitidos sin cambios, $errores errores";
$outputCompleto = $rsyncTexto . "\n" . implode("\n", $compresionLog);

$elapsed = round(microtime(true) - $startTime, 2);

echo json_encode([
    'status' => ($exitCode === 0) ? 'OK' : 'WARNING',
    'elapsed' => $elapsed . 's',
    'exit_code' => $exitCode,
    'enabled_count' => count($archivos),
    'procesados' => $procesados,
    'omitidos' => $omitidos,
    'errores' => $errores,
    'output' => $outputCompleto,
]);
