<?php

$pageTitle = 'Archivos';

$pdo = getDB();
require_once __DIR__ . '/../lib/sync_helper.php';
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

    $archivoStmt = $pdo->prepare("SELECT nombre, ruta FROM archivos WHERE id = ?");
    $upsertStmt = $pdo->prepare("
        INSERT INTO archivo_sucursal (archivo_id, sucursal_id, nombre, es_desblinde)
        VALUES (?, ?, ?, ?)
        ON CONFLICT (sucursal_id, nombre, es_desblinde)
        DO UPDATE SET archivo_id = EXCLUDED.archivo_id, enabled = TRUE, sync = FALSE
    ");

    foreach ($archivoIds as $aid) {
        $archivoStmt->execute([(int)$aid]);
        $arch = $archivoStmt->fetch();
        if (!$arch) {
            $errors[] = "Archivo ID $aid no encontrado";
            continue;
        }
        $nombre = $arch['nombre'];
        $esDesblinde = strpos($arch['ruta'], 'DSBLIND') !== false;

        foreach ($sucursalIds as $sid) {
            try {
                $upsertStmt->execute([(int)$aid, $sid, $nombre, $esDesblinde ? 't' : 'f']);
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
    $ruta = strtoupper(trim($_POST['ruta'] ?? ''));
    $nombre = strtoupper(trim($_POST['nombre'] ?? ''));
    $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

    $resp = ['status' => 'OK', 'mensaje' => '', 'log' => []];

    if ($ruta === '' || $nombre === '') {
        $resp['status'] = 'ERROR';
        $resp['mensaje'] = 'Ruta y nombre son obligatorios.';
    } else {
        $relPath = $ruta . '/' . $nombre;
        $getOneScript = realpath(__DIR__ . '/../scripts/getOne.sh');
        $cmd = "sudo " . escapeshellarg($getOneScript) . " " . escapeshellarg($relPath) . " 2>&1";
        exec($cmd, $syncOutput, $syncCode);
        $resp['log'] = $syncOutput;

        if ($syncCode !== 0) {
            $resp['status'] = 'ERROR';
            $resp['mensaje'] = "Archivo no encontrado en el origen remoto: $relPath";
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO archivos (ruta, nombre, enabled)
                    VALUES (?, ?, TRUE)
                    ON CONFLICT (ruta, nombre) DO UPDATE
                    SET enabled = TRUE, status = 'updating'
                ");
                $stmt->execute([$ruta, $nombre]);
                $result = processAndCompressFile($ruta, $nombre);
                if ($result['status'] === 'OK') {
                    $resp['mensaje'] = "Archivo '$nombre' registrado, sincronizado y comprimido exitosamente.";
                } else {
                    $resp['mensaje'] = "Archivo '$nombre' registrado (sin cambios en contenido).";
                }
                $resp['row_count'] = $stmt->rowCount();
            } catch (Exception $e) {
                $resp['status'] = 'ERROR';
                $resp['mensaje'] = 'Error al registrar archivo: ' . $e->getMessage();
            }
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode($resp);
        exit;
    }

    if ($resp['status'] === 'OK') {
        $mensaje = $resp['mensaje'];
        header('Location: /dashboard/archivos?tab=registrar');
        exit;
    }
    $error = $resp['mensaje'];
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
        SELECT a.id, a.ruta, a.nombre, a.peso, a.status, a.fecha_archivo
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
    $sort = match($_GET['sort'] ?? 'ruta') { 'fecha_archivo' => 'fecha_archivo', default => 'ruta' };
    $q = trim($_GET['q'] ?? '');

    if ($q !== '' && strlen($q) >= 2) {
        $like = '%' . $q . '%';
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM archivos WHERE ruta ILIKE ? OR nombre ILIKE ?");
        $countStmt->execute([$like, $like]);
        $total = (int)$countStmt->fetchColumn();
    } else {
        $q = '';
        $total = (int)$pdo->query("SELECT COUNT(*) FROM archivos")->fetchColumn();
    }

    $orderBy = ($sort === 'fecha_archivo') ? 'a.fecha_archivo DESC NULLS LAST' : 'a.ruta, a.nombre';

    $whereClause = $q ? "WHERE a.ruta ILIKE ? OR a.nombre ILIKE ?" : "";
    $sql = "
        SELECT a.id, a.ruta, a.nombre, a.peso, a.n_descargas, a.status, a.enabled, a.fecha_carga,
               a.flat, a.br, a.compr_pct, a.fecha_archivo,
               (SELECT COUNT(*) FROM archivo_sucursal WHERE archivo_id = a.id AND enabled = TRUE) AS total_suc,
               (SELECT COUNT(*) FROM archivo_sucursal WHERE archivo_id = a.id AND enabled = TRUE AND sync = TRUE) AS sync_suc
        FROM archivos a
        {$whereClause}
        ORDER BY {$orderBy}
        LIMIT ? OFFSET ?
    ";

    if ($q) {
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$like, $like, $perPage, $offset]);
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$perPage, $offset]);
    }
    $rows = $stmt->fetchAll();

    header('Content-Type: application/json');
    echo json_encode(['results' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage, 'sort' => $sort]);
    exit;
}

