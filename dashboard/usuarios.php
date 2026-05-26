<?php

$pageTitle = 'Usuarios';

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

// Editar usuario
$editId = (int)($_GET['edit'] ?? 0);
$editData = null;
if ($editId) {
    $stmt = $pdo->prepare("SELECT id, nombre, nickname, email, clavecorta, enabled, can_upload, can_download, err_notif FROM usuarios WHERE id = ?");
    $stmt->execute([$editId]);
    $editData = $stmt->fetch();
}

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'editar' && $editId) {
        $nombre = trim($_POST['nombre'] ?? '');
        $nickname = trim($_POST['nickname'] ?? '');
        $password = $_POST['password'] ?? '';
        $clavecorta = trim($_POST['clavecorta'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $err_notif = !empty($_POST['err_notif']);
        $can_upload = !empty($_POST['can_upload']);
        $can_download = !empty($_POST['can_download']);
        $enabled = !empty($_POST['enabled']);

        if (empty($nombre) || empty($nickname)) {
            $error = 'Nombre y nickname son obligatorios.';
        } else {
            try {
                if ($password) {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("UPDATE usuarios SET nombre=?, nickname=?, password=?, clavecorta=?, email=?, err_notif=?, can_upload=?, can_download=?, enabled=? WHERE id=?");
                    $stmt->execute([$nombre, $nickname, $hash, $clavecorta, $email, $err_notif ? 't' : 'f', $can_upload ? 't' : 'f', $can_download ? 't' : 'f', $enabled ? 't' : 'f', $editId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE usuarios SET nombre=?, nickname=?, clavecorta=?, email=?, err_notif=?, can_upload=?, can_download=?, enabled=? WHERE id=?");
                    $stmt->execute([$nombre, $nickname, $clavecorta, $email, $err_notif ? 't' : 'f', $can_upload ? 't' : 'f', $can_download ? 't' : 'f', $enabled ? 't' : 'f', $editId]);
                }
                $mensaje = "Usuario '$nickname' actualizado.";
                $editData['nombre'] = $nombre;
                $editData['nickname'] = $nickname;
                $editData['email'] = $email;
                $editData['clavecorta'] = $clavecorta;
                $editData['err_notif'] = $err_notif ? 't' : 'f';
                $editData['can_upload'] = $can_upload ? 't' : 'f';
                $editData['can_download'] = $can_download ? 't' : 'f';
                $editData['enabled'] = $enabled ? 't' : 'f';
            } catch (Exception $e) {
                $error = 'Error al actualizar: ' . $e->getMessage();
            }
        }
    }

    if ($_POST['action'] === 'crear') {
        $nombre = trim($_POST['nombre'] ?? '');
        $nickname = trim($_POST['nickname'] ?? '');
        $password = $_POST['password'] ?? '';
        $clavecorta = trim($_POST['clavecorta'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $err_notif = !empty($_POST['err_notif']);

        if (empty($nombre) || empty($nickname) || empty($password)) {
            $error = 'Nombre, nickname y contraseña son obligatorios.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            try {
                $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, nickname, password, clavecorta, email, err_notif) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nombre, $nickname, $hash, $clavecorta, $email, $err_notif ? 't' : 'f']);
                $mensaje = "Usuario '$nickname' creado exitosamente.";
            } catch (Exception $e) {
                $error = 'Error al crear usuario: ' . $e->getMessage();
            }
        }
    }
}

// Alternar estado enabled
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $userId = (int)$_GET['toggle'];
    $stmt = $pdo->prepare("UPDATE usuarios SET enabled = NOT enabled WHERE id = ?");
    $stmt->execute([$userId]);
    $mensaje = 'Estado del usuario actualizado.';
    header('Location: /dashboard/usuarios');
    exit;
}

$usuarios = $pdo->query("SELECT id, nombre, nickname, email, enabled, can_upload, can_download, err_notif, created_at FROM usuarios ORDER BY id")->fetchAll();

require __DIR__ . '/header.php';
?>

<h1>Usuarios</h1>

<?php if ($mensaje): ?>
    <div class="flash flash-success"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<article>
    <header><strong>Crear Nuevo Usuario</strong></header>
    <form method="POST" action="/dashboard/usuarios">
        <input type="hidden" name="action" value="crear">
        <div class="grid">
            <label>
                Nombre completo
                <input type="text" name="nombre" required>
            </label>
            <label>
                Nickname
                <input type="text" name="nickname" required>
            </label>
            <label>
                Contraseña
                <input type="password" name="password" required>
            </label>
            <label>
                Clave corta
                <input type="text" name="clavecorta" placeholder="Opcional">
            </label>
            <label>
                Email
                <input type="email" name="email" placeholder="Opcional">
            </label>
            <label>
                <input type="checkbox" name="err_notif" value="1">
                Notificar errores
            </label>
        </div>
        <button type="submit">Crear Usuario</button>
    </form>
</article>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Nickname</th>
                <th>Email</th>
                <th>Estado</th>
                <th>Subir</th>
                <th>Descargar</th>
                <th>Err.Notif</th>
                <th>Creado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><?= $u['id'] ?></td>
                    <td><?= htmlspecialchars($u['nombre']) ?></td>
                    <td><?= htmlspecialchars($u['nickname']) ?></td>
                    <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                    <td>
                        <?php if ($u['enabled'] === 't' || $u['enabled'] === true): ?>
                            <span style="color: green;">Activo</span>
                        <?php else: ?>
                            <span style="color: red;">Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td><?= ($u['can_upload'] === 't' || $u['can_upload'] === true) ? 'Si' : 'No' ?></td>
                    <td><?= ($u['can_download'] === 't' || $u['can_download'] === true) ? 'Si' : 'No' ?></td>
                    <td><?= ($u['err_notif'] === 't' || $u['err_notif'] === true) ? 'Si' : 'No' ?></td>
                    <td><?= fmtFecha($u['created_at']) ?> (<?= timeago($u['created_at']) ?>)</td>
                    <td class="actions">
                        <a href="/dashboard/usuarios?toggle=<?= $u['id'] ?>" role="button" class="secondary outline" style="padding: 0.25rem 0.5rem;">
                            <?= ($u['enabled'] === 't' || $u['enabled'] === true) ? 'Deshabilitar' : 'Habilitar' ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/footer.php'; ?>
