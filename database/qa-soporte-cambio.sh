#!/bin/bash
# QA por API del alta por cambio de proveedor y de la ayuda a soporte (Sprint 04-B): tipos ya autorizados que pasan a
# producción sin certificar, rangos del sistema anterior que Lite no carga, expediente hasta la primera emisión
# aceptada; solicitud de ayuda con el expediente sin secretos, bandeja y respuesta de soporte, y acceso.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo); FX2=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a)
T2=$(campo "$FX2" tag); A2=$(campo "$FX2" a)
J1=$(mktemp); J2=$(mktemp); JC=$(mktemp); JS=$(mktemp); TMP=$(mktemp -d)
docker compose -p myposlite stop scheduler >/dev/null 2>&1
limpiar() { sii normal; docker compose -p myposlite start scheduler >/dev/null 2>&1; fixture borrar $T1; fixture borrar $T2; rm -rf $J1 $J2 $JC $JS $OUT $TMP; }
trap limpiar EXIT
source "$(dirname "$0")/qa-lib-dte.sh"
entregar() { MSYS_NO_PATHCONV=1 docker compose -p myposlite exec -T backend php -r 'require "backend/config/database.php"; require "backend/src/Emision.php"; entregarCorreosPendientes(conectarBaseLite(), 100);'; }
buzon() { MSYS_NO_PATHCONV=1 docker compose -p myposlite exec -T backend php -r "echo file_get_contents('http://correo-simulado:8092/');" > $OUT; }
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
HOY=$(date +%F)
cambio() { call $J1 POST dte.php $A1 centro "{\"accion\":\"cambio-proveedor\",\"proveedorAnterior\":\"${2-Facturador Anterior SpA}\",\"tipos\":$1,\"fechaCorte\":\"$HOY\"${3:+,$3}}"; }
paso() { call $J1 GET dte.php $A1 centro >/dev/null; py "[p['hecho'] for p in d['cambioProveedor']['pasos'] if p['clave'] == '$1'][0]"; }

expect "ingreso administrador" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
expect "ingreso en otra empresa" 200 "$(entrar $J2 "$(campo "$FX2" correo)" "$(campo "$FX2" clave)" $A2 centro)"
sii normal
RUT=$(call $J1 GET dte.php $A1 centro >/dev/null; py "d['rut']")
expect "sin cambio de proveedor el expediente no existe" None "$(py "d['cambioProveedor']")"

echo "--- Cambio de proveedor"
expect "sin emisor de producción" 409 "$(cambio '[33]')"
emisor $J1 $A1 produccion 80 >/dev/null
expect "sin certificado de producción" 409 "$(cambio '[33]')"
certificado 11111111-1 'Clave QA' $TMP/firma.pfx 365
subir $J1 $A1 certificado $TMP/firma.pfx produccion 'Clave QA' >/dev/null
expect "sin nombre del proveedor anterior" 422 "$(cambio '[33]' '')"
expect "tipo que Lite no emite" 422 "$(cambio '[33,46]')"
expect "rango de un tipo no seleccionado" 422 "$(cambio '[33]' 'Otro' '"rangos":[{"tipo":61,"desde":1,"hasta":10}]')"
expect "rangos que se solapan" 422 "$(cambio '[33]' 'Otro' '"rangos":[{"tipo":33,"desde":1,"hasta":10},{"tipo":33,"desde":5,"hasta":20}]')"
expect "registra facturas y boletas desde el proveedor anterior" 201 "$(cambio '[33,39,41]' 'Facturador Anterior SpA' '"pendientes":"Notas de crédito de octubre","rangos":[{"tipo":33,"desde":1,"hasta":500}]')"
call $J1 GET dte.php $A1 centro >/dev/null
expect "los tres tipos quedan en producción sin certificar" "['produccion', 'produccion', 'produccion']" "$(py "[h['estado'] for h in d['habilitaciones'] if h['tipo'] in (33, 39, 41)]")"
expect "la emisión de facturas queda habilitada" True "$(call $J1 GET emision.php $A1 centro >/dev/null; py "d['habilitados']['33']")"
expect "registrarlo dos veces" 409 "$(cambio '[56]')"
expect "expediente: registro y certificado hechos, CAF y primera emisión pendientes" "True True False False" "$(paso registro) $(paso certificado) $(paso caf) $(paso primera)"
expect "la fecha de corte de hoy ya rige" True "$(paso corte)"
expect "el rango del proveedor anterior queda registrado" "[(33, 1, 500, 'Facturador Anterior SpA')]" "$(py "[(r['tipo'], r['desde'], r['hasta'], r['sistema']) for r in d['cambioProveedor']['rangos']]")"

