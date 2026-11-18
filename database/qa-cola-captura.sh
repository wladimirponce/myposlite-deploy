#!/bin/bash
# QA por API de la captura desde el celular (Sprint 03, ampliación): la foto tomada en un equipo queda en la cola del
# local con su lectura y se confirma desde otro equipo; la cola es por local; descartar la saca de la cola sin
# borrarla de la nube; errores de lectura visibles; permisos y aislamiento. Requiere el lector simulado.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo); FX2=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a); B1=$(campo "$FX1" b)
T2=$(campo "$FX2" tag); A2=$(campo "$FX2" a)
CEL=$(mktemp); PC=$(mktemp); J2=$(mktemp); TMP=$(mktemp -d)
limpiar() {
  for e in $A1 $B1 $A2; do MSYS_NO_PATHCONV=1 docker compose -p myposlite exec -T storage sh /opt/lite-storage/retirar-empresa.sh $e; done
  fixture borrar $T1; fixture borrar $T2; rm -rf $CEL $PC $J2 $OUT $TMP
}
trap limpiar EXIT
ruta() { if command -v cygpath >/dev/null; then cygpath -m "$1"; else echo "$1"; fi; }
# subir JAR EMPRESA LOCAL ARCHIVO: respalda como lo haría el celular, con su agente de navegador.
subir() { curl -s -o $OUT -w '%{http_code}' -b $1 -c $1 -X POST "$B/archivos.php" -H 'X-MyPOSLite-Request: 1' \
  -A 'Mozilla/5.0 (Linux; Android 14) QA Celular' -H "X-MyPOSLite-Empresa: $2" -H "X-MyPOSLite-Local: $3" -F "archivo=@$(ruta $4)"; }
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
foto() {
  local marca="LECTURA-SIMULADA:$2 $(head -c 8 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  printf '\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00%s\xff\xd9' "$marca" > $1
}
lectura() { printf '%s' "$1" | base64 -w0; }
cola() { call $1 GET archivos.php $2 ${3:-centro} >/dev/null; py "$4"; }
descartar() { call $1 PATCH archivos.php $2 ${3:-centro} "{\"id\":\"$4\",\"descartar\":true}"; }

CORREO=$(campo "$FX1" correo); CLAVE=$(campo "$FX1" clave)
expect "el celular inicia sesión" 200 "$(entrar $CEL "$CORREO" "$CLAVE" $A1 centro)"
expect "el computador inicia otra sesión del mismo usuario" 200 "$(entrar $PC "$CORREO" "$CLAVE" $A1 centro)"
expect "administrador de otra empresa" 200 "$(entrar $J2 "$(campo "$FX2" correo)" "$(campo "$FX2" clave)" $A2 centro)"
bash database/aprovisionar-almacenamiento.sh $A1 $B1 $A2 >/dev/null || exit 1
call $PC POST contactos.php $A1 centro '{"rut":"76.086.428-5","razonSocial":"Distribuidora QA","correo":"","proveedor":true,"receptor":false}' >/dev/null; P1=$(py "d['id']")
call $PC POST productos.php $A1 centro "$(prod QA-BEB 'Bebida QA' "$(pres Unidad 7801 1 1200)")" >/dev/null
UNI=$(call $PC GET productos.php $A1 centro >/dev/null; py "[x['id'] for p in d['productos'] if p['sku'] == 'QA-BEB' for x in p['presentaciones']][0]")
GUIA="{\"tipo_documento\":\"GUIA_DESPACHO_COMPRA\",\"proveedor_rut\":\"76086428-5\",\"proveedor_nombre\":\"Distribuidora QA\",\"folio\":\"2201\",\"fecha_documento\":\"2026-11-10\",\"total\":14280,\"items\":[{\"codigo_detectado\":null,\"codigo_barra_detectado\":\"7801\",\"nombre_detectado\":\"BEBIDA\",\"cantidad_detectada\":12,\"costo_unitario_detectado\":1000,\"total_detectado\":12000}]}"

echo "--- Foto en el celular, revisión en el computador"
foto $TMP/guia.jpg "$(lectura "$GUIA")"
expect "el celular respalda la foto de la guía" 201 "$(subir $CEL $A1 centro $TMP/guia.jpg)"; FOTO=$(py "d['id']")
expect "y la lee" 201 "$(call $CEL POST digitalizacion.php $A1 centro "{\"archivoId\":\"$FOTO\"}")"
expect "el computador la ve en la cola del local, leída y con el celular como dispositivo" "lista True" \
  "$(cola $PC $A1 centro "[(a['lectura_estado'], 'Android' in a['dispositivo']) for a in d['archivos'] if a['id'] == '$FOTO'][0]" | tr -d "(),'")"
