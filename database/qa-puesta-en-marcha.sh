#!/bin/bash
# QA por API de la barra de avance de puesta en marcha. Datos ficticios, se retiran al final.
cd "$(dirname "$0")/.." || exit 1
B=http://127.0.0.1:${MYPOSLITE_FRONTEND_PORT:-5177}/api
J=$(mktemp); V=$(mktemp); OUT=$(mktemp)
FX=$(docker compose -p myposlite exec -T backend php database/qa-sesion-fixture.php crear-catalogo)
get() { echo "$FX" | sed -E "s/.*\"$1\":\"?([^\",}]*)\"?.*/\1/"; }
TAG=$(get tag); MAIL=$(get correo); PASS=$(get clave); A=$(get a); Bc=$(get b); C=$(get c)
trap 'docker compose -p myposlite exec -T backend php database/qa-sesion-fixture.php borrar $TAG; rm -f $J $V $OUT' EXIT
ok=0; bad=0
call() { # JAR METHOD PATH EMPRESA BODY
  curl -s -o $OUT -w '%{http_code}' -b $1 -c $1 -X "$2" "$B/$3" \
    -H 'Content-Type: application/json' -H 'X-MyPOSLite-Request: 1' -H "X-MyPOSLite-Empresa: $4" -H 'X-MyPOSLite-Local: centro' \
    ${5:+--data "$5"}
}
expect() { if [ "$2" = "$3" ]; then ok=$((ok+1)); echo "OK   $1"; else bad=$((bad+1)); echo "FALLA $1: esperado $2, obtenido $3 :: $(cat $OUT)"; fi; }
pasos() { call $J GET puesta-en-marcha.php $1 >/dev/null; sed -E 's/\{"codigo":"([a-z-]+)","hecho":(true|false),"disponible":(true|false)\}/\1=\2\/\3/g; s/.*"pasos":\[//; s/\]\}//' $OUT; }

expect "sin sesión" 401 "$(call $J GET puesta-en-marcha.php $A)"
call $J POST session.php '' "{\"correo\":\"$MAIL\",\"clave\":\"$PASS\"}" >/dev/null
expect "sin local seleccionado" 403 "$(call $J GET puesta-en-marcha.php $A)"
call $J PATCH session.php '' "{\"empresaId\":\"$A\",\"localCodigo\":\"centro\"}" >/dev/null
expect "empresa nueva: nada hecho" "local-caja=false/true,catalogo=false/true,correo-cierre=false/true,certificacion=false/true,primera-boleta=false/false" "$(pasos $A)"
grep -q '"demo":false' $OUT && { ok=$((ok+1)); echo "OK   no es demostración"; } || { bad=$((bad+1)); echo "FALLA demo"; }
expect "solo GET" 405 "$(call $J POST puesta-en-marcha.php $A '{}')"
expect "encabezado de empresa ajena" 409 "$(call $J GET puesta-en-marcha.php $C)"

expect "alta de caja" 201 "$(call $J POST ubicaciones.php $A '{"tipo":"caja","codigo":"1","nombre":"Caja 1","localCodigo":"centro"}')"
expect "tras la caja" "local-caja=true/true,catalogo=false/true,correo-cierre=false/true,certificacion=false/true,primera-boleta=false/false" "$(pasos $A)"
expect "alta de producto" 201 "$(call $J POST productos.php $A '{"sku":"QA-1","nombre":"Producto QA","unidadBase":"unidad","afectoIva":true,"presentaciones":[{"nombre":"Unidad","equivalencia":"1","precio":"1000"}]}')"
call $J GET destinatarios.php $A >/dev/null; REV=$(sed -E 's/.*"revision":"?([^",}]*)"?.*/\1/' $OUT)
expect "destinatario principal" 200 "$(call $J PUT destinatarios.php $A "{\"revision\":\"$REV\",\"principal\":\"cierre-$TAG@example.invalid\",\"adicionales\":[]}")"
expect "tres pasos listos" "local-caja=true/true,catalogo=true/true,correo-cierre=true/true,certificacion=false/true,primera-boleta=false/false" "$(pasos $A)"

VP="Qa$(head -c 12 /dev/urandom | base64 | tr -d '/+=')9x"
expect "alta de vendedor" 201 "$(call $J POST usuarios.php $A "{\"correo\":\"qa-created-$TAG@example.invalid\",\"nombre\":\"Vendedor QA\",\"clave\":\"$VP\",\"rol\":\"vendedor\",\"locales\":[\"centro\"],\"cajas\":[]}")"
call $V POST session.php '' "{\"correo\":\"qa-created-$TAG@example.invalid\",\"clave\":\"$VP\"}" >/dev/null
call $V PATCH session.php '' "{\"empresaId\":\"$A\",\"localCodigo\":\"centro\"}" >/dev/null
expect "vendedor sin acceso" 403 "$(call $V GET puesta-en-marcha.php $A)"

call $J PATCH session.php '' "{\"empresaId\":\"$Bc\",\"localCodigo\":\"sur\"}" >/dev/null
expect "contador en B no ve el avance de A" "local-caja=false/true,catalogo=false/true,correo-cierre=false/true,certificacion=false/true,primera-boleta=false/false" "$(pasos $Bc)"
docker compose -p myposlite exec -T backend php -r 'require "backend/config/database.php"; conectarBaseLite()->prepare("UPDATE empresas SET es_demo = 1 WHERE id = ? AND razon_social LIKE ?")->execute([$argv[1], "QA-%"]);' "$Bc"
expect "demostración omite la certificación" "local-caja=false/true,catalogo=false/true,correo-cierre=false/true,primera-boleta=false/false" "$(pasos $Bc)"
grep -q '"demo":true' $OUT && { ok=$((ok+1)); echo "OK   marcada como demostración"; } || { bad=$((bad+1)); echo "FALLA demo B"; }

echo; echo "Resultado: $ok correctas, $bad fallas"
