<div id="tab-registrar" class="tab-content" style="display:none;">
    <article>
        <header><strong>Registrar Nuevo Archivo</strong></header>
        <form id="registrar-form" method="POST" action="/dashboard/archivos">
            <input type="hidden" name="action" value="registrar">
            <div class="grid">
                <label>
                    Ruta
                    <input type="text" name="ruta" required placeholder="CHAPAS/ENVIAR">
                </label>
                <label>
                    Nombre
                    <input type="text" name="nombre" required placeholder="LISTA.CDX">
                </label>

            </div>
            <button type="submit">Registrar Archivo</button>
        </form>
        <div id="registrar-mensaje" style="display:none;margin-top:1rem;"></div>
        <progress id="registrar-progress" style="display:none;margin-top:1rem;width:100%;"></progress>
        <pre id="registrar-log" style="display:none;margin-top:0.5rem;background:var(--card-background-color);padding:0.75rem;border-radius:var(--border-radius);font-size:0.8rem;max-height:200px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;"></pre>
    </article>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var registrarForm = document.getElementById('registrar-form');
    if (registrarForm) {
        registrarForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var progress = document.getElementById('registrar-progress');
            var logEl = document.getElementById('registrar-log');
            var msgEl = document.getElementById('registrar-mensaje');

            msgEl.style.display = 'none';
            msgEl.textContent = '';
            progress.style.display = 'block';
            logEl.style.display = 'block';
            logEl.textContent = 'Sincronizando...';

            var formData = new FormData(registrarForm);
            fetch('/dashboard/archivos', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                progress.style.display = 'none';
                logEl.textContent = (data.log || []).join('\n');
                msgEl.style.display = 'block';
                if (data.status === 'OK') {
                    msgEl.className = 'flash flash-success';
                } else {
                    msgEl.className = 'flash flash-warning';
                }
                msgEl.textContent = data.mensaje || '';
            })
            .catch(function () {
                progress.style.display = 'none';
                logEl.textContent = 'Error de conexión al servidor.';
                msgEl.style.display = 'block';
                msgEl.className = 'flash flash-warning';
                msgEl.textContent = 'Error inesperado.';
            });
        });
    }
});
</script>
