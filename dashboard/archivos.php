<?php

$pageTitle = 'Archivos';

$pdo = getDB();
$mensaje = '';
$error = '';

// === POST: relacionar archivos con sucursales ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'relacionar') {
    header('Content-Type: application/json');
    $archivoIds = $_POST['archivo_ids'] ?? [];
    $sucursalIds = $_POST['sucursal_ids'] ?? [];

    if (empty($archivoIds) || empty($sucursalIds)) {
        echo json_encode(['ok' => false, 'error' => 'Selecciona archivos y sucursales']);
        exit;
    }

    $inserted = 0;
    $errors = [];

    $nombreStmt = $pdo->prepare("SELECT nombre FROM archivos WHERE id = ?");
    $upsertStmt = $pdo->prepare("
        INSERT INTO archivo_sucursal (archivo_id, sucursal_id, nombre)
        VALUES (?, ?, ?)
        ON CONFLICT ON CONSTRAINT archivo_sucursal_sucursal_id_nombre_key
        DO UPDATE SET archivo_id = EXCLUDED.archivo_id, enabled = TRUE, sync = FALSE
    ");

    foreach ($archivoIds as $aid) {
        $nombreStmt->execute([(int)$aid]);
        $arch = $nombreStmt->fetch();
        if (!$arch) {
            $errors[] = "Archivo ID $aid no encontrado";
            continue;
        }
        $nombre = $arch['nombre'];

        foreach ($sucursalIds as $sid) {
            try {
                $upsertStmt->execute([(int)$aid, $sid, $nombre]);
                $inserted++;
            } catch (Exception $e) {
                $errors[] = "Error al relacionar archivo $aid con sucursal $sid: " . $e->getMessage();
            }
        }
    }

    echo json_encode(['ok' => true, 'inserted' => $inserted, 'errors' => $errors]);
    exit;
}

// === POST: eliminar archivo ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'eliminar') {
    header('Content-Type: application/json');
    $archivoId = (int)($_POST['archivo_id'] ?? 0);
    if ($archivoId) {
        try {
            $pdo->prepare("DELETE FROM archivos WHERE id = ?")->execute([$archivoId]);
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['ok' => false, 'error' => 'ID inválido']);
    }
    exit;
}

// === POST: registrar archivo ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'registrar') {
    $ruta = trim($_POST['ruta'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $is_desblinde = !empty($_POST['is_desblinde']);

    if ($ruta === '' || $nombre === '') {
        $error = 'Ruta y nombre son obligatorios.';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO archivos (ruta, nombre, is_desblinde) VALUES (?, ?, ?)");
            $stmt->execute([$ruta, $nombre, $is_desblinde ? 't' : 'f']);
            $mensaje = "Archivo '$nombre' registrado exitosamente.";
        } catch (Exception $e) {
            $error = 'Error al registrar archivo: ' . $e->getMessage();
        }
    }
    if (!$error) {
        header('Location: /dashboard/archivos?tab=registrar');
        exit;
    }
}

$showRegistrar = isset($_GET['tab']) && $_GET['tab'] === 'registrar';

// === POST: toggle enabled ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle-enabled') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $enabled = !empty($_POST['enabled']);
    if ($id) {
        try {
            $pdo->prepare("UPDATE archivos SET enabled = ? WHERE id = ?")->execute([$enabled ? 't' : 'f', $id]);
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['ok' => false, 'error' => 'ID inválido']);
    }
    exit;
}

// === POST: toggle desblinde ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle-desblinde') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $is_desblinde = !empty($_POST['is_desblinde']);
    if ($id) {
        try {
            $pdo->prepare("UPDATE archivos SET is_desblinde = ? WHERE id = ?")->execute([$is_desblinde ? 't' : 'f', $id]);
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['ok' => false, 'error' => 'ID inválido']);
    }
    exit;
}

// === POST: editar archivo ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'editar') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $ruta = trim($_POST['ruta'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    if ($id && $ruta !== '' && $nombre !== '') {
        try {
            $pdo->prepare("UPDATE archivos SET ruta = ?, nombre = ? WHERE id = ?")->execute([$ruta, $nombre, $id]);
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['ok' => false, 'error' => 'Datos inválidos']);
    }
    exit;
}

// === AJAX: search archivos (min 2 chars, for eliminar tab) ===
$type = $_GET['type'] ?? 'archivos';

