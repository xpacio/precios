<?php

$pageTitle = 'Archivo Log';

$pdo = getDB();

$actionFilter = $_GET['action'] ?? '';
$qFilter = $_GET['q'] ?? '';
$daysFilter = (int)($_GET['days'] ?? 7);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
$offset = ($page - 1) * $perPage;

$clauses = [];
$params = [];

if ($actionFilter) {
    $clauses[] = 'l.action = ?';
    $params[] = $actionFilter;
}
if ($qFilter) {
    $clauses[] = '(a.ruta ILIKE ? OR a.nombre ILIKE ? OR CAST(a.id AS TEXT) = ?)';
    $likeQ = '%' . $qFilter . '%';
    $params[] = $likeQ;
    $params[] = $likeQ;
    $params[] = $qFilter;
}
if ($daysFilter > 0) {
    $clauses[] = 'l.created_at >= NOW() - INTERVAL \'' . $daysFilter . ' days\'';
}

$where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

$countSql = "SELECT COUNT(*) FROM archivo_log l JOIN archivos a ON a.id = l.archivo_id $where";
$totalStmt = $pdo->prepare($countSql);
$totalStmt->execute($params);
$totalRows = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$sql = "SELECT l.id, l.action, l.prev_flat, l.new_flat, l.detalle, l.created_at,
               a.id AS archivo_id, a.ruta, a.nombre
        FROM archivo_log l
        JOIN archivos a ON a.id = l.archivo_id
        $where
        ORDER BY l.created_at DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$sumSql = "SELECT l.action, COUNT(*) AS total
           FROM archivo_log l JOIN archivos a ON a.id = l.archivo_id $where
           GROUP BY l.action ORDER BY l.action";
$stmt = $pdo->prepare($sumSql);
$stmt->execute($params);
$resumen = $stmt->fetchAll();

require __DIR__ . '/header.php';
?>

<h1>Archivo Log</h1>

<article>
    <header><strong>Resumen por acción</strong></header>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Acción</th>
                    <th>Registros</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($resumen)): ?>
                    <tr><td colspan="2">Sin registros.</td></tr>
                <?php else: ?>
                    <?php foreach ($resumen as $r): ?>
                        <tr>
                            <td>
                                <a href="?action=<?= urlencode($r['action']) ?>&days=<?= $daysFilter ?>&q=<?= urlencode($qFilter) ?>">
                                    <code><?= htmlspecialchars($r['action']) ?></code>
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
    <a href="/dashboard/archivo-log?days=7" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">7 días</a>
    <a href="/dashboard/archivo-log?days=1" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">1 día</a>
    <a href="/dashboard/archivo-log?days=30" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">30 días</a>
    <a href="/dashboard/archivo-log?days=0" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">Todo</a>
    <span style="font-size:0.85rem;color:#888;margin-left:0.5rem;">Acción:</span>
    <a href="/dashboard/archivo-log?days=<?= $daysFilter ?>&q=<?= urlencode($qFilter) ?>" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">todas</a>
    <a href="?action=sync&amp;days=<?= $daysFilter ?>&q=<?= urlencode($qFilter) ?>" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">sync</a>
    <a href="?action=verify&amp;days=<?= $daysFilter ?>&q=<?= urlencode($qFilter) ?>" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">verify</a>
    <a href="?action=upload&amp;days=<?= $daysFilter ?>&q=<?= urlencode($qFilter) ?>" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">upload</a>
    <a href="?action=assoc&amp;days=<?= $daysFilter ?>&q=<?= urlencode($qFilter) ?>" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">assoc</a>
    <span style="font-size:0.85rem;color:#888;margin-left:0.5rem;">Buscar:</span>
    <form method="GET" style="display:inline;margin:0;">
        <?php if ($actionFilter): ?>
            <input type="hidden" name="action" value="<?= htmlspecialchars($actionFilter) ?>">
        <?php endif; ?>
        <input type="hidden" name="days" value="<?= $daysFilter ?>">
        <input type="text" name="q" placeholder="ruta / nombre o ID" value="<?= htmlspecialchars($qFilter) ?>"
               style="width:180px;padding:0.25rem 0.5rem;font-size:0.85rem;">
        <button type="submit" class="secondary" style="padding:0.25rem 0.75rem;font-size:0.85rem;">Buscar</button>
    </form>
</div>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Fecha</th>
                <th>Archivo</th>
                <th>Acción</th>
                <th>Detalle</th>
                <th>Flat Prev</th>
                <th>Flat New</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="7">Sin registros.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td><?= $l['id'] ?></td>
                        <td style="font-size:0.85rem;white-space:nowrap;"><?= date('d/m H:i', strtotime($l['created_at'])) ?></td>
                        <td>
                            <a href="/dashboard/archivo-editar?id=<?= $l['archivo_id'] ?>">
                                <code style="font-size:0.8rem;"><?= htmlspecialchars($l['ruta'] . '/' . $l['nombre']) ?></code>
                            </a>
                        </td>
                        <td><code><?= htmlspecialchars($l['action']) ?></code></td>
                        <td style="font-size:0.85rem;"><?= htmlspecialchars($l['detalle'] ?? '-') ?></td>
                        <td style="font-size:0.8rem;"><?= htmlspecialchars($l['prev_flat'] ?? '-') ?></td>
                        <td style="font-size:0.8rem;"><?= htmlspecialchars($l['new_flat'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
    <div style="display:flex;gap:0.5rem;justify-content:center;margin-top:1rem;">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="?page=<?= $p ?>&days=<?= $daysFilter ?>&action=<?= urlencode($actionFilter) ?>&q=<?= urlencode($qFilter) ?>"
               role="button" class="secondary outline" style="padding:0.25rem 0.6rem;font-size:0.85rem;<?= $p === $page ? 'font-weight:bold;' : '' ?>">
                <?= $p ?>
            </a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
