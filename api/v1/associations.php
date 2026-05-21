<?php

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

try {
    $pdo = getDB();
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    // GET /api/v1/associations/{sucursal_id}
    if ($method === 'GET') {
        if (!$idSucursal) {
            http_response_code(400);
            echo json_encode(['error' => 'Falta sucursal_id']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT a.id, a.path, a.nombre, a.md5zip, a.peso, a.ausente, asu.sync, asu.created_at AS asociado_desde
            FROM archivo_sucursal asu
            JOIN archivos a ON a.id = asu.archivo_id
            WHERE asu.sucursal_id = ? AND asu.enabled = TRUE
            ORDER BY a.path, a.nombre
        ");
        $stmt->execute([$idSucursal]);
        echo json_encode($stmt->fetchAll(), JSON_PRETTY_PRINT);
        exit;
    }

    // POST /api/v1/associations - Crear asociación
    if ($method === 'POST') {
        $sucursalId = $input['sucursal_id'] ?? $idSucursal;
        $archivoId = $input['archivo_id'] ?? null;

        if (!$sucursalId || !$archivoId) {
            http_response_code(400);
            echo json_encode(['error' => 'Faltan sucursal_id y archivo_id']);
            exit;
        }

        // Verificar que la sucursal existe
        $stmt = $pdo->prepare("SELECT 1 FROM sucursales WHERE id_sucursal = ? AND enabled = TRUE");
        $stmt->execute([$sucursalId]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Sucursal no encontrada o deshabilitada']);
            exit;
        }

        // Obtener nombre del archivo
        $stmt = $pdo->prepare("SELECT id, nombre FROM archivos WHERE id = ?");
        $stmt->execute([$archivoId]);
        $archivo = $stmt->fetch();

        if (!$archivo) {
            http_response_code(404);
            echo json_encode(['error' => 'Archivo no encontrado']);
            exit;
        }

        // Validar que la sucursal no tenga ya un archivo con el mismo nombre
        $stmt = $pdo->prepare("
            SELECT 1 FROM archivo_sucursal asu
            JOIN archivos a ON a.id = asu.archivo_id
            WHERE asu.sucursal_id = ? AND a.nombre = ? AND asu.enabled = TRUE
        ");
        $stmt->execute([$sucursalId, $archivo['nombre']]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => "La sucursal ya tiene un archivo con nombre '{$archivo['nombre']}'"]);
            exit;
        }

        $pdo->prepare("
            INSERT INTO archivo_sucursal (archivo_id, sucursal_id, nombre)
            VALUES (?, ?, ?)
        ")->execute([$archivoId, $sucursalId, $archivo['nombre']]);

        http_response_code(201);
        echo json_encode(['status' => 'OK', 'mensaje' => 'Asociación creada']);
        exit;
    }

    // DELETE /api/v1/associations
    if ($method === 'DELETE') {
        $sucursalId = $input['sucursal_id'] ?? $idSucursal;
        $archivoId = $input['archivo_id'] ?? null;

        if (!$sucursalId || !$archivoId) {
            http_response_code(400);
            echo json_encode(['error' => 'Faltan sucursal_id y archivo_id']);
            exit;
        }

        $pdo->prepare("
            DELETE FROM archivo_sucursal
            WHERE archivo_id = ? AND sucursal_id = ?
        ")->execute([$archivoId, $sucursalId]);

        echo json_encode(['status' => 'OK', 'mensaje' => 'Asociación eliminada']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
