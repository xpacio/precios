<?php

$pageTitle = 'Usuarios';

$pdo = getDB();
$mensaje = '';
$error = '';

// Crear usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'crear') {
        $nombre = trim($_POST['nombre'] ?? '');
        $nickname = trim($_POST['nickname'] ?? '');
        $password = $_POST['password'] ?? '';
        $clavecorta = trim($_POST['clavecorta'] ?? '');

        if (empty($nombre) || empty($nickname) || empty($password)) {
            $error = 'Nombre, nickname y contraseña son obligatorios.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            try {
                $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, nickname, password, clavecorta) VALUES (?, ?, ?, ?)");
                $stmt->execute([$nombre, $nickname, $hash, $clavecorta]);
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

$usuarios = $pdo->query("SELECT id, nombre, nickname, enabled, can_upload, can_download, created_at FROM usuarios ORDER BY id")->fetchAll();

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
                <th>Estado</th>
                <th>Subir</th>
                <th>Descargar</th>
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
                    <td>
                        <?php if ($u['enabled'] === 't' || $u['enabled'] === true): ?>
                            <span style="color: green;">Activo</span>
                        <?php else: ?>
                            <span style="color: red;">Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td><?= ($u['can_upload'] === 't' || $u['can_upload'] === true) ? 'Si' : 'No' ?></td>
                    <td><?= ($u['can_download'] === 't' || $u['can_download'] === true) ? 'Si' : 'No' ?></td>
                    <td><?= htmlspecialchars($u['created_at']) ?></td>
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
