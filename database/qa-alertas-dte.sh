#!/bin/bash
# QA por API de las alertas de emisión (Sprint 04-B): certificado de producción por vencer, vencido o ausente; CAF vencido
# o por vencer según el tipo; folios agotados, escasos o que se acaban en menos de una semana al ritmo de las últimas
# dos semanas; y lo que ve la caja.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a)
J1=$(mktemp); TMP=$(mktemp -d)
docker compose -p myposlite stop scheduler >/dev/null 2>&1
limpiar() { docker compose -p myposlite start scheduler >/dev/null 2>&1; fixture borrar $T1; rm -rf $J1 $OUT $TMP; }
trap limpiar EXIT
source "$(dirname "$0")/qa-lib-dte.sh"
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
# pasos TIPO: lleva el tipo a producción sin cargar CAF.
pasos() { for paso in en_certificacion "visto_bueno\",\"atencion\":\"QA$1\",\"fecha\":\"2026-11-10" produccion; do
  call $J1 POST dte.php $A1 centro "{\"accion\":\"habilitacion\",\"tipo\":$1,\"estado\":\"$paso\"}" >/dev/null; done; }
alertas() { call $J1 GET dte.php $A1 centro >/dev/null; py "[a['nivel'] + ' ' + str(a['tipo']) + ' ' + a['mensaje'] for a in d['alertas']]" > $TMP/alertas; }
hay() { grep -c -- "$1" $TMP/alertas | tr -d '\r'; }

expect "ingreso administrador" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
call $J1 GET dte.php $A1 centro >/dev/null
expect "sin tipos en producción no hay alertas" "[]" "$(py "d['alertas']")"
sii normal
expect "boletas en producción" 200 "$(produccion $J1 $A1 11111111-1)"
RUT=$(call $J1 GET dte.php $A1 centro >/dev/null; py "d['rut']")

echo "--- Certificado"
alertas
expect "certificado de 30 días: aviso de vencimiento" 1 "$(hay "aviso None El certificado digital vence en 29 días\|aviso None El certificado digital vence en 30 días")"
certificado 11111111-1 'Clave QA' $TMP/anual.pfx 365
subir $J1 $A1 certificado $TMP/anual.pfx produccion 'Clave QA' >/dev/null
alertas
expect "renovado por un año: sin alerta del certificado" 0 "$(hay 'certificado digital')"

echo "--- Folios de boletas"
expect "boleta afecta con 200 folios y sin consumo: sin alerta" 0 "$(hay 'boleta afecta')"
expect "boleta exenta con 200 folios: sin alerta" 0 "$(hay 'boleta exenta')"
pasos 34
alertas
expect "factura exenta habilitada sin CAF: urgente, no quedan folios" 1 "$(hay 'critico 34 No quedan folios de factura exenta: pida un CAF')"

echo "--- Vigencia del CAF"
pasos 33; pasos 52
subir $J1 $A1 caf "$(caf $RUT 33 1 50 300 - 2026-04-01)" >/dev/null
subir $J1 $A1 caf "$(caf $RUT 52 1 50 300 - 2026-04-01)" >/dev/null
alertas
expect "CAF de factura de abril: vencido, anular folios" 1 "$(hay 'critico 33 El CAF de los folios 1 a 50 de factura venció el 01-10-2026: anule')"
expect "CAF de guía de abril: solo pide confirmar en sii.cl" 1 "$(hay 'aviso 52 El CAF de los folios 1 a 50 de guía de despacho tiene más de 6 meses')"
subir $J1 $A1 caf "$(caf $RUT 33 51 80 300 - 2026-05-25)" >/dev/null
alertas
expect "CAF de factura de mayo: vence en menos de 30 días" 1 "$(hay 'aviso 33 El CAF de los folios 51 a 80 de factura vence el 25-11-2026')"

echo "--- Folios escasos y consumo"
pasos 61
subir $J1 $A1 caf "$(caf $RUT 61 1 5 300)" >/dev/null
alertas
expect "nota de crédito con 5 folios: aviso" 1 "$(hay 'aviso 61 Quedan 5 folios de nota de crédito: pida')"
call $J1 POST contactos.php $A1 centro '{"rut":"96.511.760-1","razonSocial":"Mayorista QA","correo":"","proveedor":false,"receptor":true,"giro":"Comercio","direccion":"Los Aromos 45","comuna":"Maipú"}' >/dev/null; R1=$(py "d['id']")
pasos 56
subir $J1 $A1 caf "$(caf $RUT 56 1 31 300)" >/dev/null
# Una factura de Lite sirve de documento referido para las notas.
call $J1 POST productos.php $A1 centro "$(prod QA-BEB 'Bebida QA' "$(pres Unidad '' 1 1190)")" >/dev/null
UNI=$(call $J1 GET productos.php $A1 centro >/dev/null; py "d['productos'][0]['presentaciones'][0]['id']")
call $J1 POST emision.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":33,\"receptorId\":\"$R1\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"1\"}]}" >/dev/null
F=$(py "d.get('id')")
for i in $(seq 1 21); do
  call $J1 POST emision.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":56,\"receptorId\":\"$R1\",\"codigoReferencia\":3,\"razon\":\"Ajuste\",\"referencia\":{\"documentoId\":\"$F\"},\"lineas\":[{\"nombre\":\"Ajuste\",\"cantidad\":\"1\",\"precio\":100}]}" >/dev/null
done
call $J1 GET emision.php $A1 centro >/dev/null; EMITIDAS=$(py "len([x for x in d['documentos'] if x['tipo'] == 56])")
expect "21 notas de débito en el día" 21 "$EMITIDAS"
alertas
expect "quedan 10 al ritmo de 1,5 por día: aviso con días" 1 "$(hay 'aviso 56 Quedan 10 folios de nota de débito, unos 6 días al ritmo actual')"

echo "--- Caja"
call $J1 GET pos.php $A1 centro >/dev/null
expect "la caja no ve las alertas de facturas, guías ni notas" "[]" "$(py "d['alertasDte']")"
certificado 11111111-1 'Clave QA' $TMP/corto.pfx 10
subir $J1 $A1 certificado $TMP/corto.pfx produccion 'Clave QA' >/dev/null
call $J1 GET pos.php $A1 centro >/dev/null
expect "pero sí el certificado que vence en 9 o 10 días" True "$(py "len(d['alertasDte']) == 1 and d['alertasDte'][0]['mensaje'].startswith('El certificado digital vence en ')")"
call $J1 GET dte.php $A1 centro >/dev/null
expect "las urgentes van primero" critico "$(py "d['alertas'][0]['nivel']")"
expect "las alertas no exponen secretos" False "$(py "any(k in json.dumps(d['alertas']) for k in ['RSASK', 'BEGIN', 'clave'])")"
resultado
