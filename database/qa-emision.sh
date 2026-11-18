#!/bin/bash
# QA por API de la emisión comercial (Sprint 06) contra el SII simulado: facturas, guías y notas con receptor, guía de
# venta y de traslado interno, consolidación de guías con una sola salida física, factura directa y exenta, nota que
# corrige montos, nota con devolución física, nota de débito, referencia histórica con evidencia, repetición, envío en
# EnvioDTE válidos y acceso por empresa.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo); FX2=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a)
T2=$(campo "$FX2" tag); A2=$(campo "$FX2" a)
J1=$(mktemp); J2=$(mktemp); JV=$(mktemp); TMP=$(mktemp -d)
docker compose -p myposlite stop scheduler >/dev/null 2>&1
limpiar() { docker compose -p myposlite start scheduler >/dev/null 2>&1; MSYS_NO_PATHCONV=1 docker compose -p myposlite exec -T storage sh /opt/lite-storage/retirar-empresa.sh $A1; fixture borrar $T1; fixture borrar $T2; rm -rf $J1 $J2 $JV $OUT $TMP; }
trap limpiar EXIT
source "$(dirname "$0")/qa-lib-dte.sh"
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
saldo() { call $J1 GET inventario.php $A1 centro >/dev/null; py "[p['saldo'] for p in d['productos'] if p['sku'] == '$1'][0]"; }
saldoNorte() { call $J1 PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"norte\"}" >/dev/null; call $J1 GET inventario.php $A1 norte >/dev/null; py "[p['saldo'] for p in d['productos'] if p['sku'] == '$1'][0]"; call $J1 PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"centro\"}" >/dev/null; }
# lotes SKU: cantidad de lotes del producto en la empresa; una devolución a sus lotes de origen no crea lotes nuevos.
lotes() { docker compose -p myposlite exec -T backend php -r 'require "backend/config/database.php"; $q = conectarBaseLite()->prepare("SELECT COUNT(*) FROM stock_lotes l JOIN productos p ON p.empresa_id = l.empresa_id AND p.id = l.producto_id WHERE l.empresa_id = ? AND p.sku = ?"); $q->execute([$argv[1], $argv[2]]); echo $q->fetchColumn();' $A1 $1; }
lin() { echo "{\"presentacionId\":\"$1\",\"cantidad\":\"$2\"${3:+,\"precio\":$3}}"; }
emitir() { call $J1 POST emision.php $A1 centro "{\"clave\":\"${CLAVE:-$(clave)}\",\"tipo\":$1,\"receptorId\":\"$2\"$3}"; }

expect "ingreso administrador" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
bash database/aprovisionar-almacenamiento.sh $A1 >/dev/null || exit 1
sii normal
expect "boletas en producción" 200 "$(produccion $J1 $A1 11111111-1)"
call $J1 POST contactos.php $A1 centro '{"rut":"96.511.760-1","razonSocial":"Mayorista QA","correo":"","proveedor":false,"receptor":true}' >/dev/null; R1=$(py "d['id']")
call $J1 POST productos.php $A1 centro "$(prod QA-BEB 'Bebida QA' "$(pres Unidad '' 1 1190),$(pres 'Caja 12' '' 12 13000)")" >/dev/null
PRODUCTO_EXENTO=$(prod QA-LIB 'Libro QA' "$(pres Unidad '' 1 10000)"); call $J1 POST productos.php $A1 centro "${PRODUCTO_EXENTO/\"afectoIva\":true/\"afectoIva\":false}" >/dev/null
call $J1 GET productos.php $A1 centro >/dev/null
UNI=$(py "[x['id'] for p in d['productos'] if p['sku'] == 'QA-BEB' for x in p['presentaciones'] if x['nombre'] == 'Unidad'][0]")
CAJA=$(py "[x['id'] for p in d['productos'] if p['sku'] == 'QA-BEB' for x in p['presentaciones'] if x['nombre'] == 'Caja 12'][0]")
LIB=$(py "[x['id'] for p in d['productos'] if p['sku'] == 'QA-LIB' for x in p['presentaciones']][0]")
call $J1 POST inventario.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":\"inicial\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"100\",\"vencimiento\":\"2027-06-30\"},$(lin $LIB 10)]}" >/dev/null

