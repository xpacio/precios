<?php

$pageTitle = 'Inicio';

$pdo = getDB();

$totalArchivos = $pdo->query("SELECT COUNT(*) FROM archivos")->fetchColumn();
$totalDescargas = $pdo->query("SELECT COALESCE(SUM(n_descargas), 0) FROM archivos")->fetchColumn();
$totalSucursales = $pdo->query("SELECT COUNT(*) FROM sucursales WHERE enabled = TRUE")->fetchColumn();
$totalUsuarios = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE enabled = TRUE")->fetchColumn();

$stmt = $pdo->query("
    SELECT a.id, a.nombre, a.peso, a.fecha_carga, a.n_descargas, s.nombre_sucursal
    FROM archivos a
    JOIN archivo_sucursal asu ON a.id = asu.archivo_id
    JOIN sucursales s ON asu.sucursal_id = s.id_sucursal
    ORDER BY a.fecha_carga DESC
    LIMIT 10
");
$recientes = $stmt->fetchAll();

require __DIR__ . '/header.php';
?>

<h1>Panel de Control</h1>

<div class="stats-grid">
    <article class="stat-card">
        <header>Archivos</header>
        <h3><?= number_format($totalArchivos) ?></h3>
    </article>
    <article class="stat-card">
        <header>Descargas</header>
        <h3><?= number_format($totalDescargas) ?></h3>
    </article>
    <article class="stat-card">
        <header>Sucursales Activas</header>
        <h3><?= number_format($totalSucursales) ?></h3>
    </article>
    <article class="stat-card">
        <header>Usuarios Activos</header>
        <h3><?= number_format($totalUsuarios) ?></h3>
    </article>
</div>

<section>
    <h2>Archivos Recientes</h2>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Sucursal</th>
                    <th>Peso</th>
                    <th>Subido</th>
                    <th>Descargas</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recientes)): ?>
                    <tr><td colspan="5">No hay archivos cargados.</td></tr>
                <?php else: ?>
                    <?php foreach ($recientes as $f): ?>
                        <tr>
                            <td><?= htmlspecialchars($f['nombre']) ?></td>
                            <td><?= htmlspecialchars($f['nombre_sucursal']) ?></td>
                            <td><?= number_format($f['peso']) ?> B</td>
                            <td><?= htmlspecialchars($f['fecha_carga']) ?></td>
                            <td><?= number_format($f['n_descargas']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/footer.php'; ?>
