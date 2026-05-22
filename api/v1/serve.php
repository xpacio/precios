<?php

require_once __DIR__ . '/../../config/database.php';

$PRECIOS_DIR = '/srv/precios';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: text/plain');
    echo "ERROR: Use GET";
    exit;
}

$sucursalId = $idSucursal;
$fileName = $fileName ?? null;

if (!$sucursalId || !$fileName) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo "ERROR: Faltan sucursal o nombre de archivo";
    exit;
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT a.id, a.ruta, a.nombre, a.flat, a.br
        FROM archivo_sucursal asu
        JOIN archivos a ON a.id = asu.archivo_id
        WHERE asu.sucursal_id = ? AND a.nombre = ? AND asu.enabled = TRUE AND a.status = 'ready'
        LIMIT 1
    ");
    $stmt->execute([$sucursalId, $fileName]);
    $file = $stmt->fetch();

    if (!$file) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "ERROR: Archivo '{$fileName}' no disponible para sucursal {$sucursalId}";
        exit;
    }

    $brPath = $file['ruta'] . '/' . $file['nombre'] . '.br';

    if (!file_exists($brPath)) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "ERROR: Archivo comprimido no encontrado";
        exit;
    }

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($brPath));
    header('X-FLAT: ' . $file['flat']);
    header('X-BR: ' . $file['br']);

    readfile($fullPath);

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "ERROR: {$e->getMessage()}";
}