echo "--- Habilitación y receptor"
expect "factura sin habilitar" 409 "$(emitir 33 $R1 ",\"lineas\":[$(lin $UNI 1)]")"
for t in 33 34 52 56 61; do habilitar $J1 $A1 $t; done
expect "receptor sin giro ni dirección" 422 "$(emitir 33 $R1 ",\"lineas\":[$(lin $UNI 1)]")"
expect "completa giro, dirección y comuna del receptor" 200 "$(call $J1 PATCH contactos.php $A1 centro "{\"id\":\"$R1\",\"rut\":\"96.511.760-1\",\"razonSocial\":\"Mayorista QA\",\"correo\":\"\",\"proveedor\":false,\"receptor\":true,\"giro\":\"Comercio mayorista\",\"direccion\":\"Los Aromos 45\",\"comuna\":\"Maipú\"}")"

echo "--- Guías"
expect "guía sin tipo de traslado" 422 "$(emitir 52 $R1 ",\"lineas\":[$(lin $UNI 10)]")"
expect "guía de venta por 10" 201 "$(emitir 52 $R1 ",\"indTraslado\":1,\"tipoDespacho\":2,\"lineas\":[$(lin $UNI 10)]")"; G1=$(py "d['id']")
expect "guía de venta por una caja de 12" 201 "$(emitir 52 $R1 ",\"indTraslado\":1,\"lineas\":[$(lin $CAJA 1)]")"; G2=$(py "d['id']")
expect "cada guía de venta descuenta: quedan 78" 78.000000 "$(saldo QA-BEB)"
expect "traslado interno al mismo local" 422 "$(emitir 52 $R1 ",\"indTraslado\":5,\"localDestino\":\"centro\",\"lineas\":[$(lin $UNI 5)]")"
expect "guía de traslado interno de 5 a Norte" 201 "$(emitir 52 $R1 ",\"indTraslado\":5,\"localDestino\":\"norte\",\"lineas\":[$(lin $UNI 5)]")"
expect "es el traspaso: 73 en Centro y 5 en Norte, un solo movimiento" "73.000000 5.000000" "$(saldo QA-BEB) $(saldoNorte QA-BEB)"

echo "--- Factura que consolida guías"
expect "factura 33 con las dos guías y sus líneas" 201 "$(emitir 33 $R1 ",\"guias\":[\"$G1\",\"$G2\"]")"; F1=$(py "d['id']")
expect "referencia ambas guías: 10 unidades y una caja" "[(52, 1), (52, 2)] 24900" "$(py "[(r['tipo'], r['folio']) for r in d['referencias']]") $(py "d['total']")"
expect "una sola salida física: siguen 73" 73.000000 "$(saldo QA-BEB)"
expect "las guías quedan facturadas en la factura 1" "1 1" "$(call $J1 GET "emision.php?documento=$G1" $A1 centro >/dev/null; py "d['facturadaEn']") $(call $J1 GET "emision.php?documento=$G2" $A1 centro >/dev/null; py "d['facturadaEn']")"
expect "una guía no se factura dos veces" 422 "$(emitir 33 $R1 ",\"guias\":[\"$G1\"]")"

