<?php

declare(strict_types=1);

require_once __DIR__ . '/Inventario.php';
require_once __DIR__ . '/Emision.php';
require_once __DIR__ . '/Rut.php';

use MyposLite\Dte\ConstructorDte;

const TIPOS_EMISION = [33 => 'Factura', 34 => 'Factura exenta', 52 => 'Guía de despacho', 56 => 'Nota de débito', 61 => 'Nota de crédito'];
// Tipos de traslado de la guía que Lite emite: 1 operación que constituye venta, 5 traslado interno entre locales.
const TRASLADOS_GUIA = [1 => 'Venta', 5 => 'Traslado interno'];

function formatoRut(string $rut): string
{
    return substr($rut, 0, -1) . '-' . substr($rut, -1);
}

/** Receptor de la empresa marcado como tal. Facturas y guías exigen giro, dirección y comuna. */
function receptorEmision(PDO $db, string $empresa, mixed $id, int $tipo): array
{
    if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id)) {
        throw new InvalidArgumentException('Seleccione el receptor.');
    }
    $query = $db->prepare('SELECT id, rut, razon_social, giro, direccion, comuna FROM contactos WHERE empresa_id = ? AND id = ? AND receptor');
    $query->execute([$empresa, $id]);
    $fila = $query->fetch(PDO::FETCH_ASSOC);
    if (!$fila) {
        throw new InvalidArgumentException('Seleccione un receptor de la empresa.');
    }
    if (in_array($tipo, [33, 34, 52], true) && ($fila['giro'] === null || $fila['direccion'] === null || $fila['comuna'] === null)) {
        throw new InvalidArgumentException('Complete el giro, la dirección y la comuna de ' . $fila['razon_social'] . ' en Proveedores y receptores.');
    }
    return ['id' => (string) $fila['id'], 'rut' => formatoRut($fila['rut']), 'nombre' => $fila['razon_social'], 'giro' => $fila['giro'],
        'direccion' => $fila['direccion'], 'comuna' => $fila['comuna']];
}

/**
 * Líneas del documento: del catálogo (presentacionId y cantidad, con el precio de lista con IVA o uno negociado) o de
 * texto libre (nombre, cantidad y precio), que solo admiten las notas. La factura exenta marca todo como exento.
 *
 * @return list<array<string, mixed>> Líneas para el DTE con, si son del catálogo, la resolución de inventario en 'stock'.
 */
