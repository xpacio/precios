<div id="tab-eliminar" class="tab-content" style="display:none;">
    <div style="display:flex;gap:0.75rem;align-items:center;">
        <div style="flex:1;">
            <label for="q-del">Buscar archivo para eliminar (nombre o ruta)</label>
            <input type="text" id="q-del" minlength="2" placeholder="Escribe al menos 2 caracteres...">
        </div>
        <label style="white-space:nowrap;margin-top:1.5rem;">
            <input type="checkbox" id="confirm-del">
            Confirmar eliminación
        </label>
    </div>

    <div id="del-archivos-results" style="margin-top:0.5rem;">
        <p>Ingresa al menos 2 caracteres para buscar archivos.</p>
    </div>

    <div id="mensaje-del" style="display:none;margin-bottom:1rem;"></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var inputDel = document.getElementById('q-del');
    var delResults = document.getElementById('del-archivos-results');
    var mensajeDel = document.getElementById('mensaje-del');
    var delTimer;

    function escapar(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function mostrarMensajeDel(tipo, texto) {
        mensajeDel.style.display = 'block';
        mensajeDel.className = 'flash flash-' + tipo;
        mensajeDel.innerHTML = texto;
        setTimeout(function () { mensajeDel.style.display = 'none'; }, 5000);
    }

    function renderDelArchivos(data) {
        if (data.total === 0) {
            delResults.innerHTML = '<p>No se encontraron archivos para "<strong>' + escapar(inputDel.value) + '</strong>".</p>';
            return;
        }
        var html = '<p>' + data.total + ' resultado(s) (máx. 50).</p>';
        html += '<div class="table-container"><table><thead><tr><th>Ruta</th><th>Archivo</th><th>Peso</th><th>Modificado</th><th>Status</th><th>Acción</th></tr></thead><tbody>';

        for (var i = 0; i < data.results.length; i++) {
            var f = data.results[i];
            html += '<tr>';
            html += '<td style="font-size:0.85rem;color:#666;">' + escapar(f.ruta.replace('/srv/precios/', '')) + '</td>';
            html += '<td>' + escapar(f.nombre) + '</td>';
            html += '<td>' + (f.peso ? escapar(f.peso) : '-') + '</td>';
            html += '<td style="font-size:0.85rem;">' + fmtFecha(f.fecha_archivo) + ' (' + timeago(f.fecha_archivo) + ')' + '</td>';
            html += '<td>' + escapar(f.status ?? '-') + '</td>';
            html += '<td><button class="secondary outline btn-eliminar" data-id="' + f.id + '" data-nombre="' + escapar(f.nombre) + '" style="padding:0.25rem 0.5rem;font-size:0.85rem;">Eliminar</button></td>';
            html += '</tr>';
        }
        html += '</tbody></table></div>';
        delResults.innerHTML = html;

        var confirmCheck = document.getElementById('confirm-del');

        document.querySelectorAll('.btn-eliminar').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirmCheck.checked) {
                    mostrarMensajeDel('error', 'Marca "Confirmar eliminación" para habilitar la eliminación.');
                    return;
                }

                var id = this.dataset.id;
                this.disabled = true;
                this.textContent = 'Eliminando...';

                var formData = new FormData();
                formData.append('action', 'eliminar');
                formData.append('archivo_id', id);

                fetch('/dashboard/archivos', { method: 'POST', body: formData })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.ok) {
                            btn.closest('tr').remove();
                        } else {
                            mostrarMensajeDel('error', data.error || 'Error al eliminar');
                            btn.disabled = false;
                            btn.textContent = 'Eliminar';
                        }
                    })
                    .catch(function () {
                        mostrarMensajeDel('error', 'Error de conexión.');
                        btn.disabled = false;
                        btn.textContent = 'Eliminar';
                    });
            });
        });
    }

    function searchDelArchivos() {
        var q = inputDel.value.trim();
        if (q.length < 2) {
            delResults.innerHTML = '<p>Ingresa al menos 2 caracteres para buscar archivos.</p>';
            return;
        }
        fetch('?type=archivos-eliminar&q=' + encodeURIComponent(q) + '&ajax=1')
            .then(function (r) { return r.json(); })
            .then(renderDelArchivos)
            .catch(function () { delResults.innerHTML = '<p style="color:red">Error al buscar.</p>'; });
    }

    inputDel.addEventListener('input', function () {
        clearTimeout(delTimer);
        delTimer = setTimeout(searchDelArchivos, 300);
    });
});
</script>
