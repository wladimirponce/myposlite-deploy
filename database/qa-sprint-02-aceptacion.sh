#!/bin/bash
# QA de aceptación del Sprint 02 por API: presentaciones y códigos de barras, dos empresas,
# usuario con dos membresías, vendedor, contador e importación con errores por fila.
# Crea dos juegos de datos ficticios y los retira al terminar. Requiere python en el host.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo); FX2=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a); B1=$(campo "$FX1" b)
T2=$(campo "$FX2" tag); A2=$(campo "$FX2" a)
J1=$(mktemp); J2=$(mktemp); JV=$(mktemp)
trap 'fixture borrar $T1; fixture borrar $T2; rm -f $J1 $J2 $JV $OUT' EXIT

expect "ingreso administrador 1" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
expect "ingreso administrador 2" 200 "$(entrar $J2 "$(campo "$FX2" correo)" "$(campo "$FX2" clave)" $A2 centro)"

echo "--- Presentaciones y códigos de barras"
expect "bebida con unidad y caja" 201 "$(call $J1 POST productos.php $A1 centro "$(prod QA-BEBIDA 'Bebida QA' "$(pres Unidad 7801 1 1200),$(pres 'Caja 12' 7802 12 12000)")")"
P_BEBIDA=$(py "d['id']")
expect "cigarrillos con cajetilla y cartón" 201 "$(call $J1 POST productos.php $A1 centro "$(prod QA-CIGARRO 'Cigarrillos QA' "$(pres Cajetilla 7803 1 4500),$(pres 'Cartón' 7804 10 43000)" unidad true)")"
expect "producto por peso" 201 "$(call $J1 POST productos.php $A1 centro "$(prod QA-PESO 'Queso QA' "$(pres Granel '' 1 8990),$(pres 'Bolsa 250 g' 7805 0.25 2400)" kg)")"
call $J1 GET 'productos.php?codigo=7802' $A1 centro >/dev/null
expect "7802 resuelve la caja de bebida" "QA-BEBIDA Caja 12 12.000000 12000" "$(py "d['producto']['sku'], *[(p['nombre'], p['equivalencia'], p['precio']) for p in d['producto']['presentaciones'] if p['id'] == d['presentacionId']][0]")"
call $J1 GET 'productos.php?codigo=7801' $A1 centro >/dev/null
expect "7801 resuelve la unidad de bebida" "QA-BEBIDA Unidad 1.000000" "$(py "d['producto']['sku'], *[(p['nombre'], p['equivalencia']) for p in d['producto']['presentaciones'] if p['id'] == d['presentacionId']][0]")"
call $J1 GET 'productos.php?codigo=7804' $A1 centro >/dev/null
expect "7804 resuelve el cartón y pide edad" "QA-CIGARRO Cartón 10.000000 True" "$(py "d['producto']['sku'], *[(p['nombre'], p['equivalencia']) for p in d['producto']['presentaciones'] if p['id'] == d['presentacionId']][0], d['producto']['verificar_edad']")"
call $J1 GET 'productos.php?codigo=7805' $A1 centro >/dev/null
expect "7805 resuelve la bolsa por peso" "QA-PESO kg Bolsa 250 g 0.250000" "$(py "d['producto']['sku'], d['producto']['unidad_base'], *[(p['nombre'], p['equivalencia']) for p in d['producto']['presentaciones'] if p['id'] == d['presentacionId']][0]")"
expect "código desconocido" 404 "$(call $J1 GET 'productos.php?codigo=0000' $A1 centro)"
expect "equivalencia cero" 422 "$(call $J1 POST productos.php $A1 centro "$(prod QA-X1 X "$(pres U '' 0 100)")")"
expect "equivalencia 0.000000" 422 "$(call $J1 POST productos.php $A1 centro "$(prod QA-X2 X "$(pres U '' 0.000000 100)")")"
expect "equivalencia negativa" 422 "$(call $J1 POST productos.php $A1 centro "$(prod QA-X3 X "$(pres U '' -1 100)")")"
expect "código de barras de otro producto" 409 "$(call $J1 POST productos.php $A1 centro "$(prod QA-X4 X "$(pres U 7801 1 100)")")"
expect "código repetido dentro del producto" 409 "$(call $J1 POST productos.php $A1 centro "$(prod QA-X5 X "$(pres U 7900 1 100),$(pres C 7900 6 500)")")"
expect "presentación repetida en el producto" 409 "$(call $J1 POST productos.php $A1 centro "$(prod QA-X6 X "$(pres U '' 1 100),$(pres U '' 6 500)")")"
expect "SKU repetido" 409 "$(call $J1 POST productos.php $A1 centro "$(prod QA-BEBIDA X "$(pres U '' 1 100)")")"
call $J1 GET productos.php $A1 centro >/dev/null
expect "los rechazos no dejaron productos" 3 "$(py "len(d['productos'])")"

