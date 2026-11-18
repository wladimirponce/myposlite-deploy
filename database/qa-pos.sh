#!/bin/bash
# QA por API de caja y ventas (Sprint 05, primer corte): turnos por caja, cajas asignadas, venta con boleta en la misma
# transacción, idempotencia ante doble clic y reintentos simultáneos, presentaciones en unidades base, venta por peso,
# verificación de edad, boleta exenta, pagos y vuelto, saldo negativo con aviso, última unidad en dos cajas,
# ingresos y retiros, arqueo con dos vendedores, reimpresión sin reemisión, trazabilidad hasta la venta, demostración
# y permisos. Requiere el SII simulado: la empresa pasa por la certificación de boletas para emitir en producción.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo); FX2=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a); B1=$(campo "$FX1" b)
T2=$(campo "$FX2" tag); A2=$(campo "$FX2" a)
J1=$(mktemp); J2=$(mktemp); JV=$(mktemp); TMP=$(mktemp -d)
trap 'fixture borrar $T1; fixture borrar $T2; rm -rf $J1 $J2 $JV $OUT $TMP' EXIT
source "$(dirname "$0")/qa-lib-dte.sh"
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
presentacion() { call $J1 GET productos.php $A1 centro >/dev/null; py "[x['id'] for p in d['productos'] if p['sku'] == '$1' for x in p['presentaciones'] if x['nombre'] == '$2'][0]"; }
saldo() { call $J1 GET inventario.php $A1 centro >/dev/null; py "[p['saldo'] for p in d['productos'] if p['sku'] == '$1'][0]"; }
lin() { echo "{\"presentacionId\":\"$1\",\"cantidad\":\"$2\"}"; }
# venta JAR EMPRESA CAJA CLAVE LINEAS PAGOS [EXTRA]
venta() { call $1 POST pos.php $2 centro "{\"accion\":\"vender\",\"clave\":\"$4\",\"caja\":\"$3\",\"lineas\":[$5],\"pagos\":[$6]${7:+,$7}}"; }
efectivo() { echo "{\"medio\":\"efectivo\",\"monto\":$1${2:+,\"recibido\":$2}}"; }
tarjeta() { echo "{\"medio\":\"tarjeta\",\"monto\":$1}"; }
caja() { call $1 GET pos.php $2 centro >/dev/null; py "[c for c in d['cajas'] if c['codigo'] == '$3'][0]$4"; }

expect "ingreso administrador" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
expect "ingreso administrador 2" 200 "$(entrar $J2 "$(campo "$FX2" correo)" "$(campo "$FX2" clave)" $A2 centro)"
for c in 1 2; do call $J1 POST ubicaciones.php $A1 centro "{\"tipo\":\"caja\",\"codigo\":\"$c\",\"nombre\":\"Caja $c\",\"localCodigo\":\"centro\"}" >/dev/null; done
VC="qa-created-$T1@example.invalid"; VP="Qa$(head -c 12 /dev/urandom | base64 | tr -d '/+=')9x"
call $J1 POST usuarios.php $A1 centro "{\"correo\":\"$VC\",\"nombre\":\"Vendedora QA\",\"clave\":\"$VP\",\"rol\":\"vendedor\",\"locales\":[\"centro\"],\"cajas\":[{\"localCodigo\":\"centro\",\"codigo\":\"1\"}]}" >/dev/null
expect "ingreso vendedora" 200 "$(entrar $JV "$VC" "$VP" $A1 centro)"
call $J1 POST productos.php $A1 centro "$(prod QA-BEB 'Bebida QA' "$(pres Unidad 7801 1 1000),$(pres 'Pack 6' 7806 6 5500)")" >/dev/null
call $J1 POST productos.php $A1 centro "$(prod QA-CIG 'Cigarrillos QA' "$(pres Cajetilla '' 1 5200),$(pres 'Cartón' '' 10 50000)" unidad true)" >/dev/null
call $J1 POST productos.php $A1 centro "$(prod QA-QUESO 'Queso QA' "$(pres Granel '' 1 9990)" kg)" >/dev/null
PRODUCTO_EXENTO=$(prod QA-LIBRO 'Libro QA' "$(pres Unidad '' 1 10000)"); call $J1 POST productos.php $A1 centro "${PRODUCTO_EXENTO/\"afectoIva\":true/\"afectoIva\":false}" >/dev/null
UNI=$(presentacion QA-BEB Unidad); PACK=$(presentacion QA-BEB 'Pack 6'); CAJ=$(presentacion QA-CIG Cajetilla); CAR=$(presentacion QA-CIG 'Cartón')
GRA=$(presentacion QA-QUESO Granel); LIB=$(presentacion QA-LIBRO Unidad)
call $J1 POST contactos.php $A1 centro '{"rut":"76.086.428-5","razonSocial":"Distribuidora QA","correo":"","proveedor":true,"receptor":false}' >/dev/null; P1=$(py "d['id']")
call $J1 POST recepciones.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":52,\"proveedorId\":\"$P1\",\"folio\":\"7001\",\"fecha\":\"2026-11-10\",\"montoTotal\":\"10000\",\"lineas\":[$(lin $UNI 12)]}" >/dev/null; GUIA=$(py "d['id']")
call $J1 POST inventario.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":\"inicial\",\"lineas\":[$(lin $CAR 3),$(lin $GRA 2)]}" >/dev/null

