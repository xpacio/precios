<?php

$pageTitle = 'Archivos';

$pdo = getDB();

// Fetch all files grouped by path
$stmt = $pdo->query("
    SELECT id, path, nombre, peso, md5zip, ausente, ultimo_cambio, updated_at
    FROM archivos
    ORDER BY path, nombre
");
$archivos = $stmt->fetchAll();

// Group by path
$groups = [];
foreach ($archivos as $f) {
    $groups[$f['path']][] = $f;
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
                <th>Peso</th>
                <th>Estado</th>
                <th>Último cambio</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $first = true;
            foreach ($groups as $path => $files):
                $pathParts = explode('/', $path);
                $region = $pathParts[0];
                $subdir = count($pathParts) > 1 ? substr($path, strlen($region) + 1) : '';
            ?>
                <tr class="group-header">
                    <td colspan="6">📁 <?= htmlspecialchars($path) ?></td>
                </tr>
                <?php foreach ($files as $f):
                    $esCambiado = $f['ultimo_cambio'] && (time() - strtotime($f['ultimo_cambio'])) < 3600;
                    $esAusente = ($f['ausente'] === 't' || $f['ausente'] === true);
                    $rowClass = $esCambiado ? 'cambiado' : ($esAusente ? 'ausente' : '');
                ?>
                    <tr class="<?= $rowClass ?>">
                        <td><?= htmlspecialchars($f['nombre']) ?></td>
                        <td><code><?= htmlspecialchars($f['md5zip'] ?? '-') ?></code></td>
                        <td><?= $f['peso'] ? number_format($f['peso']) . ' B' : '-' ?></td>
                        <td>
                            <?php if ($esAusente): ?>
                                <span class="badge-err">Ausente</span>
                            <?php elseif ($esCambiado): ?>
                                <span class="badge-warn">Cambiado</span>
                            <?php else: ?>
                                <span class="badge-ok">OK</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $f['ultimo_cambio'] ? htmlspecialchars($f['ultimo_cambio']) : '-' ?></td>
                        <td>
                            <form method="POST" action="/api/v1/sync/<?= htmlspecialchars($f['id']) ?>" style="display:inline" target="_blank">
                                <input type="hidden" name="X-API-Key" value="precios_api_key_2024">
                                <button type="submit" class="secondary outline" style="padding: 0.2rem 0.5rem;font-size:0.8rem">Sync</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/footer.php'; ?>