if ($type === 'archivos-eliminar' && ($_GET['ajax'] ?? false)) {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) {
        header('Content-Type: application/json');
        echo json_encode(['results' => [], 'total' => 0]);
        exit;
    }
    $stmt = $pdo->prepare("
        SELECT a.id, a.ruta, a.nombre, a.peso, a.status, a.fecha_carga
        FROM archivos a
        WHERE a.ruta ILIKE ? OR a.nombre ILIKE ?
        ORDER BY a.ruta, a.nombre
        LIMIT 10
    ");
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like]);
    $rows = $stmt->fetchAll();
    header('Content-Type: application/json');
    echo json_encode(['results' => $rows, 'total' => count($rows)]);
    exit;
}

if ($type === 'sucursales' && ($_GET['ajax'] ?? false)) {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 3) {
        header('Content-Type: application/json');
        echo json_encode(['results' => [], 'total' => 0]);
        exit;
    }
    $stmt = $pdo->prepare("
        SELECT id_sucursal, nombre_sucursal
        FROM sucursales
        WHERE id_sucursal ILIKE ? OR nombre_sucursal ILIKE ?
        ORDER BY id_sucursal
        LIMIT 20
    ");
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like]);
    $rows = $stmt->fetchAll();
    header('Content-Type: application/json');
    echo json_encode(['results' => $rows, 'total' => count($rows)]);
    exit;
}

// === AJAX: list all archivos with pagination ===
if ($type === 'archivos-listar' && ($_GET['ajax'] ?? false)) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 25;
    $offset = ($page - 1) * $perPage;

    $total = (int)$pdo->query("SELECT COUNT(*) FROM archivos")->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT a.id, a.ruta, a.nombre, a.peso, a.status, a.enabled, a.is_desblinde, a.fecha_carga
        FROM archivos a
        ORDER BY a.ruta, a.nombre
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$perPage, $offset]);
    $rows = $stmt->fetchAll();

    header('Content-Type: application/json');
    echo json_encode(['results' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage]);
    exit;
}

// === AJAX: search archivos ===
$search = trim($_GET['q'] ?? '');
$results = [];

if (strlen($search) >= 3) {
    $sql = "SELECT a.id, a.ruta, a.nombre, a.peso, a.flat, a.br, a.xxh3, a.comprimido, a.status, a.fecha_carga
            FROM archivos a
            WHERE a.ruta ILIKE ? OR a.nombre ILIKE ?
            ORDER BY a.ruta, a.nombre
            LIMIT 50";
    $like = '%' . $search . '%';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$like, $like]);
    $results = $stmt->fetchAll();
}

if ($_GET['ajax'] ?? false) {
    header('Content-Type: application/json');
    echo json_encode(['results' => $results, 'total' => count($results)]);
    exit;
}

$totalArchivos = $pdo->query('SELECT COUNT(*) FROM archivos')->fetchColumn();
require __DIR__ . '/header.php';
?>

<h1>Archivos</h1>

<?php if ($mensaje): ?>
    <div class="flash flash-success"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<nav class="tabs">
    <ul>
        <li><a href="#" data-tab="asociar" class="contrast">Asociar</a></li>
        <li><a href="#" data-tab="eliminar">Eliminar archivo</a></li>
        <li><a href="#" data-tab="listar">Listar archivos</a></li>
        <li><a href="#" data-tab="registrar">Registrar archivo</a></li>
    </ul>
</nav>

<div id="tab-asociar" class="tab-content">
    <?php $sucursalFilter = trim($_GET['sucursal'] ?? ''); ?>
    <?php if ($sucursalFilter): ?>
        <p><a href="/dashboard/sucursales?sucursal=<?= urlencode($sucursalFilter) ?>" class="secondary">&larr; <?= htmlspecialchars($sucursalFilter) ?></a></p>
    <?php endif; ?>
    <p>Total: <?= $totalArchivos ?> archivos</p>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
        <div>
            <label for="q">Buscar archivo (nombre o ruta)</label>
            <input type="text" id="q" minlength="3" placeholder="Escribe al menos 3 caracteres..." autofocus>
            <div id="archivos-results" style="margin-top:0.5rem;">
                <p>Ingresa al menos 3 caracteres para buscar archivos.</p>
            </div>
        </div>
        <div>
            <label for="qs">Buscar sucursal</label>
            <input type="text" id="qs" minlength="3" placeholder="Escribe al menos 3 caracteres...">
            <div id="sucursales-results" style="margin-top:0.5rem;">
                <p>Ingresa al menos 3 caracteres para buscar sucursales.</p>
            </div>
        </div>
    </div>

    <div style="text-align:center;margin:1.5rem 0;">
        <button id="btn-relacionar" class="contrast" style="padding:0.6rem 2rem;font-size:1.1rem;">Relacionar seleccionados</button>
    </div>

    <div id="mensaje" style="display:none;margin-bottom:1rem;"></div>
