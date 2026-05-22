<?php

$pageTitle = 'Sincronización';

$pdo = getDB();

$totalFiles = $pdo->query("SELECT COUNT(*) FROM archivos")->fetchColumn();
$ready = $pdo->query("SELECT COUNT(*) FROM archivos WHERE status = 'ready'")->fetchColumn();
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
        <header>Listos</header>
        <h3 style="color: #2e7d32;"><?= number_format($ready) ?></h3>
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

        if (data.status !== 'OK') {
            results.innerHTML = `<div class="flash flash-error">Error: ${data.mensaje || 'Desconocido'}</div>`;
            results.style.display = 'block';
            return;
        }

        let html = `<div class="flash flash-success">
            <strong>Sincronización completada</strong> en ${data.elapsed}
        </div>`;

        if (data.output) {
            html += `<pre style="background:#1e1e1e;color:#d4d4d4;padding:1rem;border-radius:4px;overflow-x:auto;font-size:0.85rem;line-height:1.4;max-height:60vh;overflow-y:auto;">${htmlspecialchars(data.output)}</pre>`;
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

function htmlspecialchars(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
</script>

<?php require __DIR__ . '/footer.php'; ?>
