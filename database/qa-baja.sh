#!/bin/bash
# QA de la baja de empresa (Sprint 08): exportación ZIP completa y sin secretos, descarga registrada, confirmación con
# RUT y huella, acceso cortado, eliminación de datos y archivos por el operador con registro, y otra empresa intacta.
# También comprueba que un archivo vencido por la regla de un año desaparece sin que su documento pierda los datos.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo); FX2=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a)
T2=$(campo "$FX2" tag); A2=$(campo "$FX2" a)
J1=$(mktemp); J2=$(mktemp); JV=$(mktemp); TMP=$(mktemp -d)
docker compose -p myposlite stop scheduler >/dev/null 2>&1
php() { MSYS_NO_PATHCONV=1 docker compose -p myposlite exec -T backend php -r "require 'backend/config/database.php'; \$db = conectarBaseLite(); $1" "${@:2}"; }
limpiar() { docker compose -p myposlite start scheduler >/dev/null 2>&1
  for a in $A1 $A2; do MSYS_NO_PATHCONV=1 docker compose -p myposlite exec -T storage sh /opt/lite-storage/retirar-empresa.sh $a; done
  php '$db->prepare("DELETE FROM empresa_bajas WHERE empresa_id = ?")->execute([$argv[1]]);' $A1
  fixture borrar $T1; fixture borrar $T2; rm -rf $J1 $J2 $JV $OUT $TMP; }
trap limpiar EXIT
source "$(dirname "$0")/qa-lib-dte.sh"
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
subirArchivo() { curl -s -b $1 -X POST "$B/archivos.php" -H 'X-MyPOSLite-Request: 1' -H "X-MyPOSLite-Empresa: $2" -H 'X-MyPOSLite-Local: centro' -F "archivo=@$(ruta $3)" | python -c "import json,sys; print(json.load(sys.stdin)['id'])"; }
filas() { php 'foreach ($db->query("SELECT table_name FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = \"empresa_id\" AND table_name <> \"empresa_bajas\" ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN) as $t) { $q = $db->prepare("SELECT COUNT(*) FROM `$t` WHERE empresa_id = ?"); $q->execute([$argv[1]]); $n = (int) $q->fetchColumn(); if ($n) echo "$t=$n "; }' $1; }
baja() { call $J1 POST bajas.php $A1 centro "$1"; }

expect "ingreso administrador" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
expect "ingreso en otra empresa" 200 "$(entrar $J2 "$(campo "$FX2" correo)" "$(campo "$FX2" clave)" $A2 centro)"
bash database/aprovisionar-almacenamiento.sh $A1 $A2 >/dev/null || exit 1
sii normal
expect "boletas en producción" 200 "$(produccion $J1 $A1 11111111-1)"
call $J1 POST ubicaciones.php $A1 centro '{"tipo":"caja","codigo":"1","nombre":"Caja 1","localCodigo":"centro"}' >/dev/null
call $J1 POST productos.php $A1 centro "$(prod QA-BEB 'Bebida QA' "$(pres Unidad '' 1 1190)")" >/dev/null
UNI=$(call $J1 GET productos.php $A1 centro >/dev/null; py "d['productos'][0]['presentaciones'][0]['id']")
call $J1 POST pos.php $A1 centro '{"accion":"abrir","caja":"1","montoInicial":0}' >/dev/null
call $J1 POST pos.php $A1 centro "{\"accion\":\"vender\",\"clave\":\"$(clave)\",\"caja\":\"1\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"2\"}],\"pagos\":[{\"medio\":\"efectivo\",\"monto\":2380}]}" >/dev/null
printf '%%PDF-1.4\n%% guia de baja %s\n%%%%EOF\n' "$(clave)" > $TMP/guia.pdf
ARCHIVO=$(subirArchivo $J1 $A1 $TMP/guia.pdf); HUELLA_PDF=$(sha256sum $TMP/guia.pdf | cut -d' ' -f1)
printf '%%PDF-1.4\n%% otra empresa %s\n%%%%EOF\n' "$(clave)" > $TMP/otra.pdf
subirArchivo $J2 $A2 $TMP/otra.pdf >/dev/null
ANTES_B=$(filas $A2)