echo "--- Rangos disjuntos"
expect "un CAF que toca los folios del proveedor anterior" 409 "$(subir $J1 $A1 caf "$(caf $RUT 33 450 600 300)")"
expect "un CAF propio a continuación" 201 "$(subir $J1 $A1 caf "$(caf $RUT 33 501 600 300)")"
expect "en certificación el rango no se restringe" 201 "$(subir $J1 $A1 caf "$(caf $RUT 33 1 100 100)")"
subir $J1 $A1 caf "$(caf $RUT 39 1 100 300)" >/dev/null; subir $J1 $A1 caf "$(caf $RUT 41 1 100 300)" >/dev/null
expect "con CAF de los tres tipos el paso queda hecho" True "$(paso caf)"

echo "--- Primera emisión real"
call $J1 POST contactos.php $A1 centro '{"rut":"96.511.760-1","razonSocial":"Mayorista QA","correo":"","proveedor":false,"receptor":true,"giro":"Comercio","direccion":"Los Aromos 45","comuna":"Maipú"}' >/dev/null; R1=$(py "d['id']")
call $J1 POST productos.php $A1 centro "$(prod QA-BEB 'Bebida QA' "$(pres Unidad '' 1 1190)")" >/dev/null
UNI=$(call $J1 GET productos.php $A1 centro >/dev/null; py "d['productos'][0]['presentaciones'][0]['id']")
expect "la primera factura usa el folio 501, no los del proveedor anterior" "201 501" "$(call $J1 POST emision.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":33,\"receptorId\":\"$R1\",\"lineas\":[{\"presentacionId\":\"$UNI\",\"cantidad\":\"1\"}]}") $(py "d['folio']")"
call $J1 POST dte.php $A1 centro '{"accion":"enviar-boletas"}' >/dev/null
E=$(call $J1 GET dte.php $A1 centro >/dev/null; py "d['ambientes']['produccion']['envios'][0]['id']")
call $J1 POST dte.php $A1 centro "{\"accion\":\"consultar\",\"envio\":\"$E\"}" >/dev/null
expect "aceptada por el SII, el expediente queda completo" "True True True True True" "$(paso registro) $(paso certificado) $(paso caf) $(paso corte) $(paso primera)"

