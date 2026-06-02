<?php

$pageTitle = 'Cli Log';

$pdo = getDB();

$tab = $_GET['tab'] ?? 'normal';
$daysFilter = (int)($_GET['days'] ?? 7);

// === Normal tab (NOR) ===
$sucursalFilter = $_GET['sucursal'] ?? '';
$norClauses = ["l.file_type = 'NOR'"];
$norParams = [];
if ($sucursalFilter) {
    $norClauses[] = 'l.sucursal_id = ?';
    $norParams[] = $sucursalFilter;
}
if ($daysFilter > 0) {
    $norClauses[] = 'l.created_at >= NOW() - INTERVAL \'' . $daysFilter . ' days\'';
}
$norWhere = 'WHERE ' . implode(' AND ', $norClauses);

$norSql = "SELECT l.id, l.sucursal_id, l.file_name, l.file_type, l.api_key_id, l.usuario_id, l.ip_address, l.status, l.created_at,
                  k.descripcion AS api_key_desc
           FROM cli_log l
           LEFT JOIN api_keys k ON k.id = l.api_key_id
           $norWhere
           ORDER BY l.created_at DESC
           LIMIT 200";
$stmt = $pdo->prepare($norSql);
$stmt->execute($norParams);
$norLogs = $stmt->fetchAll();

$norSumSql = "SELECT COUNT(*) AS total FROM cli_log l $norWhere";
$stmt = $pdo->prepare($norSumSql);
$stmt->execute($norParams);
$norTotal = $stmt->fetchColumn();

// === Desblind tab (DBD) ===
$dbdSucursal = $_GET['dbd_sucursal'] ?? '';
$dbdUsuario = $_GET['dbd_usuario'] ?? '';
$dbdClauses = ["l.file_type = 'DBD'"];
$dbdParams = [];
if ($dbdSucursal) {
    $dbdClauses[] = 'l.sucursal_id = ?';
    $dbdParams[] = $dbdSucursal;
}
if ($dbdUsuario) {
    $dbdClauses[] = 'l.dbd_user ILIKE ?';
    $dbdParams[] = '%' . $dbdUsuario . '%';
}
$dbdWhere = 'WHERE ' . implode(' AND ', $dbdClauses);

$dbdSql = "SELECT l.id, l.sucursal_id, l.file_name, l.dbd_user, l.ip_address, l.status, l.created_at
           FROM cli_log l
           $dbdWhere
           ORDER BY l.created_at DESC
           LIMIT 20";
$stmt = $pdo->prepare($dbdSql);
$stmt->execute($dbdParams);
$dbdLogs = $stmt->fetchAll();

require __DIR__ . '/header.php';
?>

<h1>Cli Log</h1>

<nav class="tabs" id="cli-log-tabs">
    <ul>
        <li><a href="?tab=normal&days=<?= $daysFilter ?>"<?= $tab === 'normal' ? ' class="contrast"' : '' ?>>Normal</a></li>
        <li><a href="?tab=desblind&days=<?= $daysFilter ?>"<?= $tab === 'desblind' ? ' class="contrast"' : '' ?>>Desblind</a></li>
    </ul>
</nav>

<?php if ($tab === 'normal'): ?>