echo "--- Turnos y cajas"
expect "vender sin turno" 409 "$(venta $JV $A1 1 $(clave) "$(lin $UNI 1)" "$(efectivo 1000)")"
expect "la vendedora no abre una caja que no tiene asignada" 403 "$(call $JV POST pos.php $A1 centro '{"accion":"abrir","caja":"2","montoInicial":0}')"
expect "la vendedora abre su caja con 20.000" 201 "$(call $JV POST pos.php $A1 centro '{"accion":"abrir","caja":"1","montoInicial":20000}')"
expect "un segundo turno en la misma caja" 409 "$(call $J1 POST pos.php $A1 centro '{"accion":"abrir","caja":"1","montoInicial":0}')"
expect "la vendedora solo ve su caja" "['1']" "$(call $JV GET pos.php $A1 centro >/dev/null; py "[c['codigo'] for c in d['cajas']]")"
expect "sin boletas en producción no se vende" 409 "$(venta $JV $A1 1 $(clave) "$(lin $UNI 1)" "$(efectivo 1000)")"
expect "certificación de boletas y paso a producción" 200 "$(produccion $J1 $A1 11111111-1)"
expect "la caja informa boletas habilitadas" True "$(call $JV GET pos.php $A1 centro >/dev/null; py "d['boletasHabilitadas']")"

echo "--- Venta con boleta"
V1=$(clave)
expect "2 unidades y un pack de bebida en efectivo con 10.000" 201 "$(venta $JV $A1 1 $V1 "$(lin $UNI 2),$(lin $PACK 1)" "$(efectivo 7500 10000)")"
expect "total, vuelto y boleta 39 folio 1 preparada" "7500 2500 39 1 preparado" "$(py "d['total'], d['vuelto'], d['documento']['tipo'], d['documento']['folio'], d['documento']['estado']" | tr -d "(),'")"
VENTA1=$(py "d['id']")
expect "descuenta 8 unidades base" 4.000000 "$(saldo QA-BEB)"
expect "repetir la misma venta (doble clic)" 200 "$(venta $JV $A1 1 $V1 "$(lin $UNI 2),$(lin $PACK 1)" "$(efectivo 7500 10000)")"
expect "es la misma venta y boleta" "$VENTA1 1 True" "$(py "d['id'], d['documento']['folio'], d['repetida']" | tr -d "(),'")"
V2=$(clave)
for i in 1 2 3 4 5; do venta $JV $A1 1 $V2 "$(lin $UNI 1)" "$(efectivo 1000)" > $TMP/r$i & done; wait
expect "cinco reintentos simultáneos crean una sola venta y un solo descuento" "3.000000 2" "$(saldo QA-BEB) $(caja $JV $A1 1 "['turno']['ventasPorMedio']['efectivo'] // 1000 - 6")"
expect "y una sola boleta nueva" "[2, 1]" "$(caja $JV $A1 1 "['ventas']" >/dev/null; caja $JV $A1 1 "" >/dev/null; py "[v['documento']['folio'] for v in [c for c in d['cajas'] if c['codigo'] == '1'][0]['ventas']]")"

