<?php

/**
 * Endpoint: POST /api/v1/auth
 * Procesa el formulario de login.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain');
    echo "ERROR: Metodo no permitido. Use POST.";
    exit;
}

$nickname = $_POST['nickname'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($nickname) || empty($password)) {
    header('Location: /login?error=campos_vacios');
    exit;
}

try {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, nombre, password, enabled FROM usuarios WHERE nickname = ?");
    $stmt->execute([$nickname]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        header('Location: /login?error=invalid_credentials');
        exit;
    }

    if (!$user['enabled']) {
        header('Location: /login?error=usuario_deshabilitado');
        exit;
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_nombre'] = $user['nombre'];

    header('Location: /dashboard');
    exit;

} catch (Exception $e) {
    header('Location: /login?error=server_error');
    exit;
}
