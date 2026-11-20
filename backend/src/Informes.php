<?php

declare(strict_types=1);

require_once __DIR__ . '/CierreDiario.php';

/**
 * Motor de informes de gestión y KPIs para MyPOS Lite.
 * Calcula métricas comerciales, flujo de caja, rentabilidad, inventario, mermas,
 * demanda no satisfecha (faltas) y balance tributario estimado (IVA / DTE)
 * a partir de los datos reales de la empresa y los locales autorizados.
 */

/**
 * Valida y resuelve el rango de fechas comerciales en la zona horaria de la empresa.
 *
 * @return array{desde: DateTimeImmutable, hasta: DateTimeImmutable, dias: int, zona: DateTimeZone, hoy: string}
 */
function rangoInforme(PDO $db, string $empresa, ?string $desdeParam, ?string $hastaParam): array
{
    $zona = zonaCierre($db, $empresa);
    $hoy = new DateTimeImmutable('today', $zona);

    if ($hastaParam === null || trim($hastaParam) === '') {
        $hasta = $hoy;
    } else {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($hastaParam), $zona);
        if (!$parsed || $parsed->format('Y-m-d') !== trim($hastaParam)) {
            throw new InvalidArgumentException('Fecha de término inválida.');
        }
        $hasta = $parsed > $hoy ? $hoy : $parsed;
    }

    if ($desdeParam === null || trim($desdeParam) === '') {
        $desde = $hasta->modify('-6 days');
    } else {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($desdeParam), $zona);
        if (!$parsed || $parsed->format('Y-m-d') !== trim($desdeParam)) {
            throw new InvalidArgumentException('Fecha de inicio inválida.');
        }
        $desde = $parsed;
    }

    if ($desde > $hasta) {
        throw new InvalidArgumentException('La fecha de inicio no puede ser posterior a la fecha de término.');
    }

    $dias = (int) $desde->diff($hasta)->days + 1;
    if ($dias > 366) {
        throw new InvalidArgumentException('El rango máximo de consulta es de 366 días.');
    }

    return [
        'desde' => $desde,
        'hasta' => $hasta,
        'dias' => $dias,
        'zona' => $zona,
        'hoy' => $hoy->format('Y-m-d'),
    ];
}

/**
 * Convierte un rango de fechas comerciales [desde, hasta] inclusive a límites UTC [inicio, fin) para consultas DATETIME.
 *
 * @return array{inicioUtc: string, finUtc: string, desdeSql: string, hastaSql: string}
 */
function limitesUtcInforme(DateTimeImmutable $desde, DateTimeImmutable $hasta): array
{
    $utc = new DateTimeZone('UTC');
    return [
        'inicioUtc' => $desde->setTime(0, 0, 0)->setTimezone($utc)->format('Y-m-d H:i:s'),
        'finUtc' => $hasta->modify('+1 day')->setTime(0, 0, 0)->setTimezone($utc)->format('Y-m-d H:i:s'),
        'desdeSql' => $desde->format('Y-m-d'),
        'hastaSql' => $hasta->format('Y-m-d'),
    ];
}

/**
 * Genera placeholders SQL (?, ?, ...) para cláusulas IN sobre locales.
 *
 * @param list<string> $locales
 */
function inPlaceholders(array $locales): string
{
    return implode(',', array_fill(0, count($locales), '?'));
}

/**
 * Calcula variación porcentual segura entre período actual y período anterior.
 */
function variacionPct(float $actual, float $anterior): ?float
{
    if ($anterior == 0.0) {
        return $actual == 0.0 ? 0.0 : null;
    }
    return round((($actual - $anterior) / abs($anterior)) * 100, 1);
}

/**
 * Resumen compacto de ventas, devoluciones, compras y retiros para un rango dado (usado para período actual y comparación).
 *
 * @param list<string> $locales
 * @return array<string, mixed>
 */
