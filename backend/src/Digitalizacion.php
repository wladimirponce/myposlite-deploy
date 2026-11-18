<?php

declare(strict_types=1);

require_once __DIR__ . '/Archivos.php';
require_once __DIR__ . '/LectorGemini.php';
require_once __DIR__ . '/LectorXmlDte.php';
require_once __DIR__ . '/Recepcion.php';
require_once __DIR__ . '/Rut.php';

/**
 * Digitalización de documentos recibidos: la lectura de un archivo ya respaldado produce un borrador que nunca
 * mueve stock. El operador lo revisa en el formulario de ingreso y lo confirma con registrarDocumentoRecibido.
 */
final class LecturaEnCurso extends RuntimeException
{
}

/** Clave estable de una línea del proveedor, para recordar a qué presentación corresponde. */
function claveProveedorLinea(?string $codigo, ?string $nombre): ?string
{
    $base = $codigo !== null && trim($codigo) !== '' ? 'codigo:' . $codigo : ($nombre !== null && trim($nombre) !== '' ? 'nombre:' . $nombre : null);
    return $base === null ? null : mb_substr(preg_replace('/\s+/u', ' ', mb_strtolower(trim($base))) ?? $base, 0, 160);
}

/**
 * Lee un archivo con el servicio configurado. Si ya hay una lectura lista sin documento y no se pide reintentar,
 * la reutiliza para no repetir el costo. Toda lectura, exitosa o fallida, queda registrada.
 *
 * @return array{id: string, reutilizada: bool}
 */
