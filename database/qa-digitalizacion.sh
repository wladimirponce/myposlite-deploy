#!/bin/bash
# QA por API de la digitalización con el lector simulado (perfil simulador): borrador sin movimiento de stock,
# sugerencias de proveedor, productos y guías, corrección, confirmación única, memoria proveedor-producto,
# servicio caído o con respuesta inválida, concurrencia, permisos y aislamiento.
# Requiere MYPOSLITE_GEMINI_API_BASE apuntando al lector simulado y MYPOSLITE_GEMINI_API_KEY=simulado.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo); FX2=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a); B1=$(campo "$FX1" b)
T2=$(campo "$FX2" tag); A2=$(campo "$FX2" a)
J1=$(mktemp); J2=$(mktemp); JV=$(mktemp); TMP=$(mktemp -d)
limpiar() {
  for e in $A1 $B1 $A2; do MSYS_NO_PATHCONV=1 docker compose -p myposlite exec -T storage sh /opt/lite-storage/retirar-empresa.sh $e; done
  fixture borrar $T1; fixture borrar $T2; rm -rf $J1 $J2 $JV $OUT $TMP
}
trap limpiar EXIT
ruta() { if command -v cygpath >/dev/null; then cygpath -m "$1"; else echo "$1"; fi; }
subir() { curl -s -o $OUT -w '%{http_code}' -b $1 -c $1 -X POST "$B/archivos.php" -H 'X-MyPOSLite-Request: 1' \
  -H "X-MyPOSLite-Empresa: $2" -H 'X-MyPOSLite-Local: centro' -F "archivo=@$(ruta $3)"; }
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
# documento ARCHIVO FORMATO LECTURA_JSON: crea un PDF o JPEG ficticio que el simulador "lee" como LECTURA_JSON.
documento() {
  local marca="LECTURA-SIMULADA:$(printf '%s' "$3" | base64 -w0) $(head -c 8 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  if [ "$2" = pdf ]; then printf '%%PDF-1.4\n%% %s\n%%%%EOF\n' "$marca" > $1
  else printf '\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00%s\xff\xd9' "$marca" > $1; fi
}
leer() { call $1 POST digitalizacion.php $2 centro "{\"archivoId\":\"$3\"${4:+,\"reintentar\":true}}"; }
saldo() { call $J1 GET inventario.php $A1 centro >/dev/null; py "[p['saldo'] for p in d['productos'] if p['sku'] == '$1'][0]"; }
item() { echo "{\"codigo_detectado\":$1,\"codigo_barra_detectado\":$2,\"nombre_detectado\":\"$3\",\"cantidad_detectada\":$4,\"costo_unitario_detectado\":$5,\"total_detectado\":$(($4 * $5))}"; }

expect "ingreso administrador 1" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
expect "ingreso administrador 2" 200 "$(entrar $J2 "$(campo "$FX2" correo)" "$(campo "$FX2" clave)" $A2 centro)"
bash database/aprovisionar-almacenamiento.sh $A1 $B1 $A2 >/dev/null || exit 1
call $J1 POST contactos.php $A1 centro '{"rut":"76.086.428-5","razonSocial":"Distribuidora QA","correo":"","proveedor":true,"receptor":false}' >/dev/null; P1=$(py "d['id']")
call $J1 POST productos.php $A1 centro "$(prod QA-BEB 'Bebida QA' "$(pres Unidad 7801 1 1200),$(pres 'Caja 12' 7802 12 12000)")" >/dev/null
call $J1 POST productos.php $A1 centro "$(prod GAL-1 'Galleta QA' "$(pres Unidad '' 1 800)")" >/dev/null
call $J1 GET productos.php $A1 centro >/dev/null
UNI=$(py "[x['id'] for p in d['productos'] if p['sku'] == 'QA-BEB' for x in p['presentaciones'] if x['nombre'] == 'Unidad'][0]")
GAL=$(py "[x['id'] for p in d['productos'] if p['sku'] == 'GAL-1' for x in p['presentaciones']][0]")

