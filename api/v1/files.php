<?php

/**
 * Endpoint: GET /api/v1/files/{idSucursal}
 * Lista los archivos disponibles para una sucursal.
 */

header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo "ERROR: Metodo no permitido. Use GET.";
    exit;
}

if (!$idSucursal) {
    http_response_code(400);
    echo "ERROR: Falta idSucursal en la URI.";
    exit;
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT a.id, a.nombre, a.peso, a.md5zip, a.md5flat, a.fecha_carga, a.is_desblinde
        FROM archivos a
        JOIN archivo_sucursal asu ON a.id = asu.archivo_id
        WHERE asu.sucursal_id = ? AND asu.enabled = TRUE
        ORDER BY a.fecha_carga DESC
    ");
    $stmt->execute([$idSucursal]);
    $files = $stmt->fetchAll();

    echo "STATUS: OK\n";
    echo "SUCURSAL: $idSucursal\n";
    echo "TOTAL: " . count($files) . "\n";
    echo "---\n";

    foreach ($files as $f) {
        echo "ID: {$f['id']}\n";
        echo "NOMBRE: {$f['nombre']}\n";
        echo "PESO: {$f['peso']}\n";
        echo "MD5ZIP: {$f['md5zip']}\n";
        echo "MD5FLAT: {$f['md5flat']}\n";
        echo "FECHA: {$f['fecha_carga']}\n";
        echo "DESBLINDE: " . ($f['is_desblinde'] ? 'SI' : 'NO') . "\n";
        echo "---\n";
    }

} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage();
}
