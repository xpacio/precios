<?php

$pageTitle = 'Archivos';

$pdo = getDB();

$sucursalFiltro = $_GET['sucursal'] ?? '';

$sql = "
    SELECT a.id, a.nombre, a.peso, a.md5zip, a.md5flat, a.fecha_carga, a.is_desblinde, a.n_descargas,
           s.nombre_sucursal, s.id_sucursal
    FROM archivos a
    JOIN archivo_sucursal asu ON a.id = asu.archivo_id
    JOIN sucursales s ON asu.sucursal_id = s.id_sucursal
";
$params = [];
if ($sucursalFiltro !== '') {
    $sql .= " WHERE asu.sucursal_id = ?";
    $params[] = $sucursalFiltro;
}
$sql .= " ORDER BY a.fecha_carga DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$archivos = $stmt->fetchAll();

$sucursales = $pdo->query("SELECT id_sucursal, nombre_sucursal FROM sucursales ORDER BY id_sucursal")->fetchAll();

require __DIR__ . '/header.php';
?>

<h1>Archivos</h1>

<form method="GET" action="/dashboard/archivos">
    <label for="sucursal">Filtrar por sucursal</label>
    <select name="sucursal" id="sucursal" onchange="this.form.submit()">
        <option value="">Todas las sucursales</option>
        <?php foreach ($sucursales as $s): ?>
            <option value="<?= htmlspecialchars($s['id_sucursal']) ?>" <?= $sucursalFiltro === $s['id_sucursal'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['id_sucursal']) ?> - <?= htmlspecialchars($s['nombre_sucursal']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Sucursal</th>
                <th>Peso</th>
                <th>MD5</th>
                <th>Fecha</th>
                <th>Tipo</th>
                <th>Descargas</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($archivos)): ?>
                <tr><td colspan="7">No se encontraron archivos.</td></tr>
            <?php else: ?>
                <?php foreach ($archivos as $f): ?>
                    <tr>
                        <td><?= htmlspecialchars($f['nombre']) ?></td>
                        <td><?= htmlspecialchars($f['nombre_sucursal']) ?></td>
                        <td><?= number_format($f['peso']) ?> B</td>
                        <td><code><?= htmlspecialchars($f['md5zip']) ?></code></td>
                        <td><?= htmlspecialchars($f['fecha_carga']) ?></td>
                        <td><?= $f['is_desblinde'] === 't' ? 'Desblinde' : 'Normal' ?></td>
                        <td><?= number_format($f['n_descargas']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/footer.php'; ?>
