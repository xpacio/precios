#!/bin/bash
set -euo pipefail

SRC_DIR="${1:-/srv/precios}"
API_BASE="http://precios.servicios.care/api/v1"

if [ ! -d "$SRC_DIR" ]; then
    echo "ERROR: El directorio $SRC_DIR no existe"
    exit 1
fi

SYNCED=0
SKIPPED=0
FAILED=0

echo "=== updatePrecios ==="
echo "Origen: $SRC_DIR"
echo ""

shopt -s globstar nullglob

for f in "$SRC_DIR"/**/*; do
    [ -f "$f" ] || continue
    ##[[ "$f" == *.br ]] && continue

    rel_path="${f#$SRC_DIR/}"

    [[ "$f" == *.br ]] && continue

    echo -n "$rel_path ... "

    resp=$(curl -s -w "\n%{http_code}" "$API_BASE/updateFile/$rel_path")
    http_code=$(echo "$resp" | tail -1)
    body=$(echo "$resp" | sed '$d')

    if [ "$http_code" = "200" ]; then
        if echo "$body" | grep -q '"SKIP"'; then
            echo "SKIP"
            SKIPPED=$((SKIPPED + 1))
        elif echo "$body" | grep -q '"OK"'; then
            accion=$(echo "$body" | grep -oP '"accion"\s*:\s*"\K[^"]+')
            echo "OK ($accion)"
            SYNCED=$((SYNCED + 1))
        else
            echo "$body"
            SYNCED=$((SYNCED + 1))
        fi
    else
        echo "FAIL (HTTP $http_code)"
        FAILED=$((FAILED + 1))
    fi
done

echo ""
echo "=== Resumen ==="
echo "  Sincronizados: $SYNCED"
echo "  Sin cambios:   $SKIPPED"
echo "  Fallidos:      $FAILED"

exit $FAILED
