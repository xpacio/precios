<?php

/**
 * Controlador de Descarga (Download)
 * Endpoint: /api/v1/download/{idSucursal}/{fileName}
 */

header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo "ERROR: Metodo no permitido. Use GET para descargar archivos.";
    exit;
}

// MD5 enviado por el cliente para comparar (header: md5)
$clientMd5 = $_SERVER['HTTP_MD5'] ?? null;
$isDesblindeReq = ($_SERVER['HTTP_IS_DESBLINDE'] ?? '0') === '1';
$claveCorta = $_SERVER['HTTP_CLAVECORTA'] ?? null;

if (!$idSucursal || !$fileName) {
    http_response_code(400);
    echo "ERROR: Faltan parametros (sucursal o nombre de archivo) en la URI.";
    exit;
}

$dsn = "pgsql:host=localhost;port=5432;dbname=precios";
try {
    $pdo = new PDO($dsn, "postgres", "password", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // Si es desblinde, primero validamos la clave corta
    if ($isDesblindeReq) {
        if (empty($claveCorta)) {
            http_response_code(401);
            echo "ERROR: La opcion desblinde requiere clave corta.";
            exit;
        }
        
        $stmtAuth = $pdo->prepare("SELECT id FROM usuarios WHERE clavecorta = ? AND enabled = TRUE AND can_download = TRUE");
        $stmtAuth->execute([$claveCorta]);
        if (!$stmtAuth->fetch()) {
            http_response_code(403);
            echo "ERROR: Clave corta invalida o usuario no autorizado.";
            exit;
        }
    }

    // Buscar el archivo más reciente asociado a la sucursal
    $stmt = $pdo->prepare("
        SELECT a.id, a.nombre, a.ruta, a.md5zip, a.md5flat 
        FROM archivos a
        JOIN archivo_sucursal asu ON a.id = asu.archivo_id
        WHERE asu.sucursal_id = ? AND a.nombre = ? AND a.is_desblinde = ?
        ORDER BY a.fecha_carga DESC LIMIT 1
    ");
    $stmt->execute([$idSucursal, $fileName, ($isDesblindeReq ? 'true' : 'false')]);
    $fileRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fileRow) {
        http_response_code(404);
        echo "ERROR: Archivo no encontrado para la sucursal $idSucursal";
        exit;
    }

    // Comparar MD5 (usamos substr 8 para coincidir con el DDL CHAR(8))
    $dbMd5 = strtolower(trim($fileRow['md5zip']));
    $cmpMd5 = strtolower(trim(substr($clientMd5 ?? '', 0, 8)));

    if (!empty($cmpMd5) && $dbMd5 === $cmpMd5) {
        echo "STATUS: SIN_CAMBIOS";
        exit;
    }

    // El archivo físico se encuentra en /storage/ y su nombre es el UUID (id)
    $physicalPath = __DIR__ . '/../storage/' . $fileRow['id'];

    if (!file_exists($physicalPath)) {
        http_response_code(404);
        echo "ERROR: El archivo fisico no existe en el servidor.";
        exit;
    }

    // Enviar cabeceras informativas
    header('nombre: ' . $fileRow['nombre']);
    header('md5zip: ' . $fileRow['md5zip']);
    header('md5flat: ' . $fileRow['md5flat']);
    
    // Incrementar contador de descargas
    $pdo->prepare("UPDATE archivos SET n_descargas = n_descargas + 1 WHERE id = ?")
        ->execute([$fileRow['id']]);

    // Volcar contenido binario
    readfile($physicalPath);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage();
}
