<?php

require_once __DIR__ . '/config/database.php';

session_start();

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$segments = explode('/', trim($requestUri, '/'));
$baseSeg = $segments[0] ?? null;

// === API Routes ===
if ($baseSeg === 'api' && ($segments[1] ?? null) === 'v1') {

    $controller = $segments[2] ?? null;
    $idSucursal = $segments[3] ?? null;
    $fileName   = $segments[4] ?? null;

    if (in_array($controller, ['sync', 'sync-fast', 'sync-selected', 'auth', 'archivos-fuente-full'], true)) {
        // internal endpoints, no API key required
    } else {
        require_once __DIR__ . '/api/v1/middleware.php';
        requireApiKey();
    }

    $controllerFile = __DIR__ . "/api/v1/{$controller}.php";
    if (file_exists($controllerFile)) {
        require_once $controllerFile;
        exit;
    }

    header('Content-Type: text/plain');
    http_response_code(404);
    echo "ERROR: Controller '$controller' no encontrado";
    exit;
}

// === Dashboard Routes ===
if ($baseSeg === 'dashboard') {

    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit;
    }

    $page = $segments[1] ?? 'index';
    $pageFile = __DIR__ . "/dashboard/{$page}.php";

    if (file_exists($pageFile)) {
        $currentPage = $page;
        require_once $pageFile;
        exit;
    }

    http_response_code(404);
    header('Content-Type: text/plain');
    echo "ERROR: 404 - Pagina no encontrada";
    exit;
}

// === Login / Home ===
if ($requestUri === '/' || $requestUri === '/login') {
    if (isset($_SESSION['user_id'])) {
        header('Location: /dashboard');
        exit;
    }
    require_once __DIR__ . '/public/login.php';
    exit;
}

http_response_code(404);
header('Content-Type: text/plain');
echo "ERROR: 404 - Pagina no encontrada";
