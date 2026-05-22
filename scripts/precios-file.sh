#!/bin/bash
set -euo pipefail

LOG_FILE="/tmp/precios_$(date '+%Y%m').log"
DEST_DIR="/srv/precios"
REMOTE="admin@respaldos.camposreyeros.com:/volume1/homes/Precios/MASTERS/Mily-Master230716/"
FILE="${1:-}"

log() {
    local msg="$(date '+%Y-%m-%d %H:%M:%S') [$1] $2"
    echo "$msg" | tee -a "$LOG_FILE" >&2 || true
}

if [[ -z "$FILE" ]]; then
    log "ERROR" "Uso: $0 <ruta-relativa>"
    log "ERROR" "Ejemplo: $0 CHAPALA/ENVIAR/CABLISTA.DBF"
    exit 1
fi

mkdir -p "$DEST_DIR"

log "INFO" "Sincronizando: $FILE"

RSYNC_OUTPUT=$(timeout 30 rsync -irtz --files-from=<(echo "$FILE") --chmod=Du=rwx,go=rx,Fu=rw,go=r "$REMOTE" "$DEST_DIR" 2>&1) || {
    ERR=$?
    log "ERROR" "Rsync falló para $FILE (código $ERR)"
    exit 2
}

chmod -R o+X "$DEST_DIR"

while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    log "RSYNC" "$line"
done <<< "$RSYNC_OUTPUT"

log "SUCCESS" "Sincronizado: $FILE"

echo "$RSYNC_OUTPUT" | grep '^>f' | sed 's/^>f[^ ]* *//' || true
