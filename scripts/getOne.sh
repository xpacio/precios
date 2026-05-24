#!/bin/bash

set -euo pipefail

LOG_FILE="precios_$(date '+%Y%m').log"
DEST_DIR="/srv/precios"
REMOTE_HOST="admin@respaldos.camposreyeros.com"
REMOTE_PATH="/volume1/homes/Precios/MASTERS/Mily-Master230716/"



log() {
    local msg="$(date '+%Y-%m-%d %H:%M:%S') [$1] $2"
    echo "$msg" | tee -a "$LOG_FILE"
}

# Verificar que se proporcionó un parámetro
if [[ $# -eq 0 ]]; then
    log "ERROR" "No se proporcionó ruta remota del archivo"
    echo "Uso: $0 <ruta_relativa_remota_del_archivo>"
    exit 1
fi

REMOTE_FILE="${1#/}"

# Validar que el parámetro no esté vacío
if [[ -z "$REMOTE_FILE" ]]; then
    log "ERROR" "La ruta remota del archivo está vacía"
    exit 1
fi

log "INFO" "Archivo remoto solicitado: $REMOTE_FILE"

mkdir -p "$DEST_DIR" || {
    log "ERROR" "No se pudo crear el directorio de destino: $DEST_DIR"
    exit 1
}

if [[ ! -w "$DEST_DIR" ]]; then
    log "ERROR" "No hay permisos de escritura en $DEST_DIR"
    exit 1
fi

log "INFO" "Inicia transferencia desde $REMOTE_HOST:$REMOTE_PATH$REMOTE_FILE"

START_TIME=$(date +%s)

# Verificar conectividad SSH
if ! ssh -q -o BatchMode=yes -o ConnectTimeout=2 "$REMOTE_HOST" exit; then
    log "ERROR" "No se puede conectar a $REMOTE_HOST"
    exit 1
fi

# Verificar si el archivo existe en el remoto (opcional pero recomendado)
if ! ssh "$REMOTE_HOST" "test -f \"$REMOTE_PATH$REMOTE_FILE\""; then
    log "ERROR" "El archivo $REMOTE_PATH$REMOTE_FILE no existe en el servidor remoto"
    exit 1
fi

# Crear subdirectorio local si es necesario
SUB_DIR=$(dirname "$REMOTE_FILE")
if [[ "$SUB_DIR" != "." ]]; then
    mkdir -p "$DEST_DIR/$SUB_DIR"
fi

# Transferir el archivo específico con timeout de 10 segundos
if rsync -iz -e ssh --timeout=10 "$REMOTE_HOST:$REMOTE_PATH$REMOTE_FILE" "$DEST_DIR/$SUB_DIR/"; then
    chown -R www-data:www-data "$DEST_DIR/$SUB_DIR" 2>/dev/null || true
    END_TIME=$(date +%s)
    DURATION=$((END_TIME - START_TIME))
    log "SUCCESS" "Transferencia completada en ${DURATION} segundos"
    exit 0
else
    log "ERROR" "rsync falló con código $?"
    exit 1
fi