echo "--- Importación con errores por fila"
FILAS="{\"filas\":[{\"fila\":2,\"producto\":$(prod QA-I1 'Importado 1' "$(pres U 7910 1 100)")},{\"fila\":3,\"producto\":$(prod QA-I2 'Importado 2' "$(pres U 7801 1 100)")},{\"fila\":4,\"producto\":$(prod QA-I3 'Importado 3' "$(pres U '' 0 100)")}]}"
expect "previsualización" 200 "$(call $J1 POST 'productos.php?importacion=previsualizar' $A1 centro "$FILAS")"
expect "una fila válida y errores en 3 y 4" "1 [3, 4] 0" "$(py "d['correctas'], [e['fila'] for e in d['errores']], d['importados']")"
call $J1 POST 'productos.php?importacion=confirmar' $A1 centro "$FILAS" >/dev/null
expect "confirmar con errores no importa" 0 "$(py "d['importados']")"
call $J1 GET productos.php $A1 centro >/dev/null
expect "el catálogo sigue con tres productos" 3 "$(py "len(d['productos'])")"

echo "--- Dos empresas e identificadores ajenos"
expect "alta de proveedor en empresa 1" 201 "$(call $J1 POST contactos.php $A1 centro '{"rut":"76.086.428-5","razonSocial":"Proveedor QA","correo":"","proveedor":true,"receptor":false}')"
C_A1=$(py "d['id']")
call $J1 GET 'productos.php?codigo=7801' $A1 centro >/dev/null; PRES_A1=$(py "d['presentacionId']")
call $J1 GET usuarios.php $A1 centro >/dev/null; U_A1=$(py "d['actor']")
expect "mismo SKU y código en empresa 2" 201 "$(call $J2 POST productos.php $A2 centro "$(prod QA-BEBIDA 'Bebida de otra empresa' "$(pres Unidad 7801 1 999)")")"
P_A2=$(py "d['id']")
expect "mismo RUT de contacto en empresa 2" 201 "$(call $J2 POST contactos.php $A2 centro '{"rut":"76.086.428-5","razonSocial":"Proveedor QA 2","correo":"","proveedor":true,"receptor":false}')"
call $J2 GET 'productos.php?codigo=7801' $A2 centro >/dev/null
expect "el código resuelve el producto de la empresa activa" "$P_A2 999" "$(py "d['producto']['id'], d['producto']['presentaciones'][0]['precio']")"
call $J2 GET productos.php $A2 centro >/dev/null
expect "empresa 2 solo ve su producto" 1 "$(py "len(d['productos'])")"
EDIT="\"sku\":\"QA-ROBO\",\"nombre\":\"Ajeno\",\"unidadBase\":\"unidad\",\"afectoIva\":true,\"presentaciones\":[$(pres U '' 1 1)]"
expect "editar producto ajeno por ID" 404 "$(call $J2 PATCH productos.php $A2 centro "{\"id\":\"$P_BEBIDA\",\"revision\":\"1\",$EDIT}")"
expect "editar contacto ajeno por ID" 404 "$(call $J2 PATCH contactos.php $A2 centro "{\"id\":\"$C_A1\",\"rut\":\"76.086.428-5\",\"razonSocial\":\"Ajeno\",\"correo\":\"\",\"proveedor\":true,\"receptor\":false}")"
expect "editar usuario ajeno por ID" 404 "$(call $J2 PATCH usuarios.php $A2 centro "{\"id\":\"$U_A1\",\"rol\":\"vendedor\",\"activa\":false,\"locales\":[],\"cajas\":[]}")"
expect "proveedor habitual ajeno" 422 "$(call $J2 POST productos.php $A2 centro "{\"sku\":\"QA-Y1\",\"nombre\":\"Y\",\"unidadBase\":\"unidad\",\"afectoIva\":true,\"presentaciones\":[$(pres U '' 1 1)],\"proveedorId\":\"$C_A1\"}")"
expect "promoción sobre presentación ajena" 422 "$(call $J2 POST promociones.php $A2 centro "{\"presentacionId\":\"$PRES_A1\",\"nombre\":\"Ajena\",\"tipo\":\"2x1\",\"alcance\":\"local\",\"desde\":\"2026-01-01\",\"hasta\":\"2027-01-01\"}")"
expect "editar local inexistente en la empresa" 404 "$(call $J2 PATCH ubicaciones.php $A2 centro '{"tipo":"local","codigo":"sur","nombre":"Ajeno"}')"
expect "seleccionar empresa ajena" 403 "$(call $J2 PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"centro\"}")"
for ep in productos contactos usuarios ubicaciones destinatarios promociones impuestos puesta-en-marcha; do
  expect "lectura de $ep con encabezado de empresa ajena" 409 "$(call $J2 GET $ep.php $A1 centro)"
