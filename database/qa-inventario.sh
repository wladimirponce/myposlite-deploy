#!/bin/bash
# QA por API del inventario (Sprint 03, primer corte): saldos, lotes, mermas, ajustes, traspasos,
# repetición de peticiones, concurrencia, informe de quiebres, permisos y aislamiento.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo); FX2=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a); B1=$(campo "$FX1" b)
T2=$(campo "$FX2" tag); A2=$(campo "$FX2" a)
J1=$(mktemp); J2=$(mktemp); JV=$(mktemp); TMP=$(mktemp -d)
trap 'fixture borrar $T1; fixture borrar $T2; rm -rf $J1 $J2 $JV $OUT $TMP' EXIT
# Aleatoria: un contador no sirve porque op se evalúa en subshells.
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
# op TIPO LINEAS [EXTRA] -> JSON de una operación con clave nueva
op() { echo "{\"clave\":\"$(clave)\",\"tipo\":\"$1\",\"lineas\":[$2]${3:+,$3}}"; }
lin() { echo "{\"presentacionId\":\"$1\",\"cantidad\":\"$2\"${3:+,\"vencimiento\":\"$3\"}}"; }
saldo() { call $J1 GET inventario.php $A1 ${2:-centro} >/dev/null; py "[p['saldo'] for p in d['productos'] if p['sku'] == '$1'][0]"; }
presentacion() { call $J1 GET productos.php $A1 centro >/dev/null; py "[x['id'] for p in d['productos'] if p['sku'] == '$1' for x in p['presentaciones'] if x['nombre'] == '$2'][0]"; }

expect "ingreso administrador 1" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
expect "ingreso administrador 2" 200 "$(entrar $J2 "$(campo "$FX2" correo)" "$(campo "$FX2" clave)" $A2 centro)"
call $J1 POST productos.php $A1 centro "$(prod QA-BEBIDA 'Bebida QA' "$(pres Unidad '' 1 1200),$(pres 'Caja 12' '' 12 12000)")" >/dev/null; P_BEBIDA=$(py "d['id']")
call $J1 POST productos.php $A1 centro "$(prod QA-QUESO 'Queso QA' "$(pres Granel '' 1 8990),$(pres 'Bolsa 250 g' '' 0.25 2400)" kg)" >/dev/null
call $J1 POST productos.php $A1 centro "$(prod QA-CONC 'Concurrente QA' "$(pres Unidad '' 1 100)")" >/dev/null
call $J1 POST productos.php $A1 centro "$(prod QA-VACIO 'Sin stock QA' "$(pres Unidad '' 1 100)")" >/dev/null
call $J2 POST productos.php $A2 centro "$(prod QA-AJENO 'Ajeno QA' "$(pres Unidad '' 1 100)")" >/dev/null
UNI=$(presentacion QA-BEBIDA Unidad); CAJA=$(presentacion QA-BEBIDA 'Caja 12')
GRANEL=$(presentacion QA-QUESO Granel); BOLSA=$(presentacion QA-QUESO 'Bolsa 250 g'); CONC=$(presentacion QA-CONC Unidad)
call $J2 GET productos.php $A2 centro >/dev/null; AJENA=$(py "d['productos'][0]['presentaciones'][0]['id']")

