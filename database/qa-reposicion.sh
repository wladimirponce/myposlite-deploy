#!/bin/bash
# QA por API de la lista de reposición por proveedor (Sprint 03, ampliación): agrupación por proveedor habitual,
# cantidad sugerida en la presentación de compra, productos sin mínimo, mínimos por local, aislamiento y permisos.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo); FX2=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a)
T2=$(campo "$FX2" tag); A2=$(campo "$FX2" a)
J1=$(mktemp); J2=$(mktemp); JV=$(mktemp)
trap 'fixture borrar $T1; fixture borrar $T2; rm -f $J1 $J2 $JV $OUT' EXIT
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
lin() { echo "{\"presentacionId\":\"$1\",\"cantidad\":\"$2\"}"; }
op() { echo "{\"clave\":\"$(clave)\",\"tipo\":\"$1\",\"lineas\":[$2]}"; }
doc() { echo "{\"clave\":\"$(clave)\",\"tipo\":$1,\"proveedorId\":\"$2\",\"folio\":\"$3\",\"fecha\":\"2026-11-10\",\"montoTotal\":\"100000\",\"lineas\":[$4]}"; }
presentacion() { call $J1 GET productos.php $A1 centro >/dev/null; py "[x['id'] for p in d['productos'] if p['sku'] == '$1' for x in p['presentaciones'] if x['nombre'] == '$2'][0]"; }
proveedor() { call $J1 POST contactos.php $A1 centro "{\"rut\":\"$1\",\"razonSocial\":\"$2\",\"correo\":\"$3\",\"proveedor\":true,\"receptor\":false}" >/dev/null; py "d['id']"; }
# producto JSON EXTRA -> crea el producto agregando campos opcionales (mínimo del local y proveedor habitual).
producto() { call $J1 POST productos.php $A1 centro "${1%\}},$2}"; }
# Grupos con solo los productos de este QA (SKU QA-R-), como (proveedor, [(sku, sugerida, presentación)]).
lista() { call $J1 GET 'inventario.php?reposicion=1' $A1 ${1:-centro} >/dev/null; py "[(g['proveedor'], [(p['sku'], p['sugerida'], p['presentacion']) for p in g['productos'] if p['sku'].startswith('QA-R-')]) for g in d['proveedores'] if any(p['sku'].startswith('QA-R-') for p in g['productos'])]"; }

expect "ingreso administrador 1" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
expect "ingreso administrador 2" 200 "$(entrar $J2 "$(campo "$FX2" correo)" "$(campo "$FX2" clave)" $A2 centro)"
P1=$(proveedor 76.086.428-5 'Distribuidora QA' 'pedidos@distribuidora.invalid')
P2=$(proveedor 96.511.760-1 'Lácteos QA' '')

echo "--- Catálogo"
expect "bebida de Distribuidora, mínimo 30" 201 "$(producto "$(prod QA-R-BEBIDA 'Bebida QA' "$(pres Unidad '' 1 1000),$(pres 'Caja 12' '' 12 11000)")" "\"minimoLocal\":\"30\",\"proveedorId\":\"$P1\"")"
expect "galleta de Distribuidora, mínimo 10" 201 "$(producto "$(prod QA-R-GALLETA 'Galleta QA' "$(pres Unidad '' 1 800)")" "\"minimoLocal\":\"10\",\"proveedorId\":\"$P1\"")"
expect "leche de Lácteos, mínimo 6" 201 "$(producto "$(prod QA-R-LECHE 'Leche QA' "$(pres Unidad '' 1 900)")" "\"minimoLocal\":\"6\",\"proveedorId\":\"$P2\"")"
expect "queso por kilo de Lácteos, mínimo 2,5 kg" 201 "$(producto "$(prod QA-R-QUESO 'Queso QA' "$(pres Granel '' 1 9000),$(pres 'Bolsa 250 g' '' 0.25 2500)" kg)" "\"minimoLocal\":\"2.5\",\"proveedorId\":\"$P2\"")"
expect "pan sin proveedor ni mínimo" 201 "$(producto "$(prod QA-R-PAN 'Pan QA' "$(pres Unidad '' 1 200)")" '"x":null')"
CAJA=$(presentacion QA-R-BEBIDA 'Caja 12'); LECHE=$(presentacion QA-R-LECHE Unidad); GRANEL=$(presentacion QA-R-QUESO Granel)
expect "guía de 1 caja de bebida" 201 "$(call $J1 POST recepciones.php $A1 centro "$(doc 52 $P1 9001 "$(lin $CAJA 1)")")"
expect "inventario inicial: 6 leches y 1 kg de queso" 201 "$(call $J1 POST inventario.php $A1 centro "$(op inicial "$(lin $LECHE 6),$(lin $GRANEL 1)")")"