done
call $J1 GET 'productos.php?codigo=7801' $A1 centro >/dev/null
expect "el producto de empresa 1 quedó intacto" "$P_BEBIDA Bebida QA 1" "$(py "d['producto']['id'], d['producto']['nombre'], d['producto']['revision']")"

echo "--- Vendedor con un solo local"
VC="qa-created-$T1@example.invalid"; VP="Qa$(head -c 12 /dev/urandom | base64 | tr -d '/+=')9x"
expect "alta de vendedor en Centro" 201 "$(call $J1 POST usuarios.php $A1 centro "{\"correo\":\"$VC\",\"nombre\":\"Vendedor QA\",\"clave\":\"$VP\",\"rol\":\"vendedor\",\"locales\":[\"centro\"],\"cajas\":[]}")"
expect "ingreso del vendedor" 200 "$(entrar $JV $VC $VP $A1 centro)"
expect "ve una empresa y solo el local Centro" "1 ['centro']" "$(py "len(d['empresas']), [l['codigo'] for l in d['empresas'][0]['locales']]")"
expect "no puede seleccionar el local Norte" 403 "$(call $JV PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"norte\"}")"
expect "encabezado de otro local" 409 "$(call $JV GET productos.php $A1 norte)"
expect "no lista usuarios" 403 "$(call $JV GET usuarios.php $A1 centro)"
expect "no crea usuarios" 403 "$(call $JV POST usuarios.php $A1 centro '{"correo":"x@example.invalid","nombre":"X","clave":"clave-de-prueba-1","rol":"administrador","locales":[],"cajas":[]}')"
expect "no cambia su propio rol" 403 "$(call $JV PATCH usuarios.php $A1 centro '{"id":"1","rol":"administrador","activa":true,"locales":[],"cajas":[]}')"
expect "no lista locales y cajas" 403 "$(call $JV GET ubicaciones.php $A1 centro)"
expect "no ve contactos" 403 "$(call $JV GET contactos.php $A1 centro)"
expect "no ve destinatarios" 403 "$(call $JV GET destinatarios.php $A1 centro)"
expect "no ve impuestos" 403 "$(call $JV GET impuestos.php $A1 centro)"
expect "no crea productos" 403 "$(call $JV POST productos.php $A1 centro "$(prod QA-V1 V "$(pres U '' 1 1)")")"
expect "no crea promociones" 403 "$(call $JV POST promociones.php $A1 centro "{\"presentacionId\":\"$PRES_A1\",\"nombre\":\"V\",\"tipo\":\"2x1\",\"alcance\":\"local\",\"desde\":\"2026-01-01\",\"hasta\":\"2027-01-01\"}")"
expect "sí consulta el catálogo" 200 "$(call $JV GET productos.php $A1 centro)"
expect "sí resuelve un código de barras" 200 "$(call $JV GET 'productos.php?codigo=7803' $A1 centro)"

