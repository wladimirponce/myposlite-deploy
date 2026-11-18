#!/bin/bash
# QA por API del respaldo de archivos de ingreso en MinIO (Sprint 03): subida, duplicado por huella, no reemplazo,
# enlace temporal, aislamiento entre empresas, adjunto al documento, almacenamiento no disponible y vencimiento.
# Requiere el perfil storage levantado. Aprovisiona las empresas de prueba y retira sus buckets al terminar.
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
# subir JAR EMPRESA ARCHIVO [NOMBRE] -> código HTTP; la respuesta queda en $OUT
# En Git Bash, la ruta pegada a archivo=@ no se traduce para el curl de Windows: se convierte aquí.
ruta() { if command -v cygpath >/dev/null; then cygpath -m "$1"; else echo "$1"; fi; }
subir() { curl -s -o $OUT -w '%{http_code}' -b $1 -c $1 -X POST "$B/archivos.php" -H 'X-MyPOSLite-Request: 1' \
  -H "X-MyPOSLite-Empresa: $2" -H 'X-MyPOSLite-Local: centro' -H 'User-Agent: QA Android 14' -F "archivo=@$(ruta $3);filename=${4:-$(basename $3)}"; }
leer() { call $1 GET "archivos.php?enlace=$3" $2 centro >/dev/null; py "d.get('url', '')"; }
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }

# Archivos de prueba: el tipo se reconoce por el contenido.
printf '%%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%% Guía ficticia QA %s\n%%%%EOF\n' "$T1" > $TMP/guia.pdf
printf '\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00Foto ficticia QA %s\xff\xd9' "$T1" > $TMP/foto.jpg
printf '\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00Borrador descartado %s\xff\xd9' "$T1" > $TMP/descartada.jpg
printf 'Esto no es una foto' > $TMP/nota.txt
printf '%%PDF-1.4 vacío\n' > $TMP/grande.pdf; head -c 10485800 /dev/zero >> $TMP/grande.pdf

expect "ingreso administrador 1" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
expect "ingreso administrador 2" 200 "$(entrar $J2 "$(campo "$FX2" correo)" "$(campo "$FX2" clave)" $A2 centro)"
call $J1 POST contactos.php $A1 centro '{"rut":"76.086.428-5","razonSocial":"Distribuidora QA","correo":"","proveedor":true,"receptor":false}' >/dev/null; P1=$(py "d['id']")
call $J1 POST productos.php $A1 centro "$(prod QA-BEB 'Bebida QA' "$(pres Unidad '' 1 1000)")" >/dev/null
call $J1 GET productos.php $A1 centro >/dev/null; UNI=$(py "d['productos'][0]['presentaciones'][0]['id']")
call $J2 POST contactos.php $A2 centro '{"rut":"76.086.428-5","razonSocial":"Proveedor de otra empresa","correo":"","proveedor":true,"receptor":false}' >/dev/null; P2=$(py "d['id']")
call $J2 POST productos.php $A2 centro "$(prod QA-AJENO 'Ajeno QA' "$(pres Unidad '' 1 1000)")" >/dev/null
call $J2 GET productos.php $A2 centro >/dev/null; UNI2=$(py "d['productos'][0]['presentaciones'][0]['id']")

echo "--- Almacenamiento no aprovisionado: el ingreso manual sigue"
expect "subida sin bucket de la empresa" 503 "$(subir $J1 $A1 $TMP/guia.pdf)"
expect "el mensaje ofrece seguir sin adjunto" True "$(py "'sin adjunto' in d['error']")"
expect "el documento se ingresa igual" 201 "$(call $J1 POST recepciones.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":52,\"proveedorId\":\"$P1\",\"folio\":\"900\",\"fecha\":\"2026-11-10\",\"montoTotal\":\"1000\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"1\"}]}")"
expect "no quedó registro del archivo fallido" "[]" "$(call $J1 GET archivos.php $A1 centro >/dev/null; py "d['archivos']")"
bash database/aprovisionar-almacenamiento.sh $A1 $B1 $A2 >/dev/null || exit 1

