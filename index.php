<?php

/**
 * Router simple para la API de Precios
 * Estructura de URI: /api/v1/controlador/idSucursal/fileName
 */

// Iniciar sesión para la interfaz web
session_start();

// Obtener la ruta limpia de la petición
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$segments = explode('/', trim($requestUri, '/'));

// Validar el prefijo de la API (api/v1)
if (isset($segments[0]) && $segments[0] === 'api' && isset($segments[1]) && $segments[1] === 'v1') {
    
    // Extraer los segmentos según el esquema solicitado
    $controller = $segments[2] ?? null;
    $idSucursal = $segments[3] ?? null;
    $fileName   = $segments[4] ?? null;

    // Controlador incrustado: status
    if ($controller === 'status') {
        header('status: OK');
        header('Content-Type: text/plain');
        http_response_code(200);
        echo "OK";
        exit;
    }

    // Carga dinámica de controladores externos
    $controllerFile = __DIR__ . "/controllers/{$controller}.php";
    if (file_exists($controllerFile)) {
        require_once $controllerFile;
        exit;
    }

    header('Content-Type: text/plain');
    echo "Controller: $controller\n";
    echo "Sucursal: $idSucursal\n";
    echo "File: $fileName\n";

} elseif ($requestUri === '/' || $requestUri === '/login') {
    // Si el usuario ya está logueado, redirigir al panel (que crearemos luego)
    if (isset($_SESSION['user_id'])) {
        header('Location: /dashboard');
        exit;
    }
    require_once __DIR__ . '/login.php';
} else {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "ERROR: 404 - Pagina no encontrada";
}
