#!/usr/bin/env php
<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Acceso denegado');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/sync_helper.php';

$startTime = microtime(true);

$pdo = getDB();
$pdo->exec("UPDATE archivos SET status = 'updating' WHERE enabled = TRUE");

$archivos = $pdo->query("SELECT ruta, nombre FROM archivos WHERE enabled = TRUE ORDER BY ruta, nombre")->fetchAll();

$lines = [];
foreach ($archivos as $a) {
    $r = preg_replace('#^/srv/precios/#', '', $a['ruta']);
    $lines[] = $r . '/' . $a['nombre'];
}
file_put_contents(__DIR__ . '/all.ls', implode("\n", $lines) . "\n");

exec("sudo " . escapeshellarg(__DIR__ . '/getAll.sh') . " --fast all.ls 2>&1", $output, $exitCode);

$procesados = $omitidos = $errores = 0;
foreach ($archivos as $a) {
    $r = preg_replace('#^/srv/precios/#', '', $a['ruta']);
    $n = $a['nombre'];
    if (!file_exists("/srv/precios/$r/$n")) { $omitidos++; continue; }
    $res = processAndCompressFile($r, $n);
    match ($res['status']) { 'OK' => $procesados++, 'SKIP' => $omitidos++, default => $errores++ };
}

$transferidos = extractTransferidos($output);
$elapsed = round(microtime(true) - $startTime, 2);
logSync($pdo, 'cron-full-fast', '--fast all.ls', count($archivos), $transferidos, $procesados, $omitidos, $errores, $exitCode, $elapsed);

$ok = ($exitCode === 0 && $errores === 0);
$status = $ok ? 'OK' : 'WARNING';
echo "[cron-all-fast] $status — total:" . count($archivos) . " transf:$transferidos ok:$procesados skip:$omitidos err:$errores ({$elapsed}s)\n";
exit($ok ? 0 : 1);
