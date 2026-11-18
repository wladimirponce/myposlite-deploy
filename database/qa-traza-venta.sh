#!/bin/bash
# QA por API de la trazabilidad hasta la venta (Sprint 05): consumo de dos lotes por vencimiento, origen de lo vendido
# hasta la guía y su foto, venta en otro local tras un traspaso que llega a la guía original, devolución al lote, y
# despacho con factura y nota de crédito con devolución física que vuelve al lote de la guía recibida.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a)
J1=$(mktemp); TMP=$(mktemp -d)
limpiar() { MSYS_NO_PATHCONV=1 docker compose -p myposlite exec -T storage sh /opt/lite-storage/retirar-empresa.sh $A1; fixture borrar $T1; rm -rf $J1 $OUT $TMP; }
trap limpiar EXIT
source "$(dirname "$0")/qa-lib-dte.sh"
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
guia() { call $J1 POST recepciones.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":52,\"proveedorId\":\"$P1\",\"folio\":\"$1\",\"fecha\":\"2026-11-10\",\"montoTotal\":\"5000\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"5\",\"vencimiento\":\"$2\"}]${3:+,\"archivos\":[\"$3\"]}}" >/dev/null; py "d['id']"; }
vender() { call $J1 POST pos.php $A1 $1 "{\"accion\":\"vender\",\"clave\":\"$(clave)\",\"caja\":\"$2\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"$3\"}],\"pagos\":[{\"medio\":\"efectivo\",\"monto\":$(($3 * 1000))}]}" >/dev/null; py "d['id']"; }
origen() { call $J1 GET "pos.php?venta=$1" $A1 ${2:-centro} >/dev/null; py "[(o['cantidad'], o['vencimiento'], o['documento']['folio'] if o['documento'] else o['origen']) for o in d['origen']]"; }

expect "ingreso administrador" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
bash database/aprovisionar-almacenamiento.sh $A1 >/dev/null || exit 1
sii normal
expect "boletas en producción" 200 "$(produccion $J1 $A1 11111111-1)"
for local in centro norte; do call $J1 POST ubicaciones.php $A1 centro "{\"tipo\":\"caja\",\"codigo\":\"$local\",\"nombre\":\"Caja $local\",\"localCodigo\":\"$local\"}" >/dev/null; done
call $J1 POST productos.php $A1 centro "$(prod QA-YOG 'Yogur QA' "$(pres Unidad '' 1 1000)")" >/dev/null
UNI=$(call $J1 GET productos.php $A1 centro >/dev/null; py "[x['id'] for p in d['productos'] if p['sku'] == 'QA-YOG' for x in p['presentaciones']][0]")
PRODUCTO=$(py "[p['id'] for p in d['productos'] if p['sku'] == 'QA-YOG'][0]")
call $J1 POST contactos.php $A1 centro '{"rut":"76.086.428-5","razonSocial":"Lácteos QA","correo":"","proveedor":true,"receptor":false}' >/dev/null; P1=$(py "d['id']")
printf '\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00guia-foto-%s\xff\xd9' "$(clave)" > $TMP/guia.jpg
FOTO=$(curl -s -b $J1 -X POST "$B/archivos.php" -H 'X-MyPOSLite-Request: 1' -H "X-MyPOSLite-Empresa: $A1" -H 'X-MyPOSLite-Local: centro' -F "archivo=@$(ruta $TMP/guia.jpg)" | python -c "import json,sys; print(json.load(sys.stdin)['id'])")
GA=$(guia 501 2027-01-15 $FOTO); GB=$(guia 502 2026-12-15)
call $J1 POST pos.php $A1 centro '{"accion":"abrir","caja":"centro","montoInicial":0}' >/dev/null

echo "--- Dos lotes por vencimiento"
V1=$(vender centro centro 7)
expect "consume primero el lote que vence antes y luego el otro" "[('2.000000', '2027-01-15', '501'), ('5.000000', '2026-12-15', '502')]" "$(origen $V1)"
expect "la guía 501 tiene su foto en el origen" 1 "$(py "[o['documento']['archivos'] for o in d['origen'] if o['documento']['folio'] == '501'][0]")"
DOC=$(py "[o['documento']['id'] for o in d['origen'] if o['documento']['folio'] == '501'][0]")
FOTO_DOC=$(call $J1 GET "archivos.php?documento=$DOC" $A1 centro >/dev/null; py "d['archivos'][0]['id']")
expect "desde la venta se llega a la foto de la guía" "200 True" "$(call $J1 GET "archivos.php?enlace=$FOTO_DOC" $A1 centro) $(py "d['url'].startswith('http')")"