echo "--- Subida y duplicado por huella"
expect "PDF de una guía" 201 "$(subir $J1 $A1 $TMP/guia.pdf 'Guía 1001.pdf')"
PDF=$(py "d['id']")
expect "foto JPEG" 201 "$(subir $J1 $A1 $TMP/foto.jpg)"
FOTO=$(py "d['id']")
expect "el mismo PDF con otro nombre es duplicado" "200 $PDF True" "$(subir $J1 $A1 $TMP/guia.pdf 'otro-nombre.pdf') $(py "d['id'], d['duplicado']" | tr -d "(),'")"
expect "texto plano rechazado por su contenido" 422 "$(subir $J1 $A1 $TMP/nota.txt 'disfrazado.pdf')"
expect "archivo de más de 10 MB" 413 "$(subir $J1 $A1 $TMP/grande.pdf)"
expect "petición sin archivo" 422 "$(curl -s -o $OUT -w '%{http_code}' -b $J1 -X POST "$B/archivos.php" -H 'X-MyPOSLite-Request: 1' -H "X-MyPOSLite-Empresa: $A1" -H 'X-MyPOSLite-Local: centro' -F 'otro=x')"
call $J1 GET archivos.php $A1 centro >/dev/null
expect "dos archivos sin documento, con usuario, local y dispositivo" "2 centro QA Android 14 application/pdf" "$(py "len(d['archivos']), *[(a['local_codigo'], a['dispositivo'], a['tipo_mime']) for a in d['archivos'] if a['id'] == '$PDF'][0]" | tr -d "(),'")"

echo "--- Enlace temporal y no reemplazo"
URL=$(leer $J1 $A1 $PDF)
expect "el enlace devuelve el PDF original" "$(sha256sum < $TMP/guia.pdf | cut -c1-64)" "$(curl -s "$URL" | sha256sum | cut -c1-64)"
expect "el enlace vence en 5 minutos" True "$(py "d['expiraEn'] == 300 and 'X-Amz-Expires=300' in d['url']")"
expect "sin firma no se lee" 403 "$(curl -s -o /dev/null -w '%{http_code}' "${URL%%\?*}")"
expect "con la firma alterada no se lee" 403 "$(curl -s -o /dev/null -w '%{http_code}' "${URL%?}0")"
CRED=$(docker compose -p myposlite exec -T backend php backend/bin/clave-almacenamiento.php $A1)
OBJ=$(echo "${URL%%\?*}" | sed -E 's#.*/##'); INTERNO="http://storage:9000/lite-recepcion-$A1/$OBJ"
expect "la empresa no puede reemplazar su objeto" 412 "$(docker compose -p myposlite exec -T backend curl -s -o /dev/null -w '%{http_code}' --aws-sigv4 aws:amz:us-east-1:s3 --user "lite-empresa-$A1:$CRED" -X PUT -H 'If-None-Match: *' --data-binary otro "$INTERNO")"
expect "ni borrarlo" 403 "$(docker compose -p myposlite exec -T backend curl -s -o /dev/null -w '%{http_code}' --aws-sigv4 aws:amz:us-east-1:s3 --user "lite-empresa-$A1:$CRED" -X DELETE "$INTERNO")"
CRED2=$(docker compose -p myposlite exec -T backend php backend/bin/clave-almacenamiento.php $A2)
expect "la credencial de otra empresa no lee el bucket" 403 "$(docker compose -p myposlite exec -T backend curl -s -o /dev/null -w '%{http_code}' --aws-sigv4 aws:amz:us-east-1:s3 --user "lite-empresa-$A2:$CRED2" "$INTERNO")"

echo "--- Adjunto al documento y borrador descartado"
DOC="{\"clave\":\"$(clave)\",\"tipo\":52,\"proveedorId\":\"$P1\",\"folio\":\"1001\",\"fecha\":\"2026-11-10\",\"montoTotal\":\"5000\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"5\"}],\"archivos\":[\"$PDF\",\"$FOTO\"]}"
expect "guía con PDF y foto adjuntos" 201 "$(call $J1 POST recepciones.php $A1 centro "$DOC")"
G1001=$(py "d['id']")
call $J1 GET "archivos.php?documento=$G1001" $A1 centro >/dev/null
expect "el documento lista sus dos archivos" 2 "$(py "len(d['archivos'])")"
call $J1 GET recepciones.php $A1 centro >/dev/null
expect "el listado de documentos cuenta los adjuntos" 2 "$(py "[x['archivos'] for x in d['documentos'] if x['folio'] == '1001'][0]")"
expect "un archivo ya adjunto no se adjunta a otro documento" 409 "$(call $J1 POST recepciones.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":52,\"proveedorId\":\"$P1\",\"folio\":\"1002\",\"fecha\":\"2026-11-10\",\"montoTotal\":\"1000\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"1\"}],\"archivos\":[\"$PDF\"]}")"
call $J1 GET recepciones.php $A1 centro >/dev/null
expect "y ese documento no quedó registrado" "[]" "$(py "[x['folio'] for x in d['documentos'] if x['folio'] == '1002']")"
expect "subir la foto de un borrador" 201 "$(subir $J1 $A1 $TMP/descartada.jpg)"
DESC=$(py "d['id']")
expect "tras descartar el borrador la foto sigue en la nube" "$(sha256sum < $TMP/descartada.jpg | cut -c1-64)" "$(curl -s "$(leer $J1 $A1 $DESC)" | sha256sum | cut -c1-64)"
expect "y figura entre los archivos sin documento" "['$DESC']" "$(call $J1 GET archivos.php $A1 centro >/dev/null; py "[a['id'] for a in d['archivos']]")"

