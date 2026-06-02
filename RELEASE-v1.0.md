# Release v1.0 — Sistema de Sincronización de Precios

Primera release estable del sistema de distribución de archivos DBF de precios desde servidor NAS central hacia sucursales, con compresión Brotli, verificación de integridad XXH3, y soporte para descargas normales (NOR) y ciegas (DBD).

## Arquitectura

```
NAS Remoto (Synology)  ──rsync──>  Servidor Web (PHP + Postgres)  <──http──>  Sucursal (zcli.exe)
```

Tres capas bien definidas:

### 1. RSync — Obtención de fuentes
- `scripts/getAll.sh` — sincronización masiva desde `admin@respaldos.camposreyeros.com` hacia `/srv/precios/`
- `scripts/getOne.sh` — descarga individual de un archivo
- `scripts/cron-all-fast.php` — pipeline completo: rsync → compresión Brotli nivel 11 → registro en BD
- Hash XXH3 (últimos 4 hex, mayúsculas) como identificador corto de integridad (`flat` / `br`)
- Logging en `sync_log` con tiempos, transferidos, procesados, errores

### 2. Web — API REST + Dashboard
**API REST** (6 endpoints protegidos con X-API-Key):
- `GET /api/v1/pending/{sucursal}` — lista archivos pendientes de descargar
- `GET /api/v1/serve/{sucursal}/{archivo}` — sirve archivo `.br` con headers X-FLAT / X-BR
- `POST /api/v1/confirm` — confirma resultado de descarga (individual o batch)
- `POST /api/v1/dbd-auth` — valida credenciales DBD antes de descargar
- `GET /api/v1/download/{sucursal}/{archivo}` — descarga directa
- `POST /api/v1/upload/{sucursal}/{archivo}` — subida con verificación de hash

**Dashboard** (Pico CSS, sesión PHP):
- Archivos: listar, asociar a sucursales, eliminar, registrar desde NAS
- Sucursales: búsqueda, detalle con archivos asociados, gestión DBD
- Usuarios: CRUD con permisos (can_dsblind, can_upload, can_download, err_notif)
- Cli Log: registro de descargas Normal y Desblind con filtros
- Sync Log: historial de sincronizaciones rsync
- Archivo Log: bitácora de cambios en archivos

**Autenticación DBD** (Descarga Ciega):
- Usuario `GTE` → validación contra `sucursales.clave_dbd` (case-insensitive)
- Usuario del sistema → validación contra `usuarios.clavecorta` (bcrypt, siempre mayúsculas)
- Clave temporal de 6 caracteres hex, se limpia al primer uso exitoso
- Uso único: tras descargar, `enabled=false` + `clave_dbd=null`

### 3. Cliente — zcli (Zig)
- Cliente nativo Windows x86_64, **403 KB** (UPX), sin dependencias externas
- Descompresión Brotli vía FFI (código C compilado estáticamente)
- Menú interactivo con timeout (10s) y pre-análisis de archivos (`+` nuevo, `=` igual, `-` obsoleto)
- Flujo DBD headless: prompt credenciales → auth → descarga automática
- Reintentos: hasta 5 si el hash BR no coincide; hasta 10 si el archivo está bloqueado por el punto de venta
- Renombre atómico con timestamp para evitar corrupción
- Restauración de mtime desde el servidor
- Configuración via `appsettings.json` o modo interactivo

## Base de Datos (PostgreSQL 15)

8 tablas: `archivos`, `sucursales`, `archivo_sucursal`, `usuarios`, `cli_log`, `sync_log`, `archivo_log`, `api_keys`

Migraciones:
- `002_add_download_counters.sql` — n_descargas, n_envios, n_exitos, ultimo_resultado
- `003_add_clave_dbd.sql` — clave_dbd en sucursales para DBD
- `004_add_dbd_user_to_cli_log.sql` — dbd_user en cli_log para auditoría DBD

## Cómo usar

### Servidor
```bash
# Sincronizar desde NAS y comprimir
sudo -u www-data php scripts/cron-all-fast.php

# O individual
php scripts/cron-all-fast.php
```

### Cliente
```bash
# En la sucursal (Windows)
zcli.exe

# Primera ejecución: configuración interactiva
# Ejecuciones siguientes: usa appsettings.json
```

## Notas técnicas
- Compresión: Brotli nivel 11 en PHP (`brotli_compress`), descompresión nativa en Zig
- Hash: XXH3 64-bit, últimos 4 hex en mayúsculas como identificador corto
- Atomic rename: escritura a `.tmp` + `rename()` para evitar archivos corruptos
- zcli compilado con Zig 0.17.0-dev, ReleaseSmall, strip, gc-sections
