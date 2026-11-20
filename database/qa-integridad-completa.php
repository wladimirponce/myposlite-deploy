<?php

declare(strict_types=1);

/**
 * Suite de Auditoría Integral End-to-End (12 Fases) — MyPOS Lite
 * Verifica UI/API + Integridad Matemática Interna en MariaDB:
 *   1. Alta de productos con foto (PNG real), presentaciones (Unidad/Pack x6), kg y exento
 *   2. Digitalización de factura XML con proveedor NUEVO (auto-creación) + Guía vinculada a Factura (sin doble stock)
 *   3. Ventas multi-vendedor (Admin + Vendedor), multi-medio de pago y descuento FEFO de stock
 *   4. Venta en stock negativo (-3) y posterior carga/recepción (+10 => +7 exacto)
 *   5. Inventario físico, ajustes, mermas e invariante contable SUM(movimientos) == saldo
 *   6. Devolución parcial con reintegro en efectivo y recuperación de stock + bloqueo de sobre-devolución
 *   7. Movimientos de caja (ingreso/retiro), cuadratura matemática del arqueo y Cierre Diario multi-vendedor
 *   8. Gastos fijos/honorarios y Cuentas Corrientes (Fiado, tope de crédito, abono y saldo)
 *   9. Cuadratura peso a peso de Informes y KPIs (Ventas Netas, Utilidad Bruta neta de devoluciones, Utilidad Neta Real, Flujo, IVA)
 *  10. Idempotencia (reintento de clave en venta y caja sin duplicar)
 *  11. Aislamiento multi-empresa y permisos por rol (vendedor sin acceso a informes/gastos)
 *  12. Promoción activa aplicada en venta + verificación de margen real
 */

require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/src/AccesoMaestros.php';
require_once __DIR__ . '/../backend/src/CatalogoProducto.php';
require_once __DIR__ . '/../backend/src/Inventario.php';
require_once __DIR__ . '/../backend/src/Recepcion.php';
require_once __DIR__ . '/../backend/src/Digitalizacion.php';
require_once __DIR__ . '/../backend/src/Ventas.php';
require_once __DIR__ . '/../backend/src/CierreDiario.php';
require_once __DIR__ . '/../backend/src/Gastos.php';
require_once __DIR__ . '/../backend/src/CuentasCorrientes.php';
require_once __DIR__ . '/../backend/src/Informes.php';
require_once __DIR__ . '/../backend/src/Promociones.php';

$db = conectarBaseLite();
$passed = 0;
$failed = 0;
$errores = [];

function check(bool $cond, string $label, string $detail = ''): void
{
    global $passed, $failed, $errores;
    if ($cond) {
        $passed++;
        echo "  [OK] $label\n";
    } else {
        $failed++;
        $msg = $detail !== '' ? "$label -> $detail" : $label;
        $errores[] = $msg;
        echo "  [FALLO] $msg\n";
    }
}

function rutConDv(int $num): string
{
    $s = 0;
    foreach (str_split(strrev((string) $num)) as $i => $d) {
        $s += (int) $d * ($i % 6 + 2);
    }
    $r = 11 - $s % 11;
    $dv = match ($r) { 11 => '0', 10 => 'K', default => (string) $r };
    return $num . $dv;
}

// Crear empresa aislada de auditoría
$seed = random_int(71000000, 75999999);
$rutEmpresa = rutConDv($seed);
$rutEmpresa2 = rutConDv($seed + 1);
$rutProvNuevo = rutConDv($seed + 2);
$rutClienteFiado = rutConDv($seed + 3);
$hoy = (new DateTimeImmutable('today', new DateTimeZone('America/Santiago')))->format('Y-m-d');
$vencePronto = (new DateTimeImmutable('today +10 days', new DateTimeZone('America/Santiago')))->format('Y-m-d');
$venceLejos = (new DateTimeImmutable('today +60 days', new DateTimeZone('America/Santiago')))->format('Y-m-d');

$db->prepare("INSERT INTO empresas (rut, razon_social, zona_horaria, es_demo, activa) VALUES (?, 'Comercio Auditoria E2E SpA', 'America/Santiago', 1, 1)")
    ->execute([$rutEmpresa]);
$empresa = (string) $db->lastInsertId();

$db->prepare("INSERT INTO empresas (rut, razon_social, zona_horaria, es_demo, activa) VALUES (?, 'Otra Empresa Aislada SpA', 'America/Santiago', 1, 1)")
    ->execute([$rutEmpresa2]);
$empresa2 = (string) $db->lastInsertId();

$db->prepare("INSERT INTO locales (empresa_id, codigo, nombre) VALUES (?, 'casa-matriz', 'Casa Matriz'), (?, 'sucursal-2', 'Sucursal 2')")
    ->execute([$empresa, $empresa]);
