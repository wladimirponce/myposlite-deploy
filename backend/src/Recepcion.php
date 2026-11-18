<?php

declare(strict_types=1);

require_once __DIR__ . '/Inventario.php';
require_once __DIR__ . '/Archivos.php';
require_once __DIR__ . '/ImpuestosEspecificos.php';

const TIPOS_DOCUMENTO_RECIBIDO = [33 => 'Factura', 34 => 'Factura exenta', 52 => 'Guía de despacho', 56 => 'Nota de débito', 61 => 'Nota de crédito'];

/**
 * Registra un documento de proveedor y, si corresponde, su movimiento de stock, en una sola transacción.
 *
 * - Guía 52, factura 33/34: las cantidades recibidas entran al stock del local activo.
 *   Una factura puede vincular guías pendientes del mismo proveedor y local: solo entra lo que
 *   la factura recibe por sobre lo que esas guías ya ingresaron, producto por producto.
 * - Nota de crédito 61: referida a una factura del proveedor. Sin líneas solo ajusta montos.
 *   Con líneas registra la devolución física y descuenta stock; si no alcanza, se rechaza completa.
 * - Nota de débito 56: referida a una factura, solo montos.
 * - El mismo proveedor, tipo y folio no entra dos veces (409). Repetir la clave devuelve el documento original.
 *
 * @return array{id: string, repetida: bool}
 */