echo "--- Ayuda a soporte"
ayuda() { call $1 POST soporte.php $2 centro "{\"accion\":\"pedir\",\"tramite\":\"$3\",\"paso\":\"Cargar CAF\",\"mensaje\":\"No sé dónde pedir el CAF\"}"; }
expect "trámite inválido" 422 "$(ayuda $J1 $A1 otro)"
expect "pedir ayuda desde el cambio de proveedor" 201 "$(ayuda $J1 $A1 cambio_proveedor)"; S1=$(py "d['id']")
expect "la empresa ve su solicitud abierta" "abierta" "$(call $J1 GET soporte.php $A1 centro >/dev/null; py "d['solicitudes'][0]['estado']")"
expect "otra empresa no la ve" "[]" "$(call $J2 GET soporte.php $A2 centro >/dev/null; py "d['solicitudes']")"
expect "un usuario sin soporte no abre la bandeja" 403 "$(call $J2 GET 'soporte.php?bandeja=1' '' '')"
docker compose -p myposlite exec -T backend php database/soporte.php "$(campo "$FX2" correo)" >/dev/null
expect "soporte abre la bandeja" 200 "$(call $J2 GET 'soporte.php?bandeja=1' '' '')"
expect "con empresa, trámite, paso y mensaje" "Cargar CAF cambio_proveedor No sé dónde pedir el CAF $A1" "$(py "' '.join(next((s['paso'], s['tramite'], s['mensaje'], s['empresaId']) for s in d['solicitudes'] if s['id'] == '$S1'))")"
expect "y el expediente adjunto con el cambio de proveedor" "Facturador Anterior SpA $RUT" "$(py "next(s['contexto']['expediente']['cambioProveedor']['proveedorAnterior'] + ' ' + s['contexto']['empresa']['rut'] for s in d['solicitudes'] if s['id'] == '$S1')")"
expect "sin secretos en lo que recibe soporte" False "$(py "any(k in json.dumps(d) for k in ['RSASK', 'BEGIN', 'pfx', 'clave_cifrada', 'xml_cifrado', 'Clave QA'])")"
expect "soporte ve el expediente vigente de la empresa" "200 $RUT" "$(call $J2 GET "soporte.php?expediente=$A1" '' '') $(py "d['rut']")"
expect "pero no el de una empresa sin solicitudes" 404 "$(call $J2 GET "soporte.php?expediente=$A2" '' '')"
expect "soporte responde" 200 "$(call $J2 POST soporte.php '' '' "{\"accion\":\"responder\",\"id\":\"$S1\",\"respuesta\":\"Entre a sii.cl, Factura electrónica, Timbraje electrónico\"}")"
entregar
expect "quien pidió recibe la respuesta por correo" 1 "$(buzon; py "len([m for m in d['mensajes'] if m['para'] == '$(campo "$FX1" correo)' and m['asunto'] == 'Soporte respondió su pedido de ayuda'])")"
expect "la empresa ve la respuesta" "respondida Entre a sii.cl, Factura electrónica, Timbraje electrónico" "$(call $J1 GET soporte.php $A1 centro >/dev/null; py "d['solicitudes'][0]['estado'] + ' ' + d['solicitudes'][0]['respuesta']")"
expect "soporte cierra la solicitud" 200 "$(call $J2 POST soporte.php '' '' "{\"accion\":\"responder\",\"id\":\"$S1\",\"respuesta\":\"Cerrado tras confirmar la carga\",\"cerrar\":true}")"
expect "cerrada, el expediente deja de estar a la vista" 404 "$(call $J2 GET "soporte.php?expediente=$A1" '' '')"
expect "una cerrada no se vuelve a responder" 404 "$(call $J2 POST soporte.php '' '' "{\"accion\":\"responder\",\"id\":\"$S1\",\"respuesta\":\"Otra\"}")"
expect "sin sesión" 401 "$(call $JS GET 'soporte.php?bandeja=1' '' '')"

echo "--- Permisos"
VC="qa-created-$T1@example.invalid"; VP="Qa$(head -c 12 /dev/urandom | base64 | tr -d '/+=')9x"
call $J1 POST usuarios.php $A1 centro "{\"correo\":\"$VC\",\"nombre\":\"Contadora QA\",\"clave\":\"$VP\",\"rol\":\"contador\",\"locales\":[\"centro\"],\"cajas\":[]}" >/dev/null
entrar $JC "$VC" "$VP" $A1 centro >/dev/null
expect "la contadora pide ayuda" 201 "$(ayuda $JC $A1 etapa_2)"
entregar
expect "el equipo de soporte recibe el pedido por correo" 1 "$(buzon; py "len([m for m in d['mensajes'] if m['para'] == '$(campo "$FX2" correo)' and m['asunto'].startswith('Pedido de ayuda de ')])")"
expect "pero no registra un cambio de proveedor" 403 "$(call $JC POST dte.php $A1 centro "{\"accion\":\"cambio-proveedor\",\"proveedorAnterior\":\"X\",\"tipos\":[56],\"fechaCorte\":\"$HOY\"}")"
expect "ni responde como soporte" 403 "$(call $JC POST soporte.php '' '' "{\"accion\":\"responder\",\"id\":\"$S1\",\"respuesta\":\"x\"}")"
resultado
