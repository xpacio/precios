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

    $stmt = $pdo->query("SELECT id, ruta, nombre, flat, br, status FROM archivos");
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $fullPath = $row['ruta'] . '/' . $row['nombre'];
        $brPath = $fullPath . '.br';
        $exists = file_exists($fullPath);

        if (!$exists) {
            $pdo->prepare("UPDATE archivos SET status = 'missing', updated_at = NOW() WHERE id = ?")
                ->execute([$row['id']]);
            $desaparecidos++;
            continue;
        }

        $data = file_get_contents($fullPath);
        $flat = substr(hash('xxh3', $data), 0, 6);
        $changed = $row['flat'] !== $flat || !file_exists($brPath);

        if ($changed) {
            $brContent = brotli_compress($data, 11);
            if ($brContent === false) throw new RuntimeException("No se pudo comprimir: $fullPath");
            file_put_contents($brPath, $brContent);
            $br = substr(hash('xxh3', $brContent), 0, 6);
            $size = filesize($brPath);

            $pdo->prepare("UPDATE archivos SET flat = ?, br = ?, peso = ?, status = 'ready', updated_at = NOW() WHERE id = ?")
                ->execute([$flat, $br, filesize($fullPath), $row['id']]);

            $pdo->prepare("UPDATE archivo_sucursal SET sync = FALSE, updated_at = NOW() WHERE archivo_id = ?")
                ->execute([$row['id']]);
            $cambiados++;
        } else {
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
