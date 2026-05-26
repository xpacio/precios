<?php

require_once __DIR__ . '/../../config/database.php';

$pdo = getDB();
$stmt = $pdo->query("SELECT ruta, nombre FROM archivos WHERE enabled = TRUE ORDER BY ruta, nombre");
$archivos = $stmt->fetchAll();

$lines = [];
foreach ($archivos as $a) {
    $rutaRel = preg_replace('#^/srv/precios/#', '', $a['ruta']);
    $lines[] = $rutaRel . '/' . $a['nombre'];
}

$destFile = realpath(__DIR__ . '/../../scripts') . '/archivosFuenteFull.txt';
$s = file_put_contents($destFile, implode("\n", $lines) . "\n");
var_dump($s);