function leerArchivoRecibido(PDO $db, string $empresa, string $usuario, string $archivo, bool $reintentar): array
{
    $buscar = $db->prepare('SELECT huella, tipo_mime, documento_id, subido_en > UTC_TIMESTAMP() - INTERVAL '
        . DIAS_ARCHIVO_RECIBIDO . ' DAY AS disponible FROM archivos_recibidos WHERE empresa_id = ? AND id = ?');
    $buscar->execute([$empresa, $archivo]);
    $fila = $buscar->fetch(PDO::FETCH_ASSOC);
    if (!$fila) {
        throw new RuntimeException('Archivo no encontrado.', 404);
    }
    if ($fila['documento_id'] !== null) {
        throw new RuntimeException('Ese archivo ya pertenece a un documento ingresado.', 409);
    }
    if (!$fila['disponible']) {
        throw new RuntimeException('El archivo superó el año de conservación.', 410);
    }
    if (!$reintentar) {
        $previa = $db->prepare("SELECT id FROM lecturas_documento WHERE empresa_id = ? AND archivo_id = ? AND estado = 'lista'
            AND documento_id IS NULL ORDER BY id DESC LIMIT 1");
        $previa->execute([$empresa, $archivo]);
        if (($id = $previa->fetchColumn()) !== false) {
            return ['id' => (string) $id, 'reutilizada' => true];
        }
    }
    // Un archivo se lee de a una vez: una segunda petición simultánea no duplica el costo.
    $candado = "myposlite:lectura:$empresa:$archivo";
    $tomar = $db->prepare('SELECT GET_LOCK(?, 0)');
    $tomar->execute([$candado]);
    if ((int) $tomar->fetchColumn() !== 1) {
        throw new LecturaEnCurso('Ese archivo ya se está leyendo. Espere unos segundos.', 409);
    }
    try {
        $db->prepare("INSERT INTO lecturas_documento (empresa_id, archivo_id, estado, modelo, usuario_id) VALUES (?, ?, 'procesando', ?, ?)")
            ->execute([$empresa, $archivo, modeloLectura(), $usuario]);
        $lectura = $db->lastInsertId();
        try {
            $contenido = obtenerObjetoAlmacenamiento($empresa, $fila['huella']);
            if (in_array($fila['tipo_mime'], ['text/xml', 'application/xml'], true)) {
                // Un DTE en XML trae los datos exactos: se lee aquí, sin servicio externo ni costo.
                $rut = $db->prepare('SELECT rut FROM empresas WHERE id = ?');
                $rut->execute([$empresa]);
                $leido = leerDocumentoXmlDte($contenido, (string) $rut->fetchColumn());
            } else {
                $leido = leerDocumentoGemini($contenido, $fila['tipo_mime']);
            }
        } catch (InvalidArgumentException $error) {
            $db->prepare("UPDATE lecturas_documento SET estado = 'error', error = ?, terminada_en = CURRENT_TIMESTAMP(6)
                WHERE empresa_id = ? AND id = ?")->execute([mb_substr($error->getMessage(), 0, 300), $empresa, $lectura]);
            throw $error;
        } catch (LecturaNoDisponible | AlmacenamientoNoDisponible $error) {
            $db->prepare("UPDATE lecturas_documento SET estado = 'error', error = ?, terminada_en = CURRENT_TIMESTAMP(6)
                WHERE empresa_id = ? AND id = ?")->execute([mb_substr($error->getMessage(), 0, 300), $empresa, $lectura]);
            throw new LecturaNoDisponible($error->getMessage(), $error->getCode() ?: 503, $error);
        }
        $db->prepare("UPDATE lecturas_documento SET estado = 'lista', modelo = ?, resultado = ?, tokens_entrada = ?, tokens_salida = ?,
            terminada_en = CURRENT_TIMESTAMP(6) WHERE empresa_id = ? AND id = ?")
            ->execute([$leido['modelo'], json_encode($leido['resultado'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $leido['tokensEntrada'], $leido['tokensSalida'], $empresa, $lectura]);
        return ['id' => (string) $lectura, 'reutilizada' => false];
    } finally {
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$candado]);
    }
}

/**
 * Borrador de una lectura: lo detectado y lo que Lite sugiere a partir del catálogo, los contactos,
 * las guías pendientes y la memoria del proveedor. Las sugerencias se calculan al consultar,
 * así que reflejan asociaciones aprendidas después de la lectura.
 */
function borradorLectura(PDO $db, string $empresa, string $local, string $id): array
{
    $query = $db->prepare('SELECT l.id, l.archivo_id, l.estado, l.modelo, l.resultado, l.error, l.creada_en, l.documento_id,
            a.nombre AS archivo
        FROM lecturas_documento l JOIN archivos_recibidos a ON a.empresa_id = l.empresa_id AND a.id = l.archivo_id
        WHERE l.empresa_id = ? AND l.id = ?');
    $query->execute([$empresa, $id]);
    $lectura = $query->fetch(PDO::FETCH_ASSOC);
    if (!$lectura) {
        throw new RuntimeException('Lectura no encontrada.', 404);
    }
    $base = ['id' => (string) $lectura['id'], 'archivoId' => (string) $lectura['archivo_id'], 'archivo' => $lectura['archivo'],
        'estado' => $lectura['estado'], 'modelo' => $lectura['modelo'], 'error' => $lectura['error'],
        'documentoId' => $lectura['documento_id'] === null ? null : (string) $lectura['documento_id'], 'avisos' => []];
    if ($lectura['estado'] !== 'lista') {
        return $base;
    }
    $detectado = json_decode((string) $lectura['resultado'], true, 16, JSON_THROW_ON_ERROR);
    $base['detectado'] = $detectado;
    $avisos = [];

    $proveedor = null;
    if ($detectado['proveedorRut'] !== null) {
        try {
            $rut = canonizarRut($detectado['proveedorRut']);
            $buscar = $db->prepare('SELECT id, razon_social FROM contactos WHERE empresa_id = ? AND rut = ? AND proveedor = 1');
            $buscar->execute([$empresa, $rut]);
            $proveedor = $buscar->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($proveedor === null) {
                $avisos[] = "El proveedor {$detectado['proveedorNombre']} (RUT {$detectado['proveedorRut']}) no está registrado como proveedor.";
            }
        } catch (InvalidArgumentException) {
            $avisos[] = 'El RUT del proveedor no se leyó correctamente. Seleccione el proveedor.';
        }
    } else {
        $avisos[] = 'No se leyó el RUT del proveedor. Seleccione el proveedor.';
    }
    $base['proveedorId'] = $proveedor ? (string) $proveedor['id'] : null;

    if ($proveedor && $detectado['tipo'] !== null && $detectado['folio'] !== null) {
        $existe = $db->prepare('SELECT 1 FROM documentos_recibidos WHERE empresa_id = ? AND proveedor_id = ? AND tipo = ? AND folio = ?');
        $existe->execute([$empresa, $proveedor['id'], $detectado['tipo'], $detectado['folio']]);
        if ($existe->fetchColumn() !== false) {
            $avisos[] = 'Ese documento ya fue ingresado: ' . TIPOS_DOCUMENTO_RECIBIDO[$detectado['tipo']] . " {$detectado['folio']}.";
        }
    }
    $base['referidoId'] = null;
    if ($proveedor && in_array($detectado['tipo'], [56, 61], true) && $detectado['folioReferido'] !== null) {
        $factura = $db->prepare('SELECT id FROM documentos_recibidos WHERE empresa_id = ? AND proveedor_id = ? AND tipo IN (33, 34) AND folio = ?');
        $factura->execute([$empresa, $proveedor['id'], $detectado['folioReferido']]);
        $base['referidoId'] = ($ref = $factura->fetchColumn()) === false ? null : (string) $ref;
        if ($base['referidoId'] === null) {
            $avisos[] = "La factura {$detectado['folioReferido']} que modifica la nota no está ingresada.";
        }
    }

    // Presentación sugerida por línea: código de barras, memoria del proveedor y luego SKU.
    $porBarras = $db->prepare('SELECT pp.id, pp.nombre, p.id AS producto_id, p.nombre AS producto FROM producto_presentaciones pp
        JOIN productos p ON p.empresa_id = pp.empresa_id AND p.id = pp.producto_id WHERE pp.empresa_id = ? AND pp.codigo_barras = ?');
    $porMemoria = $db->prepare('SELECT pp.id, pp.nombre, p.id AS producto_id, p.nombre AS producto FROM proveedor_producto_memoria m
        JOIN producto_presentaciones pp ON pp.empresa_id = m.empresa_id AND pp.id = m.presentacion_id
        JOIN productos p ON p.empresa_id = pp.empresa_id AND p.id = pp.producto_id
        WHERE m.empresa_id = ? AND m.proveedor_id = ? AND m.clave = ?');
    $porSku = $db->prepare('SELECT pp.id, pp.nombre, p.id AS producto_id, p.nombre AS producto FROM productos p
        JOIN producto_presentaciones pp ON pp.empresa_id = p.empresa_id AND pp.producto_id = p.id
        WHERE p.empresa_id = ? AND p.sku = ? ORDER BY pp.equivalencia = 1 DESC, pp.id LIMIT 1');
    $lineas = [];
    $sinAsociar = 0;
    foreach ($detectado['items'] as $item) {
        $clave = claveProveedorLinea($item['codigo'], $item['nombre']);
        $sugerida = null;
        $metodo = null;
        if ($item['codigoBarras'] !== null) {
            $porBarras->execute([$empresa, $item['codigoBarras']]);
            [$sugerida, $metodo] = [$porBarras->fetch(PDO::FETCH_ASSOC) ?: null, 'código de barras'];
        }
        if ($sugerida === null && $proveedor && $clave !== null) {
            $porMemoria->execute([$empresa, $proveedor['id'], $clave]);
            [$sugerida, $metodo] = [$porMemoria->fetch(PDO::FETCH_ASSOC) ?: null, 'asociación anterior del proveedor'];
        }
        if ($sugerida === null && $item['codigo'] !== null) {
            $porSku->execute([$empresa, $item['codigo']]);
            [$sugerida, $metodo] = [$porSku->fetch(PDO::FETCH_ASSOC) ?: null, 'SKU'];
        }
        $sinAsociar += $sugerida === null ? 1 : 0;
        $lineas[] = $item + ['claveProveedor' => $clave, 'presentacionId' => $sugerida ? (string) $sugerida['id'] : null,
            'productoId' => $sugerida ? (string) $sugerida['producto_id'] : null,
            'sugerencia' => $sugerida ? "{$sugerida['producto']} · {$sugerida['nombre']}" : null, 'metodo' => $sugerida ? $metodo : null];
    }
    if ($sinAsociar > 0) {
        $avisos[] = $sinAsociar === 1 ? 'Una línea no se asoció a un producto del catálogo.' : "$sinAsociar líneas no se asociaron a productos del catálogo.";
    }
    if ($detectado['items'] && $detectado['total'] > 0) {
        $suma = array_sum(array_column($detectado['items'], 'total'));
        $esperado = $detectado['neto'] > 0 ? $detectado['neto'] + $detectado['exento'] : $detectado['total'];
        if ($suma > 0 && abs($suma - $esperado) > 2 && abs($suma - $detectado['total']) > 2) {
            $avisos[] = 'La suma de las líneas no cuadra con el total del documento. Revise cantidades y montos.';
        }
    }
    $base['lineas'] = $lineas;

    // Guías pendientes del proveedor: sugeridas si la factura las menciona o si todos sus productos aparecen en ella.
    $base['guias'] = [];
    if ($proveedor && in_array($detectado['tipo'], [33, 34], true)) {
        $productos = array_filter(array_column($lineas, 'productoId'));
        foreach (guiasPendientes($db, $empresa, $local, (string) $proveedor['id']) as $guia) {
            $guia['sugerida'] = in_array($guia['folio'], $detectado['foliosGuias'], true)
                || ($productos && !array_diff(array_column($guia['lineas'], 'productoId'), $productos));
            $base['guias'][] = $guia;
        }
    }
    $base['avisos'] = $avisos;
    return $base;
}

/** Lecturas sin documento del local, sin archivos descartados, más recientes primero. */
function lecturasPendientes(PDO $db, string $empresa, string $local): array
{
    $query = $db->prepare("SELECT l.id, l.archivo_id, l.estado, l.modelo, l.error, l.creada_en, a.nombre AS archivo
        FROM lecturas_documento l JOIN archivos_recibidos a ON a.empresa_id = l.empresa_id AND a.id = l.archivo_id
        WHERE l.empresa_id = ? AND l.documento_id IS NULL AND a.documento_id IS NULL AND a.descartado_en IS NULL AND a.local_codigo = ?
        AND l.id = (SELECT MAX(x.id) FROM lecturas_documento x WHERE x.empresa_id = l.empresa_id AND x.archivo_id = l.archivo_id)
        ORDER BY l.id DESC LIMIT 50");
    $query->execute([$empresa, $local]);
    return array_map(static fn(array $fila): array => ['id' => (string) $fila['id'], 'archivoId' => (string) $fila['archivo_id']] + $fila,
        $query->fetchAll(PDO::FETCH_ASSOC));
}
