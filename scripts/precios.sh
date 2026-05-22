#!/bin/bash
set -euo pipefail

LOG_FILE="precios_$(date '+%Y%m').log"
PRECIOS_FILE="precios.txt"
DEST_DIR="/srv/precios"
REMOTE_HOST="admin@respaldos.camposreyeros.com"
REMOTE_PATH="/volume1/homes/Precios/MASTERS/Mily-Master230716/"

# Contadores para estadísticas
TOTAL_ARCHIVOS=0
TRANSFERIDOS=0
NO_ENCONTRADOS=0
ERRORES=0

log() {
    local msg="$(date '+%Y-%m-%d %H:%M:%S') [$1] $2"
    echo "$msg" | tee -a "$LOG_FILE"
}

# Verificar que existe el archivo de lista
if [[ ! -f "$PRECIOS_FILE" ]] || [[ ! -s "$PRECIOS_FILE" ]]; then
    log "ERROR" "precios.txt no encontrado o vacío"
    exit 1
fi

# Crear directorio destino si no existe
mkdir -p "$DEST_DIR" || {
    log "ERROR" "No se pudo crear el directorio de destino: $DEST_DIR"
    exit 1
}

# Verificar permisos de escritura
if [[ ! -w "$DEST_DIR" ]]; then
    log "ERROR" "No hay permisos de escritura en $DEST_DIR"
    exit 1
fi

log "INFO" "Inicia procesamiento de archivos desde $REMOTE_HOST:$REMOTE_PATH"
log "INFO" "Leyendo lista de archivos desde $PRECIOS_FILE"

START_TIME=$(date +%s)

# Verificar conectividad SSH
if ! ssh -q -o BatchMode=yes -o ConnectTimeout=2 "$REMOTE_HOST" exit; then
    log "ERROR" "No se puede conectar a $REMOTE_HOST"
    exit 1
fi

# Leer el archivo línea por línea y procesar cada archivo
while IFS= read -r REMOTE_FILE || [[ -n "$REMOTE_FILE" ]]; do
    # Saltar líneas vacías
    if [[ -z "$REMOTE_FILE" ]]; then
        continue
    fi
    
    # Eliminar espacios en blanco al inicio/final
    REMOTE_FILE=$(echo "$REMOTE_FILE" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')
    
    TOTAL_ARCHIVOS=$((TOTAL_ARCHIVOS + 1))
    log "INFO" "Procesando archivo [$TOTAL_ARCHIVOS]: $REMOTE_FILE"
    
    # Verificar si el archivo existe en el remoto
    if ssh "$REMOTE_HOST" "test -f \"$REMOTE_PATH$REMOTE_FILE\""; then
        log "INFO" "Archivo encontrado: $REMOTE_PATH$REMOTE_FILE"
        
        # Transferir el archivo específico
        if rsync -irz -e ssh "$REMOTE_HOST:$REMOTE_PATH$REMOTE_FILE" "$DEST_DIR/"; then
            TRANSFERIDOS=$((TRANSFERIDOS + 1))
            log "SUCCESS" "Transferido correctamente: $REMOTE_FILE"
        else
            ERRORES=$((ERRORES + 1))
            log "ERROR" "Falló la transferencia de: $REMOTE_FILE (código: $?)"
        fi
    else
        NO_ENCONTRADOS=$((NO_ENCONTRADOS + 1))
        log "ERROR" "Archivo NO encontrado en remoto: $REMOTE_PATH$REMOTE_FILE"
    fi
    
    echo "" # Línea en blanco para separar en el log
    
done < "$PRECIOS_FILE"

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

# Mostrar resumen final
log "INFO" "========== RESUMEN FINAL =========="
log "INFO" "Total archivos procesados: $TOTAL_ARCHIVOS"
log "INFO" "Transferidos exitosamente: $TRANSFERIDOS"
log "INFO" "No encontrados en remoto: $NO_ENCONTRADOS"
log "INFO" "Errores en transferencia: $ERRORES"
log "INFO" "Tiempo total: ${DURATION} segundos"
log "SUCCESS" "Procesamiento completado"

# Salir con código apropiado
if [[ $ERRORES -gt 0 ]] || [[ $NO_ENCONTRADOS -gt 0 ]]; then
    exit 1
else
    exit 0
fi
