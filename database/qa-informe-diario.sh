#!/bin/bash
# QA del correo del informe diario (Sprint 07), sobre el cierre diario por local: no sale mientras falte un local,
# desglose por local y total de la empresa, asunto con pendientes sin afirmar aceptación, actualización única cuando el
# SII responde, rechazos, día sin documentos, plazo de las 06:00 con locales sin cerrar y sin mezclar empresas.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo); FX2=$(fixture crear-catalogo); FX3=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a)
T2=$(campo "$FX2" tag); A2=$(campo "$FX2" a)
T3=$(campo "$FX3" tag); A3=$(campo "$FX3" a)
J1=$(mktemp); J2=$(mktemp); J3=$(mktemp); TMP=$(mktemp -d)
docker compose -p myposlite stop scheduler >/dev/null 2>&1
dc() { MSYS_NO_PATHCONV=1 docker compose -p myposlite exec -T "$@"; }
buzon() { dc backend php -r "echo file_get_contents('http://correo-simulado:8092/$1');" > $OUT; }
limpiar() { buzon '_control?modo=normal'; sii normal; docker compose -p myposlite start scheduler >/dev/null 2>&1; fixture borrar $T1; fixture borrar $T2; fixture borrar $T3; rm -rf $J1 $J2 $J3 $OUT $TMP; }
trap limpiar EXIT
source "$(dirname "$0")/qa-lib-dte.sh"
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
# revisar [AHORA]: revisa las jornadas y entrega lo encolado.
revisar() { dc backend php -r 'require "backend/config/database.php"; require "backend/src/InformeDiario.php"; $db = conectarBaseLite();
  echo revisarInformesDiariosCorreo($db, isset($argv[1]) ? new DateTimeImmutable($argv[1]) : null); entregarCorreosPendientes($db, 100);' ${1:+"$1"}; }
asuntos() { buzon; py "[m['asunto'] for m in d['mensajes'] if m['para'] == '$1' and 'MyPOS Lite' in m['asunto']]"; }
verificar() { REV=$(call $1 GET destinatarios.php $2 centro >/dev/null; py "d['revision']")
  call $1 PUT destinatarios.php $2 centro "{\"principal\":\"$3\",\"adicionales\":[],\"revision\":\"$REV\"}" >/dev/null
  dc backend php -r 'require "backend/config/database.php"; conectarBaseLite()->prepare("UPDATE destinatarios_cierre SET verificado_en = UTC_TIMESTAMP(6) WHERE empresa_id = ?")->execute([$argv[1]]);' $2; }
local() { call $J1 PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"$1\"}" >/dev/null; }
vender() { call $J1 POST pos.php $A1 $1 "{\"accion\":\"vender\",\"clave\":\"$(clave)\",\"caja\":\"$1\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"1\"}],\"pagos\":[{\"medio\":\"$2\",\"monto\":2000}]}" >/dev/null; }

expect "ingreso administrador" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
expect "ingreso en otra empresa" 200 "$(entrar $J2 "$(campo "$FX2" correo)" "$(campo "$FX2" clave)" $A2 centro)"
sii normal; buzon '_control?modo=normal'; buzon _vaciar
DUENA="duena-$T1@empresa.test"; OTRA="duena-$T2@empresa.test"
verificar $J1 $A1 $DUENA; verificar $J2 $A2 $OTRA
expect "boletas en producción" 200 "$(produccion $J1 $A1 11111111-1)"
call $J1 POST productos.php $A1 centro "$(prod QA-BEB 'Bebida QA' "$(pres Unidad '' 1 2000)")" >/dev/null
UNI=$(call $J1 GET productos.php $A1 centro >/dev/null; py "d['productos'][0]['presentaciones'][0]['id']")
for l in centro norte; do call $J1 POST ubicaciones.php $A1 centro "{\"tipo\":\"caja\",\"codigo\":\"$l\",\"nombre\":\"Caja $l\",\"localCodigo\":\"$l\"}" >/dev/null; done
call $J1 POST pos.php $A1 centro '{"accion":"abrir","caja":"centro","montoInicial":0}' >/dev/null
vender centro efectivo; vender centro tarjeta
call $J1 POST pos.php $A1 centro '{"accion":"cerrar","caja":"centro","efectivoContado":1900}' >/dev/null
expect "Centro cierra su jornada" 200 "$(call $J1 POST cierres.php $A1 centro)"

echo "--- Un local sin cerrar"
expect "con Norte abierto no sale el informe" "0" "$(revisar | head -c 1)"
local norte; call $J1 POST pos.php $A1 norte '{"accion":"abrir","caja":"norte","montoInicial":0}' >/dev/null
vender norte efectivo
call $J1 POST pos.php $A1 norte '{"accion":"cerrar","caja":"norte","efectivoContado":2000}' >/dev/null
expect "Norte cierra su jornada" 200 "$(call $J1 POST cierres.php $A1 norte)"; local centro

echo "--- Informe con pendientes del SII"
revisar >/dev/null
expect "sale con las cantidades, sin afirmar aceptación" "['Día cerrado en MyPOS Lite · 0 boletas aceptadas y 3 pendientes de respuesta del SII']" "$(asuntos $DUENA)"
ID=$(buzon; py "[m['id'] for m in d['mensajes'] if m['para'] == '$DUENA'][-1]")
dc backend php -r "echo file_get_contents('http://correo-simulado:8092/?id=$ID');" > $TMP/m.eml
TEXTO=$(python -c "
import email; m = email.message_from_bytes(open(r'$(ruta $TMP/m.eml)', 'rb').read())
print(next(p.get_payload(decode=True).decode() for p in m.walk() if p.get_content_type() == 'text/plain'))")
expect "desglose por local con medios de pago" "1 1" "$(echo "$TEXTO" | grep -c 'Centro: 2 ventas por \$4.000 (efectivo \$2.000, tarjeta \$2.000)') $(echo "$TEXTO" | grep -c 'Norte: 1 ventas por \$2.000')"
expect "la diferencia de caja de Centro" 1 "$(echo "$TEXTO" | grep -c 'Diferencia de caja en Caja centro: \$-100')"
expect "el total de la empresa suma los locales" 1 "$(echo "$TEXTO" | grep -c 'Total de la empresa: \$6.000')"
expect "los rangos coinciden con los folios usados" 1 "$(echo "$TEXTO" | grep -c '3 boletas, folios 1 a 3: 0 aceptados')"
expect "revisar de nuevo no duplica" 1 "$(revisar >/dev/null; asuntos $DUENA | python -c "import sys, ast; print(len(ast.literal_eval(sys.stdin.read())))")"

echo "--- Actualización cuando el SII responde"
call $J1 POST dte.php $A1 centro '{"accion":"enviar-boletas"}' >/dev/null
for e in $(call $J1 GET dte.php $A1 centro >/dev/null; py "' '.join(x['id'] for x in d['ambientes']['produccion']['envios'] if x['estado'] == 'enviado')"); do
  call $J1 POST dte.php $A1 centro "{\"accion\":\"consultar\",\"envio\":\"$e\"}" >/dev/null; done
revisar >/dev/null
expect "una actualización con todo aceptado" "Actualización · Día cerrado en MyPOS Lite · Documentos del día aceptados por el SII" "$(buzon; py "[m['asunto'] for m in d['mensajes'] if m['para'] == '$DUENA'][-1]")"
expect "y una sola" 2 "$(revisar >/dev/null; asuntos $DUENA | python -c "import sys, ast; print(len(ast.literal_eval(sys.stdin.read())))")"

echo "--- Otra empresa: día sin documentos y plazo de las 06:00"
call $J2 POST cierres.php $A2 centro >/dev/null
revisar >/dev/null
expect "con un local sin cerrar no sale antes del plazo" "[]" "$(asuntos $OTRA)"
MANANA=$(date -u -d '+1 day' +%Y-%m-%dT10:00:00+00:00)
revisar "$MANANA" >/dev/null
expect "al día siguiente sale marcando el local sin cerrar" "['Jornada con locales sin cerrar en MyPOS Lite · Sin documentos tributarios']" "$(asuntos $OTRA)"
expect "ningún informe mezcla empresas" "0 0" "$(buzon; py "len([m for m in d['mensajes'] if m['para'] == '$OTRA' and 'Bebida' in m['asunto']])") $(py "len([m for m in d['mensajes'] if m['para'] not in ('$DUENA', '$OTRA') and 'Día cerrado' in m['asunto']])")"
echo "--- Alertas de caja"
expect "ingreso en la tercera empresa" 200 "$(entrar $J3 "$(campo "$FX3" correo)" "$(campo "$FX3" clave)" $A3 centro)"
TERCERA="duena-$T3@empresa.test"; verificar $J3 $A3 $TERCERA
produccion $J3 $A3 11111111-1 >/dev/null; habilitar $J3 $A3 61
call $J3 POST productos.php $A3 centro "$(prod QA-BEB 'Bebida QA' "$(pres Unidad '' 1 2000)")" >/dev/null
U3=$(call $J3 GET productos.php $A3 centro >/dev/null; py "d['productos'][0]['presentaciones'][0]['id']")
call $J3 POST ubicaciones.php $A3 centro '{"tipo":"caja","codigo":"1","nombre":"Caja 1","localCodigo":"centro"}' >/dev/null
call $J3 POST pos.php $A3 centro '{"accion":"abrir","caja":"1","montoInicial":0}' >/dev/null
for i in 1 2 3; do
  call $J3 POST pos.php $A3 centro "{\"accion\":\"vender\",\"clave\":\"$(clave)\",\"caja\":\"1\",\"lineas\":[{\"presentacionId\":\"$U3\",\"cantidad\":\"1\"}],\"pagos\":[{\"medio\":\"efectivo\",\"monto\":2000}]}" >/dev/null; V=$(py "d['id']")
  call $J3 POST pos.php $A3 centro "{\"accion\":\"devolver\",\"clave\":\"$(clave)\",\"caja\":\"1\",\"venta\":\"$V\",\"lineas\":[{\"linea\":1,\"cantidad\":\"1\"}],\"medio\":\"efectivo\",\"motivo\":\"Cliente arrepentido\",\"fisica\":true}" >/dev/null
done
call $J3 POST pos.php $A3 centro '{"accion":"cerrar","caja":"1","efectivoContado":100}' >/dev/null
for i in 1 2; do call $J3 POST pos.php $A3 centro '{"accion":"abrir","caja":"1","montoInicial":0}' >/dev/null; call $J3 POST pos.php $A3 centro '{"accion":"cerrar","caja":"1","efectivoContado":50}' >/dev/null; done
# Inventario del día: una factura recibida deja la leche bajo su margen mínimo y con un lote que vence en tres días.
LECHE=$(prod QA-LECHE 'Leche QA' "$(pres Unidad '' 1 1190)"); call $J3 POST productos.php $A3 centro "${LECHE%\}},\"margenMinimo\":\"25\"}" >/dev/null
UL=$(call $J3 GET productos.php $A3 centro >/dev/null; py "[x['id'] for p in d['productos'] if p['sku'] == 'QA-LECHE' for x in p['presentaciones']][0]")
call $J3 POST contactos.php $A3 centro '{"rut":"76.086.428-5","razonSocial":"Lácteos QA","correo":"","proveedor":true,"receptor":false}' >/dev/null; P3=$(py "d['id']")
VENCE=$(date -d '+3 day' +%F)
call $J3 POST recepciones.php $A3 centro "{\"clave\":\"$(clave)\",\"tipo\":33,\"proveedorId\":\"$P3\",\"folio\":\"8801\",\"fecha\":\"$(date +%F)\",\"montoTotal\":\"10710\",\"lineas\":[{\"presentacionId\":\"$UL\",\"cantidad\":\"10\",\"precioUnitario\":\"900\",\"vencimiento\":\"$VENCE\"}]}" >/dev/null
call $J3 POST cierres.php $A3 centro >/dev/null
call $J3 PATCH session.php '' '' "{\"empresaId\":\"$A3\",\"localCodigo\":\"norte\"}" >/dev/null; call $J3 POST cierres.php $A3 norte >/dev/null
revisar >/dev/null
ID=$(buzon; py "[m['id'] for m in d['mensajes'] if m['para'] == '$TERCERA' and 'MyPOS Lite' in m['asunto']][-1]")
dc backend php -r "echo file_get_contents('http://correo-simulado:8092/?id=$ID');" > $TMP/m3.eml
TEXTO=$(python -c "
import email; m = email.message_from_bytes(open(r'$(ruta $TMP/m3.eml)', 'rb').read())
print(next(p.get_payload(decode=True).decode() for p in m.walk() if p.get_content_type() == 'text/plain'))")
expect "tres diferencias seguidas del mismo vendedor" 1 "$(echo "$TEXTO" | grep -c 'tuvo diferencia de caja en sus últimos tres turnos: \$100, \$50, \$50')"
expect "el lote de leche que vence en tres días" 1 "$(echo "$TEXTO" | grep -c "Centro: 1 lotes vencidos o por vencer en 7 días: Leche QA 10 unidad, vence el $(date -d '+3 day' +%d-%m-%Y)")"
expect "la leche bajo su margen mínimo por el costo de hoy" 1 "$(echo "$TEXTO" | grep -c 'Leche QA · Unidad: margen de 10 % con el costo recibido hoy, bajo el mínimo de 25 %')"
expect "tres devoluciones sobre su promedio" 1 "$(echo "$TEXTO" | grep -c 'registró 3 devoluciones en la jornada, sobre su promedio de 0,0 por día')"

resultado
