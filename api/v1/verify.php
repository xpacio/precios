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
    // Ensure directories are traversable by www-data
    system("chmod -R o+X " . escapeshellarg($PRECIOS_DIR) . " 2>/dev/null");

    $pdo = getDB();
    $cambiados = 0;
    $aparecidos = 0;
    $desaparecidos = 0;
    $sin_cambios = 0;

    $stmt = $pdo->query("SELECT id, path, nombre, md5flat, md5zip, ausente FROM archivos");
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $fullPath = $PRECIOS_DIR . '/' . $row['path'] . '/' . $row['nombre'];
        $brPath = $PRECIOS_DIR . '/' . $row['path'] . '/' . pathinfo($row['nombre'], PATHINFO_FILENAME) . '.br';
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

        $md5flat = substr(md5_file($fullPath), 0, 8);
        $changed = $wasAusente || $row['md5flat'] !== $md5flat || !file_exists($brPath);

        if ($changed) {
            $brContent = brotli_compress(file_get_contents($fullPath), 11);
            file_put_contents($brPath, $brContent);
            $md5zip = substr(md5_file($brPath), 0, 8);
            $size = filesize($brPath);

            $pdo->prepare("UPDATE archivos SET ausente = FALSE, md5flat = ?, md5zip = ?, peso = ?, ultimo_cambio = NOW(), updated_at = NOW() WHERE id = ?")
                ->execute([$md5flat, $md5zip, $size, $row['id']]);

            if (!$wasAusente) {
                $pdo->prepare("UPDATE archivo_sucursal SET sync = FALSE, updated_at = NOW() WHERE archivo_id = ?")
                    ->execute([$row['id']]);
                $cambiados++;
            } else {
                $aparecidos++;
            }
        } else {
            // Actualizar peso por si acaso
            $size = filesize($brPath);
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