function lineasEmision(PDO $db, string $empresa, int $tipo, mixed $lineas): array
{
    if (!is_array($lineas) || !array_is_list($lineas) || $lineas === [] || count($lineas) > 60) {
        throw new InvalidArgumentException('El documento debe tener entre 1 y 60 líneas.');
    }
    $precio = $db->prepare('SELECT pp.precio, p.afecto_iva FROM producto_presentaciones pp JOIN productos p ON p.empresa_id = pp.empresa_id
        AND p.id = pp.producto_id WHERE pp.empresa_id = ? AND pp.id = ?');
    $salida = [];
    foreach ($lineas as $i => $linea) {
        $numero = $i + 1;
        if (!is_array($linea)) {
            throw new InvalidArgumentException("Línea $numero inválida.");
        }
        $negociado = $linea['precio'] ?? null;
        if ($negociado !== null && (!is_int($negociado) || $negociado < 0 || $negociado > 999999999999)) {
            throw new InvalidArgumentException("Línea $numero: precio en pesos enteros, con IVA.");
        }
        if (isset($linea['presentacionId'])) {
            $stock = resolverLineasInventario($db, $empresa, [['presentacionId' => $linea['presentacionId'], 'cantidad' => $linea['cantidad'] ?? null]], false)[0];
            $precio->execute([$empresa, $linea['presentacionId']]);
            $datos = $precio->fetch(PDO::FETCH_ASSOC);
            $salida[] = ['nombre' => $stock['nombre'] . ((float) $stock['equivalencia'] === 1.0 ? '' : ' ' . $stock['presentacion']),
                'cantidad' => $stock['cantidad'], 'precio' => $negociado ?? (int) $datos['precio'], 'exento' => $tipo === 34 || !$datos['afecto_iva'],
                'stock' => $stock];
            continue;
        }
        $nombre = is_string($linea['nombre'] ?? null) ? trim($linea['nombre']) : '';
        $cantidad = $linea['cantidad'] ?? null;
        if (!in_array($tipo, [56, 61], true) || $nombre === '' || mb_strlen($nombre) > 80 || $negociado === null
            || !is_string($cantidad) || !preg_match('/^\d{1,9}(?:\.\d{1,6})?$/D', $cantidad) || bccomp($cantidad, '0', 6) <= 0) {
            throw new InvalidArgumentException("Línea $numero: elija un producto del catálogo; el texto libre solo va en notas, con cantidad y precio.");
        }
        $salida[] = ['nombre' => $nombre, 'cantidad' => $cantidad, 'precio' => $negociado, 'exento' => $tipo === 34 || ($linea['exento'] ?? false) === true,
            'stock' => null];
    }
    return $salida;
}

/**
 * Emite una factura (33, 34), guía (52) o nota (56, 61) en producción, con su stock, en una transacción identificada por
 * la clave del cliente.
 *
 * - Factura: con guías emitidas del mismo receptor las consolida (las referencia y no mueve stock: la salida física fue
 *   la de cada guía); sin guías descuenta su propio stock. Saldo negativo permitido con aviso, como la venta.
 * - Guía: tipo de traslado 1 (venta, descuenta stock y queda por facturar) o 5 (traslado interno a otro local: es el
 *   traspaso del inventario, un solo movimiento con su lote y vencimiento, y no admite saldo negativo).
 * - Notas: referidas a un documento (de Lite, o histórico con su folio, fecha y un archivo que lo pruebe). La nota de
 *   crédito puede anular (1), corregir texto (2) o corregir montos (3), y con devolución física repone stock. La nota
 *   de débito solo corrige montos.
 */
function emitirDocumento(PDO $db, string $empresa, string $local, string $usuario, array $permisos, array $input): array
{
    if (!in_array('documentos.emitir', $permisos, true)) {
        throw new RuntimeException('No tiene permiso para emitir documentos.', 403);
    }
    $clave = claveOperacion($input['clave'] ?? null);
    $previo = $db->prepare('SELECT id FROM dte_documentos WHERE empresa_id = ? AND clave = ?');
    $previo->execute([$empresa, 'emision-' . $clave]);
    if ($id = $previo->fetchColumn()) {
        return detalleEmitido($db, $empresa, (string) $id) + ['repetido' => true, 'avisos' => []];
    }
    $tipo = $input['tipo'] ?? null;
    if (!is_int($tipo) || !isset(TIPOS_EMISION[$tipo])) {
        throw new InvalidArgumentException('Seleccione el tipo de documento.');
    }
    $demo = $db->prepare('SELECT es_demo FROM empresas WHERE id = ?');
    $demo->execute([$empresa]);
    if ((bool) $demo->fetchColumn() || !tipoHabilitadoDte($db, $empresa, $tipo)) {
        throw new RuntimeException(TIPOS_EMISION[$tipo] . ': el tipo no está habilitado en producción. Actívelo en Documentos tributarios.', 409);
    }
    $receptor = receptorEmision($db, $empresa, $input['receptorId'] ?? null, $tipo);

    // Guías a consolidar en la factura: emitidas por Lite, al mismo receptor, de venta y sin facturar.
    $guias = $input['guias'] ?? [];
    if (!is_array($guias) || !array_is_list($guias) || count($guias) > 50 || ($guias !== [] && !in_array($tipo, [33, 34], true))) {
        throw new InvalidArgumentException('Solo una factura consolida guías, hasta 50.');
    }
    $guiasDatos = [];
    $buscarGuia = $db->prepare("SELECT d.id, d.folio, d.fecha_emision FROM dte_documentos d
        LEFT JOIN dte_guias_facturadas f ON f.empresa_id = d.empresa_id AND f.guia_id = d.id
        WHERE d.empresa_id = ? AND d.id = ? AND d.tipo = 52 AND d.receptor_id = ? AND d.origen = 'emision' AND f.guia_id IS NULL
        AND d.xml LIKE '%<IndTraslado>1</IndTraslado>%'");
    foreach ($guias as $guia) {
        $buscarGuia->execute([$empresa, is_string($guia) ? $guia : '', $receptor['id']]);
        $fila = $buscarGuia->fetch(PDO::FETCH_ASSOC);
        if (!$fila) {
            throw new InvalidArgumentException('Las guías deben ser de venta, emitidas al mismo receptor y sin facturar.');
        }
        $guiasDatos[] = $fila;
    }
    // Sin líneas, la factura toma las de sus guías.
    $entrada = $input['lineas'] ?? null;
    if (($entrada === null || $entrada === []) && $guiasDatos !== []) {
        $deGuias = $db->prepare('SELECT presentacion_id, cantidad, precio_unitario FROM dte_documento_lineas WHERE empresa_id = ? AND documento_id = ? ORDER BY linea');
        $entrada = [];
        foreach ($guiasDatos as $guia) {
            $deGuias->execute([$empresa, $guia['id']]);
            foreach ($deGuias->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $entrada[] = ['presentacionId' => (string) $l['presentacion_id'], 'cantidad' => rtrim(rtrim((string) $l['cantidad'], '0'), '.'),
                    'precio' => (int) $l['precio_unitario']];
            }
        }
    }
    $lineas = lineasEmision($db, $empresa, $tipo, $entrada);

    // Referencias.
    $referencias = [];
    foreach ($guiasDatos as $guia) {
        $referencias[] = ['tipo' => 52, 'folio' => (int) $guia['folio'], 'fecha' => $guia['fecha_emision'], 'razon' => 'Guía de despacho facturada',
            'referido' => (string) $guia['id'], 'archivo' => null];
    }
    $esNota = in_array($tipo, [56, 61], true);
    $codigo = $input['codigoReferencia'] ?? null;
    $razon = is_string($input['razon'] ?? null) ? trim($input['razon']) : '';
    if ($esNota) {
        if (!in_array($codigo, $tipo === 61 ? [1, 2, 3] : [3], true) || $razon === '' || mb_strlen($razon) > 90) {
            throw new InvalidArgumentException($tipo === 61 ? 'Indique si la nota anula, corrige texto o corrige montos, y el motivo.'
                : 'La nota de débito corrige montos: indique el motivo.');
        }
        $ref = $input['referencia'] ?? null;
        if (!is_array($ref)) {
            throw new InvalidArgumentException('La nota debe referirse a un documento.');
        }
        if (isset($ref['documentoId'])) {
            $buscar = $db->prepare("SELECT id, tipo, folio, fecha_emision FROM dte_documentos WHERE empresa_id = ? AND id = ? AND ambiente = 'produccion'
                AND tipo IN (33, 34, 39, 41, 56, 61)");
            $buscar->execute([$empresa, $ref['documentoId']]);
            $original = $buscar->fetch(PDO::FETCH_ASSOC);
            if (!$original) {
                throw new InvalidArgumentException('El documento referido no existe en esta empresa.');
            }
            $referencias[] = ['tipo' => (int) $original['tipo'], 'folio' => (int) $original['folio'], 'fecha' => $original['fecha_emision'],
                'codigo' => $codigo, 'razon' => $razon, 'referido' => (string) $original['id'], 'archivo' => null];
        } else {
            // Referencia histórica: un documento emitido antes de Lite, con evidencia respaldada.
            $tipoRef = $ref['tipo'] ?? null;
            $folioRef = $ref['folio'] ?? null;
            $fechaRef = $ref['fecha'] ?? null;
            $archivo = $ref['archivoId'] ?? null;
            $evidencia = $db->prepare('SELECT 1 FROM archivos_recibidos WHERE empresa_id = ? AND id = ?');
            $evidencia->execute([$empresa, is_string($archivo) ? $archivo : '']);
            if (!in_array($tipoRef, [33, 34, 39, 41], true) || !is_int($folioRef) || $folioRef < 1 || !is_string($fechaRef)
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fechaRef) || $evidencia->fetchColumn() === false) {
                throw new InvalidArgumentException('La referencia histórica necesita tipo, folio, fecha y un archivo respaldado que la pruebe.');
            }
            $referencias[] = ['tipo' => $tipoRef, 'folio' => $folioRef, 'fecha' => $fechaRef, 'codigo' => $codigo, 'razon' => $razon,
                'referido' => null, 'archivo' => (string) $archivo];
        }
    }

    // Despacho de la guía.
    $opciones = [];
    $destino = null;
    if ($tipo === 52) {
        $traslado = $input['indTraslado'] ?? null;
        if (!is_int($traslado) || !isset(TRASLADOS_GUIA[$traslado])) {
            throw new InvalidArgumentException('Indique el tipo de traslado de la guía: venta o traslado interno.');
        }
        $opciones['indTraslado'] = $traslado;
        if (isset($input['tipoDespacho'])) {
            if (!in_array($input['tipoDespacho'], [1, 2, 3], true)) {
                throw new InvalidArgumentException('Tipo de despacho inválido.');
            }
            $opciones['tipoDespacho'] = $input['tipoDespacho'];
        }
        if ($traslado === 5) {
            $destino = $input['localDestino'] ?? null;
            $existe = $db->prepare('SELECT 1 FROM locales WHERE empresa_id = ? AND codigo = ?');
            $existe->execute([$empresa, is_string($destino) ? $destino : '']);
            if ($destino === $local || $existe->fetchColumn() === false) {
                throw new InvalidArgumentException('El traslado interno va a otro local de la empresa.');
            }
        }
    }
    $fisica = ($input['devolucionFisica'] ?? false) === true;
    if ($fisica && $tipo !== 61) {
        throw new InvalidArgumentException('Solo la nota de crédito registra devolución física.');
    }
    $conStock = array_values(array_filter(array_map(static fn (array $l) => $l['stock'], $lineas)));

    $emisor = emisorDte($db, $empresa, 'produccion');
    $firma = abrirCertificadoDte($db, $empresa, 'produccion');
    $zona = $db->prepare('SELECT zona_horaria FROM empresas WHERE id = ?');
    $zona->execute([$empresa]);
    $ahora = new DateTimeImmutable('now', new DateTimeZone((string) $zona->fetchColumn() ?: 'America/Santiago'));
    $avisos = [];

    $db->beginTransaction();
    try {
        $documento = generarDocumentoDte($db, $empresa, 'produccion', $usuario, $emisor, $firma, ['tipo' => $tipo, 'clave' => 'emision-' . $clave,
            'origen' => 'emision', 'referencia' => TIPOS_EMISION[$tipo] . ' a ' . $receptor['nombre'], 'receptor' => $receptor, 'opciones' => $opciones,
            'lineas' => array_map(static fn (array $l): array => array_diff_key($l, ['stock' => true]), $lineas),
            'referencias' => array_map(static fn (array $r): array => array_diff_key($r, ['referido' => true, 'archivo' => true]), $referencias)], $ahora);
        $id = $documento['id'];
        $db->prepare('UPDATE dte_documentos SET receptor_id = ?, local_codigo = ? WHERE empresa_id = ? AND id = ?')->execute([$receptor['id'], $local, $empresa, $id]);
        $montos = ConstructorDte::calcular($tipo, array_map(static fn (array $l): array => array_diff_key($l, ['stock' => true]), $lineas));
        $guardar = $db->prepare('INSERT INTO dte_documento_lineas (empresa_id, documento_id, linea, producto_id, presentacion_id, nombre, presentacion,
            cantidad, equivalencia, precio_unitario, monto, exento) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($lineas as $i => $l) {
            $guardar->execute([$empresa, $id, $i + 1, $l['stock']['producto'] ?? null, $l['stock']['linea']['presentacionId'] ?? null, $l['nombre'],
                $l['stock']['presentacion'] ?? null, $l['cantidad'], $l['stock']['equivalencia'] ?? null, $l['precio'], $montos['lineas'][$i]['monto'],
                (int) $l['exento']]);
        }
        $ref = $db->prepare('INSERT INTO dte_referencias (empresa_id, documento_id, linea, tipo, folio, fecha, codigo, razon, referido_id, archivo_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($referencias as $i => $r) {
            $ref->execute([$empresa, $id, $i + 1, $r['tipo'], $r['folio'], $r['fecha'], $r['codigo'] ?? null, $r['razon'], $r['referido'], $r['archivo']]);
        }
        $facturada = $db->prepare('INSERT INTO dte_guias_facturadas (empresa_id, guia_id, factura_id) VALUES (?, ?, ?)');
        foreach ($guiasDatos as $guia) {
            $facturada->execute([$empresa, $guia['id'], $id]);
        }

        // Stock: una sola salida física por mercadería. La factura con guías no mueve: ya salió con cada guía.
        $operacion = static function (string $tipoOperacion, ?string $hacia) use ($db, $empresa, $local, $usuario, $id, $documento, $tipo): string {
            $db->prepare('INSERT INTO stock_operaciones (empresa_id, local_codigo, tipo, local_destino, causa, clave, usuario_id, dte_documento_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$empresa, $local, $tipoOperacion, $hacia, TIPOS_EMISION[$tipo] . ' ' . $documento['folio'],
                'dte:' . $id, $usuario, $id]);
            return (string) $db->lastInsertId();
        };
        if ($conStock !== [] && in_array($tipo, [33, 34], true) && $guiasDatos === []) {
            $avisos = aplicarMovimientosInventario($db, $empresa, $operacion('despacho', null), $local, null, false, $conStock, true);
        } elseif ($conStock !== [] && $tipo === 52 && $opciones['indTraslado'] === 1) {
            $avisos = aplicarMovimientosInventario($db, $empresa, $operacion('despacho', null), $local, null, false, $conStock, true);
        } elseif ($conStock !== [] && $tipo === 52) {
            // Traslado interno: es el traspaso, con su lote y vencimiento en el destino. No admite saldo negativo.
            aplicarMovimientosInventario($db, $empresa, $operacion('traspaso', $destino), $local, $destino, false, $conStock);
        } elseif ($conStock !== [] && $tipo === 61 && $fisica) {
            // Si la nota se refiere a una factura o guía de Lite que despachó, la mercadería vuelve a los lotes de los que salió,
            // como la devolución de la caja; si no (referencia histórica o sin despacho), entra como lote nuevo.
            $despachos = [];
            foreach ($referencias as $r) {
                if ($r['referido'] !== null) {
                    $despachos[] = $r['referido'];
                    $guiasDe = $db->prepare('SELECT guia_id FROM dte_guias_facturadas WHERE empresa_id = ? AND factura_id = ?');
                    $guiasDe->execute([$empresa, $r['referido']]);
                    array_push($despachos, ...array_map('strval', $guiasDe->fetchAll(PDO::FETCH_COLUMN)));
                }
            }
            $porProducto = [];
            foreach ($conStock as $linea) {
                $porProducto[$linea['producto']] = bcadd($porProducto[$linea['producto']] ?? '0', $linea['base'], 6);
            }
            $despacho = false;
            if ($despachos !== []) {
                $marcas = implode(',', array_fill(0, count($despachos), '?'));
                $hubo = $db->prepare("SELECT 1 FROM stock_operaciones WHERE empresa_id = ? AND tipo = 'despacho' AND dte_documento_id IN ($marcas) LIMIT 1");
                $hubo->execute([$empresa, ...$despachos]);
                $despacho = $hubo->fetchColumn() !== false;
            }
            $lotes = [];
            foreach ($despacho ? array_keys($porProducto) : [] as $producto) {
                $lotes[$producto] = lotesDespachadosInventario($db, $empresa, $local, $despachos, (string) $producto);
            }
            if (!$despacho) {
                aplicarMovimientosInventario($db, $empresa, $operacion('devolucion_cliente', null), $local, null, true, $conStock);
            } else {
                foreach ($porProducto as $producto => $cantidad) {
                    $pendiente = array_reduce($lotes[$producto], static fn (string $suma, array $l): string => bcadd($suma, (string) $l['pendiente'], 6), '0');
                    if (bccomp($cantidad, $pendiente, 6) > 0) {
                        throw new InvalidArgumentException('Vuelven más unidades de las que despachó el documento desde este local: quedan '
                            . rtrim(rtrim($pendiente, '0'), '.') . ' por devolver.');
                    }
                }
                $idOperacion = $operacion('devolucion_cliente', null);
                foreach ($conStock as $linea) {
                    reponerEnLotesInventario($db, $empresa, $idOperacion, $local, ['producto' => (string) $linea['producto'], 'base' => $linea['base'],
                        'cantidad' => $linea['cantidad'], 'presentacion' => $linea['presentacion'], 'equivalencia' => $linea['equivalencia']],
                        lotesDespachadosInventario($db, $empresa, $local, $despachos, (string) $linea['producto']));
                }
            }
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
    return detalleEmitido($db, $empresa, $id) + ['repetido' => false, 'avisos' => array_map(static fn (array $n): string => $n['nombre']
        . ' quedó con saldo ' . rtrim(rtrim($n['saldo'], '0'), '.') . ' en este local.', $avisos)];
}

/** Documento emitido con receptor, líneas, referencias, guías facturadas y estado ante el SII. */
function detalleEmitido(PDO $db, string $empresa, string $id): array
{
    $query = $db->prepare('SELECT d.id, d.tipo, d.folio, d.fecha_emision, d.monto_total, d.estado, d.track_id, d.local_codigo, d.xml,
            c.razon_social AS receptor, c.rut AS receptor_rut, f.factura_id, ff.folio AS factura_folio
        FROM dte_documentos d LEFT JOIN contactos c ON c.empresa_id = d.empresa_id AND c.id = d.receptor_id
        LEFT JOIN dte_guias_facturadas f ON f.empresa_id = d.empresa_id AND f.guia_id = d.id
        LEFT JOIN dte_documentos ff ON ff.empresa_id = f.empresa_id AND ff.id = f.factura_id
        WHERE d.empresa_id = ? AND d.id = ?');
    $query->execute([$empresa, $id]);
    $fila = $query->fetch(PDO::FETCH_ASSOC);
    if (!$fila) {
        throw new RuntimeException('El documento no existe en esta empresa.', 404);
    }
    $lineas = $db->prepare('SELECT linea, nombre, cantidad, precio_unitario, monto, exento FROM dte_documento_lineas WHERE empresa_id = ? AND documento_id = ? ORDER BY linea');
    $lineas->execute([$empresa, $id]);
    $refs = $db->prepare('SELECT tipo, folio, fecha, codigo, razon, referido_id, archivo_id FROM dte_referencias WHERE empresa_id = ? AND documento_id = ? ORDER BY linea');
    $refs->execute([$empresa, $id]);
    $traslado = preg_match('#<IndTraslado>(\d)</IndTraslado>#', $fila['xml'], $m) ? (int) $m[1] : null;
    return ['id' => (string) $fila['id'], 'tipo' => (int) $fila['tipo'], 'folio' => (int) $fila['folio'], 'fecha' => $fila['fecha_emision'],
        'total' => (int) $fila['monto_total'], 'estado' => $fila['estado'], 'trackId' => $fila['track_id'], 'local' => $fila['local_codigo'],
        'receptor' => $fila['receptor'], 'receptorRut' => $fila['receptor_rut'] === null ? null : formatoRut($fila['receptor_rut']),
        'indTraslado' => $traslado, 'facturadaEn' => $fila['factura_folio'] === null ? null : (int) $fila['factura_folio'],
        'lineas' => array_map(static fn (array $l): array => ['linea' => (int) $l['linea'], 'nombre' => $l['nombre'], 'cantidad' => $l['cantidad'],
            'precio' => (int) $l['precio_unitario'], 'monto' => (int) $l['monto'], 'exento' => (bool) $l['exento']], $lineas->fetchAll(PDO::FETCH_ASSOC)),
        'referencias' => array_map(static fn (array $r): array => ['tipo' => (int) $r['tipo'], 'folio' => (int) $r['folio'], 'fecha' => $r['fecha'],
            'codigo' => $r['codigo'] === null ? null : (int) $r['codigo'], 'razon' => $r['razon'],
            'documentoId' => $r['referido_id'] === null ? null : (string) $r['referido_id'], 'archivoId' => $r['archivo_id'] === null ? null : (string) $r['archivo_id']],
            $refs->fetchAll(PDO::FETCH_ASSOC))];
}

/** Historial de documentos emitidos que no son boletas, más recientes primero, y guías de venta por facturar. */
function listarEmitidos(PDO $db, string $empresa, string $cursor): array
{
    $query = $db->prepare("SELECT d.id FROM dte_documentos d WHERE d.empresa_id = ? AND d.ambiente = 'produccion' AND d.tipo IN (33, 34, 52, 56, 61)
        AND (? = '0' OR d.id < ?) ORDER BY d.id DESC LIMIT 51");
    $query->execute([$empresa, $cursor, $cursor]);
    $ids = $query->fetchAll(PDO::FETCH_COLUMN);
    $hayMas = count($ids) === 51;
    $documentos = array_map(static fn ($id): array => array_diff_key(detalleEmitido($db, $empresa, (string) $id), ['lineas' => true]),
        array_slice($ids, 0, 50));
    return ['documentos' => $documentos, 'siguiente' => $hayMas ? end($documentos)['id'] : null];
}
