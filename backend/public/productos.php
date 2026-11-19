<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/CatalogoProducto.php';
require __DIR__ . '/../src/ImportacionCatalogo.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST', 'PATCH'], true)) {
    header('Allow: GET, POST, PATCH');
    responder(405, ['error' => 'Método no disponible.']);
}
if ($method !== 'GET' && ($_SERVER['HTTP_X_MYPOSLITE_REQUEST'] ?? '') !== '1') {
    responder(403, ['error' => 'Solicitud no autorizada.']);
}

try {
    if (!iniciarSesionLite()) {
        responder(503, ['error' => 'No se pudo iniciar la sesión.']);
    }
    $id = idSesionLite();
    $contexto = $_SESSION['contexto'] ?? null;
    if ($id === null) {
        responder(401, ['error' => 'Inicie sesión.']);
    }
    if ($contexto === null) {
        responder(403, ['error' => 'Seleccione un local autorizado.']);
    }
    if (($_SERVER['HTTP_X_MYPOSLITE_EMPRESA'] ?? null) !== $contexto['empresaId']) {
        responder(409, ['error' => 'Cambió la empresa activa. Vuelva a abrir el catálogo.']);
    }

    $db = conectarBaseLite();
    $empresa = $contexto['empresaId'];
    $local = $contexto['localCodigo'];
    $localSolicitado = $_SERVER['HTTP_X_MYPOSLITE_LOCAL'] ?? null;
    if ($localSolicitado !== null && rawurldecode($localSolicitado) !== $local) {
        responder(409, ['error' => 'Cambió el local activo. Vuelva a abrir el catálogo.']);
    }
    $permisos = permisosMaestros($db, $id, $empresa, $local);
    $puedeEditar = in_array('maestros.editar', $permisos, true);
    $puedeLeer = $puedeEditar || array_intersect(['operacion.consultar', 'pos.vender'], $permisos);
    // El costo y el proveedor quedan fuera del alcance de quien solo vende.
    $verCostos = $puedeEditar || in_array('operacion.consultar', $permisos, true);
    if (!$puedeLeer || ($method !== 'GET' && !$puedeEditar)) {
        responder(403, ['error' => 'No tiene permiso para esta operación.']);
    }
    $_SESSION['actividad'] = time();
    session_write_close();

    if ($method === 'GET') {
        $cursor = $_GET['desde'] ?? '0';
        if (!is_string($cursor) || !preg_match('/^\d{1,20}$/D', $cursor)) {
            responder(400, ['error' => 'Paginación inválida.']);
        }
        // ?codigo= resuelve un código de barras a su producto y presentación dentro de la empresa.
        $codigo = $_GET['codigo'] ?? null;
        $presentacionId = null;
        if ($codigo !== null) {
            if (!is_string($codigo) || trim($codigo) === '' || strlen(trim($codigo)) > 48) {
                responder(400, ['error' => 'Código de barras inválido.']);
            }
            $buscar = $db->prepare('SELECT id, producto_id FROM producto_presentaciones WHERE empresa_id = ? AND codigo_barras = ?');
            $buscar->execute([$empresa, trim($codigo)]);
            $encontrada = $buscar->fetch(PDO::FETCH_ASSOC);
            if (!$encontrada) {
                responder(404, ['error' => 'Ningún producto usa ese código de barras.']);
            }
            $presentacionId = (string) $encontrada['id'];
            $cursor = (string) ($encontrada['producto_id'] - 1);
        }
        $limite = $codigo === null ? 51 : 1;
        $query = $db->prepare('SELECT p.id, p.sku, p.nombre, p.unidad_base, p.afecto_iva, p.verificar_edad,
                   p.revision, p.imagen_url, p.principio_activo, p.laboratorio, c.nombre AS categoria, pm.minimo AS minimo_local, p.impuesto_codigo,
                   p.costo, p.margen_minimo, p.proveedor_habitual_id, pr.razon_social AS proveedor_habitual
            FROM productos p
            LEFT JOIN categorias_producto c ON c.empresa_id = p.empresa_id AND c.id = p.categoria_id
            LEFT JOIN contactos pr ON pr.empresa_id = p.empresa_id AND pr.id = p.proveedor_habitual_id
            LEFT JOIN producto_minimos pm ON pm.empresa_id = p.empresa_id AND pm.producto_id = p.id AND pm.local_codigo = ?
            WHERE p.empresa_id = ? AND p.id > ? ORDER BY p.id LIMIT ' . $limite);
        $query->execute([$local, $empresa, $cursor]);
        $productos = $query->fetchAll(PDO::FETCH_ASSOC);
        $hayMas = count($productos) === 51;
        if ($hayMas) {
            array_pop($productos);
        }
        $ids = array_column($productos, 'id');
        $presentaciones = [];
        if ($ids) {
            $params = implode(',', array_fill(0, count($ids), '?'));
            $query = $db->prepare("SELECT id, producto_id, nombre, codigo_barras, equivalencia, precio
                FROM producto_presentaciones WHERE empresa_id = ? AND producto_id IN ($params)
                ORDER BY nombre");
            $query->execute([$empresa, ...$ids]);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row['id'] = (string) $row['id'];
                $presentaciones[$row['producto_id']][] = $row;
            }
        }
        foreach ($productos as &$producto) {
            $producto['id'] = (string) $producto['id'];
            $producto['revision'] = (string) $producto['revision'];
            $producto['afecto_iva'] = (bool) $producto['afecto_iva'];
            $producto['verificar_edad'] = (bool) $producto['verificar_edad'];
            $producto['presentaciones'] = $presentaciones[$producto['id']] ?? [];
            if ($verCostos) {
                $producto['proveedor_habitual_id'] = $producto['proveedor_habitual_id'] === null
                    ? null : (string) $producto['proveedor_habitual_id'];
            } else {
                unset($producto['costo'], $producto['margen_minimo'], $producto['proveedor_habitual_id'], $producto['proveedor_habitual']);
            }
        }
        unset($producto);
        if ($presentacionId !== null) {
            responder(200, ['producto' => $productos[0], 'presentacionId' => $presentacionId]);
        }
        responder(200, ['productos' => $productos, 'puedeEditar' => $puedeEditar,
            'siguiente' => $hayMas ? (string) end($ids) : null]);
    }

    $importacion = $_GET['importacion'] ?? null;
    if ($importacion !== null && ($method !== 'POST' ||
        !in_array($importacion, ['previsualizar', 'confirmar'], true))) {
        responder(400, ['error' => 'Operación de importación inválida.']);
    }
    $max = $importacion === null ? 16384 : 524288;
    $raw = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($raw === false || strlen($raw) > $max) {
        responder(413, ['error' => 'Solicitud demasiado grande.']);
    }
    $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        responder(400, ['error' => 'Solicitud inválida.']);
    }
    if ($importacion !== null) {
        if ($localSolicitado === null) {
            responder(400, ['error' => 'Indique el local para importar.']);
        }
        $resultado = importarCatalogo($db, $empresa, $local, $id, $input, $importacion === 'confirmar');
        responder($resultado['importados'] > 0 ? 201 : 200, $resultado);
    }
    if (array_key_exists('minimoLocal', $input) && $localSolicitado === null) {
        responder(400, ['error' => 'Indique el local para guardar el mínimo.']);
    }
    $db->beginTransaction();
    $producto = guardarProducto($db, $empresa, $local, $id, $input, $method === 'PATCH');
    $db->commit();
    responder($method === 'POST' ? 201 : 200, ['id' => $producto]);
} catch (InvalidArgumentException $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    responder(422, ['error' => $error->getMessage()]);
} catch (JsonException) {
    responder(400, ['error' => 'JSON inválido.']);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($error instanceof RuntimeException && in_array($error->getCode(), [404, 409], true)) {
        responder($error->getCode(), ['error' => $error->getMessage()]);
    }
    if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
        responder(409, ['error' => 'El SKU, código de barras o nombre de presentación ya existe.']);
    }
    error_log('MyPOS Lite productos: ' . $error::class);
    responder(500, ['error' => 'No se pudo completar la solicitud.']);
}
