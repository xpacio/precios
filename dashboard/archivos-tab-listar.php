<div id="tab-listar" class="tab-content">
    <div id="listar-info" style="margin-bottom:0.5rem;">
        <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
            <span id="listar-total"></span>
            <input type="text" id="q-listar" minlength="2" placeholder="Buscar archivo (nombre o ruta)..." style="max-width:300px;">
        </div>
    </div>
    <div class="table-container" id="listar-table-container">
        <p>Cargando archivos...</p>
    </div>
    <div id="listar-pagination" style="display:flex;gap:0.5rem;justify-content:center;margin-top:1rem;"></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var listarPage = 1;
    var listarSort = 'ruta';

    function loadListar(page) {
        listarPage = page;
        var container = document.getElementById('listar-table-container');
        var pagination = document.getElementById('listar-pagination');
        var q = document.getElementById('q-listar').value.trim();

        container.innerHTML = '<p>Cargando...</p>';

        var url = '?type=archivos-listar&sort=' + encodeURIComponent(listarSort) + '&page=' + page + '&ajax=1';
        if (q.length >= 2) url += '&q=' + encodeURIComponent(q);

        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var totalPages = Math.ceil(data.total / data.perPage);
                var nextSort = (listarSort === 'fecha_archivo') ? 'ruta' : 'fecha_archivo';
                var nextLabel = (nextSort === 'fecha_archivo') ? 'Modificado' : 'Ruta';
                document.getElementById('listar-total').innerHTML =
                    'Total: <strong>' + data.total + '</strong> archivos (pág. ' + data.page + ' de ' + totalPages + ') ' +
                    '<button class="secondary outline" id="btn-toggle-sort" style="padding:0.2rem 0.6rem;font-size:0.8rem;vertical-align:middle;" type="button">Ordenar por ' + nextLabel + ' ▾</button>';

                if (data.total === 0) {
                    container.innerHTML = '<p>No hay archivos.</p>';
                    pagination.innerHTML = '';
                    return;
                }

                var rutaArrow = (listarSort === 'ruta') ? ' ▾' : '';
                var fechaArrow = (listarSort === 'fecha_archivo') ? ' ▾' : '';
                var html = '<table><thead><tr><th>Ruta' + rutaArrow + '</th><th>Archivo</th><th>Peso</th><th>Desc</th><th>fl</th><th>br</th><th>Comp.</th><th>Disp</th><th>Modificado' + fechaArrow + '</th><th>Carga</th><th>Status</th><th>Tipo</th><th>Activo</th></tr></thead><tbody>';
                for (var i = 0; i < data.results.length; i++) {
                    var f = data.results[i];
                    var dispPct = f.total_suc > 0 ? Math.round(f.sync_suc / f.total_suc * 100) : -1;
                    var rowStyle = '';
                    if (dispPct === 100) rowStyle = ' style="background:rgba(0,200,0,0.04);"';
                    else if (dispPct > 0) rowStyle = ' style="background:rgba(200,180,0,0.06);"';
                    html += '<tr' + rowStyle + '>';
                    html += '<td style="font-size:0.85rem;color:#666;">' + escapeHtml(f.ruta.replace('/srv/precios/', '')) + '</td>';
                    html += '<td><a href="/dashboard/archivo-editar?id=' + f.id + '">' + escapeHtml(f.nombre) + '</a></td>';
                    html += '<td>' + (f.peso ? escapeHtml(f.peso) : '-') + '</td>';
                    html += '<td>' + (f.n_descargas != null ? f.n_descargas : '0') + '</td>';
                    html += '<td style="font-family:monospace;font-size:0.8rem;">' + (f.flat ? escapeHtml(f.flat.substring(0, 3)) : '-') + '</td>';
                    html += '<td style="font-family:monospace;font-size:0.8rem;">' + (f.br ? escapeHtml(f.br.substring(0, 3)) : '-') + '</td>';
                    html += '<td>' + (f.compr_pct != null ? escapeHtml(f.compr_pct) + '%' : '-') + '</td>';
                    html += '<td style="font-family:monospace;font-size:0.8rem;';
                    if (dispPct === 100) html += 'color:limegreen;font-weight:bold;';
                    else if (dispPct > 0) html += 'color:#c8a000;font-weight:bold;';
                    html += '">';
                    if (f.total_suc > 0) {
                        html += dispPct + '% ' + f.sync_suc + '/' + f.total_suc;
                    } else {
                        html += '<a href="/dashboard/archivos?tab=asociar&id=' + f.id + '&q=' + encodeURIComponent(f.nombre) + '" style="text-decoration:none;color:#888;" title="Asociar a sucursal">+</a>';
                    }
                    html += '</td>';
                    html += '<td style="font-size:0.85rem;">' + fmtFecha(f.fecha_archivo) + ' (' + timeago(f.fecha_archivo) + ')' + '</td>';
                    html += '<td style="font-size:0.85rem;">' + fmtFecha(f.fecha_carga) + ' (' + timeago(f.fecha_carga) + ')' + '</td>';
                    html += '<td>' + (f.status === 'ausente' ? '<span style="color:#e65100;font-weight:bold;">Ausente</span>' : escapeHtml(f.status || '-')) + '</td>';
                    html += '<td style="text-align:center;">' +
                      (f.ruta.indexOf('DSBLIND') !== -1
                        ? '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#bf616a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2l0 -6"/><path d="M11 16a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M8 11v-5a4 4 0 0 1 8 0"/></svg>'
                        : '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#5e81ac" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6"/><path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0"/><path d="M8 11v-4a4 4 0 1 1 8 0v4"/></svg>') +
                    '</td>';
                    html += '<td><input type="checkbox" class="toggle-enabled" data-id="' + f.id + '"' + (f.enabled ? ' checked' : '') + '></td>';
                    html += '</tr>';
                }
                html += '</tbody></table>';
                container.innerHTML = html;

                var pagHtml = '';
                if (data.page > 1) {
                    pagHtml += '<button class="secondary outline" data-page="' + (data.page - 1) + '" style="padding:0.25rem 0.75rem;">&laquo; Anterior</button>';
                }
                pagHtml += '<span style="padding:0.25rem 0.75rem;">Pág. ' + data.page + ' de ' + totalPages + '</span>';
                if (data.page < totalPages) {
                    pagHtml += '<button class="secondary outline" data-page="' + (data.page + 1) + '" style="padding:0.25rem 0.75rem;">Siguiente &raquo;</button>';
                }
                pagination.innerHTML = pagHtml;

                pagination.querySelectorAll('button').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        loadListar(parseInt(this.dataset.page));
                    });
                });

                var sortBtn = document.getElementById('btn-toggle-sort');
                if (sortBtn) {
                    sortBtn.addEventListener('click', function () {
                        listarSort = (listarSort === 'fecha_archivo') ? 'ruta' : 'fecha_archivo';
                        loadListar(1);
                    });
                }

                container.querySelectorAll('.toggle-enabled').forEach(function (cb) {
                    cb.addEventListener('change', function () {
                        var id = this.dataset.id;
                        var enabled = this.checked;
                        var formData = new FormData();
                        formData.append('action', 'toggle-enabled');
                        formData.append('id', id);
                        formData.append('enabled', enabled ? '1' : '');
                        fetch('/dashboard/archivos', { method: 'POST', body: formData })
                            .then(function (r) { return r.json(); })
                            .then(function (data) {
                                if (!data.ok) cb.checked = !enabled;
                            })
                            .catch(function () { cb.checked = !enabled; });
                    });
                });
            })
            .catch(function () {
                container.innerHTML = '<p style="color:red">Error al cargar archivos.</p>';
            });
    }

    document.querySelector('.tabs a[data-tab="listar"]').addEventListener('click', function () {
        loadListar(1);
    });

    var listarSearchTimer;
    var qListar = document.getElementById('q-listar');
    if (qListar) {
        qListar.addEventListener('input', function () {
            clearTimeout(listarSearchTimer);
            listarSearchTimer = setTimeout(function () { loadListar(1); }, 300);
        });
    }

});
</script>