// === AJAX: search archivos ===
$search = trim($_GET['q'] ?? '');
$searchId = (int)($_GET['id'] ?? 0);
$results = [];

if ($searchId) {
    $stmt = $pdo->prepare("SELECT a.id, a.ruta, a.nombre, a.peso, a.flat, a.br, a.xxh3, a.comprimido, a.status, a.fecha_archivo FROM archivos a WHERE a.id = ?");
    $stmt->execute([$searchId]);
    $results = $stmt->fetchAll();
} elseif (strlen($search) >= 3) {
    $sql = "SELECT a.id, a.ruta, a.nombre, a.peso, a.flat, a.br, a.xxh3, a.comprimido, a.status, a.fecha_archivo
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
        <li><a href="#" data-tab="listar" class="contrast">Listar archivos</a></li>
        <li><a href="#" data-tab="asociar">Asociar</a></li>
        <li><a href="#" data-tab="eliminar">Eliminar archivo</a></li>
        <li><a href="#" data-tab="registrar">Registrar archivo</a></li>
    </ul>
</nav>

<?php require __DIR__ . '/archivos-tab-listar.php'; ?>
<?php require __DIR__ . '/archivos-tab-asociar.php'; ?>
<?php require __DIR__ . '/archivos-tab-eliminar.php'; ?>
<?php require __DIR__ . '/archivos-tab-registrar.php'; ?>

<script>
function escapeHtml(str) {
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}
function fmtFecha(ts) {
    if (!ts) return '-';
    var parts = ts.split(' ');
    if (parts.length !== 2) return ts;
    var d = parts[0].split('-');
    var t = parts[1].split(':');
    if (d.length < 3 || t.length < 2) return ts;
    return d[0].slice(-1) + d[1] + d[2] + '.' + t[0] + t[1];
}
function timeago(ts) {
    if (!ts) return '-';
    var diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
    if (diff < 0) return '0s';
    var s = diff, m = Math.floor(s / 60), h = Math.floor(s / 3600), d = Math.floor(s / 86400), M = Math.floor(d / 30), a = Math.floor(d / 365);
    if (s < 60) return s + 's';
    if (m < 60) return m + 'm';
    if (h < 2) return h + 'h' + (m % 60 ? (m % 60) + 'm' : '');
    if (h < 24) return h + 'h+';
    if (d === 1) return '1d'; if (d === 2) return '2d+';
    if (d < 30) return d + 'd+'; if (M === 1) return '1M';
    if (M < 12) return M + 'M+'; if (a === 1) return '1a';
    return a + 'a+';
}
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.tabs a[data-tab]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            document.querySelectorAll('.tabs a').forEach(function (a) { a.classList.remove('contrast'); });
            link.classList.add('contrast');
            document.querySelectorAll('.tab-content').forEach(function (d) { d.style.display = 'none'; });
            document.getElementById('tab-' + link.dataset.tab).style.display = '';
        });
    });

    var tabParam = new URLSearchParams(window.location.search).get('tab');
    var defaultTabName = tabParam || 'listar';
    var defaultTab = document.querySelector('.tabs a[data-tab="' + defaultTabName + '"]');
    if (defaultTab) defaultTab.click();
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
