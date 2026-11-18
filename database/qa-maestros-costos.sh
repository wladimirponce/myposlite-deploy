#!/bin/bash
# QA por API de impuestos específicos, costo, margen y proveedor habitual. Datos ficticios, se retiran al final.
cd "$(dirname "$0")/.." || exit 1
B=http://127.0.0.1:${MYPOSLITE_FRONTEND_PORT:-5177}/api
J=$(mktemp)
FX=$(docker compose -p myposlite exec -T backend php database/qa-sesion-fixture.php crear-catalogo)
get() { echo "$FX" | sed -E "s/.*\"$1\":\"?([^\",}]*)\"?.*/\1/"; }
TAG=$(get tag); MAIL=$(get correo); PASS=$(get clave); A=$(get a); Bc=$(get b); C=$(get c)
trap 'docker compose -p myposlite exec -T backend php database/qa-sesion-fixture.php borrar $TAG; rm -f $J' EXIT
ok=0; bad=0
# call METHOD PATH EMPRESA BODY -> imprime "status body"
call() {
  local m=$1 p=$2 e=$3 d=$4
  curl -s -o /tmp/qa_body -w '%{http_code}' -b $J -c $J -X "$m" "$B/$p" \
    -H 'Content-Type: application/json' -H 'X-MyPOSLite-Request: 1' -H "X-MyPOSLite-Empresa: $e" -H "X-MyPOSLite-Local: $LOCAL" \
    ${d:+--data "$d"}
}
expect() { # nombre esperado obtenido
  if [ "$2" = "$3" ]; then ok=$((ok+1)); echo "OK   $1 ($3)"; else bad=$((bad+1)); echo "FALLA $1: esperado $2, obtenido $3 :: $(cat /tmp/qa_body)"; fi
}
body() { cat /tmp/qa_body; }

LOCAL=centro
expect "login" 200 "$(call POST session.php '' "{\"correo\":\"$MAIL\",\"clave\":\"$PASS\"}")"
expect "contexto A/centro" 200 "$(call PATCH session.php '' "{\"empresaId\":\"$A\",\"localCodigo\":\"centro\"}")"

echo "--- Impuestos"
expect "GET impuestos" 200 "$(call GET impuestos.php $A)"
body | grep -q '"codigo":"licores".*"porcentaje":"31.500000".*"origen":"sii"' && echo "OK   licores 31,5 % origen SII" || { bad=$((bad+1)); echo "FALLA tasa base licores :: $(body)"; }
N=$(body | grep -o '"origen"' | wc -l); expect "seis impuestos base" 6 "$N"
expect "ajuste licores 2026-01-01" 201 "$(call POST impuestos.php $A '{"codigo":"licores","vigenteDesde":"2026-01-01","porcentaje":"35.5","utmPorUnidad":"0"}')"
expect "ajuste repetido" 409 "$(call POST impuestos.php $A '{"codigo":"licores","vigenteDesde":"2026-01-01","porcentaje":"36","utmPorUnidad":"0"}')"
expect "tasa sobre 100" 422 "$(call POST impuestos.php $A '{"codigo":"licores","vigenteDesde":"2026-02-01","porcentaje":"101","utmPorUnidad":"0"}')"
expect "fecha inválida" 422 "$(call POST impuestos.php $A '{"codigo":"licores","vigenteDesde":"2026-02-30","porcentaje":"10","utmPorUnidad":"0"}')"
expect "impuesto inexistente" 422 "$(call POST impuestos.php $A '{"codigo":"inventado","vigenteDesde":"2026-02-01","porcentaje":"10","utmPorUnidad":"0"}')"
expect "antes de la tasa base" 422 "$(call POST impuestos.php $A '{"codigo":"licores","vigenteDesde":"2010-01-01","porcentaje":"10","utmPorUnidad":"0"}')"
call GET impuestos.php $A >/dev/null
body | grep -q '"codigo":"licores"[^}]*"porcentaje":"35.500000"[^}]*"origen":"empresa"' && { ok=$((ok+1)); echo "OK   hoy rige el ajuste de empresa"; } || { bad=$((bad+1)); echo "FALLA ajuste vigente :: $(body)"; }
call GET 'impuestos.php?fecha=2025-12-31' $A >/dev/null
body | grep -q '"codigo":"licores"[^}]*"porcentaje":"31.500000"[^}]*"origen":"sii"' && { ok=$((ok+1)); echo "OK   2025-12-31 conserva la tasa anterior"; } || { bad=$((bad+1)); echo "FALLA fecha anterior :: $(body)"; }
expect "empresa ajena por encabezado" 409 "$(call GET impuestos.php $C)"

