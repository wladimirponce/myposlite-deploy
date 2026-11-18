#!/bin/bash
# QA por API de la trazabilidad de un documento de proveedor (Sprint 03): lo recibido, lo que queda por local,
# mermas, devoluciones, traspasos, vencidos, guía incluida en su factura, aislamiento y permisos.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo); FX2=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a); B1=$(campo "$FX1" b)
T2=$(campo "$FX2" tag); A2=$(campo "$FX2" a)
J1=$(mktemp); J2=$(mktemp); JV=$(mktemp)
trap 'fixture borrar $T1; fixture borrar $T2; rm -f $J1 $J2 $JV $OUT' EXIT
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
lin() { echo "{\"presentacionId\":\"$1\",\"cantidad\":\"$2\"${3:+,\"vencimiento\":\"$3\"}}"; }
doc() { echo "{\"clave\":\"$(clave)\",\"tipo\":$1,\"proveedorId\":\"$2\",\"folio\":\"$3\",\"fecha\":\"2026-11-10\",\"montoTotal\":\"100000\",\"lineas\":[$4]${5:+,$5}}"; }
op() { echo "{\"clave\":\"$(clave)\",\"tipo\":\"$1\",\"lineas\":[$2]${3:+,$3}}"; }
presentacion() { call $J1 GET productos.php $A1 centro >/dev/null; py "[x['id'] for p in d['productos'] if p['sku'] == '$1' for x in p['presentaciones'] if x['nombre'] == '$2'][0]"; }
traza() { call $J1 GET "recepciones.php?trazabilidad=$1" $A1 ${2:-centro}; }
# producto NOMBRE EXPRESION -> evalúa la expresión sobre el producto de la última trazabilidad, disponible como p.
producto() { py "[$2 for p in d['productos'] if p['nombre'] == '$1'][0]"; }

expect "ingreso administrador 1" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
expect "ingreso administrador 2" 200 "$(entrar $J2 "$(campo "$FX2" correo)" "$(campo "$FX2" clave)" $A2 centro)"
call $J1 POST productos.php $A1 centro "$(prod QA-BEBIDA 'Bebida QA' "$(pres Unidad '' 1 1200),$(pres 'Caja 12' '' 12 12000)")" >/dev/null
call $J1 POST productos.php $A1 centro "$(prod QA-GALLETA 'Galleta QA' "$(pres Unidad '' 1 800)")" >/dev/null
UNI=$(presentacion QA-BEBIDA Unidad); CAJA=$(presentacion QA-BEBIDA 'Caja 12'); GAL=$(presentacion QA-GALLETA Unidad)
call $J1 POST contactos.php $A1 centro '{"rut":"76.086.428-5","razonSocial":"Distribuidora QA","correo":"","proveedor":true,"receptor":false}' >/dev/null
P1=$(py "d['id']")

echo "--- Movimientos"
expect "inventario inicial de 100 bebidas, ajeno a los documentos" 201 "$(call $J1 POST inventario.php $A1 centro "$(op inicial "$(lin $UNI 100)")")"
expect "guía 2001 con 2 cajas que vencen en 2027" 201 "$(call $J1 POST recepciones.php $A1 centro "$(doc 52 $P1 2001 "$(lin $CAJA 2 2027-12-01)")")"
G2001=$(py "d['id']")
expect "factura 6001 por 3 cajas y 10 galletas vencidas, con la guía" 201 "$(call $J1 POST recepciones.php $A1 centro "$(doc 33 $P1 6001 "$(lin $CAJA 3),$(lin $GAL 10 2026-10-01)" "\"guias\":[\"$G2001\"]")")"
F6001=$(py "d['id']")
expect "merma de 3 bebidas rotas" 201 "$(call $J1 POST inventario.php $A1 centro "$(op merma "$(lin $UNI 3)" '"causa":"Botellas rotas"')")"
expect "traspaso de 10 bebidas a Norte" 201 "$(call $J1 POST inventario.php $A1 centro "$(op traspaso "$(lin $UNI 10)" '"localDestino":"norte"')")"
expect "nota de crédito 7101 con devolución de 4" 201 "$(call $J1 POST recepciones.php $A1 centro "$(doc 61 $P1 7101 "$(lin $UNI 4)" "\"referidoId\":\"$F6001\"")")"
N7101=$(py "d['id']")
expect "cambia al local Norte" 200 "$(call $J1 PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"norte\"}")"
expect "merma de 2 en Norte" 201 "$(call $J1 POST inventario.php $A1 norte "$(op merma "$(lin $UNI 2)" '"causa":"Vencidas en góndola"')")"
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"centro\"}" >/dev/null

