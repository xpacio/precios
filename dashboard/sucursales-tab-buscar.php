<div id="tab-buscar" class="tab-content">
    <label for="q">Buscar sucursal (código o nombre)</label>
    <input type="text" name="q" id="q" value="<?= htmlspecialchars($search ?? '') ?>" minlength="2" placeholder="Escribe al menos 2 caracteres..." autofocus>
    <div id="sucursales-results">
        <p>Ingresa al menos 2 caracteres para buscar sucursales.</p>
    </div>
</div>
