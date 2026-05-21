#!/bin/bash
set -eo pipefail

STORAGE_DIR="/var/www/precios/storage"
DB_DSN="postgresql://postgres:password@localhost:5432/precios"
API_BASE="http://localhost/api/v1"
API_KEY="precios_api_key_2024"
MODE="direct"
DRY_RUN=false
VERBOSE=false

usage() {
    echo "Uso: $0 [OPCIONES] [DIRECTORIO]"
    echo ""
    echo "Sincroniza archivos de DIRECTORIO (default: /src/precios) a la BD y storage."
    echo "Estructura esperada: /src/precios/{SUCURSAL_ID}/{archivo}"
    echo ""
    echo "Opciones:"
    echo "  --api         Usar API HTTP en vez de BD directa (mas lento pero desacoplado)"
    echo "  --dry-run     Solo mostrar que se haria, sin ejecutar"
    echo "  --verbose     Mostrar cada archivo procesado"
    echo "  --help        Esta ayuda"
    exit 0
}

SRC_DIR=""
for arg in "$@"; do
    case "$arg" in
        --api) MODE="api" ;;
        --dry-run) DRY_RUN=true ;;
        --verbose) VERBOSE=true ;;
        --help|-h) usage ;;
        *) SRC_DIR="$arg" ;;
    esac
done
SRC_DIR="${SRC_DIR:-/src/precios}"

if [ ! -d "$SRC_DIR" ]; then
    echo "ERROR: El directorio $SRC_DIR no existe"
    exit 1
fi

if [ ! -d "$STORAGE_DIR" ]; then
    mkdir -p "$STORAGE_DIR"
fi

SYNCED=0
SKIPPED=0
FAILED=0

process_file_direct() {
    local file="$1"
    local sucursal_id="$2"
    local filename
    filename=$(basename "$file")
    local filesize
    filesize=$(stat -c%s "$file")
    local md5full
    md5full=$(md5sum "$file" | cut -d' ' -f1)
    local md5zip
    md5zip=$(echo "$md5full" | cut -c1-8)
    local fecha
    fecha=$(date '+%Y-%m-%d %H:%M:%S')

    local exists
    exists=$(psql "$DB_DSN" -At -c "
        SELECT 1 FROM archivos a
        JOIN archivo_sucursal asu ON a.id = asu.archivo_id
        WHERE asu.sucursal_id = '$sucursal_id'
          AND a.nombre = '${filename//\'/\'\'}'
          AND a.md5zip = '$md5zip'
        LIMIT 1
    " 2>/dev/null)

    if [ "$exists" = "1" ]; then
        $VERBOSE && echo "  [SKIP] $filename (sin cambios)"
        SKIPPED=$((SKIPPED + 1))
        return 0
    fi

    if $DRY_RUN; then
        echo "  [NUEVO] $filename -> sucursal $sucursal_id (md5: $md5zip, peso: $filesize)"
        SYNCED=$((SYNCED + 1))
        return 0
    fi

    local new_id_raw
    new_id_raw=$(psql "$DB_DSN" -At -c "
        INSERT INTO archivos (nombre, ruta, peso, md5zip, md5flat, fecha_archivo, is_desblinde, usuario_que_cargo)
        VALUES ('${filename//\'/\'\'}', '$SRC_DIR/$sucursal_id', $filesize, '$md5zip', '$md5zip', '$fecha', FALSE, 1)
        RETURNING id;
    " 2>/dev/null)
    local new_id
    new_id=$(echo "$new_id_raw" | head -1)

    if [ -z "$new_id" ]; then
        echo "  [FAIL] $filename - error insertando en BD"
        FAILED=$((FAILED + 1))
        return 1
    fi

    cp "$file" "$STORAGE_DIR/$new_id" || {
        echo "  [FAIL] $filename - error copiando a storage"
        psql "$DB_DSN" -c "DELETE FROM archivos WHERE id = '$new_id'" >/dev/null 2>&1
        FAILED=$((FAILED + 1))
        return 1
    }

    psql "$DB_DSN" -c "
        INSERT INTO archivo_sucursal (archivo_id, sucursal_id) VALUES ('$new_id', '$sucursal_id');
    " >/dev/null 2>&1

    echo "  [OK]   $filename -> $new_id ($md5zip)"
    SYNCED=$((SYNCED + 1))
}

process_file_api() {
    local file="$1"
    local sucursal_id="$2"
    local filename
    filename=$(basename "$file")
    local md5zip
    md5zip=$(md5sum "$file" | cut -c1-8)

    if $DRY_RUN; then
        echo "  [API]  $filename -> sucursal $sucursal_id"
        SYNCED=$((SYNCED + 1))
        return 0
    fi

    local resp
    resp=$(curl -s -w "\n%{http_code}" \
        -H "X-API-Key: $API_KEY" \
        -H "NOMBRE: $filename" \
        -H "RUTA: $SRC_DIR/$sucursal_id" \
        -H "MD5ZIP: $md5zip" \
        -H "MD5FLAT: $md5zip" \
        -F "archivo=@$file" \
        "$API_BASE/upload/$sucursal_id/$filename" 2>/dev/null)

    local http_code
    http_code=$(echo "$resp" | tail -1)
    local body
    body=$(echo "$resp" | sed '$d')

    if [ "$http_code" = "200" ]; then
        local file_id
        file_id=$(echo "$body" | grep -oP 'ID: \K\S+')
        echo "  [OK]   $filename -> $file_id"
        SYNCED=$((SYNCED + 1))
    else
        echo "  [FAIL] $filename (HTTP $http_code): $body"
        FAILED=$((FAILED + 1))
    fi
}

echo "=== Sincronizando archivos ==="
echo "Origen: $SRC_DIR"
echo "Modo:   $MODE"
$DRY_RUN && echo "Dry-run: SI"
echo ""

for sucursal_dir in "$SRC_DIR"/*/; do
    [ -d "$sucursal_dir" ] || continue

    sucursal_id=$(basename "$sucursal_dir")

    # Verificar que la sucursal existe en BD
    if [ "$MODE" = "direct" ]; then
        exists_s=$(psql "$DB_DSN" -At -c "SELECT 1 FROM sucursales WHERE id_sucursal = '$sucursal_id'" 2>/dev/null)
        if [ "$exists_s" != "1" ]; then
            echo "  [WARN] Sucursal '$sucursal_id' no existe en BD, se omite"
            continue
        fi
    fi

    files=("$sucursal_dir"*)
    if [ ${#files[@]} -eq 0 ] || [ ! -f "${files[0]}" ]; then
        $VERBOSE && echo "  [INFO] $sucursal_id: sin archivos"
        continue
    fi

    echo "--- Sucursal $sucursal_id ---"

    for file in "$sucursal_dir"*; do
        [ -f "$file" ] || continue
        if [ "$MODE" = "api" ]; then
            process_file_api "$file" "$sucursal_id"
        else
            process_file_direct "$file" "$sucursal_id"
        fi
    done
done

echo ""
echo "=== Resumen ==="
echo "  Sincronizados: $SYNCED"
echo "  Sin cambios:   $SKIPPED"
echo "  Fallidos:      $FAILED"

if [ "$FAILED" -gt 0 ]; then
    exit 1
fi
