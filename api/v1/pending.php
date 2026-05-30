<?php

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Use GET']);
    exit;
}

if (!$idSucursal) {
    http_response_code(400);
    echo json_encode(['error' => 'Falta sucursal_id']);
    exit;
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT a.id, a.ruta, a.nombre, a.flat, a.br, a.peso,
               a.updated_at AS ultimo_cambio, a.fecha_archivo
        FROM archivo_sucursal asu
        JOIN archivos a ON a.id = asu.archivo_id
        WHERE asu.sucursal_id = ? AND asu.enabled = TRUE AND a.status = 'ready'
        ORDER BY a.nombre
    ");
    $stmt->execute([$idSucursal]);
    $files = $stmt->fetchAll();

    $result = array_map(function ($f) {
        return [
            'id' => $f['id'],
            'ruta' => $f['ruta'],
            'nombre' => $f['nombre'],
            'flat' => trim($f['flat']),
            'br' => trim($f['br']),
            'peso' => (int)$f['peso'],
            'ultimo_cambio' => $f['ultimo_cambio'],
            'fecha_archivo' => $f['fecha_archivo'],
        ];
    }, $files);

    echo json_encode([
        'status' => 'OK',
        'sucursal' => $idSucursal,
        'pendientes' => count($result),
        'archivos' => $result,
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