echo "--- Entradas, equivalencia y repetición"
INICIAL=$(op inicial "$(lin $CAJA 2)")
expect "inventario inicial de 2 cajas de 12" 201 "$(call $J1 POST inventario.php $A1 centro "$INICIAL")"
expect "saldo de 24 unidades" 24.000000 "$(saldo QA-BEBIDA)"
expect "repetir la misma petición" 200 "$(call $J1 POST inventario.php $A1 centro "$INICIAL")"
expect "la repetición se informa" True "$(py "d['repetida']")"
expect "el saldo no cambió al repetir" 24.000000 "$(saldo QA-BEBIDA)"
EDIT="\"id\":\"$P_BEBIDA\",\"sku\":\"QA-BEBIDA\",\"nombre\":\"Bebida QA\",\"afectoIva\":true,\"presentaciones\":[{\"id\":\"$UNI\",\"nombre\":\"Unidad\",\"equivalencia\":\"1\",\"precio\":\"1200\"},{\"id\":\"$CAJA\",\"nombre\":\"Caja 10\",\"equivalencia\":\"10\",\"precio\":\"10000\"}]"
expect "cambiar la equivalencia de la caja a 10" 200 "$(call $J1 PATCH productos.php $A1 centro "{$EDIT,\"revision\":\"1\",\"unidadBase\":\"unidad\"}")"
call $J1 GET "inventario.php?producto=$P_BEBIDA" $A1 centro >/dev/null
expect "el movimiento anterior conserva caja de 12" "24.000000 Caja 12 2.000000 12.000000" "$(py "*[(m['cantidad'], m['presentacion'], m['cantidad_presentacion'], m['equivalencia']) for m in d['movimientos']][0]")"
expect "y el saldo sigue en 24" 24.000000 "$(saldo QA-BEBIDA)"
expect "una caja nueva suma 10" 201 "$(call $J1 POST inventario.php $A1 centro "$(op ajuste_entrada "$(lin $CAJA 1)" '"causa":"Conteo"')")"
expect "saldo de 34 unidades" 34.000000 "$(saldo QA-BEBIDA)"
expect "no se puede cambiar la unidad base con movimientos" 409 "$(call $J1 PATCH productos.php $A1 centro "{$EDIT,\"revision\":\"2\",\"unidadBase\":\"kg\"}")"

echo "--- Validaciones y salidas"
expect "merma sin causa" 422 "$(call $J1 POST inventario.php $A1 centro "$(op merma "$(lin $UNI 1)")")"
expect "merma de 4 unidades" 201 "$(call $J1 POST inventario.php $A1 centro "$(op merma "$(lin $UNI 4)" '"causa":"Botellas rotas"')")"
expect "saldo de 30 unidades" 30.000000 "$(saldo QA-BEBIDA)"
expect "media unidad de un producto entero" 422 "$(call $J1 POST inventario.php $A1 centro "$(op inicial "$(lin $UNI 0.5)")")"
expect "cantidad cero" 422 "$(call $J1 POST inventario.php $A1 centro "$(op inicial "$(lin $UNI 0)")")"
expect "cantidad negativa" 422 "$(call $J1 POST inventario.php $A1 centro "$(op inicial "$(lin $UNI -3)")")"
expect "presentación de otra empresa" 422 "$(call $J1 POST inventario.php $A1 centro "$(op inicial "$(lin $AJENA 1)")")"
expect "tipo desconocido" 422 "$(call $J1 POST inventario.php $A1 centro "$(op venta "$(lin $UNI 1)")")"
expect "sin clave de operación" 422 "$(call $J1 POST inventario.php $A1 centro "{\"tipo\":\"inicial\",\"lineas\":[$(lin $UNI 1)]}")"
expect "vencimiento en una salida" 422 "$(call $J1 POST inventario.php $A1 centro "$(op merma "$(lin $UNI 1 2027-01-01)" '"causa":"x"')")"
expect "salida mayor que el saldo" 409 "$(call $J1 POST inventario.php $A1 centro "$(op ajuste_salida "$(lin $UNI 31)" '"causa":"Conteo"')")"
expect "una línea sin stock anula toda la operación" 409 "$(call $J1 POST inventario.php $A1 centro "$(op merma "$(lin $UNI 5),$(lin $CONC 1)" '"causa":"Mixta"')")"
expect "el saldo quedó intacto tras los rechazos" 30.000000 "$(saldo QA-BEBIDA)"

