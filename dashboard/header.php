<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?> - Precios API</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <style>
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { text-align: center; }
        .stat-card h3 { font-size: 2.5rem; margin: 0; }
        nav a[class="contrast"] { text-decoration: underline; }
        .actions { display: flex; gap: 0.5rem; }
        .actions form { margin: 0; }
        .table-container { overflow-x: auto; }
        .flash { padding: 0.5rem 1rem; border-radius: 4px; margin-bottom: 1rem; }
        .flash-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .flash-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
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
                <li><a href="/dashboard/usuarios" class="<?= $currentPage === 'usuarios' ? 'contrast' : '' ?>">Usuarios</a></li>
                <li><a href="/dashboard/logout">Salir</a></li>
            </ul>
        </nav>
    </header>
    <main class="container">