echo "--- Traspaso y venta en otro local"
call $J1 POST inventario.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":\"traspaso\",\"localDestino\":\"norte\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"2\"}]}" >/dev/null
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"norte\"}" >/dev/null
call $J1 POST pos.php $A1 norte '{"accion":"abrir","caja":"norte","montoInicial":0}' >/dev/null
V2=$(vender norte norte 1)
expect "lo vendido en Norte llega a la guía 501 de Centro" "[('1.000000', '2027-01-15', '501')]" "$(origen $V2 norte)"
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"centro\"}" >/dev/null

echo "--- Devolución al lote"
for paso in en_certificacion 'visto_bueno","atencion":"QA61","fecha":"2026-11-10' produccion; do
  call $J1 POST dte.php $A1 centro "{\"accion\":\"habilitacion\",\"tipo\":61,\"estado\":\"$paso\"}" >/dev/null
done
RUT=$(call $J1 GET dte.php $A1 centro >/dev/null; py "d['rut']")
subir $J1 $A1 caf "$(caf $RUT 61 1 50 300)" >/dev/null
lotes() { call $J1 GET "inventario.php?producto=$PRODUCTO" $A1 centro >/dev/null; py "sorted((l['vencimiento'], l['saldo']) for l in d['lotes'])"; }
expect "antes de devolver queda 1 en el lote de la guía 501 y nada en el de la 502" "[('2027-01-15', '1.000000')]" "$(lotes)"
expect "devuelve 3 unidades" 201 "$(call $J1 POST pos.php $A1 centro "{\"accion\":\"devolver\",\"clave\":\"$(clave)\",\"caja\":\"centro\",\"venta\":\"$V1\",\"lineas\":[{\"linea\":1,\"cantidad\":\"3\"}],\"medio\":\"efectivo\",\"motivo\":\"Cliente arrepentido\",\"fisica\":true}")"
expect "vuelven a sus lotes de origen, sin superar lo que salió de cada uno" "[('2026-12-15', '3.000000'), ('2027-01-15', '1.000000')]" "$(lotes)"
call $J1 GET "recepciones.php?trazabilidad=$GB" $A1 centro >/dev/null
expect "la guía 502 muestra 2 vendidas netas y 3 que quedan" "{'venta': '2.000000'} 3.000000" "$(py "' '.join(str(x) for x in [(p['salidas'], p['queda']) for p in d['productos']][0])")"

echo "--- Factura y nota de crédito con devolución física"
habilitar $J1 $A1 33
call $J1 POST contactos.php $A1 centro '{"rut":"96.511.760-1","razonSocial":"Mayorista QA","correo":"","proveedor":false,"receptor":true,"giro":"Comercio","direccion":"Los Aromos 45","comuna":"Maipú"}' >/dev/null; R1=$(py "d['id']")
call $J1 POST emision.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":33,\"receptorId\":\"$R1\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"2\"}]}" >/dev/null; F1=$(py "d['id']")
traza() { call $J1 GET "recepciones.php?trazabilidad=$GB" $A1 centro >/dev/null; py "' '.join(str(x) for x in [(dict(sorted(p['salidas'].items())), p['queda']) for p in d['productos']][0])"; }
expect "la factura despacha 2 del lote de la guía 502" "{'despacho': '2.000000', 'venta': '2.000000'} 1.000000" "$(traza)"
expect "nota de crédito con devolución física de las 2" 201 "$(call $J1 POST emision.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":61,\"receptorId\":\"$R1\",\"codigoReferencia\":1,\"razon\":\"Devolución\",\"devolucionFisica\":true,\"referencia\":{\"documentoId\":\"$F1\"},\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"2\"}]}")"
expect "vuelven al lote de la guía 502: despacho neto 0 y quedan 3" "{'despacho': '0.000000', 'venta': '2.000000'} 3.000000" "$(traza)"

resultado
