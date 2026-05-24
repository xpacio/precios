<?php

/**
 * Endpoint: GET /api/v1/download/{idSucursal}/{fileName}
 * Descarga un archivo comprimido con brotli (.br).
 */

header('Content-Type: application/octet-stream');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: text/plain');
    echo "ERROR: Metodo no permitido. Use GET.";
    exit;
}

if (!$idSucursal || !$fileName) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo "ERROR: Faltan parametros (sucursal o nombre de archivo).";
    exit;
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT a.id, a.ruta, a.nombre, a.flat, a.br, a.status
        FROM archivos a
        JOIN archivo_sucursal asu ON a.id = asu.archivo_id
        WHERE asu.sucursal_id = ? AND a.nombre = ? AND asu.enabled = TRUE
        ORDER BY a.fecha_carga DESC LIMIT 1
    ");
    $stmt->execute([$idSucursal, $fileName]);
    $fileRow = $stmt->fetch();

    if (!$fileRow) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "ERROR: Archivo no encontrado para la sucursal $idSucursal";
        exit;
    }

    if ($fileRow['status'] !== 'ready') {
        http_response_code(503);
        header('Content-Type: text/plain');
        echo "ERROR: Archivo en actualizacion, intente mas tarde";
        exit;
    }

    $brPath = '/srv/precios/' . $fileRow['ruta'] . '/' . $fileRow['nombre'] . '.br';

    if (!file_exists($brPath)) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "ERROR: El archivo comprimido no existe en el servidor.";
        exit;
    }

    header('Content-Encoding: br');
    header('X-Comprimido: brotli');
    header('X-FLAT: ' . $fileRow['flat']);
    header('X-BR: ' . $fileRow['br']);
    header('Content-Disposition: attachment; filename="' . $fileRow['nombre'] . '.br"');
    header('Content-Length: ' . filesize($brPath));

    $pdo->prepare("UPDATE archivos SET n_descargas = n_descargas + 1 WHERE id = ?")
        ->execute([$fileRow['id']]);

    readfile($brPath);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "ERROR: " . $e->getMessage();
}
