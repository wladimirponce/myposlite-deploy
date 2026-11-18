#!/bin/bash
# QA por API del envío al SII (Sprint 04-A, segundo corte) contra el SII simulado del perfil simulador:
# token con semilla firmada, UPLOAD del set, track ID, consulta de estado, SII caído, respuesta perdida, envíos
# simultáneos, rechazo, empresa de demostración y permisos. En todos los casos se comprueba que se reenvían los mismos
# bytes y que no se consume ningún folio nuevo. Requiere MYPOSLITE_SII_BASE=http://sii-simulado:8091.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo); FX2=$(fixture crear-catalogo); FX3=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a); B1=$(campo "$FX1" b)
T2=$(campo "$FX2" tag); A2=$(campo "$FX2" a)
T3=$(campo "$FX3" tag); A3=$(campo "$FX3" a)
J1=$(mktemp); J2=$(mktemp); J3=$(mktemp); TMP=$(mktemp -d)
trap 'sii normal; fixture borrar $T1; fixture borrar $T2; fixture borrar $T3; rm -rf $J1 $J2 $J3 $OUT $TMP' EXIT
source "$(dirname "$0")/qa-lib-dte.sh"
accion() { call $1 POST dte.php $2 centro "{\"accion\":\"$3\"${4:+,\"envio\":\"$4\"}}"; }
# recibidos HUELLA: cuántos sobres con esa huella recibió el SII simulado.
# Sin envíos, el simulador entrega la lista vacía como [] y no como objeto.
recibidos() { sii; py "sum(1 for e in (d['envios'] or {}).values() if e['huella'] == '$1')"; }
huella() { curl -s -b $1 "$B/dte.php?envio=$3" -H "X-MyPOSLite-Empresa: $2" -H 'X-MyPOSLite-Local: centro' | sha256sum | cut -d' ' -f1; }
folios() { call $1 GET dte.php $2 centro >/dev/null; py "[c['disponibles'] for c in d['ambientes']['certificacion']['cafs']]"; }
envio() { call $1 GET dte.php $2 centro >/dev/null; py "[(e['estado'], e['intentos']) for e in d['ambientes']['certificacion']['envios']][0]"; }

for n in 1 2 3; do
  FX=FX$n; J=J$n; A=A$n
  expect "ingreso administrador $n" 200 "$(entrar ${!J} "$(campo "${!FX}" correo)" "$(campo "${!FX}" clave)" ${!A} centro)"
done
sii normal >/dev/null
preparado $J1 $A1 11111111-1; preparado $J2 $A2 22222222-2; preparado $J3 $A3 33333333-3
expect "set de la empresa 1" 201 "$(accion $J1 $A1 preparar-set)"; E1=$(py "d['envio']")
expect "set de la empresa 2" 201 "$(accion $J2 $A2 preparar-set)"; E2=$(py "d['envio']")
expect "set de la empresa 3" 201 "$(accion $J3 $A3 preparar-set)"; E3=$(py "d['envio']")
H1=$(huella $J1 $A1 $E1); H2=$(huella $J2 $A2 $E2); H3=$(huella $J3 $A3 $E3)

echo "--- SII caído"
expect "consultar sin track ID" 409 "$(accion $J1 $A1 consultar $E1)"
sii caido
expect "enviar con el SII caído responde" 200 "$(accion $J1 $A1 enviar $E1)"
expect "queda pendiente y sin track ID" "pendiente None" "$(py "d['estado'], d['trackId']" | tr -d "(),'")"
sii normal
expect "el SII no recibió nada" 0 "$(recibidos $H1)"

echo "--- Envío y consulta"
expect "reintento con el SII arriba" 200 "$(accion $J1 $A1 enviar $E1)"
expect "queda enviado con track ID" "enviado True" "$(py "d['estado'], d['trackId'] is not None" | tr -d "(),'")"
TRACK=$(py "d['trackId']")
expect "el SII recibió el sobre una vez, con sus mismos bytes" 1 "$(recibidos $H1)"
expect "con el titular del certificado y el RUT de la empresa" "11111111-1 True" \
  "$(py "[(e['rut_sender'], e['rut_company'] != '-') for e in d['envios'].values() if e['huella'] == '$H1'][0]" | tr -d "(),'")"
