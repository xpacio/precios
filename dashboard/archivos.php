<?php

$pageTitle = 'Archivos';

$pdo = getDB();

$stmt = $pdo->query("
    SELECT id, ruta, nombre, peso, md5zip, xxh3, comprimido, status, fecha_carga
    FROM archivos
    ORDER BY ruta, nombre
");
$archivos = $stmt->fetchAll();

$groups = [];
foreach ($archivos as $f) {
    $groups[$f['ruta']][] = $f;
}

require __DIR__ . '/header.php';
?>

<h1>Archivos</h1>

<p>Total: <?= count($archivos) ?> archivos en <?= count($groups) ?> directorios</p>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>Archivo</th>
                <th>MD5</th>
                <th>XXH3</th>
                <th>Peso</th>
                <th>Status</th>
                <th>Registrado</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($groups as $ruta => $files): ?>
                <tr style="background:#f0f0f0;font-weight:bold">
                    <td colspan="6">📁 <?= htmlspecialchars($ruta) ?></td>
                </tr>
                <?php foreach ($files as $f): ?>
                    <tr>
                        <td><?= htmlspecialchars($f['nombre']) ?></td>
                        <td><code><?= htmlspecialchars($f['md5zip'] ?? '-') ?></code></td>
                        <td><code><?= htmlspecialchars($f['xxh3'] ?? '-') ?></code></td>
                        <td><?= $f['peso'] ? number_format($f['peso']) . ' B' : '-' ?></td>
                        <td><?= htmlspecialchars($f['status'] ?? 'ready') ?></td>
                        <td><?= htmlspecialchars($f['fecha_carga']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/footer.php'; ?>