echo "--- Lista del local Centro"
expect "consulta la reposición" 200 "$(call $J1 GET 'inventario.php?reposicion=1' $A1 centro)"
expect "agrupada por proveedor, sin proveedor al final; la leche en su mínimo no aparece" \
  "[('Distribuidora QA', [('QA-R-BEBIDA', '2', 'Caja 12'), ('QA-R-GALLETA', '10', 'Unidad')]), ('Lácteos QA', [('QA-R-QUESO', '6', 'Bolsa 250 g')]), (None, [('QA-R-PAN', None, 'Unidad')])]" \
  "$(lista)"
expect "la bebida: faltan 18 y se piden 2 cajas de la última compra" "('12.000000', '18.000000', '12.000000')" \
  "$(py "[(p['saldo'], p['falta'], p['equivalencia']) for g in d['proveedores'] for p in g['productos'] if p['sku'] == 'QA-R-BEBIDA'][0]")"
expect "el grupo trae RUT y correo del proveedor" "('760864285', 'pedidos@distribuidora.invalid')" \
  "$(py "[(g['rut'], g['correo']) for g in d['proveedores'] if g['proveedor'] == 'Distribuidora QA'][0]")"
expect "una merma deja la leche bajo el mínimo" 201 "$(call $J1 POST inventario.php $A1 centro "{\"clave\":\"$(clave)\",\"tipo\":\"merma\",\"causa\":\"Rotas\",\"lineas\":[$(lin $LECHE 1)]}")"
expect "y aparece con 1 sugerida" "[('QA-R-LECHE', '1', 'Unidad'), ('QA-R-QUESO', '6', 'Bolsa 250 g')]" "$(lista | python -c "import sys,ast; print([g[1] for g in ast.literal_eval(sys.stdin.read()) if g[0] == 'Lácteos QA'][0])")"

echo "--- Mínimos por local, aislamiento y permisos"
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"norte\"}" >/dev/null
expect "en Norte no hay mínimos: todo sin stock y sin cantidad sugerida" "[None, None, None, None, None]" \
  "$(call $J1 GET 'inventario.php?reposicion=1' $A1 norte >/dev/null; py "[p['sugerida'] for g in d['proveedores'] for p in g['productos'] if p['sku'].startswith('QA-R-')]")"
call $J1 PATCH session.php '' '' "{\"empresaId\":\"$A1\",\"localCodigo\":\"centro\"}" >/dev/null
expect "otra empresa no ve estos productos" "200 False" "$(call $J2 GET 'inventario.php?reposicion=1' $A2 centro) $(py "any(p['sku'].startswith('QA-R-') for g in d['proveedores'] for p in g['productos'])")"
VC="qa-created-$T1@example.invalid"; VP="Qa$(head -c 12 /dev/urandom | base64 | tr -d '/+=')9x"
call $J1 POST usuarios.php $A1 centro "{\"correo\":\"$VC\",\"nombre\":\"Vendedor QA\",\"clave\":\"$VP\",\"rol\":\"vendedor\",\"locales\":[\"centro\"],\"cajas\":[]}" >/dev/null
entrar $JV "$VC" "$VP" $A1 centro >/dev/null
expect "vendedor no consulta la reposición" 403 "$(call $JV GET 'inventario.php?reposicion=1' $A1 centro)"

resultado
