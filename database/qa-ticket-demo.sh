#!/bin/bash
source "$(dirname "$0")/qa-lib.sh"
FX=$(fixture crear-catalogo)
TAG=$(campo "$FX" tag); A=$(campo "$FX" a); B1=$(campo "$FX" b)
J=$(mktemp); PDF=$(mktemp)
trap 'fixture borrar "$TAG"; rm -f "$J" "$PDF" "$OUT"' EXIT
[[ "$A" =~ ^[0-9]+$ ]] || exit 1
printf 'UPDATE empresas SET es_demo = TRUE WHERE id = %s;\n' "$A" | docker compose -p myposlite exec -T database sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb -u "$MARIADB_USER" "$MARIADB_DATABASE"' || exit 1

expect "sesión y local demo" 200 "$(entrar $J "$(campo "$FX" correo)" "$(campo "$FX" clave)" "$A" centro)"
expect "caja demo" 201 "$(call $J POST ubicaciones.php $A centro '{"tipo":"caja","codigo":"1","nombre":"Caja demo","localCodigo":"centro"}')"
expect "producto demo" 201 "$(call $J POST productos.php $A centro "$(prod QA-TICKET 'Bebida QA' "$(pres Unidad 7800001234567 1 2000)")")"
if [ "$bad" -gt 0 ]; then resultado; exit 1; fi
call $J GET productos.php $A centro >/dev/null
P=$(py "d['productos'][0]['presentaciones'][0]['id']")
expect "abrir caja" 201 "$(call $J POST pos.php $A centro '{"accion":"abrir","caja":"1","montoInicial":1000}')"
expect "venta sin DTE" "201 None" "$(call $J POST pos.php $A centro "{\"accion\":\"vender\",\"caja\":\"1\",\"clave\":\"ticket-qa-$TAG\",\"lineas\":[{\"presentacionId\":\"$P\",\"cantidad\":\"1\"}],\"pagos\":[{\"medio\":\"efectivo\",\"monto\":2000,\"recibido\":2500}]}") $(py "d['documento']")"
V=$(py "d['id']")
ticket() { curl -s -o "$PDF" -w '%{http_code}' -b "$J" "$B/ticket-venta.php?id=$V" -H "X-MyPOSLite-Empresa: $1" -H "X-MyPOSLite-Local: $2"; }
expect "PDF del ticket" 200 "$(ticket "$A" centro)"
PDF_HOST="$PDF"
if command -v cygpath >/dev/null; then PDF_HOST=$(cygpath -m "$PDF"); fi
expect "ticket interno de 80 mm con monto y vuelto" True "$(python -c 'import sys,pymupdf; p=pymupdf.open(stream=open(sys.argv[1],"rb").read(),filetype="pdf")[0]; t=" ".join(p.get_text().split()); w=p.rect.width * 25.4 / 72; print(True if abs(w - 80) < .1 and all(x in t for x in ("TICKET DE VENTA", "sin validez tributaria", "Bebida QA", "$2.000", "$500")) else (w, t))' "$PDF_HOST")"
expect "reimpresión" 200 "$(ticket "$A" centro)"
expect "arqueo" 200 "$(call $J POST pos.php $A centro '{"accion":"cerrar","caja":"1","efectivoContado":3000}')"
expect "cierre sin folios" "200 1 []" "$(call $J POST cierres.php $A centro) $(py "d['consolidado']['cantidad']") $(py "d['folios']")"
expect "cambio de empresa" 200 "$(call $J PATCH session.php '' '' "{\"empresaId\":\"$B1\",\"localCodigo\":\"sur\"}")"
expect "venta ajena no disponible" 404 "$(ticket "$B1" sur)"
resultado
