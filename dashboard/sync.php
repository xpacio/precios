<?php

$pageTitle = 'Sincronización';

$pdo = getDB();

$totalFiles = $pdo->query("SELECT COUNT(*) FROM archivos")->fetchColumn();
$disabled = $pdo->query("SELECT COUNT(*) FROM archivos WHERE enabled = FALSE")->fetchColumn();
$updating = $pdo->query("SELECT COUNT(*) FROM archivos WHERE status = 'updating'")->fetchColumn();
$ultimaSync = $pdo->query("SELECT MAX(updated_at) FROM archivos")->fetchColumn();

require __DIR__ . '/header.php';
?>

<h1>Sincronización</h1>

<div class="stats-grid">
    <article class="stat-card">
        <header>Archivos en catálogo</header>
        <h3><?= number_format($totalFiles) ?></h3>
    </article>
    <article class="stat-card">
        <header>Deshabilitados</header>
        <h3 style="color: #c62828;"><?= number_format($disabled) ?></h3>
    </article>
    <article class="stat-card">
        <header>Actualizando</header>
        <h3 style="color: <?= $updating > 0 ? '#e65100' : '#2e7d32' ?>;"><?= number_format($updating) ?></h3>
    </article>
    <article class="stat-card">
        <header>Última actualización</header>
        <h3 style="font-size: 1.2rem;"><?= $ultimaSync ? date('d/m H:i', strtotime($ultimaSync)) : 'Nunca' ?></h3>
    </article>
</div>

<button id="btnSync" onclick="startSync()">🔄 Sincronizar Ahora</button>

<div id="syncLoading" style="display:none; margin-top: 1rem;">
    <progress indeterminate></progress>
    <p>Sincronizando archivos desde el servidor remoto...</p>
</div>

<div id="syncResults" style="display:none; margin-top: 1rem;"></div>

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
