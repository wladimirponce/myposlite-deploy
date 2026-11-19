<?php

declare(strict_types=1);

require_once __DIR__ . '/Inventario.php';
require_once __DIR__ . '/Emision.php';
require_once __DIR__ . '/Recepcion.php';
require_once __DIR__ . '/Promociones.php';

use MyposLite\Dte\ConstructorBoleta;
use MyposLite\Dte\ConstructorDte;

const MEDIOS_PAGO = ['efectivo', 'tarjeta', 'transferencia'];

function claveOperacion(mixed $clave): string
{
    if (!is_string($clave) || !preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $clave)) {
        throw new InvalidArgumentException('Identificador de operación inválido.');
    }
    return $clave;
}

function pesos(mixed $valor, string $campo): int
{
    if (!is_int($valor) || $valor < 0 || $valor > 999999999999) {
        throw new InvalidArgumentException("Ingrese $campo en pesos enteros.");
    }
    return $valor;
}

/**
 * La caja debe existir en el local activo. Quien no supervisa cajas solo opera las que tiene asignadas.
 */
function exigirCaja(PDO $db, string $empresa, string $local, string $usuario, array $permisos, mixed $caja): string
{
    if (!is_string($caja) || $caja === '' || strlen($caja) > 24) {
        throw new InvalidArgumentException('Seleccione la caja.');
    }
    $existe = $db->prepare('SELECT 1 FROM cajas WHERE empresa_id = ? AND local_codigo = ? AND codigo = ?');
    $existe->execute([$empresa, $local, $caja]);
    if ($existe->fetchColumn() === false) {
        throw new RuntimeException('La caja no existe en este local.', 404);
    }
    if (!in_array('caja.supervisar', $permisos, true)) {
        $asignada = $db->prepare('SELECT 1 FROM membresias_cajas WHERE empresa_id = ? AND usuario_id = ? AND local_codigo = ? AND caja_codigo = ?');
        $asignada->execute([$empresa, $usuario, $local, $caja]);
        if ($asignada->fetchColumn() === false) {
            throw new RuntimeException('No tiene asignada esta caja.', 403);
        }
    }
    return $caja;
}

