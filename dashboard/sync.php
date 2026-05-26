<?php

$pageTitle = 'Sincronización';

$pdo = getDB();

// === AJAX: search archivos ===
if (($_GET['type'] ?? '') === 'search-files') {
    $q = trim($_GET['q'] ?? '');
    $results = [];
    if (strlen($q) >= 2) {
        $stmt = $pdo->prepare("
            SELECT id, ruta, nombre, status, enabled, fecha_archivo
            FROM archivos
            WHERE (ruta ILIKE ? OR nombre ILIKE ?) AND enabled = TRUE
            ORDER BY ruta, nombre
            LIMIT 50
        ");
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like]);
        $results = $stmt->fetchAll();
    }
    header('Content-Type: application/json');
    echo json_encode(['results' => $results]);
    exit;
}

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

<div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
    <button id="btnSync" onclick="startSync()">🔄 Sincronizar Ahora</button>
    <button id="btnSyncFast" onclick="startSyncFast()" class="secondary outline">⚡ Sync Rápido</button>
</div>

<div id="syncLoading" style="display:none; margin-top: 1rem;">
    <progress indeterminate></progress>
    <p id="syncLoadingMsg">Sincronizando archivos desde el servidor remoto...</p>
</div>

<div id="syncResults" style="display:none; margin-top: 1rem;"></div>

<hr style="margin:2rem 0;">

<h2>Sync Selectivo</h2>
<p style="color:#666;">Busca y selecciona archivos para sincronizar individualmente.</p>

<div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1.5rem;">
    <article>
        <header><strong>Archivos disponibles</strong></header>
        <input type="text" id="selSearch" placeholder="Buscar archivo (mín. 2 caracteres)..." style="margin-bottom:0.75rem;">
        <div id="selResults" style="max-height:400px;overflow-y:auto;">
            <p style="color:#888;">Escribe para buscar archivos habilitados.</p>
        </div>
        <div style="display:flex;gap:0.5rem;margin-top:0.75rem;">
            <button id="btnSelSync" class="primary" disabled>Sincronizar seleccionados</button>
            <button id="btnSelClear" class="secondary outline" disabled>Limpiar</button>
        </div>
        <progress id="selProgress" style="display:none;margin-top:0.75rem;width:100%;"></progress>
    </article>
    <article>
        <header><strong>Resultados</strong></header>
        <div id="selResultados" style="max-height:450px;overflow-y:auto;">
            <p style="color:#888;">Los resultados aparecerán aquí después de sincronizar.</p>
        </div>
    </article>
</div>

