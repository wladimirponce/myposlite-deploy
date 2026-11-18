<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/Soporte.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
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
    $input = [];
    if ($method === 'POST') {
        $raw = file_get_contents('php://input', false, null, 0, 8193);
        if ($raw === false || strlen($raw) > 8192) {
            responder(413, ['error' => 'Solicitud demasiado grande.']);
        }
        $input = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($input)) {
            responder(400, ['error' => 'Solicitud inválida.']);
        }
    }
    // Bandeja del equipo de soporte: no depende de una empresa activa.
    $deSoporte = isset($_GET['bandeja']) || isset($_GET['expediente']) || ($input['accion'] ?? null) === 'responder';
    if ($deSoporte) {
        if (!iniciarSesionLite()) {
            responder(503, ['error' => 'No se pudo iniciar la sesión.']);
        }
        $usuario = idSesionLite();
        session_write_close();
        if ($usuario === null) {
            responder(401, ['error' => 'Inicie sesión.']);
        }
        $db = conectarBaseLite();
        if (!esSoporte($db, $usuario)) {
            responder(403, ['error' => 'Solo el equipo de soporte de Lite.']);
        }
        if ($method === 'POST') {
            responder(200, responderSoporte($db, $usuario, $input));
        }
        if (isset($_GET['expediente'])) {
            if (!is_string($_GET['expediente']) || !preg_match('/^[1-9][0-9]{0,19}$/D', $_GET['expediente'])) {
                responder(400, ['error' => 'Empresa inválida.']);
            }
            responder(200, expedienteSoporte($db, $_GET['expediente']));
        }
        responder(200, ['solicitudes' => bandejaSoporte($db)]);
    }

    ['db' => $db, 'empresa' => $empresa, 'usuario' => $usuario, 'permisos' => $permisos] = contextoAutorizado();
    if (!array_intersect(['empresa.configurar', 'operacion.consultar'], $permisos)) {
        responder(403, ['error' => 'No tiene permiso para esta operación.']);
    }
    if ($method === 'GET') {
        responder(200, ['solicitudes' => solicitudesEmpresaSoporte($db, $empresa)]);
    }
    if (($input['accion'] ?? null) !== 'pedir') {
        responder(400, ['error' => 'Acción inválida.']);
    }
    responder(201, pedirAyudaSoporte($db, $empresa, $usuario, $input));
} catch (InvalidArgumentException $error) {
    responder(422, ['error' => $error->getMessage()]);
} catch (JsonException) {
    responder(400, ['error' => 'JSON inválido.']);
} catch (Throwable $error) {
    if ($error instanceof RuntimeException && in_array($error->getCode(), [404, 409], true)) {
        responder($error->getCode(), ['error' => $error->getMessage()]);
    }
    error_log('MyPOS Lite soporte: ' . $error::class . ' ' . $error->getMessage());
    responder(500, ['error' => 'No se pudo completar la solicitud.']);
}