expect "el SII lo encontró válido en esquema y firmas" "[]" "$(py "[e['errores'] for e in d['envios'].values() if e['huella'] == '$H1'][0]")"
expect "enviar otra vez no reenvía" 200 "$(accion $J1 $A1 enviar $E1)"
expect "devuelve el mismo track ID" "True $TRACK" "$(py "d['repetido'], d['trackId']" | tr -d "(),'")"
expect "y el SII sigue con una recepción" 1 "$(recibidos $H1)"
expect "consulta el estado" 200 "$(accion $J1 $A1 consultar $E1)"
expect "aceptado con la glosa del SII" "aceptado Envio Procesado" "$(py "d['estado'], d['glosa']" | tr -d "(),'")"
expect "dos intentos de envío registrados" "('aceptado', 2)" "$(envio $J1 $A1)"
expect "la puesta en marcha marca la certificación como lista" True "$(call $J1 GET puesta-en-marcha.php $A1 centro >/dev/null; py "[p['hecho'] for p in d['pasos'] if p['codigo'] == 'certificacion'][0]")"
expect "sin folios nuevos" "[0]" "$(folios $J1 $A1)"

echo "--- RCOF del set"
expect "prepara el RCOF del set" 201 "$(accion $J1 $A1 preparar-rcof)"
expect "montos del RCOF aceptado de Libertad y folios 1 a 5" "43831 8329 2000 54160 [1, 2, 3, 4, 5]" "$(py "d['neto'], d['iva'], d['exento'], d['total'], d['folios']" | tr -d "(),'" | sed 's/\[1 2 3 4 5\]/[1, 2, 3, 4, 5]/')"
R1=$(py "d['envio']")
expect "repetirlo devuelve el mismo" "200 $R1" "$(accion $J1 $A1 preparar-rcof) $(py "d['envio']")"
HR=$(huella $J1 $A1 $R1)
expect "envía el RCOF" 200 "$(accion $J1 $A1 enviar $R1)"
expect "recibido con track ID" "enviado True" "$(py "d['estado'], d['trackId'] is not None" | tr -d "(),'")"
expect "el SII lo validó contra ConsumoFolio_v10 y su firma" "[] 5" "$(sii; py "[(e['errores'], e['informados']) for e in d['envios'].values() if e['huella'] == '$HR'][0]" | tr -d "(),'" )"
expect "consulta el RCOF" aceptado "$(accion $J1 $A1 consultar $R1 >/dev/null; py "d['estado']")"

echo "--- Habilitación por tipo"
hab() { call $1 POST dte.php $2 centro "{\"accion\":\"habilitacion\",\"tipo\":$3,\"estado\":\"$4\"${5:+,$5}}"; }
habilitado() { call $1 GET dte.php $2 centro >/dev/null; py "[(h['tipo'], h['estado']) for h in d['habilitaciones'] if h['tipo'] in (39, 41, 33)]"; }
expect "todo sin iniciar" "[(39, 'sin_iniciar'), (41, 'sin_iniciar'), (33, 'sin_iniciar')]" "$(habilitado $J1 $A1)"
expect "no se salta al visto bueno" 409 "$(hab $J1 $A1 39 visto_bueno)"
expect "inicia la certificación de boletas" 200 "$(hab $J1 $A1 39 en_certificacion)"
expect "39 y 41 avanzan juntas" "[39, 41]" "$(py "d['tipos']")"
expect "visto bueno sin número de atención" 422 "$(hab $J1 $A1 39 visto_bueno)"
expect "visto bueno con la atención y la fecha del correo del SII" 200 "$(hab $J1 $A1 39 visto_bueno '"atencion":"5171530","fecha":"2026-11-06"')"
expect "producción sin emisor de producción" 409 "$(hab $J1 $A1 39 produccion)"
emisor $J1 $A1 produccion 80 >/dev/null
expect "producción sin certificado de producción" 409 "$(hab $J1 $A1 39 produccion)"
certificado 11111111-1 'Clave QA' $TMP/prod.pfx
subir $J1 $A1 certificado $TMP/prod.pfx produccion 'Clave QA' >/dev/null
expect "boletas en producción" 200 "$(hab $J1 $A1 39 produccion)"
expect "39 y 41 en producción, facturas sin iniciar" "[(39, 'produccion'), (41, 'produccion'), (33, 'sin_iniciar')]" "$(habilitado $J1 $A1)"
expect "la atención queda registrada" "5171530 2026-11-06" "$(py "[(h['atencion'], h['vistoBuenoEn']) for h in d['habilitaciones'] if h['tipo'] == 39][0]" | tr -d "(),'")"
expect "facturas pueden iniciar y volver atrás" "200 200" "$(hab $J1 $A1 33 en_certificacion) $(hab $J1 $A1 33 sin_iniciar)"
expect "producción no se retrocede" 409 "$(hab $J1 $A1 39 visto_bueno '"atencion":"1","fecha":"2026-11-06"')"

