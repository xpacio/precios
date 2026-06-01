<?php

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "ERROR: Use POST para confirmar descarga";
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$sucursalId = $input['sucursal_id'] ?? $idSucursal;
$archivoNombre = $input['nombre'] ?? null;
$resultado = $input['resultado'] ?? 'downloaded';

$isBatch = ($_SERVER['HTTP_X_BATCH'] ?? '') === 'true';

try {
    $pdo = getDB();

    if ($isBatch) {
        $batch = $input['batch'] ?? null;
        if (!$sucursalId || !$batch || !is_array($batch)) {
            http_response_code(400);
            echo "ERROR: Faltan sucursal_id y batch";
            exit;
        }

        $validResults = ['downloaded', 'skip', 'error-br', 'error-flat', 'error-tmp', 'error-blocked'];
        $stmt = $pdo->prepare("
            UPDATE archivo_sucursal
            SET sync = TRUE,
                ultimo_resultado = ?,
                n_exitos = CASE WHEN ? = 'downloaded' THEN n_exitos + 1 ELSE n_exitos END,
                updated_at = NOW()
            WHERE sucursal_id = ? AND nombre = ? AND enabled = TRUE
        ");

        $pdo->beginTransaction();
        $count = 0;
        $hasDbd = false;
        foreach ($batch as $item) {
            $n = $item['nombre'] ?? null;
            $r = $item['resultado'] ?? 'skip';
            if (!$n || !in_array($r, $validResults, true)) continue;
            $stmt->execute([$r, $r, $sucursalId, $n]);
            if ($r === 'downloaded') {
                $stmtT = $pdo->prepare("SELECT es_desblinde FROM archivo_sucursal WHERE sucursal_id = ? AND nombre = ?");
                $stmtT->execute([$sucursalId, $n]);
                $rowT = $stmtT->fetch();
                if ($rowT && ($rowT['es_desblinde'] === 't' || $rowT['es_desblinde'] === true)) {
                    $pdo->prepare("UPDATE archivo_sucursal SET enabled = FALSE WHERE sucursal_id = ? AND nombre = ?")
                        ->execute([$sucursalId, $n]);
                    $hasDbd = true;
                }
            }
            $count++;
        }
        if ($hasDbd) {
            $pdo->prepare("UPDATE sucursales SET clave_dbd = NULL WHERE id_sucursal = ?")
                ->execute([$sucursalId]);
        }
        $pdo->commit();

        echo "STATUS: OK\n";
        echo "BATCH: {$count} procesados\n";
        exit;
    }

    if (!$sucursalId || !$archivoNombre) {
        http_response_code(400);
        echo "ERROR: Faltan sucursal_id y nombre";
        exit;
    }

    if (!in_array($resultado, ['downloaded', 'skip', 'error-br', 'error-flat', 'error-tmp', 'error-blocked'], true)) {
        http_response_code(400);
        echo "ERROR: resultado inválido: {$resultado}";
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE archivo_sucursal
        SET sync = TRUE,
            ultimo_resultado = ?,
            n_exitos = CASE WHEN ? = 'downloaded' THEN n_exitos + 1 ELSE n_exitos END,
            updated_at = NOW()
        WHERE sucursal_id = ? AND nombre = ? AND enabled = TRUE
    ");
    $stmt->execute([$resultado, $resultado, $sucursalId, $archivoNombre]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo "ERROR: No se encontró asociación para {$sucursalId}/{$archivoNombre}";
        exit;
    }

    if ($resultado === 'downloaded') {
        $stmtT = $pdo->prepare("SELECT es_desblinde FROM archivo_sucursal WHERE sucursal_id = ? AND nombre = ?");
        $stmtT->execute([$sucursalId, $archivoNombre]);
        $rowT = $stmtT->fetch();
        if ($rowT && ($rowT['es_desblinde'] === 't' || $rowT['es_desblinde'] === true)) {
            $pdo->prepare("UPDATE archivo_sucursal SET enabled = FALSE WHERE sucursal_id = ? AND nombre = ?")
                ->execute([$sucursalId, $archivoNombre]);
            $pdo->prepare("UPDATE sucursales SET clave_dbd = NULL WHERE id_sucursal = ?")
                ->execute([$sucursalId]);
        }
    }

    echo "STATUS: OK\n";
    echo "MENSAJE: Archivo {$archivoNombre} -> {$resultado}\n";

} catch (Exception $e) {
    if ($isBatch && isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo "ERROR: {$e->getMessage()}\n";
}
