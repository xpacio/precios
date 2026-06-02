#!/usr/bin/env php
<?php
/**
 * Pre-Release Smoke Test
 * 
 * Usage: php scripts/release-check.sh
 * 
 * Verifica que el sistema funcione antes de taggear un release:
 *  1. Conexion BD
 *  2. API endpoints clave
 *  3. Archivos listos para servir
 *  4. Integridad .br vs DB
 *  5. Sincronizacion basica
 *  6. Cliente zcli compilado
 */

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    $icon = $ok ? '[OK]' : '[FAIL]';
    echo " $icon $label";
    if (!$ok && $detail) echo " — $detail";
    echo "\n";
    if ($ok) $pass++; else $fail++;
}

function apiGet(string $url): ?array {
    $ctx = stream_context_create(['http' => ['header' => "X-API-Key: precios_api_key_2024\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;
    return json_decode($body, true);
}

echo "=== Pre-Release Smoke Test ===\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Database
try {
    require_once __DIR__ . '/../config/database.php';
    $pdo = getDB();
    $pdo->query('SELECT 1');
    check('Conexion BD', true);
} catch (Exception $e) {
    check('Conexion BD', false, $e->getMessage());
    echo "\nError fatal en BD, abortando.\n";
    exit(1);
}

// 2. Tablas requeridas
$tables = ['archivos', 'sucursales', 'archivo_sucursal', 'usuarios', 'cli_log', 'sync_log', 'archivo_log', 'api_keys'];
foreach ($tables as $t) {
    $ok = (bool)$pdo->query("SELECT to_regclass('$t')")->fetchColumn();
    check("Tabla $t", (bool)$ok);
}

// 3. API Keys activas
$keys = $pdo->query("SELECT COUNT(*) FROM api_keys WHERE enabled = TRUE")->fetchColumn();
check("API Keys activas ($keys)", $keys > 0);

// 4. Usuarios con permisos basicos
$users = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE enabled = TRUE")->fetchColumn();
check("Usuarios activos ($users)", $users > 0);

$dsblind = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE can_dsblind = TRUE")->fetchColumn();
check("Usuarios con DBD ($dsblind)", $dsblind > 0);

// 5. Sucursales activas
$sucs = $pdo->query("SELECT COUNT(*) FROM sucursales WHERE enabled = TRUE")->fetchColumn();
check("Sucursales activas ($sucs)", $sucs > 0);

// 6. Archivos registrados
$totalArchivos = $pdo->query("SELECT COUNT(*) FROM archivos")->fetchColumn();
$readyArchivos = $pdo->query("SELECT COUNT(*) FROM archivos WHERE status = 'ready'")->fetchColumn();
$brArchivos   = $pdo->query("SELECT COUNT(*) FROM archivos WHERE br IS NOT NULL AND br != ''")->fetchColumn();
check("Archivos registrados ($totalArchivos)", $totalArchivos > 0);
check("Archivos ready ($readyArchivos de $totalArchivos)", $readyArchivos > 0);
check("Archivos con hash br ($brArchivos)", $brArchivos == $readyArchivos);

// 7. Archivos sin .br en disco (verificacion fisica)
$missing = 0;
$rows = $pdo->query("SELECT ruta, nombre FROM archivos WHERE status = 'ready'")->fetchAll();
foreach ($rows as $r) {
    $brPath = "/srv/precios/{$r['ruta']}/{$r['nombre']}.br";
    if (!file_exists($brPath)) $missing++;
}
check("Archivos ready sin .br en disco", $missing === 0, "$missing archivos faltantes");

// 8. Asociaciones archivo-sucursal activas
$asoc = $pdo->query("SELECT COUNT(*) FROM archivo_sucursal WHERE enabled = TRUE")->fetchColumn();
check("Asociaciones activas ($asoc)", $asoc > 0);

// 9. API REST — pending
$apiBase = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/api/v1';
$testSuc = $pdo->query("SELECT id_sucursal FROM sucursales WHERE enabled = TRUE LIMIT 1")->fetchColumn();
if ($testSuc) {
    $data = apiGet("$apiBase/pending/$testSuc");
    check("GET /api/v1/pending/$testSuc", $data !== null && ($data['status'] ?? '') === 'OK', 'No response');
    if ($data) check("  Archivos pendientes: {$data['pendientes']}", $data['pendientes'] >= 0);
}

// 10. API REST — files
if ($testSuc) {
    $data = apiGet("$apiBase/files/$testSuc");
    check("GET /api/v1/files/$testSuc", $data !== null, 'No response');
}

// 11. .br files accesibles via read (prueba de integridad)
$testFile = $pdo->query("
    SELECT a.id, a.ruta, a.nombre, a.br
    FROM archivo_sucursal asu
    JOIN archivos a ON a.id = asu.archivo_id
    WHERE asu.enabled = TRUE AND a.status = 'ready' AND a.br IS NOT NULL
    LIMIT 1
")->fetch();
if ($testFile) {
    $brPath = "/srv/precios/{$testFile['ruta']}/{$testFile['nombre']}.br";
    if (file_exists($brPath)) {
        $content = file_get_contents($brPath);
        $expectedBr = strtoupper(substr(strtoupper(hash('xxh3', $content)), -4));
        $dbBr = trim($testFile['br']);
        check("Integridad .br ({$testFile['nombre']})", $expectedBr === $dbBr, "DB: $dbBr, real: $expectedBr");
    } else {
        check("Archivo .br en disco ({$testFile['nombre']})", false, 'No existe en disco');
    }
}

// 12. zcli compilado
$zcliPath = __DIR__ . '/../zcli.exe';
$zcliOk = file_exists($zcliPath) && filesize($zcliPath) > 500000;
check("zcli.exe compilado (" . (file_exists($zcliPath) ? round(filesize($zcliPath)/1024) . 'KB' : 'no existe') . ")", $zcliOk);

// 13. Migraciones aplicadas
$hasClaveDbd = (bool)$pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name='sucursales' AND column_name='clave_dbd'")->fetchColumn();
$hasDbdUser = (bool)$pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name='cli_log' AND column_name='dbd_user'")->fetchColumn();
check("Migracion 003 (clave_dbd)", $hasClaveDbd);
check("Migracion 004 (dbd_user)", $hasDbdUser);

// 14. Sync_log reciente
$lastSync = $pdo->query("SELECT created_at FROM sync_log ORDER BY created_at DESC LIMIT 1")->fetchColumn();
check("Sync log con registro", $lastSync !== false, "No hay sincronizaciones registradas");

echo "\n=== Resultado: $pass OK, $fail FAIL ===\n";
exit($fail > 0 ? 1 : 0);
