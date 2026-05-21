<?php

$pageTitle = 'Sucursales';

$pdo = getDB();
$mensaje = '';
$error = '';

// Handle association creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $sucursalId = $_POST['sucursal_id'] ?? '';
    $archivoId = $_POST['archivo_id'] ?? '';

    if ($_POST['action'] === 'asociar' && $sucursalId && $archivoId) {
        try {
            // Get file nombre
            $stmt = $pdo->prepare("SELECT nombre FROM archivos WHERE id = ?");
            $stmt->execute([$archivoId]);
            $file = $stmt->fetch();

            if (!$file) {
                $error = 'Archivo no encontrado';
            } else {
                // Check no duplicate nombre
                $stmt = $pdo->prepare("
                    SELECT 1 FROM archivo_sucursal asu
                    JOIN archivos a ON a.id = asu.archivo_id
                    WHERE asu.sucursal_id = ? AND a.nombre = ? AND asu.enabled = TRUE
                ");
                $stmt->execute([$sucursalId, $file['nombre']]);
                if ($stmt->fetch()) {
                    $error = "La sucursal ya tiene un archivo con nombre '{$file['nombre']}'";
                } else {
                    $pdo->prepare("INSERT INTO archivo_sucursal (archivo_id, sucursal_id, nombre) VALUES (?, ?, ?)")
                        ->execute([$archivoId, $sucursalId, $file['nombre']]);
                    $mensaje = 'Archivo asociado exitosamente';
                }
            }
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }

    if ($_POST['action'] === 'desasociar' && $sucursalId && $archivoId) {
        try {
            $pdo->prepare("DELETE FROM archivo_sucursal WHERE archivo_id = ? AND sucursal_id = ?")
                ->execute([$archivoId, $sucursalId]);
            $mensaje = 'Asociación eliminada';
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }

    // Redirect to avoid re-POST
    $query = $sucursalId ? '?sucursal=' . urlencode($sucursalId) : '';
    header('Location: /dashboard/sucursales' . $query);
    exit;
}

$sucursalDetalle = $_GET['sucursal'] ?? '';

// List all sucursales
$stmt = $pdo->query("
    SELECT s.id_sucursal, s.nombre_sucursal, s.enabled,
           COUNT(DISTINCT asu.archivo_id) AS total_asociados,
           COUNT(DISTINCT CASE WHEN asu.sync = FALSE AND a.ausente = FALSE THEN asu.archivo_id END) AS pendientes
    FROM sucursales s
    LEFT JOIN archivo_sucursal asu ON s.id_sucursal = asu.sucursal_id AND asu.enabled = TRUE
    LEFT JOIN archivos a ON a.id = asu.archivo_id
    GROUP BY s.id_sucursal, s.nombre_sucursal, s.enabled
    ORDER BY s.id_sucursal
");
$sucursales = $stmt->fetchAll();

require __DIR__ . '/header.php';
?>

<h1>Sucursales</h1>

<?php if ($mensaje): ?>
    <div class="flash flash-success"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($sucursalDetalle): ?>
    <?php
    $stmt = $pdo->prepare("SELECT id_sucursal, nombre_sucursal FROM sucursales WHERE id_sucursal = ?");
    $stmt->execute([$sucursalDetalle]);
    $suc = $stmt->fetch();

    if (!$suc) {
        echo '<p class="badge-err">Sucursal no encontrada</p>';
    } else {
        // Associados
        $stmt = $pdo->prepare("
            SELECT a.id, a.path, a.nombre, a.md5zip, a.peso, a.ausente, asu.sync, asu.created_at AS asociado_desde
            FROM archivo_sucursal asu
            JOIN archivos a ON a.id = asu.archivo_id
            WHERE asu.sucursal_id = ? AND asu.enabled = TRUE
            ORDER BY a.path, a.nombre
        ");
        $stmt->execute([$sucursalDetalle]);
        $asociados = $stmt->fetchAll();

        // Archivos disponibles para asociar (que no estén ya asociados por nombre)
        $stmt = $pdo->prepare("
            SELECT a.id, a.path, a.nombre, a.ausente
            FROM archivos a
            WHERE a.id NOT IN (
                SELECT asu2.archivo_id FROM archivo_sucursal asu2
                WHERE asu2.sucursal_id = ? AND asu2.enabled = TRUE
            )
            ORDER BY a.path, a.nombre
        ");
        $stmt->execute([$sucursalDetalle]);
        $disponibles = $stmt->fetchAll();
    ?>
    <h2>
        <a href="/dashboard/sucursales" class="secondary">&larr; Volver</a>
        Sucursal: <?= htmlspecialchars($suc['id_sucursal']) ?> - <?= htmlspecialchars($suc['nombre_sucursal']) ?>
    </h2>

    <h3>Archivos Asociados (<?= count($asociados) ?>)</h3>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Archivo</th>
                    <th>MD5</th>
                    <th>Estado</th>
                    <th>Sincronizado</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($asociados)): ?>
                    <tr><td colspan="5">Sin archivos asociados</td></tr>
                <?php else: ?>
                    <?php foreach ($asociados as $a):
                        $esAusente = ($a['ausente'] === 't' || $a['ausente'] === true);
                        $estaSync = ($a['sync'] === 't' || $a['sync'] === true);
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($a['path'] . '/' . $a['nombre']) ?></td>
                            <td><code><?= htmlspecialchars($a['md5zip'] ?? '-') ?></code></td>
                            <td><?= $esAusente ? '<span class="badge-err">Ausente</span>' : '<span class="badge-ok">Presente</span>' ?></td>
                            <td><?= $estaSync ? '<span class="badge-ok">✔</span>' : '<span class="badge-warn">Pendiente</span>' ?></td>
                            <td>
                                <form method="POST" action="/dashboard/sucursales" style="display:inline">
                                    <input type="hidden" name="action" value="desasociar">
                                    <input type="hidden" name="sucursal_id" value="<?= htmlspecialchars($sucursalDetalle) ?>">
                                    <input type="hidden" name="archivo_id" value="<?= htmlspecialchars($a['id']) ?>">
                                    <button type="submit" class="secondary outline" style="padding:0.2rem 0.5rem;font-size:0.8rem">Desasociar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h3>Asociar Nuevo Archivo</h3>
    <?php if (empty($disponibles)): ?>
        <p>No hay archivos disponibles para asociar.</p>
    <?php else: ?>
        <form method="POST" action="/dashboard/sucursales">
            <input type="hidden" name="action" value="asociar">
            <input type="hidden" name="sucursal_id" value="<?= htmlspecialchars($sucursalDetalle) ?>">
            <label for="archivo_id">Seleccionar archivo</label>
            <select name="archivo_id" id="archivo_id" required>
                <option value="">-- Seleccionar --</option>
                <?php foreach ($disponibles as $d): ?>
                    <option value="<?= htmlspecialchars($d['id']) ?>">
                        <?= htmlspecialchars($d['path'] . '/' . $d['nombre']) ?>
                        <?= ($d['ausente'] === 't' || $d['ausente'] === true) ? '(Ausente)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Asociar</button>
        </form>
    <?php endif; ?>
    <?php } ?>
<?php else: ?>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Nombre</th>
                    <th>Estado</th>
                    <th>Asociados</th>
                    <th>Pendientes</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sucursales as $s): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($s['id_sucursal']) ?></code></td>
                        <td><?= htmlspecialchars($s['nombre_sucursal']) ?></td>
                        <td>
                            <?php if ($s['enabled'] === 't' || $s['enabled'] === true): ?>
                                <span class="badge-ok">Activa</span>
                            <?php else: ?>
                                <span class="badge-err">Inactiva</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $s['total_asociados'] ?></td>
                        <td><?= $s['pendientes'] > 0 ? '<span class="badge-warn">' . $s['pendientes'] . '</span>' : '<span class="badge-ok">0</span>' ?></td>
                        <td>
                            <a href="/dashboard/sucursales?sucursal=<?= urlencode($s['id_sucursal']) ?>" role="button" class="secondary outline" style="padding:0.25rem 0.5rem">Ver</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
