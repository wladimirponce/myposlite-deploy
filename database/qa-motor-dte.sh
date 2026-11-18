#!/bin/bash
# QA por API del motor DTE (Sprint 04-A, primer corte, sin SII): emisor por ambiente, certificado y CAF cifrados,
# rechazo de CAF ajenos, dañados, repetidos o solapados, set de certificación de boletas con folios, timbres, firmas
# y esquema verificados, repetición sin consumir folios, reversión sin folios, aislamiento y permisos.
# Usa certificado y CAF ficticios de database/dte-ficticio.php: nada de esto se puede enviar al SII.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo); FX2=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a); B1=$(campo "$FX1" b)
T2=$(campo "$FX2" tag); A2=$(campo "$FX2" a)
J1=$(mktemp); J2=$(mktemp); JV=$(mktemp); TMP=$(mktemp -d)
trap 'fixture borrar $T1; fixture borrar $T2; rm -rf $J1 $J2 $JV $OUT $TMP' EXIT
source "$(dirname "$0")/qa-lib-dte.sh"
estado() { call $J1 GET dte.php $A1 centro >/dev/null; py "$1"; }

expect "ingreso administrador 1" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
expect "ingreso administrador 2" 200 "$(entrar $J2 "$(campo "$FX2" correo)" "$(campo "$FX2" clave)" $A2 centro)"
expect "estado inicial del motor" "True None []" "$(estado "d['configurado'], d['ambientes']['certificacion']['emisor'], d['ambientes']['certificacion']['cafs']")"
RUT=$(estado "d['rut']"); RUT2=$(call $J2 GET dte.php $A2 centro >/dev/null; py "d['rut']")

echo "--- Emisor y certificado"
expect "preparar el set sin emisor" 409 "$(call $J1 POST dte.php $A1 centro '{"accion":"preparar-set"}')"
expect "certificación exige resolución 0" 422 "$(emisor $J1 $A1 certificacion 80)"
expect "emisor de certificación con la fecha del timbraje" 200 "$(emisor $J1 $A1 certificacion 0)"
expect "emisor de producción" 200 "$(emisor $J1 $A1 produccion 80)"
expect "preparar el set sin certificado" 409 "$(call $J1 POST dte.php $A1 centro '{"accion":"preparar-set"}')"
certificado 11111111-1 'Clave QA 1' $TMP/firma.pfx
expect "certificado con contraseña equivocada" 422 "$(subir $J1 $A1 certificado $TMP/firma.pfx certificacion 'otra')"
expect "certificado con ambiente inválido" 422 "$(subir $J1 $A1 certificado $TMP/firma.pfx pruebas 'Clave QA 1')"
expect "certificado de certificación" 201 "$(subir $J1 $A1 certificado $TMP/firma.pfx certificacion 'Clave QA 1')"
expect "identifica al titular" "11111111-1" "$(py "d['rutTitular']")"

echo "--- CAF"
expect "CAF de otro RUT" 422 "$(subir $J1 $A1 caf "$(caf 96511760-1 39 1 5 100)")"
expect "CAF con la clave de otro rango" 422 "$(subir $J1 $A1 caf "$(caf $RUT 39 1 5 100 danado)")"
expect "CAF de un tipo que Lite no emite" 422 "$(subir $J1 $A1 caf "$(caf $RUT 46 1 5 100)")"
expect "archivo que no es CAF" 422 "$(subir $J1 $A1 caf $TMP/firma.pfx)"
caf $RUT 39 1 5 100 >/dev/null; cp $TMP/caf.xml $TMP/caf-1-5.xml
expect "CAF de boletas 1-5 en certificación" 201 "$(subir $J1 $A1 caf $TMP/caf-1-5.xml)"
expect "reconoce tipo, ambiente y rango" "39 certificacion 1 5" "$(py "d['tipo'], d['ambiente'], d['desde'], d['hasta']" | tr -d "(),'")"
expect "el mismo CAF otra vez" 409 "$(subir $J1 $A1 caf $TMP/caf-1-5.xml)"
expect "un CAF que se solapa con 1-5" 409 "$(subir $J1 $A1 caf "$(caf $RUT 39 4 9 100)")"
expect "el mismo rango en producción no se solapa" 201 "$(subir $J1 $A1 caf "$(caf $RUT 39 1 5 300)")"
expect "y queda como producción" produccion "$(py "d['ambiente']")"
call $J1 GET dte.php $A1 centro >/dev/null
expect "el estado no expone claves, certificados ni contraseñas" "False" "$(py "any(x in open(sys.argv[1], encoding='utf-8').read() for x in ('RSASK', 'BEGIN', 'Clave QA', 'pfx'))")"
expect "5 folios disponibles en certificación" "[(39, 5)]" "$(estado "[(c['tipo'], c['disponibles']) for c in d['ambientes']['certificacion']['cafs']]")"

