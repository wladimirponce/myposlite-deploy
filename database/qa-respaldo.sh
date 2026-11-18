#!/bin/bash
# QA de respaldo y recuperación (Sprint 08): respalda base y archivos con el entorno en uso, restaura en un proyecto
# aislado y comprueba allí las filas del manifiesto, que la boleta aceptada sigue aceptada sin reenviarse, que el
# archivo de ingreso conserva sus bytes y que la venta siguiente continúa los folios. El entorno en uso no cambia.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a)
J1=$(mktemp); JR=$(mktemp); TMP=$(mktemp -d)
AISLADO=myposlite-qa-restauracion
export MYPOSLITE_RESTAURACION_BACKEND_PORT=18098 MYPOSLITE_RESTAURACION_STORAGE_PORT=19098 MYPOSLITE_RESTAURACION_FRONTEND_PORT=5188
docker compose -p myposlite stop scheduler >/dev/null 2>&1
limpiar() { [ -n "${QA_CONSERVAR:-}" ] || bash database/restaurar-aislado.sh --retirar $AISLADO >/dev/null 2>&1; docker compose -p myposlite start scheduler >/dev/null 2>&1
  MSYS_NO_PATHCONV=1 docker compose -p myposlite exec -T storage sh /opt/lite-storage/retirar-empresa.sh $A1; fixture borrar $T1; rm -rf $J1 $JR $OUT $TMP; }
trap limpiar EXIT
source "$(dirname "$0")/qa-lib-dte.sh"
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
PRINCIPAL=$B
vender() { call $1 POST pos.php $A1 centro "{\"accion\":\"vender\",\"clave\":\"$(clave)\",\"caja\":\"1\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"1\"}],\"pagos\":[{\"medio\":\"efectivo\",\"monto\":1190}]}" >/dev/null; py "d['id']"; }
boleta() { call $1 GET "pos.php?venta=$2" $A1 centro >/dev/null; py "(d['documento']['folio'], d['documento']['estado'])"; }

expect "ingreso administrador" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
bash database/aprovisionar-almacenamiento.sh $A1 >/dev/null || exit 1
sii normal
expect "boletas en producción" 200 "$(produccion $J1 $A1 11111111-1)"
call $J1 POST ubicaciones.php $A1 centro '{"tipo":"caja","codigo":"1","nombre":"Caja 1","localCodigo":"centro"}' >/dev/null
call $J1 POST productos.php $A1 centro "$(prod QA-BEB 'Bebida QA' "$(pres Unidad '' 1 1190)")" >/dev/null
UNI=$(call $J1 GET productos.php $A1 centro >/dev/null; py "d['productos'][0]['presentaciones'][0]['id']")
call $J1 POST pos.php $A1 centro '{"accion":"abrir","caja":"1","montoInicial":0}' >/dev/null
V1=$(vender $J1)
call $J1 POST dte.php $A1 centro '{"accion":"enviar-boletas"}' >/dev/null
E=$(call $J1 GET dte.php $A1 centro >/dev/null; py "d['ambientes']['produccion']['envios'][0]['id']")
call $J1 POST dte.php $A1 centro "{\"accion\":\"consultar\",\"envio\":\"$E\"}" >/dev/null
ANTES=$(boleta $J1 $V1)
expect "la boleta de la venta queda aceptada" aceptado "$(python -c "print($ANTES[1])")"
printf '%%PDF-1.4\n%% guia respaldada %s\n%%%%EOF\n' "$(clave)" > $TMP/guia.pdf
ARCHIVO=$(curl -s -b $J1 -X POST "$B/archivos.php" -H 'X-MyPOSLite-Request: 1' -H "X-MyPOSLite-Empresa: $A1" -H 'X-MyPOSLite-Local: centro' -F "archivo=@$(ruta $TMP/guia.pdf)" | python -c "import json,sys; print(json.load(sys.stdin)['id'])")
HUELLA=$(sha256sum $TMP/guia.pdf | cut -d' ' -f1)

