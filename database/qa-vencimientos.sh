#!/bin/bash
# QA por API del informe de productos por vencer por local (Sprint 03, ampliación): orden, plazo, vencidos,
# origen del lote, consumo por vencimiento, traspaso con su vencimiento, validación, aislamiento y permisos.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo); FX2=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a); B1=$(campo "$FX1" b)
T2=$(campo "$FX2" tag); A2=$(campo "$FX2" a)
J1=$(mktemp); J2=$(mktemp); JV=$(mktemp)
trap 'fixture borrar $T1; fixture borrar $T2; rm -f $J1 $J2 $JV $OUT' EXIT
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
lin() { echo "{\"presentacionId\":\"$1\",\"cantidad\":\"$2\"${3:+,\"vencimiento\":\"$3\"}}"; }
op() { echo "{\"clave\":\"$(clave)\",\"tipo\":\"$1\",\"lineas\":[$2]${3:+,$3}}"; }
doc() { echo "{\"clave\":\"$(clave)\",\"tipo\":$1,\"proveedorId\":\"$2\",\"folio\":\"$3\",\"fecha\":\"2026-11-10\",\"montoTotal\":\"100000\",\"lineas\":[$4]}"; }
presentacion() { call $J1 GET productos.php $A1 centro >/dev/null; py "[x['id'] for p in d['productos'] if p['sku'] == '$1' for x in p['presentaciones'] if x['nombre'] == '$2'][0]"; }
# fecha DIAS -> fecha de hoy más DIAS días, según la base de datos (el reloj del contenedor manda).
HOY=$(docker compose -p myposlite exec -T database sh -c 'mariadb -N -u myposlite -p"$MARIADB_PASSWORD" -e "SELECT CURRENT_DATE"' 2>/dev/null)
fecha() { python -c "import datetime,sys; print(datetime.date.fromisoformat(sys.argv[1]) + datetime.timedelta(days=int(sys.argv[2])))" "$HOY" "$1"; }
vencer() { call $J1 GET "inventario.php?vencimientos=$1" $A1 ${2:-centro}; }

expect "fecha de la base" 10 "${#HOY}"
expect "ingreso administrador 1" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
expect "ingreso administrador 2" 200 "$(entrar $J2 "$(campo "$FX2" correo)" "$(campo "$FX2" clave)" $A2 centro)"
call $J1 POST productos.php $A1 centro "$(prod QA-LECHE 'Leche QA' "$(pres Unidad '' 1 1000)")" >/dev/null
call $J1 POST productos.php $A1 centro "$(prod QA-YOGUR 'Yogur QA' "$(pres Unidad '' 1 500)")" >/dev/null
LECHE=$(presentacion QA-LECHE Unidad); YOGUR=$(presentacion QA-YOGUR Unidad)
call $J1 POST contactos.php $A1 centro '{"rut":"76.086.428-5","razonSocial":"Lácteos QA","correo":"","proveedor":true,"receptor":false}' >/dev/null
P1=$(py "d['id']")

echo "--- Lotes"
expect "inventario inicial: vencida, en 5 días, en 60 días y sin fecha" 201 "$(call $J1 POST inventario.php $A1 centro "$(op inicial \
  "$(lin $LECHE 4 "$(fecha -3)"),$(lin $LECHE 6 "$(fecha 5)"),$(lin $LECHE 10 "$(fecha 60)"),$(lin $LECHE 5)")")"
expect "guía 4001 con yogur que vence en 20 días" 201 "$(call $J1 POST recepciones.php $A1 centro "$(doc 52 $P1 4001 "$(lin $YOGUR 12 "$(fecha 20)")")")"

echo "--- Informe a 30 días"
expect "consulta a 30 días" 200 "$(vencer 30)"
expect "vencido primero, luego por fecha; sin el de 60 días ni el sin fecha" "[('Leche QA', -3, '4.000000'), ('Leche QA', 5, '6.000000'), ('Yogur QA', 20, '12.000000')]" \
  "$(py "[(l['nombre'], l['dias'], l['saldo']) for l in d['lotes']]")"
expect "cuenta un lote vencido" 1 "$(py "d['vencidos']")"
expect "el yogur informa su guía y proveedor" "('recepcion', 52, '4001', 'Lácteos QA')" \
  "$(py "[(l['origen'], l['documento_tipo'], l['documento_folio'], l['proveedor']) for l in d['lotes'] if l['nombre'] == 'Yogur QA'][0]")"
expect "la leche informa el inventario inicial" "('inicial', None)" "$(py "(d['lotes'][0]['origen'], d['lotes'][0]['documento_folio'])")"
expect "a 90 días aparece el de 60" 4 "$(vencer 90 >/dev/null; py "len(d['lotes'])")"

echo "--- Consumo por vencimiento y traspaso"
expect "merma de 5 leches" 201 "$(call $J1 POST inventario.php $A1 centro "$(op merma "$(lin $LECHE 5)" '"causa":"Vencidas"')")"
vencer 30 >/dev/null
expect "la merma consumió la vencida y 1 de la siguiente" "[(5, '5.000000'), (20, '12.000000')]" "$(py "[(l['dias'], l['saldo']) for l in d['lotes']]")"
expect "ya no hay vencidos" 0 "$(py "d['vencidos']")"
expect "traspaso de 2 leches a Norte" 201 "$(call $J1 POST inventario.php $A1 centro "$(op traspaso "$(lin $LECHE 2)" '"localDestino":"norte"')")"
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"norte\"}" >/dev/null
expect "Norte consulta sus vencimientos" 200 "$(vencer 30 norte)"
expect "el lote traspasado conserva el vencimiento y su origen" "[('Leche QA', 5, '2.000000', 'traspaso', 'Centro')]" \
  "$(py "[(l['nombre'], l['dias'], l['saldo'], l['origen'], l['origen_local_nombre']) for l in d['lotes']]")"
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"centro\"}" >/dev/null
expect "Centro queda con 3 de esa fecha" "3.000000" "$(vencer 30 >/dev/null; py "d['lotes'][0]['saldo']")"

echo "--- Validación, aislamiento y permisos"
expect "cero días" 400 "$(vencer 0)"
expect "más de un año" 400 "$(vencer 366)"
expect "texto" 400 "$(vencer abc)"
expect "otra empresa no ve estos lotes" "200 []" "$(call $J2 GET 'inventario.php?vencimientos=365' $A2 centro) $(py "d['lotes']")"
VC="qa-created-$T1@example.invalid"; VP="Qa$(head -c 12 /dev/urandom | base64 | tr -d '/+=')9x"
call $J1 POST usuarios.php $A1 centro "{\"correo\":\"$VC\",\"nombre\":\"Vendedor QA\",\"clave\":\"$VP\",\"rol\":\"vendedor\",\"locales\":[\"centro\"],\"cajas\":[]}" >/dev/null
entrar $JV "$VC" "$VP" $A1 centro >/dev/null
expect "vendedor no consulta vencimientos" 403 "$(call $JV GET 'inventario.php?vencimientos=30' $A1 centro)"
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$B1\",\"localCodigo\":\"sur\"}" >/dev/null
expect "contador consulta los de su empresa" "200 []" "$(call $J1 GET 'inventario.php?vencimientos=30' $B1 sur) $(py "d['lotes']")"

resultado