echo "--- Contactos"
expect "alta proveedor" 201 "$(call POST contactos.php $A '{"rut":"76.086.428-5","razonSocial":"Distribuidora QA","correo":"","proveedor":true,"receptor":false}')"
PROV=$(body | sed -E 's/.*"id":"([0-9]+)".*/\1/')
expect "alta receptor" 201 "$(call POST contactos.php $A '{"rut":"11.111.111-1","razonSocial":"Cliente QA","correo":"","proveedor":false,"receptor":true}')"
RECEP=$(body | sed -E 's/.*"id":"([0-9]+)".*/\1/')
expect "filtro proveedores" 200 "$(call GET 'contactos.php?proveedores=1' $A)"
N=$(body | grep -o '"rut"' | wc -l); expect "solo un proveedor en el filtro" 1 "$N"

echo "--- Productos"
P='"sku":"QA-PISCO","nombre":"Pisco QA","unidadBase":"unidad","afectoIva":true,"verificarEdad":true,"presentaciones":[{"nombre":"Botella","codigoBarras":"","equivalencia":"1","precio":"5950"},{"nombre":"Caja 6","codigoBarras":"","equivalencia":"6","precio":"30000"}]'
expect "alta con costo, margen, proveedor e impuesto" 201 "$(call POST productos.php $A "{$P,\"impuestoCodigo\":\"licores\",\"costo\":\"3500.5\",\"margenMinimo\":\"25\",\"proveedorId\":\"$PROV\"}")"
PID=$(body | sed -E 's/.*"id":"([0-9]+)".*/\1/')
call GET productos.php $A >/dev/null
body | grep -q '"impuesto_codigo":"licores","costo":"3500.50","margen_minimo":"25.00","proveedor_habitual_id":"'$PROV'","proveedor_habitual":"Distribuidora QA"' && { ok=$((ok+1)); echo "OK   el listado devuelve los cuatro campos"; } || { bad=$((bad+1)); echo "FALLA listado :: $(body)"; }
expect "costo negativo" 422 "$(call POST productos.php $A "{${P/QA-PISCO/QA-X1},\"costo\":\"-1\"}")"
expect "costo con tres decimales" 422 "$(call POST productos.php $A "{${P/QA-PISCO/QA-X2},\"costo\":\"1.234\"}")"
expect "margen 100" 422 "$(call POST productos.php $A "{${P/QA-PISCO/QA-X3},\"margenMinimo\":\"100\"}")"
expect "proveedor que es solo receptor" 422 "$(call POST productos.php $A "{${P/QA-PISCO/QA-X4},\"proveedorId\":\"$RECEP\"}")"
expect "proveedor inexistente" 422 "$(call POST productos.php $A "{${P/QA-PISCO/QA-X5},\"proveedorId\":\"99999999\"}")"
expect "impuesto fuera de catálogo" 422 "$(call POST productos.php $A "{${P/QA-PISCO/QA-X6},\"impuestoCodigo\":\"inventado\"}")"
call GET productos.php $A >/dev/null; N=$(body | grep -o '"sku"' | wc -l); expect "los rechazos no dejaron productos" 1 "$N"
PRES=$(body | grep -o '"presentaciones":\[.*\]' | grep -o '{"id":"[0-9]*","producto_id":[0-9]*,"nombre":"[^"]*"' | sed -E 's/\{"id":"([0-9]+)".*"nombre":"([^"]*)"/\1=\2/' | tr '\n' ' ')
ID_B=$(echo "$PRES" | tr ' ' '\n' | grep '=Botella' | cut -d= -f1); ID_C=$(echo "$PRES" | tr ' ' '\n' | grep '=Caja 6' | cut -d= -f1)
E='"sku":"QA-PISCO","nombre":"Pisco QA","unidadBase":"unidad","afectoIva":true,"verificarEdad":true,"presentaciones":[{"id":"'$ID_B'","nombre":"Botella","codigoBarras":"","equivalencia":"1","precio":"5950"}]'
expect "edición sin campos opcionales" 200 "$(call PATCH productos.php $A "{\"id\":\"$PID\",\"revision\":\"1\",$E}")"
call GET productos.php $A >/dev/null
body | grep -q '"impuesto_codigo":"licores","costo":"3500.50","margen_minimo":"25.00","proveedor_habitual_id":"'$PROV'"' && { ok=$((ok+1)); echo "OK   omitir conserva los valores"; } || { bad=$((bad+1)); echo "FALLA conservar :: $(body)"; }
expect "quitar marca de proveedor en uso" 409 "$(call PATCH contactos.php $A "{\"id\":\"$PROV\",\"rut\":\"76.086.428-5\",\"razonSocial\":\"Distribuidora QA\",\"correo\":\"\",\"proveedor\":false,\"receptor\":true}")"
expect "edición con null" 200 "$(call PATCH productos.php $A "{\"id\":\"$PID\",\"revision\":\"2\",$E,\"impuestoCodigo\":null,\"costo\":null,\"margenMinimo\":null,\"proveedorId\":null}")"
call GET productos.php $A >/dev/null
body | grep -q '"impuesto_codigo":null,"costo":null,"margen_minimo":null,"proveedor_habitual_id":null,"proveedor_habitual":null' && { ok=$((ok+1)); echo "OK   null retira los valores"; } || { bad=$((bad+1)); echo "FALLA retirar :: $(body)"; }
expect "quitar marca de proveedor ya libre" 200 "$(call PATCH contactos.php $A "{\"id\":\"$PROV\",\"rut\":\"76.086.428-5\",\"razonSocial\":\"Distribuidora QA\",\"correo\":\"\",\"proveedor\":false,\"receptor\":true}")"
expect "revisión obsoleta" 409 "$(call PATCH productos.php $A "{\"id\":\"$PID\",\"revision\":\"1\",$E,\"costo\":\"1\"}")"

