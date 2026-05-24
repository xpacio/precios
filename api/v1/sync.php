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
$fuenteFile = $baseScripts . '/archivosFuente.txt';
$getAllScript = $baseScripts . '/getAll.sh';

$lines = [];
foreach ($archivos as $a) {
    $rutaRel = preg_replace('#^/srv/precios/#', '', $a['ruta']);
    $lines[] = $rutaRel . '/' . $a['nombre'];
}
file_put_contents($fuenteFile, implode("\n", $lines) . "\n");
$output = [];
exec("sudo " . escapeshellarg($getAllScript) . " 2>&1", $output, $exitCode);

$outputText = implode("\n", $output);

// Parse [e1] lines and disable files not found on remote
$disableStmt = $pdo->prepare("UPDATE archivos SET enabled = FALSE, status = 'ausente' WHERE ruta = ? AND nombre = ?");
$disableCount = 0;
foreach ($output as $line) {
    if (preg_match('/\[e1\]\s+(.+)$/', $line, $m)) {
        $relPath = trim($m[1]);
        $dir = dirname($relPath);
        $file = basename($relPath);
        $disableStmt->execute([$dir, $file]);
        if ($disableStmt->rowCount() > 0) $disableCount++;
    }
}

// Re-fetch remaining enabled files after disabling absent ones
$stmt = $pdo->query("SELECT ruta, nombre FROM archivos WHERE enabled = TRUE ORDER BY ruta, nombre");
$archivosActivos = $stmt->fetchAll();

$procesados = 0;
$omitidos = 0;
$errores = 0;
$compresionLog = [];
$compresionLog[] = "--- Compresión brotli ---";

foreach ($archivosActivos as $a) {
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
$outputText .= "\n" . implode("\n", $compresionLog);

$elapsed = round(microtime(true) - $startTime, 2);

echo json_encode([
    'status' => $exitCode === 0 ? 'OK' : 'WARNING',
    'elapsed' => $elapsed . 's',
    'exit_code' => $exitCode,
    'enabled_count' => count($archivos),
    'disabled_count' => $disableCount,
    'procesados' => $procesados,
    'omitidos' => $omitidos,
    'errores' => $errores,
    'output' => $outputText,
]);