echo "--- Peso y lotes por vencimiento"
expect "queso: 1 kg que vence en junio" 201 "$(call $J1 POST inventario.php $A1 centro "$(op inicial "$(lin $GRANEL 1 2027-06-01)")")"
expect "queso: 4 bolsas de 250 g que vencen en enero" 201 "$(call $J1 POST inventario.php $A1 centro "$(op inicial "$(lin $BOLSA 4 2027-01-01)")")"
expect "saldo de 2 kg" 2.000000 "$(saldo QA-QUESO)"
expect "merma de 1,5 kg" 201 "$(call $J1 POST inventario.php $A1 centro "$(op merma "$(lin $GRANEL 1.5)" '"causa":"Vencido"')")"
call $J1 GET productos.php $A1 centro >/dev/null; P_QUESO=$(py "[p['id'] for p in d['productos'] if p['sku'] == 'QA-QUESO'][0]")
call $J1 GET "inventario.php?producto=$P_QUESO" $A1 centro >/dev/null
expect "consumió primero el lote de enero y dejó 0,5 kg de junio" "[('2027-06-01', '0.500000')]" "$(py "[(l['vencimiento'], l['saldo']) for l in d['lotes']]")"

echo "--- Traspaso entre locales"
expect "traspaso al mismo local" 422 "$(call $J1 POST inventario.php $A1 centro "$(op traspaso "$(lin $UNI 1)" '"localDestino":"centro"')")"
expect "traspaso a un local de otra empresa" 422 "$(call $J1 POST inventario.php $A1 centro "$(op traspaso "$(lin $UNI 1)" '"localDestino":"sur"')")"
expect "traspaso de 6 unidades y 0,5 kg a Norte" 201 "$(call $J1 POST inventario.php $A1 centro "$(op traspaso "$(lin $UNI 6),$(lin $GRANEL 0.5)" '"localDestino":"norte"')")"
expect "Centro queda con 24 unidades" 24.000000 "$(saldo QA-BEBIDA)"
expect "Centro queda sin queso" 0.000000 "$(saldo QA-QUESO)"
expect "cambia al local Norte" 200 "$(call $J1 PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"norte\"}")"
expect "Norte recibe 6 unidades" 6.000000 "$(saldo QA-BEBIDA norte)"
call $J1 GET "inventario.php?producto=$P_QUESO" $A1 norte >/dev/null
expect "el lote de Norte conserva el vencimiento" "[('2027-06-01', '0.500000')] traspaso centro" "$(py "[(l['vencimiento'], l['saldo']) for l in d['lotes']], d['movimientos'][0]['tipo'], d['movimientos'][0]['local_origen']")"
expect "encabezado de un local distinto al activo" 409 "$(call $J1 GET inventario.php $A1 centro)"
expect "vuelve al local Centro" 200 "$(call $J1 PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"centro\"}")"

echo "--- Informe de quiebres"
expect "mínimo de 30 para la bebida en Centro" 200 "$(call $J1 PATCH productos.php $A1 centro "{$EDIT,\"revision\":\"2\",\"unidadBase\":\"unidad\",\"minimoLocal\":\"30\"}")"
call $J1 GET inventario.php $A1 centro >/dev/null
expect "estados por producto" "{'QA-BEBIDA': 'bajo', 'QA-CONC': 'cero', 'QA-QUESO': 'cero', 'QA-VACIO': 'cero'}" "$(py "dict(sorted((p['sku'], p['estado']) for p in d['productos']))")"
expect "entrada que supera el mínimo" 201 "$(call $J1 POST inventario.php $A1 centro "$(op ajuste_entrada "$(lin $UNI 6)" '"causa":"Conteo"')")"
call $J1 GET 'inventario.php?quiebres=1' $A1 centro >/dev/null
expect "el filtro de quiebres excluye la bebida" "['QA-CONC', 'QA-QUESO', 'QA-VACIO']" "$(py "sorted(p['sku'] for p in d['productos'])")"

