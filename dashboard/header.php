<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?> - Precios API</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <style>
        html { font-size: 100%; }
        @media (min-width: 1600px) { html { font-size: 112.5%; } }
        main .container { max-width: min(90%, 1200px); }
        :root {
            --pico-spacing: 0.75rem;
            --pico-block-spacing-vertical: 0.75rem;
            --pico-block-spacing-horizontal: 0.75rem;
            --pico-typography-spacing-vertical: 0.5rem;
            --pico-form-element-spacing-vertical: 0.5rem;
            --pico-form-element-spacing-horizontal: 0.75rem;
            --pico-nav-element-spacing-vertical: 0.5rem;
        }
        h1, h2, h3, h4 { --pico-typography-spacing-top: 1.5rem; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem; margin-bottom: 1.5rem; }
        .stat-card { text-align: center; }
        .stat-card h3 { font-size: 2.5rem; margin: 0; }
        nav a[class="contrast"] { text-decoration: underline; }
        .actions { display: flex; gap: 0.5rem; }
        .actions form { margin: 0; }
        .table-container { overflow-x: auto; }
        .flash { padding: 0.5rem 1rem; border-radius: 4px; margin-bottom: 1rem; }
        .flash-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .flash-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .badge-ok { color: #2e7d32; font-weight: bold; }
        .badge-warn { color: #e65100; font-weight: bold; }
        .badge-err { color: #c62828; font-weight: bold; }
        .cambiado { background: #fff3e0; }
        .ausente { opacity: 0.5; }
        .group-header { cursor: pointer; background: #f5f5f5; font-weight: bold; }
        .group-header:hover { background: #e0e0e0; }
        .tabs { margin-bottom: 0; }
        .tabs a[data-tab] { cursor: pointer; }
        .tab-content { margin-top: 1rem; }
    </style>
    <script>
        document.addEventListener('click', function(e) {
            const header = e.target.closest('.group-header');
            if (header) {
                const next = header.nextElementSibling;
                while (next && !next.classList.contains('group-header')) {
                    next.style.display = next.style.display === 'none' ? '' : 'none';
                    next = next.nextElementSibling;
                }
            }
        });
    </script>
</head>
<body>
    <header class="container">
        <nav>
            <ul>
                <li><strong>Precios API</strong></li>
            </ul>
            <ul>
                <li><a href="/dashboard" class="<?= $currentPage === 'index' ? 'contrast' : '' ?>">Inicio</a></li>
                <li><a href="/dashboard/archivos" class="<?= $currentPage === 'archivos' ? 'contrast' : '' ?>">Archivos</a></li>
                <li><a href="/dashboard/sucursales" class="<?= $currentPage === 'sucursales' ? 'contrast' : '' ?>">Sucursales</a></li>
                <li><a href="/dashboard/sync" class="<?= $currentPage === 'sync' ? 'contrast' : '' ?>">Sincronización</a></li>
                <li><a href="/dashboard/usuarios" class="<?= $currentPage === 'usuarios' ? 'contrast' : '' ?>">Usuarios</a></li>
                <li><a href="/dashboard/logout">Salir</a></li>
            </ul>
        </nav>
    </header>
    <main class="container">
