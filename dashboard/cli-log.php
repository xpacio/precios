<?php

$pageTitle = 'Cli Log';

$pdo = getDB();

$sucursalFilter = $_GET['sucursal'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$daysFilter = (int)($_GET['days'] ?? 7);
$clauses = [];
$params = [];

if ($sucursalFilter) {
    $clauses[] = 'l.sucursal_id = ?';
    $params[] = $sucursalFilter;
}
if ($typeFilter && in_array($typeFilter, ['NOR', 'DBD'], true)) {
    $clauses[] = 'l.file_type = ?';
    $params[] = $typeFilter;
}
if ($daysFilter > 0) {
    $clauses[] = 'l.created_at >= NOW() - INTERVAL \'' . $daysFilter . ' days\'';
}

$where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

$sql = "SELECT l.id, l.sucursal_id, l.file_name, l.file_type, l.api_key_id, l.usuario_id, l.ip_address, l.status, l.created_at,
               k.descripcion AS api_key_desc
        FROM cli_log l
        LEFT JOIN api_keys k ON k.id = l.api_key_id
        $where
        ORDER BY l.created_at DESC
        LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$sumSql = "SELECT l.file_type, COUNT(*) AS total
           FROM cli_log l $where
           GROUP BY l.file_type ORDER BY l.file_type";
$stmt = $pdo->prepare($sumSql);
$stmt->execute($params);
$resumen = $stmt->fetchAll();

$alertas = [];
$stmt = $pdo->prepare("SELECT d.id, d.sucursal_id, d.file_name, d.created_at
        FROM cli_log d
        WHERE d.file_type = 'DBD'
          AND d.created_at < NOW() - INTERVAL '1 minute'
          AND d.created_at > NOW() - INTERVAL '1 hour'
          AND NOT EXISTS (
            SELECT 1 FROM cli_log n
            WHERE n.sucursal_id = d.sucursal_id
              AND n.file_type = 'NOR'
              AND n.created_at > d.created_at
              AND n.created_at < d.created_at + INTERVAL '1 hour'
          )
        ORDER BY d.created_at DESC
        LIMIT 50");
$stmt->execute();
$alertas = $stmt->fetchAll();

require __DIR__ . '/header.php';
?>

<h1>Cli Log</h1>

<?php if ($alertas): ?>
    <div class="flash flash-warning">
        <strong><?= count($alertas) ?> DBD sin NOR complementario</strong> (última hora, +1 min de antigüedad)
        <details style="margin-top:0.5rem;">
            <summary>Ver detalles</summary>
            <table>
                <thead>
                    <tr>
                        <th>Sucursal</th>
                        <th>Archivo</th>
                        <th>Descargado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($alertas as $a): ?>
                        <tr>
                            <td><?= htmlspecialchars($a['sucursal_id']) ?></td>
                            <td><?= htmlspecialchars($a['file_name']) ?></td>
                            <td><?= date('d/m H:i', strtotime($a['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    </div>
<?php endif; ?>

<article>
    <header><strong>Resumen por tipo</strong></header>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Descargas</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($resumen)): ?>
                    <tr><td colspan="2">Sin registros.</td></tr>
                <?php else: ?>
                    <?php foreach ($resumen as $r): ?>
                        <tr>
                            <td>
                                <a href="?type=<?= urlencode($r['file_type']) ?>&days=<?= $daysFilter ?>">
                                    <?= htmlspecialchars($r['file_type']) ?>
                                </a>
                            </td>
                            <td><?= number_format($r['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</article>

<div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem;">
    <span style="font-size:0.85rem;color:#888;">Filtrar:</span>
    <a href="/dashboard/cli-log?days=7" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">7 días</a>
    <a href="/dashboard/cli-log?days=1" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">1 día</a>
    <a href="/dashboard/cli-log?days=0" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">Todo</a>
    <span style="font-size:0.85rem;color:#888;margin-left:0.5rem;">Tipo:</span>
    <a href="/dashboard/cli-log?days=<?= $daysFilter ?>" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">todos</a>
    <a href="?type=NOR&amp;days=<?= $daysFilter ?>" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">NOR</a>
    <a href="?type=DBD&amp;days=<?= $daysFilter ?>" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">DBD</a>
    <span style="font-size:0.85rem;color:#888;margin-left:0.5rem;">Sucursal:</span>
    <input type="text" name="sucursal" form="filtro-sucursal" placeholder="ID sucursal" value="<?= htmlspecialchars($sucursalFilter) ?>"
           style="width:100px;padding:0.25rem 0.5rem;font-size:0.85rem;">
    <a href="?sucursal=<?= urlencode($sucursalFilter) ?>&days=<?= $daysFilter ?>&type=<?= urlencode($typeFilter) ?>"
       role="button" class="secondary" style="padding:0.25rem 0.75rem;font-size:0.85rem;">Ir</a>
</div>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Fecha</th>
                <th>Sucursal</th>
                <th>Archivo</th>
                <th>Tipo</th>
                <th>API Key</th>
                <th>IP</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="8">Sin registros de actividad.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td><?= $l['id'] ?></td>
                        <td style="font-size:0.85rem;white-space:nowrap;"><?= date('d/m H:i', strtotime($l['created_at'])) ?></td>
                        <td><code><?= htmlspecialchars($l['sucursal_id']) ?></code></td>
                        <td><code style="font-size:0.8rem;"><?= htmlspecialchars($l['file_name']) ?></code></td>
                        <td>
                            <?php if ($l['file_type'] === 'DBD'): ?>
                                <span style="color:#c62828;font-weight:bold;">DBD</span>
                            <?php else: ?>
                                <span style="color:#2e7d32;">NOR</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.8rem;"><?= htmlspecialchars($l['api_key_desc'] ?? '-') ?></td>
                        <td style="font-size:0.8rem;"><?= htmlspecialchars($l['ip_address'] ?? '-') ?></td>
                        <td><?= $l['status'] === 'ok' ? '<span style="color:#2e7d32;">OK</span>' : htmlspecialchars($l['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/footer.php'; ?>