echo "--- Factura directa, exenta y notas"
expect "factura directa de 3 bebidas a precio negociado de 1.000" 201 "$(emitir 33 $R1 ",\"lineas\":[$(lin $UNI 3 1000)]")"; F2=$(py "d['id']")
expect "total 3.000 y descuenta 3" "3000 70.000000" "$(py "d['total']") $(saldo QA-BEB)"
expect "factura exenta de un libro" 201 "$(emitir 34 $R1 ",\"lineas\":[$(lin $LIB 1)]")"
expect "toda la factura exenta" True "$(py "all(l['exento'] for l in d['lineas'])")"
expect "nota de crédito sin referencia" 422 "$(emitir 61 $R1 ",\"codigoReferencia\":3,\"razon\":\"Descuento\",\"lineas\":[{\"nombre\":\"Descuento\",\"cantidad\":\"1\",\"precio\":500}]")"
expect "nota de crédito con código inválido" 422 "$(emitir 61 $R1 ",\"codigoReferencia\":7,\"razon\":\"x\",\"referencia\":{\"documentoId\":\"$F2\"},\"lineas\":[{\"nombre\":\"x\",\"cantidad\":\"1\",\"precio\":1}]")"
expect "nota de crédito que corrige montos de la factura directa" 201 "$(emitir 61 $R1 ",\"codigoReferencia\":3,\"razon\":\"Descuento por volumen\",\"referencia\":{\"documentoId\":\"$F2\"},\"lineas\":[{\"nombre\":\"Descuento por volumen\",\"cantidad\":\"1\",\"precio\":500}]")"
expect "referencia la factura con código 3, sin mover stock" "[(33, 2, 3)] 500 70.000000" "$(py "[(r['tipo'], r['folio'], r['codigo']) for r in d['referencias']]") $(py "d['total']") $(saldo QA-BEB)"
LOTES=$(lotes QA-BEB)
expect "nota de crédito que anula con devolución física de 3" 201 "$(emitir 61 $R1 ",\"codigoReferencia\":1,\"razon\":\"Devolución total\",\"devolucionFisica\":true,\"referencia\":{\"documentoId\":\"$F2\"},\"lineas\":[$(lin $UNI 3 1000)]")"
expect "repone 3: vuelve a 73" 73.000000 "$(saldo QA-BEB)"
expect "vuelve a los lotes de los que salió, sin crear lotes" "$LOTES" "$(lotes QA-BEB)"
expect "no vuelve más de lo que despachó la factura" 422 "$(emitir 61 $R1 ",\"codigoReferencia\":3,\"razon\":\"Otra\",\"devolucionFisica\":true,\"referencia\":{\"documentoId\":\"$F2\"},\"lineas\":[$(lin $UNI 1 1000)]")"
expect "devolución física de 2 sobre la factura que consolidó guías" 201 "$(emitir 61 $R1 ",\"codigoReferencia\":3,\"razon\":\"Devuelve 2\",\"devolucionFisica\":true,\"referencia\":{\"documentoId\":\"$F1\"},\"lineas\":[$(lin $UNI 2 1000)]")"
expect "vuelven a los lotes de sus guías: 75, sin lotes nuevos" "75.000000 $LOTES" "$(saldo QA-BEB) $(lotes QA-BEB)"
expect "nota de débito que anula" 422 "$(emitir 56 $R1 ",\"codigoReferencia\":1,\"razon\":\"x\",\"referencia\":{\"documentoId\":\"$F2\"},\"lineas\":[{\"nombre\":\"x\",\"cantidad\":\"1\",\"precio\":1}]")"
expect "nota de débito por flete" 201 "$(emitir 56 $R1 ",\"codigoReferencia\":3,\"razon\":\"Flete no cobrado\",\"referencia\":{\"documentoId\":\"$F2\"},\"lineas\":[{\"nombre\":\"Flete\",\"cantidad\":\"1\",\"precio\":3000}]")"

