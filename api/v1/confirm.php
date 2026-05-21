<?php

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "ERROR: Use POST para confirmar descarga";
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$sucursalId = $input['sucursal_id'] ?? $idSucursal;
$archivoNombre = $input['nombre'] ?? null;

if (!$sucursalId || !$archivoNombre) {
    http_response_code(400);
    echo "ERROR: Faltan sucursal_id y nombre";
    exit;
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("
        UPDATE archivo_sucursal SET sync = TRUE, updated_at = NOW()
        WHERE sucursal_id = ? AND nombre = ? AND enabled = TRUE
    ");
    $stmt->execute([$sucursalId, $archivoNombre]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo "ERROR: No se encontró asociación para {$sucursalId}/{$archivoNombre}";
        exit;
    }

    echo "STATUS: OK\n";
    echo "MENSAJE: Archivo {$archivoNombre} marcado como sincronizado para sucursal {$sucursalId}\n";

} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: {$e->getMessage()}\n";
}
