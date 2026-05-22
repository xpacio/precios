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
            <strong>Sincronización completada</strong> en ${data.elapsed}<br>
            ${data.inserted_updated} procesados, ${data.skipped} sin cambios, ${data.errors} errores
        </div>`;

        if (data.total_files > 0) {
            html += `<h3>Archivos cambiados (${data.total_files})</h3>
            <div class="table-container"><table>
                <thead><tr><th>Archivo</th><th>Resultado</th><th>Acción</th></tr></thead>
                <tbody>`;

            for (const r of data.results) {
                const statusClass = r.status === 'SKIP' ? 'badge-ok' : r.status === 'OK' ? 'badge-warn' : 'badge-err';
                html += `<tr>
                    <td><code>${htmlspecialchars(r.nombre)}</code><br><small>${htmlspecialchars(r.ruta)}</small></td>
                    <td class="${statusClass}">${r.status}</td>
                    <td>${r.accion || '-'}</td>
                </tr>`;
            }

            html += `</tbody></table></div>`;
        } else {
            html += `<p>No se detectaron cambios en los archivos.</p>`;
        }

        results.innerHTML = html;
        results.style.display = 'block';

        setTimeout(() => location.reload(), 5000);

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
