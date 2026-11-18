#!/bin/bash
# QA por API de la venta en contingencia (Sprint 05, ampliación): ventas guardadas sin conexión y sincronizadas con su
# clave: cinco ventas, cinco cobros, un descuento de stock por venta y ninguna boleta duplicada; repetir la sincronización
# no cambia nada; sin promociones para cuadrar con lo cobrado a precio de lista; última unidad vendida sin conexión en dos
# cajas, con saldo negativo y aviso.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a)
J1=$(mktemp); TMP=$(mktemp -d)
trap 'fixture borrar $T1; rm -rf $J1 $OUT $TMP' EXIT
source "$(dirname "$0")/qa-lib-dte.sh"
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
saldo() { call $J1 GET inventario.php $A1 centro >/dev/null; py "[p['saldo'] for p in d['productos'] if p['sku'] == '$1'][0]"; }
# sincronizar CLAVE CAJA PRESENTACION CANTIDAD MONTO [CONTINGENCIA]: lo que la caja envía al volver la conexión.
sincronizar() { call $J1 POST pos.php $A1 centro "{\"accion\":\"vender\",\"contingencia\":${6:-true},\"clave\":\"$1\",\"caja\":\"$2\",\"lineas\":[{\"presentacionId\":\"$3\",\"cantidad\":\"$4\"}],\"pagos\":[{\"medio\":\"efectivo\",\"monto\":$5}]}"; }
ventas() { call $J1 GET pos.php $A1 centro >/dev/null; py "sorted(v['documento']['folio'] for c in d['cajas'] for v in c['ventas'])"; }

expect "ingreso administrador" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
sii normal
expect "boletas en producción" 200 "$(produccion $J1 $A1 11111111-1)"
for c in 1 2; do call $J1 POST ubicaciones.php $A1 centro "{\"tipo\":\"caja\",\"codigo\":\"$c\",\"nombre\":\"Caja $c\",\"localCodigo\":\"centro\"}" >/dev/null; done
call $J1 POST productos.php $A1 centro "$(prod QA-BEB 'Bebida QA' "$(pres Unidad '' 1 1000)")" >/dev/null
call $J1 POST productos.php $A1 centro "$(prod QA-ULT 'Último QA' "$(pres Unidad '' 1 500)")" >/dev/null
call $J1 GET productos.php $A1 centro >/dev/null
BEB=$(py "[x['id'] for p in d['productos'] if p['sku'] == 'QA-BEB' for x in p['presentaciones']][0]"); ULT=$(py "[x['id'] for p in d['productos'] if p['sku'] == 'QA-ULT' for x in p['presentaciones']][0]")
call $J1 POST promociones.php $A1 centro "{\"presentacionId\":\"$BEB\",\"nombre\":\"2x1\",\"tipo\":\"2x1\",\"alcance\":\"empresa\",\"desde\":\"2026-01-01\",\"hasta\":\"2027-12-31\"}" >/dev/null
call $J1 POST inventario.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":\"inicial\",\"lineas\":[{\"presentacionId\":\"$BEB\",\"cantidad\":\"20\"},{\"presentacionId\":\"$ULT\",\"cantidad\":\"1\"}]}" >/dev/null
for c in 1 2; do call $J1 POST pos.php $A1 centro "{\"accion\":\"abrir\",\"caja\":\"$c\",\"montoInicial\":0}" >/dev/null; done

echo "--- Cinco ventas sin conexión"
CLAVES=(); for i in 1 2 3 4 5; do CLAVES+=($(clave)); done
expect "sin la marca de contingencia, el precio de lista no cuadra con el 2x1" 422 "$(sincronizar ${CLAVES[0]} 1 $BEB 2 2000 false)"
R=""; for k in "${CLAVES[@]}"; do R="$R $(sincronizar $k 1 $BEB 2 2000)"; done
expect "al volver la conexión se registran las cinco, a precio de lista" " 201 201 201 201 201" "$R"
expect "cinco boletas, sin duplicados" "[1, 2, 3, 4, 5]" "$(ventas)"
expect "un descuento de stock por venta" 10.000000 "$(saldo QA-BEB)"
expect "cinco cobros de 2.000 en efectivo" 10000 "$(call $J1 GET pos.php $A1 centro >/dev/null; py "[c for c in d['cajas'] if c['codigo'] == '1'][0]['turno']['ventasPorMedio']['efectivo']")"
R=""; for k in "${CLAVES[@]}"; do R="$R $(sincronizar $k 1 $BEB 2 2000)"; done
expect "repetir la sincronización reconoce cada venta" " 200 200 200 200 200" "$R"
expect "y no cambia nada" "[1, 2, 3, 4, 5] 10.000000" "$(ventas) $(saldo QA-BEB)"

echo "--- Última unidad en dos cajas sin conexión"
U1=$(clave); U2=$(clave)
sincronizar $U1 1 $ULT 1 500 > $TMP/u1 & sincronizar $U2 2 $ULT 1 500 > $TMP/u2 & wait
expect "ambas ventas se aceptan al sincronizar" "201 201" "$(cat $TMP/u1) $(cat $TMP/u2)"
expect "el saldo queda en -1, como decidió Wladimir" -1.000000 "$(saldo QA-ULT)"

resultado
