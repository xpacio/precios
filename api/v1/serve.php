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
$fileRuta = $_SERVER['HTTP_X_RUTA'] ?? null;

if (!$sucursalId || !$fileName) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo "ERROR: Faltan sucursal o nombre de archivo";
    exit;
}

try {
    $pdo = getDB();

    $query = "
        SELECT a.id, a.ruta, a.nombre, a.flat, a.br
        FROM archivo_sucursal asu
        JOIN archivos a ON a.id = asu.archivo_id
        WHERE asu.sucursal_id = ? AND a.nombre = ? AND asu.enabled = TRUE AND a.status = 'ready'";
    $params = [$sucursalId, $fileName];

    if ($fileRuta) {
        $query .= " AND a.ruta = ?";
        $params[] = $fileRuta;
    }
    $query .= " LIMIT 1";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $file = $stmt->fetch();

    if (!$file) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "ERROR: Archivo '{$fileName}' no disponible para sucursal {$sucursalId}";
        exit;
    }

    $brPath = $PRECIOS_DIR . '/' . $file['ruta'] . '/' . $file['nombre'] . '.br';

    if (!file_exists($brPath)) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "ERROR: Archivo comprimido no encontrado";
        exit;
    }

    $pdo->prepare("UPDATE archivos          SET n_descargas = n_descargas + 1       WHERE id = ?")->execute([$file['id']]);
    $pdo->prepare("UPDATE archivo_sucursal SET n_envios    = n_envios    + 1       WHERE archivo_id = ? AND sucursal_id = ?")->execute([$file['id'], $sucursalId]);

    $fileType = strpos($file['ruta'], 'DSBLIND') !== false ? 'DBD' : 'NOR';

    if ($fileType === 'DBD') {
        $dbdUser = strtoupper($_SERVER['HTTP_X_DBD_USER'] ?? '');
        $dbdPass = $_SERVER['HTTP_X_DBD_PASSWORD'] ?? '';

        if ($dbdPass === '' || strtolower($dbdPass) === 'null') {
            http_response_code(403);
            header('Content-Type: text/plain');
            echo "ERROR: Clave DBD invalida.";
            exit;
        }

        if ($dbdUser === 'GTE') {
            $stmtS = $pdo->prepare("SELECT clave_dbd FROM sucursales WHERE id_sucursal = ? AND clave_dbd IS NOT NULL");
            $stmtS->execute([$sucursalId]);
            $suc = $stmtS->fetch();
            if (!$suc || strtoupper($suc['clave_dbd']) !== strtoupper($dbdPass)) {
                http_response_code(403);
                header('Content-Type: text/plain');
                echo "ERROR: Clave DBD incorrecta.";
                exit;
            }
        } else {
            $stmtU = $pdo->prepare("SELECT id, clavecorta FROM usuarios WHERE UPPER(nickname) = ? AND enabled = TRUE AND can_dsblind = TRUE");
            $stmtU->execute([$dbdUser]);
            $user = $stmtU->fetch();
            if (!$user || !password_verify(strtoupper($dbdPass), $user['clavecorta'])) {
                http_response_code(403);
                header('Content-Type: text/plain');
                echo "ERROR: Usuario o clave incorrecta.";
                exit;
            }
        }
    }

    $stmt = $pdo->prepare("INSERT INTO cli_log (sucursal_id, file_name, file_type, api_key_id, ip_address, dbd_user) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$sucursalId, $file['nombre'], $fileType, $GLOBALS['_api_key_id'] ?? null, $_SERVER['REMOTE_ADDR'] ?? '', $fileType === 'DBD' ? $dbdUser : null]);

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($brPath));
    header('X-FLAT: ' . $file['flat']);
    header('X-BR: ' . $file['br']);

    readfile($brPath);

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "ERROR: {$e->getMessage()}";
}