$db->prepare("INSERT INTO cajas (empresa_id, local_codigo, codigo, nombre) VALUES (?, 'casa-matriz', 'caja-1', 'Caja 1')")
    ->execute([$empresa]);

$hash = password_hash('AuditPass2026!', PASSWORD_DEFAULT);
$db->prepare("INSERT INTO usuarios (correo, nombre, clave_hash, activo) VALUES (?, 'Admin Auditor', ?, 1)")
    ->execute(["admin-{$seed}@audit.test", $hash]);
$adminId = (string) $db->lastInsertId();

$db->prepare("INSERT INTO usuarios (correo, nombre, clave_hash, activo) VALUES (?, 'Vendedor Dos', ?, 1)")
    ->execute(["vendedor-{$seed}@audit.test", $hash]);
$vendId = (string) $db->lastInsertId();

$db->prepare("INSERT INTO membresias (empresa_id, usuario_id, rol_codigo, activa) VALUES (?, ?, 'administrador', 1), (?, ?, 'vendedor', 1)")
    ->execute([$empresa, $adminId, $empresa, $vendId]);
$db->prepare("INSERT INTO membresias_locales (empresa_id, usuario_id, local_codigo) VALUES (?, ?, 'casa-matriz')")
    ->execute([$empresa, $vendId]);
$db->prepare("INSERT INTO membresias_cajas (empresa_id, usuario_id, local_codigo, caja_codigo) VALUES (?, ?, 'casa-matriz', 'caja-1')")
    ->execute([$empresa, $vendId]);

$permAdmin = permisosMaestros($db, $adminId, $empresa, 'casa-matriz');
$permVend = permisosMaestros($db, $vendId, $empresa, 'casa-matriz');

echo "=== FASE 1: Alta de Productos con Foto, Presentaciones, Kg y Exento ===\n";
$imgDir = __DIR__ . '/../storage/productos';
if (!is_dir($imgDir)) {
    mkdir($imgDir, 0775, true);
}
// Generar un PNG válido real de 1x1 pixel y guardarlo como foto del producto
$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$imgHash = hash('sha256', $pngBytes);
$imgFile = "{$empresa}_{$imgHash}.png";
file_put_contents($imgDir . '/' . $imgFile, $pngBytes);
$imgUrl = '/api/producto-imagen.php?f=' . $imgFile;

$prodBebida = guardarProducto($db, $empresa, 'casa-matriz', $adminId, [
    'sku' => 'AUD-BEB-01',
    'nombre' => 'Bebida Cola Auditoría 1,5 L',
    'unidadBase' => 'unidad',
    'afectoIva' => true,
    'verificarEdad' => false,
    'categoria' => 'Bebidas',
    'costo' => '1000',
    'margenMinimo' => '20',
    'minimoLocal' => '5',
    'imagenUrl' => $imgUrl,
    'presentaciones' => [
        ['nombre' => 'Unidad', 'codigoBarras' => '7809990000011', 'equivalencia' => '1', 'precio' => '2380'],
        ['nombre' => 'Pack x6', 'codigoBarras' => '7809990000066', 'equivalencia' => '6', 'precio' => '13090'],
    ],
]);
$prodPan = guardarProducto($db, $empresa, 'casa-matriz', $adminId, [
    'sku' => 'AUD-PAN-KG',
    'nombre' => 'Pan Marraqueta Fresco',
    'unidadBase' => 'kg',
    'afectoIva' => true,
    'verificarEdad' => false,
    'categoria' => 'Panadería',
    'costo' => '1200',
    'minimoLocal' => '3',
    'presentaciones' => [
        ['nombre' => 'Kilo', 'codigoBarras' => '7809990000022', 'equivalencia' => '1', 'precio' => '2380'],
    ],
]);
$prodNeg = guardarProducto($db, $empresa, 'casa-matriz', $adminId, [
    'sku' => 'AUD-NEG-01',
    'nombre' => 'Café Grano Stock Negativo',
    'unidadBase' => 'unidad',
    'afectoIva' => false,
    'verificarEdad' => false,
    'categoria' => 'Cafetería',
    'costo' => '3000',
    'presentaciones' => [
        ['nombre' => 'Unidad', 'codigoBarras' => '7809990000033', 'equivalencia' => '1', 'precio' => '5000'],
    ],
]);

$presBebida = $db->prepare('SELECT id, nombre FROM producto_presentaciones WHERE empresa_id = ? AND producto_id = ? ORDER BY equivalencia');
$presBebida->execute([$empresa, $prodBebida]);
$pbRows = $presBebida->fetchAll(PDO::FETCH_ASSOC);
$presBebidaUn = (string) $pbRows[0]['id'];
$presBebidaPack = (string) $pbRows[1]['id'];

