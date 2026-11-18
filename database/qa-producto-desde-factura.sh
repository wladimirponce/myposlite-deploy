#!/bin/bash
# QA por API de la carga asistida (Sprint 03, ampliación): una factura digitalizada trae un producto que no está en el
# catálogo; se crea desde la línea con el alta normal de productos y el borrador lo reconoce, la confirmación lo
# recibe con costo y proveedor habitual, y la siguiente factura del proveedor lo asocia por la memoria del proveedor.
# Requiere el lector simulado (perfil simulador), como qa-digitalizacion.sh.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a); B1=$(campo "$FX1" b)
J1=$(mktemp); TMP=$(mktemp -d)
limpiar() {
  for e in $A1 $B1; do MSYS_NO_PATHCONV=1 docker compose -p myposlite exec -T storage sh /opt/lite-storage/retirar-empresa.sh $e; done
  fixture borrar $T1; rm -rf $J1 $OUT $TMP
}
trap limpiar EXIT
ruta() { if command -v cygpath >/dev/null; then cygpath -m "$1"; else echo "$1"; fi; }
subir() { curl -s -o $OUT -w '%{http_code}' -b $J1 -c $J1 -X POST "$B/archivos.php" -H 'X-MyPOSLite-Request: 1' \
  -H "X-MyPOSLite-Empresa: $A1" -H 'X-MyPOSLite-Local: centro' -F "archivo=@$(ruta $1)"; }
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
documento() {
  local marca="LECTURA-SIMULADA:$(printf '%s' "$2" | base64 -w0) $(head -c 8 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  printf '%%PDF-1.4\n%% %s\n%%%%EOF\n' "$marca" > $1
}
item() { echo "{\"codigo_detectado\":$1,\"codigo_barra_detectado\":$2,\"nombre_detectado\":\"$3\",\"cantidad_detectada\":$4,\"costo_unitario_detectado\":$5,\"total_detectado\":$(($4 * $5))}"; }
factura() { echo "{\"tipo_documento\":\"FACTURA_ELECTRONICA\",\"proveedor_rut\":\"76086428-5\",\"proveedor_nombre\":\"Distribuidora QA\",\"folio\":\"$1\",\"fecha_documento\":\"2026-11-10\",\"total\":$2,\"items\":[$3]}"; }
ficha() { call $J1 GET productos.php $A1 centro >/dev/null; py "[p['$2'] for p in d['productos'] if p['sku'] == '$1'][0]"; }

expect "ingreso administrador" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
bash database/aprovisionar-almacenamiento.sh $A1 $B1 >/dev/null || exit 1
call $J1 POST contactos.php $A1 centro '{"rut":"76.086.428-5","razonSocial":"Distribuidora QA","correo":"","proveedor":true,"receptor":false}' >/dev/null; P1=$(py "d['id']")
call $J1 POST productos.php $A1 centro "$(prod QA-BEB 'Bebida QA' "$(pres Unidad 7801 1 1200)")" >/dev/null
UNI=$(call $J1 GET productos.php $A1 centro >/dev/null; py "[x['id'] for p in d['productos'] if p['sku'] == 'QA-BEB' for x in p['presentaciones']][0]")

echo "--- Factura con un producto nuevo"
documento $TMP/f1.pdf "$(factura 'N° 8001' 21000 "$(item null '"7801"' 'BEBIDA 1.5' 10 1200),$(item '"PROV-77"' '"7809999000017"' 'CHOCOLATE AMARGO 100G' 10 900)")"
expect "respaldo de la factura" 201 "$(subir $TMP/f1.pdf)"; F1=$(py "d['id']")
expect "lectura" 201 "$(call $J1 POST digitalizacion.php $A1 centro "{\"archivoId\":\"$F1\"}")"; L1=$(py "d['id']")
expect "la bebida se asocia y el chocolate no" "['código de barras', None]" "$(py "[l['metodo'] for l in d['lineas']]")"
expect "la lectura no creó nada en el catálogo" 1 "$(call $J1 GET productos.php $A1 centro >/dev/null; py "len([p for p in d['productos'] if p['sku'] in ('QA-BEB', 'CHOC-AMARGO')])")"

echo "--- Alta desde la línea"
NUEVO="{\"sku\":\"CHOC-AMARGO\",\"nombre\":\"Chocolate amargo 100 g\",\"unidadBase\":\"unidad\",\"afectoIva\":true,\"verificarEdad\":false,\"proveedorId\":\"$P1\",\"presentaciones\":[$(pres Unidad 7809999000017 1 1990)]}"
expect "crea el producto con el código de barras leído" 201 "$(call $J1 POST productos.php $A1 centro "$NUEVO")"
expect "con el proveedor del documento como habitual" "$P1" "$(ficha CHOC-AMARGO proveedor_habitual_id)"
expect "el código de barras duplicado de otro producto se rechaza" 409 "$(call $J1 POST productos.php $A1 centro "${NUEVO//CHOC-AMARGO/CHOC-OTRO}")"
call $J1 GET "digitalizacion.php?id=$L1" $A1 centro >/dev/null
CHOC=$(py "d['lineas'][1]['presentacionId']")
expect "el mismo borrador ahora lo sugiere por código de barras" "código de barras" "$(py "d['lineas'][1]['metodo']")"

echo "--- Confirmación"
LINEAS="[{\"presentacionId\":\"$UNI\",\"cantidad\":\"10\",\"precioUnitario\":\"1200\",\"claveProveedor\":$(py "json.dumps(d['lineas'][0]['claveProveedor'])")},{\"presentacionId\":\"$CHOC\",\"cantidad\":\"10\",\"precioUnitario\":\"900\",\"claveProveedor\":$(py "json.dumps(d['lineas'][1]['claveProveedor'])")}]"
expect "confirma la factura" 201 "$(call $J1 POST recepciones.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":33,\"proveedorId\":\"$P1\",\"folio\":\"8001\",\"fecha\":\"2026-11-10\",\"montoTotal\":\"21000\",\"lecturaId\":\"$L1\",\"lineas\":$LINEAS}")"
expect "entran 10 chocolates" 10.000000 "$(call $J1 GET inventario.php $A1 centro >/dev/null; py "[p['saldo'] for p in d['productos'] if p['sku'] == 'CHOC-AMARGO'][0]")"
expect "con el costo de la factura" 900.00 "$(ficha CHOC-AMARGO costo)"

echo "--- Siguiente factura del proveedor, sin código de barras"
documento $TMP/f2.pdf "$(factura 'N° 8002' 9000 "$(item '"PROV-77"' null 'CHOCOLATE AMARGO 100G' 10 900)")"
expect "respaldo" 201 "$(subir $TMP/f2.pdf)"; F2=$(py "d['id']")
expect "lectura" 201 "$(call $J1 POST digitalizacion.php $A1 centro "{\"archivoId\":\"$F2\"}")"
expect "el chocolate llega asociado por la memoria del proveedor" "$CHOC asociación anterior del proveedor" "$(py "d['lineas'][0]['presentacionId'], d['lineas'][0]['metodo']" | tr -d "(),'")"

echo "--- Permisos"
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$B1\",\"localCodigo\":\"sur\"}" >/dev/null
expect "el contador no crea productos" 403 "$(call $J1 POST productos.php $B1 sur "${NUEVO//CHOC-AMARGO/CHOC-B}")"

resultado
