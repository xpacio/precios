<?php

$pageTitle = 'Archivos';

$pdo = getDB();

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
    $skipped = 0;
    $errors = [];

    $checkStmt = $pdo->prepare("
        SELECT 1 FROM archivo_sucursal
        WHERE archivo_id = ? AND sucursal_id = ? AND enabled = TRUE
    ");
    $nombreStmt = $pdo->prepare("SELECT nombre FROM archivos WHERE id = ?");
    $insertStmt = $pdo->prepare("
        INSERT INTO archivo_sucursal (archivo_id, sucursal_id, nombre)
        VALUES (?, ?, ?)
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
            $checkStmt->execute([(int)$aid, $sid]);
            if ($checkStmt->fetch()) {
                $skipped++;
                continue;
            }
            try {
                $insertStmt->execute([(int)$aid, $sid, $nombre]);
                $inserted++;
            } catch (Exception $e) {
                $errors[] = "Error al relacionar archivo $aid con sucursal $sid: " . $e->getMessage();
            }
        }
    }

    echo json_encode(['ok' => true, 'inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors]);
    exit;
}

// === AJAX: search sucursales ===
$type = $_GET['type'] ?? 'archivos';

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

<h1>Relacionar Archivos y Sucursales</h1>

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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('q');
    const inputSuc = document.getElementById('qs');
    const archivosResults = document.getElementById('archivos-results');
    const sucResults = document.getElementById('sucursales-results');
    const btnRelacionar = document.getElementById('btn-relacionar');
    const mensaje = document.getElementById('mensaje');
    let timer, sucTimer;

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function mostrarMensaje(tipo, texto) {
        mensaje.style.display = 'block';
        mensaje.className = 'flash flash-' + tipo;
        mensaje.innerHTML = texto;
        setTimeout(function () { mensaje.style.display = 'none'; }, 5000);
    }

    function renderSucursales(data) {
        if (data.total === 0) {
            sucResults.innerHTML = '<p>No se encontraron sucursales para "<strong>' + escapeHtml(inputSuc.value) + '</strong>".</p>';
            return;
        }
        let html = '<p>' + data.total + ' resultado(s) (máx. 20).</p>';
        html += '<div class="table-container"><table><thead><tr><th>Código</th><th>Nombre</th><th>Sel.</th></tr></thead><tbody>';
        for (const s of data.results) {
            html += '<tr>';
            html += '<td><code>' + escapeHtml(s.id_sucursal) + '</code></td>';
            html += '<td>' + escapeHtml(s.nombre_sucursal) + '</td>';
            html += '<td><input type="checkbox" class="suc-check" value="' + escapeHtml(s.id_sucursal) + '"></td>';
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
                    if (data.errors.length > 0) msg += '. Errores: ' + data.errors.join(', ');
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
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
