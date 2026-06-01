<div id="tab-asociar" class="tab-content" style="display:none;">
    <?php $sucursalFilter = trim($_GET['sucursal'] ?? ''); ?>
    <?php if ($sucursalFilter): ?>
        <p><a href="/dashboard/sucursales?sucursal=<?= urlencode($sucursalFilter) ?>" class="secondary">&larr; <?= htmlspecialchars($sucursalFilter) ?></a></p>
    <?php endif; ?>
    <p>Total: <?= $totalArchivos ?> archivos</p>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
        <div>
            <label for="q">Buscar archivo (nombre o ruta)</label>
            <input type="text" id="q" minlength="3" placeholder="Escribe al menos 3 caracteres..." autofocus>
            <div id="archivos-results" style="margin-top:0.5rem;">
                <p>Ingresa al menos 3 caracteres para buscar archivos.</p>
            </div>
        </div>
        <div>
            <label for="qs">Buscar sucursal</label>
            <input type="text" id="qs" minlength="3" placeholder="Escribe al menos 3 caracteres...">
            <div id="sucursales-results" style="margin-top:0.5rem;">
                <p>Ingresa al menos 3 caracteres para buscar sucursales.</p>
            </div>
        </div>
    </div>

    <div style="text-align:center;margin:1.5rem 0;">
        <button id="btn-relacionar" class="contrast" style="padding:0.6rem 2rem;font-size:1.1rem;">Relacionar seleccionados</button>
    </div>

    <div id="mensaje" style="display:none;margin-bottom:1rem;"></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('q');
    var inputSuc = document.getElementById('qs');
    var archivosResults = document.getElementById('archivos-results');
    var sucResults = document.getElementById('sucursales-results');
    var btnRelacionar = document.getElementById('btn-relacionar');
    var mensaje = document.getElementById('mensaje');
    var sucursalAuto = new URLSearchParams(window.location.search).get('sucursal');
    var archivoIdAuto = new URLSearchParams(window.location.search).get('id');
    var archivoQAuto = new URLSearchParams(window.location.search).get('q');
    var timer, sucTimer;

    function escapar(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function mostrarMensaje(tipo, texto) {
        mensaje.style.display = 'block';
        mensaje.className = 'flash flash-' + tipo;
        mensaje.innerHTML = texto;
        setTimeout(function () { mensaje.style.display = 'none'; }, 5000);
    }

    function renderSucursales(data) {
        if (data.total === 0) {
            sucResults.innerHTML = '<p>No se encontraron sucursales para "<strong>' + escapar(inputSuc.value) + '</strong>".</p>';
            return;
        }
        var html = '<p>' + data.total + ' resultado(s) (máx. 20).</p>';
        html += '<div class="table-container"><table><thead><tr><th>Código</th><th>Nombre</th><th>Sel.</th></tr></thead><tbody>';
        for (var si = 0; si < data.results.length; si++) {
            var s = data.results[si];
            var checked = sucursalAuto && s.id_sucursal === sucursalAuto ? ' checked' : '';
            html += '<tr>';
            html += '<td><code>' + escapar(s.id_sucursal) + '</code></td>';
            html += '<td><a href="/dashboard/sucursales?sucursal=' + encodeURIComponent(s.id_sucursal) + '">' + escapar(s.nombre_sucursal) + '</a></td>';
            html += '<td><input type="checkbox" class="suc-check" value="' + escapar(s.id_sucursal) + '"' + checked + '></td>';
            html += '</tr>';
        }
        html += '</tbody></table></div>';
        sucResults.innerHTML = html;
    }

    function renderArchivos(data) {
        if (data.total === 0) {
            archivosResults.innerHTML = '<p>No se encontraron archivos para "<strong>' + escapar(input.value) + '</strong>".</p>';
            return;
        }
        var html = '<p>' + data.total + ' resultado(s) (máx. 50).</p>';
        html += '<p style="margin-bottom:0.5rem;"><button class="secondary outline" id="btn-select-all" style="padding:0.25rem 0.75rem;font-size:0.85rem;" type="button">Seleccionar todos</button></p>';
        html += '<div class="table-container"><table><thead><tr><th>Ruta</th><th>Archivo</th><th>Modificado</th><th>Sel.</th></tr></thead><tbody>';

        for (var fi = 0; fi < data.results.length; fi++) {
            var f = data.results[fi];
            var isMatch = archivoIdAuto && String(f.id) === archivoIdAuto;
            html += '<tr>';
            html += '<td style="font-size:0.85rem;color:#666;">' + escapar(f.ruta.replace('/srv/precios/', '')) + '</td>';
            html += '<td>' + escapar(f.nombre) + '</td>';
            html += '<td style="font-size:0.85rem;">' + fmtFecha(f.fecha_archivo) + ' (' + timeago(f.fecha_archivo) + ')' + '</td>';
            html += '<td><input type="checkbox" class="arch-check" value="' + f.id + '"' + (isMatch ? ' checked' : '') + '></td>';
            html += '</tr>';
        }
        html += '</tbody></table></div>';
        archivosResults.innerHTML = html;

        var btnSelectAll = document.getElementById('btn-select-all');
        if (btnSelectAll) {
            btnSelectAll.addEventListener('click', function () {
                var checkboxes = document.querySelectorAll('.arch-check');
                var allChecked = Array.from(checkboxes).every(function (cb) { return cb.checked; });
                checkboxes.forEach(function (cb) { cb.checked = !allChecked; });
                btnSelectAll.textContent = allChecked ? 'Seleccionar todos' : 'Deseleccionar todos';
            });
        }
    }

    function searchSucursales() {
        var q = inputSuc.value.trim();
        if (q.length < 3) { sucResults.innerHTML = '<p>Ingresa al menos 3 caracteres para buscar sucursales.</p>'; return; }
        fetch('?type=sucursales&q=' + encodeURIComponent(q) + '&ajax=1')
            .then(function (r) { return r.json(); })
            .then(renderSucursales)
            .catch(function () { sucResults.innerHTML = '<p style="color:red">Error al buscar.</p>'; });
    }

    function searchArchivos() {
        var q = input.value.trim();
        var url;
        if (archivoIdAuto) {
            url = '?id=' + encodeURIComponent(archivoIdAuto) + '&ajax=1';
        } else {
            if (q.length < 3) { archivosResults.innerHTML = '<p>Ingresa al menos 3 caracteres para buscar archivos.</p>'; return; }
            url = '?q=' + encodeURIComponent(q) + '&ajax=1';
        }
        fetch(url)
            .then(function (r) { return r.json(); })
            .then(renderArchivos)
            .catch(function () { archivosResults.innerHTML = '<p style="color:red">Error al buscar.</p>'; });
    }

    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(searchArchivos, 300); });
    inputSuc.addEventListener('input', function () { clearTimeout(sucTimer); sucTimer = setTimeout(searchSucursales, 300); });

    if (sucursalAuto) { inputSuc.value = sucursalAuto; searchSucursales(); }
    if (archivoIdAuto) { if (archivoQAuto) input.value = archivoQAuto; searchArchivos(); }

    btnRelacionar.addEventListener('click', function () {
        var archivosSel = Array.from(document.querySelectorAll('.arch-check:checked')).map(function (cb) { return cb.value; });
        var sucursalesSel = Array.from(document.querySelectorAll('.suc-check:checked')).map(function (cb) { return cb.value; });

        if (archivosSel.length === 0 || sucursalesSel.length === 0) {
            mostrarMensaje('error', 'Selecciona al menos un archivo y una sucursal.');
            return;
        }

        btnRelacionar.disabled = true;
        btnRelacionar.textContent = 'Relacionando...';

        var formData = new FormData();
        formData.append('action', 'relacionar');
        for (var ai = 0; ai < archivosSel.length; ai++) formData.append('archivo_ids[]', archivosSel[ai]);
        for (var si = 0; si < sucursalesSel.length; si++) formData.append('sucursal_ids[]', sucursalesSel[si]);

        fetch('/dashboard/archivos', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) {
                    var msg = 'Relación guardada: ' + data.inserted + ' insertadas';
                    if (data.skipped > 0) msg += ', ' + data.skipped + ' ya existían';
                    if (data.errors && data.errors.length > 0) msg += '. Errores: ' + data.errors.join(', ');
                    mostrarMensaje('success', msg);
                } else {
                    mostrarMensaje('error', data.error || 'Error al relacionar');
                }
            })
            .catch(function () { mostrarMensaje('error', 'Error de conexión.'); })
            .finally(function () {
                btnRelacionar.disabled = false;
                btnRelacionar.textContent = 'Relacionar seleccionados';
            });
    });
});
</script>