echo "--- Contador en empresa B (solo lectura) y aislamiento"
LOCAL=sur
expect "contexto B/sur" 200 "$(call PATCH session.php '' "{\"empresaId\":\"$Bc\",\"localCodigo\":\"sur\"}")"
expect "contador lee impuestos" 200 "$(call GET impuestos.php $Bc)"
body | grep -q '"codigo":"licores"[^}]*"porcentaje":"31.500000"[^}]*"origen":"sii"' && { ok=$((ok+1)); echo "OK   el ajuste de A no afecta a B"; } || { bad=$((bad+1)); echo "FALLA aislamiento de ajustes :: $(body)"; }
body | grep -q '"ajustes":\[\],"puedeEditar":false' && { ok=$((ok+1)); echo "OK   B sin historial y sin edición"; } || { bad=$((bad+1)); echo "FALLA historial B :: $(body)"; }
expect "contador no ajusta impuestos" 403 "$(call POST impuestos.php $Bc '{"codigo":"vinos","vigenteDesde":"2026-01-01","porcentaje":"1","utmPorUnidad":"0"}')"
expect "contador no crea productos" 403 "$(call POST productos.php $Bc "{$P,\"costo\":\"1\"}")"
expect "catálogo de B" 200 "$(call GET productos.php $Bc)"
N=$(body | grep -o '"sku"' | wc -l); expect "B no ve productos de A" 0 "$N"
expect "encabezado de A con sesión en B" 409 "$(call GET productos.php $A)"

echo; echo "Resultado: $ok correctas, $bad fallas"
