<?php

/**
 * Endpoint: POST /api/v1/upload/{idSucursal}/{fileName}
 */

require_once __DIR__ . '/../../lib/hash_helper.php';

header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "ERROR: Metodo no permitido. Use POST para cargar archivos.";
    exit;
}

$hNombre    = $_SERVER['HTTP_NOMBRE'] ?? null;
$hRuta      = $_SERVER['HTTP_RUTA'] ?? null;
$hFlat      = $_SERVER['HTTP_FLAT'] ?? null;
$hBr        = $_SERVER['HTTP_BR'] ?? null;
$hFecha     = $_SERVER['HTTP_FECHA_ARCHIVO'] ?? null;


if (!isset($_FILES['archivo'])) {
    http_response_code(400);
    echo "ERROR: No se encontro el archivo en el cuerpo de la peticion (clave: 'archivo')";
    exit;
}

$file = $_FILES['archivo'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(500);
    echo "ERROR: Error en la subida del archivo (PHP Code: " . $file['error'] . ")";
    exit;
}

$calculatedFlat = flatHash(file_get_contents($file['tmp_name']));
if ($hFlat && $calculatedFlat !== $hFlat) {
    http_response_code(400);
    echo "ERROR: Fallo de integridad FLAT\nESPERADO: $hFlat\nCALCULADO: $calculatedFlat";
    exit;
}

$storageDir = __DIR__ . '/../../storage';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0755, true);
}

try {
    $pdo = getDB();
    $pdo->beginTransaction();

    $finalFileName = $hNombre ?? $fileName ?? $file['name'];

    $stmt = $pdo->prepare("INSERT INTO archivos (nombre, ruta, peso, flat, br, fecha_carga, n_descargas) VALUES (?, ?, ?, ?, ?, ?, 0) RETURNING id");
    $stmt->execute([
        $finalFileName,
        $hRuta,
        $file['size'],
        substr($hFlat ?? $calculatedFlat, 0, 4),
        substr($hBr ?? '', 0, 4),
        $hFecha ?? date('Y-m-d H:i:s'),
    ]);

    $archivoId = $stmt->fetchColumn();

    $destination = $storageDir . DIRECTORY_SEPARATOR . $archivoId;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new Exception("No se pudo mover el archivo al almacenamiento fisico.");
    }

    $pdo->prepare("INSERT INTO archivo_sucursal (archivo_id, sucursal_id, nombre) VALUES (?, ?, ?)")->execute([$archivoId, $idSucursal, $finalFileName]);
    $pdo->commit();

    echo "STATUS: OK\nID: $archivoId\nMENSAJE: Archivo cargado y registrado exitosamente";
} catch (Exception $e) {
    if (isset($pdo)) $pdo->rollBack();
    http_response_code(500);
    echo "ERROR: Error de base de datos: " . $e->getMessage();
}