echo "--- Guía digitalizada desde un PDF"
documento $TMP/guia.pdf pdf "{\"tipo_documento\":\"GUIA_DESPACHO_COMPRA\",\"proveedor_rut\":\"76086428-5\",\"proveedor_nombre\":\"Distribuidora QA\",\"folio\":\"N° 001501\",\"fecha_documento\":\"2026-11-10\",\"total\":28560,\"items\":[$(item null '"7801"' 'BEBIDA 1.5' 20 1200)]}"
expect "respaldo del PDF" 201 "$(subir $J1 $A1 $TMP/guia.pdf)"; PDF=$(py "d['id']")
expect "lectura del PDF" 201 "$(leer $J1 $A1 $PDF)"; L1=$(py "d['id']")
expect "borrador: proveedor, tipo, folio y fecha" "$P1 52 1501 2026-11-10" "$(py "d['proveedorId'], d['detectado']['tipo'], d['detectado']['folio'], d['detectado']['fecha']" | tr -d "(),'")"
expect "la línea se asocia por código de barras" "$UNI código de barras 20" "$(py "d['lineas'][0]['presentacionId'], d['lineas'][0]['metodo'], d['lineas'][0]['cantidad']" | tr -d "(),'")"
expect "la lectura no movió stock" 0.000000 "$(saldo QA-BEB)"
expect "leer otra vez reutiliza la lectura" "200 $L1 True" "$(leer $J1 $A1 $PDF) $(py "d['id'], d['reutilizada']" | tr -d "(),'")"
GUIA="{\"clave\":\"$(clave)\",\"tipo\":52,\"proveedorId\":\"$P1\",\"folio\":\"1501\",\"fecha\":\"2026-11-10\",\"montoTotal\":\"28560\",\"lecturaId\":\"$L1\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"20\"}]}"
expect "confirmar la guía" 201 "$(call $J1 POST recepciones.php $A1 centro "$GUIA")"; G1501=$(py "d['id']")
expect "el stock sube 20" 20.000000 "$(saldo QA-BEB)"
expect "el PDF quedó adjunto sin indicarlo" "['$PDF']" "$(call $J1 GET "archivos.php?documento=$G1501" $A1 centro >/dev/null; py "[a['id'] for a in d['archivos']]")"
expect "la misma lectura no se confirma dos veces" 409 "$(call $J1 POST recepciones.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":52,\"proveedorId\":\"$P1\",\"folio\":\"1599\",\"fecha\":\"2026-11-10\",\"montoTotal\":\"1\",\"lecturaId\":\"$L1\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"1\"}]}")"
expect "un archivo ya ingresado no se vuelve a leer" 409 "$(leer $J1 $A1 $PDF)"
expect "el stock sigue en 20" 20.000000 "$(saldo QA-BEB)"

echo "--- Factura desde una foto: propone la guía y corrige una línea"
documento $TMP/factura.jpg jpg "{\"tipo_documento\":\"FACTURA_COMPRA\",\"proveedor_rut\":\"76.086.428-5\",\"folio\":\"5501\",\"fecha_documento\":\"2026-11-12\",\"neto\":40000,\"iva\":7600,\"total\":47600,\"folios_guias_referenciadas\":[\"1501\"],\"items\":[$(item null '"7801"' 'BEBIDA 1.5' 20 1200),$(item '"PRV-GAL"' null 'GALLETA SURTIDA' 200 800)]}"
expect "respaldo de la foto" 201 "$(subir $J1 $A1 $TMP/factura.jpg)"; FOTO=$(py "d['id']")
expect "lectura de la foto" 201 "$(leer $J1 $A1 $FOTO)"; L2=$(py "d['id']")
expect "propone la guía 1501 referenciada" "[('1501', True)]" "$(py "[(g['folio'], g['sugerida']) for g in d['guias']]")"
expect "la galleta llega sin asociar y con aviso" "None True" "$(py "d['lineas'][1]['presentacionId'], any('no se asoci' in a for a in d['avisos'])" | tr -d "(),'")"
CLAVE_GAL=$(py "d['lineas'][1]['claveProveedor']")
expect "el borrador sigue sin mover stock" 20.000000 "$(saldo QA-BEB)"
FACTURA="{\"clave\":\"$(clave)\",\"tipo\":33,\"proveedorId\":\"$P1\",\"folio\":\"5501\",\"fecha\":\"2026-11-12\",\"montoTotal\":\"47600\",\"lecturaId\":\"$L2\",\"guias\":[\"$G1501\"],\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"20\",\"claveProveedor\":\"codigo:7801\"},{\"presentacionId\":\"$GAL\",\"cantidad\":\"20\",\"claveProveedor\":\"$CLAVE_GAL\"}]}"
expect "confirmar con la galleta asociada y la cantidad corregida a 20" 201 "$(call $J1 POST recepciones.php $A1 centro "$FACTURA")"
expect "la bebida sube 20 una sola vez" 20.000000 "$(saldo QA-BEB)"
expect "la galleta entra con la cantidad corregida" 20.000000 "$(saldo GAL-1)"

