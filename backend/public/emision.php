<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/Ventas.php';
require __DIR__ . '/../src/EmisionComercial.php';

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
    ['db' => $db, 'empresa' => $empresa, 'local' => $local, 'usuario' => $usuario, 'permisos' => $permisos] = $acceso;
    if (rawurldecode($_SERVER['HTTP_X_MYPOSLITE_LOCAL'] ?? '') !== $local) {
        responder(409, ['error' => 'Cambió el local activo. Vuelva a abrir la emisión.']);
    }
    $emite = in_array('documentos.emitir', $permisos, true);
    if (($method === 'POST' && !$emite) || (!$emite && !in_array('operacion.consultar', $permisos, true))) {
        responder(403, ['error' => 'No tiene permiso para esta operación.']);
    }
    if ($method === 'GET') {
        if (isset($_GET['documento'])) {
            if (!is_string($_GET['documento']) || !preg_match('/^[1-9][0-9]{0,19}$/D', $_GET['documento'])) {
                responder(400, ['error' => 'Documento inválido.']);
            }
            responder(200, detalleEmitido($db, $empresa, $_GET['documento']));
        }
        $cursor = $_GET['desde'] ?? '0';
        if (!is_string($cursor) || !preg_match('/^\d{1,20}$/D', $cursor)) {
            responder(400, ['error' => 'Paginación inválida.']);
        }
        $habilitados = [];
        foreach (array_keys(TIPOS_EMISION) as $tipo) {
            $habilitados[$tipo] = tipoHabilitadoDte($db, $empresa, $tipo);
        }
        responder(200, listarEmitidos($db, $empresa, $cursor) + ['habilitados' => $habilitados, 'puedeEmitir' => $emite]);
    }
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if ($raw === false || strlen($raw) > 65536) {
        responder(413, ['error' => 'Solicitud demasiado grande.']);
    }
    $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        responder(400, ['error' => 'Solicitud inválida.']);
    }
    $resultado = emitirDocumento($db, $empresa, $local, $usuario, $permisos, $input);
    responder($resultado['repetido'] ? 200 : 201, $resultado);
} catch (InvalidArgumentException $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    responder(422, ['error' => $error->getMessage()]);
} catch (JsonException) {
    responder(400, ['error' => 'JSON inválido.']);
} catch (MotorDteNoDisponible $error) {
    responder(503, ['error' => $error->getMessage()]);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($error instanceof RuntimeException && in_array($error->getCode(), [403, 404, 409, 422], true)) {
        responder($error->getCode(), ['error' => $error->getMessage()]);
    }
    if ($error instanceof PDOException && in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true)) {
        header('Retry-After: 1');
        responder(503, ['error' => 'El sistema está ocupado. Vuelva a intentar: el documento no se duplicará.']);
    }
    error_log('MyPOS Lite emision: ' . $error::class . ' ' . $error->getMessage());
    responder(500, ['error' => 'No se pudo completar la solicitud.']);
}
