#!/bin/bash
# QA por API de documentos de proveedor (Sprint 03, segundo corte): guías, facturas con guías vinculadas,
# recepción parcial, notas de crédito y débito, duplicados, repetición, concurrencia, permisos y aislamiento.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo); FX2=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a); B1=$(campo "$FX1" b)
T2=$(campo "$FX2" tag); A2=$(campo "$FX2" a)
J1=$(mktemp); J2=$(mktemp); JV=$(mktemp); TMP=$(mktemp -d)
trap 'fixture borrar $T1; fixture borrar $T2; rm -rf $J1 $J2 $JV $OUT $TMP' EXIT
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
lin() { echo "{\"presentacionId\":\"$1\",\"cantidad\":\"$2\"${3:+,\"recibida\":\"$3\"}}"; }
# doc TIPO PROVEEDOR FOLIO LINEAS [EXTRA] -> JSON de un documento con clave nueva
doc() { echo "{\"clave\":\"$(clave)\",\"tipo\":$1,\"proveedorId\":\"$2\",\"folio\":\"$3\",\"fecha\":\"2026-11-10\",\"montoTotal\":\"100000\",\"lineas\":[$4]${5:+,$5}}"; }
saldo() { call $J1 GET inventario.php $A1 ${2:-centro} >/dev/null; py "[p['saldo'] for p in d['productos'] if p['sku'] == '$1'][0]"; }
presentacion() { call $J1 GET productos.php $A1 centro >/dev/null; py "[x['id'] for p in d['productos'] if p['sku'] == '$1' for x in p['presentaciones'] if x['nombre'] == '$2'][0]"; }
proveedor() { call $1 POST contactos.php $2 centro "{\"rut\":\"$3\",\"razonSocial\":\"$4\",\"correo\":\"\",\"proveedor\":true,\"receptor\":false}" >/dev/null; py "d['id']"; }

expect "ingreso administrador 1" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
expect "ingreso administrador 2" 200 "$(entrar $J2 "$(campo "$FX2" correo)" "$(campo "$FX2" clave)" $A2 centro)"
call $J1 POST productos.php $A1 centro "$(prod QA-BEBIDA 'Bebida QA' "$(pres Unidad '' 1 1200),$(pres 'Caja 12' '' 12 12000)")" >/dev/null
call $J1 POST productos.php $A1 centro "$(prod QA-GALLETA 'Galleta QA' "$(pres Unidad '' 1 800)")" >/dev/null
UNI=$(presentacion QA-BEBIDA Unidad); CAJA=$(presentacion QA-BEBIDA 'Caja 12'); GAL=$(presentacion QA-GALLETA Unidad)
P1=$(proveedor $J1 $A1 76.086.428-5 'Distribuidora QA')
P2=$(proveedor $J1 $A1 96.511.760-1 'Mayorista QA')
call $J1 POST contactos.php $A1 centro '{"rut":"11.111.111-1","razonSocial":"Cliente QA","correo":"","proveedor":false,"receptor":true}' >/dev/null; CLIENTE=$(py "d['id']")
P_AJENO=$(proveedor $J2 $A2 76.086.428-5 'Distribuidora de otra empresa')

