<?php

require_once __DIR__ . '/../../config/database.php';

ini_set('max_execution_time', 3600);

header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "ERROR: Use POST para sincronizar";
    exit;
}

$archivo_id = $idSucursal ?? null;

try {
    $pdo = getDB();

    if ($archivo_id) {
        // Sync por archivo
        $stmt = $pdo->prepare("SELECT path, nombre FROM archivos WHERE id = ?");
        $stmt->execute([$archivo_id]);
        $file = $stmt->fetch();

        if (!$file) {
            http_response_code(404);
            echo "ERROR: Archivo no encontrado\n";
            exit;
        }

        $relPath = $file['path'] . '/' . $file['nombre'];
        $cmd = sprintf('/root/scripts/precios-file.sh %s 2>&1', escapeshellarg($relPath));
        echo "Ejecutando: precios-file.sh {$relPath}\n";
        passthru($cmd, $exitCode);

        if ($exitCode !== 0) {
            echo "ERROR: Rsync falló con código {$exitCode}\n";
            exit;
        }
    } else {
        // Sync completo
        $cmd = '/root/scripts/precios.sh 2>&1';
        echo "Ejecutando: precios.sh\n";
        passthru($cmd, $exitCode);

        if ($exitCode !== 0) {
            echo "ERROR: Rsync falló con código {$exitCode}\n";
            exit;
        }
    }

    // Post-sync: verificar MD5
    echo "\n--- Verificando MD5 ---\n";
    require __DIR__ . '/verify.php';

} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: {$e->getMessage()}\n";
}
