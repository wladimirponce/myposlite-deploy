#!/bin/bash
# QA por API de devoluciones (Sprint 05 con la nota de crédito 61 de 04-B) contra el SII simulado: permiso, nota de
# crédito referida a la boleta (corrige montos si es parcial, anula si es total), reposición en el lote de origen,
# reintegro en el arqueo, límites, repetición, envío de la nota en un EnvioDTE válido y trazabilidad neta.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo); FX2=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a)
T2=$(campo "$FX2" tag); A2=$(campo "$FX2" a)
J1=$(mktemp); J2=$(mktemp); JV=$(mktemp); TMP=$(mktemp -d)
docker compose -p myposlite stop scheduler >/dev/null 2>&1
trap 'sii normal; docker compose -p myposlite start scheduler >/dev/null 2>&1; fixture borrar $T1; fixture borrar $T2; rm -rf $J1 $J2 $JV $OUT $TMP' EXIT
source "$(dirname "$0")/qa-lib-dte.sh"
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
saldo() { call $J1 GET inventario.php $A1 centro >/dev/null; py "[p['saldo'] for p in d['productos'] if p['sku'] == '$1'][0]"; }
devolver() { call $1 POST pos.php $A1 centro "{\"accion\":\"devolver\",\"clave\":\"$2\",\"caja\":\"1\",\"venta\":\"$3\",\"lineas\":[$4],\"medio\":\"$5\",\"motivo\":\"$6\",\"fisica\":${7:-true}}"; }

expect "ingreso administrador" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
sii normal
expect "boletas en producción" 200 "$(produccion $J1 $A1 11111111-1)"
RUT=$(call $J1 GET dte.php $A1 centro >/dev/null; py "d['rut']")
call $J1 POST ubicaciones.php $A1 centro '{"tipo":"caja","codigo":"1","nombre":"Caja 1","localCodigo":"centro"}' >/dev/null
VC="qa-created-$T1@example.invalid"; VP="Qa$(head -c 12 /dev/urandom | base64 | tr -d '/+=')9x"
call $J1 POST usuarios.php $A1 centro "{\"correo\":\"$VC\",\"nombre\":\"Vendedora QA\",\"clave\":\"$VP\",\"rol\":\"vendedor\",\"locales\":[\"centro\"],\"cajas\":[{\"localCodigo\":\"centro\",\"codigo\":\"1\"}]}" >/dev/null
entrar $JV "$VC" "$VP" $A1 centro >/dev/null
call $J1 POST productos.php $A1 centro "$(prod QA-BEB 'Bebida QA' "$(pres Unidad '' 1 1000),$(pres 'Pack 6' '' 6 5500)")" >/dev/null
UNI=$(call $J1 GET productos.php $A1 centro >/dev/null; py "[x['id'] for p in d['productos'] if p['sku'] == 'QA-BEB' for x in p['presentaciones'] if x['nombre'] == 'Unidad'][0]")
PACK=$(py "[x['id'] for p in d['productos'] if p['sku'] == 'QA-BEB' for x in p['presentaciones'] if x['nombre'] == 'Pack 6'][0]")
call $J1 POST contactos.php $A1 centro '{"rut":"76.086.428-5","razonSocial":"Distribuidora QA","correo":"","proveedor":true,"receptor":false}' >/dev/null; P1=$(py "d['id']")
call $J1 POST recepciones.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":52,\"proveedorId\":\"$P1\",\"folio\":\"9001\",\"fecha\":\"2026-11-10\",\"montoTotal\":\"10000\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"20\"}]}" >/dev/null; GUIA=$(py "d['id']")
call $J1 POST pos.php $A1 centro '{"accion":"abrir","caja":"1","montoInicial":10000}' >/dev/null
call $J1 POST pos.php $A1 centro "{\"accion\":\"vender\",\"clave\":\"$(clave)\",\"caja\":\"1\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"2\"},{\"presentacionId\":\"$PACK\",\"cantidad\":\"1\"}],\"pagos\":[{\"medio\":\"efectivo\",\"monto\":7500}]}" >/dev/null
VENTA=$(py "d['id']")
expect "venta de 8 bebidas con boleta 1" "1 12.000000" "$(py "d['documento']['folio']") $(saldo QA-BEB)"

echo "--- Permisos y habilitación"
expect "la vendedora no devuelve" 403 "$(devolver $JV $(clave) $VENTA '{"linea":1,"cantidad":"1"}' efectivo 'Producto dañado')"
expect "sin notas de crédito en producción no hay devolución" 409 "$(devolver $J1 $(clave) $VENTA '{"linea":1,"cantidad":"1"}' efectivo 'Producto dañado')"
for paso in en_certificacion 'visto_bueno","atencion":"QA61","fecha":"2026-11-10' produccion; do
  call $J1 POST dte.php $A1 centro "{\"accion\":\"habilitacion\",\"tipo\":61,\"estado\":\"$paso\"}" >/dev/null
done
subir $J1 $A1 caf "$(caf $RUT 61 1 50 300)" >/dev/null
expect "notas de crédito en producción" True "$(call $J1 GET pos.php $A1 centro >/dev/null; py "d['notasHabilitadas']")"

echo "--- Devolución parcial"
D1=$(clave)
expect "devuelve 1 unidad dañada en efectivo" 201 "$(devolver $J1 $D1 $VENTA '{"linea":1,"cantidad":"1"}' efectivo 'Producto dañado')"
expect "nota de crédito 61 folio 1 por 1.000, sin anular" "1000 61 1 False" "$(py "d['monto'], d['nota']['tipo'], d['nota']['folio'], d['anula']" | tr -d "(),'")"
expect "vuelve al saldo" 13.000000 "$(saldo QA-BEB)"
expect "repetir la devolución no la duplica" "200 13.000000" "$(devolver $J1 $D1 $VENTA '{"linea":1,"cantidad":"1"}' efectivo 'Producto dañado') $(saldo QA-BEB)"
expect "no se devuelve más de lo vendido" 422 "$(devolver $J1 $(clave) $VENTA '{"linea":1,"cantidad":"2"}' efectivo 'Error')"
expect "sin motivo" 422 "$(devolver $J1 $(clave) $VENTA '{"linea":1,"cantidad":"1"}' efectivo '')"

