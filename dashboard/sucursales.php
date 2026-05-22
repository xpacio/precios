<?php

$pageTitle = 'Sucursales';

$pdo = getDB();
$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $sucursalId = $_POST['sucursal_id'] ?? '';
    $archivoId = (int)($_POST['archivo_id'] ?? 0);

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

$search = trim($_GET['q'] ?? '');
$sucursales = [];
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
            SELECT a.id, a.ruta, a.nombre, a.flat, a.xxh3, a.peso, asu.sync, asu.created_at AS asociado_desde
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

    <h3>Archivos Asociados (<?= count($asociados) ?>)</h3>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Archivo</th>
                    <th>Flat</th>
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
                            <td><?= htmlspecialchars(str_replace('/srv/precios/', '', $a['ruta']) . '/' . $a['nombre']) ?></td>
                            <td><code><?= htmlspecialchars($a['flat'] ?? '-') ?></code></td>
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

    <?php } ?>
<?php else: ?>
    <div style="display:flex;gap:0.75rem;align-items:end;margin-bottom:1rem;">
        <div style="flex:1;">
            <label for="q">Buscar sucursal (código o nombre)</label>
            <input type="text" name="q" id="q" value="<?= htmlspecialchars($search) ?>" minlength="2" placeholder="Escribe al menos 2 caracteres..." autofocus>
        </div>
        <a href="/dashboard/sucursal_crear" role="button" class="secondary outline" style="padding:0.4rem 1rem;white-space:nowrap;">+ Nueva Sucursal</a>
    </div>

    <div id="sucursales-results">
        <p>Ingresa al menos 2 caracteres para buscar sucursales.</p>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const input = document.getElementById('q');
        const results = document.getElementById('sucursales-results');
        let timer;

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
