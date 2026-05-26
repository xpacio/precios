<?php

$pageTitle = 'Inicio';

$pdo = getDB();

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

$totalArchivos = $pdo->query("SELECT COUNT(*) FROM archivos")->fetchColumn();
$totalSucursales = $pdo->query("SELECT COUNT(*) FROM sucursales WHERE enabled = TRUE")->fetchColumn();
$comprimidos = $pdo->query("SELECT COUNT(*) FROM archivos WHERE comprimido = TRUE")->fetchColumn();
$totalDescargas = $pdo->query("SELECT COALESCE(SUM(n_descargas), 0) FROM archivos")->fetchColumn();
$asociaciones = $pdo->query("SELECT COUNT(*) FROM archivo_sucursal WHERE enabled = TRUE")->fetchColumn();

$stmt = $pdo->query("
    SELECT a.nombre, a.ruta, a.fecha_carga, a.fecha_archivo
    FROM archivos a
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
        <header>Comprimidos</header>
        <h3><?= number_format($comprimidos) ?></h3>
    </article>
    <article class="stat-card">
        <header>Descargas</header>
        <h3><?= number_format($totalDescargas) ?></h3>
    </article>
    <article class="stat-card">
        <header>Sucursales</header>
        <h3><?= number_format($totalSucursales) ?></h3>
    </article>
    <article class="stat-card">
        <header>Asociaciones</header>
        <h3><?= number_format($asociaciones) ?></h3>
    </article>
</div>

<section>
    <h2>Archivos Recientes</h2>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Archivo</th>
                    <th>Ruta</th>
                    <th>Modificado</th>
                    <th>Registrado</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recientes)): ?>
                    <tr><td colspan="4">Sin archivos registrados.</td></tr>
                <?php else: ?>
                    <?php foreach ($recientes as $f): ?>
                        <tr>
                            <td><?= htmlspecialchars($f['nombre']) ?></td>
                            <td><code><?= htmlspecialchars($f['ruta']) ?></code></td>
                            <td style="font-size:0.85rem;"><?= fmtFecha($f['fecha_archivo']) ?> (<?= timeago($f['fecha_archivo']) ?>)</td>
                            <td><?= fmtFecha($f['fecha_carga']) ?> (<?= timeago($f['fecha_carga']) ?>)</td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/footer.php'; ?>
