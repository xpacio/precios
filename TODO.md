# Revisión: Implementación archivo_log

## Estado: Sin fallas críticas

## Resumen por archivo

### ✅ lib/sync_helper.php:110-111
```
INSERT INTO archivo_log … VALUES (?, 'sync', ?, ?, ?)
```
- `$existing['xxh3']` se lee **antes** del UPDATE (línea 103-106), por lo que contiene el hash anterior → correcto.
- `$flat` es el hash recién calculado → correcto.

### ✅ api/v1/verify.php:58-59
```
INSERT INTO archivo_log … VALUES (?, 'verify', ?, ?, ?)
```
- `$row['flat']` se capturó en el `fetchAll()` de línea 26, antes del UPDATE → es el valor viejo.
- `$flat` es el hash nuevo calculado.
- El INSERT ocurre dentro del mismo `$changed === true` → consistente.

### ⚠️ api/v1/upload.php:78-79
```
$pdo->commit();
$pdo->prepare("INSERT INTO archivo_log …")->execute([…]);
```
- El INSERT del log ocurre **fuera de la transacción** (después de `commit()`).
- Si el INSERT falla, la subida ya se cometió y el log se pierde silenciosamente.
- **Decisión de diseño aceptada**: el log es informativo, no crítico.
- Alternativa: mover el INSERT antes del `commit()` (dentro de la transacción).

### ✅ api/v1/associations.php:72-73
```
INSERT INTO archivo_log … VALUES (?, 'assoc', …)
```
- Sin transacción wrapping, mismo patrón que el resto del endpoint.
- `$archivoId` y `$sucursalId` validados antes de llegar aquí.

### ✅ dashboard/archivo-editar.php:246-254
```php
$logStmt = $pdo->prepare("SELECT … FROM archivo_log WHERE archivo_id = ? …");
$logStmt->execute([$id]);
```
- `$id` definido antes (línea 40).
- Sin try/catch, consistente con el resto de queries del archivo (sucursales, etc.).
- Si la BD falla, la página igual se rompe (comportamiento existente).

### ✅ dashboard/archivo-log.php
- **SQL injection**: `$daysFilter` casteado a `(int)`, `$perPage`/`$offset` son enteros.
- **ILIKE**: usamos `ILIKE` (PostgreSQL) con prepared statements → seguro.
- **Paginación**: `LIMIT $perPage OFFSET $offset` con enteros → seguro.
- `$totalPages = max(1, ceil($totalRows / $perPage))` → cuando hay 0 filas, totalPages=1, pero el bloque de paginación se oculta (`if ($totalPages > 1)`). Correcto.
- **Formulario de búsqueda** con `method="GET"`: los hidden inputs preservan `action` y `days` al submit.

### ✅ dashboard/header.php:70
```php
<li><a href="/dashboard/archivo-log" class="<?= $currentPage === 'archivo-log' ? 'contrast' : '' ?>">Archivo Log</a></li>
```
- `$currentPage` es seteado por `index.php:49` como `$segments[1]`, que es `'archivo-log'` → match correcto.

### ✅ DDL.sql + BD
- `archivo_log` creada con FK → `archivos(id) ON DELETE CASCADE`.
- Índices en `archivo_id` y `created_at`.

## Pre-existentes (no introducidos por este cambio)

### 🔴 archivo-editar.php:192 — Posible warning si `ruta` es null
```php
if (strpos($arch['ruta'], 'DSBLIND') !== false):
```
Si `$arch['ruta']` es null, `strpos()` emite advertencia en PHP 8+. La query SELECT (línea 47) nunca devuelve `ruta` null por schema, pero si un futuro UPDATE dejara el campo null, rompe.

### 🟡 verify.php:18 — `system()` con permisos
```php
system("chmod -R o+X " . escapeshellarg($PRECIOS_DIR) . " 2>/dev/null");
```
Usar `system()` es aceptable para tareas administrativas, pero `exec()` o `shell_exec()` serían más predecibles (no dependen del output buffer).

### 🟡 upload.php:50 — `getDB()` sin import explícito
No hay `require_once __DIR__ . '/../../config/database.php'`. Funciona porque `index.php` lo carga primero, pero es una dependencia oculta. Lo mismo aplica a `archivo-editar.php` y `sync_helper.php`.

## Conclusión
Código correcto, consistente con patrones existentes. Sin fallas que requieran corrección antes del commit.
