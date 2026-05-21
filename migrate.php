<?php

$dsn = "pgsql:host=localhost;port=5432;dbname=precios";
$pdo = new PDO($dsn, "postgres", "password", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

echo "Migrando esquema de base de datos...\n";

$pdo->exec("DROP TABLE IF EXISTS archivo_sucursal CASCADE");
$pdo->exec("DROP TABLE IF EXISTS archivos CASCADE");
$pdo->exec("DROP TABLE IF EXISTS api_keys CASCADE");
$pdo->exec("DROP TABLE IF EXISTS sucursales CASCADE");
$pdo->exec("DROP TABLE IF EXISTS usuarios CASCADE");

$sql = file_get_contents(__DIR__ . '/DDL.sql');
$pdo->exec($sql);

echo "✓ Esquema migrado exitosamente\n";
echo "  - usuarios: {$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn()}\n";
echo "  - api_keys: {$pdo->query('SELECT COUNT(*) FROM api_keys')->fetchColumn()}\n";
echo "  - sucursales: {$pdo->query('SELECT COUNT(*) FROM sucursales')->fetchColumn()}\n";
echo "  - archivos: {$pdo->query('SELECT COUNT(*) FROM archivos')->fetchColumn()}\n";
echo "  - archivo_sucursal: {$pdo->query('SELECT COUNT(*) FROM archivo_sucursal')->fetchColumn()}\n";
