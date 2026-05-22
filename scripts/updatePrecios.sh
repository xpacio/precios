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

    rel_path="${f#$SRC_DIR/}"

    [[ "$f" == *.br ]] && continue

    echo -n "$rel_path ... "

    resp=$(curl -s -w "\n%{http_code}" "$API_BASE/updateFile/$rel_path")
    http_code=$(echo "$resp" | tail -1)
    body=$(echo "$resp" | sed '$d')

    if [ "$http_code" = "200" ]; then
        if echo "$body" | grep -q '"SKIP"'; then
            echo -n "SKIP"
            SKIPPED=$((SKIPPED + 1))
        elif echo "$body" | grep -q '"OK"'; then
            accion=$(echo "$body" | grep -oP '"accion"\s*:\s*"\K[^"]+')
            echo -n "OK ($accion)"
            SYNCED=$((SYNCED + 1))
        else
            echo -n "$body"
            SYNCED=$((SYNCED + 1))
        fi
    else
        echo "FAIL (HTTP $http_code)"
        FAILED=$((FAILED + 1))
        continue
    fi

    # Ensure .br exists
    br_file="${f}.br"
    if [ ! -f "$br_file" ]; then
        echo -n " → comprimiendo..."
        if php -r '
            $f = $argv[1];
            $data = file_get_contents($f);
            if ($data === false) { exit(1); }
            $compressed = brotli_compress($data, 6);
            if ($compressed === false) { exit(2); }
            $brFile = $f . ".br";
            if (file_put_contents($brFile, $compressed) === false) { exit(3); }
            $brHash = substr(hash("xxh3", $compressed), 0, 6);
            $pdo = new PDO("pgsql:host=localhost;port=5432;dbname=precios", "postgres", "password");
            $dir = dirname($f);
            $name = basename($f);
            $s = $pdo->prepare("UPDATE archivos SET comprimido = TRUE, br = ? WHERE ruta = ? AND nombre = ?");
            $s->execute([$brHash, $dir, $name]);
        ' "$f" 2>/dev/null; then
            echo "OK"
            SYNCED=$((SYNCED + 1))
        else
            echo "ERROR"
            FAILED=$((FAILED + 1))
        fi
    else
        echo ""
    fi
done

echo ""
echo "=== Resumen ==="
echo "  Sincronizados: $SYNCED"
echo "  Sin cambios:   $SKIPPED"
echo "  Fallidos:      $FAILED"

exit $FAILED
