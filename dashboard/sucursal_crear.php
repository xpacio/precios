<?php

$pageTitle = 'Nueva Sucursal';

$pdo = getDB();
$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            header('Location: /dashboard/sucursales?sucursal=' . urlencode($nuevoId));
            exit;
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

require __DIR__ . '/header.php';
?>

<h1>Nueva Sucursal</h1>

<p><a href="/dashboard/sucursales" class="secondary">&larr; Volver</a></p>

<?php if ($error): ?>
    <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST" action="/dashboard/sucursal_crear" style="max-width:400px;">
    <label for="nuevo_id">ID (solo minúsculas y números)</label>
    <input type="text" name="nuevo_id" id="nuevo_id" pattern="[a-z0-9]+" required placeholder="ej. suc001" value="<?= htmlspecialchars($_POST['nuevo_id'] ?? '') ?>">
    <label for="nuevo_nombre">Nombre</label>
    <input type="text" name="nuevo_nombre" id="nuevo_nombre" required placeholder="ej. Sucursal Centro" value="<?= htmlspecialchars($_POST['nuevo_nombre'] ?? '') ?>">
    <button type="submit">Crear Sucursal</button>
</form>

<?php require __DIR__ . '/footer.php'; ?>
