<?php

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Use POST']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$nickname = strtoupper(trim($input['nickname'] ?? ''));
$password = $input['password'] ?? '';
$sucursalId = $input['sucursal_id'] ?? '';

if (empty($nickname) || empty($password) || empty($sucursalId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Faltan campos']);
    exit;
}

if ($password === '' || strtolower($password) === 'null') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Clave DBD invalida.']);
    exit;
}

try {
    $pdo = getDB();

    if ($nickname === 'GTE') {
        $stmt = $pdo->prepare("SELECT clave_dbd FROM sucursales WHERE id_sucursal = ? AND clave_dbd IS NOT NULL");
        $stmt->execute([$sucursalId]);
        $suc = $stmt->fetch();
        if (!$suc || $suc['clave_dbd'] !== $password) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Clave DBD incorrecta.']);
            exit;
        }
    } else {
        $stmt = $pdo->prepare("SELECT id, password FROM usuarios WHERE UPPER(nickname) = ? AND enabled = TRUE AND can_dsblind = TRUE");
        $stmt->execute([$nickname]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password'])) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Usuario o clave incorrecta.']);
            exit;
        }
    }

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
