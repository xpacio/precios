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

    if ($_POST['action'] === 'toggle-sucursal-enabled' && $sucursalId) {
        header('Content-Type: application/json');
        $enabled = !empty($_POST['enabled']);
        try {
            $pdo->prepare("UPDATE sucursales SET enabled = ? WHERE id_sucursal = ?")
                ->execute([$enabled ? 't' : 'f', $sucursalId]);
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'gen-dbd-suc' && $sucursalId) {
        header('Content-Type: application/json');
        try {
            $clave = strtoupper(substr(md5(time() . '-' . $sucursalId . '-' . $_SESSION['user_id']), 0, 6));
            $pdo->prepare("UPDATE archivo_sucursal SET enabled = TRUE WHERE sucursal_id = ? AND es_desblinde = TRUE")
                ->execute([$sucursalId]);
            $pdo->prepare("UPDATE sucursales SET clave_dbd = ? WHERE id_sucursal = ?")
                ->execute([$clave, $sucursalId]);
            echo json_encode(['ok' => true, 'clave_dbd' => $clave, 'sucursal_id' => $sucursalId]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'editar' && $sucursalId) {
        header('Content-Type: application/json');
        $nuevoNombre = trim($_POST['nombre_sucursal'] ?? '');
        $enabled = !empty($_POST['enabled']) ? 't' : 'f';
        if (!$nuevoNombre) {
            echo json_encode(['ok' => false, 'error' => 'Nombre requerido']);
            exit;
        }
        try {
            $pdo->prepare("UPDATE sucursales SET nombre_sucursal = ?, enabled = ? WHERE id_sucursal = ?")
                ->execute([$nuevoNombre, $enabled, $sucursalId]);
            echo json_encode(['ok' => true, 'nombre' => $nuevoNombre]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'desasociar' && $sucursalId && $archivoId) {
        header('Content-Type: application/json');
        try {
            $pdo->prepare("DELETE FROM archivo_sucursal WHERE archivo_id = ? AND sucursal_id = ?")
                ->execute([$archivoId, $sucursalId]);
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
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

    $stmt = $pdo->prepare("SELECT id_sucursal, nombre_sucursal, enabled, clave_dbd FROM sucursales WHERE id_sucursal = ?");
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

    $dsblindCount = 0;
    foreach ($asociados as $a) {
        if ($a['es_desblinde'] === 't' || $a['es_desblinde'] === true) {
            $dsblindCount++;
        }
    }

    $dbdStmt = $pdo->prepare("SELECT id, file_name, dbd_user, created_at FROM cli_log WHERE sucursal_id = ? AND file_type = 'DBD' ORDER BY created_at DESC LIMIT 20");
    $dbdStmt->execute([$sucId]);
    $dbdLogs = $dbdStmt->fetchAll();

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
                    <th style="text-align:center;">fl–br</th>
                    <th style="text-align:center;">Tipo</th>
                    <th style="text-align:center;">Activo</th>
                    <th style="text-align:center;">Sync</th>
                    <th style="text-align:center;">Resultado</th>
                    <th style="text-align:center;">Env/Ex</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($asociados)): ?>
                    <tr><td colspan="8">Sin archivos asociados</td></tr>
                <?php else: ?>
                    <?php foreach ($asociados as $a):
                        $estaSync = ($a['sync'] === 't' || $a['sync'] === true);
                        $estaActivo = ($a['enabled'] === 't' || $a['enabled'] === true);
                    ?>
                        <tr<?= $estaActivo ? '' : ' style="opacity:0.4;"' ?>>
                            <td><a href="/dashboard/archivo-editar?id=<?= (int)$a['id'] ?>"><?= htmlspecialchars(str_replace('/srv/precios/', '', $a['ruta']) . '/' . $a['nombre']) ?></a></td>
                            <td style="text-align:center;"><code><?= htmlspecialchars(!empty($a['flat']) ? substr($a['flat'], 0, 3) : '-') ?>–<?= htmlspecialchars(!empty($a['br']) ? substr($a['br'], 0, 3) : '-') ?></code></td>
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
                                $r = $a['ultimo_resultado'] ?? 'pending';
                                $rMeta = match ($r) {
                                    'downloaded'    => ['[+]', '#a3be8c', '#fff'],
                                    'skip'          => ['[=]', '#81a1c1', '#fff'],
                                    'pending'       => ['[?]', '#ebcb8b', '#2e3440'],
                                    'error-br'      => ['[Eb]', '#bf616a', '#fff'],
                                    'error-flat'    => ['[Ef]', '#bf616a', '#fff'],
                                    'error-tmp'     => ['[Et]', '#d08770', '#fff'],
                                    'error-blocked' => ['[EB]', '#d08770', '#fff'],
                                    default         => ['[?]', '#ebcb8b', '#2e3440'],
                                };
                            ?>
                            <td style="text-align:center;"><code style="background:<?= $rMeta[1] ?>;color:<?= $rMeta[2] ?>;font-size:0.8rem" title="<?= htmlspecialchars($r) ?>"><?= $rMeta[0] ?></code></td>
                            <td style="text-align:center;"><?= (int)($a['n_envios'] ?? 0) ?> / <?= (int)($a['n_exitos'] ?? 0) ?></td>
                            <td>
                                <svg class="btn-desasociar" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#c62828" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-sucursal="<?= htmlspecialchars($sucId) ?>" data-archivo="<?= (int)$a['id'] ?>" style="cursor:pointer" title="Desasociar"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M17 22v-2"/><path d="M9 15l6 -6"/><path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464"/><path d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463"/><path d="M20 17h2"/><path d="M2 7h2"/><path d="M7 2v2"/></svg>
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
        <form class="form-editar-ajax" method="POST" action="/dashboard/sucursales" style="display:flex;gap:0.75rem;align-items:end;flex-wrap:wrap;">
            <input type="hidden" name="action" value="editar">
            <input type="hidden" name="sucursal_id" value="<?= htmlspecialchars($sucId) ?>">
            <label style="flex:1;min-width:200px;">
                Nombre
                <input type="text" name="nombre_sucursal" value="<?= htmlspecialchars($suc['nombre_sucursal']) ?>" required>
            </label>
            <label style="white-space:nowrap;">
                <input type="checkbox" name="enabled" value="1" <?= (($suc['enabled'] ?? false) === true || ($suc['enabled'] ?? 'f') === 't') ? 'checked' : '' ?>>
                Activa
            </label>
            <button type="submit" class="secondary outline" style="padding:0.3rem 0.8rem;">Guardar</button>
        </form>
    </article>
    <?php
    $editarHtml = ob_get_clean();

    ob_start();
    ?>
    <article>
        <header><strong>Clave DBD</strong></header>
        <p style="font-size:1.2rem;font-weight:bold;letter-spacing:0.2em;">
            <?= $suc['clave_dbd'] ? htmlspecialchars($suc['clave_dbd']) : '<span style="color:#888;">— sin clave</span>' ?>
        </p>
        <p style="margin-top:0.5rem;">
            <button class="gen-dbd-suc secondary outline" data-sucursal="<?= htmlspecialchars($sucId) ?>">Generar clave DBD</button>
        </p>
        <p style="font-size:0.85rem;color:#888;">
            Habilita todos los archivos DSBLIND de esta sucursal (<?= $dsblindCount ?>) y genera una nueva clave de 6 caracteres.
        </p>
    </article>

    <?php if (!empty($dbdLogs)): ?>
    <article style="margin-top:1rem;">
        <header><strong>Descargas DBD</strong></header>
        <div class="table-container">
            <table class="compact-table" style="font-size:0.85rem;">
                <thead>
                    <tr>
                        <th>Archivo</th>
                        <th>Usuario</th>
                        <th>Fecha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dbdLogs as $log): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($log['file_name']) ?></code></td>
                            <td><?= htmlspecialchars($log['dbd_user'] ?? '-') ?></td>
                            <td style="white-space:nowrap;"><?= date('d/m H:i', strtotime($log['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
    <?php endif; ?>
    <?php
    $dbdHtml = ob_get_clean();

    echo json_encode([
        'ok' => true,
        'id' => $sucId,
        'nombre' => $suc['nombre_sucursal'],
        'clave_dbd' => $suc['clave_dbd'],
        'total' => count($asociados),
        'dsblind_count' => $dsblindCount,
        'archivos_html' => $archivosHtml,
        'editar_html' => $editarHtml,
        'dbd_html' => $dbdHtml,
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

function renderSucursalTab(data) {
    var container = document.getElementById('tab-content-container');
    var existing = document.getElementById('tab-suc-' + data.id);
    if (existing) {
        existing.querySelector('#suc-' + data.id + '-archivos').innerHTML = data.archivos_html;
        existing.querySelector('#suc-' + data.id + '-editar').innerHTML = data.editar_html;
        var dbdDiv = existing.querySelector('#suc-' + data.id + '-dbd');
        if (dbdDiv) dbdDiv.innerHTML = data.dbd_html;
        var mainLink = document.querySelector('#sucursales-tabs a[data-tab="suc-' + data.id + '"]');
        if (mainLink) {
            var shortName = data.id + ' — ' + data.nombre;
            mainLink.textContent = shortName;
        }
        var archLink = existing.querySelector('a[data-tab="suc-' + data.id + '-archivos"]');
        if (archLink) archLink.textContent = 'Archivos (' + data.total + ')';
        return;
    }
    var nav = document.querySelector('#sucursales-tabs ul');
    var li = document.createElement('li');
    li.innerHTML = '<a href="#" data-tab="suc-' + data.id + '" class="contrast">' + escapeHtml(data.id + ' — ' + data.nombre) + '</a>';
    nav.insertBefore(li, nav.lastElementChild);
    var div = document.createElement('div');
    div.id = 'tab-suc-' + data.id;
    div.className = 'suc-detail-tab tab-content';
    div.innerHTML =
        '<nav class="tabs suc-subtabs">' +
            '<ul>' +
                '<li><a href="#" data-tab="suc-' + data.id + '-archivos" class="contrast">Archivos (' + data.total + ')</a></li>' +
                '<li><a href="#" data-tab="suc-' + data.id + '-editar">Editar</a></li>' +
                '<li><a href="#" data-tab="suc-' + data.id + '-dbd">DBD</a></li>' +
            '</ul>' +
        '</nav>' +
        '<div id="suc-' + data.id + '-archivos" class="suc-subcontent">' + data.archivos_html + '</div>' +
        '<div id="suc-' + data.id + '-editar" class="suc-subcontent" style="display:none">' + data.editar_html + '</div>' +
        '<div id="suc-' + data.id + '-dbd" class="suc-subcontent" style="display:none">' + data.dbd_html + '</div>';
    container.appendChild(div);
}

function loadSucursalTab(sucId) {
    var existingTab = document.querySelector('#sucursales-tabs a[data-tab="suc-' + sucId + '"]');
    if (existingTab) {
        fetch('/dashboard/sucursales?action=detail&sucursal=' + encodeURIComponent(sucId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) { alert(data.error || 'Error al cargar'); return; }
                renderSucursalTab(data);
                activateTab('suc-' + data.id);
            })
            .catch(function () { alert('Error de red al actualizar'); });
        return;
    }
    fetch('/dashboard/sucursales?action=detail&sucursal=' + encodeURIComponent(sucId))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok) { alert(data.error || 'Error al cargar'); return; }
            renderSucursalTab(data);
            activateTab('suc-' + data.id);
        })
        .catch(function () { alert('Error de red al cargar sucursal'); });
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

    // === Desasociar (AJAX) ===
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-desasociar');
        if (!btn) return;
        var archivoId = btn.dataset.archivo;
        var sucursalId = btn.dataset.sucursal;
        var formData = new FormData();
        formData.append('action', 'desasociar');
        formData.append('sucursal_id', sucursalId);
        formData.append('archivo_id', archivoId);
        fetch('/dashboard/sucursales', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) { alert(data.error || 'Error'); return; }
                var tr = btn.closest('tr');
                if (tr) tr.parentNode.removeChild(tr);
                var subLink = document.querySelector('.suc-subtabs a[data-tab="suc-' + sucursalId + '-archivos"]');
                if (subLink) {
                    var m = subLink.textContent.match(/\((\d+)\)/);
                    if (m) subLink.textContent = 'Archivos (' + (parseInt(m[1]) - 1) + ')';
                }
                var oldMsg = document.querySelector('.ajax-msg-des');
                if (oldMsg) oldMsg.parentNode.removeChild(oldMsg);
                var msg = document.createElement('div');
                msg.className = 'ajax-msg-des flash flash-success';
                msg.textContent = 'Archivo #' + archivoId + ' desasociado.';
                msg.style.cssText = 'position:fixed;top:1rem;left:50%;transform:translateX(-50%);z-index:999;padding:0.5rem 1rem;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,0.2);';
                document.body.appendChild(msg);
                setTimeout(function() { msg.parentNode.removeChild(msg); }, 2000);
            })
            .catch(function () { alert('Error de red'); });
    });

    // === Editar sucursal (AJAX) ===
    document.addEventListener('submit', function (e) {
        var form = e.target.closest('.form-editar-ajax');
        if (!form) return;
        e.preventDefault();
        var formData = new FormData(form);
        fetch('/dashboard/sucursales', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var oldMsg = form.parentNode.querySelector('.ajax-msg');
                if (oldMsg) oldMsg.parentNode.removeChild(oldMsg);
                var msg = document.createElement('div');
                msg.className = 'ajax-msg';
                if (!data.ok) {
                    msg.className += ' flash flash-error';
                    msg.textContent = data.error || 'Error';
                } else {
                    msg.className += ' flash flash-success';
                    msg.textContent = 'Guardado.';
                    var sucId = form.querySelector('[name=sucursal_id]').value;
                    var mainLink = document.querySelector('#sucursales-tabs a[data-tab="suc-' + sucId + '"]');
                    if (mainLink) {
                        var parts = mainLink.textContent.split(' — ');
                        mainLink.textContent = parts[0] + ' — ' + data.nombre;
                    }
                    setTimeout(function() { msg.parentNode.removeChild(msg); }, 3000);
                }
                form.parentNode.insertBefore(msg, form);
            })
            .catch(function () { alert('Error de red'); });
    });

    // === Toggle sucursal enabled (search results) ===
    document.addEventListener('change', function (e) {
        var cb = e.target.closest('.toggle-sucursal-enabled');
        if (!cb) return;
        var formData = new FormData();
        formData.append('action', 'toggle-sucursal-enabled');
        formData.append('sucursal_id', cb.dataset.sucursal);
        formData.append('enabled', cb.checked ? '1' : '');
        fetch('/dashboard/sucursales', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) cb.checked = !cb.checked;
            })
            .catch(function () { cb.checked = !cb.checked; });
    });

    // === "Ver" sucursal → dynamic tab ===
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.ver-sucursal');
        if (!btn) return;
        e.preventDefault();
        loadSucursalTab(btn.dataset.sucursal);
    });

    // === Gen DBD Suc (habilitar todos DSBLIND + generar clave) ===
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.gen-dbd-suc');
        if (!btn) return;
        e.preventDefault();
        var sucId = btn.dataset.sucursal;
        btn.setAttribute('aria-busy', 'true');
        var formData = new FormData();
        formData.append('action', 'gen-dbd-suc');
        formData.append('sucursal_id', sucId);
        fetch('/dashboard/sucursales', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.removeAttribute('aria-busy');
                if (data.ok) {
                    var tabContent = document.querySelector('#suc-' + sucId + '-dbd');
                    if (tabContent) {
                        var claveHtml = '<span style="font-size:1.2rem;font-weight:bold;letter-spacing:0.2em;">' + escapeHtml(data.clave_dbd) + '</span>';
                        var claveP = tabContent.querySelector('p strong');
                        if (claveP) {
                            claveP.parentElement.innerHTML = claveHtml;
                        } else {
                            loadSucursalTab(sucId);
                        }
                    }
                } else {
                    alert('Error: ' + (data.error || 'desconocido'));
                }
            })
            .catch(function () { btn.removeAttribute('aria-busy'); alert('Error de red al generar clave DBD'); });
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
            html += '<td style="text-align:center;"><input type="checkbox" class="toggle-sucursal-enabled" data-sucursal="' + escapeHtml(s.id_sucursal) + '"' + (enabled ? ' checked' : '') + '></td>';
            html += '<td>' + s.total_asociados + '</td>';
            html += '<td style="white-space:nowrap"><a href="#" class="ver-sucursal secondary outline" role="button" data-sucursal="' + escapeHtml(s.id_sucursal) + '" style="padding:0.25rem 0.5rem">Ver</a></td>';
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

    // === Auto-load from URL ?sucursal=X ===
    var params = new URLSearchParams(window.location.search);
    var autoSuc = params.get('sucursal');
    if (autoSuc) {
        loadSucursalTab(autoSuc);
    }
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