echo "--- Respaldo"
CARPETA=$(bash database/respaldo.sh $TMP/respaldos)
expect "respaldo con base, archivos y manifiesto" "3" "$(ls $CARPETA | grep -c -E '^(base.sql.gz|objetos.tar.gz|manifiesto.json)$')"
MANIFIESTO=$(ruta $CARPETA/manifiesto.json)
expect "el manifiesto no trae secretos del .env" 0 "$(grep -c -E 'MYPOSLITE_|PASSWORD|SECRET' $CARPETA/manifiesto.json)"
expect "el manifiesto registra la versión y las migraciones" True "$(python -c "import json; m=json.load(open(r'$MANIFIESTO', encoding='utf-8')); print(len(m['version']) >= 7 and '030_soporte_cambio_proveedor.sql' in m['migraciones'])")"
expect "un respaldo dañado no se restaura" 1 "$(mkdir -p $TMP/danado; cp $CARPETA/manifiesto.json $TMP/danado/; printf 'x' | gzip > $TMP/danado/base.sql.gz; bash database/restaurar-aislado.sh $TMP/danado $AISLADO >/dev/null 2>&1; echo $?)"
expect "nunca sobre el entorno en uso" 1 "$(bash database/restaurar-aislado.sh $CARPETA myposlite >/dev/null 2>&1; echo $?)"

echo "--- Restauración aislada"
expect "restaura en otro proyecto" 0 "$(bash database/restaurar-aislado.sh $CARPETA $AISLADO >$TMP/restaura.log 2>&1; echo $?)"
FILAS=$(MSYS_NO_PATHCONV=1 docker compose -p $AISLADO exec -T backend php -r '
require "backend/config/database.php"; $db = conectarBaseLite(); $m = json_decode(stream_get_contents(STDIN), true); $distintas = [];
foreach ($m["tablas"] as $t => $n) { $r = (int) $db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); if ($r !== $n) { $distintas[] = "$t $n/$r"; } }
echo $distintas === [] ? "iguales" : implode(", ", $distintas);' < $CARPETA/manifiesto.json)
expect "las filas de cada tabla coinciden con el manifiesto" iguales "$FILAS"
B=http://127.0.0.1:5188/api
expect "el mismo usuario entra al entorno restaurado" 200 "$(entrar $JR "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
expect "la boleta sigue aceptada con su folio" "$ANTES" "$(boleta $JR $V1)"
xml() { MSYS_NO_PATHCONV=1 docker compose -p $1 exec -T backend php -r 'require "backend/config/database.php"; $q = conectarBaseLite()->prepare("SELECT SHA2(d.xml, 256) FROM dte_documentos d JOIN ventas v ON v.empresa_id = d.empresa_id AND v.documento_id = d.id WHERE v.empresa_id = ? AND v.id = ?"); $q->execute([$argv[1], $argv[2]]); echo $q->fetchColumn();' $A1 $V1; }
expect "el XML firmado de la boleta es idéntico, byte a byte" "$(xml myposlite)" "$(xml $AISLADO)"
expect "nada queda pendiente de envío: no se reemite" "0" "$(call $JR POST dte.php $A1 centro '{"accion":"enviar-boletas"}' >/dev/null; py "d['enviados'] + d['pendientes']")"
call $JR GET "archivos.php?enlace=$ARCHIVO" $A1 centro >/dev/null; URL=$(py "d['url']")
expect "el archivo de ingreso conserva sus bytes" "$HUELLA" "$(curl -s "$URL" | sha256sum | cut -d" " -f1)"
expect "el enlace apunta al almacenamiento aislado" True "$(python -c "print('127.0.0.1:19098' in '$URL')")"
V2=$(vender $JR)
expect "la venta siguiente continúa los folios" "$(python -c "print($ANTES[0] + 1)")" "$(boleta $JR $V2 | python -c "import sys; print(eval(sys.stdin.read())[0])")"

echo "--- Entorno en uso"
B=$PRINCIPAL
expect "el entorno en uso no recibió la venta del restaurado" 1 "$(docker compose -p myposlite exec -T backend php -r 'require "backend/config/database.php"; $q = conectarBaseLite()->prepare("SELECT COUNT(*) FROM ventas WHERE empresa_id = ?"); $q->execute([$argv[1]]); echo $q->fetchColumn();' $A1)"
resultado
