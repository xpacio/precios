<?php

$pageTitle = 'Sucursales';

$pdo = getDB();
$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $sucursalId = $_POST['sucursal_id'] ?? '';
    $archivoId = (int)($_POST['archivo_id'] ?? 0);

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

    if ($_POST['action'] === 'toggle-enabled' && $sucursalId && $archivoId) {
        header('Content-Type: application/json');
        $enabled = !empty($_POST['enabled']);
        try {
            $pdo->prepare("UPDATE archivo_sucursal SET enabled = ? WHERE archivo_id = ? AND sucursal_id = ?")
                ->execute([$enabled ? 't' : 'f', $archivoId, $sucursalId]);
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

    if (($_POST['action'] === 'crear' && !$error)) {
        header('Location: /dashboard/sucursales');
        exit;
    }
    if ($_POST['action'] !== 'crear') {
        header('Location: /dashboard/sucursales');
        exit;
    }
}

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

// === AJAX: sucursal detail for dynamic tabs ===
if (($_GET['action'] ?? '') === 'detail') {
    header('Content-Type: application/json');
    $sucId = $_GET['sucursal'] ?? '';

    if (!$sucId) {
        echo json_encode(['ok' => false, 'error' => 'ID requerido']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id_sucursal, nombre_sucursal, enabled FROM sucursales WHERE id_sucursal = ?");
    $stmt->execute([$sucId]);
    $suc = $stmt->fetch();

    if (!$suc) {
        echo json_encode(['ok' => false, 'error' => 'Sucursal no encontrada']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT a.id, a.ruta, a.nombre, a.flat, a.br, a.peso, asu.enabled, asu.sync, asu.es_desblinde, asu.created_at AS asociado_desde,
               asu.ultimo_resultado, asu.n_envios, asu.n_exitos
        FROM archivo_sucursal asu
        JOIN archivos a ON a.id = asu.archivo_id
        WHERE asu.sucursal_id = ?
        ORDER BY asu.enabled DESC, a.ruta, a.nombre
    ");
    $stmt->execute([$sucId]);
    $asociados = $stmt->fetchAll();

    ob_start();
    ?>
    <style>
        .compact-table td, .compact-table th { padding:0.2rem 0.5rem !important; }
    </style>
    <div class="table-container">
        <table class="compact-table" style="font-size:0.85rem;">
            <thead>
                <tr>
                    <th>Archivo</th>
                    <th>fl</th>
                    <th>br</th>
                    <th style="text-align:center;">Tipo</th>
                    <th style="text-align:center;">Activo</th>
                    <th style="text-align:center;">Sync</th>
                    <th style="text-align:center;">Resultado</th>
                    <th style="text-align:center;">Env</th>
                    <th style="text-align:center;">Ex</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($asociados)): ?>
                    <tr><td colspan="10">Sin archivos asociados</td></tr>
                <?php else: ?>
                    <?php foreach ($asociados as $a):
                        $estaSync = ($a['sync'] === 't' || $a['sync'] === true);
                        $estaActivo = ($a['enabled'] === 't' || $a['enabled'] === true);
                    ?>
                        <tr<?= $estaActivo ? '' : ' style="opacity:0.4;"' ?>>
                            <td><a href="/dashboard/archivo-editar?id=<?= (int)$a['id'] ?>"><?= htmlspecialchars(str_replace('/srv/precios/', '', $a['ruta']) . '/' . $a['nombre']) ?></a></td>
                            <td><code><?= htmlspecialchars(!empty($a['flat']) ? substr($a['flat'], 0, 3) : '-') ?></code></td>
                            <td><code><?= htmlspecialchars(!empty($a['br']) ? substr($a['br'], 0, 3) : '-') ?></code></td>
                            <td style="text-align:center;">
                              <?php if ($a['es_desblinde'] === 't' || $a['es_desblinde'] === true): ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#bf616a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2l0 -6"/><path d="M11 16a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M8 11v-5a4 4 0 0 1 8 0"/></svg>
                              <?php else: ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#5e81ac" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6"/><path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0"/><path d="M8 11v-4a4 4 0 1 1 8 0v4"/></svg>
                              <?php endif; ?>
                            </td>
                            <td style="text-align:center;"><input type="checkbox" class="toggle-enabled" data-id="<?= (int)$a['id'] ?>" data-sucursal="<?= htmlspecialchars($sucId) ?>"<?= $estaActivo ? ' checked' : '' ?>></td>
                            <td style="text-align:center;"><input type="checkbox" class="toggle-sync" data-id="<?= (int)$a['id'] ?>" data-sucursal="<?= htmlspecialchars($sucId) ?>"<?= $estaSync ? ' checked' : '' ?>></td>
                            <?php
                                $resultado = $a['ultimo_resultado'] ?? 'pending';
                                $badgeColor = match ($resultado) {
                                    'downloaded'    => '#2e7d32',
                                    'skip'          => '#1565c0',
                                    'pending'       => '#9e9e9e',
                                    'error-br'      => '#c62828',
                                    'error-flat'    => '#c62828',
                                    'error-tmp'     => '#c62828',
                                    'error-blocked' => '#e65100',
                                    default         => '#9e9e9e',
                                };
                            ?>
                            <td style="text-align:center;">
                                <span style="display:inline-block;padding:0.1rem 0.4rem;border-radius:3px;background:<?= $badgeColor ?>;color:#fff;font-size:0.75rem;font-weight:600;"><?= htmlspecialchars($resultado) ?></span>
                            </td>
                            <td style="text-align:center;"><?= (int)($a['n_envios'] ?? 0) ?></td>
                            <td style="text-align:center;"><?= (int)($a['n_exitos'] ?? 0) ?></td>
                            <td>
                                <form method="POST" action="/dashboard/sucursales" style="display:inline">
                                    <input type="hidden" name="action" value="desasociar">
                                    <input type="hidden" name="sucursal_id" value="<?= htmlspecialchars($sucId) ?>">
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
    <?php
    $archivosHtml = ob_get_clean();

    ob_start();
    ?>
    <article>
        <form method="POST" action="/dashboard/sucursales" style="display:flex;gap:0.75rem;align-items:end;flex-wrap:wrap;">
            <input type="hidden" name="action" value="editar">
            <input type="hidden" name="sucursal_id" value="<?= htmlspecialchars($sucId) ?>">
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
    <?php
    $editarHtml = ob_get_clean();

    echo json_encode([
        'ok' => true,
        'id' => $sucId,
        'nombre' => $suc['nombre_sucursal'],
        'total' => count($asociados),
        'archivos_html' => $archivosHtml,
        'editar_html' => $editarHtml,
    ]);
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

<nav class="tabs" id="sucursales-tabs">
    <ul>
        <li><a href="#" data-tab="buscar" class="contrast">Buscar sucursales</a></li>
        <li><a href="#" data-tab="sin-archivos">Sin archivos asociados</a></li>
        <li><a href="#" data-tab="crear">+ Nueva sucursal</a></li>
    </ul>
</nav>

<div id="tab-content-container">
<?php require __DIR__ . '/sucursales-tab-buscar.php'; ?>
<?php require __DIR__ . '/sucursales-tab-sin-archivos.php'; ?>
<?php require __DIR__ . '/sucursales-tab-crear.php'; ?>
</div>

<script>
function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function activateTab(tabId) {
    document.querySelectorAll('#sucursales-tabs a').forEach(function (a) { a.classList.remove('contrast'); });
    var tabLink = document.querySelector('#sucursales-tabs a[data-tab="' + tabId + '"]');
    if (tabLink) tabLink.classList.add('contrast');
    document.querySelectorAll('#tab-content-container > .tab-content').forEach(function (d) { d.style.display = 'none'; });
    var content = document.getElementById('tab-' + tabId);
    if (content) content.style.display = '';
}

document.addEventListener('DOMContentLoaded', function () {
    // === Main tab switching ===
    document.addEventListener('click', function (e) {
        var link = e.target.closest('#sucursales-tabs a[data-tab]');
        if (!link) return;
        e.preventDefault();
        activateTab(link.dataset.tab);
    });

    // === Sub-tab switching (Archivos / Editar inside dynamic tabs) ===
    document.addEventListener('click', function (e) {
        var link = e.target.closest('.suc-subtabs a[data-tab]');
        if (!link) return;
        e.preventDefault();
        var container = link.closest('.suc-detail-tab');
        if (!container) return;
        container.querySelectorAll('.suc-subtabs a').forEach(function (a) { a.classList.remove('contrast'); });
        link.classList.add('contrast');
        container.querySelectorAll('.suc-subcontent').forEach(function (d) { d.style.display = 'none'; });
        var content = container.querySelector('#' + link.dataset.tab);
        if (content) content.style.display = '';
    });

    // === Toggle Sync (event delegation) ===
    document.addEventListener('change', function (e) {
        var cb = e.target.closest('.toggle-sync');
        if (!cb) return;
        var formData = new FormData();
        formData.append('action', 'toggle-sync');
        formData.append('sucursal_id', cb.dataset.sucursal);
        formData.append('archivo_id', cb.dataset.id);
        formData.append('sync', cb.checked ? '1' : '');
        fetch('/dashboard/sucursales', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) cb.checked = !cb.checked;
            })
            .catch(function () { cb.checked = !cb.checked; });
    });

    // === Toggle Enabled (event delegation) ===
    document.addEventListener('change', function (e) {
        var cb = e.target.closest('.toggle-enabled');
        if (!cb) return;
        var tr = cb.closest('tr');
        var formData = new FormData();
        formData.append('action', 'toggle-enabled');
        formData.append('sucursal_id', cb.dataset.sucursal);
        formData.append('archivo_id', cb.dataset.id);
        formData.append('enabled', cb.checked ? '1' : '');
        fetch('/dashboard/sucursales', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) cb.checked = !cb.checked;
                else tr.style.opacity = cb.checked ? '1' : '0.4';
            })
            .catch(function () { cb.checked = !cb.checked; });
    });

    // === "Ver" sucursal → dynamic tab ===
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.ver-sucursal');
        if (!btn) return;
        e.preventDefault();
        var sucId = btn.dataset.sucursal;
        var existingTab = document.querySelector('#sucursales-tabs a[data-tab="suc-' + sucId + '"]');
        if (existingTab) {
            activateTab('suc-' + sucId);
            return;
        }

        fetch('/dashboard/sucursales?action=detail&sucursal=' + encodeURIComponent(sucId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) {
                    alert(data.error || 'Error al cargar');
                    return;
                }
                var nav = document.querySelector('#sucursales-tabs ul');
                var li = document.createElement('li');
                li.innerHTML = '<a href="#" data-tab="suc-' + data.id + '" class="contrast">' + escapeHtml(data.id + ' — ' + data.nombre) + '</a>';
                nav.insertBefore(li, nav.lastElementChild);

                var container = document.getElementById('tab-content-container');
                var div = document.createElement('div');
                div.id = 'tab-suc-' + data.id;
                div.className = 'suc-detail-tab tab-content';
                div.innerHTML =
                    '<nav class="tabs suc-subtabs">' +
                        '<ul>' +
                            '<li><a href="#" data-tab="suc-' + data.id + '-archivos" class="contrast">Archivos (' + data.total + ')</a></li>' +
                            '<li><a href="#" data-tab="suc-' + data.id + '-editar">Editar</a></li>' +
                        '</ul>' +
                    '</nav>' +
                    '<div id="suc-' + data.id + '-archivos" class="suc-subcontent">' + data.archivos_html + '</div>' +
                    '<div id="suc-' + data.id + '-editar" class="suc-subcontent" style="display:none">' + data.editar_html + '</div>';
                container.appendChild(div);

                activateTab('suc-' + data.id);
            })
            .catch(function () { alert('Error de red al cargar sucursal'); });
    });

    // === Search ===
    var input = document.getElementById('q');
    var results = document.getElementById('sucursales-results');
    var timer;

    function render(data) {
        if (data.total === 0) {
            results.innerHTML = '<p>No se encontraron sucursales para "<strong>' + escapeHtml(input.value) + '</strong>".</p>';
            return;
        }
        var html = '<p>' + data.total + ' resultado(s) para "<strong>' + escapeHtml(input.value) + '</strong>"' + (data.total === 20 ? ' (máximo 20)' : '') + '.</p>';
        html += '<div class="table-container"><table><thead><tr><th>Código</th><th>Nombre</th><th>Estado</th><th>Asociados</th><th>Acción</th></tr></thead><tbody>';
        for (var i = 0; i < data.results.length; i++) {
            var s = data.results[i];
            var enabled = (s.enabled === 't' || s.enabled === true);
            html += '<tr>';
            html += '<td><code>' + escapeHtml(s.id_sucursal) + '</code></td>';
            html += '<td>' + escapeHtml(s.nombre_sucursal) + '</td>';
            html += '<td><span style="color:' + (enabled ? 'green">Activa' : 'red">Inactiva') + '</span></td>';
            html += '<td>' + s.total_asociados + '</td>';
            html += '<td><a href="#" class="ver-sucursal secondary outline" role="button" data-sucursal="' + escapeHtml(s.id_sucursal) + '" style="padding:0.25rem 0.5rem">Ver</a></td>';
            html += '</tr>';
        }
        html += '</tbody></table></div>';
        results.innerHTML = html;
    }

    function search() {
        var q = input.value.trim();
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

<?php require __DIR__ . '/footer.php'; ?>
