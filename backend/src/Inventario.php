<?php

declare(strict_types=1);

// Sentido de cada tipo sobre el local activo: 1 suma, -1 resta. El traspaso además suma en el destino.
const TIPOS_INVENTARIO = ['inicial' => 1, 'ajuste_entrada' => 1, 'ajuste_salida' => -1, 'merma' => -1, 'traspaso' => -1];

/**
 * Registra una operación de inventario completa o ninguna de sus líneas.
 *
 * - El saldo por local y producto vive en stock_saldos. Su fila se bloquea antes de mover,
 *   en orden estable, para serializar operaciones concurrentes sobre el mismo producto.
 * - Una salida manual nunca deja saldo negativo: responde 409 y no registra nada.
 * - Cada entrada crea un lote. Las salidas consumen lotes por vencimiento más próximo y luego por antigüedad.
 * - La clave identifica la operación: repetirla devuelve la operación original sin mover stock.
 * - Las cantidades son cadenas decimales y se operan con bcmath para no perder precisión.
 *
 * @return array{id: string, repetida: bool}
 */
function registrarOperacionInventario(PDO $db, string $empresa, string $local, string $usuario, array $input): array
{
    $tipo = $input['tipo'] ?? null;
    $clave = $input['clave'] ?? null;
    $destino = $input['localDestino'] ?? null;
    $lineas = $input['lineas'] ?? null;
    $causa = is_string($input['causa'] ?? null) ? trim($input['causa']) : '';
    if (!is_string($tipo) || !isset(TIPOS_INVENTARIO[$tipo])) {
        throw new InvalidArgumentException('Seleccione un tipo de movimiento válido.');
    }
    if (!is_string($clave) || !preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $clave)) {
        throw new InvalidArgumentException('Identificador de operación inválido.');
    }
    if (mb_strlen($causa) > 200 || ($causa === '' && in_array($tipo, ['ajuste_entrada', 'ajuste_salida', 'merma'], true))) {
        throw new InvalidArgumentException('Indique la causa del movimiento, con hasta 200 caracteres.');
    }
    if ($tipo === 'traspaso') {
        $existe = $db->prepare('SELECT 1 FROM locales WHERE empresa_id = ? AND codigo = ?');
        $existe->execute([$empresa, is_string($destino) ? $destino : '']);
        if ($destino === $local || $existe->fetchColumn() === false) {
            throw new InvalidArgumentException('Seleccione un local de destino de la empresa, distinto del actual.');
        }
    } else {
        $destino = null;
    }
    $entrada = TIPOS_INVENTARIO[$tipo] === 1;
    $resueltas = resolverLineasInventario($db, $empresa, $lineas, $entrada);

    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO stock_operaciones (empresa_id, local_codigo, tipo, local_destino, causa, clave, usuario_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$empresa, $local, $tipo, $destino, $causa === '' ? null : $causa, $clave, $usuario]);
    } catch (PDOException $error) {
        $db->rollBack();
        if ((int) ($error->errorInfo[1] ?? 0) !== 1062) {
            throw $error;
        }
        $previa = $db->prepare('SELECT id FROM stock_operaciones WHERE empresa_id = ? AND clave = ?');
        $previa->execute([$empresa, $clave]);
        return ['id' => (string) $previa->fetchColumn(), 'repetida' => true];
    }
    $operacion = $db->lastInsertId();
    aplicarMovimientosInventario($db, $empresa, $operacion, $local, $destino, $entrada, $resueltas);
    $db->commit();
    return ['id' => (string) $operacion, 'repetida' => false];
}

/**
 * Valida líneas con presentacionId, cantidad y vencimiento opcional, y las convierte a la unidad base
 * con la equivalencia vigente. Compartida por las operaciones manuales y la recepción de documentos.
 * Con $permitirVacia acepta una lista vacía, para documentos que solo modifican montos.
 */