$presPan = (string) $db->query("SELECT id FROM producto_presentaciones WHERE empresa_id = $empresa AND producto_id = $prodPan LIMIT 1")->fetchColumn();
$presNeg = (string) $db->query("SELECT id FROM producto_presentaciones WHERE empresa_id = $empresa AND producto_id = $prodNeg LIMIT 1")->fetchColumn();

$imgGuardada = (string) $db->query("SELECT imagen_url FROM productos WHERE id = $prodBebida")->fetchColumn();
check($imgGuardada === $imgUrl && is_file($imgDir . '/' . $imgFile), 'Producto creado con foto persistida en BD y disco');
check(count($pbRows) === 2, 'Producto con 2 presentaciones (Unidad y Pack x6)');

echo "\n=== FASE 2: Digitalización XML (Auto-creación Proveedor Nuevo) + Guía/Factura Sin Doble Stock ===\n";
$rutProvFmt = substr($rutProvNuevo, 0, -1) . '-' . substr($rutProvNuevo, -1);
$rutEmpFmt = substr($rutEmpresa, 0, -1) . '-' . substr($rutEmpresa, -1);
$xmlFactura = <<<XML
<?xml version="1.0" encoding="ISO-8859-1"?>
<DTE version="1.0" xmlns="http://www.sii.cl/SiiDte">
  <Documento ID="F9001">
    <Encabezado>
      <IdDoc><TipoDTE>33</TipoDTE><Folio>9001</Folio><FchEmis>{$hoy}</FchEmis></IdDoc>
      <Emisor><RUTEmisor>{$rutProvFmt}</RUTEmisor><RznSoc>Distribuidora Nueva E2E Ltda</RznSoc></Emisor>
      <Receptor><RUTRecep>{$rutEmpFmt}</RUTRecep><RznSocRecep>Comercio Auditoria E2E SpA</RznSocRecep></Receptor>
      <Totales><MntNeto>20000</MntNeto><IVA>3800</IVA><MntTotal>23800</MntTotal></Totales>
    </Encabezado>
    <Detalle>
      <NroLinDet>1</NroLinDet>
      <CdgItem><TpoCodigo>INT1</TpoCodigo><VlrCodigo>AUD-BEB-01</VlrCodigo></CdgItem>
      <NmbItem>Bebida Cola Auditoria 1,5 L</NmbItem>
      <QtyItem>20</QtyItem>
      <PrcItem>1000</PrcItem>
      <MontoItem>20000</MontoItem>
    </Detalle>
  </Documento>
</DTE>
XML;

// Simular archivo recibido + lectura XML directa con leerDocumentoXmlDte + borradorLectura (auto-creación proveedor)
$leidoXml = leerDocumentoXmlDte($xmlFactura, $rutEmpresa);
$db->prepare("INSERT INTO archivos_recibidos (empresa_id, local_codigo, huella, nombre, tipo_mime, tamano, usuario_id) VALUES (?, 'casa-matriz', ?, 'factura-9001.xml', 'application/xml', ?, ?)")
    ->execute([$empresa, hash('sha256', $xmlFactura), strlen($xmlFactura), $adminId]);
