<?php

$pageTitle = 'Editar Archivo';

$pdo = getDB();
$mensaje = '';
$error = '';

$id = (int)($segments[2] ?? $_GET['id'] ?? 0);

if (!$id) {
    header('Location: /dashboard/archivos');
    exit;
}

$archivo = $pdo->prepare("SELECT id, ruta, nombre FROM archivos WHERE id = ?");
$archivo->execute([$id]);
$arch = $archivo->fetch();

if (!$arch) {
    header('Location: /dashboard/archivos');
    exit;
}

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
    <header><strong>Editar Archivo #<?= $arch['id'] ?></strong></header>
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
        <button type="submit">Guardar Cambios</button>
    </form>
</article>

<?php require __DIR__ . '/footer.php'; ?>
