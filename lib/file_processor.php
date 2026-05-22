<?php

function processFile(string $relPath): array
{
    $baseDir = '/srv/precios';
    $fullPath = $baseDir . '/' . $relPath;
    $realPath = realpath($fullPath);

    if (!$realPath || !is_file($realPath)) {
        return ['status' => 'ERROR', 'mensaje' => "Archivo no encontrado: $fullPath"];
    }

    $ruta = dirname($realPath);
    $nombre = basename($realPath);
    $peso = filesize($realPath);
    $data = file_get_contents($realPath);
    $xxh3 = hash('xxh3', $data);
    $md5zip = substr(md5($data), 0, 8);
    $brPath = $realPath . '.br';

    try {
        $pdo = getDB();

        $stmt = $pdo->prepare("SELECT id, xxh3, status FROM archivos WHERE ruta = ? AND nombre = ?");
        $stmt->execute([$ruta, $nombre]);
        $existing = $stmt->fetch();

        if ($existing && $existing['xxh3'] === $xxh3 && $existing['status'] === 'ready') {
            return [
                'status' => 'SKIP',
                'ruta' => $ruta,
                'nombre' => $nombre,
                'xxh3' => $xxh3,
                'mensaje' => 'Sin cambios'
            ];
        }

        $isNew = ($existing === false);

        if ($isNew) {
            $stmt = $pdo->prepare("
                INSERT INTO archivos (ruta, nombre, peso, md5zip, xxh3, status)
                VALUES (?, ?, ?, ?, ?, 'updating')
                RETURNING id
            ");
            $stmt->execute([$ruta, $nombre, $peso, $md5zip, $xxh3]);
            $archivoId = $stmt->fetchColumn();
        } else {
            $archivoId = $existing['id'];
            $pdo->prepare("UPDATE archivos SET status = 'updating' WHERE id = ?")
                ->execute([$archivoId]);
        }

        $tmpSuffix = bin2hex(random_bytes(4));
        $tmpPath = $brPath . '.' . $tmpSuffix . '.tmp';

        $compressed = brotli_compress($data, 11);

        if ($compressed === false) {
            $pdo->prepare("UPDATE archivos SET status = 'ready' WHERE id = ?")->execute([$archivoId]);
            return ['status' => 'ERROR', 'mensaje' => 'Error al comprimir con brotli'];
        }

        if (file_put_contents($tmpPath, $compressed) === false) {
            $pdo->prepare("UPDATE archivos SET status = 'ready' WHERE id = ?")->execute([$archivoId]);
            return ['status' => 'ERROR', 'mensaje' => 'Error al escribir archivo temporal'];
        }

        rename($tmpPath, $brPath);

        $pdo->prepare("
            UPDATE archivos SET peso = ?, md5zip = ?, xxh3 = ?, comprimido = TRUE, status = 'ready', updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$peso, $md5zip, $xxh3, $archivoId]);

        return [
            'status' => 'OK',
            'accion' => $isNew ? 'INSERT' : 'UPDATE',
            'id' => (int)$archivoId,
            'ruta' => $ruta,
            'nombre' => $nombre,
            'xxh3' => $xxh3,
            'md5zip' => $md5zip,
            'peso' => $peso,
            'comprimido' => true
        ];

    } catch (Exception $e) {
        if (isset($archivoId)) {
            $pdo->prepare("UPDATE archivos SET status = 'ready' WHERE id = ?")->execute([$archivoId]);
        }
        return ['status' => 'ERROR', 'mensaje' => $e->getMessage()];
    }
}