<div id="cli-log-normal">
    <article>
        <header><strong>Normal — <?= number_format($norTotal) ?> descargas</strong></header>
    </article>

    <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem;">
        <span style="font-size:0.85rem;color:#888;">Filtrar:</span>
        <a href="?tab=normal&days=7" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">7 días</a>
        <a href="?tab=normal&days=1" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">1 día</a>
        <a href="?tab=normal&days=0" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;">Todo</a>
        <span style="font-size:0.85rem;color:#888;margin-left:0.5rem;">Sucursal:</span>
        <form method="GET" action="/dashboard/cli-log" style="display:inline">
            <input type="hidden" name="tab" value="normal">
            <input type="hidden" name="days" value="<?= $daysFilter ?>">
            <input type="text" name="sucursal" placeholder="ID sucursal" value="<?= htmlspecialchars($sucursalFilter) ?>"
                   style="width:100px;padding:0.25rem 0.5rem;font-size:0.85rem;">
            <button type="submit" class="secondary" style="padding:0.25rem 0.75rem;font-size:0.85rem;">Ir</button>
        </form>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Fecha</th>
                    <th>Sucursal</th>
                    <th>Archivo</th>
                    <th>API Key</th>
                    <th>IP</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($norLogs)): ?>
                    <tr><td colspan="7">Sin registros de actividad.</td></tr>
                <?php else: ?>
                    <?php foreach ($norLogs as $l): ?>
                        <tr>
                            <td><?= $l['id'] ?></td>
                            <td style="font-size:0.85rem;white-space:nowrap;"><?= date('d/m H:i', strtotime($l['created_at'])) ?></td>
                            <td><code><?= htmlspecialchars($l['sucursal_id']) ?></code></td>
                            <td><code style="font-size:0.8rem;"><?= htmlspecialchars($l['file_name']) ?></code></td>
                            <td style="font-size:0.8rem;"><?= htmlspecialchars($l['api_key_desc'] ?? '-') ?></td>
                            <td style="font-size:0.8rem;"><?= htmlspecialchars($l['ip_address'] ?? '-') ?></td>
                            <td><?= $l['status'] === 'ok' ? '<span style="color:#2e7d32;">OK</span>' : htmlspecialchars($l['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>

<div id="cli-log-desblind">
    <article>
        <header><strong>Desblind — Últimas 20 descargas DBD</strong></header>
    </article>

    <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem;">
        <form method="GET" action="/dashboard/cli-log" style="display:flex;gap:0.5rem;align-items:end;flex-wrap:wrap;">
            <input type="hidden" name="tab" value="desblind">
            <input type="hidden" name="days" value="<?= $daysFilter ?>">
            <label style="font-size:0.85rem;">
                Sucursal
                <input type="text" name="dbd_sucursal" placeholder="ID" value="<?= htmlspecialchars($dbdSucursal) ?>"
                       style="width:100px;padding:0.25rem 0.5rem;font-size:0.85rem;">
            </label>
            <label style="font-size:0.85rem;">
                Usuario
                <input type="text" name="dbd_usuario" placeholder="GTE, elia..." value="<?= htmlspecialchars($dbdUsuario) ?>"
                       style="width:120px;padding:0.25rem 0.5rem;font-size:0.85rem;">
            </label>
            <button type="submit" class="secondary" style="padding:0.25rem 0.75rem;font-size:0.85rem;">Filtrar</button>
            <a href="?tab=desblind" role="button" class="secondary outline" style="padding:0.25rem 0.75rem;font-size:0.85rem;">Limpiar</a>
        </form>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Fecha</th>
                    <th>Sucursal</th>
                    <th>Archivo</th>
                    <th>Usuario</th>
                    <th>IP</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($dbdLogs)): ?>
                    <tr><td colspan="7">Sin registros DBD.</td></tr>
                <?php else: ?>
                    <?php foreach ($dbdLogs as $l): ?>
                        <tr>
                            <td><?= $l['id'] ?></td>
                            <td style="font-size:0.85rem;white-space:nowrap;"><?= date('d/m H:i', strtotime($l['created_at'])) ?></td>
                            <td><code><?= htmlspecialchars($l['sucursal_id']) ?></code></td>
                            <td><code style="font-size:0.8rem;"><?= htmlspecialchars($l['file_name']) ?></code></td>
                            <td><?= htmlspecialchars($l['dbd_user'] ?? '-') ?></td>
                            <td style="font-size:0.8rem;"><?= htmlspecialchars($l['ip_address'] ?? '-') ?></td>
                            <td><?= $l['status'] === 'ok' ? '<span style="color:#2e7d32;">OK</span>' : htmlspecialchars($l['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
