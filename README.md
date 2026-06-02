# Sistema de Sincronización de Precios

Arquitectura de tres capas para la distribución de archivos DBF de precios desde un servidor NAS central hacia sucursales, con compresión Brotli, verificación de integridad XXH3, y soporte para descargas normales (NOR) y ciegas (DBD).

---

## Índice

1. [Capa RSync — Obtención de fuentes](#1-capa-rsync--obtención-de-fuentes)
2. [Capa Web — API REST + Dashboard](#2-capa-web--api-rest--dashboard)
3. [Capa Cliente — zcli (Zig)](#3-capa-cliente--zcli-zig)
4. [Base de Datos](#4-base-de-datos)
5. [Flujo Completo](#5-flujo-completo)

---

## 1. Capa RSync — Obtención de fuentes

Responsable de traer los archivos DBF originales desde el servidor NAS remoto hacia el servidor web, donde serán comprimidos y servidos.

### Origen remoto

```
admin@respaldos.camposreyeros.com:/volume1/homes/Precios/MASTERS/Mily-Master230716/
```

### Destino local

```
/srv/precios/
```

### Scripts

#### `scripts/getAll.sh`

Sincronización masiva desde el NAS. Dos modos:

- **Normal** (por defecto): lee un archivo de lista línea por línea, verifica existencia remota vía SSH para cada archivo, luego ejecuta `rsync -tirz` individual. Lleva conteo de transferidos, no encontrados y errores.
- **Fast** (`--fast`): ejecuta un único comando `rsync -tirvz --files-from=<archivo>` que transfiere todo en una sola pasada. Más rápido pero sin validación previa.

Al finalizar ejecuta `chown -R www-data:www-data /srv/precios` y escribe logs mensuales en `scripts/log_precios_YYYYMM.log`.

```bash
# Uso
./scripts/getAll.sh archivosFuenteFull.txt   # modo normal
./scripts/getAll.sh --fast all.ls             # modo rápido
```

#### `scripts/getOne.sh`

Sincronización de un único archivo. Usa `rsync -iz` con timeout de 10s. Acepta `--fast` para saltar verificación SSH/conectividad.

```bash
./scripts/getOne.sh CHAPALA/TABLA017.DBF
```

#### `scripts/cron-all-fast.php`

Script CLI (PHP) pensado para cron. Flujo:

1. Marca todos los archivos como `status='updating'`
2. Lee archivos habilitados de la base de datos y genera `all.ls`
3. Ejecuta `getAll.sh --fast all.ls` vía `sudo`
4. Para cada archivo sincronizado, llama a `processAndCompressFile()` que:
   - Lee el archivo desde `/srv/precios/{ruta}/{nombre}`
   - Calcula hash XXH3 (últimos 4 caracteres hex en mayúsculas = `flatHash`)
   - Si ya existe `.br` en disco y el hash coincide → salta (SKIP), solo actualiza `status='ready'` si estaba en otro estado
   - Si no existe `.br` o el hash cambió → comprime con Brotli nivel 11, escribe `.br`
   - Actualiza la base de datos: `peso`, `flat`, `br`, `xxh3`, `comprimido`, `compr_pct`, `status='ready'`
   - Resetea contadores de `archivo_sucursal` (`n_envios=0`, `n_exitos=0`, `ultimo_resultado='pending'`)
   - Inserta en `archivo_log` con acción `sync`
5. Registra resultado en `sync_log`

```bash
# Ejecución manual
sudo -u www-data php scripts/cron-all-fast.php

# Cron (cada hora)
0 * * * * /usr/bin/php /var/www/precios/scripts/cron-all-fast.php >> /var/www/precios/scripts/cron.log 2>&1
```

### Archivos de listas

| Archivo | Propósito |
|---------|-----------|
| `scripts/archivosFuenteFull.txt` | Lista maestra de 19 archivos DBF/CDX de todas las sucursales |
| `scripts/archivosFuente.txt.bak` | Lista histórica completa (272 líneas) |
| `scripts/all.ls` | Lista generada dinámicamente por `cron-all-fast.php` con los archivos habilitados |
| `scripts/selected.ls` | Subconjunto de 7 archivos para sincronización selectiva |

### Funciones auxiliares (`lib/sync_helper.php`)

| Función | Descripción |
|---------|-------------|
| `extractTransferidos(array $output): int` | Parsea la salida de rsync para contar archivos transferidos. Busca `[TRANSFERIDOS] N` o cuenta líneas `[i2]` |
| `logSync(PDO, mode, params, total, transferidos, procesados, omitidos, errores, exitCode, durationSec)` | Inserta un registro en `sync_log` con estado `ok/warning/error` |
| `processAndCompressFile(ruta, nombre): array` | Procesa un archivo: verifica, comprime con Brotli, actualiza BD. Retorna `OK`, `SKIP` o `ERROR` |

### `lib/hash_helper.php`

```php
function flatHash(string $data): string
{
    return substr(strtoupper(hash('xxh3', $data)), -4);
}
```

Calcula el hash XXH3 (últimos 4 dígitos hexadecimales en mayúsculas). Se usa como identificador corto de integridad tanto para el archivo plano (`flat`) como para el comprimido (`br`).

---

## 2. Capa Web — API REST + Dashboard

Servidor PHP con dos caras: una API REST para el cliente Zig y un panel web de administración.

### Enrutamiento (`index.php`)

| Ruta | Tipo | Auth | Descripción |
|------|------|------|-------------|
| `/` o `/login` | Web | Sesión | Pantalla de login |
| `/dashboard/{page}` | Web | Sesión | Panel de administración |
| `/api/v1/{controller}/{idSucursal?}/{fileName?}` | API | X-API-Key | Endpoints REST |

Endpoints exceptuados de API key: `sync`, `sync-fast`, `sync-selected`, `auth`, `archivos-fuente-full`.

### API REST — Endpoints

#### `GET /api/v1/pending/{idSucursal}`

Devuelve JSON con archivos pendientes de descarga para una sucursal. Filtra por `archivo_sucursal.enabled=TRUE` y `archivos.status='ready'`. Cada archivo incluye: `id`, `ruta`, `nombre`, `flat`, `br`, `peso`, `ultimo_cambio`, `fecha_archivo`.

Respuesta:
```json
{
  "archivos": [
    {
      "id": 1,
      "ruta": "CHAPALA/ENVIAR",
      "nombre": "LISTA.DBF",
      "flat": "A1B2",
      "br": "C3D4",
      "peso": 12345,
      "ultimo_cambio": "2026-05-30 12:00:00",
      "fecha_archivo": "2026-05-30 10:00:00"
    }
  ]
}
```

#### `GET /api/v1/serve/{idSucursal}/{fileName}`

Sirve el archivo `.br` comprimido. Headers:

| Header | Descripción |
|--------|-------------|
| `X-API-Key` | API key del cliente |
| `X-Ruta` | (opcional) Ruta del archivo para desambiguar |
| `X-DBD-User` | (DBD) Usuario GTE o nickname del sistema |
| `X-DBD-Password` | (DBD) Clave DBD |

Response headers: `X-FLAT`, `X-BR` con los hashes.

**Autenticación DBD** (cuando la ruta contiene `DSBLIND`):

| Si X-DBD-User es... | Validación |
|---------------------|------------|
| `GTE` | Compara `password` contra `sucursales.clave_dbd` (case-insensitive, `strtoupper`) |
| Otro nickname | Busca en `usuarios.nickname` (case-insensitive), verifica `password_verify(strtoupper(password), usuarios.clavecorta)`. Usuario debe tener `can_dsblind=TRUE` |

Si `clave_dbd` es `NULL`, vacío o `"null"` → rechaza.

Al servir: incrementa `archivos.n_descargas`, `archivo_sucursal.n_envios`, inserta en `cli_log` con `dbd_user` correspondiente.

#### `POST /api/v1/confirm`

Confirma el resultado de una descarga.

**Modalidad individual** (por defecto): cuerpo JSON con `{sucursal_id, archivo_id, nombre, resultado}`. Actualiza `archivo_sucursal` con `sync=TRUE`, `ultimo_resultado`, `n_exitos++`. Resultados válidos: `downloaded`, `skip`, `error-br`, `error-flat`, `error-tmp`, `error-blocked`.

**Modalidad batch** (`X-Batch: true`): cuerpo JSON con `{sucursal_id, batch: [{nombre, resultado}]}` para confirmar múltiples archivos como `skip` en una sola transacción.

**DBD**: si `resultado=downloaded` y es archivo DBD, deshabilita la asociación (`enabled=FALSE`) y limpia `sucursales.clave_dbd=NULL`.

#### `POST /api/v1/dbd-auth`

Validación exclusiva de credenciales DBD antes de iniciar descargas. Cuerpo: `{nickname, password, sucursal_id}`. Retorna `{"ok": true}` o 403. Usa la misma lógica que `serve.php`.

#### `GET /api/v1/download/{idSucursal}/{fileName}`

Descarga directa del archivo `.br` sin autenticación DBD (solo API key). Headers: `Content-Encoding: br`, `X-Comprimido: brotli`, `X-FLAT`, `X-BR`, `Content-Disposition: attachment`.

#### `GET /api/v1/files/{idSucursal}`

Listado plano texto de archivos disponibles para una sucursal.

#### `POST /api/v1/upload/{idSucursal}/{fileName}`

Subida de archivo comprimido. Requiere headers `Nombre`, `Ruta`, `Flat`, `Br`, `Fecha_Archivo`. Verifica integridad del hash FLAT.

#### `GET/POST/DELETE /api/v1/associations/{sucursal_id?}`

CRUD de asociaciones archivo-sucursal.

#### `POST /api/v1/auth`

Login web. Verifica `usuarios.nickname` + `password` (bcrypt). Inicia sesión y redirige a `/dashboard`.

### Dashboard Web

Panel administrativo con Pico CSS. Archivos en `/dashboard/`.

#### `index.php`
Tarjetas de estadísticas: total archivos, comprimidos, descargas, sucursales, asociaciones. Tabla de archivos recientes.

#### `archivos.php` + tabs
4 pestañas:
- **Listar**: tabla paginada con búsqueda y orden por ruta/fecha
- **Asociar**: vincula archivos existentes a sucursales
- **Eliminar**: borra archivos de la base
- **Registrar**: registra nuevo archivo por ruta+nombre, ejecuta `getOne.sh` + `processAndCompressFile`

#### `archivo-editar.php`
Editor individual de archivo. Muestra metadatos, asociaciones a sucursales, permite:

- Habilitar/deshabilitar archivo
- Activar `sync` por sucursal
- **Generar clave DBD** por sucursal (acción `gen-dbd-file`): genera clave hex de 6 caracteres y actualiza `sucursales.clave_dbd`
- **Descargas DBD**: tabla de histórico de descargas DBD para archivos en ruta DSBLIND
- Sincronización one-click desde NAS
- Historial de `archivo_log`

#### `sucursales.php`
Gestión de sucursales con pestañas dinámicas:
- **Buscar**: buscador por ID o nombre
- **Sin archivos asociados**: sucursales sin ninguna asociación
- **Nueva sucursal**: creación

Al seleccionar una sucursal, muestra vista detalle con:
- Archivos asociados (tabla con inline toggle enabled/sync, desasociar AJAX)
- Pestaña **DBD**: clave actual, botón "Generar clave DBD", historial de descargas DBD

#### `usuarios.php`
CRUD de usuarios con toggles inline para: enabled, can_dsblind, err_notif. Formulario de edición con:
- `password`: contraseña web (bcrypt, sin transformación)
- `clavecorta`: contraseña de 5 caracteres (se almacena como bcrypt, siempre en mayúsculas antes de hashear)

#### `cli-log.php`
Registro de descargas del cliente. Dos pestañas:
- **Normal (NOR)**: filtros por fecha y sucursal
- **Desblind (DBD)**: filtros por sucursal y `dbd_user`

#### Otros

| Archivo | Propósito |
|---------|-----------|
| `sync.php` | Disparador manual de `cron-all-fast.php` |
| `sync-log.php` | Historial de sincronizaciones rsync |
| `archivo-log.php` | Bitácora de cambios en archivos |

---

## 3. Capa Cliente — zcli (Zig)

Cliente nativo de terminal escrito en **Zig**, compilado para **Windows x86_64** (ReleaseSmall, ~1.1 MB). Incluye descompresión Brotli vía FFI directa (sin DLL externa). Se conecta a la API REST para sincronizar archivos locales.

### Compilación

```bash
cd zcli
zig build
# Genera: zig-out/bin/zcli.exe
```

### Configuración (`appsettings.json`)

```json
{
  "ApiBaseUrl": "http://precios.servicios.care",
  "ApiKey": "precios_api_key_2024",
  "SucursalId": "CHAPALA"
}
```

Si no existe, el cliente entra en modo interactivo pidiendo código de sucursal y opcionalmente URL/API key personalizadas.

### Arquitectura interna

**Lenguaje**: Zig puro con FFI a C para:
- I/O de archivos (`fopen`, `fread`, `fwrite`, `fclose`)
- Teclado (`_kbhit`, `_getch` para entrada con timeout)
- Estadísticas de archivo (`_stat64`)
- Timestamps (`_utime`)
- Brotli (`BrotliDecoderCreateInstance`, `BrotliDecoderDecompressStream`, `BrotliDecoderDestroyInstance`)

**HTTP**: usa `std.http.Client` de la stdlib de Zig.

**Hashing**: `std.hash.XxHash3` para verificar integridad FLAT y BR (últimos 4 hex, mayúsculas).

### Flujo principal (`main.zig`)

```
main()
  ├─ readConfig() o setupConfig() interactivo
  └─ runSync()
       ├─ fetchFiles()        → GET /api/v1/pending/{sucursal}
       ├─ Separa DBD y NOR por ruta
       ├─ processFiles(NOR)   → menú interactivo
       │    ├─ Pre-análisis: clasifica archivos como '=' (igual), '+' (nuevo), '-' (obsoleto)
       │    ├─ Muestra lista numerada con indicador de estado y antigüedad
       │    └─ Opciones: número (individual), t (todos), f (faltantes), b (blinde→DBD), s (salir)
       └─ downloadGroup(DBD)  → automático tras autenticación
            ├─ promptDbdCredentials()  → pide usuario/clave
            ├─ verifyDbdAuth()          → POST /api/v1/dbd-auth
            └─ Descarga en lote
```

#### `processFile()` — Pipeline de descarga

```
downloadFile()         → GET /api/v1/serve/{suc}/nombre + headers DBD
    │  (con reintentos hasta 5 si hash BR no coincide)
    │
    ▼
decompressBrotli()     → BrotliDecoderDecompressStream
    │
    ▼
Verificar hash FLAT    → computeShortHash() vs file.flat
    │
    ▼
Comparar con local     → si existe y hash coincide → confirm("skip")
    │                     si local es más reciente → pregunta sobrescribir
    │
    ▼
Escribir temporal      → {nombre}.{timestamp}.tmp
    │
    ▼
Renombrar atómico      → remove() + rename() (hasta 10 reintentos, 4s entre cada uno)
    │
    ▼
Restaurar mtime        → _utime() con fecha del servidor
    │
    ▼
confirmDownload()      → POST /api/v1/confirm {resultado:"downloaded"}
```

### Funciones clave

| Función | Línea | Propósito |
|---------|-------|-----------|
| `fetchFiles()` | 286 | Obtiene lista de archivos pendientes de la API |
| `downloadFile()` | 342 | Descarga archivo `.br` con headers DBD opcionales |
| `confirmDownload()` | 380 | Confirma resultado individual |
| `confirmBatch()` | 413 | Confirma múltiples archivos como skip en lote |
| `verifyDbdAuth()` | 460 | Valida credenciales DBD contra endpoint dedicado |
| `decompressBrotli()` | 581 | Descompresión Brotli vía FFI |
| `computeShortHash()` | 509 | Últimos 4 hex de XXH3 (mayúsculas) |
| `processFile()` | 763 | Pipeline completo: download → decompress → verify → write → confirm |
| `processFiles()` | 928 | Menú interactivo NOR con pre-análisis y opciones |
| `downloadGroup()` | 893 | Descarga headless de grupo DBD con autenticación |
| `promptDbdCredentials()` | 616 | Input interactivo de credenciales DBD |

### Interfaz de usuario

```
=== Sincronizacion - Sucursal: CHAPALA ===

[1] + 2026-05-30 10:00:00 CHAPALA/ENVIAR/LISTA.DBF
[2] = 2026-05-29 08:00:00 CHAPALA/ENVIAR/LISTA.CDX

Numero, [t]odos, [f]altantes, [b]linde, [s]alir [f]: █
```

- `+` = archivo nuevo o con cambios
- `=` = ya sincronizado
- `-` = local más reciente (pregunta antes de sobrescribir)
- `E` = error en intento previo

Timeout de 10s en el menú; si no se ingresa nada, equivale a `f` (faltantes).

### DBD (Descarga Ciega)

Cuando hay archivos en ruta que contiene `DSBLIND`, el flujo es:

1. Si el usuario presiona `b` en el menú NOR (o solo hay archivos DBD), entra a `downloadGroup()`
2. Solicita credenciales DBD (usuario + clave)
3. Valida contra `POST /api/v1/dbd-auth`
4. Si falla, permite reintentar; si se cancela (usuario vacío), aborta
5. Descarga todos los archivos DBD automáticamente (sin menú)
6. Cada archivo DBD descargado correctamente deshabilita la asociación y limpia la clave en el servidor

---

## 4. Base de Datos

PostgreSQL 15. Tablas:

### `sucursales`
| Columna | Tipo | Descripción |
|---------|------|-------------|
| `id_sucursal` | VARCHAR(5) PK | Código (CHAPALA, CHE, etc.) |
| `nombre_sucursal` | VARCHAR(100) | Nombre completo |
| `enabled` | BOOLEAN | Sucursal activa |
| `clave_dbd` | VARCHAR(6) | Clave DBD temporal (hex, se limpia al usar) |

### `archivos`
| Columna | Tipo | Descripción |
|---------|------|-------------|
| `id` | INTEGER PK | |
| `ruta` | VARCHAR(500) | Ruta relativa en /srv/precios |
| `nombre` | VARCHAR(255) | Nombre del archivo |
| `peso` | BIGINT | Tamaño original |
| `xxh3` | CHAR(6) | Hash completo (alias de flat) |
| `flat` | CHAR(6) | Últimos 4 hex XXH3 del original |
| `br` | CHAR(6) | Últimos 4 hex XXH3 del comprimido |
| `comprimido` | BOOLEAN | TRUE si tiene .br |
| `compr_pct`| INTEGER | Porcentaje compresión (br*100/original) |
| `status` | VARCHAR(10) | ready / updating |
| `n_descargas` | INT | Contador de descargas |
| `fecha_archivo` | TIMESTAMP | Fecha modificación del archivo fuente |
| `fecha_carga` | TIMESTAMP | Fecha de registro |
| UNIQUE(ruta, nombre) | | |

### `archivo_sucursal` (junction)
| Columna | Tipo | Descripción |
|---------|------|-------------|
| `archivo_id` | INTEGER PK, FK→archivos | |
| `sucursal_id` | VARCHAR(5) PK, FK→sucursales | |
| `nombre` | VARCHAR(255) | Nombre del archivo |
| `enabled` | BOOLEAN | Asociación activa |
| `sync` | BOOLEAN | Archivo sincronizado |
| `es_desblinde` | BOOLEAN | Es un archivo DBD |
| `n_envios` | INT | Intentos de envío |
| `n_exitos` | INT | Descargas exitosas |
| `ultimo_resultado` | VARCHAR(14) | pending/downloaded/skip/error-* |
| UNIQUE(sucursal_id, nombre, es_desblinde) | | |

### `usuarios`
| Columna | Tipo | Descripción |
|---------|------|-------------|
| `id` | INTEGER PK | |
| `nombre` | VARCHAR(255) | Nombre real |
| `nickname` | VARCHAR(50) UNIQUE | Nombre de usuario |
| `password` | VARCHAR(255) | Bcrypt (web login, sin transformación) |
| `clavecorta` | VARCHAR(255) | Bcrypt (siempre mayúsculas antes de hashear) |
| `can_dsblind` | BOOLEAN | Permiso para DBD |
| `can_upload` | BOOLEAN | Permiso subida |
| `can_download` | BOOLEAN | Permiso descarga |

### `cli_log`
| Columna | Tipo | Descripción |
|---------|------|-------------|
| `sucursal_id` | VARCHAR(50) | |
| `file_name` | VARCHAR(255) | |
| `file_type` | VARCHAR(10) | NOR / DBD |
| `api_key_id` | INTEGER FK | |
| `usuario_id` | INTEGER FK | |
| `ip_address` | VARCHAR(45) | |
| `dbd_user` | VARCHAR(255) | GTE o nickname del usuario DBD |
| `status` | VARCHAR(10) | ok / error |

### `sync_log`
Bitácora de ejecuciones de rsync con modo, parámetros, total/transferidos/procesados/omitidos/errores, código de salida y duración.

### `archivo_log`
Bitácora de cambios en archivos: action (`sync`, `upload`, `assoc`), hashes anterior/nuevo, detalle.

---

## 5. Flujo Completo

```
NAS Remoto                          Servidor Web                    Sucursal (Cliente Zig)
────────────                        ────────────                    ─────────────────────
                                    1. cron-all-fast.php
                                       │
[RSync] ───── getAll.sh ───────────> /srv/precios/{ruta}/
                                       │
                                       │ processAndCompressFile()
                                       │   • calcula XXH3 (flat)
                                       │   • comprime Brotli → .br
                                       │   • calcula XXH3 del .br (br)
                                       │   • UPDATE archivos
                                       │   • INSERT archivo_log
                                       │
                                       │ ←── LISTO para servir
                                       
                                                                    2. zcli (usuario)
                                                                       │
                                                                       │ GET /api/v1/pending/{suc}
                                                                       │ ← lista de archivos
                                                                       │
                                                                       │ Menú interactivo (NOR)
                                                                       │   ─ o ─
                                                                       │ DBD: prompt credenciales
                                                                       │   → POST /api/v1/dbd-auth
                                                                       │   → GET /api/v1/serve/{suc}/{nom}
                                                                       │         + X-DBD-User/Password
                                                                       │
                                       │ ←── archivo .br
                                       │
                                       │ download → decompress → verify hash → write
                                       │
                                       │ POST /api/v1/confirm
                                       │   { resultado: "downloaded" }
                                       │
                                       │ ←── OK
                                       │
                                       │ (DBD) UPDATE archivo_sucursal
                                       │   enabled=FALSE, clave_dbd=NULL
```

### Flujo de autenticación DBD

```
Cliente                          Servidor
──────                          ────────
Prompt usuario/clave
       │
       │ POST /api/v1/dbd-auth
       │   { nickname, password, sucursal_id }
       │
       │   ┌─ nickname = "GTE"?
       │   │   → SELECT clave_dbd FROM sucursales
       │   │   → strtoupper(password) == clave_dbd? (case-insensitive)
       │   │
       │   └─ Otro nickname?
       │       → SELECT nickname, clavecorta, can_dsblind FROM usuarios
       │       → WHERE UPPER(nickname) = UPPER(input)
       │       → password_verify(strtoupper(password), clavecorta)
       │       → can_dsblind = TRUE
       │
       │ ← {"ok": true} o 403
       │
       │ (Si ok) procede con descargas
       │ (Si 403) reintenta hasta 3 veces
```

### Consideraciones de seguridad

- API key en header `X-API-Key` para todos los endpoints REST
- Sesión PHP con `$_SESSION` para dashboard
- Contraseñas web con bcrypt (`password_hash`, `password_verify`)
- `clavecorta` también bcrypt, siempre mayúsculas antes de hashear
- `clave_dbd` en texto plano (temporal, se limpia al primer uso)
- Rechazo de claves vacías o literal `"null"` tanto en cliente como servidor
- DBD: tras descarga exitosa se deshabilita la asociación y se limpia la clave (uso único)

---

## Migraciones

| Archivo | Descripción |
|---------|-------------|
| `migrations/002_add_download_counters.sql` | Agrega `n_descargas`, `n_envios`, `n_exitos`, `ultimo_resultado` |
| `migrations/003_add_clave_dbd.sql` | Agrega `clave_dbd VARCHAR(6)` a `sucursales` |
| `migrations/004_add_dbd_user_to_cli_log.sql` | Agrega `dbd_user VARCHAR(255)` a `cli_log` |

---

## Notas técnicas

- **Hash XXH3**: se usa la implementación nativa de PHP (`hash('xxh3', ...)`) y de Zig (`std.hash.XxHash3`). Se toman solo los últimos 4 caracteres hexadecimales en mayúsculas como identificador corto (`flatHash`/`computeShortHash`).
- **Compresión Brotli**: nivel 11 (máxima compresión) en PHP (`brotli_compress`). Descompresión en Zig vía biblioteca C compilada estáticamente (código fuente en `zcli/brotli/`).
- **Atomic rename**: Zig escribe a archivo temporal `.tmp` y renombra para evitar corrupción. Reintenta hasta 10 veces si el archivo está bloqueado por el punto de venta.
- **Timeouts**: menú interactivo con timeout de 10s; descargas con reintentos hasta 5 si el hash BR no coincide.
