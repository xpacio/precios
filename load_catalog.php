<?php

require_once __DIR__ . '/config/database.php';

$SUCURSALES_FILE = __DIR__ . '/sucursales.txt';
$PRECIOS_FILE = '/root/scripts/precios.txt.bak';
$PRECIOS_DIR = '/srv/precios';

// --- Cargar sucursales ---
echo "Cargando sucursales desde {$SUCURSALES_FILE}...\n";

$lines = file($SUCURSALES_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$count = 0;
$pdo = getDB();

foreach ($lines as $line) {
    if (preg_match("/^\s*'([a-z0-9]+)'=>\s*'(.+?)'/", $line, $m)) {
        $id = $m[1];
        $nombre = $m[2];
        try {
            $pdo->prepare("INSERT INTO sucursales (id_sucursal, nombre_sucursal) VALUES (?, ?) ON CONFLICT DO NOTHING")
                ->execute([$id, $nombre]);
            $count++;
        } catch (Exception $e) {
            echo "  Error con sucursal '$id': {$e->getMessage()}\n";
        }
    }
}

echo "✓ {$count} sucursales cargadas\n";

// --- Cargar archivos desde precios.txt.bak ---
echo "Cargando archivos desde {$PRECIOS_FILE}...\n";

$lines = file($PRECIOS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$count = 0;
$insertStmt = $pdo->prepare("
    INSERT INTO archivos (path, nombre, ausente)
    VALUES (?, ?, ?)
    ON CONFLICT (path, nombre) DO NOTHING
");

foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line)) continue;

    $parts = explode('/', $line);
    $nombre = array_pop($parts);
    $path = strtoupper(implode('/', $parts));

    $fullPath = $PRECIOS_DIR . '/' . $line;
    $ausente = !file_exists($fullPath);

    $insertStmt->execute([$path, strtoupper($nombre), $ausente ? 'true' : 'false']);
    $count++;
}

echo "✓ {$count} archivos registrados\n";

// --- Resumen ---
echo "\nResumen:\n";
echo "  Sucursales: {$pdo->query('SELECT COUNT(*) FROM sucursales')->fetchColumn()}\n";
echo "  Archivos: {$pdo->query('SELECT COUNT(*) FROM archivos')->fetchColumn()}\n";
echo "  Ausentes: {$pdo->query("SELECT COUNT(*) FROM archivos WHERE ausente = TRUE")->fetchColumn()}\n";
echo "  Presentes: {$pdo->query("SELECT COUNT(*) FROM archivos WHERE ausente = FALSE")->fetchColumn()}\n";
