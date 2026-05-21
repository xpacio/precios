<?php

$pageTitle = 'Sincronización';

$pdo = getDB();

$mensaje = '';
$error = '';

// Stats
$totalFiles = $pdo->query("SELECT COUNT(*) FROM archivos")->fetchColumn();
$presentes = $pdo->query("SELECT COUNT(*) FROM archivos WHERE ausente = FALSE")->fetchColumn();
$ausentes = $pdo->query("SELECT COUNT(*) FROM archivos WHERE ausente = TRUE")->fetchColumn();
$cambiados = $pdo->query("SELECT COUNT(*) FROM archivos WHERE ultimo_cambio IS NOT NULL AND ultimo_cambio > NOW() - INTERVAL '1 hour'")->fetchColumn();

$totalAssoc = $pdo->query("SELECT COUNT(*) FROM archivo_sucursal WHERE enabled = TRUE")->fetchColumn();
$pendientes = $pdo->query("
    SELECT COUNT(*) FROM archivo_sucursal asu
    JOIN archivos a ON a.id = asu.archivo_id
    WHERE asu.enabled = TRUE AND asu.sync = FALSE AND a.ausente = FALSE
")->fetchColumn();

require __DIR__ . '/header.php';
?>

<h1>Sincronización</h1>

<div class="stats-grid">
    <article class="stat-card">
        <header>Archivos en catálogo</header>
        <h3><?= number_format($totalFiles) ?></h3>
    </article>
    <article class="stat-card">
        <header>Presentes en disco</header>
        <h3><?= number_format($presentes) ?></h3>
    </article>
    <article class="stat-card">
        <header>Ausentes</header>
        <h3 style="color: #c62828;"><?= number_format($ausentes) ?></h3>
    </article>
    <article class="stat-card">
        <header>Cambiados (última hora)</header>
        <h3 style="color: #e65100;"><?= number_format($cambiados) ?></h3>
    </article>
    <article class="stat-card">
        <header>Asociaciones</header>
        <h3><?= number_format($totalAssoc) ?></h3>
    </article>
    <article class="stat-card">
        <header>Pendientes de sync</header>
        <h3 style="color: <?= $pendientes > 0 ? '#e65100' : '#2e7d32' ?>;"><?= number_format($pendientes) ?></h3>
    </article>
</div>

<div style="display: flex; gap: 1rem; flex-wrap: wrap;">
    <form method="POST" action="/api/v1/sync" target="_blank" style="display:inline">
        <input type="hidden" name="X-API-Key" value="precios_api_key_2024">
        <button type="submit">🔄 Sincronizar Todo</button>
    </form>

    <form method="POST" action="/api/v1/verify" target="_blank" style="display:inline">
        <input type="hidden" name="X-API-Key" value="precios_api_key_2024">
        <button type="submit" class="secondary">🔍 Verificar MD5</button>
    </form>
</div>

<hr>

<h2>Sucursales con Pendientes</h2>
<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>Sucursal</th>
                <th>Nombre</th>
                <th>Archivos pendientes</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $stmt = $pdo->query("
                SELECT s.id_sucursal, s.nombre_sucursal,
                       COUNT(*) AS num_pendientes
                FROM archivo_sucursal asu
                JOIN archivos a ON a.id = asu.archivo_id
                JOIN sucursales s ON s.id_sucursal = asu.sucursal_id
                WHERE asu.enabled = TRUE AND asu.sync = FALSE AND a.ausente = FALSE
                GROUP BY s.id_sucursal, s.nombre_sucursal
                ORDER BY num_pendientes DESC
                LIMIT 20
            ");
            $rows = $stmt->fetchAll();

            if (empty($rows)): ?>
                <tr><td colspan="4">No hay pendientes</td></tr>
            <?php else:
                foreach ($rows as $r): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($r['id_sucursal']) ?></code></td>
                        <td><?= htmlspecialchars($r['nombre_sucursal']) ?></td>
                        <td><span class="badge-warn"><?= $r['num_pendientes'] ?></span></td>
                        <td>
                            <a href="/dashboard/sucursales?sucursal=<?= urlencode($r['id_sucursal']) ?>" role="button" class="secondary outline" style="padding:0.25rem 0.5rem;font-size:0.8rem">Ver</a>
                        </td>
                    </tr>
                <?php endforeach;
            endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/footer.php'; ?>