echo "--- Set de certificación de boletas"
expect "prepara el set" 201 "$(call $J1 POST dte.php $A1 centro '{"accion":"preparar-set"}')"
expect "cinco casos con folios 1 a 5 y sus totales" "[(1, 1, 29800), (2, 2, 2040), (3, 3, 4100), (4, 4, 14720), (5, 5, 3500)]" \
  "$(py "[(x['caso'], x['folio'], x['total']) for x in d['documentos']]")"
ENVIO=$(py "d['envio']")
expect "consumió los 5 folios" "[(39, 0)]" "$(estado "[(c['tipo'], c['disponibles']) for c in d['ambientes']['certificacion']['cafs']]")"
expect "repetir devuelve el mismo set" 200 "$(call $J1 POST dte.php $A1 centro '{"accion":"preparar-set"}')"
expect "con los mismos folios" "[1, 2, 3, 4, 5]" "$(py "[x['folio'] for x in d['documentos']]")"
expect "descarga el sobre" 200 "$(curl -s -o $TMP/sobre.xml -w '%{http_code}' -b $J1 "$B/dte.php?envio=$ENVIO" -H "X-MyPOSLite-Empresa: $A1" -H 'X-MyPOSLite-Local: centro')"
expect "esquema, seis firmas y cinco timbres válidos" '{"errores":[],"timbres":5}' "$(docker compose -p myposlite exec -T backend php database/dte-ficticio.php validar < $TMP/sobre.xml)"
expect "carátula: titular, resolución 0 y fecha del timbraje" "11111111-1 0 2026-11-05 5" \
  "$(python -c "import re,sys; s=open(sys.argv[1],encoding='latin-1').read(); print(*[re.search(r'<%s>([^<]*)</'%t, s).group(1) for t in ('RutEnvia','NroResol','FchResol','NroDTE')])" $TMP/sobre.xml)"
expect "la comuna con Ñ viaja en ISO-8859-1" True "$(python -c "import sys; print('Ñuñoa'.encode('latin-1') in open(sys.argv[1],'rb').read())" $TMP/sobre.xml)"
expect "el caso 4 marca la línea exenta y su total" True "$(python -c "import sys; s=open(sys.argv[1],encoding='latin-1').read(); print('<IndExe>1</IndExe><NmbItem>item exento 2</NmbItem>' in s and '<MntNeto>10689</MntNeto><MntExe>2000</MntExe><IVA>2031</IVA><MntTotal>14720</MntTotal>' in s)" $TMP/sobre.xml)"

echo "--- Sin folios suficientes no se consume nada"
expect "emisor de la empresa 2" 200 "$(emisor $J2 $A2 certificacion 0)"
certificado 22222222-2 'Clave QA 2' $TMP/firma2.pfx
expect "certificado de la empresa 2" 201 "$(subir $J2 $A2 certificado $TMP/firma2.pfx certificacion 'Clave QA 2')"
expect "CAF de solo 4 folios" 201 "$(subir $J2 $A2 caf "$(caf $RUT2 39 10 13 100)")"
expect "el set no alcanza" 409 "$(call $J2 POST dte.php $A2 centro '{"accion":"preparar-set"}')"
expect "sin set no hay RCOF" 409 "$(call $J2 POST dte.php $A2 centro '{"accion":"preparar-rcof"}')"
expect "y los 4 folios siguen disponibles" "[4]" "$(call $J2 GET dte.php $A2 centro >/dev/null; py "[c['disponibles'] for c in d['ambientes']['certificacion']['cafs']]")"
expect "la empresa 2 no puede cargar el CAF de la empresa 1" 422 "$(subir $J2 $A2 caf $TMP/caf-1-5.xml)"

echo "--- Aislamiento y permisos"
expect "otra empresa no descarga el sobre" 404 "$(curl -s -o $OUT -w '%{http_code}' -b $J2 "$B/dte.php?envio=$ENVIO" -H "X-MyPOSLite-Empresa: $A2" -H 'X-MyPOSLite-Local: centro')"
VC="qa-created-$T1@example.invalid"; VP="Qa$(head -c 12 /dev/urandom | base64 | tr -d '/+=')9x"
call $J1 POST usuarios.php $A1 centro "{\"correo\":\"$VC\",\"nombre\":\"Vendedor QA\",\"clave\":\"$VP\",\"rol\":\"vendedor\",\"locales\":[\"centro\"],\"cajas\":[]}" >/dev/null
entrar $JV "$VC" "$VP" $A1 centro >/dev/null
expect "vendedor no consulta el motor" 403 "$(call $JV GET dte.php $A1 centro)"
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$B1\",\"localCodigo\":\"sur\"}" >/dev/null
expect "contador consulta" 200 "$(call $J1 GET dte.php $B1 sur)"
expect "contador no configura" 403 "$(curl -s -o $OUT -w '%{http_code}' -b $J1 -X POST "$B/dte.php" -H 'Content-Type: application/json' -H 'X-MyPOSLite-Request: 1' -H "X-MyPOSLite-Empresa: $B1" -H 'X-MyPOSLite-Local: sur' --data-binary '{"accion":"preparar-set"}')"

resultado