<script>
async function syncFetch(url, btnId) {
    const btn = document.getElementById(btnId);
    const loading = document.getElementById('syncLoading');
    const loadingMsg = document.getElementById('syncLoadingMsg');
    const results = document.getElementById('syncResults');

    btn.disabled = true;
    loading.style.display = 'block';
    results.style.display = 'none';
    results.innerHTML = '';

    try {
        const resp = await fetch(url);
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

async function startSync() {
    await syncFetch('/api/v1/sync', 'btnSync');
}

async function startSyncFast() {
    document.getElementById('syncLoadingMsg').textContent = 'Sync rápido: descargando todo con rsync...';
    await syncFetch('/api/v1/sync-fast', 'btnSyncFast');
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

function fmtFecha(ts) {
    if (!ts) return '-';
    var parts = ts.split(' ');
    if (parts.length !== 2) return ts;
    var d = parts[0].split('-');
    var t = parts[1].split(':');
    if (d.length < 3 || t.length < 2) return ts;
    return d[0].slice(-1) + d[1] + d[2] + '.' + t[0] + t[1];
}

function timeago(ts) {
    if (!ts) return '-';
    var diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
    if (diff < 0) return '0s';
    var s = diff;
    var m = Math.floor(s / 60);
    var h = Math.floor(s / 3600);
    var d = Math.floor(s / 86400);
    var M = Math.floor(d / 30);
    var a = Math.floor(d / 365);
    if (s < 60) return s + 's';
    if (m < 60) return m + 'm';
    if (h < 2) return h + 'h' + (m % 60 ? (m % 60) + 'm' : '');
    if (h < 24) return h + 'h+';
    if (d === 1) return '1d';
    if (d === 2) return '2d+';
    if (d < 30) return d + 'd+';
    if (M === 1) return '1M';
    if (M < 12) return M + 'M+';
    if (a === 1) return '1a';
    return a + 'a+';
}

// === Sync Selectivo ===
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('selSearch');
    const resultsDiv = document.getElementById('selResults');
    const btnSync = document.getElementById('btnSelSync');
    const btnClear = document.getElementById('btnSelClear');
    const progress = document.getElementById('selProgress');
    const resultadosDiv = document.getElementById('selResultados');

    let searchTimer = null;

    function renderFileList(data) {
        if (data.results.length === 0) {
            resultsDiv.innerHTML = '<p style="color:#888;">Sin resultados.</p>';
            return;
        }
        let html = '<div style="display:flex;flex-direction:column;gap:0.3rem;">';
        for (let i = 0; i < data.results.length; i++) {
            const f = data.results[i];
            const label = f.ruta + '/' + f.nombre;
            html += '<label style="display:flex;align-items:center;gap:0.5rem;padding:0.3rem 0.5rem;border-radius:4px;cursor:pointer;" onmouseenter="this.style.background=\'var(--card-background-color)\'" onmouseleave="this.style.background=\'\'">';
            html += '<input type="checkbox" class="sel-file-cb" data-ruta="' + htmlspecialchars(f.ruta) + '" data-nombre="' + htmlspecialchars(f.nombre) + '" style="flex-shrink:0;">';
            html += '<span style="font-size:0.85rem;">' + htmlspecialchars(label) + '</span>';
            html += '<span style="font-size:0.75rem;color:#999;margin-left:0.75rem;">' + fmtFecha(f.fecha_archivo) + ' (' + timeago(f.fecha_archivo) + ')' + '</span>';
            html += '<span style="margin-left:auto;font-size:0.75rem;color:#888;">' + htmlspecialchars(f.status) + '</span>';
            html += '</label>';
        }
        html += '</div>';
        resultsDiv.innerHTML = html;
    }

    function doSearch() {
        const q = searchInput.value.trim();
        if (q.length < 2) {
            resultsDiv.innerHTML = '<p style="color:#888;">Escribe al menos 2 caracteres.</p>';
            return;
        }
        fetch('?type=search-files&q=' + encodeURIComponent(q))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                renderFileList(data);
                updateButtons();
            })
            .catch(function () {
                resultsDiv.innerHTML = '<p style="color:red;">Error al buscar.</p>';
            });
    }

    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(doSearch, 300);
    });

    function getSelectedFiles() {
        const cbs = resultsDiv.querySelectorAll('.sel-file-cb:checked');
        const files = [];
        for (let i = 0; i < cbs.length; i++) {
            files.push({
                ruta: cbs[i].dataset.ruta,
                nombre: cbs[i].dataset.nombre,
            });
        }
        return files;
    }

    function updateButtons() {
        const files = getSelectedFiles();
        btnSync.disabled = files.length === 0;
        btnClear.disabled = files.length === 0;
    }

    resultsDiv.addEventListener('change', function (e) {
        if (e.target.classList.contains('sel-file-cb')) {
            updateButtons();
        }
    });

    btnClear.addEventListener('click', function () {
        const cbs = resultsDiv.querySelectorAll('.sel-file-cb:checked');
        for (let i = 0; i < cbs.length; i++) {
            cbs[i].checked = false;
        }
        updateButtons();
    });

    btnSync.addEventListener('click', function () {
        const files = getSelectedFiles();
        if (files.length === 0) return;

        progress.style.display = 'block';
        btnSync.disabled = true;
        resultadosDiv.innerHTML = '<p style="color:#888;">Sincronizando...</p>';

        fetch('/api/v1/sync-selected', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ files: files }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            progress.style.display = 'none';
            btnSync.disabled = false;

            let html = '<div style="display:flex;flex-direction:column;gap:0.4rem;">';
            if (data.resultados) {
                for (let i = 0; i < data.resultados.length; i++) {
                    const r = data.resultados[i];
                    const label = r.ruta + '/' + r.nombre;
                    let icon, color;
                    if (r.compresion === 'OK') { icon = '✅'; color = '#2e7d32'; }
                    else if (r.compresion === 'SKIP') { icon = '⏭️'; color = '#666'; }
                    else if (r.sync === 'AUSENTE') { icon = '❌'; color = '#c62828'; }
                    else { icon = '⚠️'; color = '#e65100'; }
                    html += '<div style="display:flex;align-items:center;gap:0.5rem;padding:0.3rem 0.5rem;border-radius:4px;background:var(--card-background-color);">';
                    html += '<span>' + icon + '</span>';
                    html += '<span style="font-size:0.85rem;color:' + color + ';">' + htmlspecialchars(label) + '</span>';
                    html += '<span style="margin-left:auto;font-size:0.75rem;color:#888;">' + htmlspecialchars(r.mensaje || '') + '</span>';
                    html += '</div>';
                }
            }
            if (data.elapsed) {
                html += '<p style="margin-top:0.5rem;font-size:0.8rem;color:#888;">Completado en ' + htmlspecialchars(data.elapsed) + '</p>';
            }
            html += '</div>';
            resultadosDiv.innerHTML = html;

            // Uncheck synced files
            const cbs = resultsDiv.querySelectorAll('.sel-file-cb:checked');
            for (let i = 0; i < cbs.length; i++) {
                cbs[i].checked = false;
            }
            updateButtons();
        })
        .catch(function (err) {
            progress.style.display = 'none';
            btnSync.disabled = false;
            resultadosDiv.innerHTML = '<div class="flash flash-error">Error de conexión: ' + htmlspecialchars(err.message) + '</div>';
        });
    });
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