echo "--- Presentaciones, peso, edad y exento"
expect "cigarrillos sin confirmar la edad" 422 "$(venta $JV $A1 1 $(clave) "$(lin $CAJ 1),$(lin $CAR 1)" "$(tarjeta 55200)")"
expect "cajetilla y cartón con la edad confirmada, con tarjeta" 201 "$(venta $JV $A1 1 $(clave) "$(lin $CAJ 1),$(lin $CAR 1)" "$(tarjeta 55200)" '"edadConfirmada":true')"
expect "descuenta 11 cajetillas de 30" 19.000000 "$(saldo QA-CIG)"
expect "medio kilo de queso" 201 "$(venta $JV $A1 1 $(clave) "$(lin $GRA 0.5)" "$(efectivo 4995)")"
expect "monto redondeado y 0,5 kg descontados" "4995 1.500000" "$(py "d['total']") $(saldo QA-QUESO)"
expect "un libro exento emite boleta exenta 41" 201 "$(venta $JV $A1 1 $(clave) "$(lin $LIB 1)" "$(efectivo 10000)")"
expect "boleta 41 sin IVA" "41 1 10000 0 10000" "$(py "d['documento']['tipo'], d['documento']['folio'], d['exento'], d['iva'], d['total']" | tr -d "(),'")"
expect "pagos que no suman el total" 422 "$(venta $JV $A1 1 $(clave) "$(lin $UNI 1)" "$(efectivo 900)")"
expect "efectivo recibido menor que el monto" 422 "$(venta $JV $A1 1 $(clave) "$(lin $UNI 1)" "$(efectivo 1000 500)")"

echo "--- Saldo negativo y última unidad"
expect "vender 5 bebidas con 3 en stock" 201 "$(venta $JV $A1 1 $(clave) "$(lin $UNI 5)" "$(efectivo 5000)")"
expect "se permite con aviso" "['Bebida QA quedó con saldo -2 en este local.'] -2.000000" "$(py "d['avisos']") $(saldo QA-BEB)"
call $J1 POST pos.php $A1 centro '{"accion":"abrir","caja":"2","montoInicial":5000}' >/dev/null
call $J1 POST inventario.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":\"ajuste_entrada\",\"causa\":\"Conteo\",\"lineas\":[$(lin $UNI 3)]}" >/dev/null
venta $JV $A1 1 $(clave) "$(lin $UNI 1)" "$(efectivo 1000)" > $TMP/u1 & venta $J1 $A1 2 $(clave) "$(lin $UNI 1)" "$(efectivo 1000)" > $TMP/u2 & wait
expect "la última unidad vendida a la vez en dos cajas: ambas ventas, saldo -1" "201 201 -1.000000" "$(cat $TMP/u1) $(cat $TMP/u2) $(saldo QA-BEB)"

echo "--- Caja, arqueo y reimpresión"
R=$(clave)
expect "retiro operativo de 5.000" 200 "$(call $JV POST pos.php $A1 centro "{\"accion\":\"movimiento\",\"caja\":\"1\",\"tipo\":\"retiro\",\"monto\":5000,\"causa\":\"Depósito en banco\",\"clave\":\"$R\"}")"
expect "repetir el retiro no lo duplica" True "$(call $JV POST pos.php $A1 centro "{\"accion\":\"movimiento\",\"caja\":\"1\",\"tipo\":\"retiro\",\"monto\":5000,\"causa\":\"Depósito en banco\",\"clave\":\"$R\"}" >/dev/null; py "d['repetido']")"
expect "ingreso de 1.000 para sencillo" 200 "$(call $JV POST pos.php $A1 centro "{\"accion\":\"movimiento\",\"caja\":\"1\",\"tipo\":\"ingreso\",\"monto\":1000,\"causa\":\"Sencillo\",\"clave\":\"$(clave)\"}")"
expect "retiro sin causa" 422 "$(call $JV POST pos.php $A1 centro "{\"accion\":\"movimiento\",\"caja\":\"1\",\"tipo\":\"retiro\",\"monto\":100,\"causa\":\"\",\"clave\":\"$(clave)\"}")"
# Efectivo de la caja 1: 7.500 + 1.000 + 4.995 + 10.000 + 5.000 + 1.000 = 29.495; tarjeta 55.200.
expect "efectivo esperado: 20.000 + 29.495 + 1.000 − 5.000" "45495 {'efectivo': 29495, 'tarjeta': 55200, 'transferencia': 0}" \
  "$(caja $JV $A1 1 "['turno']['efectivoEsperado']") $(caja $JV $A1 1 "['turno']['ventasPorMedio']")"
