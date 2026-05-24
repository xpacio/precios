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

// === POST: toggle enabled (AJAX) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle-enabled' && ($_POST['sucursal_id'] ?? '')) {
    header('Content-Type: application/json');
    $sid = $_POST['sucursal_id'];
    $enabled = !empty($_POST['enabled']);
    try {
        $pdo->prepare("UPDATE archivo_sucursal SET enabled = ? WHERE archivo_id = ? AND sucursal_id = ?")
            ->execute([$enabled ? 't' : 'f', $id, $sid]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// === POST: toggle sync (AJAX) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle-sync' && ($_POST['sucursal_id'] ?? '')) {
    header('Content-Type: application/json');
    $sid = $_POST['sucursal_id'];
    $sync = !empty($_POST['sync']);
    try {
        $pdo->prepare("UPDATE archivo_sucursal SET sync = ? WHERE archivo_id = ? AND sucursal_id = ?")
            ->execute([$sync ? 't' : 'f', $id, $sid]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
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
    SELECT s.id_sucursal, s.nombre_sucursal, asu.sync, asu.enabled
    FROM archivo_sucursal asu
    JOIN sucursales s ON s.id_sucursal = asu.sucursal_id
    WHERE asu.archivo_id = ?
    ORDER BY asu.enabled DESC, s.id_sucursal
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
        <span style="margin-left:1rem;">
            <a href="/dashboard/archivos?tab=asociar&id=<?= $id ?>&q=<?= urlencode($arch['nombre']) ?>" style="text-decoration:none;font-size:1.2rem;color:#888;" title="Asociar a sucursal">+</a>
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
                <th>Activo</th>
                <th>Descargado</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($sucursales)): ?>
                <tr><td colspan="5">Sin sucursales asociadas. <a href="/dashboard/archivos?tab=asociar&id=<?= $id ?>&q=<?= urlencode($arch['nombre']) ?>">Asociar ahora</a></td></tr>
            <?php else: ?>
                <?php foreach ($sucursales as $s):
                    $activo = ($s['enabled'] === 't' || $s['enabled'] === true);
                ?>
                    <tr<?= $activo ? '' : ' style="opacity:0.4;"' ?>>
                        <td><a href="http://precios.servicios.care/dashboard/sucursales?sucursal=<?= urlencode($s['id_sucursal']) ?>"><code><?= htmlspecialchars($s['id_sucursal']) ?></code></a></td>
                        <td><?= htmlspecialchars($s['nombre_sucursal']) ?></td>
                        <td><input type="checkbox" class="toggle-enabled" data-id="<?= htmlspecialchars($s['id_sucursal']) ?>"<?= $activo ? ' checked' : '' ?>></td>
                        <td><input type="checkbox" class="toggle-sync" data-id="<?= htmlspecialchars($s['id_sucursal']) ?>"<?= ($s['sync'] === 't' || $s['sync'] === true) ? ' checked' : '' ?>></td>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    function toggleField(action, cb) {
        var formData = new FormData();
        formData.append('action', action);
        formData.append('sucursal_id', cb.dataset.id);
        formData.append(cb.className === 'toggle-enabled' ? 'enabled' : 'sync', cb.checked ? '1' : '');
        fetch('/dashboard/archivo-editar?id=<?= $id ?>', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) cb.checked = !cb.checked;
                else if (action === 'toggle-enabled') {
                    var tr = cb.closest('tr');
                    tr.style.opacity = cb.checked ? '1' : '0.4';
                }
            })
            .catch(function () { cb.checked = !cb.checked; });
    }
    document.querySelectorAll('.toggle-sync').forEach(function (cb) {
        cb.addEventListener('change', function () { toggleField('toggle-sync', this); });
    });
    document.querySelectorAll('.toggle-enabled').forEach(function (cb) {
        cb.addEventListener('change', function () { toggleField('toggle-enabled', this); });
    });
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
