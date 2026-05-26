<?php

$pageTitle = 'Sync Log';

$pdo = getDB();

// Filtro por modo
$modeFilter = $_GET['mode'] ?? '';
$daysFilter = (int)($_GET['days'] ?? 7);
$clauses = [];
$params = [];

if ($modeFilter && in_array($modeFilter, ['full', 'full-fast', 'selected', 'one'], true)) {
    $clauses[] = 'mode = ?';
    $params[] = $modeFilter;
}
if ($daysFilter > 0) {
    $clauses[] = 'created_at >= NOW() - INTERVAL \'' . $daysFilter . ' days\'';
}

$where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

// Últimos 100 registros
$sql = "SELECT id, mode, params, status, total, transferidos, procesados, omitidos, errores, exit_code, duration_sec, created_at
        FROM sync_log $where
        ORDER BY created_at DESC
        LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT mode, COUNT(*) AS total_ejecuciones,
           SUM(total) AS total_archivos,
           SUM(transferidos) AS total_transferidos,
           SUM(procesados) AS total_procesados
    FROM sync_log $where
    GROUP BY mode
    ORDER BY mode
");
$stmt->execute($params);
$resumen = $stmt->fetchAll();

function fmtStatus(string $s): string {
    return match ($s) {
        'ok' => '<span style="color:#2e7d32;">OK</span>',
        'warning' => '<span style="color:#e65100;">WARNING</span>',
        'error' => '<span style="color:#c62828;">ERROR</span>',
        default => htmlspecialchars($s),
    };
}

require __DIR__ . '/header.php';
?>

<h1>Sync Log</h1>

<article>
    <header><strong>Resumen por modo</strong></header>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Modo</th>
                    <th>Ejecuciones</th>
                    <th>Archivos totales</th>
                    <th>Transferidos</th>
                    <th>Procesados</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($resumen)): ?>
                    <tr><td colspan="5">Sin registros.</td></tr>
                <?php else: ?>
                    <?php foreach ($resumen as $r): ?>
                        <tr>
                            <td><a href="?mode=<?= urlencode($r['mode']) ?>"><?= htmlspecialchars($r['mode']) ?></a></td>
                            <td><?= number_format($r['total_ejecuciones']) ?></td>
                            <td><?= number_format($r['total_archivos']) ?></td>
                            <td><?= number_format($r['total_transferidos']) ?></td>
                            <td><?= number_format($r['total_procesados']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</article>

<div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem;">
    <span style="font-size:0.85rem;color:#888;">Filtrar:</span>
    <a href="/dashboard/sync-log?days=7" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">7 días</a>
    <a href="/dashboard/sync-log?days=0" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">Todo</a>
    <span style="font-size:0.85rem;color:#888;margin-left:0.5rem;">Modo:</span>
    <a href="/dashboard/sync-log?days=<?= $daysFilter ?>" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">todos</a>
    <a href="?mode=full&amp;days=<?= $daysFilter ?>" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">full</a>
    <a href="?mode=full-fast&amp;days=<?= $daysFilter ?>" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">full-fast</a>
    <a href="?mode=selected&amp;days=<?= $daysFilter ?>" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">selected</a>
    <a href="?mode=one&amp;days=<?= $daysFilter ?>" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">one</a>
</div>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Fecha</th>
                <th>Modo</th>
                <th>Params</th>
                <th>Estado</th>
                <th>Total</th>
                <th>Transf.</th>
                <th>Proc.</th>
                <th>Omit.</th>
                <th>Err.</th>
                <th>Dur.</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="11">Sin registros de sincronización.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $l):
                    $dur = $l['duration_sec'];
                    $durStr = $dur < 60 ? round($dur) . 's' : floor($dur/60) . 'm' . round($dur % 60) . 's';
                ?>
                    <tr>
                        <td><?= $l['id'] ?></td>
                        <td style="font-size:0.85rem;white-space:nowrap;"><?= date('d/m H:i', strtotime($l['created_at'])) ?></td>
                        <td><code><?= htmlspecialchars($l['mode']) ?></code></td>
                        <td><code style="font-size:0.8rem;"><?= htmlspecialchars($l['params']) ?></code></td>
                        <td><?= fmtStatus($l['status']) ?></td>
                        <td><?= number_format($l['total']) ?></td>
                        <td><?= number_format($l['transferidos']) ?></td>
                        <td><?= number_format($l['procesados']) ?></td>
                        <td><?= number_format($l['omitidos']) ?></td>
                        <td><?= $l['errores'] ? '<span style="color:#c62828;">' . number_format($l['errores']) . '</span>' : '0' ?></td>
                        <td style="font-size:0.85rem;"><?= $durStr ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/footer.php'; ?>
