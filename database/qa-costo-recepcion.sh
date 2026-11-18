#!/bin/bash
# QA por API del costo al recibir (Sprint 03, ampliación): costo ponderado por documento, alza respecto de la
# compra anterior, margen bajo el mínimo por presentación, líneas sin precio, notas, repetición y revisión.
source "$(dirname "$0")/qa-lib.sh"
FX1=$(fixture crear-catalogo)
T1=$(campo "$FX1" tag); A1=$(campo "$FX1" a)
J1=$(mktemp)
trap 'fixture borrar $T1; rm -f $J1 $OUT' EXIT
clave() { echo "qa-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
lin() { echo "{\"presentacionId\":\"$1\",\"cantidad\":\"$2\"${3:+,\"precioUnitario\":\"$3\"}}"; }
doc() { echo "{\"clave\":\"$(clave)\",\"tipo\":$1,\"proveedorId\":\"$2\",\"folio\":\"$3\",\"fecha\":\"2026-11-10\",\"montoTotal\":\"100000\",\"lineas\":[$4]${5:+,$5}}"; }
presentacion() { call $J1 GET productos.php $A1 centro >/dev/null; py "[x['id'] for p in d['productos'] if p['sku'] == '$1' for x in p['presentaciones'] if x['nombre'] == '$2'][0]"; }
ficha() { call $J1 GET productos.php $A1 centro >/dev/null; py "[p['$2'] for p in d['productos'] if p['sku'] == '$1'][0]"; }
recibir() { call $J1 POST recepciones.php $A1 centro "$1"; }

expect "ingreso administrador" 200 "$(entrar $J1 "$(campo "$FX1" correo)" "$(campo "$FX1" clave)" $A1 centro)"
# Unidad a $1.190 (neto $1.000) y caja de 12 a $11.900 (neto $10.000), margen mínimo 25 %.
PRODUCTO=$(prod QA-BEBIDA 'Bebida QA' "$(pres Unidad '' 1 1190),$(pres 'Caja 12' '' 12 11900)")
expect "producto con margen mínimo de 25 %" 201 "$(call $J1 POST productos.php $A1 centro "${PRODUCTO%\}},\"margenMinimo\":\"25\"}")"
call $J1 POST productos.php $A1 centro "$(prod QA-SIN 'Sin mínimo QA' "$(pres Unidad '' 1 1000)")" >/dev/null
UNI=$(presentacion QA-BEBIDA Unidad); CAJA=$(presentacion QA-BEBIDA 'Caja 12'); SIN=$(presentacion QA-SIN Unidad)
call $J1 POST contactos.php $A1 centro '{"rut":"76.086.428-5","razonSocial":"Distribuidora QA","correo":"","proveedor":true,"receptor":false}' >/dev/null
P1=$(py "d['id']")
R0=$(ficha QA-BEBIDA revision)

echo "--- Primera compra"
expect "guía 3001 con 2 cajas a \$7.200" 201 "$(recibir "$(doc 52 $P1 3001 "$(lin $CAJA 2 7200)")")"
expect "sin avisos: no hay compra anterior y el margen alcanza" "[]" "$(py "d['avisos']")"
expect "el costo queda en \$600 por unidad" 600.00 "$(ficha QA-BEBIDA costo)"
expect "la revisión del producto sube" $((R0 + 1)) "$(ficha QA-BEBIDA revision)"

echo "--- Alza y margen bajo"
FACTURA=$(doc 33 $P1 6001 "$(lin $CAJA 1 9000),$(lin $UNI 6 800)")
expect "factura 6001 con caja a \$9.000 y 6 unidades a \$800" 201 "$(recibir "$FACTURA")"
expect "avisa alza y margen bajo en ambas presentaciones" "['alza', 'margen', 'margen']" "$(py "[a['tipo'] for a in d['avisos']]")"
expect "el alza compara con la guía anterior" "Bebida QA: el costo subió de \$600 a \$766,67 (+27,8 %) respecto de la compra anterior." "$(py "d['avisos'][0]['mensaje']")"
expect "el margen de la unidad" "Bebida QA · Unidad: margen de 23,3 %, bajo el mínimo de 25 %." "$(py "d['avisos'][1]['mensaje']")"
expect "el margen de la caja" "Bebida QA · Caja 12: margen de 8 %, bajo el mínimo de 25 %." "$(py "d['avisos'][2]['mensaje']")"
expect "el costo es el promedio ponderado del documento" 766.67 "$(ficha QA-BEBIDA costo)"
R2=$(ficha QA-BEBIDA revision)
expect "repetir la misma factura no avisa ni cambia nada" "200 []" "$(recibir "$FACTURA") $(py "d['avisos']")"
expect "el costo sigue igual" 766.67 "$(ficha QA-BEBIDA costo)"
expect "y la revisión tampoco" $R2 "$(ficha QA-BEBIDA revision)"

