<?php

function requireApiKey(): void
{
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

    if (empty($apiKey)) {
        http_response_code(401);
        header('Content-Type: text/plain');
        echo "ERROR: API Key requerida (header X-API-Key)";
        exit;
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, usuario_id FROM api_keys WHERE api_key = ? AND enabled = TRUE");
        $stmt->execute([$apiKey]);
        $key = $stmt->fetch();

        if (!$key) {
            http_response_code(403);
            header('Content-Type: text/plain');
            echo "ERROR: API Key invalida o deshabilitada";
            exit;
        }
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: text/plain');
        echo "ERROR: Error de autenticacion";
        exit;
    }
}