echo "--- Exportación"
VC="qa-created-$T1@example.invalid"; VP="Qa$(head -c 12 /dev/urandom | base64 | tr -d '/+=')9x"
call $J1 POST usuarios.php $A1 centro "{\"correo\":\"$VC\",\"nombre\":\"Supervisora QA\",\"clave\":\"$VP\",\"rol\":\"supervisor\",\"locales\":[\"centro\"],\"cajas\":[]}" >/dev/null
entrar $JV "$VC" "$VP" $A1 centro >/dev/null
expect "una supervisora no da de baja la empresa" 403 "$(call $JV POST bajas.php $A1 centro '{"accion":"preparar"}')"
expect "confirmar sin exportación" 409 "$(baja '{"accion":"confirmar","rut":"x","huella":"x"}')"
expect "prepara la exportación" 201 "$(baja '{"accion":"preparar"}')"
HUELLA=$(py "d['baja']['huella']")
expect "resumen: una venta, su boleta, cinco boletas del set y el archivo" "1 1 1" "$(py "d['baja']['resumen']['tablas']['ventas']") $(py "d['baja']['resumen']['recibidos']") $(py "int(d['baja']['resumen']['emitidos'] >= 1)")"
expect "confirmar antes de descargar" 409 "$(baja "{\"accion\":\"confirmar\",\"rut\":\"x\",\"huella\":\"$HUELLA\"}")"
expect "descarga el ZIP" 200 "$(curl -s -o $TMP/baja.zip -w '%{http_code}' -b $J1 "$B/bajas.php?descargar=1" -H "X-MyPOSLite-Empresa: $A1" -H 'X-MyPOSLite-Local: centro')"
expect "con la huella anunciada" "$HUELLA" "$(sha256sum $TMP/baja.zip | cut -d' ' -f1)"
ZIP=$(ruta $TMP/baja.zip)
expect "trae datos, emitidos, recibidos y LEEME" True "$(python -c "
import zipfile; n = zipfile.ZipFile(r'$ZIP').namelist()
print(all(any(x.startswith(p) for x in n) for p in ['datos/ventas.jsonl', 'datos/usuarios.jsonl', 'emitidos/produccion/39-', 'recibidos/$ARCHIVO-', 'LEEME.txt']))")"
expect "la foto de la guía va con sus bytes" "$HUELLA_PDF" "$(python -c "
import hashlib, zipfile; z = zipfile.ZipFile(r'$ZIP'); print(hashlib.sha256(z.read([x for x in z.namelist() if x.startswith('recibidos/')][0])).hexdigest())")"
expect "el XML de la boleta va firmado y en ISO-8859-1" True "$(python -c "
import zipfile; z = zipfile.ZipFile(r'$ZIP'); x = z.read([n for n in z.namelist() if n.startswith('emitidos/produccion/39-')][0])
print(x.startswith(b'<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?>') and b'<SignatureValue>' in x)")"
expect "sin certificados, contraseñas, CAF ni hashes" False "$(python -c "
import zipfile; z = zipfile.ZipFile(r'$ZIP'); t = b''.join(z.read(n) for n in z.namelist() if n.startswith('datos/'))
print(any(k in t for k in [b'pfx_cifrado', b'clave_cifrada', b'xml_cifrado', b'clave_hash', b'RSASK', b'BEGIN']))")"
expect "sin datos de la otra empresa" False "$(python -c "
import zipfile; z = zipfile.ZipFile(r'$ZIP'); t = b''.join(z.read(n) for n in z.namelist() if n.startswith('datos/'))
print(b'\"empresa_id\":\"$A2\"' in t)")"