function resumenPeriodoBase(PDO $db, string $empresa, array $locales, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
{
    $lim = limitesUtcInforme($desde, $hasta);
    $in = inPlaceholders($locales);

    // 1. Ventas
    $stmtVentas = $db->prepare("SELECT COUNT(*) AS cantidad,
            COALESCE(SUM(total), 0) AS total,
            COALESCE(SUM(neto), 0) AS neto,
            COALESCE(SUM(iva), 0) AS iva,
            COALESCE(SUM(exento), 0) AS exento
        FROM ventas
        WHERE empresa_id = ? AND local_codigo IN ($in) AND creada_en >= ? AND creada_en < ?");
    $stmtVentas->execute([$empresa, ...$locales, $lim['inicioUtc'], $lim['finUtc']]);
    $v = $stmtVentas->fetch(PDO::FETCH_ASSOC) ?: [];
    $ventasCantidad = (int) ($v['cantidad'] ?? 0);
    $ventasTotal = (int) ($v['total'] ?? 0);
    $ventasNeto = (int) ($v['neto'] ?? 0);
    $ventasIva = (int) ($v['iva'] ?? 0);
    $ventasExento = (int) ($v['exento'] ?? 0);

    // 2. Devoluciones
    $stmtDev = $db->prepare("SELECT COUNT(*) AS cantidad, COALESCE(SUM(d.monto), 0) AS total
        FROM devoluciones d
        JOIN turnos t ON t.empresa_id = d.empresa_id AND t.id = d.turno_id
        WHERE d.empresa_id = ? AND t.local_codigo IN ($in) AND d.creada_en >= ? AND d.creada_en < ?");
    $stmtDev->execute([$empresa, ...$locales, $lim['inicioUtc'], $lim['finUtc']]);
    $dev = $stmtDev->fetch(PDO::FETCH_ASSOC) ?: [];
    $devolucionesCantidad = (int) ($dev['cantidad'] ?? 0);
    $devolucionesTotal = (int) ($dev['total'] ?? 0);

    // 3. Rentabilidad estimada por líneas de venta (cruzando con costo neto actual del producto)
    $stmtRent = $db->prepare("SELECT
            COALESCE(SUM(CASE WHEN p.costo IS NOT NULL THEN
                (CASE WHEN vl.exento = 1 THEN vl.monto ELSE vl.monto / 1.19 END)
            ELSE 0 END), 0) AS venta_neta_con_costo,
            COALESCE(SUM(CASE WHEN p.costo IS NOT NULL THEN
                (vl.cantidad * vl.equivalencia * p.costo)
            ELSE 0 END), 0) AS costo_total,
            COALESCE(SUM(CASE WHEN vl.exento = 1 THEN vl.monto ELSE vl.monto / 1.19 END), 0) AS venta_neta_total,
            COALESCE(SUM(vl.cantidad), 0) AS unidades_vendidas
        FROM venta_lineas vl
        JOIN ventas v ON v.empresa_id = vl.empresa_id AND v.id = vl.venta_id
        LEFT JOIN productos p ON p.empresa_id = vl.empresa_id AND p.id = vl.producto_id
        WHERE vl.empresa_id = ? AND v.local_codigo IN ($in) AND v.creada_en >= ? AND v.creada_en < ?");
    $stmtRent->execute([$empresa, ...$locales, $lim['inicioUtc'], $lim['finUtc']]);
    $r = $stmtRent->fetch(PDO::FETCH_ASSOC) ?: [];
    $ventaNetaConCosto = (float) ($r['venta_neta_con_costo'] ?? 0);
    $costoTotal = (float) ($r['costo_total'] ?? 0);
    $ventaNetaTotal = (float) ($r['venta_neta_total'] ?? 0);
    $utilidadBruta = (int) round($ventaNetaConCosto - $costoTotal);
    $margenPct = $ventaNetaConCosto > 0 ? round((($ventaNetaConCosto - $costoTotal) / $ventaNetaConCosto) * 100, 1) : null;
    $coberturaCostoPct = $ventaNetaTotal > 0 ? round(($ventaNetaConCosto / $ventaNetaTotal) * 100, 1) : 0.0;

    // 4. Movimientos de caja (ingresos extra y retiros/egresos)
    $stmtCaja = $db->prepare("SELECT m.tipo, COUNT(*) AS cantidad, COALESCE(SUM(m.monto), 0) AS total
        FROM caja_movimientos m
        JOIN turnos t ON t.empresa_id = m.empresa_id AND t.id = m.turno_id
        WHERE m.empresa_id = ? AND t.local_codigo IN ($in) AND m.creado_en >= ? AND m.creado_en < ?
        GROUP BY m.tipo");
    $stmtCaja->execute([$empresa, ...$locales, $lim['inicioUtc'], $lim['finUtc']]);
    $ingresosCaja = 0;
    $retirosCaja = 0;
    foreach ($stmtCaja->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['tipo'] === 'ingreso') {
            $ingresosCaja = (int) $row['total'];
        } elseif ($row['tipo'] === 'retiro') {
            $retirosCaja = (int) $row['total'];
        }
    }

    // 5. Compras a proveedores (Facturas 33/34 + Notas de débito 56 + Guías 52 no vinculadas a factura - Notas de crédito 61)
    $stmtCompras = $db->prepare("SELECT
            COUNT(*) AS cantidad_docs,
            COALESCE(SUM(CASE
                WHEN d.tipo IN (33, 34, 56) THEN d.monto_total
                WHEN d.tipo = 52 AND NOT EXISTS (
                    SELECT 1 FROM documento_guias g WHERE g.empresa_id = d.empresa_id AND g.guia_id = d.id
                ) THEN d.monto_total
                WHEN d.tipo = 61 THEN -d.monto_total
                ELSE 0
            END), 0) AS total_compras,
            COALESCE(SUM(CASE WHEN d.tipo IN (33, 56) THEN d.monto_total ELSE 0 END), 0) AS compras_afectas,
            COALESCE(SUM(CASE WHEN d.tipo = 61 THEN d.monto_total ELSE 0 END), 0) AS nc_recibidas
        FROM documentos_recibidos d
        WHERE d.empresa_id = ? AND d.local_codigo IN ($in) AND d.fecha >= ? AND d.fecha <= ?");
    $stmtCompras->execute([$empresa, ...$locales, $lim['desdeSql'], $lim['hastaSql']]);
    $c = $stmtCompras->fetch(PDO::FETCH_ASSOC) ?: [];
    $comprasTotal = (int) round((float) ($c['total_compras'] ?? 0));
    $comprasDocs = (int) ($c['cantidad_docs'] ?? 0);
    $comprasAfectas = (int) round((float) ($c['compras_afectas'] ?? 0));
    $ncRecibidas = (int) round((float) ($c['nc_recibidas'] ?? 0));

    // 6. Descuadre acumulado en turnos cerrados
    $stmtTurnos = $db->prepare("SELECT COUNT(*) AS turnos_cerrados,
            SUM(CASE WHEN diferencia <> 0 THEN 1 ELSE 0 END) AS turnos_con_descuadre,
            COALESCE(SUM(diferencia), 0) AS descuadre_total
        FROM turnos
        WHERE empresa_id = ? AND local_codigo IN ($in) AND cerrado_en IS NOT NULL AND cerrado_en >= ? AND cerrado_en < ?");
    $stmtTurnos->execute([$empresa, ...$locales, $lim['inicioUtc'], $lim['finUtc']]);
    $tr = $stmtTurnos->fetch(PDO::FETCH_ASSOC) ?: [];

    $ventasNetasComerciales = $ventasTotal - $devolucionesTotal;
    $ticketPromedio = $ventasCantidad > 0 ? (int) round($ventasTotal / $ventasCantidad) : 0;
    $egresosTotales = $comprasTotal + $retirosCaja;
    $flujoNeto = ($ventasNetasComerciales + $ingresosCaja) - $egresosTotales;

    return [
        'ventasCantidad' => $ventasCantidad,
        'ventasTotal' => $ventasTotal,
        'ventasNetas' => $ventasNetasComerciales,
        'ventasNeto' => $ventasNeto,
        'ventasIva' => $ventasIva,
        'ventasExento' => $ventasExento,
        'ticketPromedio' => $ticketPromedio,
        'devolucionesCantidad' => $devolucionesCantidad,
        'devolucionesTotal' => $devolucionesTotal,
        'utilidadBruta' => $utilidadBruta,
        'costoVendido' => (int) round($costoTotal),
        'margenPct' => $margenPct,
        'coberturaCostoPct' => $coberturaCostoPct,
        'unidadesVendidas' => round((float) ($r['unidades_vendidas'] ?? 0), 2),
        'ingresosCaja' => $ingresosCaja,
        'retirosCaja' => $retirosCaja,
        'comprasTotal' => $comprasTotal,
        'comprasDocs' => $comprasDocs,
        'comprasAfectas' => $comprasAfectas,
        'ncRecibidas' => $ncRecibidas,
        'egresosTotales' => $egresosTotales,
        'flujoNeto' => $flujoNeto,
        'turnosCerrados' => (int) ($tr['turnos_cerrados'] ?? 0),
        'turnosConDescuadre' => (int) ($tr['turnos_con_descuadre'] ?? 0),
        'descuadreTotal' => (int) ($tr['descuadre_total'] ?? 0),
    ];
}

/**
 * Genera el informe completo de KPIs y gestión para una empresa y conjunto de locales.
 *
 * @param list<string> $locales
 * @return array<string, mixed>
 */
function generarInformeCompleto(PDO $db, string $empresa, array $locales, DateTimeImmutable $desde, DateTimeImmutable $hasta, int $dias, DateTimeZone $zona, string $hoy): array
{
    if ($locales === []) {
        throw new InvalidArgumentException('No hay locales autorizados para consultar.');
    }

    $lim = limitesUtcInforme($desde, $hasta);
    $in = inPlaceholders($locales);
    $utc = new DateTimeZone('UTC');

    // Período actual y período anterior equivalente
    $actual = resumenPeriodoBase($db, $empresa, $locales, $desde, $hasta);
    $prevHasta = $desde->modify('-1 day');
    $prevDesde = $prevHasta->modify('-' . ($dias - 1) . ' days');
    $anterior = resumenPeriodoBase($db, $empresa, $locales, $prevDesde, $prevHasta);

    $comparacion = [
        'desdeAnterior' => $prevDesde->format('Y-m-d'),
        'hastaAnterior' => $prevHasta->format('Y-m-d'),
        'ventasNetasPct' => variacionPct((float) $actual['ventasNetas'], (float) $anterior['ventasNetas']),
        'ventasCantidadPct' => variacionPct((float) $actual['ventasCantidad'], (float) $anterior['ventasCantidad']),
        'ticketPromedioPct' => variacionPct((float) $actual['ticketPromedio'], (float) $anterior['ticketPromedio']),
        'utilidadBrutaPct' => variacionPct((float) $actual['utilidadBruta'], (float) $anterior['utilidadBruta']),
        'egresosPct' => variacionPct((float) $actual['egresosTotales'], (float) $anterior['egresosTotales']),
        'flujoNetoPct' => variacionPct((float) $actual['flujoNeto'], (float) $anterior['flujoNeto']),
    ];

    // Evolución diaria (ventas y compras por fecha comercial)
    $serieMapa = [];
    $cursor = $desde;
    while ($cursor <= $hasta) {
        $f = $cursor->format('Y-m-d');
        $serieMapa[$f] = ['fecha' => $f, 'ventas' => 0, 'cantidad' => 0, 'compras' => 0, 'retiros' => 0];
        $cursor = $cursor->modify('+1 day');
    }

    $stmtVentasDia = $db->prepare("SELECT creada_en, total FROM ventas
        WHERE empresa_id = ? AND local_codigo IN ($in) AND creada_en >= ? AND creada_en < ?");
    $stmtVentasDia->execute([$empresa, ...$locales, $lim['inicioUtc'], $lim['finUtc']]);

    $horasMapa = [];
    for ($h = 0; $h < 24; $h++) {
        $horasMapa[$h] = ['hora' => $h, 'etiqueta' => sprintf('%02d:00', $h), 'ventas' => 0, 'cantidad' => 0];
    }

    foreach ($stmtVentasDia->fetchAll(PDO::FETCH_ASSOC) as $vRow) {
        $dt = (new DateTimeImmutable((string) $vRow['creada_en'], $utc))->setTimezone($zona);
        $f = $dt->format('Y-m-d');
        $h = (int) $dt->format('G');
        $monto = (int) $vRow['total'];
        if (isset($serieMapa[$f])) {
            $serieMapa[$f]['ventas'] += $monto;
            $serieMapa[$f]['cantidad']++;
        }
        $horasMapa[$h]['ventas'] += $monto;
        $horasMapa[$h]['cantidad']++;
    }

    $stmtComprasDia = $db->prepare("SELECT d.fecha,
            COALESCE(SUM(CASE
                WHEN d.tipo IN (33, 34, 56) THEN d.monto_total
                WHEN d.tipo = 52 AND NOT EXISTS (
                    SELECT 1 FROM documento_guias g WHERE g.empresa_id = d.empresa_id AND g.guia_id = d.id
                ) THEN d.monto_total
                WHEN d.tipo = 61 THEN -d.monto_total
                ELSE 0
            END), 0) AS total
        FROM documentos_recibidos d
        WHERE d.empresa_id = ? AND d.local_codigo IN ($in) AND d.fecha >= ? AND d.fecha <= ?
        GROUP BY d.fecha");
    $stmtComprasDia->execute([$empresa, ...$locales, $lim['desdeSql'], $lim['hastaSql']]);
    foreach ($stmtComprasDia->fetchAll(PDO::FETCH_ASSOC) as $cRow) {
        $f = (string) $cRow['fecha'];
        if (isset($serieMapa[$f])) {
            $serieMapa[$f]['compras'] = (int) round((float) $cRow['total']);
        }
    }

    $stmtRetirosDia = $db->prepare("SELECT m.creado_en, m.monto FROM caja_movimientos m
        JOIN turnos t ON t.empresa_id = m.empresa_id AND t.id = m.turno_id
        WHERE m.empresa_id = ? AND t.local_codigo IN ($in) AND m.tipo = 'retiro' AND m.creado_en >= ? AND m.creado_en < ?");
    $stmtRetirosDia->execute([$empresa, ...$locales, $lim['inicioUtc'], $lim['finUtc']]);
    foreach ($stmtRetirosDia->fetchAll(PDO::FETCH_ASSOC) as $rRow) {
        $dt = (new DateTimeImmutable((string) $rRow['creado_en'], $utc))->setTimezone($zona);
        $f = $dt->format('Y-m-d');
        if (isset($serieMapa[$f])) {
            $serieMapa[$f]['retiros'] += (int) $rRow['monto'];
        }
    }

    // Medios de pago
    $stmtPagos = $db->prepare("SELECT vp.medio, COUNT(DISTINCT vp.venta_id) AS ventas, COALESCE(SUM(vp.monto), 0) AS total
        FROM venta_pagos vp
        JOIN ventas v ON v.empresa_id = vp.empresa_id AND v.id = vp.venta_id
        WHERE vp.empresa_id = ? AND v.local_codigo IN ($in) AND v.creada_en >= ? AND v.creada_en < ?
        GROUP BY vp.medio
        ORDER BY total DESC");
    $stmtPagos->execute([$empresa, ...$locales, $lim['inicioUtc'], $lim['finUtc']]);
    $totalPagos = 0;
    $mediosRaw = $stmtPagos->fetchAll(PDO::FETCH_ASSOC);
    foreach ($mediosRaw as $mp) {
        $totalPagos += (int) $mp['total'];
    }
    $mediosPago = array_map(static fn (array $mp): array => [
        'medio' => (string) $mp['medio'],
        'ventas' => (int) $mp['ventas'],
        'total' => (int) $mp['total'],
        'porcentaje' => $totalPagos > 0 ? round(((int) $mp['total'] / $totalPagos) * 100, 1) : 0.0,
    ], $mediosRaw);

    // Top 15 productos más vendidos
    $stmtTopProd = $db->prepare("SELECT vl.producto_id, MAX(vl.nombre) AS nombre, MAX(p.sku) AS sku,
            SUM(vl.cantidad) AS cantidad,
            SUM(vl.monto) AS total,
            SUM(CASE WHEN p.costo IS NOT NULL THEN (CASE WHEN vl.exento = 1 THEN vl.monto ELSE vl.monto / 1.19 END) ELSE 0 END) AS neta_con_costo,
            SUM(CASE WHEN p.costo IS NOT NULL THEN (vl.cantidad * vl.equivalencia * p.costo) ELSE 0 END) AS costo_total
        FROM venta_lineas vl
        JOIN ventas v ON v.empresa_id = vl.empresa_id AND v.id = vl.venta_id
        LEFT JOIN productos p ON p.empresa_id = vl.empresa_id AND p.id = vl.producto_id
        WHERE vl.empresa_id = ? AND v.local_codigo IN ($in) AND v.creada_en >= ? AND v.creada_en < ?
        GROUP BY vl.producto_id
        ORDER BY total DESC
        LIMIT 15");
    $stmtTopProd->execute([$empresa, ...$locales, $lim['inicioUtc'], $lim['finUtc']]);
    $topProductos = array_map(static function (array $row): array {
        $neta = (float) $row['neta_con_costo'];
        $costo = (float) $row['costo_total'];
        return [
            'id' => (string) $row['producto_id'],
            'sku' => (string) ($row['sku'] ?? ''),
            'nombre' => (string) $row['nombre'],
            'cantidad' => round((float) $row['cantidad'], 2),
            'total' => (int) $row['total'],
            'utilidad' => $neta > 0 ? (int) round($neta - $costo) : null,
            'margenPct' => $neta > 0 ? round((($neta - $costo) / $neta) * 100, 1) : null,
        ];
    }, $stmtTopProd->fetchAll(PDO::FETCH_ASSOC));

    // Ventas por categoría
    $stmtCat = $db->prepare("SELECT COALESCE(c.nombre, 'Sin categoría') AS categoria,
            SUM(vl.cantidad) AS cantidad,
            SUM(vl.monto) AS total,
            SUM(CASE WHEN p.costo IS NOT NULL THEN (CASE WHEN vl.exento = 1 THEN vl.monto ELSE vl.monto / 1.19 END) ELSE 0 END) AS neta_con_costo,
            SUM(CASE WHEN p.costo IS NOT NULL THEN (vl.cantidad * vl.equivalencia * p.costo) ELSE 0 END) AS costo_total
        FROM venta_lineas vl
        JOIN ventas v ON v.empresa_id = vl.empresa_id AND v.id = vl.venta_id
        LEFT JOIN productos p ON p.empresa_id = vl.empresa_id AND p.id = vl.producto_id
        LEFT JOIN categorias_producto c ON c.empresa_id = p.empresa_id AND c.id = p.categoria_id
        WHERE vl.empresa_id = ? AND v.local_codigo IN ($in) AND v.creada_en >= ? AND v.creada_en < ?
        GROUP BY COALESCE(c.nombre, 'Sin categoría')
        ORDER BY total DESC
        LIMIT 12");
    $stmtCat->execute([$empresa, ...$locales, $lim['inicioUtc'], $lim['finUtc']]);
    $catRaw = $stmtCat->fetchAll(PDO::FETCH_ASSOC);
    $totalCat = array_sum(array_map(static fn (array $r): int => (int) $r['total'], $catRaw));
    $ventasPorCategoria = array_map(static function (array $row) use ($totalCat): array {
        $neta = (float) $row['neta_con_costo'];
        $costo = (float) $row['costo_total'];
        return [
            'categoria' => (string) $row['categoria'],
            'cantidad' => round((float) $row['cantidad'], 2),
            'total' => (int) $row['total'],
            'porcentaje' => $totalCat > 0 ? round(((int) $row['total'] / $totalCat) * 100, 1) : 0.0,
            'margenPct' => $neta > 0 ? round((($neta - $costo) / $neta) * 100, 1) : null,
        ];
    }, $catRaw);

    // Productos con margen bajo (< margen_minimo o < 12%)
    $stmtMargenBajo = $db->prepare("SELECT p.id, p.sku, p.nombre, p.costo, p.margen_minimo, p.afecto_iva,
            pp.nombre AS presentacion, pp.precio, pp.equivalencia
        FROM productos p
        JOIN producto_presentaciones pp ON pp.empresa_id = p.empresa_id AND pp.producto_id = p.id
        WHERE p.empresa_id = ? AND p.costo IS NOT NULL AND p.costo > 0 AND pp.precio > 0
        ORDER BY p.nombre
        LIMIT 500");
    $stmtMargenBajo->execute([$empresa]);
    $alertasMargen = [];
    foreach ($stmtMargenBajo->fetchAll(PDO::FETCH_ASSOC) as $pm) {
        $precioNeto = (float) $pm['precio'] / ((int) $pm['afecto_iva'] === 1 ? 1.19 : 1.0);
        $costoPres = (float) $pm['costo'] * (float) $pm['equivalencia'];
        if ($precioNeto <= 0) {
            continue;
        }
        $margen = (($precioNeto - $costoPres) / $precioNeto) * 100;
        $umbral = $pm['margen_minimo'] !== null ? (float) $pm['margen_minimo'] : 12.0;
        if ($margen < $umbral) {
            $alertasMargen[] = [
                'id' => (string) $pm['id'],
                'sku' => (string) $pm['sku'],
                'nombre' => (string) $pm['nombre'],
                'presentacion' => (string) $pm['presentacion'],
                'precio' => (int) $pm['precio'],
                'costoPres' => (int) round($costoPres),
                'margenPct' => round($margen, 1),
                'margenMinimo' => $pm['margen_minimo'] !== null ? round((float) $pm['margen_minimo'], 1) : null,
            ];
        }
    }
    usort($alertasMargen, static fn (array $a, array $b): int => $a['margenPct'] <=> $b['margenPct']);
    $alertasMargen = array_slice($alertasMargen, 0, 15);

    // Productos sin movimiento (tienen stock > 0 pero 0 ventas en el período)
    $stmtSinMov = $db->prepare("SELECT p.id, p.sku, p.nombre, p.unidad_base, p.costo,
            SUM(ss.saldo) AS stock_total,
            COALESCE(MIN(pp.precio), 0) AS precio_ref
        FROM stock_saldos ss
        JOIN productos p ON p.empresa_id = ss.empresa_id AND p.id = ss.producto_id
        LEFT JOIN producto_presentaciones pp ON pp.empresa_id = p.empresa_id AND pp.producto_id = p.id
        WHERE ss.empresa_id = ? AND ss.local_codigo IN ($in) AND ss.saldo > 0
          AND NOT EXISTS (
              SELECT 1 FROM venta_lineas vl
              JOIN ventas v ON v.empresa_id = vl.empresa_id AND v.id = vl.venta_id
              WHERE vl.empresa_id = ss.empresa_id AND vl.producto_id = ss.producto_id
                AND v.local_codigo IN ($in) AND v.creada_en >= ? AND v.creada_en < ?
          )
        GROUP BY p.id, p.sku, p.nombre, p.unidad_base, p.costo
        ORDER BY (SUM(ss.saldo) * COALESCE(p.costo, 0)) DESC, SUM(ss.saldo) DESC
        LIMIT 15");
    $stmtSinMov->execute([$empresa, ...$locales, ...$locales, $lim['inicioUtc'], $lim['finUtc']]);
    $sinMovimiento = array_map(static fn (array $row): array => [
        'id' => (string) $row['id'],
        'sku' => (string) $row['sku'],
        'nombre' => (string) $row['nombre'],
        'unidad' => (string) $row['unidad_base'],
        'stock' => round((float) $row['stock_total'], 2),
        'valorCosto' => $row['costo'] !== null ? (int) round((float) $row['stock_total'] * (float) $row['costo']) : null,
        'precioRef' => (int) $row['precio_ref'],
    ], $stmtSinMov->fetchAll(PDO::FETCH_ASSOC));

    // Ventas por vendedor
    $stmtVend = $db->prepare("SELECT u.nombre, COUNT(*) AS cantidad, COALESCE(SUM(v.total), 0) AS total
        FROM ventas v
        JOIN usuarios u ON u.id = v.vendedor_id
        WHERE v.empresa_id = ? AND v.local_codigo IN ($in) AND v.creada_en >= ? AND v.creada_en < ?
        GROUP BY u.id, u.nombre
        ORDER BY total DESC");
    $stmtVend->execute([$empresa, ...$locales, $lim['inicioUtc'], $lim['finUtc']]);
    $vendedores = array_map(static function (array $row) use ($actual): array {
        $cant = (int) $row['cantidad'];
        $tot = (int) $row['total'];
        return [
            'nombre' => (string) $row['nombre'],
            'cantidad' => $cant,
            'total' => $tot,
            'ticketPromedio' => $cant > 0 ? (int) round($tot / $cant) : 0,
            'porcentaje' => $actual['ventasTotal'] > 0 ? round(($tot / $actual['ventasTotal']) * 100, 1) : 0.0,
        ];
    }, $stmtVend->fetchAll(PDO::FETCH_ASSOC));

    // Detalle de retiros/egresos de caja e ingresos manuales
    $stmtMovs = $db->prepare("SELECT m.tipo, m.causa, m.medio, m.monto, m.creado_en, u.nombre AS usuario, t.local_codigo
        FROM caja_movimientos m
        JOIN turnos t ON t.empresa_id = m.empresa_id AND t.id = m.turno_id
        JOIN usuarios u ON u.id = m.usuario_id
        WHERE m.empresa_id = ? AND t.local_codigo IN ($in) AND m.tipo IN ('ingreso', 'retiro')
          AND m.creado_en >= ? AND m.creado_en < ?
        ORDER BY m.creado_en DESC
        LIMIT 30");
    $stmtMovs->execute([$empresa, ...$locales, $lim['inicioUtc'], $lim['finUtc']]);
    $movimientosCaja = array_map(static function (array $row) use ($utc, $zona): array {
        $dt = (new DateTimeImmutable((string) $row['creado_en'], $utc))->setTimezone($zona);
        return [
            'tipo' => (string) $row['tipo'],
            'causa' => (string) $row['causa'],
            'medio' => (string) $row['medio'],
            'monto' => (int) $row['monto'],
            'fecha' => $dt->format('d-m-Y H:i'),
            'usuario' => (string) $row['usuario'],
            'local' => (string) $row['local_codigo'],
        ];
    }, $stmtMovs->fetchAll(PDO::FETCH_ASSOC));

    // Compras por proveedor
    $stmtProv = $db->prepare("SELECT c.razon_social AS proveedor, COUNT(*) AS documentos,
            COALESCE(SUM(CASE
                WHEN d.tipo IN (33, 34, 56) THEN d.monto_total
                WHEN d.tipo = 52 AND NOT EXISTS (
                    SELECT 1 FROM documento_guias g WHERE g.empresa_id = d.empresa_id AND g.guia_id = d.id
                ) THEN d.monto_total
                WHEN d.tipo = 61 THEN -d.monto_total
                ELSE 0
            END), 0) AS total
        FROM documentos_recibidos d
        JOIN contactos c ON c.empresa_id = d.empresa_id AND c.id = d.proveedor_id
        WHERE d.empresa_id = ? AND d.local_codigo IN ($in) AND d.fecha >= ? AND d.fecha <= ?
        GROUP BY c.id, c.razon_social
        ORDER BY total DESC
        LIMIT 12");
    $stmtProv->execute([$empresa, ...$locales, $lim['desdeSql'], $lim['hastaSql']]);
    $comprasPorProveedor = array_map(static fn (array $row): array => [
        'proveedor' => (string) $row['proveedor'],
        'documentos' => (int) $row['documentos'],
        'total' => (int) round((float) $row['total']),
    ], $stmtProv->fetchAll(PDO::FETCH_ASSOC));

    // Historial de arqueos de turnos cerrados
    $stmtArqueos = $db->prepare("SELECT t.id, t.local_codigo, c.nombre AS caja, u.nombre AS operador,
            t.cerrado_en, t.efectivo_esperado, t.efectivo_contado, t.diferencia
        FROM turnos t
        JOIN cajas c ON c.empresa_id = t.empresa_id AND c.local_codigo = t.local_codigo AND c.codigo = t.caja_codigo
        JOIN usuarios u ON u.id = COALESCE(t.cerrado_por, t.abierto_por)
        WHERE t.empresa_id = ? AND t.local_codigo IN ($in) AND t.cerrado_en IS NOT NULL
          AND t.cerrado_en >= ? AND t.cerrado_en < ?
        ORDER BY t.cerrado_en DESC
        LIMIT 20");
    $stmtArqueos->execute([$empresa, ...$locales, $lim['inicioUtc'], $lim['finUtc']]);
    $arqueos = array_map(static function (array $row) use ($utc, $zona): array {
        $dt = (new DateTimeImmutable((string) $row['cerrado_en'], $utc))->setTimezone($zona);
        return [
            'id' => (string) $row['id'],
            'local' => (string) $row['local_codigo'],
            'caja' => (string) $row['caja'],
            'operador' => (string) $row['operador'],
            'fecha' => $dt->format('d-m-Y H:i'),
            'esperado' => (int) ($row['efectivo_esperado'] ?? 0),
            'contado' => (int) ($row['efectivo_contado'] ?? 0),
            'diferencia' => (int) ($row['diferencia'] ?? 0),
        ];
    }, $stmtArqueos->fetchAll(PDO::FETCH_ASSOC));

    // Inventario actual: valorización, quiebres, bajo mínimo
    $stmtInv = $db->prepare("SELECT
            COUNT(DISTINCT p.id) AS total_skus,
            SUM(CASE WHEN COALESCE(ss.saldo, 0) > 0 THEN 1 ELSE 0 END) AS skus_con_stock,
            SUM(CASE WHEN COALESCE(ss.saldo, 0) <= 0 THEN 1 ELSE 0 END) AS skus_quiebre,
            SUM(CASE WHEN pm.minimo IS NOT NULL AND COALESCE(ss.saldo, 0) > 0 AND COALESCE(ss.saldo, 0) < pm.minimo THEN 1 ELSE 0 END) AS skus_bajo_minimo,
            COALESCE(SUM(CASE WHEN COALESCE(ss.saldo, 0) > 0 AND p.costo IS NOT NULL THEN ss.saldo * p.costo ELSE 0 END), 0) AS valor_costo,
            COALESCE(SUM(CASE WHEN COALESCE(ss.saldo, 0) > 0 THEN ss.saldo * COALESCE(pr.precio_base, 0) ELSE 0 END), 0) AS valor_venta
        FROM productos p
        JOIN (SELECT ? AS local_codigo" . str_repeat(' UNION ALL SELECT ?', count($locales) - 1) . ") loc
        LEFT JOIN stock_saldos ss ON ss.empresa_id = p.empresa_id AND ss.producto_id = p.id AND ss.local_codigo = loc.local_codigo
        LEFT JOIN producto_minimos pm ON pm.empresa_id = p.empresa_id AND pm.producto_id = p.id AND pm.local_codigo = loc.local_codigo
        LEFT JOIN (
            SELECT empresa_id, producto_id, MIN(precio / equivalencia) AS precio_base
            FROM producto_presentaciones
            WHERE empresa_id = ?
            GROUP BY empresa_id, producto_id
        ) pr ON pr.empresa_id = p.empresa_id AND pr.producto_id = p.id
        WHERE p.empresa_id = ?");
    $stmtInv->execute([...$locales, $empresa, $empresa]);
    $inv = $stmtInv->fetch(PDO::FETCH_ASSOC) ?: [];
    $valorCosto = (int) round((float) ($inv['valor_costo'] ?? 0));
    $valorVenta = (int) round((float) ($inv['valor_venta'] ?? 0));

    // Mermas en el período
    $stmtMerma = $db->prepare("SELECT COUNT(DISTINCT op.id) AS eventos,
            COALESCE(SUM(ABS(sm.cantidad)), 0) AS unidades,
            COALESCE(SUM(ABS(sm.cantidad) * COALESCE(p.costo, 0)), 0) AS costo_estimado
        FROM stock_operaciones op
        JOIN stock_movimientos sm ON sm.empresa_id = op.empresa_id AND sm.operacion_id = op.id
        LEFT JOIN productos p ON p.empresa_id = sm.empresa_id AND p.id = sm.producto_id
        WHERE op.empresa_id = ? AND op.local_codigo IN ($in) AND op.tipo = 'merma'
          AND op.registrada_en >= ? AND op.registrada_en < ?");
    $stmtMerma->execute([$empresa, ...$locales, $lim['inicioUtc'], $lim['finUtc']]);
    $merma = $stmtMerma->fetch(PDO::FETCH_ASSOC) ?: [];

    // Lotes vencidos y por vencer (próximos 30 días)
    $limiteVenc = (new DateTimeImmutable($hoy, $zona))->modify('+30 days')->format('Y-m-d');
    $stmtVenc = $db->prepare("SELECT
            SUM(CASE WHEN l.vencimiento < ? THEN 1 ELSE 0 END) AS vencidos,
            SUM(CASE WHEN l.vencimiento >= ? AND l.vencimiento <= ? THEN 1 ELSE 0 END) AS por_vencer_30d,
            COALESCE(SUM(CASE WHEN l.vencimiento <= ? THEN l.saldo * COALESCE(p.costo, 0) ELSE 0 END), 0) AS valor_riesgo
        FROM stock_lotes l
        LEFT JOIN productos p ON p.empresa_id = l.empresa_id AND p.id = l.producto_id
        WHERE l.empresa_id = ? AND l.local_codigo IN ($in) AND l.saldo > 0 AND l.vencimiento IS NOT NULL");
    $stmtVenc->execute([$hoy, $hoy, $limiteVenc, $limiteVenc, $empresa, ...$locales]);
    $venc = $stmtVenc->fetch(PDO::FETCH_ASSOC) ?: [];

    // Cuaderno de faltas (demanda no satisfecha)
    $faltasResumen = ['pendientes' => 0, 'pedidas' => 0, 'recibidas' => 0, 'top' => []];
    try {
        $stmtFaltasEst = $db->prepare("SELECT estado, COUNT(*) AS cantidad FROM faltas
            WHERE empresa_id = ? AND local_codigo IN ($in)
            GROUP BY estado");
        $stmtFaltasEst->execute([$empresa, ...$locales]);
        foreach ($stmtFaltasEst->fetchAll(PDO::FETCH_ASSOC) as $fr) {
            if ($fr['estado'] === 'pendiente') {
                $faltasResumen['pendientes'] = (int) $fr['cantidad'];
            } elseif ($fr['estado'] === 'pedido') {
                $faltasResumen['pedidas'] = (int) $fr['cantidad'];
            } elseif ($fr['estado'] === 'recibido') {
                $faltasResumen['recibidas'] = (int) $fr['cantidad'];
            }
        }
        $stmtFaltasTop = $db->prepare("SELECT descripcion, COUNT(*) AS veces, SUM(cantidad) AS unidades
            FROM faltas
            WHERE empresa_id = ? AND local_codigo IN ($in) AND estado IN ('pendiente', 'pedido')
            GROUP BY descripcion
            ORDER BY veces DESC, unidades DESC
            LIMIT 8");
        $stmtFaltasTop->execute([$empresa, ...$locales]);
        $faltasResumen['top'] = array_map(static fn (array $r): array => [
            'descripcion' => (string) $r['descripcion'],
            'veces' => (int) $r['veces'],
            'unidades' => round((float) $r['unidades'], 1),
        ], $stmtFaltasTop->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable) {
        // Si la tabla faltas aún no existe en algún entorno antiguo, no interrumpir el informe.
    }

    // Tributario: DTEs emitidos en el período y balance IVA estimado
    $stmtDte = $db->prepare("SELECT d.tipo, d.estado, COUNT(*) AS cantidad, COALESCE(SUM(d.monto_total), 0) AS monto
        FROM dte_documentos d
        LEFT JOIN ventas v ON v.empresa_id = d.empresa_id AND v.documento_id = d.id
        LEFT JOIN devoluciones dev ON dev.empresa_id = d.empresa_id AND dev.documento_id = d.id
        LEFT JOIN turnos t ON t.empresa_id = dev.empresa_id AND t.id = dev.turno_id
        WHERE d.empresa_id = ? AND d.ambiente = 'produccion'
          AND d.fecha_emision >= ? AND d.fecha_emision <= ?
          AND COALESCE(d.local_codigo, v.local_codigo, t.local_codigo) IN ($in)
        GROUP BY d.tipo, d.estado
        ORDER BY d.tipo");
    $stmtDte->execute([$empresa, $lim['desdeSql'], $lim['hastaSql'], ...$locales]);
    $dtePorTipo = [];
    foreach ($stmtDte->fetchAll(PDO::FETCH_ASSOC) as $dr) {
        $tipo = (int) $dr['tipo'];
        $dtePorTipo[$tipo] ??= ['tipo' => $tipo, 'total' => 0, 'monto' => 0, 'aceptados' => 0, 'pendientes' => 0, 'rechazados' => 0];
        $cant = (int) $dr['cantidad'];
        $dtePorTipo[$tipo]['total'] += $cant;
        $dtePorTipo[$tipo]['monto'] += (int) $dr['monto'];
        if (in_array($dr['estado'], ['aceptado', 'reparo'], true)) {
            $dtePorTipo[$tipo]['aceptados'] += $cant;
        } elseif ($dr['estado'] === 'rechazado') {
            $dtePorTipo[$tipo]['rechazados'] += $cant;
        } else {
            $dtePorTipo[$tipo]['pendientes'] += $cant;
        }
    }

    // IVA Débito estimado (IVA de ventas POS - IVA de devoluciones proporcional) y IVA Crédito estimado (19% de compras afectas tipo 33/56 - NC 61)
    $ivaDebito = $actual['ventasIva'];
    $ivaDevoluciones = (int) round($actual['devolucionesTotal'] - ($actual['devolucionesTotal'] / 1.19));
    $ivaDebitoNeto = max(0, $ivaDebito - $ivaDevoluciones);
    $comprasNetasAfectas = max(0, $actual['comprasAfectas'] - $actual['ncRecibidas']);
    $ivaCreditoEstimado = (int) round($comprasNetasAfectas - ($comprasNetasAfectas / 1.19));
    $ivaBalanceEstimado = $ivaDebitoNeto - $ivaCreditoEstimado;

    return [
        'periodo' => [
            'desde' => $lim['desdeSql'],
            'hasta' => $lim['hastaSql'],
            'dias' => $dias,
            'hoy' => $hoy,
        ],
        'kpis' => $actual,
        'comparacion' => $comparacion,
        'serieDiaria' => array_values($serieMapa),
        'horasPunta' => array_values($horasMapa),
        'mediosPago' => $mediosPago,
        'topProductos' => $topProductos,
        'ventasPorCategoria' => $ventasPorCategoria,
        'alertasMargen' => $alertasMargen,
        'sinMovimiento' => $sinMovimiento,
        'vendedores' => $vendedores,
        'movimientosCaja' => $movimientosCaja,
        'comprasPorProveedor' => $comprasPorProveedor,
        'arqueos' => $arqueos,
        'inventario' => [
            'totalSkus' => (int) ($inv['total_skus'] ?? 0),
            'skusConStock' => (int) ($inv['skus_con_stock'] ?? 0),
            'skusQuiebre' => (int) ($inv['skus_quiebre'] ?? 0),
            'skusBajoMinimo' => (int) ($inv['skus_bajo_minimo'] ?? 0),
            'valorCosto' => $valorCosto,
            'valorVenta' => $valorVenta,
            'gananciaPotencial' => max(0, $valorVenta - $valorCosto),
            'mermasEventos' => (int) ($merma['eventos'] ?? 0),
            'mermasUnidades' => round((float) ($merma['unidades'] ?? 0), 2),
            'mermasCosto' => (int) round((float) ($merma['costo_estimado'] ?? 0)),
            'lotesVencidos' => (int) ($venc['vencidos'] ?? 0),
            'lotesPorVencer30d' => (int) ($venc['por_vencer_30d'] ?? 0),
            'valorRiesgoVencimiento' => (int) round((float) ($venc['valor_riesgo'] ?? 0)),
        ],
        'faltas' => $faltasResumen,
        'tributario' => [
            'ivaDebito' => $ivaDebitoNeto,
            'ivaCredito' => $ivaCreditoEstimado,
            'ivaBalance' => $ivaBalanceEstimado,
            'documentos' => array_values($dtePorTipo),
        ],
    ];
}
