#!/bin/bash
# Ayudantes de los QA del motor DTE. Usar después de qa-lib.sh: source "$(dirname "$0")/qa-lib-dte.sh"
# Requiere $TMP. Los certificados y CAF son ficticios (database/dte-ficticio.php) y no sirven ante el SII.
ruta() { if command -v cygpath >/dev/null; then cygpath -m "$1"; else echo "$1"; fi; }
ficticio() { docker compose -p myposlite exec -T backend php database/dte-ficticio.php "$@"; }
# subir JAR EMPRESA ACCION ARCHIVO [AMBIENTE] [CLAVE]: carga multipart de certificado o CAF en dte.php.
subir() { curl -s -o $OUT -w '%{http_code}' -b $1 -c $1 -X POST "$B/dte.php" -H 'X-MyPOSLite-Request: 1' \
  -H "X-MyPOSLite-Empresa: $2" -H 'X-MyPOSLite-Local: centro' --form-string "accion=$3" -F "archivo=@$(ruta $4)" \
  ${5:+--form-string "ambiente=$5"} ${6:+--form-string "clave=$6"}; }
# certificado RUT CLAVE ARCHIVO [DIAS] y caf RUT TIPO DESDE HASTA IDK [danado|-] [FECHA_AUTORIZACION] (imprime la ruta del CAF generado).
certificado() { ficticio certificado "$1" "$2" ${4:-} | base64 -d > $3; }
caf() { ficticio caf "$@" > $TMP/caf.xml; echo $TMP/caf.xml; }
# emisor JAR EMPRESA AMBIENTE NUMERO_RESOLUCION
emisor() { call $1 POST dte.php $2 centro "{\"accion\":\"emisor\",\"ambiente\":\"$3\",\"giro\":\"Venta al por menor\",\"direccion\":\"Av. Pruebas 123\",\"comuna\":\"Ñuñoa\",\"acteco\":523910,\"numeroResolucion\":$4,\"fechaResolucion\":\"2026-11-05\"${5:+,$5}}"; }
# preparado JAR EMPRESA RUT_TITULAR: emisor, certificado y CAF 1-5 de certificación listos para preparar el set.
preparado() {
  local rut; call $1 GET dte.php $2 centro >/dev/null; rut=$(py "d['rut']")
  emisor $1 $2 certificacion 0 >/dev/null
  certificado $3 'Clave QA' $TMP/firma-$2.pfx
  subir $1 $2 certificado $TMP/firma-$2.pfx certificacion 'Clave QA' >/dev/null
  subir $1 $2 caf "$(caf $rut 39 1 5 100)" >/dev/null
}
# sii [MODO]: cambia o lee el estado del SII simulado (normal, caido, perder, rechazar) y lo deja en $OUT.
sii() { docker compose -p myposlite exec -T backend php -r "echo file_get_contents('http://sii-simulado:8091/_control${1:+?modo=$1}');" > $OUT; }
# produccion JAR EMPRESA RUT_TITULAR: recorre la certificación de boletas contra el SII simulado (set y RCOF aceptados,
# visto bueno) y deja boletas 39 y 41 en producción con emisor, certificado y CAF de 1 a 200 de cada tipo.
produccion() {
  local rut e r; call $1 GET dte.php $2 centro >/dev/null; rut=$(py "d['rut']")
  preparado $1 $2 $3
  for paso in preparar-set preparar-rcof; do
    call $1 POST dte.php $2 centro "{\"accion\":\"$paso\"}" >/dev/null; e=$(py "d['envio']")
    call $1 POST dte.php $2 centro "{\"accion\":\"enviar\",\"envio\":\"$e\"}" >/dev/null
    call $1 POST dte.php $2 centro "{\"accion\":\"consultar\",\"envio\":\"$e\"}" >/dev/null
  done
  call $1 POST dte.php $2 centro '{"accion":"habilitacion","tipo":39,"estado":"en_certificacion"}' >/dev/null
  call $1 POST dte.php $2 centro '{"accion":"habilitacion","tipo":39,"estado":"visto_bueno","atencion":"QA1","fecha":"2026-11-06"}' >/dev/null
  emisor $1 $2 produccion 80 >/dev/null
  subir $1 $2 certificado $TMP/firma-$2.pfx produccion 'Clave QA' >/dev/null
  subir $1 $2 caf "$(caf $rut 39 1 200 300)" >/dev/null
  subir $1 $2 caf "$(caf $rut 41 1 200 300)" >/dev/null
  call $1 POST dte.php $2 centro '{"accion":"habilitacion","tipo":39,"estado":"produccion"}'
}
# habilitar JAR EMPRESA TIPO: lleva un tipo de la etapa 2 a producción (con visto bueno registrado) y carga su CAF de 1 a 100.
habilitar() {
  local rut paso; call $1 GET dte.php $2 centro >/dev/null; rut=$(py "d['rut']")
  for paso in en_certificacion "visto_bueno\",\"atencion\":\"QA$3\",\"fecha\":\"2026-11-10" produccion; do
    call $1 POST dte.php $2 centro "{\"accion\":\"habilitacion\",\"tipo\":$3,\"estado\":\"$paso\"}" >/dev/null
  done
  subir $1 $2 caf "$(caf $rut $3 1 100 300)" >/dev/null
}