function resolverLineasInventario(PDO $db, string $empresa, mixed $lineas, bool $entrada, bool $permitirVacia = false): array
{
    if (!is_array($lineas) || !array_is_list($lineas) || (!$lineas && !$permitirVacia) || count($lineas) > 100) {
        throw new InvalidArgumentException('La operación requiere entre 1 y 100 líneas.');
    }
    $buscar = $db->prepare('SELECT pp.producto_id, pp.nombre, pp.equivalencia, p.nombre AS producto, p.unidad_base
        FROM producto_presentaciones pp
        JOIN productos p ON p.empresa_id = pp.empresa_id AND p.id = pp.producto_id
        WHERE pp.empresa_id = ? AND pp.id = ?');
    $resueltas = [];
    foreach ($lineas as $indice => $linea) {
        $numero = $indice + 1;
        $presentacion = is_array($linea) ? ($linea['presentacionId'] ?? null) : null;
        $cantidad = is_array($linea) ? ($linea['cantidad'] ?? null) : null;
        $vencimiento = is_array($linea) ? ($linea['vencimiento'] ?? null) : null;
        if (!is_string($presentacion) || !preg_match('/^[1-9][0-9]{0,19}$/D', $presentacion)
            || !is_string($cantidad) || !preg_match('/^\d{1,9}(?:\.\d{1,6})?$/D', $cantidad) || bccomp($cantidad, '0', 6) <= 0) {
            throw new InvalidArgumentException("Línea $numero: revise la presentación y la cantidad.");
        }
        if ($vencimiento === null || $vencimiento === '') {
            $vencimiento = null;
        } elseif (!$entrada || !is_string($vencimiento) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $vencimiento, $fecha)
            || !checkdate((int) $fecha[2], (int) $fecha[3], (int) $fecha[1])) {
            throw new InvalidArgumentException("Línea $numero: el vencimiento solo aplica a entradas y debe ser una fecha válida.");
        }
        $buscar->execute([$empresa, $presentacion]);
        $fila = $buscar->fetch(PDO::FETCH_ASSOC);
        if (!$fila) {
            throw new InvalidArgumentException("Línea $numero: la presentación no pertenece a la empresa.");
        }
        $exacta = bcmul($cantidad, $fila['equivalencia'], 12);
        $base = bcadd($exacta, '0', 6);
        if (bccomp($exacta, $base, 12) !== 0) {
            throw new InvalidArgumentException("Línea $numero: la cantidad no se puede expresar en la unidad base con seis decimales.");
        }
        if ($fila['unidad_base'] === 'unidad' && bccomp($base, bcadd($base, '0', 0), 6) !== 0) {
            throw new InvalidArgumentException("Línea $numero: {$fila['producto']} se lleva en unidades enteras.");
        }
        $resueltas[] = ['producto' => (string) $fila['producto_id'], 'nombre' => $fila['producto'], 'presentacion' => $fila['nombre'],
            'equivalencia' => $fila['equivalencia'], 'cantidad' => $cantidad, 'base' => $base, 'vencimiento' => $vencimiento,
            'unidad' => $fila['unidad_base'], 'linea' => $linea];
    }
    return $resueltas;
}

/**
 * Núcleo compartido por las operaciones manuales y las recepciones de documentos: bloquea saldos,
 * registra movimientos, crea o consume lotes y actualiza saldos. Requiere una transacción abierta
 * y la fila de stock_operaciones ya creada.
 *
 * Cada línea trae producto, nombre, presentacion, equivalencia, cantidad (en la presentación), base
 * (en unidad base) y vencimiento. La clave opcional documentoLinea enlaza el movimiento con la línea del documento.
 *
 * Con $permitirNegativo (solo la venta, decisión del 2026-11-17) una salida mayor que el saldo se registra igual y deja
 * el saldo negativo; consume los lotes que existan y el resto queda sin lote. Devuelve los productos que quedaron
 * con saldo negativo, para avisar.
 *
 * @return list<array{producto: string, nombre: string, saldo: string}>
 */
