#!/bin/bash
# QA de la recepción desde XML (ampliación del Sprint 06): el DTE que envía un proveedor (aquí, otra empresa de Lite que
# emite una factura a esta) se sube como archivo de ingreso y se lee sin servicio externo. Comprueba el borrador igual al
# digitalizado, que no mueve stock, el duplicado de un documento ya ingresado por foto, un XML dirigido a otro RUT, uno
# alterado que no pasa la firma y uno que no es DTE.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo); FX2=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a)
T2=$(campo "$FX2" tag); A2=$(campo "$FX2" a)
J1=$(mktemp); J2=$(mktemp); TMP=$(mktemp -d)
docker compose -p myposlite stop scheduler >/dev/null 2>&1
limpiar() { docker compose -p myposlite start scheduler >/dev/null 2>&1; MSYS_NO_PATHCONV=1 docker compose -p myposlite exec -T storage sh /opt/lite-storage/retirar-empresa.sh $A1
  fixture borrar $T1; fixture borrar $T2; rm -rf $J1 $J2 $OUT $TMP; }
trap limpiar EXIT
source "$(dirname "$0")/qa-lib-dte.sh"
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
subirArchivo() { curl -s -o $OUT -w '%{http_code}' -b $J1 -X POST "$B/archivos.php" -H 'X-MyPOSLite-Request: 1' -H "X-MyPOSLite-Empresa: $A1" -H 'X-MyPOSLite-Local: centro' -F "archivo=@$(ruta $1)"; }
leer() { call $J1 POST digitalizacion.php $A1 centro "{\"archivoId\":\"$1\"}"; }
# sobre DOC: EnvioDTE de intercambio del documento emitido por la segunda empresa.
sobre() { MSYS_NO_PATHCONV=1 docker compose -p myposlite exec -T backend php -r 'require "backend/config/database.php"; require "backend/src/Emision.php"; echo sobreReceptorDte(conectarBaseLite(), $argv[1], $argv[2]);' $A2 $1; }
saldo() { call $J1 GET inventario.php $A1 centro >/dev/null; py "sum(float(p['saldo']) for p in d['productos'])"; }

