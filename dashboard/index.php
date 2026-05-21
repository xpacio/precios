<?php

$pageTitle = 'Inicio';

$pdo = getDB();

$totalArchivos = $pdo->query("SELECT COUNT(*) FROM archivos")->fetchColumn();
$presentes = $pdo->query("SELECT COUNT(*) FROM archivos WHERE ausente = FALSE")->fetchColumn();
$ausentes = $pdo->query("SELECT COUNT(*) FROM archivos WHERE ausente = TRUE")->fetchColumn();
$cambiados = $pdo->query("SELECT COUNT(*) FROM archivos WHERE ultimo_cambio IS NOT NULL AND ultimo_cambio > NOW() - INTERVAL '1 hour'")->fetchColumn();
$totalSucursales = $pdo->query("SELECT COUNT(*) FROM sucursales WHERE enabled = TRUE")->fetchColumn();
$pendientes = $pdo->query("
    SELECT COUNT(*) FROM archivo_sucursal asu
    JOIN archivos a ON a.id = asu.archivo_id
    WHERE asu.enabled = TRUE AND asu.sync = FALSE AND a.ausente = FALSE
")->fetchColumn();
$asociaciones = $pdo->query("SELECT COUNT(*) FROM archivo_sucursal WHERE enabled = TRUE")->fetchColumn();

// Files that changed recently
$stmt = $pdo->query("
    SELECT a.path, a.nombre, a.ultimo_cambio
    FROM archivos a
    WHERE a.ultimo_cambio IS NOT NULL
    ORDER BY a.ultimo_cambio DESC
    LIMIT 10
");
$recientes = $stmt->fetchAll();

require __DIR__ . '/header.php';
?>

<h1>Panel de Control</h1>

<div class="stats-grid">
    <article class="stat-card">
        <header>Archivos en catálogo</header>
        <h3><?= number_format($totalArchivos) ?></h3>
    </article>
    <article class="stat-card">
        <header>Presentes</header>
        <h3 style="color: #2e7d32;"><?= number_format($presentes) ?></h3>
    </article>
    <article class="stat-card">
        <header>Ausentes</header>
        <h3 style="color: #c62828;"><?= number_format($ausentes) ?></h3>
    </article>
    <article class="stat-card">
        <header>Cambiados (1h)</header>
        <h3 style="color: #e65100;"><?= number_format($cambiados) ?></h3>
    </article>
    <article class="stat-card">
        <header>Sucursales Activas</header>
        <h3><?= number_format($totalSucursales) ?></h3>
    </article>
    <article class="stat-card">
        <header>Asociaciones</header>
        <h3><?= number_format($asociaciones) ?></h3>
    </article>
    <article class="stat-card">
        <header>Pendientes Sync</header>
        <h3 style="color: <?= $pendientes > 0 ? '#e65100' : '#2e7d32' ?>;"><?= number_format($pendientes) ?></h3>
    </article>
</div>

<section>
    <h2>Cambios Recientes</h2>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Archivo</th>
                    <th>Cambiado</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recientes)): ?>
                    <tr><td colspan="2">Sin cambios registrados.</td></tr>
                <?php else: ?>
                    <?php foreach ($recientes as $f): ?>
                        <tr>
                            <td><?= htmlspecialchars($f['path'] . '/' . $f['nombre']) ?></td>
                            <td><?= htmlspecialchars($f['ultimo_cambio']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/footer.php'; ?>
