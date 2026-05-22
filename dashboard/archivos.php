<?php

$pageTitle = 'Archivos';

$pdo = getDB();

$stmt = $pdo->query("
    SELECT id, ruta, nombre, peso, md5zip, xxh3, comprimido, status, fecha_carga
    FROM archivos
    ORDER BY ruta, nombre
");
$archivos = $stmt->fetchAll();

$tree = [];
$rootFolders = [];
foreach ($archivos as $f) {
    $rel = str_replace('/srv/precios/', '', $f['ruta']);
    $parts = explode('/', $rel);
    $root = $parts[0];
    $rootFolders[$root] = true;

    $path = $f['ruta'];
    $tree[$path][] = $f;
}

$rootFolders = array_keys($rootFolders);
sort($rootFolders);

$selected = $_GET['folder'] ?? '';
$selected = preg_replace('/[^a-zA-Z0-9_\-\. ]/', '', $selected);

$filtered = [];
if ($selected) {
    $prefix = '/srv/precios/' . $selected;
    foreach ($archivos as $f) {
        if (str_starts_with($f['ruta'], $prefix)) {
            $filtered[$f['ruta']][] = $f;
        }
    }
}

require __DIR__ . '/header.php';
?>

<h1>Archivos</h1>

<p>Total: <?= count($archivos) ?> archivos</p>

<div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:1.5rem;">
    <a href="?folder=" role="button" class="<?= $selected === '' ? '' : 'outline' ?>" style="padding:0.4rem 0.8rem;">📁 Todos</a>
    <?php foreach ($rootFolders as $folder): ?>
        <a href="?folder=<?= urlencode($folder) ?>" role="button" class="<?= $selected === $folder ? '' : 'outline secondary' ?>" style="padding:0.4rem 0.8rem;">
            📁 <?= htmlspecialchars($folder) ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($selected): ?>
    <p>Carpeta: <strong><?= htmlspecialchars($selected) ?></strong> — <?= array_sum(array_map('count', $filtered)) ?> archivos</p>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Ruta</th>
                    <th>Archivo</th>
                    <th>MD5</th>
                    <th>XXH3</th>
                    <th>Peso</th>
                    <th>Status</th>
                    <th>Registrado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filtered as $ruta => $files): ?>
                    <tr style="background:#f0f0f0;font-weight:bold">
                        <td colspan="7">📁 <?= htmlspecialchars(str_replace('/srv/precios/', '', $ruta)) ?></td>
                    </tr>
                    <?php foreach ($files as $f): ?>
                        <tr>
                            <td></td>
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
<?php else: ?>
    <p>Seleccione una carpeta para ver sus archivos.</p>

    <div style="display:flex;flex-wrap:wrap;gap:1rem;">
        <?php foreach ($rootFolders as $folder): ?>
            <a href="?folder=<?= urlencode($folder) ?>" style="text-decoration:none;border:1px solid #ccc;border-radius:8px;padding:1.5rem 2rem;text-align:center;min-width:120px;background:#fafafa;color:inherit;">
                <div style="font-size:2rem;">📁</div>
                <div style="margin-top:0.5rem;font-weight:bold;"><?= htmlspecialchars($folder) ?></div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