function registrarDocumentoRecibido(PDO $db, string $empresa, string $local, string $usuario, array $input): array
{
    $tipo = $input['tipo'] ?? null;
    $clave = $input['clave'] ?? null;
    $proveedor = $input['proveedorId'] ?? null;
    $folio = $input['folio'] ?? null;
    $fecha = $input['fecha'] ?? null;
    $monto = $input['montoTotal'] ?? null;
    $referido = $input['referidoId'] ?? null;
    $guias = $input['guias'] ?? [];
    $lineas = $input['lineas'] ?? [];
    if (!is_int($tipo) || !isset(TIPOS_DOCUMENTO_RECIBIDO[$tipo])) {
        throw new InvalidArgumentException('Seleccione el tipo de documento.');
    }
    if (!is_string($clave) || !preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $clave)) {
        throw new InvalidArgumentException('Identificador de operación inválido.');
    }
    if (!is_string($proveedor) || !preg_match('/^[1-9][0-9]{0,19}$/D', $proveedor)) {
        throw new InvalidArgumentException('Seleccione el proveedor.');
    }
    if (!is_string($folio) || !preg_match('/^[1-9][0-9]{0,17}$/D', $folio)) {
        throw new InvalidArgumentException('Ingrese el folio del documento.');
    }
    if (!is_string($fecha) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $fecha, $partes)
        || !checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])) {
        throw new InvalidArgumentException('Ingrese la fecha del documento.');
    }
    if (!is_string($monto) || !preg_match('/^[0-9]{1,14}$/D', $monto)) {
        throw new InvalidArgumentException('Ingrese el monto total en pesos, sin decimales.');
    }
    $esNota = in_array($tipo, [56, 61], true);
    if ($esNota !== ($referido !== null)) {
        throw new InvalidArgumentException($esNota ? 'Las notas deben indicar la factura que modifican.' : 'Solo las notas se refieren a otro documento.');
    }
    if ($referido !== null && (!is_string($referido) || !preg_match('/^[1-9][0-9]{0,19}$/D', $referido))) {
        throw new InvalidArgumentException('Documento referido inválido.');
    }
    if ($tipo === 56 && $lineas) {
        throw new InvalidArgumentException('La nota de débito solo modifica montos y no lleva líneas de mercadería.');
    }
    if (!is_array($guias) || !array_is_list($guias) || count($guias) > 50
        || ($guias && !in_array($tipo, [33, 34], true))) {
        throw new InvalidArgumentException('Solo una factura puede vincular guías, hasta 50.');
    }
    foreach ($guias as $guia) {
        if (!is_string($guia) || !preg_match('/^[1-9][0-9]{0,19}$/D', $guia)) {
            throw new InvalidArgumentException('Guía vinculada inválida.');
        }
    }
    if (count(array_unique($guias)) !== count($guias)) {
        throw new InvalidArgumentException('Una guía aparece dos veces.');
    }

    $contacto = $db->prepare('SELECT proveedor FROM contactos WHERE empresa_id = ? AND id = ?');
    $contacto->execute([$empresa, $proveedor]);
    if (!$contacto->fetchColumn()) {
        throw new InvalidArgumentException('Seleccione un proveedor de la empresa.');
    }
    // Se valida antes de insertar: una referencia a otra empresa haría fallar la clave foránea.
    if ($referido !== null) {
        $buscar = $db->prepare('SELECT 1 FROM documentos_recibidos
            WHERE empresa_id = ? AND id = ? AND proveedor_id = ? AND tipo IN (33, 34)');
        $buscar->execute([$empresa, $referido, $proveedor]);
        if ($buscar->fetchColumn() === false) {
            throw new InvalidArgumentException('La nota debe referirse a una factura del mismo proveedor.');
        }
    }

    // Las líneas se validan con la misma conversión que el inventario manual.
    // El documento entra con su cantidad; el stock, con la cantidad recibida.
    $entrada = $tipo !== 61;
    $documentadas = resolverLineasInventario($db, $empresa, $lineas, $entrada, !in_array($tipo, [33, 34, 52], true));
    $recibidas = [];
    foreach ($documentadas as $indice => $linea) {
        $numero = $indice + 1;
        $recibida = $linea['linea']['recibida'] ?? $linea['cantidad'];
        $precio = $linea['linea']['precioUnitario'] ?? null;
        if (!is_string($recibida) || !preg_match('/^\d{1,9}(?:\.\d{1,6})?$/D', $recibida)
            || bccomp($recibida, $linea['cantidad'], 6) > 0) {
            throw new InvalidArgumentException("Línea $numero: la cantidad recibida debe estar entre cero y la del documento.");
        }
        if ($precio !== null && (!is_string($precio) || !preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $precio))) {
            throw new InvalidArgumentException("Línea $numero: precio unitario inválido.");
        }
        $documentadas[$indice]['recibida'] = $recibida;
        $documentadas[$indice]['precio'] = $precio;
        if (bccomp($recibida, '0', 6) > 0) {
            $recibidas[$indice] = ['presentacionId' => $linea['linea']['presentacionId'], 'cantidad' => $recibida,
                'vencimiento' => $linea['vencimiento']];
        }
    }
    $porMover = $recibidas ? resolverLineasInventario($db, $empresa, array_values($recibidas), $entrada) : [];
    foreach (array_keys($recibidas) as $posicion => $indice) {
        $porMover[$posicion]['documentoLinea'] = $indice + 1;
    }

    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO documentos_recibidos
            (empresa_id, proveedor_id, tipo, folio, fecha, monto_total, referido_id, local_codigo, clave, usuario_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$empresa, $proveedor, $tipo, $folio, $fecha, $monto, $referido, $local, $clave, $usuario]);
    } catch (PDOException $error) {
        $db->rollBack();
        if ((int) ($error->errorInfo[1] ?? 0) !== 1062) {
            throw $error;
        }
        $previo = $db->prepare('SELECT id FROM documentos_recibidos WHERE empresa_id = ? AND clave = ?');
        $previo->execute([$empresa, $clave]);
        $id = $previo->fetchColumn();
        if ($id === false) {
            throw new RuntimeException('Ese documento del proveedor ya fue ingresado.', 409);
        }
        return ['id' => (string) $id, 'repetida' => true, 'avisos' => []];
    }
    $documento = $db->lastInsertId();
    $archivos = $input['archivos'] ?? [];
    $lectura = $input['lecturaId'] ?? null;
    if ($lectura !== null) {
        // El borrador digitalizado se confirma una sola vez y su archivo queda adjunto al documento.
        if (!is_string($lectura) || !preg_match('/^[1-9][0-9]{0,19}$/D', $lectura) || !is_array($archivos)) {
            throw new InvalidArgumentException('Lectura inválida.');
        }
        $marcar = $db->prepare("UPDATE lecturas_documento SET documento_id = ?
            WHERE empresa_id = ? AND id = ? AND estado = 'lista' AND documento_id IS NULL");
        $marcar->execute([$documento, $empresa, $lectura]);
        if ($marcar->rowCount() !== 1) {
            throw new RuntimeException('La lectura no existe, no está lista o ya fue confirmada.', 409);
        }
        $origen = $db->prepare('SELECT archivo_id FROM lecturas_documento WHERE empresa_id = ? AND id = ?');
        $origen->execute([$empresa, $lectura]);
        $archivos = array_values(array_unique([...$archivos, (string) $origen->fetchColumn()]));
    }
    adjuntarArchivosRecibidos($db, $empresa, $documento, $archivos ?: null);
    // Recuerda a qué presentación corresponde cada código o descripción del proveedor, para la próxima lectura.
    $recordar = $db->prepare('INSERT INTO proveedor_producto_memoria (empresa_id, proveedor_id, clave, presentacion_id)
        VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE presentacion_id = VALUES(presentacion_id), usos = usos + 1');
    foreach ($documentadas as $fila) {
        $clave = $fila['linea']['claveProveedor'] ?? null;
        if ($clave === null) {
            continue;
        }
        if (!is_string($clave) || trim($clave) === '' || mb_strlen($clave) > 160) {
            throw new InvalidArgumentException('Código de proveedor inválido en una línea.');
        }
        $recordar->execute([$empresa, $proveedor, $clave, $fila['linea']['presentacionId']]);
    }

    $linea = $db->prepare('INSERT INTO documento_recibido_lineas
        (empresa_id, documento_id, linea, producto_id, presentacion, equivalencia, cantidad, recibida, precio_unitario)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($documentadas as $indice => $fila) {
        $linea->execute([$empresa, $documento, $indice + 1, $fila['producto'], $fila['presentacion'], $fila['equivalencia'],
            $fila['cantidad'], $fila['recibida'], $fila['precio']]);
    }
    $avisos = in_array($tipo, [33, 34, 52], true) ? actualizarCostosRecepcion($db, $empresa, $local, $usuario, $documento) : [];

    if ($guias) {
        // Bloquea las guías: dos facturas concurrentes no pueden descontar la misma guía.
        $marcas = implode(',', array_fill(0, count($guias), '?'));
        $validar = $db->prepare("SELECT id FROM documentos_recibidos
            WHERE empresa_id = ? AND proveedor_id = ? AND tipo = 52 AND local_codigo = ? AND id IN ($marcas) FOR UPDATE");
        $validar->execute([$empresa, $proveedor, $local, ...$guias]);
        if (count($validar->fetchAll()) !== count($guias)) {
            throw new InvalidArgumentException('Las guías deben ser del mismo proveedor y haberse recibido en este local.');
        }
        $vincular = $db->prepare('INSERT INTO documento_guias (empresa_id, factura_id, guia_id) VALUES (?, ?, ?)');
        foreach ($guias as $guia) {
            try {
                $vincular->execute([$empresa, $documento, $guia]);
            } catch (PDOException $error) {
                if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                    throw new RuntimeException('Una de las guías ya está vinculada a otra factura.', 409, $error);
                }
                throw $error;
            }
        }
        // Lo que las guías ya ingresaron, por producto, se descuenta de lo que recibe la factura.
        $previo = $db->prepare("SELECT producto_id, SUM(recibida * equivalencia) FROM documento_recibido_lineas
            WHERE empresa_id = ? AND documento_id IN ($marcas) GROUP BY producto_id");
        $previo->execute([$empresa, ...$guias]);
        $yaIngresado = array_map(static fn($valor): string => bcadd((string) $valor, '0', 6), $previo->fetchAll(PDO::FETCH_KEY_PAIR));
        foreach ($porMover as $indice => $fila) {
            $cubierto = $yaIngresado[$fila['producto']] ?? '0';
            $usa = bccomp($cubierto, $fila['base'], 6) < 0 ? $cubierto : $fila['base'];
            $yaIngresado[$fila['producto']] = bcsub($cubierto, $usa, 6);
            $resta = bcsub($fila['base'], $usa, 6);
            if (bccomp($resta, '0', 6) <= 0) {
                unset($porMover[$indice]);
                continue;
            }
            if (bccomp($resta, $fila['base'], 6) !== 0) {
                // La parte pendiente se registra en unidad base: dividirla por la presentación podría no ser exacto.
                $porMover[$indice] = ['base' => $resta, 'cantidad' => $resta, 'equivalencia' => '1',
                    'presentacion' => $fila['unidad']] + $fila;
            }
        }
        $porMover = array_values($porMover);
    }

    if ($porMover) {
        $db->prepare('INSERT INTO stock_operaciones (empresa_id, local_codigo, tipo, causa, clave, usuario_id, documento_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$empresa, $local, $entrada ? 'recepcion' : 'devolucion_proveedor',
            TIPOS_DOCUMENTO_RECIBIDO[$tipo] . ' ' . $folio, 'documento:' . $documento, $usuario, $documento]);
        aplicarMovimientosInventario($db, $empresa, $db->lastInsertId(), $local, null, $entrada, $porMover);
    }
    $db->commit();
    return ['id' => (string) $documento, 'repetida' => false, 'avisos' => $avisos];
}

/**
 * Actualiza el costo neto por unidad base de cada producto con precio en el documento recién insertado.
 *
 * - El costo nuevo es el promedio ponderado de las líneas del documento con precio: precio por presentación
 *   dividido por su equivalencia, redondeado a dos decimales. Las líneas sin precio no cambian el costo.
 * - En facturas afectas y guías se suma el impuesto adicional del producto vigente en la fecha del documento
 *   (porcentajeImpuestoEnCosto): el minorista que vende con boleta no lo recupera. La factura exenta no lo lleva.
 *   La compra anterior se recalcula igual con su propia fecha, para comparar costos equivalentes.
 * - Avisa alza cuando ese costo supera el de la compra anterior del producto (la última línea con precio
 *   de una factura o guía previa, de cualquier proveedor) y margen bajo el mínimo por presentación,
 *   con el mismo cálculo referencial que la pantalla de productos.
 * - Sube la revisión del producto, para que un formulario abierto no pise el costo, y deja el cambio
 *   en producto_eventos con el documento, el costo anterior y el nuevo.
 *
 * @return list<array{tipo: string, productoId: string, mensaje: string}>
 */
function actualizarCostosRecepcion(PDO $db, string $empresa, string $local, string $usuario, string $documento): array
{
    $compras = $db->prepare('SELECT producto_id, SUM(precio_unitario * cantidad) AS total, SUM(cantidad * equivalencia) AS base
        FROM documento_recibido_lineas WHERE empresa_id = ? AND documento_id = ? AND precio_unitario IS NOT NULL
        GROUP BY producto_id ORDER BY producto_id');
    $compras->execute([$empresa, $documento]);
    $producto = $db->prepare('SELECT nombre, costo, margen_minimo, afecto_iva, impuesto_codigo FROM productos
        WHERE empresa_id = ? AND id = ? FOR UPDATE');
    $cabecera = $db->prepare('SELECT tipo, fecha FROM documentos_recibidos WHERE empresa_id = ? AND id = ?');
    $cabecera->execute([$empresa, $documento]);
    $propio = $cabecera->fetch(PDO::FETCH_ASSOC);
    // Costo por unidad base con el impuesto adicional que el documento recarga, según su tipo y fecha.
    $conImpuesto = static fn(string $neto, ?string $impuesto, int $tipo, string $fecha): string => $tipo === 34 ? $neto
        : bcmul($neto, bcadd('1', bcdiv(porcentajeImpuestoEnCosto($db, $empresa, $impuesto, $fecha), '100', 8), 8), 6);
    $anterior = $db->prepare('SELECT l.precio_unitario, l.equivalencia, d.tipo, d.fecha FROM documento_recibido_lineas l
        JOIN documentos_recibidos d ON d.empresa_id = l.empresa_id AND d.id = l.documento_id
        WHERE l.empresa_id = ? AND l.producto_id = ? AND l.documento_id < ? AND l.precio_unitario IS NOT NULL AND d.tipo IN (33, 34, 52)
        ORDER BY l.documento_id DESC, l.linea DESC LIMIT 1');
    $presentaciones = $db->prepare('SELECT nombre, precio, equivalencia FROM producto_presentaciones
        WHERE empresa_id = ? AND producto_id = ? ORDER BY equivalencia, id');
    $actualizar = $db->prepare('UPDATE productos SET costo = ?, revision = revision + 1 WHERE empresa_id = ? AND id = ?');
    $evento = $db->prepare('INSERT INTO producto_eventos
        (empresa_id, producto_id, local_codigo, usuario_id, revision, accion, documento_id, costo_anterior, costo_nuevo)
        SELECT empresa_id, id, ?, ?, revision, \'costo_recepcion\', ?, ?, costo FROM productos WHERE empresa_id = ? AND id = ?');
    $redondear = static fn(string $valor): string => bcadd($valor, '0.005', 2);
    $avisos = [];
    foreach ($compras->fetchAll(PDO::FETCH_ASSOC) as $compra) {
        $id = (string) $compra['producto_id'];
        $producto->execute([$empresa, $id]);
        $actual = $producto->fetch(PDO::FETCH_ASSOC);
        $nuevo = $redondear($conImpuesto(bcdiv((string) $compra['total'], (string) $compra['base'], 6), $actual['impuesto_codigo'],
            (int) $propio['tipo'], $propio['fecha']));

        $anterior->execute([$empresa, $id, $documento]);
        $previa = $anterior->fetch(PDO::FETCH_ASSOC);
        if ($previa) {
            $costoPrevio = $redondear($conImpuesto(bcdiv((string) $previa['precio_unitario'], (string) $previa['equivalencia'], 6),
                $actual['impuesto_codigo'], (int) $previa['tipo'], $previa['fecha']));
            if (bccomp($nuevo, $costoPrevio, 2) > 0) {
                $alza = bccomp($costoPrevio, '0', 2) > 0
                    ? ' (+' . numeroChileno(bcdiv(bcmul(bcsub($nuevo, $costoPrevio, 2), '100', 4), $costoPrevio, 4), 1) . ' %)' : '';
                $avisos[] = ['tipo' => 'alza', 'productoId' => $id, 'mensaje' => "{$actual['nombre']}: el costo subió de $"
                    . numeroChileno($costoPrevio, 2) . ' a $' . numeroChileno($nuevo, 2) . "$alza respecto de la compra anterior."];
            }
        }

        if ($actual['costo'] === null || bccomp((string) $actual['costo'], $nuevo, 2) !== 0) {
            $actualizar->execute([$nuevo, $empresa, $id]);
            $evento->execute([$local, $usuario, $documento, $actual['costo'], $empresa, $id]);
        }

        if ($actual['margen_minimo'] !== null) {
            $presentaciones->execute([$empresa, $id]);
            foreach ($presentaciones->fetchAll(PDO::FETCH_ASSOC) as $presentacion) {
                $margen = margenPresentacion((string) $presentacion['precio'], (bool) $actual['afecto_iva'], $nuevo, (string) $presentacion['equivalencia']);
                if ($margen !== null && bccomp($margen, (string) $actual['margen_minimo'], 6) < 0) {
                    $avisos[] = ['tipo' => 'margen', 'productoId' => $id, 'mensaje' => "{$actual['nombre']} · {$presentacion['nombre']}: margen de "
                        . numeroChileno($margen, 1) . ' %, bajo el mínimo de ' . numeroChileno((string) $actual['margen_minimo'], 2) . ' %.'];
                }
            }
        }
    }
    return $avisos;
}

/**
 * Margen referencial de una presentación en porcentaje: precio neto de IVA menos el costo de sus unidades base, sobre el
 * precio neto. El mismo cálculo de la pantalla de productos. null si la presentación no tiene precio.
 */
function margenPresentacion(string $precio, bool $afectoIva, string $costo, string $equivalencia): ?string
{
    $neto = bcdiv($precio, $afectoIva ? '1.19' : '1', 6);
    if (bccomp($neto, '0', 6) <= 0) {
        return null;
    }
    return bcdiv(bcmul(bcsub($neto, bcmul($costo, $equivalencia, 6), 6), '100', 6), $neto, 6);
}

/** Número con coma decimal y punto de miles, sin ceros decimales sobrantes. */
function numeroChileno(string $valor, int $decimales): string
{
    $texto = number_format(round((float) $valor, $decimales), $decimales, ',', '.');
    return str_contains($texto, ',') ? rtrim(rtrim($texto, '0'), ',') : $texto;
}

/**
 * Documentos de la empresa, más recientes primero, con proveedor, referencias y unidades ingresadas.
 * Con $solo entrega únicamente ese documento, si es de la empresa.
 */
function listarDocumentosRecibidos(PDO $db, string $empresa, string $cursor, ?string $solo = null): array
{
    $query = $db->prepare("SELECT d.id, d.tipo, d.folio, d.fecha, d.monto_total, d.local_codigo, d.referido_id, d.proveedor_id,
            c.razon_social AS proveedor, r.folio AS referido_folio, r.tipo AS referido_tipo, f.factura_id, ff.folio AS factura_folio,
            (SELECT COUNT(*) FROM documento_recibido_lineas l WHERE l.empresa_id = d.empresa_id AND l.documento_id = d.id) AS lineas,
            (SELECT COUNT(*) FROM archivos_recibidos a WHERE a.empresa_id = d.empresa_id AND a.documento_id = d.id) AS archivos,
            (SELECT COALESCE(SUM(m.cantidad), 0) FROM stock_operaciones o JOIN stock_movimientos m
                ON m.empresa_id = o.empresa_id AND m.operacion_id = o.id
                WHERE o.empresa_id = d.empresa_id AND o.documento_id = d.id) AS stock
        FROM documentos_recibidos d
        JOIN contactos c ON c.empresa_id = d.empresa_id AND c.id = d.proveedor_id
        LEFT JOIN documentos_recibidos r ON r.empresa_id = d.empresa_id AND r.id = d.referido_id
        LEFT JOIN documento_guias f ON f.empresa_id = d.empresa_id AND f.guia_id = d.id
        LEFT JOIN documentos_recibidos ff ON ff.empresa_id = f.empresa_id AND ff.id = f.factura_id
        WHERE d.empresa_id = ? AND (? = '0' OR d.id < ?) AND (? IS NULL OR d.id = ?) ORDER BY d.id DESC LIMIT 51");
    $query->execute([$empresa, $cursor, $cursor, $solo, $solo]);
    $documentos = $query->fetchAll(PDO::FETCH_ASSOC);
    $hayMas = count($documentos) === 51;
    if ($hayMas) {
        array_pop($documentos);
    }
    foreach ($documentos as &$documento) {
        foreach (['id', 'referido_id', 'factura_id', 'proveedor_id'] as $campo) {
            $documento[$campo] = $documento[$campo] === null ? null : (string) $documento[$campo];
        }
        $documento['tipo'] = (int) $documento['tipo'];
        $documento['referido_tipo'] = $documento['referido_tipo'] === null ? null : (int) $documento['referido_tipo'];
        $documento['folio'] = (string) $documento['folio'];
        $documento['referido_folio'] = $documento['referido_folio'] === null ? null : (string) $documento['referido_folio'];
        $documento['factura_folio'] = $documento['factura_folio'] === null ? null : (string) $documento['factura_folio'];
        $documento['lineas'] = (int) $documento['lineas'];
        $documento['archivos'] = (int) $documento['archivos'];
    }
    unset($documento);
    return ['documentos' => $documentos, 'siguiente' => $hayMas ? end($documentos)['id'] : null];
}

/**
 * Guías del proveedor recibidas en el local y todavía sin factura, con sus líneas.
 * Si se indica un folio referenciado, esa guía va primero.
 */
function guiasPendientes(PDO $db, string $empresa, string $local, string $proveedor): array
{
    $query = $db->prepare('SELECT d.id, d.folio, d.fecha FROM documentos_recibidos d
        WHERE d.empresa_id = ? AND d.proveedor_id = ? AND d.local_codigo = ? AND d.tipo = 52
        AND NOT EXISTS (SELECT 1 FROM documento_guias g WHERE g.empresa_id = d.empresa_id AND g.guia_id = d.id)
        ORDER BY d.fecha, d.id LIMIT 50');
    $query->execute([$empresa, $proveedor, $local]);
    $guias = $query->fetchAll(PDO::FETCH_ASSOC);
    $lineas = $db->prepare('SELECT l.producto_id, p.nombre AS producto, l.presentacion, l.recibida
        FROM documento_recibido_lineas l JOIN productos p ON p.empresa_id = l.empresa_id AND p.id = l.producto_id
        WHERE l.empresa_id = ? AND l.documento_id = ? ORDER BY l.linea');
    foreach ($guias as &$guia) {
        $lineas->execute([$empresa, $guia['id']]);
        $guia['id'] = (string) $guia['id'];
        $guia['folio'] = (string) $guia['folio'];
        $guia['lineas'] = array_map(static fn(array $fila): array => ['productoId' => (string) $fila['producto_id']] + $fila,
            $lineas->fetchAll(PDO::FETCH_ASSOC));
    }
    unset($guia);
    return $guias;
}

/**
 * Sigue lo que entró con un documento hasta hoy, en todos los locales.
 *
 * Parte de los lotes creados por los movimientos del documento (y de sus guías, si es una factura) y baja
 * por los lotes que los traspasos crearon en otros locales (lote_origen_id). Lo que queda es el saldo de
 * esos lotes; lo que salió son sus consumos, agrupados por tipo de operación. Los traspasos se listan
 * pero no suman como salida, porque el lote sigue en otro local. Por eso, por producto,
 * entrado = queda + salidas, y la venta del Sprint 05 aparecerá como un tipo más sin cambiar esta consulta.
 *
 * @return array<string, mixed>|null null si el documento no es de la empresa.
 */
function trazabilidadDocumento(PDO $db, string $empresa, string $documento): ?array
{
    $encabezado = listarDocumentosRecibidos($db, $empresa, '0', $documento)['documentos'][0] ?? null;
    if ($encabezado === null) {
        return null;
    }
    $guias = $db->prepare('SELECT d.id, d.folio, d.fecha FROM documento_guias g
        JOIN documentos_recibidos d ON d.empresa_id = g.empresa_id AND d.id = g.guia_id
        WHERE g.empresa_id = ? AND g.factura_id = ? ORDER BY d.id');
    $guias->execute([$empresa, $documento]);
    $guias = array_map(static fn(array $fila): array => ['id' => (string) $fila['id'], 'folio' => (string) $fila['folio'],
        'fecha' => $fila['fecha']], $guias->fetchAll(PDO::FETCH_ASSOC));
    $origenes = [$documento, ...array_column($guias, 'id')];
    $marcas = implode(',', array_fill(0, count($origenes), '?'));

    $lineas = $db->prepare('SELECT l.linea, p.nombre AS producto, p.unidad_base AS unidad, l.presentacion, l.cantidad, l.recibida,
            l.equivalencia, l.precio_unitario
        FROM documento_recibido_lineas l JOIN productos p ON p.empresa_id = l.empresa_id AND p.id = l.producto_id
        WHERE l.empresa_id = ? AND l.documento_id = ? ORDER BY l.linea');
    $lineas->execute([$empresa, $documento]);

    // Movimiento neto que hicieron el documento y sus guías: positivo al recibir, negativo en una devolución.
    $movido = $db->prepare("SELECT m.producto_id, p.nombre, p.unidad_base, SUM(m.cantidad) AS cantidad
        FROM stock_operaciones o
        JOIN stock_movimientos m ON m.empresa_id = o.empresa_id AND m.operacion_id = o.id
        JOIN productos p ON p.empresa_id = m.empresa_id AND p.id = m.producto_id
        WHERE o.empresa_id = ? AND o.documento_id IN ($marcas) GROUP BY m.producto_id, p.nombre, p.unidad_base ORDER BY p.nombre");
    $movido->execute([$empresa, ...$origenes]);
    $productos = [];
    foreach ($movido->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $productos[(string) $fila['producto_id']] = ['productoId' => (string) $fila['producto_id'], 'nombre' => $fila['nombre'],
            'unidad' => $fila['unidad_base'], 'movido' => $fila['cantidad'], 'queda' => '0', 'vencido' => '0',
            'locales' => [], 'salidas' => []];
    }

    $arbol = "WITH RECURSIVE arbol (id, empresa_id, local_codigo, producto_id, saldo, vencimiento) AS (
            SELECT l.id, l.empresa_id, l.local_codigo, l.producto_id, l.saldo, l.vencimiento
            FROM stock_operaciones o
            JOIN stock_movimientos m ON m.empresa_id = o.empresa_id AND m.operacion_id = o.id
            JOIN stock_lotes l ON l.empresa_id = m.empresa_id AND l.movimiento_id = m.id
            WHERE o.empresa_id = ? AND o.documento_id IN ($marcas)
            UNION ALL
            SELECT h.id, h.empresa_id, h.local_codigo, h.producto_id, h.saldo, h.vencimiento
            FROM arbol a JOIN stock_lotes h ON h.empresa_id = a.empresa_id AND h.lote_origen_id = a.id
        ) ";
    $queda = $db->prepare($arbol . 'SELECT a.producto_id, a.local_codigo, l.nombre, SUM(a.saldo) AS saldo,
            SUM(CASE WHEN a.vencimiento < CURRENT_DATE THEN a.saldo ELSE 0 END) AS vencido
        FROM arbol a JOIN locales l ON l.empresa_id = a.empresa_id AND l.codigo = a.local_codigo
        GROUP BY a.producto_id, a.local_codigo, l.nombre HAVING SUM(a.saldo) > 0 ORDER BY a.local_codigo');
    $queda->execute([$empresa, ...$origenes]);
    foreach ($queda->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $producto = &$productos[(string) $fila['producto_id']];
        $producto['locales'][] = ['local' => $fila['local_codigo'], 'nombre' => $fila['nombre'], 'saldo' => $fila['saldo'],
            'vencido' => $fila['vencido']];
        $producto['queda'] = bcadd($producto['queda'], (string) $fila['saldo'], 6);
        $producto['vencido'] = bcadd($producto['vencido'], (string) $fila['vencido'], 6);
        unset($producto);
    }

    $consumos = 'FROM arbol a
        JOIN stock_consumos c ON c.empresa_id = a.empresa_id AND c.lote_id = a.id
        JOIN stock_movimientos m ON m.empresa_id = c.empresa_id AND m.id = c.movimiento_id
        JOIN stock_operaciones o ON o.empresa_id = m.empresa_id AND o.id = m.operacion_id ';
    // Las devoluciones vuelven el producto a su lote: la de una venta se descuenta de lo vendido y la nota de crédito con
    // devolución física, de lo despachado con guías y facturas.
    $grupo = "CASE o.tipo WHEN 'devolucion_venta' THEN 'venta' WHEN 'devolucion_cliente' THEN 'despacho' ELSE o.tipo END";
    $totales = $db->prepare($arbol . "SELECT a.producto_id, $grupo AS tipo,
        SUM(IF(o.tipo IN ('devolucion_venta', 'devolucion_cliente'), -c.cantidad, c.cantidad)) AS cantidad $consumos
        WHERE o.tipo <> 'traspaso' GROUP BY a.producto_id, $grupo ORDER BY 2");
    $totales->execute([$empresa, ...$origenes]);
    foreach ($totales->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $productos[(string) $fila['producto_id']]['salidas'][$fila['tipo']] = $fila['cantidad'];
    }

    $eventos = $db->prepare($arbol . "SELECT o.id AS operacion, o.tipo, o.registrada_en, o.local_codigo AS local, o.local_destino AS destino,
            lo.nombre AS local_nombre, ld.nombre AS destino_nombre,
            o.causa, u.nombre AS usuario, p.nombre AS producto, p.unidad_base AS unidad, SUM(c.cantidad) AS cantidad,
            d.tipo AS documento_tipo, d.folio AS documento_folio
        $consumos
        JOIN locales lo ON lo.empresa_id = o.empresa_id AND lo.codigo = o.local_codigo
        LEFT JOIN locales ld ON ld.empresa_id = o.empresa_id AND ld.codigo = o.local_destino
        JOIN usuarios u ON u.id = o.usuario_id
        JOIN productos p ON p.empresa_id = a.empresa_id AND p.id = a.producto_id
        LEFT JOIN documentos_recibidos d ON d.empresa_id = o.empresa_id AND d.id = o.documento_id
        GROUP BY o.id, a.producto_id, o.tipo, o.registrada_en, o.local_codigo, o.local_destino, lo.nombre, ld.nombre, o.causa,
            u.nombre, p.nombre, p.unidad_base, d.tipo, d.folio
        ORDER BY o.id DESC, p.nombre LIMIT 100");
    $eventos->execute([$empresa, ...$origenes]);
    $eventos = array_map(static function (array $fila): array {
        $fila['operacion'] = (string) $fila['operacion'];
        $fila['documento_folio'] = $fila['documento_folio'] === null ? null : (string) $fila['documento_folio'];
        $fila['documento_tipo'] = $fila['documento_tipo'] === null ? null : (int) $fila['documento_tipo'];
        return $fila;
    }, $eventos->fetchAll(PDO::FETCH_ASSOC));

    return ['documento' => $encabezado, 'guias' => $guias, 'lineas' => $lineas->fetchAll(PDO::FETCH_ASSOC),
        'productos' => array_values($productos), 'eventos' => $eventos];
}
