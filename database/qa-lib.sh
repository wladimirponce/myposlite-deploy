#!/bin/bash
# Funciones comunes de los QA por API. Usar con: source "$(dirname "$0")/qa-lib.sh"
# Requiere Lite levantado en Docker local y python en el host.
cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1
export PYTHONIOENCODING=utf-8
B=http://127.0.0.1:${MYPOSLITE_FRONTEND_PORT:-5177}/api
OUT=$(mktemp)
ok=0; bad=0

fixture() { docker compose -p myposlite exec -T backend php database/qa-sesion-fixture.php "$@"; }
campo() { echo "$1" | sed -E "s/.*\"$2\":\"?([^\",}]*)\"?.*/\1/"; }

# call JAR METHOD PATH EMPRESA LOCAL [BODY] -> imprime el código HTTP y deja la respuesta en $OUT.
# El cuerpo va por entrada estándar: como argumento, curl en Windows altera los caracteres no ASCII.
call() {
  printf '%s' "$6" | curl -s -o $OUT -w '%{http_code}' -b $1 -c $1 -X "$2" "$B/$3" \
    -H 'Content-Type: application/json' -H 'X-MyPOSLite-Request: 1' -H "X-MyPOSLite-Empresa: $4" -H "X-MyPOSLite-Local: $5" \
    ${6:+--data-binary @-}
}
# expect NOMBRE ESPERADO OBTENIDO
expect() { if [ "$2" = "$3" ]; then ok=$((ok+1)); echo "OK   $1"; else bad=$((bad+1)); echo "FALLA $1: esperado [$2], obtenido [$3] :: $(head -c 300 $OUT)"; fi; }
# py EXPRESION -> evalúa la expresión de Python sobre la última respuesta JSON, disponible como d.
py() { python -c "import json,sys; d=json.load(open(sys.argv[1], encoding='utf-8')); print($1)" "$OUT"; }
# entrar JAR CORREO CLAVE EMPRESA LOCAL
entrar() { call $1 POST session.php '' '' "{\"correo\":\"$2\",\"clave\":\"$3\"}" >/dev/null; call $1 PATCH session.php '' '' "{\"empresaId\":\"$4\",\"localCodigo\":\"$5\"}"; }
# prod SKU NOMBRE PRESENTACIONES [UNIDAD] [VERIFICAR_EDAD] y pres NOMBRE CODIGO EQUIVALENCIA PRECIO arman el JSON de un producto.
prod() { echo "{\"sku\":\"$1\",\"nombre\":\"$2\",\"unidadBase\":\"${4:-unidad}\",\"afectoIva\":true,\"verificarEdad\":${5:-false},\"presentaciones\":[$3]}"; }
pres() { echo "{\"nombre\":\"$1\",\"codigoBarras\":\"$2\",\"equivalencia\":\"$3\",\"precio\":\"$4\"}"; }
resultado() { echo; echo "Resultado: $ok correctas, $bad fallas"; [ $bad -eq 0 ]; }
