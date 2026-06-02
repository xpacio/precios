<?php

$pageTitle = 'Editar Archivo';

require_once __DIR__ . '/../lib/sync_helper.php';

$pdo = getDB();
$mensaje = '';
$error = '';

function fmtFecha($ts) {
    if (!$ts) return '-';
    $t = strtotime($ts);
    return substr(date('Y', $t), -1) . date('md.Hi', $t);
}

function timeago($ts) {
    if (!$ts) return '-';
    $diff = time() - strtotime($ts);
    if ($diff < 0) return '0s';
    $s = $diff;
    $m = intdiv($s, 60);
    $h = intdiv($s, 3600);
    $d = intdiv($s, 86400);
    $M = intdiv($d, 30);
    $a = intdiv($d, 365);
    if ($s < 60) return $s . 's';
    if ($m < 60) return $m . 'm';
    if ($h < 2) return $h . 'h' . ($m % 60 ? ($m % 60) . 'm' : '');
    if ($h < 24) return $h . 'h+';
    if ($d === 1) return '1d';
    if ($d === 2) return '2d+';
    if ($d < 30) return $d . 'd+';
    if ($M === 1) return '1M';
    if ($M < 12) return $M . 'M+';
    if ($a === 1) return '1a';
    return $a . 'a+';
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

// === POST: gen-dbd-file (AJAX) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'gen-dbd-file' && ($_POST['sucursal_id'] ?? '')) {
    header('Content-Type: application/json');
    $sid = $_POST['sucursal_id'];
    try {
        $clave = strtoupper(substr(md5(time() . '-' . $sid . '-' . $_SESSION['user_id']), 0, 6));
        $pdo->prepare("UPDATE archivo_sucursal SET enabled = TRUE WHERE archivo_id = ? AND sucursal_id = ?")
            ->execute([$id, $sid]);
        $pdo->prepare("UPDATE sucursales SET clave_dbd = ? WHERE id_sucursal = ?")
            ->execute([$clave, $sid]);
        echo json_encode(['ok' => true, 'clave_dbd' => $clave]);
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

// === POST: sync-one (AJAX) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync-one') {
    header('Content-Type: application/json');
    $startTime = microtime(true);
    $relPath = $arch['ruta'] . '/' . $arch['nombre'];
    $getOneScript = realpath(__DIR__ . '/../scripts/getOne.sh');
    $cmd = "sudo " . escapeshellarg($getOneScript) . " --fast " . escapeshellarg($relPath) . " 2>&1";
    exec($cmd, $output, $exitCode);
    $elapsed = round(microtime(true) - $startTime, 2);
    if ($exitCode !== 0) {
        logSync($pdo, 'one', '--fast', 1, 0, 0, 0, 1, $exitCode, $elapsed);
        echo json_encode(['ok' => false, 'mensaje' => 'Error al sincronizar: ' . implode("\n", $output)]);
        exit;
    }
    $result = processAndCompressFile($arch['ruta'], $arch['nombre']);
    $procesados = $result['status'] === 'OK' ? 1 : 0;
    $omitidos = $result['status'] === 'SKIP' ? 1 : 0;
    $errores = $result['status'] === 'ERROR' ? 1 : 0;
    logSync($pdo, 'one', '--fast', 1, 1, $procesados, $omitidos, $errores, $exitCode, $elapsed);
    $msg = $result['status'] === 'OK' ? 'Sincronizado y comprimido' : 'Sincronizado (' . $result['mensaje'] . ')';
    echo json_encode(['ok' => true, 'mensaje' => $msg]);
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
    SELECT s.id_sucursal, s.nombre_sucursal, asu.sync, asu.enabled, s.clave_dbd
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
            Modificado: <?= fmtFecha($arch['fecha_archivo']) ?> (<?= timeago($arch['fecha_archivo']) ?>) &middot; Carga: <?= fmtFecha($arch['fecha_carga']) ?> (<?= timeago($arch['fecha_carga']) ?>)
        </span>
        <span style="margin-left:1rem;display:inline-flex;align-items:center;gap:0.5rem;">
            <a href="/dashboard/archivos?tab=asociar&id=<?= $id ?>&q=<?= urlencode($arch['nombre']) ?>" role="button" class="primary" style="padding:0.15rem 0.5rem;font-size:0.8rem;text-decoration:none;" title="Asociar a sucursal">asociar</a>
            <button id="btnSyncOne" class="primary" style="padding:0.15rem 0.5rem;font-size:0.8rem;cursor:pointer;" title="Sincronizar archivo desde origen">Sync</button>
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
        <div style="margin-bottom:0.5rem;display:flex;align-items:center;gap:0.4rem;">
          <?php if (strpos($arch['ruta'], 'DSBLIND') !== false): ?>
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#bf616a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2l0 -6"/><path d="M11 16a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M8 11v-5a4 4 0 0 1 8 0"/></svg>
            <span style="color:#bf616a;font-size:0.85rem;">Desblinde</span>
          <?php else: ?>
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#5e81ac" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6"/><path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0"/><path d="M8 11v-4a4 4 0 1 1 8 0v4"/></svg>
            <span style="color:#5e81ac;font-size:0.85rem;">Normal</span>
          <?php endif; ?>
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
                <th>Clave DBD</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($sucursales)): ?>
                <tr><td colspan="6">Sin sucursales asociadas. <a href="/dashboard/archivos?tab=asociar&id=<?= $id ?>&q=<?= urlencode($arch['nombre']) ?>">Asociar ahora</a></td></tr>
            <?php else: ?>
                <?php foreach ($sucursales as $s):
                    $activo = ($s['enabled'] === 't' || $s['enabled'] === true);
                ?>
                    <tr<?= $activo ? '' : ' style="opacity:0.4;"' ?>>
                        <td><a href="http://precios.servicios.care/dashboard/sucursales?sucursal=<?= urlencode($s['id_sucursal']) ?>"><code><?= htmlspecialchars($s['id_sucursal']) ?></code></a></td>
                        <td><?= htmlspecialchars($s['nombre_sucursal']) ?></td>
                        <td><input type="checkbox" class="toggle-enabled" data-id="<?= htmlspecialchars($s['id_sucursal']) ?>"<?= $activo ? ' checked' : '' ?>></td>
                        <td><input type="checkbox" class="toggle-sync" data-id="<?= htmlspecialchars($s['id_sucursal']) ?>"<?= ($s['sync'] === 't' || $s['sync'] === true) ? ' checked' : '' ?>></td>
                        <td><code style="font-size:0.8rem;"><?= $s['clave_dbd'] ? htmlspecialchars($s['clave_dbd']) : '-' ?></code></td>
                        <td style="white-space:nowrap">
                            <?php if (strpos($arch['ruta'], 'DSBLIND') !== false): ?>
                            <button class="gen-dbd-file secondary outline" data-sucursal="<?= htmlspecialchars($s['id_sucursal']) ?>" style="padding:0.2rem 0.5rem;font-size:0.8rem">DBD</button>
                            <?php endif; ?>
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

<?php if (strpos($arch['ruta'], 'DSBLIND') !== false):
    $dbdLogStmt = $pdo->prepare("
        SELECT cl.id, cl.sucursal_id, cl.dbd_user, cl.created_at
        FROM cli_log cl
        WHERE cl.file_name = ? AND cl.file_type = 'DBD'
        ORDER BY cl.created_at DESC
        LIMIT 20
    ");
    $dbdLogStmt->execute([$arch['nombre']]);
    $dbdLogEntries = $dbdLogStmt->fetchAll();
    if (!empty($dbdLogEntries)):
?>
    <h2>Descargas DBD</h2>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Sucursal</th>
                    <th>Usuario</th>
                    <th>Fecha</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dbdLogEntries as $dl): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($dl['sucursal_id']) ?></code></td>
                        <td><?= htmlspecialchars($dl['dbd_user'] ?? '-') ?></td>
                        <td style="white-space:nowrap;"><?= date('d/m H:i', strtotime($dl['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php
    endif;
endif;
?>

<h2>Historial de Cambios</h2>

<?php
$logStmt = $pdo->prepare("
    SELECT id, action, prev_flat, new_flat, detalle, created_at
    FROM archivo_log
    WHERE archivo_id = ?
    ORDER BY created_at DESC
    LIMIT 30
");
$logStmt->execute([$id]);
$logEntries = $logStmt->fetchAll();
?>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>Acción</th>
                <th>Detalle</th>
                <th>Flat Anterior</th>
                <th>Flat Nuevo</th>
                <th>Fecha</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logEntries)): ?>
                <tr><td colspan="5">Sin cambios registrados.</td></tr>
            <?php else: ?>
                <?php foreach ($logEntries as $entry): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($entry['action']) ?></code></td>
                        <td><?= htmlspecialchars($entry['detalle'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($entry['prev_flat'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($entry['new_flat'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($entry['created_at']) ?></td>
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

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.gen-dbd-file');
        if (!btn) return;
        e.preventDefault();
        var sucId = btn.dataset.sucursal;
        var formData = new FormData();
        formData.append('action', 'gen-dbd-file');
        formData.append('sucursal_id', sucId);
        fetch('/dashboard/archivo-editar?id=<?= $id ?>', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) {
                    var flash = document.createElement('div');
                    flash.className = 'flash flash-success';
                    flash.textContent = 'Clave DBD: ' + data.clave_dbd;
                    var article = document.querySelector('article');
                    article.parentNode.insertBefore(flash, article);
                    setTimeout(function () { flash.remove(); }, 8000);
                } else {
                    alert('Error: ' + (data.error || 'desconocido'));
                }
            })
            .catch(function () { alert('Error de red al generar clave DBD'); });
    });

    document.getElementById('btnSyncOne').addEventListener('click', function () {
        var btn = this;
        btn.setAttribute('aria-busy', 'true');
        btn.disabled = true;
        var formData = new FormData();
        formData.append('action', 'sync-one');
        fetch('/dashboard/archivo-editar?id=<?= $id ?>', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.removeAttribute('aria-busy');
                btn.disabled = false;
                var flash = document.createElement('div');
                flash.className = data.ok ? 'flash flash-success' : 'flash flash-error';
                flash.textContent = data.mensaje || (data.ok ? 'OK' : 'Error');
                var article = document.querySelector('article');
                article.parentNode.insertBefore(flash, article);
                setTimeout(function () { flash.remove(); }, 5000);
            })
            .catch(function () {
                btn.removeAttribute('aria-busy');
                btn.disabled = false;
            });
    });
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
