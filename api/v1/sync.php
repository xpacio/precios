<?php

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'ERROR', 'mensaje' => 'Use GET']);
    exit;
}

$startTime = microtime(true);

$pdo = getDB();

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

$elapsed = round(microtime(true) - $startTime, 2);

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

echo json_encode([
    'status' => $exitCode === 0 ? 'OK' : 'WARNING',
    'elapsed' => $elapsed . 's',
    'exit_code' => $exitCode,
    'enabled_count' => count($archivos),
    'disabled_count' => $disableCount,
    'output' => $outputText,
]);
