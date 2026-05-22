#!/bin/bash
set -euo pipefail

LOG_FILE="/tmp/precios_$(date '+%Y%m').log"
PRECIOS_FILE="/var/www/precios/scripts/precios.txt"
DEST_DIR="/srv/precios"
REMOTE="admin@respaldos.camposreyeros.com:/volume1/homes/Precios/MASTERS/Mily-Master230716/"

log() {
    local msg="$(date '+%Y-%m-%d %H:%M:%S') [$1] $2"
    echo "$msg" | tee -a "$LOG_FILE" >&2 || true
}

if [[ ! -f "$PRECIOS_FILE" ]] || [[ ! -s "$PRECIOS_FILE" ]]; then
    log "ERROR" "precios.txt no encontrado o vacío"
    exit 1
fi

mkdir -p "$DEST_DIR"

log "INFO" "inicia"

rsync -irz --files-from="$PRECIOS_FILE" "$REMOTE" "$DEST_DIR"
log "SUCCESS" "termina"
exit 0
