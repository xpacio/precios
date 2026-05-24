<?php

$pageTitle = 'Editar Archivo';

$pdo = getDB();
$mensaje = '';
$error = '';

function fmtFecha($ts) {
    if (!$ts) return '-';
    $t = strtotime($ts);
    return substr(date('Y', $t), -1) . date('md.Hi', $t);
}

$id = (int)($segments[2] ?? $_GET['id'] ?? 0);

if (!$id) {
    header('Location: /dashboard/archivos');
    exit;
}

$archivo = $pdo->prepare("SELECT id, ruta, nombre, fecha_archivo, fecha_carga FROM archivos WHERE id = ?");
$archivo->execute([$id]);
$arch = $archivo->fetch();

if (!$arch) {
    header('Location: /dashboard/archivos');
    exit;
}

// === POST: desasociar sucursal ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'desasociar') {
    $sucursalId = $_POST['sucursal_id'] ?? '';
    if ($sucursalId) {
        try {
            $pdo->prepare("DELETE FROM archivo_sucursal WHERE archivo_id = ? AND sucursal_id = ?")
                ->execute([$id, $sucursalId]);
            $mensaje = 'Sucursal desasociada.';
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

// === GET: sucursales ligadas ===
$sucStmt = $pdo->prepare("
    SELECT s.id_sucursal, s.nombre_sucursal, asu.sync
    FROM archivo_sucursal asu
    JOIN sucursales s ON s.id_sucursal = asu.sucursal_id
    WHERE asu.archivo_id = ? AND asu.enabled = TRUE
    ORDER BY s.id_sucursal
");
$sucStmt->execute([$id]);
$sucursales = $sucStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'editar') {
    $ruta = trim($_POST['ruta'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');

    if ($ruta === '' || $nombre === '') {
        $error = 'Ruta y nombre son obligatorios.';
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE archivos SET ruta = ?, nombre = ? WHERE id = ?");
            $stmt->execute([$ruta, $nombre, $id]);
            $mensaje = "Archivo actualizado exitosamente.";
            $arch['ruta'] = $ruta;
            $arch['nombre'] = $nombre;
        } catch (Exception $e) {
            $error = 'Error al actualizar: ' . $e->getMessage();
        }
    }
}

require __DIR__ . '/header.php';
?>

<h1>Editar Archivo</h1>

<p><a href="/dashboard/archivos?tab=listar" class="secondary">&larr; Volver a archivos</a></p>

<?php if ($mensaje): ?>
    <div class="flash flash-success"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<article>
    <header>
        <strong>Editar Archivo #<?= $arch['id'] ?></strong>
        <span style="font-size:0.85rem;color:#888;margin-left:1rem;">
            Modificado: <?= fmtFecha($arch['fecha_archivo']) ?> &middot; Carga: <?= fmtFecha($arch['fecha_carga']) ?>
        </span>
    </header>
    <form method="POST" action="/dashboard/archivo-editar?id=<?= $id ?>">
        <input type="hidden" name="action" value="editar">
        <div class="grid">
            <label>
                Ruta
                <input type="text" name="ruta" value="<?= htmlspecialchars($arch['ruta']) ?>" required>
            </label>
            <label>
                Archivo
                <input type="text" name="nombre" value="<?= htmlspecialchars($arch['nombre']) ?>" required>
            </label>
        </div>
        <button type="submit">Guardar Cambios</button>
    </form>
</article>

<h2>Sucursales Asociadas (<?= count($sucursales) ?>)</h2>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>RBFID</th>
                <th>Nombre</th>
                <th>Descargado</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($sucursales)): ?>
                <tr><td colspan="4">Sin sucursales asociadas.</td></tr>
            <?php else: ?>
                <?php foreach ($sucursales as $s): ?>
                    <tr>
                        <td><a href="http://precios.servicios.care/dashboard/sucursales?sucursal=<?= urlencode($s['id_sucursal']) ?>"><code><?= htmlspecialchars($s['id_sucursal']) ?></code></a></td>
                        <td><?= htmlspecialchars($s['nombre_sucursal']) ?></td>
                        <td><?= ($s['sync'] === 't' || $s['sync'] === true) ? '<span style="color:green;font-weight:bold;">✔ Sincronizado</span>' : '<span style="color:#e65100;">Pendiente</span>' ?></td>
                        <td>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="desasociar">
                                <input type="hidden" name="sucursal_id" value="<?= htmlspecialchars($s['id_sucursal']) ?>">
                                <button type="submit" class="secondary outline" style="padding:0.2rem 0.5rem;font-size:0.8rem">Desasociar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/footer.php'; ?>
