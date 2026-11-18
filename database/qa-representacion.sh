#!/bin/bash
# QA por API de la representación impresa de los DTE (Sprints 04-B y 06): PDF de una página en hoja carta con el recuadro
# del SII, el texto que exige el revisor y el timbre PDF417 con el módulo mínimo; copia cedible con acuse de recibo solo
# donde corresponde; ticket de 80 mm para boletas; y acceso por empresa y rol.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo); FX2=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a)
T2=$(campo "$FX2" tag); A2=$(campo "$FX2" a)
J1=$(mktemp); J2=$(mktemp); JV=$(mktemp); JS=$(mktemp); TMP=$(mktemp -d)
docker compose -p myposlite stop scheduler >/dev/null 2>&1
limpiar() { docker compose -p myposlite start scheduler >/dev/null 2>&1; fixture borrar $T1; fixture borrar $T2; rm -rf $J1 $J2 $JV $JS $OUT $TMP; }
trap limpiar EXIT
source "$(dirname "$0")/qa-lib-dte.sh"
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
lin() { echo "{\"presentacionId\":\"$1\",\"cantidad\":\"$2\"${3:+,\"precio\":$3}}"; }
emitir() { call $J1 POST emision.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":$1,\"receptorId\":\"$R1\"$2}"; }
# pdf JAR EMPRESA ID [CONSULTA] -> código HTTP; el PDF queda en $TMP/doc.pdf y su resumen en $OUT.
pdf() {
  local codigo; codigo=$(curl -s -o $TMP/doc.pdf -w '%{http_code}' -b $1 "$B/documento-pdf.php?id=$3${4:+&$4}" -H "X-MyPOSLite-Empresa: $2" -H 'X-MyPOSLite-Local: centro')
  if [ "$codigo" = 200 ]; then python "$(dirname "$0")/qa-pdf.py" "$(ruta $TMP/doc.pdf)" > $OUT; else cp $TMP/doc.pdf $OUT; fi
  echo $codigo
}
tiene() { py "'$1' in d['texto']"; }

expect "ingreso administrador" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
expect "ingreso en otra empresa" 200 "$(entrar $J2 "$(campo "$FX2" correo)" "$(campo "$FX2" clave)" $A2 centro)"
sii normal
expect "boletas en producción" 200 "$(produccion $J1 $A1 11111111-1)"
RUT=$(call $J1 GET dte.php $A1 centro >/dev/null; py "d['rut']")
RUT_PUNTOS=$(python -c "import sys; n, dv = sys.argv[1].split('-'); print(f'{int(n):,}'.replace(',', '.') + '-' + dv)" "$RUT")
expect "unidad del SII del emisor" 200 "$(emisor $J1 $A1 produccion 80 '"unidadSii":"Santiago Centro"')"
call $J1 GET dte.php $A1 centro >/dev/null
expect "el emisor la guarda" "Santiago Centro" "$(py "d['ambientes']['produccion']['emisor']['unidadSii']")"
for t in 33 52 61; do habilitar $J1 $A1 $t; done
call $J1 POST contactos.php $A1 centro '{"rut":"96.511.760-1","razonSocial":"Mayorista QA","correo":"","proveedor":false,"receptor":true,"giro":"Comercio mayorista","direccion":"Los Aromos 45","comuna":"Maipú"}' >/dev/null; R1=$(py "d['id']")
call $J1 POST ubicaciones.php $A1 centro '{"tipo":"caja","codigo":"1","nombre":"Caja 1","localCodigo":"centro"}' >/dev/null
call $J1 POST productos.php $A1 centro "$(prod QA-BEB 'Bebida QA' "$(pres Unidad '' 1 1190)")" >/dev/null
UNI=$(call $J1 GET productos.php $A1 centro >/dev/null; py "[x['id'] for p in d['productos'] if p['sku'] == 'QA-BEB' for x in p['presentaciones']][0]")
call $J1 POST inventario.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":\"inicial\",\"lineas\":[$(lin $UNI 100)]}" >/dev/null

echo "--- Factura"
emitir 33 ",\"lineas\":[$(lin $UNI 3)]" >/dev/null; F1=$(py "d['id']"); FOLIO=$(py "d['folio']")
expect "PDF de la factura" 200 "$(pdf $J1 $A1 $F1)"
expect "una página en hoja carta, bajo 500 KB" "1 [215.9, 279.4] True" "$(py "d['paginas']") $(py "d['hoja']") $(py "d['bytes'] < 500 * 1024")"
expect "recuadro con RUT con puntos, tipo y folio" "True True True" "$(tiene "R.U.T.: $RUT_PUNTOS") $(tiene 'FACTURA ELECTR') $(tiene "N° $FOLIO")"
expect "unidad del SII bajo el recuadro" True "$(tiene 'S.I.I. - Santiago Centro')"
expect "leyendas del timbre y la resolución" "True True True" "$(tiene 'Timbre Electrónico SII') $(tiene 'Res. 80 de') $(tiene 'Verifique documento: www.sii.cl')"
expect "receptor, giro y total" "True True True" "$(tiene 'Mayorista QA') $(tiene 'Comercio mayorista') $(tiene '$3.570')"
expect "la tributaria no lleva acuse de recibo" False "$(tiene 'Acuse de recibo')"
expect "copia cedible" 200 "$(pdf $J1 $A1 $F1 copia=cedible)"
expect "con acuse de recibo y la marca CEDIBLE" "True True 1" "$(tiene 'Acuse de recibo') $(tiene 'CEDIBLE') $(py "d['paginas']")"
expect "la factura no tiene ticket térmico" 422 "$(pdf $J1 $A1 $F1 formato=80)"
docker compose -p myposlite exec -T backend php database/qa-timbre.php $A1 $F1 > $OUT
expect "timbre con la firma del TED" True "$(py "d['firmaEnTed']")"
expect "módulo de al menos 0,26 mm en carta" True "$(py "d['modulo'] >= 0.26")"
expect "timbre de al menos 5 × 2 cm" True "$(py "d['carta'][0] >= 50 and d['carta'][1] >= 20")"