</div>

<div id="tab-eliminar" class="tab-content" style="display:none;">
    <div style="display:flex;gap:0.75rem;align-items:center;">
        <div style="flex:1;">
            <label for="q-del">Buscar archivo para eliminar (nombre o ruta)</label>
            <input type="text" id="q-del" minlength="2" placeholder="Escribe al menos 2 caracteres...">
        </div>
        <label style="white-space:nowrap;margin-top:1.5rem;">
            <input type="checkbox" id="confirm-del">
            Confirmar eliminación
        </label>
    </div>

    <div id="del-archivos-results" style="margin-top:0.5rem;">
        <p>Ingresa al menos 2 caracteres para buscar archivos.</p>
    </div>

    <div id="mensaje-del" style="display:none;margin-bottom:1rem;"></div>
</div>

<div id="tab-listar" class="tab-content" style="display:none;">
    <div id="listar-info" style="margin-bottom:0.5rem;"></div>
    <div class="table-container" id="listar-table-container">
        <p>Cargando archivos...</p>
    </div>
    <div id="listar-pagination" style="display:flex;gap:0.5rem;justify-content:center;margin-top:1rem;"></div>
</div>

<div id="tab-registrar" class="tab-content" style="display:none;">
    <article>
        <header><strong>Registrar Nuevo Archivo</strong></header>
        <form method="POST" action="/dashboard/archivos">
            <input type="hidden" name="action" value="registrar">
            <div class="grid">
                <label>
                    Ruta
                    <input type="text" name="ruta" required placeholder="/srv/precios/...">
                </label>
                <label>
                    Nombre
                    <input type="text" name="nombre" required placeholder="archivo.pdf">
                </label>
                <label>
                    <input type="checkbox" name="is_desblinde" value="1">
                    Es desblinde
                </label>
            </div>
            <button type="submit">Registrar Archivo</button>
        </form>
    </article>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // === Tabs ===
    document.querySelectorAll('.tabs a[data-tab]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            document.querySelectorAll('.tabs a').forEach(function (a) { a.classList.remove('contrast'); });
            link.classList.add('contrast');
            document.querySelectorAll('.tab-content').forEach(function (d) { d.style.display = 'none'; });
            document.getElementById('tab-' + link.dataset.tab).style.display = '';
        });
    });

    // Auto-activate tab from URL param
    var tabParam = new URLSearchParams(window.location.search).get('tab');
    if (tabParam) {
        var tabLink = document.querySelector('.tabs a[data-tab="' + tabParam + '"]');
        if (tabLink) tabLink.click();
    }

    // === Asociar tab ===
    const input = document.getElementById('q');
    const inputSuc = document.getElementById('qs');
    const archivosResults = document.getElementById('archivos-results');
    const sucResults = document.getElementById('sucursales-results');
    const btnRelacionar = document.getElementById('btn-relacionar');
    const mensaje = document.getElementById('mensaje');
    const sucursalAuto = new URLSearchParams(window.location.search).get('sucursal');
    let timer, sucTimer;

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function mostrarMensaje(tipo, texto) {
        const el = document.getElementById('mensaje');
        el.style.display = 'block';
        el.className = 'flash flash-' + tipo;
        el.innerHTML = texto;
        setTimeout(function () { el.style.display = 'none'; }, 5000);
    }

    function renderSucursales(data) {
        if (data.total === 0) {
            sucResults.innerHTML = '<p>No se encontraron sucursales para "<strong>' + escapeHtml(inputSuc.value) + '</strong>".</p>';
            return;
        }
        let html = '<p>' + data.total + ' resultado(s) (máx. 20).</p>';
        html += '<div class="table-container"><table><thead><tr><th>Código</th><th>Nombre</th><th>Sel.</th></tr></thead><tbody>';
        for (const s of data.results) {
            const checked = sucursalAuto && s.id_sucursal === sucursalAuto ? ' checked' : '';
            html += '<tr>';
            html += '<td><code>' + escapeHtml(s.id_sucursal) + '</code></td>';
            html += '<td>' + escapeHtml(s.nombre_sucursal) + '</td>';
            html += '<td><input type="checkbox" class="suc-check" value="' + escapeHtml(s.id_sucursal) + '"' + checked + '></td>';
            html += '</tr>';
        }
        html += '</tbody></table></div>';
        sucResults.innerHTML = html;
    }

    function renderArchivos(data) {
        if (data.total === 0) {
            archivosResults.innerHTML = '<p>No se encontraron archivos para "<strong>' + escapeHtml(input.value) + '</strong>".</p>';
            return;
        }
        let html = '<p>' + data.total + ' resultado(s) (máx. 50).</p>';
        html += '<p style="margin-bottom:0.5rem;"><button class="secondary outline" id="btn-select-all" style="padding:0.25rem 0.75rem;font-size:0.85rem;" type="button">Seleccionar todos</button></p>';
        html += '<div class="table-container"><table><thead><tr><th>Ruta</th><th>Archivo</th><th>Sel.</th></tr></thead><tbody>';

        for (const f of data.results) {
            html += '<tr>';
            html += '<td style="font-size:0.85rem;color:#666;">' + escapeHtml(f.ruta.replace('/srv/precios/', '')) + '</td>';
            html += '<td>' + escapeHtml(f.nombre) + '</td>';
            html += '<td><input type="checkbox" class="arch-check" value="' + f.id + '"></td>';
            html += '</tr>';
        }
        html += '</tbody></table></div>';
        archivosResults.innerHTML = html;

        const btnSelectAll = document.getElementById('btn-select-all');
        if (btnSelectAll) {
            btnSelectAll.addEventListener('click', function () {
                const checkboxes = document.querySelectorAll('.arch-check');
                const allChecked = Array.from(checkboxes).every(function (cb) { return cb.checked; });
                checkboxes.forEach(function (cb) { cb.checked = !allChecked; });
                btnSelectAll.textContent = allChecked ? 'Seleccionar todos' : 'Deseleccionar todos';
            });
        }
    }

    function searchSucursales() {
        const q = inputSuc.value.trim();
        if (q.length < 3) {
            sucResults.innerHTML = '<p>Ingresa al menos 3 caracteres para buscar sucursales.</p>';
            return;
        }
        fetch('?type=sucursales&q=' + encodeURIComponent(q) + '&ajax=1')
            .then(function (r) { return r.json(); })
            .then(renderSucursales)
            .catch(function () { sucResults.innerHTML = '<p style="color:red">Error al buscar.</p>'; });
    }

    function searchArchivos() {
        const q = input.value.trim();
        if (q.length < 3) {
            archivosResults.innerHTML = '<p>Ingresa al menos 3 caracteres para buscar archivos.</p>';
            return;
        }
        fetch('?q=' + encodeURIComponent(q) + '&ajax=1')
            .then(function (r) { return r.json(); })
            .then(renderArchivos)
            .catch(function () { archivosResults.innerHTML = '<p style="color:red">Error al buscar.</p>'; });
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(searchArchivos, 300);
    });

    inputSuc.addEventListener('input', function () {
        clearTimeout(sucTimer);
        sucTimer = setTimeout(searchSucursales, 300);
    });

    if (sucursalAuto) {
        inputSuc.value = sucursalAuto;
        searchSucursales();
    }

    btnRelacionar.addEventListener('click', function () {
        const archivosSel = Array.from(document.querySelectorAll('.arch-check:checked')).map(function (cb) { return cb.value; });
        const sucursalesSel = Array.from(document.querySelectorAll('.suc-check:checked')).map(function (cb) { return cb.value; });

        if (archivosSel.length === 0 || sucursalesSel.length === 0) {
            mostrarMensaje('error', 'Selecciona al menos un archivo y una sucursal.');
            return;
        }

        btnRelacionar.disabled = true;
        btnRelacionar.textContent = 'Relacionando...';

        var formData = new FormData();
        formData.append('action', 'relacionar');
        for (const id of archivosSel) formData.append('archivo_ids[]', id);
        for (const id of sucursalesSel) formData.append('sucursal_ids[]', id);

        fetch('/dashboard/archivos', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                    if (data.ok) {
                        var msg = 'Relación guardada: ' + data.inserted + ' insertadas';
                        if (data.skipped > 0) msg += ', ' + data.skipped + ' ya existían';
                        if (data.errors && data.errors.length > 0) msg += '. Errores: ' + data.errors.join(', ');
                    mostrarMensaje('success', msg);
                } else {
                    mostrarMensaje('error', data.error || 'Error al relacionar');
                }
            })
            .catch(function () {
                mostrarMensaje('error', 'Error de conexión.');
            })
            .finally(function () {
                btnRelacionar.disabled = false;
                btnRelacionar.textContent = 'Relacionar seleccionados';
            });
    });

    // === Eliminar tab ===
    const inputDel = document.getElementById('q-del');
    const delResults = document.getElementById('del-archivos-results');
    const mensajeDel = document.getElementById('mensaje-del');
    let delTimer;

    function mostrarMensajeDel(tipo, texto) {
        mensajeDel.style.display = 'block';
        mensajeDel.className = 'flash flash-' + tipo;
        mensajeDel.innerHTML = texto;
        setTimeout(function () { mensajeDel.style.display = 'none'; }, 5000);
    }

    function renderDelArchivos(data) {
        if (data.total === 0) {
            delResults.innerHTML = '<p>No se encontraron archivos para "<strong>' + escapeHtml(inputDel.value) + '</strong>".</p>';
            return;
        }
        let html = '<p>' + data.total + ' resultado(s) (máx. 50).</p>';
        html += '<div class="table-container"><table><thead><tr><th>Ruta</th><th>Archivo</th><th>Peso</th><th>Status</th><th>Acción</th></tr></thead><tbody>';

        for (const f of data.results) {
            html += '<tr>';
            html += '<td style="font-size:0.85rem;color:#666;">' + escapeHtml(f.ruta.replace('/srv/precios/', '')) + '</td>';
            html += '<td>' + escapeHtml(f.nombre) + '</td>';
            html += '<td>' + (f.peso ? escapeHtml(f.peso) : '-') + '</td>';
            html += '<td>' + escapeHtml(f.status ?? '-') + '</td>';
            html += '<td><button class="secondary outline btn-eliminar" data-id="' + f.id + '" data-nombre="' + escapeHtml(f.nombre) + '" style="padding:0.25rem 0.5rem;font-size:0.85rem;">Eliminar</button></td>';
            html += '</tr>';
        }
        html += '</tbody></table></div>';
        delResults.innerHTML = html;

        const confirmCheck = document.getElementById('confirm-del');

        document.querySelectorAll('.btn-eliminar').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirmCheck.checked) {
                    mostrarMensajeDel('error', 'Marca "Confirmar eliminación" para habilitar la eliminación.');
                    return;
                }

                const id = this.dataset.id;
                this.disabled = true;
                this.textContent = 'Eliminando...';

                var formData = new FormData();
                formData.append('action', 'eliminar');
                formData.append('archivo_id', id);

                fetch('/dashboard/archivos', { method: 'POST', body: formData })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.ok) {
                            btn.closest('tr').remove();
                        } else {
                            mostrarMensajeDel('error', data.error || 'Error al eliminar');
                            btn.disabled = false;
                            btn.textContent = 'Eliminar';
                        }
                    })
                    .catch(function () {
                        mostrarMensajeDel('error', 'Error de conexión.');
                        btn.disabled = false;
                        btn.textContent = 'Eliminar';
                    });
            });
        });
    }

    function searchDelArchivos() {
        const q = inputDel.value.trim();
        if (q.length < 2) {
            delResults.innerHTML = '<p>Ingresa al menos 2 caracteres para buscar archivos.</p>';
            return;
        }
        fetch('?type=archivos-eliminar&q=' + encodeURIComponent(q) + '&ajax=1')
            .then(function (r) { return r.json(); })
            .then(renderDelArchivos)
            .catch(function () { delResults.innerHTML = '<p style="color:red">Error al buscar.</p>'; });
    }

    inputDel.addEventListener('input', function () {
        clearTimeout(delTimer);
        delTimer = setTimeout(searchDelArchivos, 300);
    });

    // === Listar tab ===
    let listarPage = 1;

    function loadListar(page) {
        listarPage = page;
        const container = document.getElementById('listar-table-container');
        const info = document.getElementById('listar-info');
        const pagination = document.getElementById('listar-pagination');

        container.innerHTML = '<p>Cargando...</p>';

        fetch('?type=archivos-listar&page=' + page + '&ajax=1')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var totalPages = Math.ceil(data.total / data.perPage);
                info.innerHTML = '<p>Total: <strong>' + data.total + '</strong> archivos (pág. ' + data.page + ' de ' + totalPages + ')</p>';

                if (data.total === 0) {
                    container.innerHTML = '<p>No hay archivos.</p>';
                    pagination.innerHTML = '';
                    return;
                }

                var html = '<table><thead><tr><th>Ruta</th><th>Archivo</th><th>Peso</th><th>Status</th><th>Desblinde</th><th>Activo</th><th>Fecha carga</th><th>Acción</th></tr></thead><tbody>';
                for (var i = 0; i < data.results.length; i++) {
                    var f = data.results[i];
                    html += '<tr>';
                    html += '<td style="font-size:0.85rem;color:#666;">' + escapeHtml(f.ruta.replace('/srv/precios/', '')) + '</td>';
                    html += '<td>' + escapeHtml(f.nombre) + '</td>';
                    html += '<td>' + (f.peso ? escapeHtml(f.peso) : '-') + '</td>';
                    html += '<td>' + (f.status === 'ausente' ? '<span style="color:#e65100;font-weight:bold;">Ausente</span>' : escapeHtml(f.status || '-')) + '</td>';
                    html += '<td><input type="checkbox" class="toggle-desblinde" data-id="' + f.id + '"' + (f.is_desblinde ? ' checked' : '') + '></td>';
                    html += '<td><input type="checkbox" class="toggle-enabled" data-id="' + f.id + '"' + (f.enabled ? ' checked' : '') + '></td>';
                    html += '<td>' + (f.fecha_carga ? escapeHtml(f.fecha_carga) : '-') + '</td>';
                    html += '<td><a href="/dashboard/archivo-editar?id=' + f.id + '" class="secondary outline" style="padding:0.2rem 0.5rem;font-size:0.8rem;text-decoration:none;">Editar</a></td>';
                    html += '</tr>';
                }
                html += '</tbody></table>';
                container.innerHTML = html;

                var pagHtml = '';
                if (data.page > 1) {
                    pagHtml += '<button class="secondary outline" data-page="' + (data.page - 1) + '" style="padding:0.25rem 0.75rem;">&laquo; Anterior</button>';
                }
                pagHtml += '<span style="padding:0.25rem 0.75rem;">Pág. ' + data.page + ' de ' + totalPages + '</span>';
                if (data.page < totalPages) {
                    pagHtml += '<button class="secondary outline" data-page="' + (data.page + 1) + '" style="padding:0.25rem 0.75rem;">Siguiente &raquo;</button>';
                }
                pagination.innerHTML = pagHtml;

                pagination.querySelectorAll('button').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        loadListar(parseInt(this.dataset.page));
                    });
                });

                container.querySelectorAll('.toggle-enabled').forEach(function (cb) {
                    cb.addEventListener('change', function () {
                        var id = this.dataset.id;
                        var enabled = this.checked;
                        var formData = new FormData();
                        formData.append('action', 'toggle-enabled');
                        formData.append('id', id);
                        formData.append('enabled', enabled ? '1' : '');
                        fetch('/dashboard/archivos', { method: 'POST', body: formData })
                            .then(function (r) { return r.json(); })
                            .then(function (data) {
                                if (!data.ok) {
                                    cb.checked = !enabled;
                                }
                            })
                            .catch(function () {
                                cb.checked = !enabled;
                            });
                    });
                });

                container.querySelectorAll('.toggle-desblinde').forEach(function (cb) {
                    cb.addEventListener('change', function () {
                        var id = this.dataset.id;
                        var checked = this.checked;
                        var formData = new FormData();
                        formData.append('action', 'toggle-desblinde');
                        formData.append('id', id);
                        formData.append('is_desblinde', checked ? '1' : '');
                        fetch('/dashboard/archivos', { method: 'POST', body: formData })
                            .then(function (r) { return r.json(); })
                            .then(function (data) {
                                if (!data.ok) {
                                    cb.checked = !checked;
                                }
                            })
                            .catch(function () {
                                cb.checked = !checked;
                            });
                    });
                });

            })
            .catch(function () {
                container.innerHTML = '<p style="color:red">Error al cargar archivos.</p>';
            });
    }

    document.querySelector('.tabs a[data-tab="listar"]').addEventListener('click', function () {
        loadListar(1);
    });

});
</script>

<?php require __DIR__ . '/footer.php'; ?>