echo "--- Referencia histórica y repetición"
expect "nota sobre una factura de otro sistema sin evidencia" 422 "$(emitir 61 $R1 ",\"codigoReferencia\":3,\"razon\":\"Ajuste\",\"referencia\":{\"tipo\":33,\"folio\":12345,\"fecha\":\"2026-01-10\"},\"lineas\":[{\"nombre\":\"Ajuste\",\"cantidad\":\"1\",\"precio\":100}]")"
printf '%%PDF-1.4\n%% factura historica %s\n%%%%EOF\n' "$(clave)" > $TMP/historica.pdf
EVIDENCIA=$(curl -s -b $J1 -X POST "$B/archivos.php" -H 'X-MyPOSLite-Request: 1' -H "X-MyPOSLite-Empresa: $A1" -H 'X-MyPOSLite-Local: centro' -F "archivo=@$(ruta $TMP/historica.pdf)" | python -c "import json,sys; print(json.load(sys.stdin)['id'])")
expect "con el PDF respaldado de esa factura" 201 "$(emitir 61 $R1 ",\"codigoReferencia\":3,\"razon\":\"Ajuste\",\"referencia\":{\"tipo\":33,\"folio\":12345,\"fecha\":\"2026-01-10\",\"archivoId\":\"$EVIDENCIA\"},\"lineas\":[{\"nombre\":\"Ajuste\",\"cantidad\":\"1\",\"precio\":100}]")"
expect "la referencia guarda la evidencia" "[(33, 12345, '$EVIDENCIA')]" "$(py "[(r['tipo'], r['folio'], r['archivoId']) for r in d['referencias']]")"
CLAVE_REP=$(clave)
expect "factura con una clave" 201 "$(CLAVE=$CLAVE_REP emitir 33 $R1 ",\"lineas\":[$(lin $UNI 1)]")"; FOLIO=$(py "d['folio']")
expect "repetir la clave devuelve la misma factura sin descontar otra vez" "200 $FOLIO 74.000000" "$(CLAVE=$CLAVE_REP emitir 33 $R1 ",\"lineas\":[$(lin $UNI 1)]") $(py "d['folio']") $(saldo QA-BEB)"

echo "--- Envío al SII e historial"
call $J1 POST dte.php $A1 centro '{"accion":"enviar-boletas"}' >/dev/null; PRIMERA=$(py "(d['lotes'], d['enviados'], d['errores'])")
expect "un EnvioDTE por documento, diez por pasada" "(10, 10, []) (2, 2, [])" "$PRIMERA $(call $J1 POST dte.php $A1 centro '{"accion":"enviar-boletas"}' >/dev/null; py "(d['lotes'], d['enviados'], d['errores'])")"
RUT=$(call $J1 GET dte.php $A1 centro >/dev/null; py "d['rut']")
expect "el SII simulado los valida contra EnvioDTE_v10" "12 True" "$(sii; py "len([e for e in d['envios'].values() if e['rut_company'] == '$RUT' and e.get('canal') == 'upload' and e['folios'] and not e['folios'][0].startswith('39')])") $(py "all(e['errores'] == [] for e in d['envios'].values() if e['rut_company'] == '$RUT')")"
call $J1 GET emision.php $A1 centro >/dev/null
expect "el historial lista los 12 documentos con su estado" "12 ['enviado']" "$(py "len(d['documentos'])") $(py "sorted(set(x['estado'] for x in d['documentos']))")"

echo "--- Acceso"
VC="qa-created-$T1@example.invalid"; VP="Qa$(head -c 12 /dev/urandom | base64 | tr -d '/+=')9x"
call $J1 POST usuarios.php $A1 centro "{\"correo\":\"$VC\",\"nombre\":\"Vendedor QA\",\"clave\":\"$VP\",\"rol\":\"vendedor\",\"locales\":[\"centro\"],\"cajas\":[]}" >/dev/null
entrar $JV "$VC" "$VP" $A1 centro >/dev/null
expect "el vendedor no emite documentos" 403 "$(call $JV POST emision.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":33,\"receptorId\":\"$R1\",\"lineas\":[$(lin $UNI 1)]}")"
expect "ingreso administrador 2" 200 "$(entrar $J2 "$(campo "$FX2" correo)" "$(campo "$FX2" clave)" $A2 centro)"
expect "otra empresa no ve la factura" 404 "$(call $J2 GET "emision.php?documento=$F1" $A2 centro)"

resultado