echo "--- Aislamiento entre empresas"
expect "la empresa 2 no obtiene el enlace de un archivo ajeno" 404 "$(call $J2 GET "archivos.php?enlace=$PDF" $A2 centro)"
expect "ni lista sus archivos" "[]" "$(call $J2 GET archivos.php $A2 centro >/dev/null; py "d['archivos']")"
expect "ni los archivos de un documento ajeno" "[]" "$(call $J2 GET "archivos.php?documento=$G1001" $A2 centro >/dev/null; py "d['archivos']")"
expect "la empresa 2 respalda el mismo PDF por separado" 201 "$(subir $J2 $A2 $TMP/guia.pdf)"
PDF2=$(py "d['id']")
expect "no puede adjuntar el archivo de la empresa 1" 409 "$(call $J2 POST recepciones.php $A2 centro "{\"clave\":\"$(clave)\",\"tipo\":52,\"proveedorId\":\"$P2\",\"folio\":\"1001\",\"fecha\":\"2026-11-10\",\"montoTotal\":\"1000\",\"lineas\":[{\"presentacionId\":\"$UNI2\",\"cantidad\":\"1\"}],\"archivos\":[\"$DESC\"]}")"
expect "encabezado de empresa ajena" 409 "$(call $J2 GET "archivos.php?enlace=$PDF2" $A1 centro)"

echo "--- Permisos y vencimiento"
VC="qa-created-$T1@example.invalid"; VP="Qa$(head -c 12 /dev/urandom | base64 | tr -d '/+=')9x"
call $J1 POST usuarios.php $A1 centro "{\"correo\":\"$VC\",\"nombre\":\"Vendedor QA\",\"clave\":\"$VP\",\"rol\":\"vendedor\",\"locales\":[\"centro\"],\"cajas\":[]}" >/dev/null
entrar $JV $VC $VP $A1 centro >/dev/null
expect "vendedor no sube archivos" 403 "$(subir $JV $A1 $TMP/foto.jpg)"
expect "vendedor no obtiene enlaces" 403 "$(call $JV GET "archivos.php?enlace=$PDF" $A1 centro)"
docker compose -p myposlite exec -T backend php -r 'require "backend/config/database.php"; conectarBaseLite()->prepare("UPDATE archivos_recibidos SET subido_en = UTC_TIMESTAMP() - INTERVAL 366 DAY WHERE empresa_id = ? AND id = ?")->execute([$argv[1], $argv[2]]);' $A1 $FOTO
expect "un archivo de más de un año ya no entrega enlace" 410 "$(call $J1 GET "archivos.php?enlace=$FOTO" $A1 centro)"
expect "pero su registro sigue en el documento" "False" "$(call $J1 GET "archivos.php?documento=$G1001" $A1 centro >/dev/null; py "[a['disponible'] for a in d['archivos'] if a['id'] == '$FOTO'][0]")"
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$B1\",\"localCodigo\":\"sur\"}" >/dev/null
expect "contador consulta archivos" 200 "$(call $J1 GET archivos.php $B1 sur)"
expect "contador no sube archivos" 403 "$(curl -s -o $OUT -w '%{http_code}' -b $J1 -X POST "$B/archivos.php" -H 'X-MyPOSLite-Request: 1' -H "X-MyPOSLite-Empresa: $B1" -H 'X-MyPOSLite-Local: sur' -F "archivo=@$(ruta $TMP/foto.jpg)")"

resultado
