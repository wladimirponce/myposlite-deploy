#!/bin/bash
# QA por API del envío de boletas de venta por el canal REST (Sprint 04-A y 05) contra el SII simulado: lotes, consulta,
# SII caído, respuesta perdida conciliada por folio, rechazo, ningún folio nuevo en los reintentos y envío automático
# del programador. El programador se detiene mientras se prueban los casos a mano y se enciende al final.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a)
J1=$(mktemp); TMP=$(mktemp -d)
docker compose -p myposlite stop scheduler >/dev/null 2>&1
trap 'sii normal; docker compose -p myposlite start scheduler >/dev/null 2>&1; fixture borrar $T1; rm -rf $J1 $OUT $TMP' EXIT
source "$(dirname "$0")/qa-lib-dte.sh"
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
vender() { call $J1 POST pos.php $A1 centro "{\"accion\":\"vender\",\"clave\":\"$(clave)\",\"caja\":\"1\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"$1\"}],\"pagos\":[{\"medio\":\"efectivo\",\"monto\":$(($1 * 1000))}]}" >/dev/null; py "d['documento']['folio']"; }
lote() { call $J1 POST dte.php $A1 centro '{"accion":"enviar-boletas"}' >/dev/null; py "(d['lotes'], d['enviados'], d['pendientes'], d['errores'])"; }
ultimo() { call $J1 GET dte.php $A1 centro >/dev/null; py "[e for e in d['ambientes']['produccion']['envios'] if e['motivo'] == 'boletas'][0]$1"; }
recibidas() { sii; py "[(e['folios'], e['errores']) for e in (d['envios'] or {}).values() if e['huella'] == '$1']"; }
huella() { curl -s -b $J1 "$B/dte.php?envio=$1" -H "X-MyPOSLite-Empresa: $A1" -H 'X-MyPOSLite-Local: centro' | sha256sum | cut -d' ' -f1; }
estadoVenta() { call $J1 GET "pos.php?venta=$1" $A1 centro >/dev/null; py "d['documento']['estado']"; }

expect "ingreso administrador" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
sii normal
expect "boletas en producción" 200 "$(produccion $J1 $A1 11111111-1)"
call $J1 POST ubicaciones.php $A1 centro '{"tipo":"caja","codigo":"1","nombre":"Caja 1","localCodigo":"centro"}' >/dev/null
call $J1 POST productos.php $A1 centro "$(prod QA-BEB 'Bebida QA' "$(pres Unidad '' 1 1000)")" >/dev/null
UNI=$(call $J1 GET productos.php $A1 centro >/dev/null; py "[x['id'] for p in d['productos'] if p['sku'] == 'QA-BEB' for x in p['presentaciones']][0]")
call $J1 POST pos.php $A1 centro '{"accion":"abrir","caja":"1","montoInicial":0}' >/dev/null

echo "--- Lote normal"
expect "tres ventas con folios 1, 2 y 3" "1 2 3" "$(vender 1) $(vender 2) $(vender 3)"
expect "un lote enviado" "(1, 1, 0, [])" "$(lote)"
E1=$(ultimo "['id']")
expect "el SII recibió las tres boletas en un sobre válido" "[(['39-1', '39-2', '39-3'], [])]" "$(recibidas "$(huella $E1)")"
expect "consulta del lote" aceptado "$(call $J1 POST dte.php $A1 centro "{\"accion\":\"consultar\",\"envio\":\"$E1\"}" >/dev/null; py "d['estado']")"
VENTA=$(call $J1 GET pos.php $A1 centro >/dev/null; py "[v['id'] for v in d['cajas'][0]['ventas'] if v['documento']['folio'] == 1][0]")
expect "la boleta de la venta queda aceptada" aceptado "$(estadoVenta $VENTA)"
expect "sin boletas nuevas no hay lote" "(0, 0, 0, [])" "$(lote)"

echo "--- SII caído"
sii caido
expect "dos ventas más" "4 5" "$(vender 1) $(vender 1)"
expect "el lote queda pendiente" "(1, 0, 1, [])" "$(lote)"
E2=$(ultimo "['id']"); H2=$(huella $E2)
expect "nada llegó al SII" "[]" "$(sii normal; recibidas $H2)"
expect "al volver el SII, consulta por folio (no recibido) y reenvía el mismo sobre" "(0, 1, 0, [])" "$(lote)"
expect "el SII lo recibió una vez con los folios 4 y 5" "[(['39-4', '39-5'], [])]" "$(recibidas $H2)"

echo "--- Respuesta perdida"
sii perder
expect "una venta con folio 6" 6 "$(vender 1)"
expect "el SII la recibe pero la respuesta se pierde" "(1, 0, 1, [])" "$(lote)"
E3=$(ultimo "['id']"); H3=$(huella $E3)
expect "el SII la tiene" "[(['39-6'], [])]" "$(recibidas $H3)"
expect "el reintento concilia por folio y no reenvía" "(0, 1, 0, [])" "$(lote)"
expect "sigue una sola recepción" "[(['39-6'], [])]" "$(recibidas $H3)"
expect "queda conciliado sin track ID" "enviado None El SII ya había recibido este sobre: conciliado por folio." "$(call $J1 GET dte.php $A1 centro >/dev/null; py "' '.join(str(x) for x in [(e['estado'], e['track_id'], e['glosa']) for e in d['ambientes']['produccion']['envios'] if e['id'] == '$E3'][0])")"
expect "la consulta va boleta por boleta" "aceptado 1 boletas consultadas por folio." "$(call $J1 POST dte.php $A1 centro "{\"accion\":\"consultar\",\"envio\":\"$E3\"}" >/dev/null; py "d['estado'], d['glosa']" | tr -d "(),'")"

echo "--- Rechazo"
sii rechazar
expect "una venta con folio 7" 7 "$(vender 1)"
expect "lote enviado" "(1, 1, 0, [])" "$(lote)"
E4=$(ultimo "['id']")
expect "el SII lo rechaza" rechazado "$(call $J1 POST dte.php $A1 centro "{\"accion\":\"consultar\",\"envio\":\"$E4\"}" >/dev/null; py "d['estado']")"

echo "--- Folios y envío automático"
expect "siete ventas, siete boletas y folios sin saltos" "[1, 2, 3, 4, 5, 6, 7]" "$(call $J1 GET pos.php $A1 centro >/dev/null; py "sorted(v['documento']['folio'] for v in d['cajas'][0]['ventas'])")"
expect "una venta más, folio 8" 8 "$(vender 1)"
VENTA8=$(call $J1 GET pos.php $A1 centro >/dev/null; py "[v['id'] for v in d['cajas'][0]['ventas'] if v['documento']['folio'] == 8][0]")
docker compose -p myposlite start scheduler >/dev/null 2>&1
for i in $(seq 1 30); do [ "$(estadoVenta $VENTA8)" != preparado ] && break; sleep 5; done
expect "el programador la envía solo" True "$(e=$(estadoVenta $VENTA8); [ "$e" = enviado ] || [ "$e" = aceptado ] && echo True || echo "$e")"

resultado
