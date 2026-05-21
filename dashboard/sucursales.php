<?php

$pageTitle = 'Sucursales';

$pdo = getDB();

$stmt = $pdo->query("
    SELECT s.id_sucursal, s.nombre_sucursal, s.enabled, s.sync,
           COUNT(DISTINCT asu2.archivo_id) AS total_archivos
    FROM sucursales s
    LEFT JOIN archivo_sucursal asu2 ON s.id_sucursal = asu2.sucursal_id AND asu2.enabled = TRUE
    GROUP BY s.id_sucursal, s.nombre_sucursal, s.enabled, s.sync
    ORDER BY s.id_sucursal
");
$sucursales = $stmt->fetchAll();

require __DIR__ . '/header.php';
?>

<h1>Sucursales</h1>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Estado</th>
                <th>Sincronización</th>
                <th>Archivos</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sucursales as $s): ?>
                <tr>
                    <td><code><?= htmlspecialchars($s['id_sucursal']) ?></code></td>
                    <td><?= htmlspecialchars($s['nombre_sucursal']) ?></td>
                    <td>
                        <?php if ($s['enabled'] === 't' || $s['enabled'] === true): ?>
                            <span style="color: green;">Activa</span>
                        <?php else: ?>
                            <span style="color: red;">Inactiva</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($s['sync'] === 't' || $s['sync'] === true): ?>
                            <span style="color: green;">Sincronizando</span>
                        <?php else: ?>
                            <span>No</span>
                        <?php endif; ?>
                    </td>
                    <td><?= number_format($s['total_archivos']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/footer.php'; ?>