echo "--- Segunda factura: productos ya asociados y documento duplicado"
documento $TMP/factura2.pdf pdf "{\"tipo_documento\":\"FACTURA_COMPRA\",\"proveedor_rut\":\"76086428-5\",\"folio\":\"5502\",\"fecha_documento\":\"2026-11-14\",\"total\":9520,\"items\":[$(item '"PRV-GAL"' null 'GALLETA SURTIDA' 10 800)]}"
subir $J1 $A1 $TMP/factura2.pdf >/dev/null; F2=$(py "d['id']")
expect "lectura de la segunda factura" 201 "$(leer $J1 $A1 $F2)"
expect "la galleta llega asociada por la memoria del proveedor" "$GAL asociación anterior del proveedor" "$(py "d['lineas'][0]['presentacionId'], d['lineas'][0]['metodo']" | tr -d "(),'")"
documento $TMP/repetida.jpg jpg "{\"tipo_documento\":\"FACTURA_COMPRA\",\"proveedor_rut\":\"76086428-5\",\"folio\":\"5501\",\"total\":47600,\"items\":[]}"
subir $J1 $A1 $TMP/repetida.jpg >/dev/null; REP=$(py "d['id']")
expect "una foto de la misma factura avisa que ya fue ingresada" True "$(leer $J1 $A1 $REP >/dev/null; py "any('ya fue ingresado' in a for a in d['avisos'])")"
expect "y no se puede volver a ingresar" 409 "$(call $J1 POST recepciones.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":33,\"proveedorId\":\"$P1\",\"folio\":\"5501\",\"fecha\":\"2026-11-12\",\"montoTotal\":\"47600\",\"lecturaId\":\"$(py "d['id']")\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"1\"}]}")"
expect "el stock no cambió" 20.000000 "$(saldo QA-BEB)"
call $J1 GET digitalizacion.php $A1 centro >/dev/null
expect "quedan dos lecturas por revisar" 2 "$(py "len([l for l in d['lecturas'] if l['estado'] == 'lista'])")"

echo "--- Servicio de lectura caído o con respuesta inválida"
printf '%%PDF-1.4\n%% LECTURA-SIMULADA:FALLA %s\n%%%%EOF\n' "$T1" > $TMP/caido.pdf
subir $J1 $A1 $TMP/caido.pdf >/dev/null; CAIDO=$(py "d['id']")
expect "con el servicio caído, tras reintentar, responde 502" 502 "$(leer $J1 $A1 $CAIDO)"
expect "ofrece el ingreso manual" True "$(py "d['ingresoManual']")"
call $J1 GET digitalizacion.php $A1 centro >/dev/null
expect "el fallo quedó registrado" "error" "$(py "[l['estado'] for l in d['lecturas'] if l['archivoId'] == '$CAIDO'][0]")"
expect "el documento se ingresa a mano con el mismo archivo" 201 "$(call $J1 POST recepciones.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":52,\"proveedorId\":\"$P1\",\"folio\":\"1502\",\"fecha\":\"2026-11-15\",\"montoTotal\":\"1200\",\"archivos\":[\"$CAIDO\"],\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"1\"}]}")"
printf '%%PDF-1.4\n%% LECTURA-SIMULADA:BASURA %s\n%%%%EOF\n' "$T1" > $TMP/basura.pdf
subir $J1 $A1 $TMP/basura.pdf >/dev/null; BASURA=$(py "d['id']")
expect "una respuesta que no es JSON se informa" "502 True" "$(leer $J1 $A1 $BASURA) $(py "'formato inválido' in d['error']")"
documento $TMP/sinrut.jpg jpg '{"tipo_documento":"GUIA_DESPACHO_COMPRA","proveedor_rut":"11.111.111-2","proveedor_nombre":"Desconocido","folio":"77","items":[]}'
subir $J1 $A1 $TMP/sinrut.jpg >/dev/null; SINRUT=$(py "d['id']")
expect "un RUT mal leído no inventa proveedor" "None True" "$(leer $J1 $A1 $SINRUT >/dev/null; py "d['proveedorId'], any('RUT' in a for a in d['avisos'])" | tr -d "(),'")"

