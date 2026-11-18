#!/bin/bash
# Carga un ejemplo pequeño de datos ficticios en la empresa de demostración, a través de la API.
# Uso: bash database/demo-datos.sh   (requiere Lite levantado en Docker local y migraciones aplicadas)
# La credencial de la cuenta demo vive en database/.demo-credencial.json (ignorado por Git).
# Si ese archivo ya permite entrar, se usa tal cual: el script nunca cambia una contraseña que funciona.
# Solo si no existe o no sirve, crea la cuenta o fija una contraseña nueva. Para elegirla, definir MYPOSLITE_DEMO_CLAVE.
# Es idempotente: si el catálogo de la demo ya tiene productos, no carga nada.
source "$(dirname "$0")/qa-lib.sh"
CRED=database/.demo-credencial.json
J=$(mktemp); trap 'rm -f $J $OUT' EXIT
entrar_demo() {
  [ -s $CRED ] || return 1
  CORREO=$(campo "$(cat $CRED)" correo); CLAVE=$(campo "$(cat $CRED)" clave)
  [ "$(call $J POST session.php '' '' "{\"correo\":\"$CORREO\",\"clave\":\"$CLAVE\"}")" = 200 ]
}
if ! entrar_demo; then
  docker compose -p myposlite exec -T ${MYPOSLITE_DEMO_CLAVE:+-e MYPOSLITE_DEMO_CLAVE} backend php backend/bin/crear-demo.php > $CRED.tmp \
    && mv $CRED.tmp $CRED || { rm -f $CRED.tmp; exit 1; }
  echo "Se fijó una contraseña para la cuenta demo. Está en $CRED."
  entrar_demo || { echo "No se pudo entrar con la cuenta demo."; exit 1; }
fi
E=$(py "[e['id'] for e in d['empresas'] if e['rut'] == '999999999'][0]")
call $J PATCH session.php '' '' "{\"empresaId\":\"$E\",\"localCodigo\":\"centro\"}" >/dev/null
call $J GET productos.php $E centro >/dev/null
if [ "$(py "len(d['productos'])")" != 0 ]; then echo "La demostración ya tiene productos. No se cargó nada."; exit 0; fi

clave() { echo "demo-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
lin() { echo "{\"presentacionId\":\"$1\",\"cantidad\":\"$2\"${3:+,\"vencimiento\":\"$3\"}}"; }
pid() { call $J GET productos.php $E centro >/dev/null; py "[x['id'] for p in d['productos'] if p['sku'] == '$1' for x in p['presentaciones'] if x['nombre'] == '$2'][0]"; }

call $J POST contactos.php $E centro '{"rut":"76.086.428-5","razonSocial":"Distribuidora Ejemplo Ltda.","correo":"","proveedor":true,"receptor":false}' >/dev/null
PROV=$(py "d['id']")
call $J POST productos.php $E centro "{\"sku\":\"BEB-COLA-15\",\"nombre\":\"Bebida cola 1,5 L\",\"unidadBase\":\"unidad\",\"afectoIva\":true,\"categoria\":\"Bebidas\",\"minimoLocal\":\"24\",\"impuestoCodigo\":\"bebidas-azucar\",\"costo\":\"950\",\"margenMinimo\":\"25\",\"proveedorId\":\"$PROV\",\"presentaciones\":[$(pres Unidad 7800000000011 1 1690),$(pres 'Pack 6' 7800000000012 6 9500)]}" >/dev/null
call $J POST productos.php $E centro "{\"sku\":\"CIG-ROJO\",\"nombre\":\"Cigarrillos rojo 20\",\"unidadBase\":\"unidad\",\"afectoIva\":true,\"verificarEdad\":true,\"categoria\":\"Tabaco\",\"minimoLocal\":\"10\",\"impuestoCodigo\":\"cigarrillos\",\"presentaciones\":[$(pres Cajetilla 7800000000021 1 5200),$(pres 'Cartón' 7800000000022 10 50000)]}" >/dev/null
call $J POST productos.php $E centro "{\"sku\":\"QUE-GAU\",\"nombre\":\"Queso gauda\",\"unidadBase\":\"kg\",\"afectoIva\":true,\"categoria\":\"Lácteos\",\"minimoLocal\":\"2\",\"costo\":\"6200\",\"margenMinimo\":\"30\",\"presentaciones\":[$(pres Granel '' 1 9990),$(pres 'Bolsa 250 g' 7800000000031 0.25 2690)]}" >/dev/null
COLA=$(pid BEB-COLA-15 Unidad); PACK=$(pid BEB-COLA-15 'Pack 6'); CAJETILLA=$(pid CIG-ROJO Cajetilla); GRANEL=$(pid QUE-GAU Granel)

call $J POST inventario.php $E centro "{\"clave\":\"$(clave)\",\"tipo\":\"inicial\",\"causa\":\"Conteo de apertura\",\"lineas\":[$(lin $CAJETILLA 8),$(lin $GRANEL 1.5 2027-01-15)]}" >/dev/null
call $J POST recepciones.php $E centro "{\"clave\":\"$(clave)\",\"tipo\":52,\"proveedorId\":\"$PROV\",\"folio\":\"1001\",\"fecha\":\"2026-11-14\",\"montoTotal\":\"22800\",\"lineas\":[$(lin $COLA 24)]}" >/dev/null
GUIA=$(py "d['id']")
call $J POST recepciones.php $E centro "{\"clave\":\"$(clave)\",\"tipo\":33,\"proveedorId\":\"$PROV\",\"folio\":\"5001\",\"fecha\":\"2026-11-15\",\"montoTotal\":\"40698\",\"guias\":[\"$GUIA\"],\"lineas\":[{\"presentacionId\":\"$PACK\",\"cantidad\":\"6\",\"precioUnitario\":\"5700\"}]}" >/dev/null
call $J POST inventario.php $E centro "{\"clave\":\"$(clave)\",\"tipo\":\"merma\",\"causa\":\"Botellas dañadas en bodega\",\"lineas\":[$(lin $COLA 2)]}" >/dev/null
call $J GET inventario.php $E centro >/dev/null
echo "Datos de demostración cargados:"; py "'\n'.join(f\"  {p['nombre']}: {float(p['saldo']):g} {p['unidad_base']} ({p['estado']})\" for p in d['productos'])"
echo "Credencial de la cuenta demo en $CRED"
