#!/bin/bash
source "$(dirname "$0")/qa-lib.sh"
FX=$(fixture crear-catalogo); T=$(campo "$FX" tag); A=$(campo "$FX" a); C=$(campo "$FX" b)
J=$(mktemp); TMP=$(mktemp -d)
limpiar() { fixture borrar "$T"; rm -f "$J" "$OUT"; rm -rf "$TMP"; }
trap limpiar EXIT
source "$(dirname "$0")/qa-lib-dte.sh"

expect "sesión administrador" 200 "$(entrar $J "$(campo "$FX" correo)" "$(campo "$FX" clave)" $A centro)"
expect "día vacío" "200 0" "$(call $J GET cierres.php $A centro) $(py "d['consolidado']['cantidad']")"
expect "boletas habilitadas con SII simulado" 200 "$(produccion $J $A 11111111-1)"
expect "alta caja" 201 "$(call $J POST ubicaciones.php $A centro '{"tipo":"caja","codigo":"1","nombre":"Caja cierre","localCodigo":"centro"}')"
expect "apertura" 201 "$(call $J POST pos.php $A centro '{"accion":"abrir","caja":"1","montoInicial":0}')"
expect "cierre con turno abierto" 409 "$(call $J POST cierres.php $A centro)"
expect "producto" 201 "$(call $J POST productos.php $A centro "$(prod QA-CIERRE 'Bebida QA' "$(pres Unidad '' 1 2000)")")"
call $J GET productos.php $A centro >/dev/null; ID=$(py "d['productos'][0]['presentaciones'][0]['id']")
expect "venta" 201 "$(call $J POST pos.php $A centro "{\"accion\":\"vender\",\"caja\":\"1\",\"clave\":\"cierre-venta-$T\",\"lineas\":[{\"presentacionId\":\"$ID\",\"cantidad\":\"1\"}],\"pagos\":[{\"medio\":\"efectivo\",\"monto\":2000}]}")"
expect "arqueo con diferencia" 200 "$(call $J POST pos.php $A centro '{"accion":"cerrar","caja":"1","efectivoContado":1900}')"
expect "cierre con venta y folio" "200 2000 1 -100 True" "$(call $J POST cierres.php $A centro) $(py "(d['consolidado']['pagos']['efectivo'], d['folios'][0]['total'], next(i for i in d['locales'] if i['codigo']=='centro')['totales']['turnos'][0]['diferencia'], bool(next(i for i in d['locales'] if i['codigo']=='centro')['cerradoEn']))" | tr -d '(),')"
expect "cierre repetido" 200 "$(call $J POST cierres.php $A centro)"
expect "impide reabrir hoy" 409 "$(call $J POST pos.php $A centro '{"accion":"abrir","caja":"1","montoInicial":0}')"
expect "fecha futura inválida" 422 "$(call $J GET 'cierres.php?fecha=2099-01-01' $A centro)"
expect "sesión contador en otra empresa" 200 "$(call $J PATCH session.php '' '' "{\"empresaId\":\"$C\",\"localCodigo\":\"sur\"}")"
expect "contador ve solo su local sin datos ajenos" "200 0 ['sur'] []" "$(call $J GET cierres.php $C sur) $(py "d['consolidado']['cantidad']") $(py "[i['codigo'] for i in d['locales']]") $(py "d['folios']")"
expect "contador no cierra" 403 "$(call $J POST cierres.php $C sur)"
resultado