echo "--- Concurrencia"
documento $TMP/concurrente.pdf pdf '{"tipo_documento":"GUIA_DESPACHO_COMPRA","proveedor_rut":"76086428-5","folio":"1600","items":[]}'
subir $J1 $A1 $TMP/concurrente.pdf >/dev/null; CONC=$(py "d['id']")
for i in 1 2 3 4 5; do (printf '{"archivoId":"%s"}' $CONC | curl -s -o /dev/null -w '%{http_code}' -b $J1 -X POST "$B/digitalizacion.php" -H 'Content-Type: application/json' \
  -H 'X-MyPOSLite-Request: 1' -H "X-MyPOSLite-Empresa: $A1" -H 'X-MyPOSLite-Local: centro' --data-binary @- > $TMP/respuesta-concurrente-$i) & done; wait
expect "5 lecturas simultáneas del mismo archivo crean una sola" 1 "$(docker compose -p myposlite exec -T backend php -r 'require "backend/config/database.php"; $q = conectarBaseLite()->prepare("SELECT COUNT(*) FROM lecturas_documento WHERE empresa_id = ? AND archivo_id = ?"); $q->execute([$argv[1], $argv[2]]); echo $q->fetchColumn();' $A1 $CONC)"
expect "las demás reutilizan o esperan" "" "$(cat $TMP/respuesta-concurrente-* | grep -o -E '[0-9]{3}' | grep -v -E '^(200|201|409)$')"

echo "--- Permisos y aislamiento"
expect "la empresa 2 no lee un archivo ajeno" 404 "$(leer $J2 $A2 $F2)"
expect "ni consulta un borrador ajeno" 404 "$(call $J2 GET "digitalizacion.php?id=$L2" $A2 centro)"
call $J2 POST contactos.php $A2 centro '{"rut":"76.086.428-5","razonSocial":"Proveedor de otra empresa","correo":"","proveedor":true,"receptor":false}' >/dev/null; P2=$(py "d['id']")
call $J2 POST productos.php $A2 centro "$(prod QA-AJENO 'Ajeno QA' "$(pres Unidad '' 1 100)")" >/dev/null
call $J2 GET productos.php $A2 centro >/dev/null; UNI2=$(py "d['productos'][0]['presentaciones'][0]['id']")
call $J1 GET digitalizacion.php $A1 centro >/dev/null; LISTA_A1=$(py "[l['id'] for l in d['lecturas'] if l['estado'] == 'lista'][0]")
expect "ni confirma con una lectura ajena" 409 "$(call $J2 POST recepciones.php $A2 centro "{\"clave\":\"$(clave)\",\"tipo\":52,\"proveedorId\":\"$P2\",\"folio\":\"1\",\"fecha\":\"2026-11-10\",\"montoTotal\":\"1\",\"lecturaId\":\"$LISTA_A1\",\"lineas\":[{\"presentacionId\":\"$UNI2\",\"cantidad\":\"1\"}]}")"
expect "la empresa 2 no ve lecturas ajenas" "[]" "$(call $J2 GET digitalizacion.php $A2 centro >/dev/null; py "d['lecturas']")"
VC="qa-created-$T1@example.invalid"; VP="Qa$(head -c 12 /dev/urandom | base64 | tr -d '/+=')9x"
call $J1 POST usuarios.php $A1 centro "{\"correo\":\"$VC\",\"nombre\":\"Vendedor QA\",\"clave\":\"$VP\",\"rol\":\"vendedor\",\"locales\":[\"centro\"],\"cajas\":[]}" >/dev/null
entrar $JV $VC $VP $A1 centro >/dev/null
expect "vendedor no digitaliza" 403 "$(leer $JV $A1 $F2)"
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$B1\",\"localCodigo\":\"sur\"}" >/dev/null
expect "contador consulta lecturas" 200 "$(call $J1 GET digitalizacion.php $B1 sur)"
expect "contador no digitaliza" 403 "$(call $J1 POST digitalizacion.php $B1 sur '{"archivoId":"1"}')"

resultado
