<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/Inventario.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    responder(405, ['error' => 'Método no disponible.']);
}
if ($method === 'POST' && ($_SERVER['HTTP_X_MYPOSLITE_REQUEST'] ?? '') !== '1') {
    responder(403, ['error' => 'Solicitud no autorizada.']);
}

try {
    $acceso = contextoAutorizado();
    ['db' => $db, 'empresa' => $empresa, 'local' => $local] = $acceso;
    // Las existencias son del local activo: el encabezado evita operar sobre un local que cambió en otra pestaña.
    if (rawurldecode($_SERVER['HTTP_X_MYPOSLITE_LOCAL'] ?? '') !== $local) {
        responder(409, ['error' => 'Cambió el local activo. Vuelva a abrir el inventario.']);
    }
    $puedeOperar = in_array('inventario.operar', $acceso['permisos'], true);
    if (($method === 'POST' && !$puedeOperar) || (!$puedeOperar && !in_array('operacion.consultar', $acceso['permisos'], true))) {
        responder(403, ['error' => 'No tiene permiso para esta operación.']);
    }

    if ($method === 'GET') {
        if (($_GET['reposicion'] ?? null) === '1') {
            responder(200, listaReposicion($db, $empresa, $local));
        }
        $dias = $_GET['vencimientos'] ?? null;
        if ($dias !== null) {
            if (!is_string($dias) || !preg_match('/^[1-9][0-9]{0,2}$/D', $dias) || (int) $dias > 365) {
                responder(400, ['error' => 'Indique entre 1 y 365 días.']);
            }
            responder(200, informeVencimientos($db, $empresa, $local, (int) $dias));
        }
        $producto = $_GET['producto'] ?? null;
        if ($producto !== null) {
            if (!is_string($producto) || !preg_match('/^[1-9][0-9]{0,19}$/D', $producto)) {
                responder(400, ['error' => 'Producto inválido.']);
            }
            responder(200, historialInventario($db, $empresa, $local, $producto));
        }
        $cursor = $_GET['desde'] ?? '0';
        if (!is_string($cursor) || !preg_match('/^\d{1,20}$/D', $cursor)) {
            responder(400, ['error' => 'Paginación inválida.']);
        }
        responder(200, informeInventario($db, $empresa, $local, $cursor, ($_GET['quiebres'] ?? null) === '1')
            + ['puedeOperar' => $puedeOperar]);
    }

    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if ($raw === false || strlen($raw) > 65536) {
        responder(413, ['error' => 'Solicitud demasiado grande.']);
    }
    $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        responder(400, ['error' => 'Solicitud inválida.']);
    }
    $resultado = registrarOperacionInventario($db, $empresa, $local, $acceso['usuario'], $input);
    responder($resultado['repetida'] ? 200 : 201, $resultado);
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
    if ($error instanceof RuntimeException && $error->getCode() === 409) {
        responder(409, ['error' => $error->getMessage()]);
    }
    // Interbloqueo o espera agotada: nada quedó registrado y la misma clave se puede reintentar.
    if ($error instanceof PDOException && in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true)) {
        header('Retry-After: 1');
        responder(503, ['error' => 'El inventario está ocupado. Vuelva a intentar.']);
    }
    error_log('MyPOS Lite inventario: ' . $error::class);
    responder(500, ['error' => 'No se pudo completar la solicitud.']);
}