echo "--- Guía y luego su factura"
GUIA=$(doc 52 $P1 1001 "$(lin $CAJA 2)")
expect "guía 1001 con 2 cajas de 12" 201 "$(call $J1 POST recepciones.php $A1 centro "$GUIA")"
G1001=$(py "d['id']")
expect "el stock sube a 24 unidades" 24.000000 "$(saldo QA-BEBIDA)"
expect "repetir la misma petición" 200 "$(call $J1 POST recepciones.php $A1 centro "$GUIA")"
expect "el stock sigue en 24" 24.000000 "$(saldo QA-BEBIDA)"
expect "la misma guía con otra clave es duplicado" 409 "$(call $J1 POST recepciones.php $A1 centro "$(doc 52 $P1 1001 "$(lin $CAJA 2)")")"
expect "el mismo folio de otro proveedor sí entra" 201 "$(call $J1 POST recepciones.php $A1 centro "$(doc 52 $P2 1001 "$(lin $GAL 5)")")"
call $J1 GET "recepciones.php?guiasPendientes=$P1" $A1 centro >/dev/null
expect "Lite propone la guía 1001 del proveedor" "['1001']" "$(py "[g['folio'] for g in d['guias']]")"
expect "factura 5001 que cubre la guía" 201 "$(call $J1 POST recepciones.php $A1 centro "$(doc 33 $P1 5001 "$(lin $CAJA 2)" "\"guias\":[\"$G1001\"]")")"
F5001=$(py "d['id']")
expect "el stock sube una sola vez: sigue en 24" 24.000000 "$(saldo QA-BEBIDA)"
call $J1 GET "recepciones.php?guiasPendientes=$P1" $A1 centro >/dev/null
expect "la guía ya no figura pendiente" "[]" "$(py "d['guias']")"
expect "la misma factura de nuevo es duplicado" 409 "$(call $J1 POST recepciones.php $A1 centro "$(doc 33 $P1 5001 "$(lin $CAJA 2)")")"
expect "otra factura no puede volver a usar la guía" 409 "$(call $J1 POST recepciones.php $A1 centro "$(doc 33 $P1 5002 "$(lin $CAJA 2)" "\"guias\":[\"$G1001\"]")")"
expect "y no dejó stock al rechazarse" 24.000000 "$(saldo QA-BEBIDA)"

echo "--- Guía de 20 unidades y factura con más productos"
expect "guía 1002 por 20 unidades" 201 "$(call $J1 POST recepciones.php $A1 centro "$(doc 52 $P1 1002 "$(lin $UNI 20)")")"
G1002=$(py "d['id']")
expect "stock en 44" 44.000000 "$(saldo QA-BEBIDA)"
expect "factura 5003 por 2 cajas y 10 galletas, vinculando la guía" 201 "$(call $J1 POST recepciones.php $A1 centro "$(doc 33 $P1 5003 "$(lin $CAJA 2),$(lin $GAL 10)" "\"guias\":[\"$G1002\"]")")"
expect "solo entran las 4 unidades que la guía no cubría" 48.000000 "$(saldo QA-BEBIDA)"
expect "y las 10 galletas" 15.000000 "$(saldo QA-GALLETA)"
call $J1 GET productos.php $A1 centro >/dev/null; P_BEB=$(py "[p['id'] for p in d['productos'] if p['sku'] == 'QA-BEBIDA'][0]")
call $J1 GET "inventario.php?producto=$P_BEB" $A1 centro >/dev/null
expect "la parte pendiente queda en unidad base con su folio" "4.000000 unidad 1.000000 5003" "$(py "*[(m['cantidad'], m['presentacion'], m['equivalencia'], m['documento_folio']) for m in d['movimientos']][0]")"

echo "--- Recepción parcial"
expect "factura 5004 por 3 cajas, recibidas 2" 201 "$(call $J1 POST recepciones.php $A1 centro "$(doc 33 $P1 5004 "$(lin $CAJA 3 2)")")"
expect "entran 24 de 36" 72.000000 "$(saldo QA-BEBIDA)"
expect "recibida mayor que la del documento" 422 "$(call $J1 POST recepciones.php $A1 centro "$(doc 33 $P1 5005 "$(lin $CAJA 1 2)")")"
expect "guía con una línea no recibida" 201 "$(call $J1 POST recepciones.php $A1 centro "$(doc 52 $P1 1003 "$(lin $GAL 6 0)")")"
G1003=$(py "d['id']")
expect "la galleta no cambia" 15.000000 "$(saldo QA-GALLETA)"