$archivoId = (string) $db->lastInsertId();
$db->prepare("INSERT INTO lecturas_documento (empresa_id, archivo_id, estado, modelo, resultado, usuario_id, terminada_en) VALUES (?, ?, 'lista', 'xml-dte', ?, ?, CURRENT_TIMESTAMP(6))")
    ->execute([$empresa, $archivoId, json_encode($leidoXml['resultado'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $adminId]);
$lecturaId = (string) $db->lastInsertId();

$borrador = borradorLectura($db, $empresa, 'casa-matriz', $lecturaId);
check($borrador['proveedorCreado'] === true && $borrador['proveedorId'] !== null, 'Al digitalizar factura, proveedor inexistente se creó automáticamente en contactos');
$provId = (string) $borrador['proveedorId'];

// Ingresar Guía 52 primero (10 unidades con vencimiento próximo) y luego Factura 33 vinculada a esa Guía (sin doble stock!)
$guiaRes = registrarDocumentoRecibido($db, $empresa, 'casa-matriz', $adminId, [
    'clave' => 'aud_guia_' . bin2hex(random_bytes(8)),
    'tipo' => 52,
    'proveedorId' => $provId,
    'folio' => '7001',
    'fecha' => $hoy,
    'montoTotal' => '11900',
    'lineas' => [
        ['presentacionId' => $presBebidaUn, 'cantidad' => '10', 'recibida' => '10', 'precioUnitario' => '1000', 'vencimiento' => $vencePronto],
    ],
]);
$saldoTrasGuia = (float) $db->query("SELECT saldo FROM stock_saldos WHERE empresa_id = $empresa AND local_codigo = 'casa-matriz' AND producto_id = $prodBebida")->fetchColumn();
check($saldoTrasGuia == 10.0, 'Guía 52 ingresó 10 unidades de Bebida al stock', "saldo=$saldoTrasGuia");

// Vincular Factura 33 a la Guía 52 (recibida=0 porque ya entró con la guía: no debe volver a sumar 10!)
registrarDocumentoRecibido($db, $empresa, 'casa-matriz', $adminId, [
    'clave' => 'aud_fact_vinc_' . bin2hex(random_bytes(8)),
    'tipo' => 33,
    'proveedorId' => $provId,
    'folio' => '9001',
    'fecha' => $hoy,
    'montoTotal' => '11900',
    'guias' => [$guiaRes['id']],
    'lecturaId' => $lecturaId,
    'lineas' => [
        ['presentacionId' => $presBebidaUn, 'cantidad' => '10', 'recibida' => '0', 'precioUnitario' => '1000'],
    ],
]);
$saldoTrasFacturaVinc = (float) $db->query("SELECT saldo FROM stock_saldos WHERE empresa_id = $empresa AND local_codigo = 'casa-matriz' AND producto_id = $prodBebida")->fetchColumn();
check($saldoTrasFacturaVinc == 10.0, 'Factura 33 vinculada a Guía 52 NO duplicó stock', "saldo=$saldoTrasFacturaVinc");

// Recibir 2° lote de Bebida (20 unidades vencimiento lejano a costo $1.000) y 10 kg de Pan
registrarDocumentoRecibido($db, $empresa, 'casa-matriz', $adminId, [
    'clave' => 'aud_fact_directa_' . bin2hex(random_bytes(8)),
    'tipo' => 33,
    'proveedorId' => $provId,
    'folio' => '9002',
    'fecha' => $hoy,
    'montoTotal' => '38080',
    'lineas' => [
        ['presentacionId' => $presBebidaUn, 'cantidad' => '20', 'recibida' => '20', 'precioUnitario' => '1000', 'vencimiento' => $venceLejos],
        ['presentacionId' => $presPan, 'cantidad' => '10', 'recibida' => '10', 'precioUnitario' => '1200'],
    ],
]);
$saldoBebida = (float) $db->query("SELECT saldo FROM stock_saldos WHERE empresa_id = $empresa AND local_codigo = 'casa-matriz' AND producto_id = $prodBebida")->fetchColumn();
$saldoPan = (float) $db->query("SELECT saldo FROM stock_saldos WHERE empresa_id = $empresa AND local_codigo = 'casa-matriz' AND producto_id = $prodPan")->fetchColumn();
check($saldoBebida == 30.0 && $saldoPan == 10.0, 'Factura directa aumentó stock exacto (Bebida=30, Pan=10 kg)', "Bebida=$saldoBebida, Pan=$saldoPan");

echo "\n=== FASE 3: Ventas Multi-Vendedor, Multi-Pago y Descuento FEFO ===\n";
$turno = abrirTurno($db, $empresa, 'casa-matriz', $adminId, $permAdmin, ['caja' => 'caja-1', 'montoInicial' => 50000]);

// Venta 1 (Admin): 1 Pack x6 Bebida ($13.090) en Efectivo
$v1Clave = 'aud_v1_' . bin2hex(random_bytes(8));
$v1 = registrarVenta($db, $empresa, 'casa-matriz', $adminId, $permAdmin, [
    'caja' => 'caja-1',
    'clave' => $v1Clave,
    'lineas' => [['presentacionId' => $presBebidaPack, 'cantidad' => '1']],
    'pagos' => [['medio' => 'efectivo', 'monto' => 13090, 'recibido' => 15000]],
]);

// Venta 2 (Vendedor Dos): 6 unidades Bebida ($14.280) + 1.5 kg Pan ($3.570) = $17.850 (Pago mixto: $7.850 Efectivo + $10.000 Tarjeta)
$v2 = registrarVenta($db, $empresa, 'casa-matriz', $vendId, $permVend, [
    'caja' => 'caja-1',
    'clave' => 'aud_v2_' . bin2hex(random_bytes(8)),
    'lineas' => [
        ['presentacionId' => $presBebidaUn, 'cantidad' => '6'],
        ['presentacionId' => $presPan, 'cantidad' => '1.5'],
    ],
    'pagos' => [
        ['medio' => 'efectivo', 'monto' => 7850, 'recibido' => 10000],
        ['medio' => 'tarjeta', 'monto' => 10000],
    ],
]);

// Verificar stock tras 6 (pack) + 6 (unidades) = 12 bebidas vendidas => 30 - 12 = 18; Pan => 10 - 1.5 = 8.5
$saldoBebida = (float) $db->query("SELECT saldo FROM stock_saldos WHERE empresa_id = $empresa AND local_codigo = 'casa-matriz' AND producto_id = $prodBebida")->fetchColumn();
$saldoPan = (float) $db->query("SELECT saldo FROM stock_saldos WHERE empresa_id = $empresa AND local_codigo = 'casa-matriz' AND producto_id = $prodPan")->fetchColumn();
check($saldoBebida == 18.0 && abs($saldoPan - 8.5) < 0.0001, 'Ventas multi-vendedor descontaron stock exacto (Bebida=18, Pan=8.5 kg)', "Bebida=$saldoBebida, Pan=$saldoPan");

// Verificar FEFO: el lote que vence primero (10 u.) debe quedar en 0 y el lote lejano (20 u.) en 18!
$lotes = $db->query("SELECT vencimiento, saldo FROM stock_lotes WHERE empresa_id = $empresa AND local_codigo = 'casa-matriz' AND producto_id = $prodBebida ORDER BY vencimiento")->fetchAll(PDO::FETCH_ASSOC);
check((float) $lotes[0]['saldo'] == 0.0 && (float) $lotes[1]['saldo'] == 18.0, 'Consumo FEFO correcto (lote próximo=0, lote lejano=18)', json_encode($lotes));

echo "\n=== FASE 4: Venta en Stock Negativo y Posterior Reposición ===\n";
$vNeg = registrarVenta($db, $empresa, 'casa-matriz', $vendId, $permVend, [
    'caja' => 'caja-1',
    'clave' => 'aud_vneg_' . bin2hex(random_bytes(8)),
    'lineas' => [['presentacionId' => $presNeg, 'cantidad' => '3']],
    'pagos' => [['medio' => 'transferencia', 'monto' => 15000]],
]);
$saldoNeg1 = (float) $db->query("SELECT saldo FROM stock_saldos WHERE empresa_id = $empresa AND local_codigo = 'casa-matriz' AND producto_id = $prodNeg")->fetchColumn();
check($saldoNeg1 == -3.0, 'Venta sin stock permitida dejando saldo negativo exacto (-3)', "saldo=$saldoNeg1");

// Cargar +10 unidades por compra
registrarDocumentoRecibido($db, $empresa, 'casa-matriz', $adminId, [
    'clave' => 'aud_fact_neg_' . bin2hex(random_bytes(8)),
    'tipo' => 33,
    'proveedorId' => $provId,
    'folio' => '9003',
    'fecha' => $hoy,
    'montoTotal' => '35700',
    'lineas' => [['presentacionId' => $presNeg, 'cantidad' => '10', 'recibida' => '10', 'precioUnitario' => '3000']],
]);
$saldoNeg2 = (float) $db->query("SELECT saldo FROM stock_saldos WHERE empresa_id = $empresa AND local_codigo = 'casa-matriz' AND producto_id = $prodNeg")->fetchColumn();
check($saldoNeg2 == 7.0, 'Reposición tras stock negativo cuadra matemáticamente (-3 + 10 = +7)', "saldo=$saldoNeg2");

echo "\n=== FASE 5: Inventario Físico, Ajustes, Mermas e Invariante Global ===\n";
registrarOperacionInventario($db, $empresa, 'casa-matriz', $adminId, [
    'clave' => 'aud_aj_ent_' . bin2hex(random_bytes(8)),
    'tipo' => 'ajuste_entrada',
    'causa' => 'Conteo físico sobrante',
    'lineas' => [['presentacionId' => $presBebidaUn, 'cantidad' => '2']],
]);
registrarOperacionInventario($db, $empresa, 'casa-matriz', $adminId, [
    'clave' => 'aud_merma_' . bin2hex(random_bytes(8)),
    'tipo' => 'merma',
    'causa' => 'Botellas rotas en bodega',
    'lineas' => [['presentacionId' => $presBebidaUn, 'cantidad' => '3']],
]);
// Bebida: 18 + 2 - 3 = 17
$saldoBebida = (float) $db->query("SELECT saldo FROM stock_saldos WHERE empresa_id = $empresa AND local_codigo = 'casa-matriz' AND producto_id = $prodBebida")->fetchColumn();
check($saldoBebida == 17.0, 'Ajuste (+2) y merma (-3) dejan Bebida en 17 exacto', "saldo=$saldoBebida");

// Invariante contable: SUM(stock_movimientos.cantidad) == stock_saldos.saldo para TODOS los productos
$descuadresStock = $db->query("SELECT s.producto_id, s.saldo, COALESCE(SUM(m.cantidad), 0) AS suma_mov
    FROM stock_saldos s
    LEFT JOIN stock_movimientos m ON m.empresa_id = s.empresa_id AND m.local_codigo = s.local_codigo AND m.producto_id = s.producto_id
    WHERE s.empresa_id = $empresa
    GROUP BY s.producto_id, s.saldo
    HAVING ABS(s.saldo - COALESCE(SUM(m.cantidad), 0)) > 0.0001")->fetchAll(PDO::FETCH_ASSOC);
check(count($descuadresStock) === 0, 'Invariante global de inventario: SUM(movimientos) == stock_saldos para todos los SKUs', json_encode($descuadresStock));

echo "\n=== FASE 6: Devolución Parcial (Caja + Stock Cuadrados) y Bloqueo Sobre-devolución ===\n";
// Devolver 2 unidades de Bebida de Venta 2 (precio unitario $2.380 => $4.760) en Efectivo con devolución física
$dev1 = devolverVenta($db, $empresa, 'casa-matriz', $adminId, $permAdmin, [
    'venta' => $v2['id'],
    'caja' => 'caja-1',
    'clave' => 'aud_dev1_' . bin2hex(random_bytes(8)),
    'motivo' => 'Cliente llevó de más',
    'medio' => 'efectivo',
    'fisica' => true,
    'lineas' => [['linea' => 1, 'cantidad' => '2']],
]);
$saldoBebida = (float) $db->query("SELECT saldo FROM stock_saldos WHERE empresa_id = $empresa AND local_codigo = 'casa-matriz' AND producto_id = $prodBebida")->fetchColumn();
check($saldoBebida == 19.0 && $dev1['monto'] === 4760, 'Devolución física recuperó +2 unidades (Bebida=19) y reembolsó $4.760', "saldo=$saldoBebida, monto={$dev1['monto']}");

$bloqueoSobreDev = false;
try {
    devolverVenta($db, $empresa, 'casa-matriz', $adminId, $permAdmin, [
        'venta' => $v2['id'],
        'caja' => 'caja-1',
        'clave' => 'aud_dev_bad_' . bin2hex(random_bytes(8)),
        'motivo' => 'Intento exceso',
        'medio' => 'efectivo',
        'fisica' => true,
        'lineas' => [['linea' => 1, 'cantidad' => '5']], // solo quedan 4 por devolver!
    ]);
} catch (Throwable) {
    $bloqueoSobreDev = true;
}
check($bloqueoSobreDev, 'Sistema bloquea devolver más cantidad de la vendida');

echo "\n=== FASE 7: Ingresos/Retiros de Caja, Arqueo Cuadrado y Cierre Diario Multi-Vendedor ===\n";
$movIngClave = 'aud_ing_' . bin2hex(random_bytes(8));
registrarMovimientoCaja($db, $empresa, 'casa-matriz', $adminId, $permAdmin, [
    'caja' => 'caja-1', 'clave' => $movIngClave, 'tipo' => 'ingreso', 'monto' => 10000, 'causa' => 'Sencillo para vuelto',
]);
registrarMovimientoCaja($db, $empresa, 'casa-matriz', $adminId, $permAdmin, [
    'caja' => 'caja-1', 'clave' => 'aud_ret_' . bin2hex(random_bytes(8)), 'tipo' => 'retiro', 'monto' => 15000, 'causa' => 'Pago flete local',
]);

// Efectivo esperado exacto: 50000 (inicial) + 13090 (v1) + 7850 (v2 efec) + 10000 (ingreso) - 15000 (retiro) - 4760 (devolucion efec) = 61180
$esperadoTeorico = 50000 + 13090 + 7850 + 10000 - 15000 - 4760;
$cierreTurno = cerrarTurno($db, $empresa, 'casa-matriz', $adminId, $permAdmin, [
    'caja' => 'caja-1',
    'efectivoContado' => $esperadoTeorico,
]);
check($cierreTurno['efectivoEsperado'] === $esperadoTeorico && $cierreTurno['diferencia'] === 0,
    "Arqueo de caja cuadrado al peso (esperado=\$$esperadoTeorico, diferencia=\$0)", json_encode($cierreTurno));

$diaObj = jornadaCierre($db, $empresa, $hoy);
$totCierre = totalesCierre($db, $empresa, 'casa-matriz', $diaObj);
check(count($totCierre['vendedores']) === 2, 'Cierre diario desglosa ventas de los 2 vendedores distintos', json_encode($totCierre['vendedores']));
check($totCierre['total'] === (13090 + 17850 + 15000), 'Cierre diario cuadra total bruto de ventas ($45.940)', "total={$totCierre['total']}");

echo "\n=== FASE 8: Gastos Fijos / Honorarios y Cuentas Corrientes (Fiado) ===\n";
registrarGastoOperacional($db, $empresa, 'casa-matriz', $adminId, [
    'fecha' => $hoy, 'categoria' => 'arriendo', 'descripcion' => 'Arriendo mes prueba', 'monto' => 20000, 'medio' => 'transferencia',
]);
registrarGastoOperacional($db, $empresa, 'casa-matriz', $adminId, [
    'fecha' => $hoy, 'categoria' => 'honorarios', 'descripcion' => 'Boleta honorarios contador', 'monto' => 5000, 'medio' => 'transferencia', 'documentoRef' => 'BH-501',
]);

$cliFiado = crearClienteCuentaCorriente($db, $empresa, $adminId, [
    'nombre' => 'Cliente Fiado E2E', 'rut' => $rutClienteFiado, 'limiteCredito' => 50000,
]);
registrarMovimientoCuentaCorriente($db, $empresa, 'casa-matriz', $adminId, [
    'contactoId' => $cliFiado['contactoId'], 'tipo' => 'cargo', 'monto' => 30000, 'descripcion' => 'Fiado abarrotes',
]);
$excesoBloqueado = false;
try {
    registrarMovimientoCuentaCorriente($db, $empresa, 'casa-matriz', $adminId, [
        'contactoId' => $cliFiado['contactoId'], 'tipo' => 'cargo', 'monto' => 25000, 'descripcion' => 'Excede tope',
    ]);
} catch (RuntimeException $e) {
    $excesoBloqueado = ($e->getCode() === 409);
}
check($excesoBloqueado, 'Cuenta corriente rechaza cargo que excede límite de crédito (HTTP 409)');

$abonoRes = registrarMovimientoCuentaCorriente($db, $empresa, 'casa-matriz', $adminId, [
    'contactoId' => $cliFiado['contactoId'], 'tipo' => 'abono', 'monto' => 10000, 'medio' => 'efectivo', 'descripcion' => 'Abono parcial',
]);
check($abonoRes['saldo'] === 20000, 'Saldo final cuenta corriente tras cargo $30k y abono $10k == $20.000', "saldo={$abonoRes['saldo']}");

echo "\n=== FASE 9: Auditoría Matemática de Informes, KPIs y Ganancias ===\n";
$rango = rangoInforme($db, $empresa, $hoy, $hoy);
$inf = generarInformeCompleto($db, $empresa, ['casa-matriz'], $rango['desde'], $rango['hasta'], $rango['dias'], $rango['zona'], $rango['hoy']);

// Ventas Brutas: 13090 + 17850 + 15000 = 45940; Devoluciones: 4760; Ventas Netas: 41180
check($inf['kpis']['ventasTotal'] === 45940 && $inf['kpis']['ventasNetas'] === 41180,
    'KPI Ventas Brutas ($45.940) y Ventas Netas ($41.180) exactos', json_encode([$inf['kpis']['ventasTotal'], $inf['kpis']['ventasNetas']]));

// Rentabilidad neta de devoluciones:
// - V1: 1 Pack (6 beb): venta neta = 13090/1.19 = 11000, costo = 6*1000 = 6000 => util = 5000
// - V2: 4 beb netas (6-2 dev): venta neta = (4*2380)/1.19 = 8000, costo = 4*1000 = 4000 => util = 4000
// - V2: 1.5 kg pan: venta neta = 3570/1.19 = 3000, costo = 1.5*1200 = 1800 => util = 1200
// - VNeg: 3 café exento: venta neta = 15000, costo = 3*3000 = 9000 => util = 6000
// Total Utilidad Bruta exacta = 5000 + 4000 + 1200 + 6000 = 16200!
// Gastos Operacionales = 20000 + 5000 = 25000 => Utilidad Neta Real = 16200 - 25000 = -8800!
check($inf['kpis']['utilidadBruta'] === 16200,
    'Utilidad Bruta descuenta devoluciones y cuadra al peso exacto ($16.200)', "got={$inf['kpis']['utilidadBruta']}");
check($inf['kpis']['gastosOperacionales'] === 25000 && $inf['kpis']['utilidadNetaEstimada'] === -8800,
    'Utilidad Neta Real Est. cuadra exacto (16.200 - 25.000 = -$8.800)', "gastos={$inf['kpis']['gastosOperacionales']}, neta={$inf['kpis']['utilidadNetaEstimada']}");

// Compras totales: Factura 9001 ($11.900, guía 7001 vinculada no suma) + Factura 9002 ($38.080) + Factura 9003 ($35.700) = 85680
check($inf['kpis']['comprasTotal'] === 85680, 'KPI Compras Totales sin duplicar guía vinculada == $85.680', "got={$inf['kpis']['comprasTotal']}");
check($inf['inventario']['mermasCosto'] === 3000, 'KPI Mermas Costo (3 bebidas x $1.000) == $3.000', "got={$inf['inventario']['mermasCosto']}");
check($inf['cuentasPorCobrar']['totalPorCobrar'] === 20000, 'KPI Cuentas por Cobrar (Fiado) == $20.000', "got={$inf['cuentasPorCobrar']['totalPorCobrar']}");

echo "\n=== FASE 10: Idempotencia (Reintento de Clave sin Duplicar) ===\n";
$v1Dup = registrarVenta($db, $empresa, 'casa-matriz', $adminId, $permAdmin, [
    'caja' => 'caja-1',
    'clave' => $v1Clave,
    'lineas' => [['presentacionId' => $presBebidaPack, 'cantidad' => '1']],
    'pagos' => [['medio' => 'efectivo', 'monto' => 13090, 'recibido' => 15000]],
]);
$saldoTrasDup = (float) $db->query("SELECT saldo FROM stock_saldos WHERE empresa_id = $empresa AND local_codigo = 'casa-matriz' AND producto_id = $prodBebida")->fetchColumn();
check($v1Dup['repetida'] === true && $saldoTrasDup == 19.0, 'Reintento de venta con misma clave es 100% idempotente (no duplica ni descuenta stock)');

echo "\n=== FASE 11: Aislamiento Multi-Empresa y Permisos por Rol ===\n";
$infEmp2 = generarInformeCompleto($db, $empresa2, ['casa-matriz'], $rango['desde'], $rango['hasta'], $rango['dias'], $rango['zona'], $rango['hoy']);
check($infEmp2['kpis']['ventasTotal'] === 0 && $infEmp2['kpis']['comprasTotal'] === 0 && $infEmp2['cuentasPorCobrar']['totalPorCobrar'] === 0,
    'Aislamiento multi-empresa verificado (Empresa 2 ve $0 de Empresa 1)');
$vendPuedeInformes = in_array('caja.supervisar', $permVend, true) || in_array('operacion.consultar', $permVend, true);
check($vendPuedeInformes === false, 'Rol vendedor bloqueado de Informes, Ganancias y Gastos Fijos');

echo "\n=== FASE 12: Promoción 2x1 Aplicada en Venta ===\n";
$turno2 = abrirTurno($db, $empresa, 'casa-matriz', $adminId, $permAdmin, ['caja' => 'caja-1', 'montoInicial' => 0]);
$db->prepare("INSERT INTO promociones (empresa_id, presentacion_id, local_codigo, nombre, tipo, cantidad, desde, hasta, activa)
    VALUES (?, ?, 'casa-matriz', 'Promo 2x1 Café', '2x1', 2, ?, ?, 1)")
    ->execute([$empresa, $presNeg, $hoy, $hoy]);
$vPromo = registrarVenta($db, $empresa, 'casa-matriz', $adminId, $permAdmin, [
    'caja' => 'caja-1',
    'clave' => 'aud_vpromo_' . bin2hex(random_bytes(8)),
    'lineas' => [['presentacionId' => $presNeg, 'cantidad' => '2']],
    'pagos' => [['medio' => 'efectivo', 'monto' => 5000]],
]);
check($vPromo['total'] === 5000, 'Promoción 2x1 aplicada automáticamente en caja (2 cafés x $5.000 = $5.000)', "total={$vPromo['total']}");

echo "\n==================================================\n";
echo "RESULTADO AUDITORÍA INTEGRAL: {$passed} OK / {$failed} FALLOS\n";
echo "==================================================\n";

// Limpiar datos de prueba de la empresa aislada para no ensuciar la BD local
@unlink($imgDir . '/' . $imgFile);
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ([
    'cuenta_corriente_movimientos', 'cuentas_corrientes', 'gastos_operacionales', 'promociones',
    'devolucion_lineas', 'devoluciones', 'venta_pagos', 'venta_lineas', 'ventas',
    'caja_movimientos', 'turnos', 'cierres_diarios',
    'stock_consumos', 'stock_lotes', 'stock_movimientos', 'stock_saldos', 'stock_operaciones',
    'documento_guias', 'documento_recibido_lineas', 'documentos_recibidos',
    'lecturas_documento', 'archivos_recibidos',
    'producto_eventos', 'producto_minimos', 'producto_presentaciones', 'productos', 'categorias_producto',
    'contacto_eventos', 'contactos', 'membresias_cajas', 'membresias_locales', 'membresias',
    'cajas', 'locales',
] as $tbl) {
    $db->prepare("DELETE FROM $tbl WHERE empresa_id IN (?, ?)")->execute([$empresa, $empresa2]);
}
$db->prepare('DELETE FROM empresas WHERE id IN (?, ?)')->execute([$empresa, $empresa2]);
$db->prepare('DELETE FROM usuarios WHERE id IN (?, ?)')->execute([$adminId, $vendId]);
$db->exec('SET FOREIGN_KEY_CHECKS=1');

exit($failed > 0 ? 1 : 0);
