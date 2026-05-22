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

log "INFO" "Iniciando sincronización completa"

RSYNC_OUTPUT=$(timeout 30 rsync -irtz --files-from="$PRECIOS_FILE" --chmod=Du=rwx,go=rx,Fu=rw,go=r "$REMOTE" "$DEST_DIR" 2>&1) || {
    ERR=$?
    log "ERROR" "Rsync falló (código $ERR)"
    exit 2
}

while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    log "RSYNC" "$line"
done <<< "$RSYNC_OUTPUT"

chmod -R o+X "$DEST_DIR"

log "SUCCESS" "Sincronización completada"

echo "$RSYNC_OUTPUT" | grep '^>f' | sed 's/^>f[^ ]* *//' || true