function aplicarMovimientosInventario(PDO $db, string $empresa, string $operacion, string $local, ?string $destino,
    bool $entrada, array $resueltas, bool $permitirNegativo = false): array
{
    // Bloqueo de saldos en orden estable de local y producto para evitar interbloqueos.
    $filas = [];
    foreach ($resueltas as $linea) {
        $filas["$local\0{$linea['producto']}"] = [$local, $linea['producto']];
        if ($destino !== null) {
            $filas["$destino\0{$linea['producto']}"] = [$destino, $linea['producto']];
        }
    }
    usort($filas, static fn(array $a, array $b): int => [$a[0], (int) $a[1]] <=> [$b[0], (int) $b[1]]);
    $crear = $db->prepare('INSERT IGNORE INTO stock_saldos (empresa_id, local_codigo, producto_id) VALUES (?, ?, ?)');
    $bloquear = $db->prepare('SELECT saldo FROM stock_saldos WHERE empresa_id = ? AND local_codigo = ? AND producto_id = ? FOR UPDATE');
    foreach ($filas as [$codigoLocal, $producto]) {
        $crear->execute([$empresa, $codigoLocal, $producto]);
        $bloquear->execute([$empresa, $codigoLocal, $producto]);
        $bloquear->fetchAll();
    }

    $movimiento = $db->prepare('INSERT INTO stock_movimientos
        (empresa_id, operacion_id, local_codigo, producto_id, cantidad, presentacion, cantidad_presentacion, equivalencia, documento_linea)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $sumar = $db->prepare('UPDATE stock_saldos SET saldo = saldo + ? WHERE empresa_id = ? AND local_codigo = ? AND producto_id = ?');
    $restar = $db->prepare('UPDATE stock_saldos SET saldo = saldo - ?
        WHERE empresa_id = ? AND local_codigo = ? AND producto_id = ? AND saldo >= ?');
    $lote = $db->prepare('INSERT INTO stock_lotes
        (empresa_id, local_codigo, producto_id, movimiento_id, lote_origen_id, vencimiento, cantidad_inicial, saldo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $lotes = $db->prepare('SELECT id, saldo, vencimiento FROM stock_lotes
        WHERE empresa_id = ? AND local_codigo = ? AND producto_id = ? AND saldo > 0
        ORDER BY vencimiento IS NULL, vencimiento, id FOR UPDATE');
    $descontar = $db->prepare('UPDATE stock_lotes SET saldo = saldo - ? WHERE empresa_id = ? AND id = ?');
    $consumo = $db->prepare('INSERT INTO stock_consumos (empresa_id, movimiento_id, lote_id, cantidad) VALUES (?, ?, ?, ?)');

    foreach ($resueltas as $linea) {
        ['producto' => $producto, 'base' => $base] = $linea;
        $detalle = [$linea['presentacion'], $linea['cantidad'], $linea['equivalencia'], $linea['documentoLinea'] ?? null];
        if ($entrada) {
            $movimiento->execute([$empresa, $operacion, $local, $producto, $base, ...$detalle]);
            $lote->execute([$empresa, $local, $producto, $db->lastInsertId(), null, $linea['vencimiento'], $base, $base]);
            $sumar->execute([$base, $empresa, $local, $producto]);
            continue;
        }
        if ($permitirNegativo) {
            $sumar->execute(['-' . $base, $empresa, $local, $producto]);
        } else {
            $restar->execute([$base, $empresa, $local, $producto, $base]);
            if ($restar->rowCount() === 0) {
                throw new RuntimeException("Stock insuficiente de {$linea['nombre']} en este local.", 409);
            }
        }
        $movimiento->execute([$empresa, $operacion, $local, $producto, '-' . $base, ...$detalle]);
        $salida = $db->lastInsertId();
        $lotes->execute([$empresa, $local, $producto]);
        $pendiente = $base;
        $consumidos = [];
        foreach ($lotes->fetchAll(PDO::FETCH_ASSOC) as $disponible) {
            if (bccomp($pendiente, '0', 6) <= 0) {
                break;
            }
            $usa = bccomp($disponible['saldo'], $pendiente, 6) < 0 ? $disponible['saldo'] : $pendiente;
            $descontar->execute([$usa, $empresa, $disponible['id']]);
            $consumo->execute([$empresa, $salida, $disponible['id'], $usa]);
            $consumidos[] = [$disponible['id'], $disponible['vencimiento'], $usa];
            $pendiente = bcsub($pendiente, $usa, 6);
        }
        if ($destino !== null) {
            $movimiento->execute([$empresa, $operacion, $destino, $producto, $base, ...$detalle]);
            $llegada = $db->lastInsertId();
            // El lote de destino conserva el vencimiento y apunta al de origen para seguir la trazabilidad.
            foreach ($consumidos as [$origen, $vencimiento, $usa]) {
                $lote->execute([$empresa, $destino, $producto, $llegada, $origen, $vencimiento, $usa, $usa]);
            }
            if (bccomp($pendiente, '0', 6) > 0) {
                $lote->execute([$empresa, $destino, $producto, $llegada, null, null, $pendiente, $pendiente]);
            }
            $sumar->execute([$base, $empresa, $destino, $producto]);
        }
    }
    $negativos = [];
    if ($permitirNegativo) {
        $saldo = $db->prepare('SELECT saldo FROM stock_saldos WHERE empresa_id = ? AND local_codigo = ? AND producto_id = ?');
        foreach ($resueltas as $linea) {
            $saldo->execute([$empresa, $local, $linea['producto']]);
            $actual = (string) $saldo->fetchColumn();
            if (bccomp($actual, '0', 6) < 0 && !isset($negativos[$linea['producto']])) {
                $negativos[$linea['producto']] = ['producto' => $linea['producto'], 'nombre' => $linea['nombre'], 'saldo' => $actual];
            }
        }
    }
    return array_values($negativos);
}

// Un producto está en quiebre sin stock o bajo su mínimo del local. Requiere los alias s (stock_saldos) y pm (producto_minimos).
const CONDICION_QUIEBRE = '(COALESCE(s.saldo, 0) <= 0 OR (pm.minimo IS NOT NULL AND COALESCE(s.saldo, 0) < pm.minimo))';

/** Saldos del local con su mínimo. Con $soloQuiebres entrega solo productos sin stock o bajo el mínimo. */
function informeInventario(PDO $db, string $empresa, string $local, string $cursor, bool $soloQuiebres): array
{
    $filtro = $soloQuiebres ? ' AND ' . CONDICION_QUIEBRE : '';
    $query = $db->prepare("SELECT p.id, p.sku, p.nombre, p.unidad_base, COALESCE(s.saldo, 0) AS saldo, pm.minimo,
            CASE WHEN COALESCE(s.saldo, 0) < 0 THEN 'negativo' WHEN COALESCE(s.saldo, 0) = 0 THEN 'cero'
                WHEN pm.minimo IS NOT NULL AND s.saldo < pm.minimo THEN 'bajo' ELSE 'normal' END AS estado
        FROM productos p
        LEFT JOIN stock_saldos s ON s.empresa_id = p.empresa_id AND s.producto_id = p.id AND s.local_codigo = ?
        LEFT JOIN producto_minimos pm ON pm.empresa_id = p.empresa_id AND pm.producto_id = p.id AND pm.local_codigo = ?
        WHERE p.empresa_id = ? AND p.id > ?$filtro ORDER BY p.id LIMIT 51");
    $query->execute([$local, $local, $empresa, $cursor]);
    $productos = $query->fetchAll(PDO::FETCH_ASSOC);
    $hayMas = count($productos) === 51;
    if ($hayMas) {
        array_pop($productos);
    }
    foreach ($productos as &$producto) {
        $producto['id'] = (string) $producto['id'];
    }
    unset($producto);
    return ['productos' => $productos, 'siguiente' => $hayMas ? end($productos)['id'] : null];
}

/** Últimos movimientos y lotes con saldo de un producto en el local. */
function historialInventario(PDO $db, string $empresa, string $local, string $producto): array
{
    $movimientos = $db->prepare('SELECT m.id, m.cantidad, m.presentacion, m.cantidad_presentacion, m.equivalencia,
            o.tipo, o.causa, o.local_codigo AS local_origen, o.local_destino, o.registrada_en, u.nombre AS usuario,
            d.tipo AS documento_tipo, d.folio AS documento_folio
        FROM stock_movimientos m
        JOIN stock_operaciones o ON o.empresa_id = m.empresa_id AND o.id = m.operacion_id
        LEFT JOIN documentos_recibidos d ON d.empresa_id = o.empresa_id AND d.id = o.documento_id
        JOIN usuarios u ON u.id = o.usuario_id
        WHERE m.empresa_id = ? AND m.local_codigo = ? AND m.producto_id = ? ORDER BY m.id DESC LIMIT 50');
    $movimientos->execute([$empresa, $local, $producto]);
    $lotes = $db->prepare('SELECT id, vencimiento, cantidad_inicial, saldo FROM stock_lotes
        WHERE empresa_id = ? AND local_codigo = ? AND producto_id = ? AND saldo > 0
        ORDER BY vencimiento IS NULL, vencimiento, id');
    $lotes->execute([$empresa, $local, $producto]);
    $resultado = ['movimientos' => $movimientos->fetchAll(PDO::FETCH_ASSOC), 'lotes' => $lotes->fetchAll(PDO::FETCH_ASSOC)];
    foreach ($resultado as &$filas) {
        foreach ($filas as &$fila) {
            $fila['id'] = (string) $fila['id'];
        }
    }
    return $resultado;
}

/**
 * Lotes con saldo del local que vencen dentro de $dias días o ya vencieron, del más urgente al último.
 * Cada lote informa cómo llegó: el documento del proveedor que lo creó o el tipo de operación,
 * y en un traspaso el local de origen. Hasta 200 lotes.
 */
function informeVencimientos(PDO $db, string $empresa, string $local, int $dias): array
{
    $query = $db->prepare('SELECT l.id, l.vencimiento, l.saldo, DATEDIFF(l.vencimiento, CURRENT_DATE) AS dias,
            p.id AS producto_id, p.nombre, p.sku, p.unidad_base, o.tipo AS origen, o.local_codigo AS origen_local,
            lo.nombre AS origen_local_nombre, d.tipo AS documento_tipo, d.folio AS documento_folio, c.razon_social AS proveedor
        FROM stock_lotes l
        JOIN productos p ON p.empresa_id = l.empresa_id AND p.id = l.producto_id
        JOIN stock_movimientos m ON m.empresa_id = l.empresa_id AND m.id = l.movimiento_id
        JOIN stock_operaciones o ON o.empresa_id = m.empresa_id AND o.id = m.operacion_id
        JOIN locales lo ON lo.empresa_id = o.empresa_id AND lo.codigo = o.local_codigo
        LEFT JOIN documentos_recibidos d ON d.empresa_id = o.empresa_id AND d.id = o.documento_id
        LEFT JOIN contactos c ON c.empresa_id = d.empresa_id AND c.id = d.proveedor_id
        WHERE l.empresa_id = ? AND l.local_codigo = ? AND l.saldo > 0 AND l.vencimiento IS NOT NULL
            AND l.vencimiento <= CURRENT_DATE + INTERVAL ? DAY
        ORDER BY l.vencimiento, p.nombre, l.id LIMIT 200');
    $query->execute([$empresa, $local, $dias]);
    $lotes = array_map(static function (array $fila): array {
        foreach (['id', 'producto_id'] as $campo) {
            $fila[$campo] = (string) $fila[$campo];
        }
        $fila['dias'] = (int) $fila['dias'];
        $fila['documento_tipo'] = $fila['documento_tipo'] === null ? null : (int) $fila['documento_tipo'];
        $fila['documento_folio'] = $fila['documento_folio'] === null ? null : (string) $fila['documento_folio'];
        return $fila;
    }, $query->fetchAll(PDO::FETCH_ASSOC));
    return ['dias' => $dias, 'lotes' => $lotes, 'vencidos' => count(array_filter($lotes, static fn(array $lote): bool => $lote['dias'] < 0))];
}

/**
 * Productos en quiebre del local agrupados por proveedor habitual, con una cantidad sugerida para el pedido.
 *
 * Lo que falta es el mínimo del local menos el saldo (con saldo negativo, también se repone lo vendido sin stock).
 * Se sugiere en la presentación de la última compra del producto (factura o guía), redondeando hacia arriba;
 * sin compras previas, en la presentación de menor equivalencia. Un producto sin mínimo y sin stock aparece
 * sin cantidad sugerida, para que el operador la decida. Hasta 500 productos.
 */
function listaReposicion(PDO $db, string $empresa, string $local): array
{
    $query = $db->prepare('SELECT p.id, p.sku, p.nombre, p.unidad_base, COALESCE(s.saldo, 0) AS saldo, pm.minimo,
            c.id AS proveedor_id, c.razon_social AS proveedor, c.rut AS proveedor_rut, c.correo AS proveedor_correo
        FROM productos p
        LEFT JOIN stock_saldos s ON s.empresa_id = p.empresa_id AND s.producto_id = p.id AND s.local_codigo = ?
        LEFT JOIN producto_minimos pm ON pm.empresa_id = p.empresa_id AND pm.producto_id = p.id AND pm.local_codigo = ?
        LEFT JOIN contactos c ON c.empresa_id = p.empresa_id AND c.id = p.proveedor_habitual_id
        WHERE p.empresa_id = ? AND ' . CONDICION_QUIEBRE . '
        ORDER BY c.razon_social IS NULL, c.razon_social, p.nombre LIMIT 500');
    $query->execute([$local, $local, $empresa]);
    $compra = $db->prepare('SELECT l.presentacion, l.equivalencia FROM documento_recibido_lineas l
        JOIN documentos_recibidos d ON d.empresa_id = l.empresa_id AND d.id = l.documento_id
        WHERE l.empresa_id = ? AND l.producto_id = ? AND d.tipo IN (33, 34, 52) ORDER BY l.documento_id DESC, l.linea DESC LIMIT 1');
    $menor = $db->prepare('SELECT nombre AS presentacion, equivalencia FROM producto_presentaciones
        WHERE empresa_id = ? AND producto_id = ? ORDER BY equivalencia, id LIMIT 1');
    $grupos = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $compra->execute([$empresa, $fila['id']]);
        $presentacion = $compra->fetch(PDO::FETCH_ASSOC);
        if (!$presentacion) {
            $menor->execute([$empresa, $fila['id']]);
            $presentacion = $menor->fetch(PDO::FETCH_ASSOC) ?: ['presentacion' => $fila['unidad_base'], 'equivalencia' => '1'];
        }
        $falta = $fila['minimo'] === null ? null : bcsub((string) $fila['minimo'], (string) $fila['saldo'], 6);
        $sugerida = null;
        if ($falta !== null && bccomp($falta, '0', 6) > 0) {
            // Redondeo hacia arriba a presentaciones completas: no se pide media caja.
            $exacta = bcdiv($falta, (string) $presentacion['equivalencia'], 6);
            $sugerida = bcadd($exacta, '0', 0);
            if (bccomp($exacta, $sugerida, 6) > 0) {
                $sugerida = bcadd($sugerida, '1', 0);
            }
        }
        $clave = $fila['proveedor_id'] === null ? '0' : (string) $fila['proveedor_id'];
        $grupos[$clave] ??= ['proveedorId' => $fila['proveedor_id'] === null ? null : $clave, 'proveedor' => $fila['proveedor'],
            'rut' => $fila['proveedor_rut'], 'correo' => $fila['proveedor_correo'], 'productos' => []];
        $grupos[$clave]['productos'][] = ['id' => (string) $fila['id'], 'sku' => $fila['sku'], 'nombre' => $fila['nombre'],
            'unidad' => $fila['unidad_base'], 'saldo' => $fila['saldo'], 'minimo' => $fila['minimo'], 'falta' => $falta,
            'presentacion' => $presentacion['presentacion'], 'equivalencia' => $presentacion['equivalencia'], 'sugerida' => $sugerida];
    }
    return ['proveedores' => array_values($grupos)];
}

/**
 * Devolución física de una venta: el producto vuelve al saldo del local y a los lotes que la venta consumió, sin
 * superar lo que salió de cada uno ni lo ya devuelto antes. Lo que se vendió sin lote (saldo negativo) solo repone saldo.
 * La vuelta a cada lote queda en stock_consumos del movimiento de devolución, para la trazabilidad.
 * Requiere una transacción abierta y la operación ya creada.
 */
function reponerDevolucionInventario(PDO $db, string $empresa, string $operacion, string $local, string $venta, array $linea): void
{
    $lotes = $db->prepare("SELECT c.lote_id, SUM(CASE WHEN o.tipo = 'venta' THEN c.cantidad ELSE -c.cantidad END) AS pendiente
        FROM stock_consumos c JOIN stock_movimientos m ON m.empresa_id = c.empresa_id AND m.id = c.movimiento_id
        JOIN stock_operaciones o ON o.empresa_id = m.empresa_id AND o.id = m.operacion_id
        WHERE c.empresa_id = ? AND o.venta_id = ? AND m.producto_id = ? AND o.tipo IN ('venta', 'devolucion_venta')
        GROUP BY c.lote_id HAVING pendiente > 0 ORDER BY c.lote_id DESC");
    $lotes->execute([$empresa, $venta, $linea['producto']]);
    reponerEnLotesInventario($db, $empresa, $operacion, $local, $linea, $lotes->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Lotes de este local que despacharon los documentos emitidos indicados y que aún no vuelven, por producto: lo que
 * salió con su guía o factura menos lo que ya repusieron notas de crédito que se refieren a ellos.
 *
 * @param list<string> $documentos
 * @return list<array{lote_id: string, pendiente: string}>
 */
function lotesDespachadosInventario(PDO $db, string $empresa, string $local, array $documentos, string $producto): array
{
    $marcas = implode(',', array_fill(0, count($documentos), '?'));
    $lotes = $db->prepare("SELECT c.lote_id, SUM(CASE WHEN o.tipo = 'despacho' THEN c.cantidad ELSE -c.cantidad END) AS pendiente
        FROM stock_consumos c JOIN stock_movimientos m ON m.empresa_id = c.empresa_id AND m.id = c.movimiento_id
        JOIN stock_operaciones o ON o.empresa_id = m.empresa_id AND o.id = m.operacion_id
        JOIN stock_lotes l ON l.empresa_id = c.empresa_id AND l.id = c.lote_id
        WHERE c.empresa_id = ? AND m.producto_id = ? AND l.local_codigo = ?
          AND ((o.tipo = 'despacho' AND o.dte_documento_id IN ($marcas))
            OR (o.tipo = 'devolucion_cliente' AND o.dte_documento_id IN (SELECT r.documento_id FROM dte_referencias r
                WHERE r.empresa_id = ? AND r.referido_id IN ($marcas))))
        GROUP BY c.lote_id HAVING pendiente > 0 ORDER BY c.lote_id DESC");
    $lotes->execute([$empresa, $producto, $local, ...$documentos, $empresa, ...$documentos]);
    return $lotes->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Vuelve una cantidad al stock en los lotes de los que salió, del más reciente al más antiguo, y registra en cada
 * lote cuánto volvió. Quien llama asegura que los lotes alcanzan para la cantidad.
 *
 * @param list<array{lote_id: string, pendiente: string}> $lotes
 */
function reponerEnLotesInventario(PDO $db, string $empresa, string $operacion, string $local, array $linea, array $lotes): void
{
    $db->prepare('INSERT IGNORE INTO stock_saldos (empresa_id, local_codigo, producto_id) VALUES (?, ?, ?)')->execute([$empresa, $local, $linea['producto']]);
    $db->prepare('SELECT saldo FROM stock_saldos WHERE empresa_id = ? AND local_codigo = ? AND producto_id = ? FOR UPDATE')
        ->execute([$empresa, $local, $linea['producto']]);
    $db->prepare('INSERT INTO stock_movimientos (empresa_id, operacion_id, local_codigo, producto_id, cantidad, presentacion,
        cantidad_presentacion, equivalencia) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$empresa, $operacion, $local, $linea['producto'], $linea['base'], $linea['presentacion'], $linea['cantidad'], $linea['equivalencia']]);
    $movimiento = $db->lastInsertId();
    $db->prepare('UPDATE stock_saldos SET saldo = saldo + ? WHERE empresa_id = ? AND local_codigo = ? AND producto_id = ?')
        ->execute([$linea['base'], $empresa, $local, $linea['producto']]);
    $resta = $linea['base'];
    $devolver = $db->prepare('UPDATE stock_lotes SET saldo = saldo + ? WHERE empresa_id = ? AND id = ?');
    $registrar = $db->prepare('INSERT INTO stock_consumos (empresa_id, movimiento_id, lote_id, cantidad) VALUES (?, ?, ?, ?)');
    foreach ($lotes as $lote) {
        if (bccomp($resta, '0', 6) <= 0) {
            break;
        }
        $usa = bccomp((string) $lote['pendiente'], $resta, 6) < 0 ? (string) $lote['pendiente'] : $resta;
        $devolver->execute([$usa, $empresa, $lote['lote_id']]);
        $registrar->execute([$empresa, $movimiento, $lote['lote_id'], $usa]);
        $resta = bcsub($resta, $usa, 6);
    }
}
