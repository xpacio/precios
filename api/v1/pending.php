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
        SELECT a.id, a.nombre, a.md5zip, a.md5flat, a.peso, a.ultimo_cambio
        FROM archivo_sucursal asu
        JOIN archivos a ON a.id = asu.archivo_id
        WHERE asu.sucursal_id = ? AND asu.enabled = TRUE AND asu.sync = FALSE AND a.ausente = FALSE
        ORDER BY a.nombre
    ");
    $stmt->execute([$idSucursal]);
    $files = $stmt->fetchAll();

    $result = array_map(function ($f) {
        return [
            'id' => $f['id'],
            'nombre' => $f['nombre'],
            'md5zip' => $f['md5zip'],
            'md5flat' => $f['md5flat'],
            'peso' => (int)$f['peso'],
            'ultimo_cambio' => $f['ultimo_cambio'],
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