echo "--- Usuario con membresía en dos empresas: administrador en una, contador en otra"
call $J1 GET session.php '' '' >/dev/null
expect "la sesión lista ambas empresas con su rol" "['administrador', 'contador']" "$(py "sorted(e['rol'] for e in d['empresas'])")"
expect "cambia a la empresa donde es contador" 200 "$(call $J1 PATCH session.php '' '' "{\"empresaId\":\"$B1\",\"localCodigo\":\"sur\"}")"
expect "encabezado de la empresa anterior" 409 "$(call $J1 GET productos.php $A1 sur)"
expect "contador lee el catálogo" 200 "$(call $J1 GET productos.php $B1 sur)"
expect "sin productos de la otra empresa" 0 "$(py "len(d['productos'])")"
expect "contador lee contactos" 200 "$(call $J1 GET contactos.php $B1 sur)"
expect "contador lee impuestos" 200 "$(call $J1 GET impuestos.php $B1 sur)"
expect "contador lee promociones" 200 "$(call $J1 GET promociones.php $B1 sur)"
expect "contador lee la puesta en marcha" 200 "$(call $J1 GET puesta-en-marcha.php $B1 sur)"
expect "contador no crea productos" 403 "$(call $J1 POST productos.php $B1 sur "$(prod QA-C1 C "$(pres U '' 1 1)")")"
expect "contador no importa" 403 "$(call $J1 POST 'productos.php?importacion=confirmar' $B1 sur "$FILAS")"
expect "contador no crea contactos" 403 "$(call $J1 POST contactos.php $B1 sur '{"rut":"11.111.111-1","razonSocial":"C","correo":"","proveedor":true,"receptor":false}')"
expect "contador no crea locales" 403 "$(call $J1 POST ubicaciones.php $B1 sur '{"tipo":"local","codigo":"nuevo","nombre":"Nuevo"}')"
expect "contador no crea usuarios" 403 "$(call $J1 POST usuarios.php $B1 sur '{"correo":"x@example.invalid","nombre":"X","clave":"clave-de-prueba-1","rol":"administrador","locales":[],"cajas":[]}')"
expect "contador no cambia destinatarios" 403 "$(call $J1 PUT destinatarios.php $B1 sur '{"revision":"0","principal":"x@example.invalid","adicionales":[]}')"
expect "contador no crea promociones" 403 "$(call $J1 POST promociones.php $B1 sur '{"presentacionId":"1","nombre":"C","tipo":"2x1","alcance":"local","desde":"2026-01-01","hasta":"2027-01-01"}')"
expect "contador no desactiva promociones" 403 "$(call $J1 PATCH promociones.php $B1 sur '{"id":"1","activa":false}')"
expect "contador no ajusta impuestos" 403 "$(call $J1 POST impuestos.php $B1 sur '{"codigo":"vinos","vigenteDesde":"2026-01-01","porcentaje":"1","utmPorUnidad":"0"}')"
expect "vuelve a la empresa donde administra" 200 "$(call $J1 PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"centro\"}")"
expect "y recupera la edición" 201 "$(call $J1 POST productos.php $A1 centro "$(prod QA-FINAL 'Producto final' "$(pres U '' 1 1)")")"

resultado
