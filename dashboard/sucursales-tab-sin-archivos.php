<div id="tab-sin-archivos" class="tab-content" style="display:none;">
    <h2>Sucursales sin archivos asociados</h2>
    <?php if (empty($sinArchivos)): ?>
        <p>Todas las sucursales tienen al menos un archivo asociado.</p>
    <?php else: ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Estado</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sinArchivos as $s):
                        $enabled = ($s['enabled'] === 't' || $s['enabled'] === true);
                    ?>
                        <tr>
                            <td><code><?= htmlspecialchars($s['id_sucursal']) ?></code></td>
                            <td><?= htmlspecialchars($s['nombre_sucursal']) ?></td>
                            <td><span style="color:<?= $enabled ? 'green">Activa' : 'red">Inactiva' ?></span></td>
                            <td>
                                <a href="/dashboard/archivos?tab=asociar&sucursal=<?= urlencode($s['id_sucursal']) ?>" role="button" class="secondary outline" style="padding:0.25rem 0.5rem;">Asociar archivos</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