echo "--- Concurrencia"
expect "5 unidades del producto concurrente" 201 "$(call $J1 POST inventario.php $A1 centro "$(op inicial "$(lin $CONC 5)")")"
paralelo() { # INDICE CUERPO -> código HTTP en $TMP/INDICE, con el tarro de sesión en solo lectura
  printf '%s' "$2" | curl -s -o /dev/null -w '%{http_code}' -b $J1 -X POST "$B/inventario.php" -H 'Content-Type: application/json' \
    -H 'X-MyPOSLite-Request: 1' -H "X-MyPOSLite-Empresa: $A1" -H 'X-MyPOSLite-Local: centro' --data-binary @- > $TMP/$1
}
for i in 1 2 3 4 5 6 7 8 9 10 11 12; do paralelo m$i "$(op merma "$(lin $CONC 1)" '"causa":"Paralela"')" & done; wait
expect "12 mermas simultáneas sobre 5 unidades: 5 aceptadas y 7 rechazadas" "5 7" "$(cat $TMP/m* | grep -o 201 | wc -l | tr -d ' ') $(cat $TMP/m* | grep -o 409 | wc -l | tr -d ' ')"
expect "el saldo queda en cero, nunca negativo" 0.000000 "$(saldo QA-CONC)"
MISMA=$(op ajuste_entrada "$(lin $CONC 3)" '"causa":"Reintento"')
for i in 1 2 3 4 5 6 7 8; do paralelo r$i "$MISMA" & done; wait
expect "8 reintentos simultáneos de una misma operación: una creada y 7 reconocidas" "1 7" "$(cat $TMP/r* | grep -o 201 | wc -l | tr -d ' ') $(cat $TMP/r* | grep -o 200 | wc -l | tr -d ' ')"
expect "el saldo sube 3 una sola vez" 3.000000 "$(saldo QA-CONC)"
call $J1 GET productos.php $A1 centro >/dev/null; P_CONC=$(py "[p['id'] for p in d['productos'] if p['sku'] == 'QA-CONC'][0]")
call $J1 GET "inventario.php?producto=$P_CONC" $A1 centro >/dev/null
expect "movimientos y lotes cuadran con el saldo" "3.0 3.0" "$(py "sum(float(m['cantidad']) for m in d['movimientos']), sum(float(l['saldo']) for l in d['lotes'])")"

echo "--- Permisos y aislamiento"
call $J2 GET inventario.php $A2 centro >/dev/null
expect "la empresa 2 solo ve su producto, sin stock" "[('QA-AJENO', '0.000000')]" "$(py "[(p['sku'], p['saldo']) for p in d['productos']]")"
expect "historial de un producto ajeno, vacío" "[] []" "$(call $J2 GET "inventario.php?producto=$P_BEBIDA" $A2 centro >/dev/null; py "d['movimientos'], d['lotes']")"
expect "encabezado de empresa ajena" 409 "$(call $J2 GET inventario.php $A1 centro)"
VC="qa-created-$T1@example.invalid"; VP="Qa$(head -c 12 /dev/urandom | base64 | tr -d '/+=')9x"
call $J1 POST usuarios.php $A1 centro "{\"correo\":\"$VC\",\"nombre\":\"Vendedor QA\",\"clave\":\"$VP\",\"rol\":\"vendedor\",\"locales\":[\"centro\"],\"cajas\":[]}" >/dev/null
entrar $JV $VC $VP $A1 centro >/dev/null
expect "vendedor no consulta el inventario" 403 "$(call $JV GET inventario.php $A1 centro)"
expect "vendedor no registra movimientos" 403 "$(call $JV POST inventario.php $A1 centro "$(op merma "$(lin $UNI 1)" '"causa":"x"')")"
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$B1\",\"localCodigo\":\"sur\"}" >/dev/null
expect "contador consulta el inventario" 200 "$(call $J1 GET inventario.php $B1 sur)"
expect "contador sin permiso de operar" False "$(py "d['puedeOperar']")"
expect "contador no registra movimientos" 403 "$(call $J1 POST inventario.php $B1 sur "$(op inicial "$(lin $UNI 1)")")"

resultado
