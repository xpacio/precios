# Archivo Log — Registro de cambios por archivo

## Propósito

Llevar un historial granular de **qué archivo se modificó, cuándo y por qué operación**.
El `sync_log` existente solo guarda agregados por sincronización (totales, transferidos, procesados),
pero no permite responder _"este archivo, ¿cuándo fue la última vez que cambió?"_.

## Esquema de tabla

```sql
CREATE TABLE archivo_log (
    id SERIAL PRIMARY KEY,
    archivo_id INT NOT NULL REFERENCES archivos(id) ON DELETE CASCADE,
    action VARCHAR(20) NOT NULL,
    prev_flat VARCHAR(6),
    new_flat VARCHAR(6),
    detalle VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_archivo_log_archivo ON archivo_log(archivo_id);
CREATE INDEX idx_archivo_log_created ON archivo_log(created_at);
```

### Campos

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | SERIAL PK | |
| `archivo_id` | INT FK → archivos(id) | Archivo afectado (CASCADE DELETE) |
| `action` | VARCHAR(20) | `sync`, `verify`, `upload`, `assoc` |
| `prev_flat` | VARCHAR(6) | Hash flat anterior (solo `sync`/`verify`) |
| `new_flat` | VARCHAR(6) | Hash flat nuevo (solo `sync`/`verify`) |
| `detalle` | VARCHAR(255) | Mensaje descriptivo |
| `created_at` | TIMESTAMP | Cuándo ocurrió |

## Puntos de logging

### 1. Central: `processAndCompressFile()` — `lib/sync_helper.php`

Cubre **4 rutas de ejecución** sin duplicar lógica:

| Llamador | Endpoint |
|---|---|
| `sync.php` (loop) | `GET /api/v1/sync` |
| `sync-fast.php` (loop) | `GET /api/v1/sync-fast` |
| `sync-selected.php` (loop) | `POST /api/v1/sync-selected` |
| `archivo-editar.php` (sync-one) | AJAX `action=sync-one` |

Se inserta cuando `processAndCompressFile()` retorna `status === 'OK'`
(archivo fue re-comprimido porque cambió el hash o faltaba `.br`).

```php
// Dentro de processAndCompressFile(), cuando status === 'OK':
$pdo->prepare("INSERT INTO archivo_log (archivo_id, action, detalle, prev_flat, new_flat)
               VALUES (?, 'sync', ?, ?, ?)")
    ->execute([$archivoId, 'Recomprimido brotli', $existing['xxh3'], $flat]);
```

### 2. `verify.php` — loop propio

Cuando `$changed === true` (archivo se re-comprimió porque flat cambió o `.br` faltaba):

```php
$pdo->prepare("INSERT INTO archivo_log (archivo_id, action, detalle, prev_flat, new_flat)
               VALUES (?, 'verify', ?, ?, ?)")
    ->execute([$row['id'], 'Verificado y re-comprimido', $row['flat'], $flat]);
```

### 3. `upload.php` — INSERT nuevo archivo

Tras `$pdo->commit()` exitoso:

```php
$pdo->prepare("INSERT INTO archivo_log (archivo_id, action, detalle)
               VALUES (?, 'upload', ?)")
    ->execute([$archivoId, 'Archivo cargado por API']);
```

### 4. `associations.php` — asociación a sucursal

Tras `INSERT ... ON CONFLICT DO UPDATE` exitoso:

```php
$pdo->prepare("INSERT INTO archivo_log (archivo_id, action, detalle)
               VALUES (?, 'assoc', ?)")
    ->execute([$archivoId, 'Asociado a sucursal ' . $sucursalId]);
```

## Dashboard

### Vista por archivo: `dashboard/archivo-editar.php`

Agregar sección "Historial de cambios" al final del HTML:

```sql
SELECT id, action, prev_flat, new_flat, detalle, created_at
FROM archivo_log
WHERE archivo_id = ?
ORDER BY created_at DESC
LIMIT 30
```

### Vista global: `dashboard/archivo-log.php` (nuevo)

- Filtros: archivo (ID o búsqueda), acción, días
- JOIN con `archivos` para mostrar ruta + nombre
- Paginación simple (LIMIT 100)
- Enlace en `header.php`

## Resumen de archivos a modificar/crear

| Archivo | Acción |
|---|---|
| `docs/archivo-log.md` | Este documento |
| `DDL.sql` | `CREATE TABLE archivo_log` |
| Base de datos | Ejecutar CREATE TABLE + índices |
| `lib/sync_helper.php` | Insertar log en `processAndCompressFile()` cuando OK |
| `api/v1/verify.php` | Insertar log cuando cambió |
| `api/v1/upload.php` | Insertar log tras commit |
| `api/v1/associations.php` | Insertar log tras asociar |
| `dashboard/archivo-editar.php` | Agregar sección "Historial" |
| `dashboard/archivo-log.php` | Nuevo — vista global |
| `dashboard/header.php` | Enlace en nav |