expect "ingreso de la empresa que recibe" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
expect "ingreso del proveedor" 200 "$(entrar $J2 "$(campo "$FX2" correo)" "$(campo "$FX2" clave)" $A2 centro)"
bash database/aprovisionar-almacenamiento.sh $A1 >/dev/null || exit 1
sii normal
# Las empresas de la fixture tienen RUT de prueba sin dígito verificador válido; aquí se necesitan como contactos.
rutValido() { python -c "
import random
n = random.randint(40000000, 49999999); s = sum(int(c) * f for c, f in zip(reversed(str(n)), [2, 3, 4, 5, 6, 7] * 2)); dv = 11 - s % 11
print(f'{n}{ {11: \"0\", 10: \"K\"}.get(dv, str(dv)) }')"; }
for a in $A1 $A2; do MSYS_NO_PATHCONV=1 docker compose -p myposlite exec -T backend php -r 'require "backend/config/database.php"; conectarBaseLite()->prepare("UPDATE empresas SET rut = ? WHERE id = ?")->execute([$argv[1], $argv[2]]);' "$(rutValido)" $a; done
RUT1=$(call $J1 GET dte.php $A1 centro >/dev/null; py "d['rut']")
RUT2=$(call $J2 GET dte.php $A2 centro >/dev/null; py "d['rut']")
# El proveedor emite una factura a la empresa que recibe.
expect "el proveedor queda en producción" 200 "$(produccion $J2 $A2 11111111-1)"
habilitar $J2 $A2 33
call $J2 POST contactos.php $A2 centro "{\"rut\":\"$RUT1\",\"razonSocial\":\"Cliente QA\",\"correo\":\"\",\"proveedor\":false,\"receptor\":true,\"giro\":\"Comercio\",\"direccion\":\"Los Aromos 45\",\"comuna\":\"Maipú\"}" >/dev/null; CLIENTE=$(py "d['id']")
call $J2 POST contactos.php $A2 centro '{"rut":"96.511.760-1","razonSocial":"Otro cliente QA","correo":"","proveedor":false,"receptor":true,"giro":"Comercio","direccion":"Los Aromos 45","comuna":"Maipú"}' >/dev/null; OTRO=$(py "d['id']")
call $J2 POST productos.php $A2 centro "$(prod QA-ARROZ 'Arroz QA 1 kg' "$(pres Unidad '' 1 1190)")" >/dev/null
ARROZ=$(call $J2 GET productos.php $A2 centro >/dev/null; py "d['productos'][0]['presentaciones'][0]['id']")
factura() { call $J2 POST emision.php $A2 centro "{\"clave\":\"$(clave)\",\"tipo\":33,\"receptorId\":\"$1\",\"lineas\":[{\"presentacionId\":\"$ARROZ\",\"cantidad\":\"$2\"}]}" >/dev/null; py "d['id']"; }
F1=$(factura $CLIENTE 10); FOLIO1=$(py "d['folio']"); sobre $F1 > $TMP/factura.xml
call $J1 POST contactos.php $A1 centro "{\"rut\":\"$RUT2\",\"razonSocial\":\"Proveedor QA\",\"correo\":\"\",\"proveedor\":true,\"receptor\":false}" >/dev/null; PROV=$(py "d['id']")

echo "--- Borrador desde el XML"
ANTES=$(saldo)
expect "el XML se sube como archivo de ingreso" 201 "$(subirArchivo $TMP/factura.xml)"; X1=$(py "d['id']")
expect "se lee sin servicio externo" "201 xml-dte" "$(leer $X1) $(py "d['modelo']")"
expect "tipo, folio y totales exactos del XML" "33 $FOLIO1 10000 1900 11900" "$(py "d['detectado']['tipo']") $(py "d['detectado']['folio']") $(py "d['detectado']['neto']") $(py "d['detectado']['iva']") $(py "d['detectado']['total']")"
expect "reconoce al proveedor registrado" "$PROV" "$(py "d['proveedorId']")"
expect "la línea con cantidad y costo" "Arroz QA 1 kg 10 1000" "$(py "d['lineas'][0]['nombre']") $(py "d['lineas'][0]['cantidad']") $(py "d['lineas'][0]['costoUnitario']")"
expect "firma válida y dirigido a esta empresa: sin observaciones" "[] 1.0" "$(py "d['detectado']['observaciones']") $(py "float(d['detectado']['confianza'])")"
expect "avisa que la línea falta asociar, como en la foto" True "$(py "any('no se asoció' in a for a in d['avisos'])")"
expect "leer no mueve stock" "$ANTES" "$(saldo)"

echo "--- Duplicado de una factura ya ingresada por foto"
call $J1 POST productos.php $A1 centro "$(prod QA-ARROZ 'Arroz QA' "$(pres Unidad '' 1 1490)")" >/dev/null
MIO=$(call $J1 GET productos.php $A1 centro >/dev/null; py "d['productos'][0]['presentaciones'][0]['id']")
expect "la factura ya se había ingresado por foto" 201 "$(call $J1 POST recepciones.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":33,\"proveedorId\":\"$PROV\",\"folio\":\"$FOLIO1\",\"fecha\":\"$(date +%F)\",\"montoTotal\":\"11900\",\"lineas\":[{\"presentacionId\":\"$MIO\",\"cantidad\":\"10\",\"precioUnitario\":\"1000\"}]}")"
call $J1 GET "digitalizacion.php?id=$(leer $X1 >/dev/null; py "d['id']")" $A1 centro >/dev/null
expect "el borrador avisa que ese documento ya fue ingresado" True "$(py "any('ya fue ingresado' in a for a in d['avisos'])")"

echo "--- XML que no corresponde"
F2=$(factura $OTRO 1); sobre $F2 > $TMP/otro.xml
subirArchivo $TMP/otro.xml >/dev/null; X2=$(py "d['id']")
expect "un DTE emitido a otro RUT queda observado" True "$(leer $X2 >/dev/null; py "any('otro RUT' in o for o in d['detectado']['observaciones'])")"
sed 's#<MntTotal>11900</MntTotal>#<MntTotal>99900</MntTotal>#' $TMP/factura.xml > $TMP/alterado.xml
subirArchivo $TMP/alterado.xml >/dev/null; X3=$(py "d['id']")
expect "un XML alterado no pasa la firma y queda observado" "True 0.5" "$(leer $X3 >/dev/null; py "any('validación del SII' in o for o in d['detectado']['observaciones'])") $(py "d['detectado']['confianza']")"
printf '<?xml version="1.0"?><pedido><item>arroz %s</item></pedido>' "$(clave)" > $TMP/pedido.xml
subirArchivo $TMP/pedido.xml >/dev/null; X4=$(py "d['id']")
expect "un XML que no es DTE se rechaza" 422 "$(leer $X4)"
expect "y queda registrado como lectura con error" True "$(call $J1 GET digitalizacion.php $A1 centro >/dev/null; py "any(l['archivoId'] == '$X4' and l['estado'] == 'error' for l in d['lecturas'])")"
resultado