echo "--- Notas"
expect "nota de crédito solo de monto" 201 "$(call $J1 POST recepciones.php $A1 centro "$(doc 61 $P1 7001 '' "\"referidoId\":\"$F5001\"")")"
expect "la nota de monto no mueve stock" 72.000000 "$(saldo QA-BEBIDA)"
expect "nota de crédito con devolución de 5 unidades" 201 "$(call $J1 POST recepciones.php $A1 centro "$(doc 61 $P1 7002 "$(lin $UNI 5)" "\"referidoId\":\"$F5001\"")")"
expect "la devolución descuenta 5" 67.000000 "$(saldo QA-BEBIDA)"
expect "devolución mayor que el stock" 409 "$(call $J1 POST recepciones.php $A1 centro "$(doc 61 $P1 7003 "$(lin $GAL 99)" "\"referidoId\":\"$F5001\"")")"
call $J1 GET recepciones.php $A1 centro >/dev/null
expect "la nota rechazada no quedó registrada" "[]" "$(py "[x['folio'] for x in d['documentos'] if x['folio'] == '7003']")"
expect "nota de débito de monto" 201 "$(call $J1 POST recepciones.php $A1 centro "$(doc 56 $P1 8001 '' "\"referidoId\":\"$F5001\"")")"
expect "nota de débito con mercadería" 422 "$(call $J1 POST recepciones.php $A1 centro "$(doc 56 $P1 8002 "$(lin $UNI 1)" "\"referidoId\":\"$F5001\"")")"
expect "nota sin factura referida" 422 "$(call $J1 POST recepciones.php $A1 centro "$(doc 61 $P1 7004 '')")"
expect "nota que refiere una guía" 422 "$(call $J1 POST recepciones.php $A1 centro "$(doc 61 $P1 7005 '' "\"referidoId\":\"$G1002\"")")"
expect "nota que refiere la factura de otro proveedor" 422 "$(call $J1 POST recepciones.php $A1 centro "$(doc 61 $P2 7006 '' "\"referidoId\":\"$F5001\"")")"
expect "factura que refiere otro documento" 422 "$(call $J1 POST recepciones.php $A1 centro "$(doc 33 $P1 5006 "$(lin $UNI 1)" "\"referidoId\":\"$F5001\"")")"

echo "--- Validaciones"
expect "tipo de documento inválido" 422 "$(call $J1 POST recepciones.php $A1 centro "$(doc 39 $P1 9001 "$(lin $UNI 1)")")"
expect "factura sin líneas" 422 "$(call $J1 POST recepciones.php $A1 centro "$(doc 33 $P1 9002 '')")"
expect "contacto que no es proveedor" 422 "$(call $J1 POST recepciones.php $A1 centro "$(doc 52 $CLIENTE 9003 "$(lin $UNI 1)")")"
expect "proveedor de otra empresa" 422 "$(call $J1 POST recepciones.php $A1 centro "$(doc 52 $P_AJENO 9004 "$(lin $UNI 1)")")"
expect "folio cero" 422 "$(call $J1 POST recepciones.php $A1 centro "$(doc 52 $P1 0 "$(lin $UNI 1)")")"
expect "guía de otro proveedor en la factura" 422 "$(call $J1 POST recepciones.php $A1 centro "$(doc 33 $P2 5007 "$(lin $UNI 1)" "\"guias\":[\"$G1002\"]")")"
expect "una guía vinculada en una guía" 422 "$(call $J1 POST recepciones.php $A1 centro "$(doc 52 $P1 1004 "$(lin $UNI 1)" "\"guias\":[\"$G1002\"]")")"
expect "el stock no cambió con los rechazos" 67.000000 "$(saldo QA-BEBIDA)"

echo "--- Concurrencia"
expect "guía 1010 de 12 unidades" 201 "$(call $J1 POST recepciones.php $A1 centro "$(doc 52 $P1 1010 "$(lin $UNI 12)")")"
G1010=$(py "d['id']")
paralelo() {
  printf '%s' "$2" | curl -s -o /dev/null -w '%{http_code}' -b $J1 -X POST "$B/recepciones.php" -H 'Content-Type: application/json' \
    -H 'X-MyPOSLite-Request: 1' -H "X-MyPOSLite-Empresa: $A1" -H 'X-MyPOSLite-Local: centro' --data-binary @- > $TMP/$1
}
for i in 1 2 3 4 5 6; do paralelo f$i "$(doc 33 $P1 $((6000 + i)) "$(lin $UNI 12)" "\"guias\":[\"$G1010\"]")" & done; wait
expect "6 facturas simultáneas sobre la misma guía: una aceptada, 5 rechazadas" "1 5" "$(cat $TMP/f* | grep -o 201 | wc -l | tr -d ' ') $(cat $TMP/f* | grep -o 409 | wc -l | tr -d ' ')"
expect "la guía entró al stock una sola vez" 79.000000 "$(saldo QA-BEBIDA)"
MISMO=$(doc 52 $P1 1011 "$(lin $UNI 3)")
for i in 1 2 3 4 5 6; do paralelo r$i "$MISMO" & done; wait
expect "6 reintentos simultáneos del mismo documento: uno creado y 5 reconocidos" "1 5" "$(cat $TMP/r* | grep -o 201 | wc -l | tr -d ' ') $(cat $TMP/r* | grep -o 200 | wc -l | tr -d ' ')"
expect "el stock sube 3 una sola vez" 82.000000 "$(saldo QA-BEBIDA)"

