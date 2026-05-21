<?php

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo "ERROR: Use GET para listar pendientes";
    exit;
}

if (!$idSucursal) {
    http_response_code(400);
    echo "ERROR: Falta sucursal_id en la URI";
    exit;
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT a.id, a.path, a.nombre, a.md5zip, a.peso, a.ultimo_cambio
        FROM archivo_sucursal asu
        JOIN archivos a ON a.id = asu.archivo_id
        WHERE asu.sucursal_id = ? AND asu.enabled = TRUE AND asu.sync = FALSE AND a.ausente = FALSE
        ORDER BY a.path, a.nombre
    ");
    $stmt->execute([$idSucursal]);
    $files = $stmt->fetchAll();

    echo "STATUS: OK\n";
    echo "SUCURSAL: {$idSucursal}\n";
    echo "PENDIENTES: " . count($files) . "\n";
    echo "---\n";

    foreach ($files as $f) {
        echo "ID: {$f['id']}\n";
        echo "RUTA: {$f['path']}/{$f['nombre']}\n";
        echo "MD5: {$f['md5zip']}\n";
        echo "PESO: {$f['peso']}\n";
        if ($f['ultimo_cambio']) {
            echo "CAMBIADO: {$f['ultimo_cambio']}\n";
        }
        echo "---\n";
    }

} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: {$e->getMessage()}\n";
}