echo "--- Trazabilidad de la factura, que incluye su guía"
expect "consulta la factura" 200 "$(traza $F6001)"
expect "encabezado de la factura" "33 6001 Distribuidora QA" "$(py "d['documento']['tipo'], d['documento']['folio'], d['documento']['proveedor']" | tr -d "(),'")"
expect "incluye la guía 2001" "['2001']" "$(py "[g['folio'] for g in d['guias']]")"
expect "líneas del documento con su presentación" "[('Bebida QA', 'Caja 12', '3.000000', '3.000000'), ('Galleta QA', 'Unidad', '10.000000', '10.000000')]" \
  "$(py "[(l['producto'], l['presentacion'], l['cantidad'], l['recibida']) for l in d['lineas']]")"
expect "entraron 36 bebidas entre guía y factura, no 60" 36.000000 "$(producto 'Bebida QA' "p['movido']")"
expect "quedan 19 en Centro y 8 en Norte" "[('centro', '19.000000'), ('norte', '8.000000')]" "$(producto 'Bebida QA' "[(l['local'], l['saldo']) for l in p['locales']]")"
expect "quedan 27 en total" 27.000000 "$(producto 'Bebida QA' "p['queda']")"
expect "se perdieron 5 por merma en ambos locales" 5.000000 "$(producto 'Bebida QA' "p['salidas']['merma']")"
expect "se devolvieron 4 al proveedor" 4.000000 "$(producto 'Bebida QA' "p['salidas']['devolucion_proveedor']")"
expect "el traspaso no cuenta como salida" False "$(producto 'Bebida QA' "'traspaso' in p['salidas']")"
expect "cuadra: entrado = queda + salidas" True "$(producto 'Bebida QA' "abs(float(p['movido']) - float(p['queda']) - sum(map(float, p['salidas'].values()))) < 1e-9")"
expect "las galletas siguen completas" "10.000000 10.000000" "$(producto 'Galleta QA' "p['movido'] + ' ' + p['queda']")"
expect "y figuran vencidas" 10.000000 "$(producto 'Galleta QA' "p['vencido']")"
expect "el inventario inicial no se mezcla" 27.000000 "$(producto 'Bebida QA' "p['queda']")"
expect "eventos: devolución, traspaso y mermas con local, causa y folio" \
  "[('merma', 'norte', 'Vencidas en góndola', None), ('devolucion_proveedor', 'centro', 'Nota de crédito 7101', '7101'), ('traspaso', 'centro', None, None), ('merma', 'centro', 'Botellas rotas', None)]" \
  "$(py "[(e['tipo'], e['local'], e['causa'], e['documento_folio']) for e in d['eventos']]")"
expect "el traspaso informa su destino y cantidad" "('Centro', 'Norte', '10.000000')" "$(py "[(e['local_nombre'], e['destino_nombre'], e['cantidad']) for e in d['eventos'] if e['tipo'] == 'traspaso'][0]")"
expect "los locales llevan su nombre" "['Centro', 'Norte']" "$(producto 'Bebida QA' "[l['nombre'] for l in p['locales']]")"

echo "--- Trazabilidad de la guía y de la nota"
expect "consulta la guía" 200 "$(traza $G2001)"
expect "la guía informa su factura" 6001 "$(py "d['documento']['factura_folio']")"
expect "la guía sola: 24 entradas, 15 quedan, 5 merma, 4 devueltas" "24.000000 15.000000 5.000000 4.000000" \
  "$(producto 'Bebida QA' "' '.join([p['movido'], p['queda'], p['salidas']['merma'], p['salidas']['devolucion_proveedor']])")"
expect "consulta la nota de crédito" 200 "$(traza $N7101)"
expect "la nota devolvió 4 y no deja lotes" "-4.000000 []" "$(producto 'Bebida QA' "p['movido'] + ' ' + str(p['locales'])")"
expect "la nota refiere la factura" 6001 "$(py "d['documento']['referido_folio']")"

echo "--- Aislamiento y permisos"
expect "documento inexistente" 404 "$(traza 999999999)"
expect "identificador inválido" 400 "$(traza abc)"
expect "otra empresa no ve la factura" 404 "$(call $J2 GET "recepciones.php?trazabilidad=$F6001" $A2 centro)"
VC="qa-created-$T1@example.invalid"; VP="Qa$(head -c 12 /dev/urandom | base64 | tr -d '/+=')9x"
call $J1 POST usuarios.php $A1 centro "{\"correo\":\"$VC\",\"nombre\":\"Vendedor QA\",\"clave\":\"$VP\",\"rol\":\"vendedor\",\"locales\":[\"centro\"],\"cajas\":[]}" >/dev/null
entrar $JV "$VC" "$VP" $A1 centro >/dev/null
expect "vendedor no consulta la trazabilidad" 403 "$(call $JV GET "recepciones.php?trazabilidad=$F6001" $A1 centro)"
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$B1\",\"localCodigo\":\"sur\"}" >/dev/null
expect "contador de otra empresa no ve la factura" 404 "$(call $J1 GET "recepciones.php?trazabilidad=$F6001" $B1 sur)"

resultado