echo "--- Traspaso, permisos y aislamiento"
expect "una guía de Centro no se vincula desde Norte" 200 "$(call $J1 PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"norte\"}")"
expect "factura en Norte con la guía de Centro" 422 "$(call $J1 POST recepciones.php $A1 norte "$(doc 33 $P1 5010 "$(lin $UNI 3)" "\"guias\":[\"$G1003\"]")")"
call $J1 GET "recepciones.php?guiasPendientes=$P1" $A1 norte >/dev/null
expect "Norte no ve las guías pendientes de Centro" "[]" "$(py "d['guias']")"
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"centro\"}" >/dev/null
call $J2 GET recepciones.php $A2 centro >/dev/null
expect "la empresa 2 no ve documentos de la empresa 1" 0 "$(py "len(d['documentos'])")"
expect "la empresa 2 no ve guías pendientes ajenas" "[]" "$(call $J2 GET "recepciones.php?guiasPendientes=$P1" $A2 centro >/dev/null; py "d['guias']")"
call $J2 POST productos.php $A2 centro "$(prod QA-AJENO 'Producto de otra empresa' "$(pres Unidad '' 1 100)")" >/dev/null
call $J2 GET productos.php $A2 centro >/dev/null; PRES2=$(py "d['productos'][0]['presentaciones'][0]['id']")
expect "la empresa 2 no vincula una guía ajena" 422 "$(call $J2 POST recepciones.php $A2 centro "$(doc 33 $P_AJENO 5001 "$(lin $PRES2 1)" "\"guias\":[\"$G1010\"]")")"
expect "la empresa 2 no refiere una factura ajena" 422 "$(call $J2 POST recepciones.php $A2 centro "$(doc 61 $P_AJENO 7001 '' "\"referidoId\":\"$F5001\"")")"
expect "la empresa 2 no recibe con una presentación ajena" 422 "$(call $J2 POST recepciones.php $A2 centro "$(doc 52 $P_AJENO 1001 "$(lin $UNI 1)")")"
expect "la empresa 2 usa el mismo folio y RUT con su proveedor" 201 "$(call $J2 POST recepciones.php $A2 centro "$(doc 33 $P_AJENO 5001 "$(lin $PRES2 4)")")"
expect "el stock de la empresa 1 no cambió" 82.000000 "$(saldo QA-BEBIDA)"
expect "encabezado de empresa ajena" 409 "$(call $J2 GET recepciones.php $A1 centro)"
VC="qa-created-$T1@example.invalid"; VP="Qa$(head -c 12 /dev/urandom | base64 | tr -d '/+=')9x"
call $J1 POST usuarios.php $A1 centro "{\"correo\":\"$VC\",\"nombre\":\"Vendedor QA\",\"clave\":\"$VP\",\"rol\":\"vendedor\",\"locales\":[\"centro\"],\"cajas\":[]}" >/dev/null
entrar $JV $VC $VP $A1 centro >/dev/null
expect "vendedor no consulta documentos" 403 "$(call $JV GET recepciones.php $A1 centro)"
expect "vendedor no ingresa documentos" 403 "$(call $JV POST recepciones.php $A1 centro "$(doc 52 $P1 1020 "$(lin $UNI 1)")")"
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$B1\",\"localCodigo\":\"sur\"}" >/dev/null
expect "contador consulta documentos" 200 "$(call $J1 GET recepciones.php $B1 sur)"
expect "contador no ingresa documentos" 403 "$(call $J1 POST recepciones.php $B1 sur "$(doc 52 $P1 1021 "$(lin $UNI 1)")")"

resultado
