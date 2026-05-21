<?php

require_once __DIR__ . '/../../config/database.php';

$PRECIOS_DIR = '/srv/precios';

header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "ERROR: Use POST para verificar archivos";
    exit;
}

try {
    $pdo = getDB();
    $cambiados = 0;
    $aparecidos = 0;
    $desaparecidos = 0;
    $sin_cambios = 0;

    $stmt = $pdo->query("SELECT id, path, nombre, md5zip, ausente FROM archivos");
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $fullPath = $PRECIOS_DIR . '/' . $row['path'] . '/' . $row['nombre'];
        $exists = file_exists($fullPath);
        $wasAusente = ($row['ausente'] === 't' || $row['ausente'] === true);

        if (!$exists) {
            if (!$wasAusente) {
                $pdo->prepare("UPDATE archivos SET ausente = TRUE, updated_at = NOW() WHERE id = ?")
                    ->execute([$row['id']]);
                $desaparecidos++;
            }
            continue;
        }

        $md5 = substr(md5_file($fullPath), 0, 8);
        $size = filesize($fullPath);

        if ($wasAusente) {
            $pdo->prepare("UPDATE archivos SET ausente = FALSE, md5zip = ?, peso = ?, ultimo_cambio = NOW(), updated_at = NOW() WHERE id = ?")
                ->execute([$md5, $size, $row['id']]);
            $aparecidos++;
        } elseif ($row['md5zip'] !== $md5) {
            $pdo->prepare("UPDATE archivos SET md5zip = ?, peso = ?, ultimo_cambio = NOW(), updated_at = NOW() WHERE id = ?")
                ->execute([$md5, $size, $row['id']]);
            $pdo->prepare("UPDATE archivo_sucursal SET sync = FALSE, updated_at = NOW() WHERE archivo_id = ?")
                ->execute([$row['id']]);
            $cambiados++;
        } else {
            // Actualizar peso por si acaso
            $pdo->prepare("UPDATE archivos SET peso = ? WHERE id = ? AND peso != ?")
                ->execute([$size, $row['id'], $size]);
            $sin_cambios++;
        }
    }

    echo "STATUS: OK\n";
    echo "SIN_CAMBIOS: {$sin_cambios}\n";
    echo "CAMBIADOS: {$cambiados}\n";
    echo "APARECIDOS: {$aparecidos}\n";
    echo "DESAPARECIDOS: {$desaparecidos}\n";

} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: {$e->getMessage()}\n";
}
