<?php

/**
 * Endpoint: POST /api/v1/upload/{idSucursal}/{fileName}
 */

header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "ERROR: Metodo no permitido. Use POST para cargar archivos.";
    exit;
}

$hNombre    = $_SERVER['HTTP_NOMBRE'] ?? null;
$hRuta      = $_SERVER['HTTP_RUTA'] ?? null;
$hMd5Zip    = $_SERVER['HTTP_MD5ZIP'] ?? null;
$hMd5Flat   = $_SERVER['HTTP_MD5FLAT'] ?? null;
$hFecha     = $_SERVER['HTTP_FECHA_ARCHIVO'] ?? null;
$hDesblinde = $_SERVER['HTTP_IS_DESBLINDE'] ?? '0';

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

$calculatedMd5 = substr(md5_file($file['tmp_name']), 0, 8);
if ($hMd5Zip && strtolower($calculatedMd5) !== strtolower($hMd5Zip)) {
    http_response_code(400);
    echo "ERROR: Fallo de integridad MD5\nESPERADO: $hMd5Zip\nCALCULADO: $calculatedMd5";
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

    $stmt = $pdo->prepare("INSERT INTO archivos (nombre, ruta, peso, md5zip, md5flat, fecha_archivo, is_desblinde, usuario_que_cargo) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id");
    $stmt->execute([
        $finalFileName,
        $hRuta,
        $file['size'],
        substr($hMd5Zip ?? $calculatedMd5, 0, 8),
        substr($hMd5Flat ?? '', 0, 8),
        $hFecha ?? date('Y-m-d H:i:s'),
        ($hDesblinde === '1' ? 'true' : 'false'),
        1
    ]);

    $archivoId = $stmt->fetchColumn();

    $destination = $storageDir . DIRECTORY_SEPARATOR . $archivoId;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new Exception("No se pudo mover el archivo al almacenamiento fisico.");
    }

    $pdo->prepare("INSERT INTO archivo_sucursal (archivo_id, sucursal_id) VALUES (?, ?)")->execute([$archivoId, $idSucursal]);
    $pdo->commit();

    echo "STATUS: OK\nID: $archivoId\nMENSAJE: Archivo cargado y registrado exitosamente";
} catch (Exception $e) {
    if (isset($pdo)) $pdo->rollBack();
    http_response_code(500);
    echo "ERROR: Error de base de datos: " . $e->getMessage();
}
