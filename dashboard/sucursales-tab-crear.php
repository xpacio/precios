<div id="tab-crear" class="tab-content" style="display:none;">
    <?php if ($error && ($_POST['action'] ?? '') === 'crear'): ?>
        <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <article>
        <header><strong>Nueva Sucursal</strong></header>
        <form method="POST" action="/dashboard/sucursales">
            <input type="hidden" name="action" value="crear">
            <div class="grid">
                <label>
                    ID (solo minúsculas y números)
                    <input type="text" name="nuevo_id" pattern="[a-z0-9]+" required placeholder="ej. suc001" value="<?= $nuevoIdValue ?? '' ?>">
                </label>
                <label>
                    Nombre
                    <input type="text" name="nuevo_nombre" required placeholder="ej. Sucursal Centro" value="<?= $nuevoNombreValue ?? '' ?>">
                </label>
            </div>
            <button type="submit">Crear Sucursal</button>
        </form>
    </article>
</div>