LECTURA=$(cola $PC $A1 centro "[a['lectura_id'] for a in d['archivos'] if a['id'] == '$FOTO'][0]")
expect "el computador abre el borrador" "$P1 2201 $UNI" "$(call $PC GET "digitalizacion.php?id=$LECTURA" $A1 centro >/dev/null; py "d['proveedorId'], d['detectado']['folio'], d['lineas'][0]['presentacionId']" | tr -d "(),'")"
expect "y confirma la guía" 201 "$(call $PC POST recepciones.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":52,\"proveedorId\":\"$P1\",\"folio\":\"2201\",\"fecha\":\"2026-11-10\",\"montoTotal\":\"14280\",\"lecturaId\":\"$LECTURA\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"12\"}]}")"
expect "la foto sale de la cola" "[]" "$(cola $PC $A1 centro "[a['id'] for a in d['archivos'] if a['id'] == '$FOTO']")"
expect "y queda adjunta a la guía" 1 "$(call $PC GET recepciones.php $A1 centro >/dev/null; py "[x['archivos'] for x in d['documentos'] if x['folio'] == '2201'][0]")"

echo "--- Cola por local"
foto $TMP/norte.jpg "$(lectura "${GUIA//2201/2202}")"
call $CEL PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"norte\"}" >/dev/null
expect "el celular respalda una foto en Norte" 201 "$(subir $CEL $A1 norte $TMP/norte.jpg)"; NORTE=$(py "d['id']")
expect "y la lee" 201 "$(call $CEL POST digitalizacion.php $A1 norte "{\"archivoId\":\"$NORTE\"}")"
expect "aparece en la cola de Norte" "['$NORTE']" "$(cola $CEL $A1 norte "[a['id'] for a in d['archivos']]")"
expect "no en la de Centro" "[]" "$(cola $PC $A1 centro "[a['id'] for a in d['archivos'] if a['id'] == '$NORTE']")"
expect "ni entre las lecturas pendientes de Centro" "[]" "$(call $PC GET digitalizacion.php $A1 centro >/dev/null; py "[l['archivoId'] for l in d['lecturas'] if l['archivoId'] == '$NORTE']")"
call $CEL PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"centro\"}" >/dev/null

echo "--- Lectura fallida y descarte"
foto $TMP/falla.jpg FALLA
expect "foto que el lector no puede leer" 201 "$(subir $CEL $A1 centro $TMP/falla.jpg)"; FALLA=$(py "d['id']")
expect "la lectura falla" 502 "$(call $CEL POST digitalizacion.php $A1 centro "{\"archivoId\":\"$FALLA\"}")"
expect "la foto queda en la cola con el error" "error True" "$(cola $PC $A1 centro "[(a['lectura_estado'], bool(a['lectura_error'])) for a in d['archivos'] if a['id'] == '$FALLA'][0]" | tr -d "(),'")"
foto $TMP/borrosa.jpg "$(lectura "${GUIA//2201/2203}")"
expect "foto borrosa sin leer" 201 "$(subir $CEL $A1 centro $TMP/borrosa.jpg)"; BORROSA=$(py "d['id']")
expect "sin lectura figura como tal" None "$(cola $PC $A1 centro "[a['lectura_estado'] for a in d['archivos'] if a['id'] == '$BORROSA'][0]")"
expect "el computador la descarta" 200 "$(descartar $PC $A1 centro $BORROSA)"
expect "sale de la cola" "[]" "$(cola $PC $A1 centro "[a['id'] for a in d['archivos'] if a['id'] == '$BORROSA']")"
expect "pero sigue en la nube" 200 "$(call $PC GET "archivos.php?enlace=$BORROSA" $A1 centro)"
expect "descartarla de nuevo" 409 "$(descartar $PC $A1 centro $BORROSA)"
expect "descartar una foto ya adjunta" 409 "$(descartar $PC $A1 centro $FOTO)"
expect "archivo inexistente" 404 "$(descartar $PC $A1 centro 999999999)"
expect "solicitud sin confirmación de descarte" 400 "$(call $PC PATCH archivos.php $A1 centro "{\"id\":\"$FALLA\"}")"
expect "sin el encabezado de escritura" 403 "$(curl -s -o $OUT -w '%{http_code}' -b $PC -X PATCH "$B/archivos.php" -H "X-MyPOSLite-Empresa: $A1" -H 'X-MyPOSLite-Local: centro' --data-binary "{\"id\":\"$FALLA\",\"descartar\":true}")"

echo "--- Aislamiento y permisos"
expect "otra empresa no descarta la foto" 404 "$(descartar $J2 $A2 centro $FALLA)"
expect "ni la ve en su cola" "[]" "$(cola $J2 $A2 centro "[a['id'] for a in d['archivos'] if a['id'] == '$FALLA']")"
call $PC PATCH session.php '' '' "{\"empresaId\":\"$B1\",\"localCodigo\":\"sur\"}" >/dev/null
expect "el contador no descarta" 403 "$(descartar $PC $B1 sur $FALLA)"

resultado