expect "reimprimir no genera otra boleta" "1 39 True" "$(call $JV GET "pos.php?venta=$VENTA1" $A1 centro >/dev/null; py "d['documento']['folio'], d['documento']['tipo'], d['documento']['ted'].startswith('<TED')" | tr -d "(),'")"
expect "el detalle trae líneas y pagos" "2 [('efectivo', 7500, 10000)]" "$(py "len(d['lineas'])") $(py "[(p['medio'], p['monto'], p['recibido']) for p in d['pagos']]")"
expect "cierre con 45.000 contados" 200 "$(call $JV POST pos.php $A1 centro '{"accion":"cerrar","caja":"1","efectivoContado":45000}')"
expect "diferencia de −495 y caja cerrada" "-495 False" "$(py "d['diferencia'], d['abierto']" | tr -d "(),'")"
expect "venta en caja cerrada" 409 "$(venta $JV $A1 1 $(clave) "$(lin $UNI 1)" "$(efectivo 1000)")"
expect "reporte por vendedor de la caja 2" "[('Vendedor ficticio QA', 1, 1000)]" "$(caja $J1 $A1 2 "['turno']['vendedores']" | sed "s/{'nombre': '\([^']*\)', 'ventas': \([0-9]*\), 'total': \([0-9]*\)}/('\1', \2, \3)/g")"

echo "--- Trazabilidad hasta la venta"
call $J1 GET "recepciones.php?trazabilidad=$GUIA" $A1 centro >/dev/null
expect "la guía de 12 bebidas muestra lo vendido" "12.000000 0 {'venta': '12.000000'}" "$(py "' '.join(str(x) for x in [(p['movido'], p['queda'], p['salidas']) for p in d['productos']][0])")"

echo "--- Demostración y permisos"
call $J2 POST ubicaciones.php $A2 centro '{"tipo":"caja","codigo":"1","nombre":"Caja 1","localCodigo":"centro"}' >/dev/null
call $J2 POST productos.php $A2 centro "$(prod QA-BEB 'Bebida QA' "$(pres Unidad '' 1 1000)")" >/dev/null
UNI2=$(call $J2 GET productos.php $A2 centro >/dev/null; py "[x['id'] for p in d['productos'] if p['sku'] == 'QA-BEB' for x in p['presentaciones']][0]")
docker compose -p myposlite exec -T database sh -c "mariadb -u myposlite -p\"\$MARIADB_PASSWORD\" myposlite -e 'UPDATE empresas SET es_demo = TRUE WHERE id = $A2'"
call $J2 POST pos.php $A2 centro '{"accion":"abrir","caja":"1","montoInicial":0}' >/dev/null
expect "la empresa de demostración vende sin documento tributario" "201 None" "$(venta $J2 $A2 1 $(clave) "$(lin $UNI2 1)" "$(efectivo 1000)") $(py "d['documento']")"
expect "otra empresa no ve la venta" 404 "$(call $J2 GET "pos.php?venta=$VENTA1" $A2 centro)"
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$B1\",\"localCodigo\":\"sur\"}" >/dev/null
expect "el contador consulta" 200 "$(call $J1 GET pos.php $B1 sur)"
expect "el contador no vende" 403 "$(call $J1 POST pos.php $B1 sur "{\"accion\":\"vender\",\"clave\":\"$(clave)\",\"caja\":\"1\",\"lineas\":[$(lin $UNI 1)],\"pagos\":[$(efectivo 1000)]}")"

resultado