echo "--- Guías y nota de crédito"
emitir 52 ",\"indTraslado\":1,\"tipoDespacho\":2,\"lineas\":[$(lin $UNI 2)]" >/dev/null; G1=$(py "d['id']")
expect "PDF de la guía de venta" 200 "$(pdf $J1 $A1 $G1)"
expect "tipo de traslado y despacho" "True True" "$(tiene 'Tipo de traslado: Operación constituye venta') $(tiene 'Tipo de despacho: Despacho por cuenta del emisor a instalaciones del cliente')"
expect "copia cedible de la guía con su factura" 200 "$(pdf $J1 $A1 $G1 copia=cedible)"
expect "dice CEDIBLE CON SU FACTURA" True "$(tiene 'CEDIBLE CON SU FACTURA')"
emitir 52 ",\"indTraslado\":5,\"localDestino\":\"norte\",\"lineas\":[$(lin $UNI 1)]" >/dev/null; G2=$(py "d['id']")
expect "el traslado interno no tiene copia cedible" 422 "$(pdf $J1 $A1 $G2 copia=cedible)"
emitir 61 ",\"codigoReferencia\":1,\"razon\":\"Anula factura\",\"referencia\":{\"documentoId\":\"$F1\"},\"lineas\":[$(lin $UNI 3)]" >/dev/null; N1=$(py "d['id']")
expect "PDF de la nota de crédito" 200 "$(pdf $J1 $A1 $N1)"
expect "con su referencia a la factura" True "$(tiene "FACTURA ELECTRÓNICA N° $FOLIO del")"
expect "y el efecto de anular" True "$(tiene 'anula el documento · Anula factura')"
expect "la nota de crédito no tiene copia cedible" 422 "$(pdf $J1 $A1 $N1 copia=cedible)"

echo "--- Boleta"
VC="qa-created-$T1@example.invalid"; VP="Qa$(head -c 12 /dev/urandom | base64 | tr -d '/+=')9x"
call $J1 POST usuarios.php $A1 centro "{\"correo\":\"$VC\",\"nombre\":\"Vendedora QA\",\"clave\":\"$VP\",\"rol\":\"vendedor\",\"locales\":[\"centro\"],\"cajas\":[{\"localCodigo\":\"centro\",\"codigo\":\"1\"}]}" >/dev/null
entrar $JV "$VC" "$VP" $A1 centro >/dev/null
call $JV POST pos.php $A1 centro '{"accion":"abrir","caja":"1","montoInicial":0}' >/dev/null
call $JV POST pos.php $A1 centro "{\"accion\":\"vender\",\"clave\":\"$(clave)\",\"caja\":\"1\",\"lineas\":[$(lin $UNI 2)],\"pagos\":[{\"medio\":\"efectivo\",\"monto\":2380}]}" >/dev/null
VENTA=$(py "d['id']"); call $JV GET "pos.php?venta=$VENTA" $A1 centro >/dev/null; B1=$(py "d['documento']['id']"); BFOLIO=$(py "d['documento']['folio']")
expect "la vendedora abre su boleta en ticket de 80 mm" 200 "$(pdf $JV $A1 $B1 formato=80)"
expect "ticket de 80 mm de una página" "1 80.0" "$(py "d['paginas']") $(py "d['hoja'][0]")"
expect "boleta con folio, timbre y total" "True True True" "$(tiene "N° $BFOLIO") $(tiene 'Timbre Electrónico SII') $(tiene '$2.380')"
expect "y en hoja carta" 200 "$(pdf $JV $A1 $B1)"
expect "la boleta no tiene copia cedible" 422 "$(pdf $JV $A1 $B1 copia=cedible)"
docker compose -p myposlite exec -T backend php database/qa-timbre.php $A1 $B1 > $OUT
expect "el timbre del ticket mide al menos 5 cm de ancho" True "$(py "d['ticket'][0] >= 50")"

echo "--- Acceso"
expect "otra empresa no ve la factura" 404 "$(pdf $J2 $A2 $F1)"
expect "documento inexistente" 404 "$(pdf $J1 $A1 999999999)"
expect "identificador inválido" 400 "$(pdf $J1 $A1 abc)"
expect "copia inválida" 400 "$(pdf $J1 $A1 $F1 copia=original)"
expect "sin sesión" 401 "$(pdf $JS $A1 $F1)"
expect "con otra empresa en el encabezado" 409 "$(pdf $J1 $A2 $F1)"
resultado
