<?php

$pageTitle = 'Sincronización';

$pdo = getDB();

$totalFiles = $pdo->query("SELECT COUNT(*) FROM archivos")->fetchColumn();
$presentes = $pdo->query("SELECT COUNT(*) FROM archivos WHERE enabled = TRUE AND status = 'ready'")->fetchColumn();
$disabled = $pdo->query("SELECT COUNT(*) FROM archivos WHERE enabled = FALSE")->fetchColumn();
$ausentes = $pdo->query("SELECT COUNT(*) FROM archivos WHERE enabled = TRUE AND status = 'missing'")->fetchColumn();
$updating = $pdo->query("SELECT COUNT(*) FROM archivos WHERE status = 'updating'")->fetchColumn();
$cambiados = $pdo->query("SELECT COUNT(*) FROM archivos WHERE updated_at > NOW() - INTERVAL '1 hour'")->fetchColumn();
$ultimaSync = $pdo->query("SELECT MAX(updated_at) FROM archivos")->fetchColumn();

$sucPendientes = $pdo->query("
    SELECT s.id_sucursal, s.nombre_sucursal, COUNT(*) AS num_pendientes
    FROM archivo_sucursal asu
    JOIN archivos a ON a.id = asu.archivo_id
    JOIN sucursales s ON s.id_sucursal = asu.sucursal_id
    WHERE asu.enabled = TRUE AND asu.sync = FALSE AND a.enabled = TRUE
    GROUP BY s.id_sucursal, s.nombre_sucursal
    ORDER BY num_pendientes DESC
    LIMIT 20
")->fetchAll();

require __DIR__ . '/header.php';
?>

<h1>Sincronización</h1>

<div class="stats-grid">
    <article class="stat-card">
        <header>Archivos en catálogo</header>
        <h3><?= number_format($totalFiles) ?></h3>
    </article>
    <article class="stat-card">
        <header>Presentes</header>
        <h3 style="color: #2e7d32;"><?= number_format($presentes) ?></h3>
    </article>
    <article class="stat-card">
        <header>Deshabilitados</header>
        <h3 style="color: #c62828;"><?= number_format($disabled) ?></h3>
    </article>
    <article class="stat-card">
        <header>Ausentes</header>
        <h3 style="color: #c62828;"><?= number_format($ausentes) ?></h3>
    </article>
    <article class="stat-card">
        <header>Actualizando</header>
        <h3 style="color: <?= $updating > 0 ? '#e65100' : '#2e7d32' ?>;"><?= number_format($updating) ?></h3>
    </article>
    <article class="stat-card">
        <header>Cambiados (última hora)</header>
        <h3 style="color: #e65100;"><?= number_format($cambiados) ?></h3>
    </article>
    <article class="stat-card">
        <header>Última actualización</header>
        <h3 style="font-size: 1.2rem;"><?= $ultimaSync ? date('d/m H:i', strtotime($ultimaSync)) : 'Nunca' ?></h3>
    </article>
</div>

<div style="display: flex; gap: 1rem; flex-wrap: wrap;">
    <button id="btnSync" onclick="startSync()">🔄 Sincronizar Ahora</button>
    <button id="btnVerify" onclick="startVerify()" class="secondary">🔍 Verificar y Comprimir</button>
</div>

<div id="syncLoading" style="display:none; margin-top: 1rem;">
    <progress indeterminate></progress>
    <p>Sincronizando archivos desde el servidor remoto...</p>
</div>

<div id="syncResults" style="display:none; margin-top: 1rem;"></div>
<div id="verifyResults" style="display:none; margin-top: 1rem;"></div>

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
            <?php if (empty($sucPendientes)): ?>
                <tr><td colspan="4">No hay pendientes</td></tr>
            <?php else:
                foreach ($sucPendientes as $r): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($r['id_sucursal']) ?></code></td>
                        <td><?= htmlspecialchars($r['nombre_sucursal']) ?></td>
                        <td><span class="badge-warn"><?= $r['num_pendientes'] ?></span></td>
                        <td>
                            <a href="/dashboard/archivos?sucursal=<?= urlencode($r['id_sucursal']) ?>" role="button" class="secondary outline" style="padding:0.25rem 0.5rem;font-size:0.8rem">Ver</a>
                        </td>
                    </tr>
                <?php endforeach;
            endif; ?>
        </tbody>
    </table>
</div>

<script>
async function startSync() {
    const btn = document.getElementById('btnSync');
    const loading = document.getElementById('syncLoading');
    const results = document.getElementById('syncResults');

    btn.disabled = true;
    loading.style.display = 'block';
    results.style.display = 'none';
    results.innerHTML = '';

    try {
        const resp = await fetch('/api/v1/sync');
        const data = await resp.json();

        loading.style.display = 'none';

        let bannerType = data.status === 'OK' ? 'success' : 'warning';
        let extra = '';
        if (data.disabled_count > 0) extra += ` — ${data.disabled_count} archivo(s) deshabilitado(s) por ausencia`;

        let bannerMsg = data.status === 'OK'
            ? `<strong>Sincronización completada</strong> en ${data.elapsed}${extra}`
            : `<strong>Sincronización con advertencias</strong> en ${data.elapsed} (código: ${data.exit_code})${extra}`;

        let html = `<div class="flash flash-${bannerType}">${bannerMsg}</div>`;

        if (data.output) {
            html += renderSyncLog(data.output);
        } else {
            html += `<p>No se detectaron cambios.</p>`;
        }

        results.innerHTML = html;
        results.style.display = 'block';

    } catch (err) {
        loading.style.display = 'none';
        results.innerHTML = `<div class="flash flash-error">Error de conexión: ${err.message}</div>`;
        results.style.display = 'block';
    } finally {
        btn.disabled = false;
    }
}

async function startVerify() {
    const btn = document.getElementById('btnVerify');
    const results = document.getElementById('verifyResults');

    btn.disabled = true;
    results.style.display = 'none';
    results.innerHTML = '<progress indeterminate></progress><p>Verificando y comprimiendo archivos...</p>';
    results.style.display = 'block';

    try {
        const resp = await fetch('/api/v1/verify', { method: 'POST' });
        const text = await resp.text();

        let html = `<div class="flash flash-${resp.ok ? 'success' : 'error'}">`;
        html += `<pre style="margin:0">${htmlspecialchars(text)}</pre></div>`;
        results.innerHTML = html;
    } catch (err) {
        results.innerHTML = `<div class="flash flash-error">Error: ${err.message}</div>`;
    } finally {
        btn.disabled = false;
    }
}

function renderSyncLog(output) {
    const lines = output.split('\n');
    let html = '<div style="background:#1e1e1e;padding:1rem;border-radius:4px;font-size:0.85rem;line-height:1.5;max-height:60vh;overflow-y:auto;font-family:monospace;">';
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        const trimmed = line.trim();
        if (!trimmed) continue;

        let color = '#d4d4d4';
        let icon = '';
        if (/\[e1\]/.test(line)) { color = '#f44336'; icon = '✗ '; }
        else if (/\[e2\]/.test(line)) { color = '#ff9800'; icon = '⚠ '; }
        else if (/\[i0\]/.test(line)) { color = '#9e9e9e'; }
        else if (/\[i1\]/.test(line)) { color = '#80cbc4'; }
        else if (/\[i2\]/.test(line)) { color = '#81c784'; icon = '✓ '; }
        else if (/\[i3\]/.test(line)) { color = '#64b5f6'; }

        const escaped = htmlspecialchars(line);
        html += `<div style="color:${color};white-space:pre-wrap;word-break:break-all;">${icon}${escaped}</div>`;
    }
    html += '</div>';
    return html;
}

function htmlspecialchars(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
</script>

<?php require __DIR__ . '/footer.php'; ?>
