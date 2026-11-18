<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/Digitalizacion.php';

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
    if (rawurldecode($_SERVER['HTTP_X_MYPOSLITE_LOCAL'] ?? '') !== $local) {
        responder(409, ['error' => 'Cambió el local activo. Vuelva a abrir la recepción.']);
    }
    $puedeOperar = in_array('inventario.operar', $acceso['permisos'], true);
    if (($method === 'POST' && !$puedeOperar) || (!$puedeOperar && !in_array('operacion.consultar', $acceso['permisos'], true))) {
        responder(403, ['error' => 'No tiene permiso para esta operación.']);
    }

    if ($method === 'GET') {
        $id = $_GET['id'] ?? null;
        if ($id === null) {
            responder(200, ['lecturas' => lecturasPendientes($db, $empresa, $local)]);
        }
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id)) {
            responder(400, ['error' => 'Lectura inválida.']);
        }
        responder(200, borradorLectura($db, $empresa, $local, $id));
    }

    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if ($raw === false || strlen($raw) > 4096) {
        responder(413, ['error' => 'Solicitud demasiado grande.']);
    }
    $input = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
    $archivo = is_array($input) ? ($input['archivoId'] ?? null) : null;
    $reintentar = is_array($input) ? ($input['reintentar'] ?? false) : false;
    if (!is_string($archivo) || !preg_match('/^[1-9][0-9]{0,19}$/D', $archivo) || !is_bool($reintentar)) {
        responder(422, ['error' => 'Indique el archivo a leer.']);
    }
    // La lectura puede tardar hasta un minuto: la sesión ya está cerrada y no bloquea otras pestañas.
    set_time_limit(150);
    $resultado = leerArchivoRecibido($db, $empresa, $acceso['usuario'], $archivo, $reintentar);
    responder($resultado['reutilizada'] ? 200 : 201, $resultado + borradorLectura($db, $empresa, $local, $resultado['id']));
} catch (JsonException) {
    responder(400, ['error' => 'JSON inválido.']);
} catch (InvalidArgumentException $error) {
    // Un XML que no es un DTE: queda registrado en la lectura.
    responder(422, ['error' => $error->getMessage()]);
} catch (LecturaNoDisponible $error) {
    // El fallo queda registrado en la lectura y el documento se puede ingresar a mano.
    responder(in_array($error->getCode(), [429, 502, 503], true) ? $error->getCode() : 503,
        ['error' => $error->getMessage(), 'ingresoManual' => true]);
} catch (Throwable $error) {
    if ($error instanceof RuntimeException && in_array($error->getCode(), [404, 409, 410], true)) {
        responder($error->getCode(), ['error' => $error->getMessage()]);
    }
    error_log('MyPOS Lite digitalización: ' . $error::class . ' ' . $error->getMessage());
    responder(500, ['error' => 'No se pudo completar la solicitud.']);
}