echo "--- Respuesta perdida"
sii perder
expect "el SII recibe pero la respuesta se pierde" 200 "$(accion $J2 $A2 enviar $E2)"
expect "queda pendiente" pendiente "$(py "d['estado']")"
expect "el SII sí lo recibió" 1 "$(recibidos $H2)"
expect "reintento" 200 "$(accion $J2 $A2 enviar $E2)"
expect "queda enviado" enviado "$(py "d['estado']")"
expect "el SII recibió los mismos bytes dos veces: ningún documento nuevo" 2 "$(recibidos $H2)"
expect "el sobre guardado no cambió" "$H2" "$(huella $J2 $A2 $E2)"
expect "sin folios nuevos" "[0]" "$(folios $J2 $A2)"

echo "--- Envíos simultáneos y rechazo"
sii rechazar
for i in 1 2 3 4; do accion $J3 $A3 enviar $E3 > $TMP/simultaneo-$i.txt & done; wait
expect "cuatro envíos simultáneos llegan una sola vez al SII" 1 "$(recibidos $H3)"
expect "consulta el rechazo" 200 "$(accion $J3 $A3 consultar $E3)"
expect "queda rechazado" rechazado "$(py "d['estado']")"
expect "un set rechazado no completa el paso" False "$(call $J3 GET puesta-en-marcha.php $A3 centro >/dev/null; py "[p['hecho'] for p in d['pasos'] if p['codigo'] == 'certificacion'][0]")"
expect "un solo intento de envío" "('rechazado', 1)" "$(envio $J3 $A3)"
expect "con el set rechazado no hay visto bueno" "200 409" "$(call $J3 POST dte.php $A3 centro '{"accion":"habilitacion","tipo":39,"estado":"en_certificacion"}') $(call $J3 POST dte.php $A3 centro '{"accion":"habilitacion","tipo":39,"estado":"visto_bueno","atencion":"1","fecha":"2026-11-06"}')"

echo "--- Demostración y permisos"
docker compose -p myposlite exec -T database sh -c "mariadb -u myposlite -p\"\$MARIADB_PASSWORD\" myposlite -e 'UPDATE empresas SET es_demo = TRUE WHERE id = $A2'"
expect "la empresa de demostración no envía al SII" 409 "$(accion $J2 $A2 enviar $E2)"
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$B1\",\"localCodigo\":\"sur\"}" >/dev/null
expect "el contador no envía" 403 "$(curl -s -o $OUT -w '%{http_code}' -b $J1 -X POST "$B/dte.php" -H 'Content-Type: application/json' -H 'X-MyPOSLite-Request: 1' -H "X-MyPOSLite-Empresa: $B1" -H 'X-MyPOSLite-Local: sur' --data-binary "{\"accion\":\"enviar\",\"envio\":\"$E1\"}")"
expect "otra empresa no envía el sobre ajeno" 404 "$(accion $J3 $A3 enviar $E1)"

resultado
