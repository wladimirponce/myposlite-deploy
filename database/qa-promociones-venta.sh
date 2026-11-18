#!/bin/bash
# QA por API de promociones en la caja (Sprint 05, ampliación): 2x1, pack y horario; la de mayor descuento por línea sin
# acumular; promociones vencidas o de otro local no aplican; cotización igual al cobro; boleta con DescuentoMonto válida
# ante el SII simulado; devolución que reintegra lo pagado con el descuento en proporción.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a)
J1=$(mktemp); TMP=$(mktemp -d)
docker compose -p myposlite stop scheduler >/dev/null 2>&1
trap 'docker compose -p myposlite start scheduler >/dev/null 2>&1; fixture borrar $T1; rm -rf $J1 $OUT $TMP' EXIT
source "$(dirname "$0")/qa-lib-dte.sh"
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
pres_id() { call $J1 GET productos.php $A1 centro >/dev/null; py "[x['id'] for p in d['productos'] if p['sku'] == '$1' for x in p['presentaciones']][0]"; }
promo() { call $J1 POST promociones.php $A1 ${LOCAL:-centro} "{\"presentacionId\":\"$1\",\"nombre\":\"$2\",\"tipo\":\"$3\",\"alcance\":\"${5:-empresa}\",\"desde\":\"${6:-2026-01-01}\",\"hasta\":\"${7:-2027-12-31}\"${4:+,$4}}"; }
lin() { echo "{\"presentacionId\":\"$1\",\"cantidad\":\"$2\"}"; }

expect "ingreso administrador" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
sii normal
expect "boletas en producción" 200 "$(produccion $J1 $A1 11111111-1)"
RUT=$(call $J1 GET dte.php $A1 centro >/dev/null; py "d['rut']")
for paso in en_certificacion 'visto_bueno","atencion":"QA61","fecha":"2026-11-10' produccion; do
  call $J1 POST dte.php $A1 centro "{\"accion\":\"habilitacion\",\"tipo\":61,\"estado\":\"$paso\"}" >/dev/null
done
subir $J1 $A1 caf "$(caf $RUT 61 1 50 300)" >/dev/null
call $J1 POST ubicaciones.php $A1 centro '{"tipo":"caja","codigo":"1","nombre":"Caja 1","localCodigo":"centro"}' >/dev/null
for p in "QA-BEB Bebida 1000" "QA-GAL Galleta 800" "QA-CHO Chocolate 800" "QA-JUG Jugo 900"; do
  set -- $p; call $J1 POST productos.php $A1 centro "$(prod $1 "$2 QA" "$(pres Unidad '' 1 $3)")" >/dev/null
done
BEB=$(pres_id QA-BEB); GAL=$(pres_id QA-GAL); CHO=$(pres_id QA-CHO); JUG=$(pres_id QA-JUG)
expect "2x1 de bebida" 201 "$(promo $BEB '2x1 bebida' 2x1)"
expect "pack 3 bebidas a 2.400, que compite con el 2x1" 201 "$(promo $BEB 'Pack bebidas' pack '"cantidad":3,"precio":"2400"')"
expect "pack 3 galletas a 2.000" 201 "$(promo $GAL 'Pack galletas' pack '"cantidad":3,"precio":"2000"')"
expect "chocolate a 500 todo el día" 201 "$(promo $CHO 'Hora feliz' horario '"precio":"500","horaInicio":"00:00","horaFin":"23:59"')"
expect "jugo con promoción vencida" 201 "$(promo $JUG 'Vencida' 2x1 '' empresa 2026-01-01 2026-01-31)"
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"norte\"}" >/dev/null
expect "jugo con promoción del local Norte, creada desde Norte" 201 "$(LOCAL=norte promo $JUG 'Solo Norte' 2x1 '' local)"
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"centro\"}" >/dev/null
call $J1 POST pos.php $A1 centro '{"accion":"abrir","caja":"1","montoInicial":0}' >/dev/null

echo "--- Cotización"
CARRO="$(lin $BEB 3),$(lin $GAL 4),$(lin $CHO 2)"
expect "cotiza el carro" 200 "$(call $J1 POST pos.php $A1 centro "{\"accion\":\"cotizar\",\"lineas\":[$CARRO]}")"
expect "2x1 gana al pack en 3 bebidas; 1 pack de galletas en 4; chocolate a 500" \
  "[(2000, 1000, '2x1 bebida'), (2800, 400, 'Pack galletas'), (1000, 600, 'Hora feliz')] 5800" \
  "$(py "[(l['monto'], l['descuento'], l['promocion']) for l in d['lineas']]") $(py "d['total']")"
expect "jugo en Centro: ni la vencida ni la de Norte" "[(1800, 0, None)]" "$(call $J1 POST pos.php $A1 centro "{\"accion\":\"cotizar\",\"lineas\":[$(lin $JUG 2)]}" >/dev/null; py "[(l['monto'], l['descuento'], l['promocion']) for l in d['lineas']]")"

echo "--- Venta y boleta"
expect "cobra lo cotizado" 201 "$(call $J1 POST pos.php $A1 centro "{\"accion\":\"vender\",\"clave\":\"$(clave)\",\"caja\":\"1\",\"lineas\":[$CARRO],\"pagos\":[{\"medio\":\"efectivo\",\"monto\":5800}]}")"
VENTA=$(py "d['id']")
expect "la venta guarda descuento y promoción por línea" "[(1000, '2x1 bebida'), (400, 'Pack galletas'), (600, 'Hora feliz')]" "$(py "[(l['descuento'], l['promocion']) for l in d['lineas']]")"
expect "lote enviado al SII" "(1, 1, 0, [])" "$(call $J1 POST dte.php $A1 centro '{"accion":"enviar-boletas"}' >/dev/null; py "(d['lotes'], d['enviados'], d['pendientes'], d['errores'])")"
E=$(call $J1 GET dte.php $A1 centro >/dev/null; py "[e['id'] for e in d['ambientes']['produccion']['envios'] if e['motivo'] == 'boletas'][0]")
SOBRE=$(curl -s -b $J1 "$B/dte.php?envio=$E" -H "X-MyPOSLite-Empresa: $A1" -H 'X-MyPOSLite-Local: centro')
expect "la boleta informa el descuento de cada línea" True "$(printf '%s' "$SOBRE" | grep -q '<PrcItem>1000</PrcItem><DescuentoMonto>1000</DescuentoMonto><MontoItem>2000</MontoItem>' && echo True || echo False)"
expect "el SII simulado la valida contra el esquema" "[[]]" "$(sii; py "[e['errores'] for e in d['envios'].values() if e['rut_company'] == '$RUT' and e.get('canal') == 'rest']")"

echo "--- Devolución con promoción"
expect "devuelve 1 de las 3 bebidas del 2x1" 201 "$(call $J1 POST pos.php $A1 centro "{\"accion\":\"devolver\",\"clave\":\"$(clave)\",\"caja\":\"1\",\"venta\":\"$VENTA\",\"lineas\":[{\"linea\":1,\"cantidad\":\"1\"}],\"medio\":\"efectivo\",\"motivo\":\"Cambio\",\"fisica\":true}")"
expect "reintegra lo pagado: 1.000 menos un tercio del descuento" 667 "$(py "d['monto']")"

resultado