echo "--- Confirmación"
RUT=$(call $J1 GET dte.php $A1 centro >/dev/null; py "d['rut']")
expect "con otro RUT" 422 "$(baja "{\"accion\":\"confirmar\",\"rut\":\"11.111.111-1\",\"huella\":\"$HUELLA\"}")"
expect "con otra huella" 422 "$(baja "{\"accion\":\"confirmar\",\"rut\":\"$RUT\",\"huella\":\"$(printf '0%.0s' {1..64})\"}")"
expect "confirma con RUT y huella" "200 confirmada" "$(baja "{\"accion\":\"confirmar\",\"rut\":\"$RUT\",\"huella\":\"$HUELLA\"}") $(py "d['baja']['estado']")"
expect "la sesión abierta pierde el acceso" 403 "$(call $J1 GET productos.php $A1 centro)"
expect "la empresa ya no aparece al entrar" False "$(call $J1 GET session.php '' '' >/dev/null; py "any(e['id'] == '$A1' for e in d['empresas'])")"
expect "el ZIP ya no queda en el servidor" 0 "$(MSYS_NO_PATHCONV=1 docker compose -p myposlite exec -T backend sh -c "ls /tmp/lite-bajas/ 2>/dev/null | grep -c '^empresa-$A1-' || true")"

echo "--- Eliminación por el operador"
expect "el operador no elimina una empresa sin baja confirmada" 1 "$(bash database/baja-empresa.sh $A2 >/dev/null 2>&1; echo $?)"
expect "elimina la empresa con baja confirmada" 0 "$(bash database/baja-empresa.sh $A1 >$TMP/baja.log 2>&1; echo $?)"
expect "no quedan filas de la empresa" "" "$(filas $A1)"
expect "ni la empresa" 0 "$(php '$q = $db->prepare("SELECT COUNT(*) FROM empresas WHERE id = ?"); $q->execute([$argv[1]]); echo $q->fetchColumn();' $A1)"
expect "la supervisora, sin otra empresa, se elimina" 0 "$(php '$q = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE correo = ?"); $q->execute([$argv[1]]); echo $q->fetchColumn();' "$VC")"
expect "el administrador conserva sus otras empresas" 1 "$(php '$q = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE correo = ?"); $q->execute([$argv[1]]); echo $q->fetchColumn();' "$(campo "$FX1" correo)")"
expect "el bucket de la empresa ya no existe" 1 "$(MSYS_NO_PATHCONV=1 docker compose -p myposlite exec -T storage sh -c 'export MC_CONFIG_DIR=$(mktemp -d); mc alias set l http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null; mc ls l/lite-recepcion-'$A1' >/dev/null 2>&1; echo $?')"
expect "queda el registro de la baja con su RUT" "eliminada ${RUT//-/}" "$(php '$q = $db->prepare("SELECT CONCAT(estado, \" \", rut) FROM empresa_bajas WHERE empresa_id = ?"); $q->execute([$argv[1]]); echo $q->fetchColumn();' $A1)"
expect "la otra empresa no cambió" "$ANTES_B" "$(filas $A2)"

echo "--- Archivo vencido por la regla de un año"
expect "el bucket tiene la regla de 365 días" True "$(MSYS_NO_PATHCONV=1 docker compose -p myposlite exec -T storage sh -c 'export MC_CONFIG_DIR=$(mktemp -d); mc alias set l http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null; mc ilm rule export l/lite-recepcion-'$A2'' | python -c "import json,sys; print(any(r.get('Expiration', {}).get('Days') == 365 for r in json.load(sys.stdin)['Rules']))")"
OTRO=$(php '$q = $db->prepare("SELECT CONCAT(id, \" \", huella) FROM archivos_recibidos WHERE empresa_id = ?"); $q->execute([$argv[1]]); echo $q->fetchColumn();' $A2)
# Lo que hace la regla al cumplirse el año: el objeto desaparece del almacenamiento.
MSYS_NO_PATHCONV=1 docker compose -p myposlite exec -T storage sh -c 'export MC_CONFIG_DIR=$(mktemp -d); mc alias set l http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null; mc rm l/lite-recepcion-'$A2'/'"${OTRO#* }"' >/dev/null'
expect "el registro del archivo conserva nombre, tipo y tamaño" "otra.pdf application/pdf True" "$(call $J2 GET archivos.php $A2 centro >/dev/null; py "' '.join(str(x) for x in [(a['nombre'], a['tipo_mime'], a['tamano'] > 0) for a in d['archivos'] if a['id'] == '${OTRO%% *}'][0])")"
resultado
