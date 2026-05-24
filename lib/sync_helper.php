<?php

function processAndCompressFile(string $ruta, string $nombre): array
{
    $baseDir = '/srv/precios';
    $fullPath = $baseDir . '/' . $ruta . '/' . $nombre;
    $realPath = realpath($fullPath);

    if (!$realPath || !is_file($realPath)) {
        return ['status' => 'ERROR', 'mensaje' => "Archivo no encontrado: $fullPath"];
    }

    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT id, xxh3, status FROM archivos WHERE enabled = TRUE AND ruta = ? AND nombre = ?");
    $stmt->execute([$ruta, $nombre]);
    $existing = $stmt->fetch();

    if (!$existing) {
        return ['status' => 'ERROR', 'mensaje' => "Archivo no registrado o deshabilitado: $ruta/$nombre"];
    }

    $data = file_get_contents($realPath);
    $flat = substr(hash('xxh3', $data), 0, 6);
    $peso = filesize($realPath);
    $fechaArchivo = date('Y-m-d H:i:s', filemtime($realPath));
    $brPath = $realPath . '.br';
    $brExists = file_exists($brPath);

    // Si .br existe en disco y hash coincide → solo corregir status si es necesario
    if ($brExists && trim($existing['xxh3']) === $flat) {
        if ($existing['status'] !== 'ready') {
            $pdo->prepare("UPDATE archivos SET status = 'ready', fecha_archivo = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$fechaArchivo, $existing['id']]);
        }
        return [
            'status' => 'SKIP',
            'ruta' => $ruta,
            'nombre' => $nombre,
            'flat' => $flat,
            'mensaje' => 'Sin cambios',
        ];
    }

    // Calcular % de compresión desde .br existente si no se va a re-comprimir
    if ($brExists) {
        $brPeso = filesize($brPath);
        $comprPct = $peso > 0 ? (int)round($brPeso * 100 / $peso) : null;
        $pdo->prepare("UPDATE archivos SET compr_pct = ? WHERE id = ?")->execute([$comprPct, $existing['id']]);
    }

    // .br faltante o hash cambio → comprimir
    try {
        $archivoId = $existing['id'];

        $tmpSuffix = bin2hex(random_bytes(4));
        $tmpPath = $brPath . '.' . $tmpSuffix . '.tmp';

        $compressed = brotli_compress($data, 11);

        if ($compressed === false) {
            return ['status' => 'ERROR', 'mensaje' => 'Error al comprimir con brotli'];
        }

        if (file_put_contents($tmpPath, $compressed) === false) {
            return ['status' => 'ERROR', 'mensaje' => 'Error al escribir archivo temporal'];
        }

        rename($tmpPath, $brPath);

        $br = substr(hash('xxh3', $compressed), 0, 6);
        $brPeso = filesize($brPath);
        $comprPct = $peso > 0 ? (int)round($brPeso * 100 / $peso) : null;

        $pdo->prepare("
            UPDATE archivos SET peso = ?, flat = ?, br = ?, xxh3 = ?, comprimido = TRUE, compr_pct = ?, status = 'ready', fecha_archivo = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$peso, $flat, $br, $flat, $comprPct, $fechaArchivo, $archivoId]);

        return [
            'status' => 'OK',
            'accion' => 'UPDATE',
            'id' => (int)$archivoId,
            'ruta' => $ruta,
            'nombre' => $nombre,
            'flat' => $flat,
            'br' => $br,
            'peso' => $peso,
            'comprimido' => true,
        ];

    } catch (Exception $e) {
        return ['status' => 'ERROR', 'mensaje' => $e->getMessage()];
    }
}