echo "--- Devolución del resto"
expect "devuelve lo que queda, sin devolución física, con tarjeta" 201 "$(devolver $J1 $(clave) $VENTA '{"linea":1,"cantidad":"1"},{"linea":2,"cantidad":"1"}' tarjeta 'Cliente desiste' false)"
expect "la nota 2 anula la boleta por 6.500" "6500 2 True" "$(py "d['monto'], d['nota']['folio'], d['anula']" | tr -d "(),'")"
expect "sin devolución física el saldo no cambia" 13.000000 "$(saldo QA-BEB)"
expect "nada más que devolver" 422 "$(devolver $J1 $(clave) $VENTA '{"linea":2,"cantidad":"1"}' efectivo 'Otra')"
expect "la venta lista sus dos devoluciones" "[(1000, 1, True), (6500, 2, False)]" "$(call $J1 GET "pos.php?venta=$VENTA" $A1 centro >/dev/null; py "[(x['monto'], x['nota']['folio'], x['fisica']) for x in d['devoluciones']]")"
expect "el arqueo descuenta solo el reintegro en efectivo" "16500 7500" "$(call $J1 GET pos.php $A1 centro >/dev/null; py "d['cajas'][0]['turno']['efectivoEsperado'], d['cajas'][0]['turno']['devoluciones']" | tr -d "(),'")"

echo "--- Envío de las notas al SII"
expect "un lote de boleta y dos EnvioDTE de notas" "(3, 3, 0, [])" "$(call $J1 POST dte.php $A1 centro '{"accion":"enviar-boletas"}' >/dev/null; py "(d['lotes'], d['enviados'], d['pendientes'], d['errores'])")"
expect "el SII validó las notas contra EnvioDTE_v10" "[['61-1'], ['61-2']] [[], []]" "$(sii; py "[e['folios'] for e in d['envios'].values() if e['rut_company'] == '$RUT' and e['folios'] and e['folios'][0].startswith('61')]") $(py "[e['errores'] for e in d['envios'].values() if e['rut_company'] == '$RUT' and e['folios'] and e['folios'][0].startswith('61')]")"
NOTA=$(call $J1 GET dte.php $A1 centro >/dev/null; py "[e['id'] for e in d['ambientes']['produccion']['envios'] if e['motivo'] == 'documentos'][0]")
expect "consulta la nota" aceptado "$(call $J1 POST dte.php $A1 centro "{\"accion\":\"consultar\",\"envio\":\"$NOTA\"}" >/dev/null; py "d['estado']")"
NOTA_XML=$(curl -s -b $J1 "$B/dte.php?envio=$NOTA" -H "X-MyPOSLite-Empresa: $A1" -H 'X-MyPOSLite-Local: centro')
expect "la nota referencia la boleta 39 folio 1" True "$(printf '%s' "$NOTA_XML" | grep -q '<TpoDocRef>39</TpoDocRef><FolioRef>1</FolioRef>' && echo True || echo False)"

echo "--- Trazabilidad"
call $J1 GET "recepciones.php?trazabilidad=$GUIA" $A1 centro >/dev/null
expect "la guía muestra 7 vendidas netas y 13 que quedan" "{'venta': '7.000000'} 13.000000" "$(py "[(p['salidas'], p['queda']) for p in d['productos']][0]" | sed "s/^(//; s/)$//; s/, '/ /; s/'$//")"

echo "--- Demostración"
expect "ingreso administrador 2" 200 "$(entrar $J2 "$(campo "$FX2" correo)" "$(campo "$FX2" clave)" $A2 centro)"
call $J2 POST ubicaciones.php $A2 centro '{"tipo":"caja","codigo":"1","nombre":"Caja 1","localCodigo":"centro"}' >/dev/null
call $J2 POST productos.php $A2 centro "$(prod QA-BEB 'Bebida QA' "$(pres Unidad '' 1 1000)")" >/dev/null
UNI2=$(call $J2 GET productos.php $A2 centro >/dev/null; py "[x['id'] for p in d['productos'] if p['sku'] == 'QA-BEB' for x in p['presentaciones']][0]")
docker compose -p myposlite exec -T database sh -c "mariadb -u myposlite -p\"\$MARIADB_PASSWORD\" myposlite -e 'UPDATE empresas SET es_demo = TRUE WHERE id = $A2'"
call $J2 POST pos.php $A2 centro '{"accion":"abrir","caja":"1","montoInicial":0}' >/dev/null
call $J2 POST pos.php $A2 centro "{\"accion\":\"vender\",\"clave\":\"$(clave)\",\"caja\":\"1\",\"lineas\":[{\"presentacionId\":\"$UNI2\",\"cantidad\":\"1\"}],\"pagos\":[{\"medio\":\"efectivo\",\"monto\":1000}]}" >/dev/null; V2=$(py "d['id']")
expect "la demostración devuelve sin nota de crédito" "201 None" "$(call $J2 POST pos.php $A2 centro "{\"accion\":\"devolver\",\"clave\":\"$(clave)\",\"caja\":\"1\",\"venta\":\"$V2\",\"lineas\":[{\"linea\":1,\"cantidad\":\"1\"}],\"medio\":\"efectivo\",\"motivo\":\"Prueba\",\"fisica\":true}") $(py "d['nota']")"

resultado