/** @return array<string, mixed>|null */
function turnoAbierto(PDO $db, string $empresa, string $local, string $caja, bool $bloquear = false): ?array
{
    $query = $db->prepare('SELECT * FROM turnos WHERE empresa_id = ? AND local_codigo = ? AND caja_codigo = ? AND abierto = 1'
        . ($bloquear ? ' FOR UPDATE' : ''));
    $query->execute([$empresa, $local, $caja]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

function abrirTurno(PDO $db, string $empresa, string $local, string $usuario, array $permisos, array $input): array
{
    require_once __DIR__ . '/CierreDiario.php';
    $caja = exigirCaja($db, $empresa, $local, $usuario, $permisos, $input['caja'] ?? null);
    $monto = pesos($input['montoInicial'] ?? null, 'el monto inicial');
    $db->beginTransaction();
    try {
        bloquearLocalCierre($db, $empresa, $local);
        if (leerCierre($db, $empresa, $local, jornadaCierre($db, $empresa, null)->format('Y-m-d')) !== null) {
            throw new RuntimeException('La jornada de este local ya está cerrada.', 409);
        }
        $db->prepare('INSERT INTO turnos (empresa_id, local_codigo, caja_codigo, monto_inicial, abierto_por) VALUES (?, ?, ?, ?, ?)')
            ->execute([$empresa, $local, $caja, $monto, $usuario]);
        $id = (string) $db->lastInsertId();
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
            throw new RuntimeException('Esta caja ya tiene un turno abierto.', 409, $error);
        }
        throw $error;
    }
    return ['turno' => $id, 'caja' => $caja, 'montoInicial' => $monto];
}

/** Ingreso o retiro de efectivo del turno, con causa. La clave evita registrarlo dos veces. */
function registrarMovimientoCaja(PDO $db, string $empresa, string $local, string $usuario, array $permisos, array $input): array
{
    $caja = exigirCaja($db, $empresa, $local, $usuario, $permisos, $input['caja'] ?? null);
    $clave = claveOperacion($input['clave'] ?? null);
    $tipo = $input['tipo'] ?? null;
    $monto = pesos($input['monto'] ?? null, 'el monto');
    $causa = is_string($input['causa'] ?? null) ? trim($input['causa']) : '';
    if (!in_array($tipo, ['ingreso', 'retiro'], true) || $monto === 0 || $causa === '' || mb_strlen($causa) > 200) {
        throw new InvalidArgumentException('Indique ingreso o retiro, un monto mayor que cero y la causa.');
    }
    $db->beginTransaction();
    try {
        $turno = turnoAbierto($db, $empresa, $local, $caja, true);
        if ($turno === null) {
            throw new RuntimeException('La caja no tiene un turno abierto.', 409);
        }
        $db->prepare('INSERT INTO caja_movimientos (empresa_id, turno_id, tipo, monto, causa, clave, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$empresa, $turno['id'], $tipo, $monto, $causa, $clave, $usuario]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if (!($error instanceof PDOException) || (int) ($error->errorInfo[1] ?? 0) !== 1062) {
            throw $error;
        }
        return ['repetido' => true];
    }
    return ['repetido' => false];
}

/**
 * Totales del turno: efectivo esperado en caja, ventas por medio de pago y por vendedor, y movimientos.
 * Efectivo esperado = monto inicial + cobros en efectivo − vuelto ya descontado + ingresos − retiros − devoluciones en efectivo.
 */
function resumenTurno(PDO $db, string $empresa, string $turno): array
{
    $datos = $db->prepare('SELECT t.*, u.nombre AS abierto_por_nombre FROM turnos t JOIN usuarios u ON u.id = t.abierto_por
        WHERE t.empresa_id = ? AND t.id = ?');
    $datos->execute([$empresa, $turno]);
    $fila = $datos->fetch(PDO::FETCH_ASSOC);
    if (!$fila) {
        throw new RuntimeException('El turno no existe.', 404);
    }
    $medios = $db->prepare('SELECT p.medio, SUM(p.monto) AS monto, COUNT(DISTINCT v.id) AS ventas FROM ventas v
        JOIN venta_pagos p ON p.empresa_id = v.empresa_id AND p.venta_id = v.id WHERE v.empresa_id = ? AND v.turno_id = ? GROUP BY p.medio');
    $medios->execute([$empresa, $turno]);
    $porMedio = array_fill_keys(MEDIOS_PAGO, 0);
    foreach ($medios->fetchAll(PDO::FETCH_ASSOC) as $medio) {
        $porMedio[$medio['medio']] = (int) $medio['monto'];
    }
    $vendedores = $db->prepare('SELECT u.nombre, COUNT(*) AS ventas, SUM(v.total) AS total FROM ventas v JOIN usuarios u ON u.id = v.vendedor_id
        WHERE v.empresa_id = ? AND v.turno_id = ? GROUP BY u.id, u.nombre ORDER BY u.nombre');
    $vendedores->execute([$empresa, $turno]);
    $movimientos = $db->prepare('SELECT m.tipo, m.medio, m.monto, m.causa, m.creado_en, u.nombre AS usuario FROM caja_movimientos m
        JOIN usuarios u ON u.id = m.usuario_id WHERE m.empresa_id = ? AND m.turno_id = ? ORDER BY m.id');
    $movimientos->execute([$empresa, $turno]);
    $lista = $movimientos->fetchAll(PDO::FETCH_ASSOC);
    $suma = static fn (string $tipo, ?string $medio = 'efectivo'): int => array_sum(array_map(static fn (array $m): int => (int) $m['monto'],
        array_filter($lista, static fn (array $m): bool => $m['tipo'] === $tipo && ($medio === null || $m['medio'] === $medio))));
    $esperado = (int) $fila['monto_inicial'] + $porMedio['efectivo'] + $suma('ingreso') - $suma('retiro') - $suma('devolucion');
    return ['turno' => (string) $fila['id'], 'caja' => $fila['caja_codigo'], 'abierto' => $fila['abierto'] !== null,
        'abiertoPor' => $fila['abierto_por_nombre'], 'abiertoEn' => $fila['abierto_en'], 'montoInicial' => (int) $fila['monto_inicial'],
        'ventasPorMedio' => $porMedio, 'devoluciones' => $suma('devolucion', null), 'ingresos' => $suma('ingreso'), 'retiros' => $suma('retiro'),
        'efectivoEsperado' => $fila['abierto'] !== null ? $esperado : (int) $fila['efectivo_esperado'],
        'efectivoContado' => $fila['efectivo_contado'] === null ? null : (int) $fila['efectivo_contado'],
        'diferencia' => $fila['diferencia'] === null ? null : (int) $fila['diferencia'],
        'vendedores' => array_map(static fn (array $v): array => ['nombre' => $v['nombre'], 'ventas' => (int) $v['ventas'], 'total' => (int) $v['total']],
            $vendedores->fetchAll(PDO::FETCH_ASSOC)),
        'movimientos' => $lista];
}

/** Arqueo y cierre: registra el efectivo contado y la diferencia con el esperado. */
function cerrarTurno(PDO $db, string $empresa, string $local, string $usuario, array $permisos, array $input): array
{
    $caja = exigirCaja($db, $empresa, $local, $usuario, $permisos, $input['caja'] ?? null);
    $contado = pesos($input['efectivoContado'] ?? null, 'el efectivo contado');
    $db->beginTransaction();
    try {
        $turno = turnoAbierto($db, $empresa, $local, $caja, true);
        if ($turno === null) {
            throw new RuntimeException('La caja no tiene un turno abierto.', 409);
        }
        $resumen = resumenTurno($db, $empresa, (string) $turno['id']);
        $db->prepare('UPDATE turnos SET abierto = NULL, efectivo_esperado = ?, efectivo_contado = ?, diferencia = ?, cerrado_por = ?,
            cerrado_en = CURRENT_TIMESTAMP(6) WHERE empresa_id = ? AND id = ?')
            ->execute([$resumen['efectivoEsperado'], $contado, $contado - $resumen['efectivoEsperado'], $usuario, $empresa, $turno['id']]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
    return resumenTurno($db, $empresa, (string) $turno['id']);
}

/**
 * Registra una venta completa o nada: venta, líneas, pagos, salida de stock y boleta, en una sola transacción e
 * identificada por la clave del cliente. Repetir la clave (doble clic, reintento tras corte) devuelve la misma venta.
 *
 * - Precios del servidor, con IVA incluido; los montos se calculan con la misma regla de la boleta.
 * - Productos con verificación de edad exigen la confirmación de quien vende, que queda registrado.
 * - El stock se descuenta en unidades base y puede quedar negativo, con aviso (decisión del 2026-11-17).
 * - La boleta se genera y guarda en la misma transacción con su folio; el envío al SII ocurre después y se reintenta
 *   sin generar otra. Sin boletas en producción la venta se rechaza, salvo en la empresa de demostración, que vende
 *   sin documento tributario.
 * - Los pagos deben sumar el total. El efectivo puede recibir más y se informa el vuelto.
 */
function registrarVenta(PDO $db, string $empresa, string $local, string $usuario, array $permisos, array $input): array
{
    $clave = claveOperacion($input['clave'] ?? null);
    $previa = $db->prepare('SELECT id FROM ventas WHERE empresa_id = ? AND clave = ?');
    $previa->execute([$empresa, $clave]);
    if ($id = $previa->fetchColumn()) {
        return detalleVenta($db, $empresa, (string) $id) + ['repetida' => true, 'avisos' => []];
    }
    $caja = exigirCaja($db, $empresa, $local, $usuario, $permisos, $input['caja'] ?? null);
    $lineas = $input['lineas'] ?? null;
    if (!is_array($lineas) || !array_is_list($lineas)) {
        throw new InvalidArgumentException('La venta requiere líneas.');
    }
    $zona = $db->prepare('SELECT zona_horaria FROM empresas WHERE id = ?');
    $zona->execute([$empresa]);
    $ahora = new DateTimeImmutable('now', new DateTimeZone((string) $zona->fetchColumn() ?: 'America/Santiago'));
    // Una venta hecha sin conexión se cobró con los precios de lista: sin promociones, para que lo cobrado cuadre.
    $contingencia = ($input['contingencia'] ?? false) === true;
    ['resueltas' => $resueltas, 'paraBoleta' => $paraBoleta, 'edad' => $edad, 'tipo' => $tipo, 'montos' => $montos]
        = cotizarVenta($db, $empresa, $local, $lineas, $ahora, !$contingencia);
    if ($edad && ($input['edadConfirmada'] ?? false) !== true) {
        throw new RuntimeException('La venta incluye productos con verificación de edad. Confirme la edad del cliente.', 422);
    }

    $pagos = $input['pagos'] ?? null;
    if (!is_array($pagos) || !array_is_list($pagos) || $pagos === [] || count($pagos) > 5) {
        throw new InvalidArgumentException('Indique entre 1 y 5 pagos.');
    }
    $suma = 0;
    $vuelto = 0;
    foreach ($pagos as $i => $pago) {
        $medio = is_array($pago) ? ($pago['medio'] ?? null) : null;
        if (!in_array($medio, MEDIOS_PAGO, true)) {
            throw new InvalidArgumentException('Medio de pago inválido.');
        }
        $monto = pesos($pago['monto'] ?? null, 'el monto del pago');
        $recibido = $pago['recibido'] ?? null;
        if ($medio === 'efectivo' && $recibido !== null) {
            if (pesos($recibido, 'el efectivo recibido') < $monto) {
                throw new InvalidArgumentException('El efectivo recibido no alcanza.');
            }
            $vuelto += $recibido - $monto;
        } elseif ($recibido !== null) {
            throw new InvalidArgumentException('Solo el efectivo registra monto recibido.');
        }
        $suma += $monto;
        $pagos[$i] = ['medio' => $medio, 'monto' => $monto, 'recibido' => $recibido];
    }
    if ($suma !== $montos['total']) {
        throw new InvalidArgumentException('Los pagos suman $' . number_format($suma, 0, ',', '.') . ' y el total es $'
            . number_format($montos['total'], 0, ',', '.') . '.');
    }

    $soloTicket = ($input['soloTicket'] ?? false) === true;
    $demo = $db->prepare('SELECT es_demo FROM empresas WHERE id = ?');
    $demo->execute([$empresa]);
    $esDemo = (bool) $demo->fetchColumn();
    $emitir = !$soloTicket && tipoHabilitadoDte($db, $empresa, $tipo);
    if (!$soloTicket && !$emitir && !$esDemo) {
        throw new RuntimeException('Las boletas no están habilitadas en producción. Para vender antes de certificarse, seleccione "Solo Ticket" o complete la certificación en Documentos tributarios.', 409);
    }
    // Certificado y emisor se abren antes de la transacción: si faltan, no se toma ningún bloqueo.
    $emisor = $emitir ? emisorDte($db, $empresa, 'produccion') : null;
    $firma = $emitir ? abrirCertificadoDte($db, $empresa, 'produccion') : null;

    $db->beginTransaction();
    try {
        $turno = turnoAbierto($db, $empresa, $local, $caja);
        if ($turno === null) {
            throw new RuntimeException('La caja no tiene un turno abierto.', 409);
        }
        try {
            $db->prepare('INSERT INTO ventas (empresa_id, local_codigo, turno_id, clave, vendedor_id, neto, iva, exento, total, edad_confirmada_por)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$empresa, $local, $turno['id'], $clave, $usuario, $montos['neto'],
                $montos['iva'], $montos['exento'], $montos['total'], $edad ? $usuario : null]);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1062) {
                throw $error;
            }
            // Otro reintento simultáneo ganó: se devuelve esa venta.
            $db->rollBack();
            $previa->execute([$empresa, $clave]);
            return detalleVenta($db, $empresa, (string) $previa->fetchColumn()) + ['repetida' => true, 'avisos' => []];
        }
        $venta = (string) $db->lastInsertId();
        $linea = $db->prepare('INSERT INTO venta_lineas (empresa_id, venta_id, linea, producto_id, presentacion_id, nombre, presentacion,
            cantidad, equivalencia, precio_unitario, descuento, promocion_id, monto, exento) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($resueltas as $i => $r) {
            $linea->execute([$empresa, $venta, $i + 1, $r['producto'], $r['linea']['presentacionId'], $r['nombre'], $r['presentacion'],
                $r['cantidad'], $r['equivalencia'], $r['precio'], $montos['lineas'][$i]['descuento'], $r['promocion']['id'] ?? null,
                $montos['lineas'][$i]['monto'], (int) $r['exento']]);
        }
        $pago = $db->prepare('INSERT INTO venta_pagos (empresa_id, venta_id, linea, medio, monto, recibido) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($pagos as $i => $p) {
            $pago->execute([$empresa, $venta, $i + 1, $p['medio'], $p['monto'], $p['recibido']]);
        }
        $db->prepare("INSERT INTO stock_operaciones (empresa_id, local_codigo, tipo, causa, clave, usuario_id, venta_id)
            VALUES (?, ?, 'venta', ?, ?, ?, ?)")->execute([$empresa, $local, 'Venta ' . $venta, 'venta:' . $venta, $usuario, $venta]);
        $avisos = array_map(static fn (array $n): string => $n['nombre'] . ' quedó con saldo ' . numeroChileno($n['saldo'], 3) . ' en este local.',
            aplicarMovimientosInventario($db, $empresa, $db->lastInsertId(), $local, null, false, $resueltas, true));
        if ($emitir) {
            $boleta = generarDocumentoDte($db, $empresa, 'produccion', $usuario, $emisor, $firma, ['tipo' => $tipo, 'clave' => 'venta-' . $venta,
                'origen' => 'venta', 'referencia' => 'Venta ' . $venta, 'lineas' => $paraBoleta], $ahora);
            $db->prepare('UPDATE ventas SET documento_id = ? WHERE empresa_id = ? AND id = ?')->execute([$boleta['id'], $empresa, $venta]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
    return detalleVenta($db, $empresa, $venta) + ['repetida' => false, 'vuelto' => $vuelto, 'avisos' => $avisos];
}

/**
 * Descuento de una promoción sobre una línea, en pesos: 2x1 regala una de cada dos; pack cobra N unidades al precio del
 * pack; horario cambia el precio unitario. Nunca negativo ni mayor que el monto de la línea.
 */
function descuentoPromocion(array $regla, string $cantidad, int $precio): int
{
    $bruto = (int) bcadd(bcmul($cantidad, (string) $precio, 6), '0.5', 0);
    $descuento = match ($regla['tipo']) {
        '2x1' => intdiv((int) $cantidad, 2) * $precio,
        'pack' => intdiv((int) $cantidad, (int) $regla['cantidad']) * ((int) $regla['cantidad'] * $precio - (int) $regla['precio']),
        'horario' => $bruto - (int) bcadd(bcmul($cantidad, (string) $regla['precio'], 6), '0.5', 0),
        default => 0,
    };
    return max(0, min($bruto, $descuento));
}

/**
 * Precios del servidor para un carro: presentación, precio con IVA y la promoción vigente de mayor descuento por línea
 * (una por línea, sin acumular: decisión de diseño del 2026-11-17). Lo usan la venta y la cotización de la caja, para
 * que el total mostrado sea el que se cobra.
 */
function cotizarVenta(PDO $db, string $empresa, string $local, mixed $lineas, DateTimeImmutable $ahora, bool $promociones = true): array
{
    if (!is_array($lineas) || !array_is_list($lineas)) {
        throw new InvalidArgumentException('La venta requiere líneas.');
    }
    $resueltas = resolverLineasInventario($db, $empresa, array_map(static fn ($l): array => is_array($l)
        ? ['presentacionId' => $l['presentacionId'] ?? null, 'cantidad' => $l['cantidad'] ?? null] : [], $lineas), false);
    $precio = $db->prepare('SELECT pp.precio, p.afecto_iva, p.verificar_edad FROM producto_presentaciones pp
        JOIN productos p ON p.empresa_id = pp.empresa_id AND p.id = pp.producto_id WHERE pp.empresa_id = ? AND pp.id = ?');
    $promocionesLinea = $db->prepare('SELECT * FROM promociones WHERE empresa_id = ? AND presentacion_id = ? AND activa');
    $paraBoleta = [];
    $edad = false;
    foreach ($resueltas as $indice => $linea) {
        $precio->execute([$empresa, $linea['linea']['presentacionId']]);
        $datos = $precio->fetch(PDO::FETCH_ASSOC);
        $mejor = null;
        $descuento = 0;
        $promocionesLinea->execute([$empresa, $linea['linea']['presentacionId']]);
        foreach ($promociones ? $promocionesLinea->fetchAll(PDO::FETCH_ASSOC) : [] as $regla) {
            if (promocionVigente($regla, $local, $ahora) && ($valor = descuentoPromocion($regla, $linea['cantidad'], (int) $datos['precio'])) > $descuento) {
                [$mejor, $descuento] = [$regla, $valor];
            }
        }
        $resueltas[$indice]['precio'] = (int) $datos['precio'];
        $resueltas[$indice]['exento'] = !$datos['afecto_iva'];
        $resueltas[$indice]['promocion'] = $mejor === null ? null : ['id' => (string) $mejor['id'], 'nombre' => $mejor['nombre']];
        $edad = $edad || (bool) $datos['verificar_edad'];
        // La presentación de una unidad base («Unidad», «Kilo») no se repite en el nombre del ítem.
        $paraBoleta[] = ['nombre' => $linea['nombre'] . ((float) $linea['equivalencia'] === 1.0 ? '' : ' ' . $linea['presentacion']),
            'cantidad' => $linea['cantidad'], 'precio' => (int) $datos['precio'], 'exento' => !$datos['afecto_iva'], 'descuento' => $descuento];
    }
    $tipo = array_filter($paraBoleta, static fn (array $l): bool => !$l['exento']) === [] ? 41 : 39;
    return ['resueltas' => $resueltas, 'paraBoleta' => $paraBoleta, 'edad' => $edad, 'tipo' => $tipo,
        'montos' => ConstructorBoleta::calcular($tipo, $paraBoleta)];
}

/**
 * Venta con sus líneas, pagos y documento. Sirve para reimprimir: devuelve el mismo documento y su timbre, sin
 * generar otro.
 */
function detalleVenta(PDO $db, string $empresa, string $venta): array
{
    $query = $db->prepare('SELECT v.id, v.local_codigo, v.total, v.neto, v.iva, v.exento, v.creada_en, t.caja_codigo, u.nombre AS vendedor,
            d.id AS documento_id, d.tipo AS documento_tipo, d.folio AS documento_folio, d.estado AS documento_estado, d.xml AS documento_xml
        FROM ventas v JOIN turnos t ON t.empresa_id = v.empresa_id AND t.id = v.turno_id JOIN usuarios u ON u.id = v.vendedor_id
        LEFT JOIN dte_documentos d ON d.empresa_id = v.empresa_id AND d.id = v.documento_id WHERE v.empresa_id = ? AND v.id = ?');
    $query->execute([$empresa, $venta]);
    $fila = $query->fetch(PDO::FETCH_ASSOC);
    if (!$fila) {
        throw new RuntimeException('La venta no existe.', 404);
    }
    $lineas = $db->prepare('SELECT l.linea, l.producto_id, l.presentacion_id, l.nombre, l.presentacion, l.cantidad, l.precio_unitario, l.descuento,
            l.monto, l.exento, l.devuelta, p.nombre AS promocion
        FROM venta_lineas l LEFT JOIN promociones p ON p.empresa_id = l.empresa_id AND p.id = l.promocion_id
        WHERE l.empresa_id = ? AND l.venta_id = ? ORDER BY l.linea');
    $lineas->execute([$empresa, $venta]);
    $pagos = $db->prepare('SELECT medio, monto, recibido FROM venta_pagos WHERE empresa_id = ? AND venta_id = ? ORDER BY linea');
    $pagos->execute([$empresa, $venta]);
    $devoluciones = $db->prepare('SELECT x.id, x.monto, x.medio, x.motivo, x.fisica, x.creada_en, d.id AS documento_id, d.folio, d.estado FROM devoluciones x
        LEFT JOIN dte_documentos d ON d.empresa_id = x.empresa_id AND d.id = x.documento_id WHERE x.empresa_id = ? AND x.venta_id = ? ORDER BY x.id');
    $devoluciones->execute([$empresa, $venta]);
    $ted = $fila['documento_xml'] !== null && preg_match('#<TED version="1.0">.*?</TED>#s', $fila['documento_xml'], $m)
        ? mb_convert_encoding($m[0], 'UTF-8', 'ISO-8859-1') : null;
    return ['id' => (string) $fila['id'], 'caja' => $fila['caja_codigo'], 'vendedor' => $fila['vendedor'], 'fecha' => $fila['creada_en'],
        'neto' => (int) $fila['neto'], 'iva' => (int) $fila['iva'], 'exento' => (int) $fila['exento'], 'total' => (int) $fila['total'],
        'documento' => $fila['documento_tipo'] === null ? null : ['id' => (string) $fila['documento_id'], 'tipo' => (int) $fila['documento_tipo'], 'folio' => (int) $fila['documento_folio'],
            'estado' => $fila['documento_estado'], 'ted' => $ted],
        'lineas' => array_map(static fn (array $l): array => ['linea' => (int) $l['linea'], 'productoId' => (string) $l['producto_id'],
            'presentacionId' => (string) $l['presentacion_id'], 'nombre' => $l['nombre'], 'presentacion' => $l['presentacion'],
            'cantidad' => $l['cantidad'], 'precio' => (int) $l['precio_unitario'], 'descuento' => (int) $l['descuento'], 'promocion' => $l['promocion'],
            'monto' => (int) $l['monto'], 'exento' => (bool) $l['exento'],
            'devuelta' => $l['devuelta']], $lineas->fetchAll(PDO::FETCH_ASSOC)),
        'pagos' => array_map(static fn (array $p): array => ['medio' => $p['medio'], 'monto' => (int) $p['monto'],
            'recibido' => $p['recibido'] === null ? null : (int) $p['recibido']], $pagos->fetchAll(PDO::FETCH_ASSOC)),
        'origen' => origenVenta($db, $empresa, $venta),
        'devoluciones' => array_map(static fn (array $d): array => ['id' => (string) $d['id'], 'monto' => (int) $d['monto'], 'medio' => $d['medio'],
            'motivo' => $d['motivo'], 'fisica' => (bool) $d['fisica'], 'fecha' => $d['creada_en'],
            'nota' => $d['folio'] === null ? null : ['id' => (string) $d['documento_id'], 'tipo' => 61, 'folio' => (int) $d['folio'], 'estado' => $d['estado']]],
            $devoluciones->fetchAll(PDO::FETCH_ASSOC))];
}

/** Ventas del turno abierto de la caja, más recientes primero. */
function ventasDelTurno(PDO $db, string $empresa, string $turno): array
{
    $query = $db->prepare('SELECT v.id, v.total, v.creada_en, u.nombre AS vendedor, d.tipo, d.folio, d.estado FROM ventas v
        JOIN usuarios u ON u.id = v.vendedor_id LEFT JOIN dte_documentos d ON d.empresa_id = v.empresa_id AND d.id = v.documento_id
        WHERE v.empresa_id = ? AND v.turno_id = ? ORDER BY v.id DESC LIMIT 100');
    $query->execute([$empresa, $turno]);
    return array_map(static fn (array $v): array => ['id' => (string) $v['id'], 'total' => (int) $v['total'], 'fecha' => $v['creada_en'],
        'vendedor' => $v['vendedor'], 'documento' => $v['tipo'] === null ? null : ['tipo' => (int) $v['tipo'], 'folio' => (int) $v['folio'],
        'estado' => $v['estado']]], $query->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Devuelve una venta, total o parcialmente, desde una caja con turno abierto:
 *
 * - Exige el permiso pos.devolver. Cada línea admite hasta lo vendido menos lo ya devuelto.
 * - Si la venta tiene boleta, emite una nota de crédito 61 referida a ella: anula (código 1) si se devuelve todo y
 *   corrige montos (código 3) si es parcial. Sin notas de crédito en producción la devolución se rechaza.
 * - Con devolución física, el producto vuelve al saldo y a sus lotes de origen.
 * - El reintegro sale de la caja por el medio indicado y afecta el arqueo.
 * - Todo en una transacción identificada por la clave del cliente.
 */
function devolverVenta(PDO $db, string $empresa, string $local, string $usuario, array $permisos, array $input): array
{
    if (!in_array('pos.devolver', $permisos, true)) {
        throw new RuntimeException('No tiene permiso para devolver ventas.', 403);
    }
    $clave = claveOperacion($input['clave'] ?? null);
    $previa = $db->prepare('SELECT id FROM devoluciones WHERE empresa_id = ? AND clave = ?');
    $previa->execute([$empresa, $clave]);
    if ($id = $previa->fetchColumn()) {
        return ['devolucion' => (string) $id, 'repetida' => true] + detalleVenta($db, $empresa, (string) $input['venta']);
    }
    $caja = exigirCaja($db, $empresa, $local, $usuario, $permisos, $input['caja'] ?? null);
    $venta = $input['venta'] ?? null;
    $medio = $input['medio'] ?? null;
    $motivo = is_string($input['motivo'] ?? null) ? trim($input['motivo']) : '';
    $fisica = ($input['fisica'] ?? null) === true;
    if (!is_string($venta) || !preg_match('/^[1-9][0-9]{0,19}$/D', $venta) || !in_array($medio, MEDIOS_PAGO, true)
        || $motivo === '' || mb_strlen($motivo) > 200) {
        throw new InvalidArgumentException('Indique la venta, el medio de reintegro y el motivo.');
    }
    $datos = $db->prepare('SELECT v.local_codigo, v.documento_id, d.tipo, d.folio, d.fecha_emision, d.xml FROM ventas v
        LEFT JOIN dte_documentos d ON d.empresa_id = v.empresa_id AND d.id = v.documento_id WHERE v.empresa_id = ? AND v.id = ?');
    $datos->execute([$empresa, $venta]);
    $origen = $datos->fetch(PDO::FETCH_ASSOC);
    if (!$origen) {
        throw new RuntimeException('La venta no existe.', 404);
    }
    if ($origen['local_codigo'] !== $local) {
        throw new RuntimeException('La venta se hizo en otro local: devuélvala desde ese local.', 409);
    }
    $pedidas = $input['lineas'] ?? null;
    if (!is_array($pedidas) || !array_is_list($pedidas) || $pedidas === []) {
        throw new InvalidArgumentException('Indique qué líneas se devuelven.');
    }
    $conNota = $origen['documento_id'] !== null;
    if ($conNota && !tipoHabilitadoDte($db, $empresa, 61)) {
        throw new RuntimeException('Las notas de crédito no están habilitadas en producción. Actívelas en Documentos tributarios.', 409);
    }
    $emisor = $conNota ? emisorDte($db, $empresa, 'produccion') : null;
    $firma = $conNota ? abrirCertificadoDte($db, $empresa, 'produccion') : null;
    $zona = $db->prepare('SELECT zona_horaria FROM empresas WHERE id = ?');
    $zona->execute([$empresa]);
    $ahora = new DateTimeImmutable('now', new DateTimeZone((string) $zona->fetchColumn() ?: 'America/Santiago'));

    $db->beginTransaction();
    try {
        $turno = turnoAbierto($db, $empresa, $local, $caja);
        if ($turno === null) {
            throw new RuntimeException('La caja no tiene un turno abierto.', 409);
        }
        $lineasVenta = $db->prepare('SELECT * FROM venta_lineas WHERE empresa_id = ? AND venta_id = ? ORDER BY linea FOR UPDATE');
        $lineasVenta->execute([$empresa, $venta]);
        $porLinea = [];
        foreach ($lineasVenta->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $porLinea[(int) $fila['linea']] = $fila;
        }
        $devueltas = [];
        foreach ($pedidas as $pedida) {
            $numero = is_array($pedida) ? ($pedida['linea'] ?? null) : null;
            $cantidad = is_array($pedida) ? ($pedida['cantidad'] ?? null) : null;
            if (!is_int($numero) || !isset($porLinea[$numero]) || isset($devueltas[$numero]) || !is_string($cantidad)
                || !preg_match('/^\d{1,9}(?:\.\d{1,6})?$/D', $cantidad) || bccomp($cantidad, '0', 6) <= 0) {
                throw new InvalidArgumentException('Revise las líneas y cantidades a devolver.');
            }
            $fila = $porLinea[$numero];
            if (bccomp($cantidad, bcsub((string) $fila['cantidad'], (string) $fila['devuelta'], 6), 6) > 0) {
                throw new InvalidArgumentException("Línea $numero: se devuelve más de lo vendido.");
            }
            $devueltas[$numero] = $cantidad;
        }
        $paraNota = [];
        foreach ($devueltas as $numero => $cantidad) {
            $fila = $porLinea[$numero];
            // Con promoción, se devuelve lo pagado: el descuento de la línea en proporción a lo devuelto.
            $descuento = (int) bcadd(bcdiv(bcmul((string) $fila['descuento'], $cantidad, 6), (string) $fila['cantidad'], 6), '0.5', 0);
            $paraNota[] = ['nombre' => $fila['nombre'] . ((float) $fila['equivalencia'] === 1.0 ? '' : ' ' . $fila['presentacion']),
                'cantidad' => $cantidad, 'precio' => (int) $fila['precio_unitario'], 'exento' => (bool) $fila['exento'], 'descuento' => $descuento];
        }
        $montos = ConstructorDte::calcular(61, $paraNota);
        $total = true;
        foreach ($porLinea as $numero => $fila) {
            $resta = bcsub(bcsub((string) $fila['cantidad'], (string) $fila['devuelta'], 6), $devueltas[$numero] ?? '0', 6);
            $total = $total && bccomp($resta, '0', 6) === 0;
        }
        $db->prepare('INSERT INTO devoluciones (empresa_id, venta_id, turno_id, clave, motivo, medio, monto, fisica, usuario_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$empresa, $venta, $turno['id'], $clave, $motivo, $medio, $montos['total'],
            (int) $fisica, $usuario]);
        $devolucion = (string) $db->lastInsertId();
        $linea = $db->prepare('INSERT INTO devolucion_lineas (empresa_id, devolucion_id, linea, cantidad, monto) VALUES (?, ?, ?, ?, ?)');
        $marcar = $db->prepare('UPDATE venta_lineas SET devuelta = devuelta + ? WHERE empresa_id = ? AND venta_id = ? AND linea = ?');
        $i = 0;
        foreach ($devueltas as $numero => $cantidad) {
            $linea->execute([$empresa, $devolucion, $numero, $cantidad, $montos['lineas'][$i++]['monto']]);
            $marcar->execute([$cantidad, $empresa, $venta, $numero]);
        }
        if ($fisica) {
            $db->prepare("INSERT INTO stock_operaciones (empresa_id, local_codigo, tipo, causa, clave, usuario_id, venta_id, devolucion_id)
                VALUES (?, ?, 'devolucion_venta', ?, ?, ?, ?, ?)")->execute([$empresa, $local, 'Devolución de la venta ' . $venta . ': ' . $motivo,
                'devolucion:' . $devolucion, $usuario, $venta, $devolucion]);
            $operacion = (string) $db->lastInsertId();
            foreach ($devueltas as $numero => $cantidad) {
                $fila = $porLinea[$numero];
                reponerDevolucionInventario($db, $empresa, $operacion, $local, $venta, ['producto' => (string) $fila['producto_id'],
                    'base' => bcmul($cantidad, (string) $fila['equivalencia'], 6), 'cantidad' => $cantidad, 'presentacion' => $fila['presentacion'],
                    'equivalencia' => $fila['equivalencia']]);
            }
        }
        if ($conNota) {
            $receptor = preg_match('#<RUTRecep>([^<]+)</RUTRecep><RznSocRecep>([^<]+)</RznSocRecep>#', (string) $origen['xml'], $m)
                ? ['rut' => $m[1], 'nombre' => mb_convert_encoding($m[2], 'UTF-8', 'ISO-8859-1')] : ['rut' => '66666666-6', 'nombre' => 'Consumidor final'];
            $nota = generarDocumentoDte($db, $empresa, 'produccion', $usuario, $emisor, $firma, ['tipo' => 61, 'clave' => 'devolucion-' . $devolucion,
                'origen' => 'devolucion', 'referencia' => 'Devolución ' . $devolucion . ' de la venta ' . $venta, 'lineas' => $paraNota,
                'receptor' => $receptor, 'referencias' => [['tipo' => (int) $origen['tipo'], 'folio' => (int) $origen['folio'],
                'fecha' => $origen['fecha_emision'], 'codigo' => $total ? 1 : 3, 'razon' => $motivo]]], $ahora);
            $db->prepare('UPDATE devoluciones SET documento_id = ? WHERE empresa_id = ? AND id = ?')->execute([$nota['id'], $empresa, $devolucion]);
        }
        $db->prepare("INSERT INTO caja_movimientos (empresa_id, turno_id, tipo, medio, monto, causa, clave, usuario_id)
            VALUES (?, ?, 'devolucion', ?, ?, ?, ?, ?)")->execute([$empresa, $turno['id'], $medio, $montos['total'],
            'Devolución de la venta ' . $venta . ': ' . $motivo, 'devolucion-' . $devolucion, $usuario]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
    return ['devolucion' => $devolucion, 'repetida' => false, 'monto' => $montos['total'], 'anula' => $total,
        'nota' => isset($nota) ? ['tipo' => 61, 'folio' => $nota['folio']] : null] + detalleVenta($db, $empresa, $venta);
}

/**
 * Origen de lo vendido: por cada lote que consumió la venta, su vencimiento, la cantidad y el lote raíz, subiendo por
 * los traspasos (lote_origen_id) hasta el que creó una recepción o un ajuste. Si llegó con un documento de proveedor,
 * informa tipo, folio, proveedor y cuántos archivos tiene, para abrir su foto. Lo vendido sin lote no aparece.
 */
function origenVenta(PDO $db, string $empresa, string $venta): array
{
    $query = $db->prepare("WITH RECURSIVE subida (consumido, lote_id, origen_id, producto_id, vencimiento, cantidad, nivel) AS (
            SELECT l.id, l.id, l.lote_origen_id, l.producto_id, l.vencimiento, c.cantidad, 0
            FROM stock_operaciones o
            JOIN stock_movimientos m ON m.empresa_id = o.empresa_id AND m.operacion_id = o.id
            JOIN stock_consumos c ON c.empresa_id = m.empresa_id AND c.movimiento_id = m.id
            JOIN stock_lotes l ON l.empresa_id = c.empresa_id AND l.id = c.lote_id
            WHERE o.empresa_id = ? AND o.venta_id = ? AND o.tipo = 'venta'
            UNION ALL
            SELECT s.consumido, p.id, p.lote_origen_id, s.producto_id, s.vencimiento, s.cantidad, s.nivel + 1
            FROM subida s JOIN stock_lotes p ON p.empresa_id = ? AND p.id = s.origen_id
        )
        SELECT s.consumido AS lote, s.vencimiento, s.cantidad, pr.nombre AS producto, o.tipo AS origen, d.id AS documento_id,
            d.tipo AS documento_tipo, d.folio AS documento_folio, c.razon_social AS proveedor,
            (SELECT COUNT(*) FROM archivos_recibidos a WHERE a.empresa_id = d.empresa_id AND a.documento_id = d.id) AS archivos
        FROM subida s
        JOIN stock_lotes raiz ON raiz.empresa_id = ? AND raiz.id = s.lote_id
        JOIN stock_movimientos m ON m.empresa_id = raiz.empresa_id AND m.id = raiz.movimiento_id
        JOIN stock_operaciones o ON o.empresa_id = m.empresa_id AND o.id = m.operacion_id
        JOIN productos pr ON pr.empresa_id = raiz.empresa_id AND pr.id = s.producto_id
        LEFT JOIN documentos_recibidos d ON d.empresa_id = o.empresa_id AND d.id = o.documento_id
        LEFT JOIN contactos c ON c.empresa_id = d.empresa_id AND c.id = d.proveedor_id
        WHERE s.origen_id IS NULL ORDER BY pr.nombre, s.consumido");
    $query->execute([$empresa, $venta, $empresa, $empresa]);
    return array_map(static fn (array $f): array => ['lote' => (string) $f['lote'], 'producto' => $f['producto'], 'vencimiento' => $f['vencimiento'],
        'cantidad' => $f['cantidad'], 'origen' => $f['origen'], 'documento' => $f['documento_id'] === null ? null : ['id' => (string) $f['documento_id'],
        'tipo' => (int) $f['documento_tipo'], 'folio' => (string) $f['documento_folio'], 'proveedor' => $f['proveedor'], 'archivos' => (int) $f['archivos']]],
        $query->fetchAll(PDO::FETCH_ASSOC));
}

/** Total del carro con promociones, sin registrar nada: la caja lo muestra antes de cobrar. */
function cotizacionCaja(PDO $db, string $empresa, string $local, array $input): array
{
    $zona = $db->prepare('SELECT zona_horaria FROM empresas WHERE id = ?');
    $zona->execute([$empresa]);
    $ahora = new DateTimeImmutable('now', new DateTimeZone((string) $zona->fetchColumn() ?: 'America/Santiago'));
    $cotizacion = cotizarVenta($db, $empresa, $local, $input['lineas'] ?? null, $ahora);
    return ['total' => $cotizacion['montos']['total'], 'edad' => $cotizacion['edad'],
        'lineas' => array_map(static fn (array $m, array $r): array => ['monto' => $m['monto'], 'descuento' => $m['descuento'],
            'promocion' => $r['promocion']['nombre'] ?? null], $cotizacion['montos']['lineas'], $cotizacion['resueltas'])];
}