echo "--- Sin precio, notas y baja"
expect "guía 3002 sin precio" 201 "$(recibir "$(doc 52 $P1 3002 "$(lin $UNI 5)")")"
expect "una línea sin precio no cambia el costo" "766.67 $R2" "$(ficha QA-BEBIDA costo) $(ficha QA-BEBIDA revision)"
F6001=$(call $J1 GET recepciones.php $A1 centro >/dev/null; py "[x['id'] for x in d['documentos'] if x['folio'] == '6001'][0]")
expect "nota de crédito con devolución y precio" 201 "$(recibir "$(doc 61 $P1 7001 "$(lin $UNI 1 9999)" "\"referidoId\":\"$F6001\"")")"
expect "la nota no cambia el costo" 766.67 "$(ficha QA-BEBIDA costo)"
expect "factura 6002 con baja a \$500" 201 "$(recibir "$(doc 33 $P1 6002 "$(lin $UNI 10 500)")")"
expect "una baja no avisa" "[]" "$(py "d['avisos']")"
expect "el costo baja a \$500" 500.00 "$(ficha QA-BEBIDA costo)"
expect "la misma compra con igual costo no sube la revisión" 201 "$(recibir "$(doc 33 $P1 6003 "$(lin $UNI 2 500)")")"
expect "revisión sin cambio" $((R2 + 1)) "$(ficha QA-BEBIDA revision)"

echo "--- Producto sin margen mínimo"
expect "factura con alza de un producto sin mínimo" 201 "$(recibir "$(doc 33 $P1 6004 "$(lin $SIN 1 700)")")"
expect "primera compra: sin avisos" "[]" "$(py "d['avisos']")"
expect "segunda compra más cara solo avisa el alza" 201 "$(recibir "$(doc 33 $P1 6005 "$(lin $SIN 1 900)")")"
expect "solo alza" "['alza']" "$(py "[a['tipo'] for a in d['avisos']]")"

echo "--- Impuesto adicional en el costo"
call $J1 POST productos.php $A1 centro "$(prod QA-AZUCAR 'Bebida azucarada QA' "$(pres Unidad '' 1 1500)" | sed 's/}$/,"impuestoCodigo":"bebidas-azucar"}/')" >/dev/null
call $J1 POST productos.php $A1 centro "$(prod QA-CIG 'Cigarrillos QA' "$(pres Cajetilla '' 1 5200)" | sed 's/}$/,"impuestoCodigo":"cigarrillos"}/')" >/dev/null
AZU=$(presentacion QA-AZUCAR Unidad); CIG=$(presentacion QA-CIG Cajetilla)
expect "guía de bebida azucarada a \$1.000 netos" 201 "$(recibir "$(doc 52 $P1 3101 "$(lin $AZU 10 1000)")")"
expect "el costo suma el 18 % de impuesto adicional" 1180.00 "$(ficha QA-AZUCAR costo)"
expect "su factura al mismo precio" 201 "$(recibir "$(doc 33 $P1 6101 "$(lin $AZU 10 1000)")")"
expect "no avisa alza: la guía se compara con el mismo impuesto" "[] 1180.00" "$(py "d['avisos']") $(ficha QA-AZUCAR costo)"
expect "factura exenta al mismo precio" 201 "$(recibir "$(doc 34 $P1 6102 "$(lin $AZU 10 1000)")")"
expect "la factura exenta no lleva impuesto adicional" 1000.00 "$(ficha QA-AZUCAR costo)"
expect "la empresa ajusta la tasa a 20 % desde el 15 de noviembre" 201 "$(call $J1 POST impuestos.php $A1 centro '{"codigo":"bebidas-azucar","vigenteDesde":"2026-11-15","porcentaje":"20","utmPorUnidad":"0"}')"
expect "factura del 20 de noviembre" 201 "$(recibir "$(doc 33 $P1 6103 "$(lin $AZU 10 1000)" '"fecha":"2026-11-20"')")"
expect "avisa el alza respecto de la exenta" "Bebida azucarada QA: el costo subió de \$1.000 a \$1.200 (+20 %) respecto de la compra anterior." "$(py "d['avisos'][0]['mensaje']")"
expect "y usa la tasa de la empresa vigente en la fecha del documento" 1200.00 "$(ficha QA-AZUCAR costo)"
expect "factura de cigarrillos a \$4.000" 201 "$(recibir "$(doc 33 $P1 6104 "$(lin $CIG 10 4000)")")"
expect "el impuesto al tabaco no se suma: ya viene en el precio" 4000.00 "$(ficha QA-CIG costo)"

resultado
