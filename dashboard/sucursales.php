<?php

$pageTitle = 'Sucursales';

$pdo = getDB();
$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $sucursalId = $_POST['sucursal_id'] ?? '';
    $archivoId = (int)($_POST['archivo_id'] ?? 0);

    if ($_POST['action'] === 'asociar' && $sucursalId && $archivoId) {
        try {
            $stmt = $pdo->prepare("SELECT nombre FROM archivos WHERE id = ?");
            $stmt->execute([$archivoId]);
            $file = $stmt->fetch();

            if (!$file) {
                $error = 'Archivo no encontrado';
            } else {
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

    $query = $sucursalId ? '?sucursal=' . urlencode($sucursalId) : '';
    header('Location: /dashboard/sucursales' . $query);
    exit;
}

$sucursalDetalle = $_GET['sucursal'] ?? '';

$stmt = $pdo->query("
    SELECT s.id_sucursal, s.nombre_sucursal, s.enabled,
           COUNT(DISTINCT asu.archivo_id) AS total_asociados
    FROM sucursales s
    LEFT JOIN archivo_sucursal asu ON s.id_sucursal = asu.sucursal_id AND asu.enabled = TRUE
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
        echo '<p>style="color:red"">Sucursal no encontrada</p>';
    } else {
        $stmt = $pdo->prepare("
            SELECT a.id, a.ruta, a.nombre, a.md5zip, a.xxh3, a.peso, asu.sync, asu.created_at AS asociado_desde
            FROM archivo_sucursal asu
            JOIN archivos a ON a.id = asu.archivo_id
            WHERE asu.sucursal_id = ? AND asu.enabled = TRUE
            ORDER BY a.ruta, a.nombre
        ");
        $stmt->execute([$sucursalDetalle]);
        $asociados = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT a.id, a.ruta, a.nombre
            FROM archivos a
            WHERE a.id NOT IN (
                SELECT asu2.archivo_id FROM archivo_sucursal asu2
                WHERE asu2.sucursal_id = ? AND asu2.enabled = TRUE
            )
            ORDER BY a.ruta, a.nombre
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
                    <th>XXH3</th>
                    <th>Sincronizado</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($asociados)): ?>
                    <tr><td colspan="5">Sin archivos asociados</td></tr>
                <?php else: ?>
                    <?php foreach ($asociados as $a):
                        $estaSync = ($a['sync'] === 't' || $a['sync'] === true);
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($a['ruta'] . '/' . $a['nombre']) ?></td>
                            <td><code><?= htmlspecialchars($a['md5zip'] ?? '-') ?></code></td>
                            <td><code><?= htmlspecialchars($a['xxh3'] ?? '-') ?></code></td>
                            <td><?= $estaSync ? '<span>✔</span>' : '<span>Pendiente</span>' ?></td>
                            <td>
                                <form method="POST" action="/dashboard/sucursales" style="display:inline">
                                    <input type="hidden" name="action" value="desasociar">
                                    <input type="hidden" name="sucursal_id" value="<?= htmlspecialchars($sucursalDetalle) ?>">
                                    <input type="hidden" name="archivo_id" value="<?= (int)$a['id'] ?>">
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
                    <option value="<?= (int)$d['id'] ?>">
                        <?= htmlspecialchars($d['ruta'] . '/' . $d['nombre']) ?>
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
                                <span style="color:green;">Activa</span>
                            <?php else: ?>
                                <span style="color:red;">Inactiva</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $s['total_asociados'] ?></td>
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
