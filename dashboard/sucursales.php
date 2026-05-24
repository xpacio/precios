<?php

$pageTitle = 'Sucursales';

$pdo = getDB();
$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $sucursalId = $_POST['sucursal_id'] ?? '';
    $archivoId = (int)($_POST['archivo_id'] ?? 0);

    // === AJAX: toggle sync ===
    if ($_POST['action'] === 'toggle-sync' && $sucursalId && $archivoId) {
        header('Content-Type: application/json');
        $sync = !empty($_POST['sync']);
        try {
            $pdo->prepare("UPDATE archivo_sucursal SET sync = ? WHERE archivo_id = ? AND sucursal_id = ?")
                ->execute([$sync ? 't' : 'f', $archivoId, $sucursalId]);
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'editar' && $sucursalId) {
        $nuevoNombre = trim($_POST['nombre_sucursal'] ?? '');
        $enabled = !empty($_POST['enabled']) ? 't' : 'f';
        if ($nuevoNombre) {
            try {
                $pdo->prepare("UPDATE sucursales SET nombre_sucursal = ?, enabled = ? WHERE id_sucursal = ?")
                    ->execute([$nuevoNombre, $enabled, $sucursalId]);
                $mensaje = 'Sucursal actualizada.';
            } catch (Exception $e) {
                $error = 'Error: ' . $e->getMessage();
            }
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

    if ($_POST['action'] === 'crear') {
        $nuevoId = trim($_POST['nuevo_id'] ?? '');
        $nuevoNombre = trim($_POST['nuevo_nombre'] ?? '');
        if (!$nuevoId || !$nuevoNombre) {
            $error = 'ID y nombre son requeridos';
        } elseif (!preg_match('/^[a-z0-9]+$/', $nuevoId)) {
            $error = 'ID solo puede contener letras minúsculas y números';
        } else {
            try {
                $pdo->prepare("INSERT INTO sucursales (id_sucursal, nombre_sucursal) VALUES (?, ?)")
                    ->execute([$nuevoId, $nuevoNombre]);
                $mensaje = "Sucursal '$nuevoId' creada exitosamente.";
            } catch (Exception $e) {
                $error = 'Error: ' . $e->getMessage();
            }
        }
    }

    if ($_POST['action'] === 'crear' && !$error) {
        header('Location: /dashboard/sucursales?sucursal=' . urlencode($nuevoId));
        exit;
    }
    if ($_POST['action'] !== 'crear') {
        $query = $sucursalId ? '?sucursal=' . urlencode($sucursalId) : '';
        header('Location: /dashboard/sucursales' . $query);
        exit;
    }
}

$sucursalDetalle = $_GET['sucursal'] ?? '';

$nuevoIdValue = htmlspecialchars($_POST['nuevo_id'] ?? '');
$nuevoNombreValue = htmlspecialchars($_POST['nuevo_nombre'] ?? '');

$search = trim($_GET['q'] ?? '');
$sucursales = [];

$stmtSin = $pdo->query("
    SELECT s.id_sucursal, s.nombre_sucursal, s.enabled
    FROM sucursales s
    WHERE NOT EXISTS (
        SELECT 1 FROM archivo_sucursal
        WHERE sucursal_id = s.id_sucursal AND enabled = TRUE
    )
    ORDER BY s.id_sucursal
    LIMIT 10
");
$sinArchivos = $stmtSin->fetchAll();

if (strlen($search) >= 2) {
    $stmt = $pdo->prepare("
        SELECT s.id_sucursal, s.nombre_sucursal, s.enabled,
               COUNT(DISTINCT asu.archivo_id) AS total_asociados
        FROM sucursales s
        LEFT JOIN archivo_sucursal asu ON s.id_sucursal = asu.sucursal_id AND asu.enabled = TRUE
        WHERE s.id_sucursal ILIKE ? OR s.nombre_sucursal ILIKE ?
        GROUP BY s.id_sucursal, s.nombre_sucursal, s.enabled
        ORDER BY s.id_sucursal
        LIMIT 20
    ");
    $like = '%' . $search . '%';
    $stmt->execute([$like, $like]);
    $sucursales = $stmt->fetchAll();
}

if ($_GET['ajax'] ?? false) {
    header('Content-Type: application/json');
    echo json_encode(['results' => $sucursales, 'total' => count($sucursales)]);
    exit;
}

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
            SELECT a.id, a.ruta, a.nombre, a.flat, a.br, a.peso, asu.sync, asu.created_at AS asociado_desde
            FROM archivo_sucursal asu
            JOIN archivos a ON a.id = asu.archivo_id
            WHERE asu.sucursal_id = ? AND asu.enabled = TRUE
            ORDER BY a.ruta, a.nombre
        ");
        $stmt->execute([$sucursalDetalle]);
        $asociados = $stmt->fetchAll();

    ?>
    <h2>
        <a href="/dashboard/sucursales" class="secondary">&larr; Volver</a>
        Sucursal: <?= htmlspecialchars($suc['id_sucursal']) ?> - <?= htmlspecialchars($suc['nombre_sucursal']) ?>
    </h2>

    <article>
        <form method="POST" action="/dashboard/sucursales" style="display:flex;gap:0.75rem;align-items:end;flex-wrap:wrap;">
            <input type="hidden" name="action" value="editar">
            <input type="hidden" name="sucursal_id" value="<?= htmlspecialchars($sucursalDetalle) ?>">
            <label style="flex:1;min-width:200px;">
                Nombre
                <input type="text" name="nombre_sucursal" value="<?= htmlspecialchars($suc['nombre_sucursal']) ?>" required>
            </label>
            <label style="white-space:nowrap;">
                <input type="checkbox" name="enabled" value="1" <?= ($suc['enabled'] ?? 't') === 't' ? 'checked' : '' ?>>
                Activa
            </label>
            <button type="submit" class="secondary outline" style="padding:0.3rem 0.8rem;">Guardar</button>
        </form>
    </article>

    <h3>Archivos Asociados (<?= count($asociados) ?>)</h3>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Archivo</th>
                    <th>fl</th>
                    <th>br</th>
                    <th>Sync</th>
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
                            <td><?= htmlspecialchars(str_replace('/srv/precios/', '', $a['ruta']) . '/' . $a['nombre']) ?></td>
                            <td><code><?= htmlspecialchars(!empty($a['flat']) ? substr($a['flat'], 0, 3) : '-') ?></code></td>
                            <td><code><?= htmlspecialchars(!empty($a['br']) ? substr($a['br'], 0, 3) : '-') ?></code></td>
                            <td><input type="checkbox" class="toggle-sync" data-id="<?= (int)$a['id'] ?>"<?= $estaSync ? ' checked' : '' ?>></td>
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

    <?php } ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.toggle-sync').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var formData = new FormData();
            formData.append('action', 'toggle-sync');
            formData.append('sucursal_id', '<?= htmlspecialchars($sucursalDetalle) ?>');
            formData.append('archivo_id', this.dataset.id);
            formData.append('sync', this.checked ? '1' : '');
            fetch('/dashboard/sucursales', { method: 'POST', body: formData })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) cb.checked = !cb.checked;
                })
                .catch(function () { cb.checked = !cb.checked; });
        });
    });
});
</script>
<?php else: ?>
    <nav class="tabs">
        <ul>
            <li><a href="#" data-tab="buscar" class="contrast">Buscar sucursales</a></li>
            <li><a href="#" data-tab="sin-archivos">Sin archivos asociados</a></li>
            <li><a href="#" data-tab="crear">+ Nueva sucursal</a></li>
        </ul>
    </nav>

    <div id="tab-buscar" class="tab-content">
        <label for="q">Buscar sucursal (código o nombre)</label>
        <input type="text" name="q" id="q" value="<?= htmlspecialchars($search) ?>" minlength="2" placeholder="Escribe al menos 2 caracteres..." autofocus>

        <div id="sucursales-results">
            <p>Ingresa al menos 2 caracteres para buscar sucursales.</p>
        </div>
    </div>

    <div id="tab-sin-archivos" class="tab-content" style="display:none;">
        <h2>Sucursales sin archivos asociados</h2>
        <?php if (empty($sinArchivos)): ?>
            <p>Todas las sucursales tienen al menos un archivo asociado.</p>
        <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Estado</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sinArchivos as $s):
                            $enabled = ($s['enabled'] === 't' || $s['enabled'] === true);
                        ?>
                            <tr>
                                <td><code><?= htmlspecialchars($s['id_sucursal']) ?></code></td>
                                <td><?= htmlspecialchars($s['nombre_sucursal']) ?></td>
                                <td><span style="color:<?= $enabled ? 'green">Activa' : 'red">Inactiva' ?></span></td>
                                <td>
                                    <a href="/dashboard/archivos?tab=asociar&sucursal=<?= urlencode($s['id_sucursal']) ?>" role="button" class="secondary outline" style="padding:0.25rem 0.5rem;">Asociar archivos</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div id="tab-crear" class="tab-content" style="display:none;">
        <?php if ($error && $_POST['action'] === 'crear'): ?>
            <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <article>
            <header><strong>Nueva Sucursal</strong></header>
            <form method="POST" action="/dashboard/sucursales">
                <input type="hidden" name="action" value="crear">
                <div class="grid">
                    <label>
                        ID (solo minúsculas y números)
                        <input type="text" name="nuevo_id" pattern="[a-z0-9]+" required placeholder="ej. suc001" value="<?= $nuevoIdValue ?>">
                    </label>
                    <label>
                        Nombre
                        <input type="text" name="nuevo_nombre" required placeholder="ej. Sucursal Centro" value="<?= $nuevoNombreValue ?>">
                    </label>
                </div>
                <button type="submit">Crear Sucursal</button>
            </form>
        </article>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const input = document.getElementById('q');
        const results = document.getElementById('sucursales-results');
        let timer;

        document.querySelectorAll('.tabs a[data-tab]').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelectorAll('.tabs a').forEach(function (a) { a.classList.remove('contrast'); });
                link.classList.add('contrast');
                document.querySelectorAll('.tab-content').forEach(function (d) { d.style.display = 'none'; });
                document.getElementById('tab-' + link.dataset.tab).style.display = '';
            });
        });

        function render(data) {
            if (data.total === 0) {
                results.innerHTML = '<p>No se encontraron sucursales para "<strong>' + escapeHtml(input.value) + '</strong>".</p>';
                return;
            }
            let html = '<p>' + data.total + ' resultado(s) para "<strong>' + escapeHtml(input.value) + '</strong>"' + (data.total === 20 ? ' (máximo 20)' : '') + '.</p>';
            html += '<div class="table-container"><table><thead><tr><th>Código</th><th>Nombre</th><th>Estado</th><th>Asociados</th><th>Acción</th></tr></thead><tbody>';
            for (const s of data.results) {
                const enabled = (s.enabled === 't' || s.enabled === true);
                html += '<tr>';
                html += '<td><code>' + escapeHtml(s.id_sucursal) + '</code></td>';
                html += '<td>' + escapeHtml(s.nombre_sucursal) + '</td>';
                html += '<td><span style="color:' + (enabled ? 'green">Activa' : 'red">Inactiva') + '</span></td>';
                html += '<td>' + s.total_asociados + '</td>';
                html += '<td><a href="/dashboard/sucursales?sucursal=' + encodeURIComponent(s.id_sucursal) + '" role="button" class="secondary outline" style="padding:0.25rem 0.5rem">Ver</a></td>';
                html += '</tr>';
            }
            html += '</tbody></table></div>';
            results.innerHTML = html;
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function search() {
            const q = input.value.trim();
            if (q.length < 2) {
                results.innerHTML = '<p>Ingresa al menos 2 caracteres para buscar sucursales.</p>';
                return;
            }
            fetch('?q=' + encodeURIComponent(q) + '&ajax=1')
                .then(function (r) { return r.json(); })
                .then(render)
                .catch(function () { results.innerHTML = '<p style="color:red">Error al buscar.</p>'; });
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(search, 300);
        });
    });
    </script>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
