#!/bin/bash
# QA por API de los correos (Sprints 07, 06 y 04-B) contra el buzón y el SII simulados: verificación de destinatarios por
# enlace, avisos del trámite solo a verificados, documentos aceptados al receptor con el EnvioDTE de intercambio y el
# PDF, fallas temporales y definitivas del servidor de correo sin avisos duplicados, reintento controlado, entrega
# concurrente, trámite detenido una vez por semana y acceso.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo); FX2=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a)
T2=$(campo "$FX2" tag); A2=$(campo "$FX2" a)
J1=$(mktemp); J2=$(mktemp); JC=$(mktemp); TMP=$(mktemp -d)
docker compose -p myposlite stop scheduler >/dev/null 2>&1
dc() { MSYS_NO_PATHCONV=1 docker compose -p myposlite exec -T "$@"; }
buzon() { dc backend php -r "echo file_get_contents('http://correo-simulado:8092/$1');" > $OUT; }
limpiar() { buzon '_control?modo=normal'; sii normal; docker compose -p myposlite start scheduler >/dev/null 2>&1; fixture borrar $T1; fixture borrar $T2; rm -rf $J1 $J2 $JC $OUT $TMP; }
trap limpiar EXIT
source "$(dirname "$0")/qa-lib-dte.sh"
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
entregar() { dc backend php -r 'require "backend/config/database.php"; require "backend/src/Emision.php"; echo json_encode(entregarCorreosPendientes(conectarBaseLite(), 100));'; }
sql() { dc backend php -r 'require "backend/config/database.php"; $q = conectarBaseLite()->prepare($argv[1]); $q->execute(array_slice($argv, 2)); echo json_encode($q->fetchAll(PDO::FETCH_NUM));' "$@"; }
para() { buzon; py "len([m for m in d['mensajes'] if m['para'] == '$1'])"; }
# eml ID: texto del mensaje decodificado y sus adjuntos guardados en $TMP.
eml() { dc backend php -r "echo file_get_contents('http://correo-simulado:8092/?id=$1');" > $TMP/m.eml
  python -c "
import email, sys, os
m = email.message_from_bytes(open(r'$(ruta $TMP/m.eml)', 'rb').read())
for p in m.walk():
    if p.get_filename(): open(os.path.join(r'$(ruta $TMP)', p.get_filename()), 'wb').write(p.get_payload(decode=True))
    elif p.get_content_type() == 'text/plain': sys.stdout.write(p.get_payload(decode=True).decode('utf-8'))"; }
ultimo() { buzon; py "[m['id'] for m in d['mensajes'] if m['para'] == '$1'][-1]"; }
emitir() { call $J1 POST emision.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":33,\"receptorId\":\"$R1\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"1\"}]}" >/dev/null; py "d['id']"; }
aceptar() { call $J1 POST dte.php $A1 centro '{"accion":"enviar-boletas"}' >/dev/null
  for e in $(call $J1 GET dte.php $A1 centro >/dev/null; py "' '.join(x['id'] for x in d['ambientes']['produccion']['envios'] if x['estado'] == 'enviado')"); do
    call $J1 POST dte.php $A1 centro "{\"accion\":\"consultar\",\"envio\":\"$e\"}" >/dev/null; done; }

expect "ingreso administrador" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
expect "ingreso en otra empresa" 200 "$(entrar $J2 "$(campo "$FX2" correo)" "$(campo "$FX2" clave)" $A2 centro)"
sii normal; buzon '_control?modo=normal'; buzon _vaciar
ADMIN="duena-$T1@empresa.test"; OTRA="contador-$T1@empresa.test"; RECEPTOR="dte-$T1@mayorista.test"
REV=$(call $J1 GET destinatarios.php $A1 centro >/dev/null; py "d['revision']")
call $J1 PUT destinatarios.php $A1 centro "{\"principal\":\"$ADMIN\",\"adicionales\":[\"$OTRA\"],\"revision\":\"$REV\"}" >/dev/null

echo "--- Verificación de destinatarios"
expect "un correo que no es destinatario" 422 "$(call $J1 POST correos.php $A1 centro '{"accion":"verificar","correo":"otro@empresa.test"}')"
expect "pide la verificación de la dueña" 202 "$(call $J1 POST correos.php $A1 centro "{\"accion\":\"verificar\",\"correo\":\"$ADMIN\"}")"
expect "la entrega sale por SMTP" '"enviados":1' "$(entregar | grep -o '"enviados":1')"
TEXTO=$(eml "$(ultimo $ADMIN)")
ENLACE=$(echo "$TEXTO" | grep -o 'http://[^ ]*verificar-correo.php?token=[0-9a-f]*')
expect "el correo trae un enlace de verificación" True "$([ -n "$ENLACE" ] && echo True || echo False)"
expect "el enlace confirma el correo" 200 "$(curl -s -o $TMP/v.html -w '%{http_code}' "$B/verificar-correo.php?${ENLACE#*\?}")"
expect "con un mensaje claro" 1 "$(grep -c 'Correo verificado' $TMP/v.html)"
expect "el enlace no sirve dos veces" 404 "$(curl -s -o /dev/null -w '%{http_code}' "$B/verificar-correo.php?${ENLACE#*\?}")"
expect "un enlace inventado" 400 "$(curl -s -o /dev/null -w '%{http_code}' "$B/verificar-correo.php?token=abc")"
call $J1 GET correos.php $A1 centro >/dev/null
expect "la dueña queda verificada y la otra no" "True False" "$(py "next(x['verificado_en'] is not None for x in d['destinatarios'] if x['correo'] == '$ADMIN')") $(py "next(x['verificado_en'] is not None for x in d['destinatarios'] if x['correo'] == '$OTRA')")"
expect "verificar de nuevo un correo verificado" 409 "$(call $J1 POST correos.php $A1 centro "{\"accion\":\"verificar\",\"correo\":\"$ADMIN\"}")"

echo "--- Avisos del trámite"
expect "boletas en producción, con el set y el RCOF respondidos" 200 "$(produccion $J1 $A1 11111111-1)"
entregar >/dev/null
expect "la dueña recibe las dos respuestas del SII" 2 "$(buzon; py "len([m for m in d['mensajes'] if m['para'] == '$ADMIN' and m['asunto'].startswith('El SII respondió')])")"
expect "la no verificada no recibe nada todavía" 0 "$(para $OTRA)"
expect "sus avisos esperan en la cola" '[[2]]' "$(sql "SELECT COUNT(*) FROM correo_envios WHERE empresa_id = ? AND destinatario = ? AND tipo = 'tramite' AND estado = 'pendiente'" $A1 $OTRA)"
for i in 1 2 3; do call $J1 POST correos.php $A1 centro "{\"accion\":\"verificar\",\"correo\":\"$OTRA\"}" >/dev/null; done
expect "una cuarta verificación en la misma hora" 409 "$(call $J1 POST correos.php $A1 centro "{\"accion\":\"verificar\",\"correo\":\"$OTRA\"}")"
entregar >/dev/null
ENLACE=$(eml "$(ultimo $OTRA)" | grep -o 'http://[^ ]*token=[0-9a-f]*')
curl -s -o /dev/null "$B/verificar-correo.php?${ENLACE#*\?}"
entregar >/dev/null
expect "al verificarse recibe los avisos que esperaban, sin duplicados" 2 "$(buzon; py "len([m for m in d['mensajes'] if m['para'] == '$OTRA' and m['asunto'].startswith('El SII respondió')])")"
SET=$(call $J1 GET dte.php $A1 centro >/dev/null; py "[e['id'] for e in d['ambientes']['certificacion']['envios'] if e['motivo'] == 'set_boletas'][0]")
call $J1 POST dte.php $A1 centro "{\"accion\":\"consultar\",\"envio\":\"$SET\"}" >/dev/null
dc backend php -r 'require "backend/config/database.php"; require "backend/src/Emision.php"; avisarRespuestaTramiteCorreo(conectarBaseLite(), $argv[1], $argv[2], "set_boletas", "aceptado", null);' $A1 $SET
entregar >/dev/null
expect "consultar otra vez o repetir el aviso no duplica" 2 "$(buzon; py "len([m for m in d['mensajes'] if m['para'] == '$ADMIN' and m['asunto'].startswith('El SII respondió')])")"

echo "--- Documentos al receptor"
habilitar $J1 $A1 33
call $J1 POST contactos.php $A1 centro "{\"rut\":\"96.511.760-1\",\"razonSocial\":\"Mayorista QA\",\"correo\":\"$RECEPTOR\",\"proveedor\":false,\"receptor\":true,\"giro\":\"Comercio\",\"direccion\":\"Los Aromos 45\",\"comuna\":\"Maipú\"}" >/dev/null; R1=$(py "d['id']")
call $J1 POST productos.php $A1 centro "$(prod QA-BEB 'Bebida QA' "$(pres Unidad '' 1 1190)")" >/dev/null
UNI=$(call $J1 GET productos.php $A1 centro >/dev/null; py "d['productos'][0]['presentaciones'][0]['id']")
F1=$(emitir)
expect "antes de la aceptación del SII no se envía al receptor" '[[0]]' "$(sql "SELECT COUNT(*) FROM correo_envios WHERE empresa_id = ? AND tipo = 'documento'" $A1)"
aceptar; entregar >/dev/null
expect "aceptada, la factura va al receptor" 1 "$(para $RECEPTOR)"
TEXTO=$(eml "$(ultimo $RECEPTOR)")
expect "con el XML de intercambio y el PDF" "True True" "$([ -s $TMP/DTE-33-1.xml ] && echo True || echo False) $([ -s $TMP/DTE-33-1.pdf ] && echo True || echo False)"
expect "el sobre va dirigido al RUT del receptor" 1 "$(grep -c '<RutReceptor>96511760-1</RutReceptor>' $TMP/DTE-33-1.xml)"
expect "y pasa EnvioDTE_v10 con su firma" "[]" "$(dc backend php -r 'require "backend/src/Emision.php"; echo json_encode(MyposLite\Dte\ValidadorEsquema::validarEnvioDte(stream_get_contents(STDIN)));' < $TMP/DTE-33-1.xml)"
expect "el PDF es la factura" "%PDF-" "$(head -c 5 $TMP/DTE-33-1.pdf)"

echo "--- Fallas del servidor de correo"
buzon '_control?modo=caido'
F2=$(emitir); aceptar
expect "servidor caído: queda pendiente para reintentar" '"reintentos":1' "$(entregar | grep -o '"reintentos":1')"
expect "con el error registrado" '[["pendiente",1]]' "$(sql "SELECT estado, intentos FROM correo_envios WHERE empresa_id = ? AND documento_id = ?" $A1 $F2)"
buzon '_control?modo=normal'
sql "UPDATE correo_envios SET proximo_intento = UTC_TIMESTAMP(6) WHERE empresa_id = ? AND documento_id = ?" $A1 $F2 >/dev/null
entregar >/dev/null
expect "al volver, sale una sola vez" 2 "$(para $RECEPTOR)"
buzon '_control?modo=rechazar'
F3=$(emitir); aceptar
expect "rechazo definitivo: queda fallido" '"fallidos":1' "$(entregar | grep -o '"fallidos":1')"
C3=$(sql "SELECT id FROM correo_envios WHERE empresa_id = ? AND documento_id = ?" $A1 $F3 | tr -dc '0-9')
buzon '_control?modo=normal'
expect "un enviado no se reintenta" 409 "$(call $J1 POST correos.php $A1 centro "{\"accion\":\"reintentar\",\"id\":\"$(sql "SELECT id FROM correo_envios WHERE empresa_id = ? AND documento_id = ?" $A1 $F1 | tr -dc '0-9')\"}")"
expect "el fallido se reintenta desde el panel" 200 "$(call $J1 POST correos.php $A1 centro "{\"accion\":\"reintentar\",\"id\":\"$C3\"}")"
entregar >/dev/null
expect "y sale una vez" 3 "$(para $RECEPTOR)"
F4=$(emitir); aceptar
( entregar > $TMP/e1 & entregar > $TMP/e2 & wait )
expect "dos entregas simultáneas envían una sola vez" 4 "$(para $RECEPTOR)"

echo "--- Trámite detenido"
call $J1 POST dte.php $A1 centro '{"accion":"habilitacion","tipo":52,"estado":"en_certificacion"}' >/dev/null
sql "UPDATE dte_habilitaciones SET actualizado_en = UTC_TIMESTAMP(6) - INTERVAL 6 DAY WHERE empresa_id = ?" $A1 >/dev/null
sql "UPDATE dte_envios SET actualizado_en = UTC_TIMESTAMP(6) - INTERVAL 6 DAY WHERE empresa_id = ? AND ambiente = 'certificacion'" $A1 >/dev/null
dc backend php -r 'require "backend/config/database.php"; require "backend/src/Emision.php"; avisarTramitesDetenidosCorreo(conectarBaseLite()); avisarTramitesDetenidosCorreo(conectarBaseLite());'
entregar >/dev/null
expect "seis días sin movimiento: un aviso por destinataria aunque se revise dos veces" "1 1" "$(buzon; py "len([m for m in d['mensajes'] if m['para'] == '$ADMIN' and 'detenido' in m['asunto']])") $(py "len([m for m in d['mensajes'] if m['para'] == '$OTRA' and 'detenido' in m['asunto']])")"

echo "--- Acceso"
call $J1 GET correos.php $A1 centro >/dev/null
expect "el historial lista lo enviado sin el cuerpo" "True False" "$(py "len(d['envios']) >= 10") $(py "any('cuerpo' in e for e in d['envios'])")"
expect "otra empresa no ve estos correos" "[]" "$(call $J2 GET correos.php $A2 centro >/dev/null; py "d['envios']")"
expect "ni reintenta los de otra empresa" 409 "$(call $J2 POST correos.php $A2 centro "{\"accion\":\"reintentar\",\"id\":\"$C3\"}")"
VC="qa-created-$T1@example.invalid"; VP="Qa$(head -c 12 /dev/urandom | base64 | tr -d '/+=')9x"
call $J1 POST usuarios.php $A1 centro "{\"correo\":\"$VC\",\"nombre\":\"Contadora QA\",\"clave\":\"$VP\",\"rol\":\"contador\",\"locales\":[\"centro\"],\"cajas\":[]}" >/dev/null
entrar $JC "$VC" "$VP" $A1 centro >/dev/null
expect "la contadora consulta el historial" 200 "$(call $JC GET correos.php $A1 centro)"
expect "pero no pide verificaciones" 403 "$(call $JC POST correos.php $A1 centro "{\"accion\":\"verificar\",\"correo\":\"$ADMIN\"}")"
resultado
