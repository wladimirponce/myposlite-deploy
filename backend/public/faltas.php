<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/Faltas.php';

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

if (in_array($method, ['POST', 'PATCH'], true) && ($_SERVER['HTTP_X_MYPOSLITE_REQUEST'] ?? '') !== '1') {
    responder(403, ['error' => 'Solicitud no autorizada.']);
}

try {
    $acceso = contextoAutorizado();
    ['db' => $db, 'empresa' => $empresa, 'local' => $local, 'usuario' => $usuario] = $acceso;

    // Verificar local activo coincidente si se envía cabecera
    $localHeader = rawurldecode($_SERVER['HTTP_X_MYPOSLITE_LOCAL'] ?? '');
    if ($localHeader !== '' && $localHeader !== $local) {
        responder(409, ['error' => 'Cambió el local activo. Vuelva a abrir la función.']);
    }

    if ($method === 'GET') {
        $estado = $_GET['estado'] ?? null;
        if ($estado !== null && !is_string($estado)) {
            responder(400, ['error' => 'Parámetro estado inválido.']);
        }
        responder(200, listarFaltas($db, $empresa, $local, $estado));
    }

    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if ($raw === false || strlen($raw) > 65536) {
        responder(413, ['error' => 'Solicitud demasiado grande.']);
    }

    $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        responder(400, ['error' => 'JSON inválido.']);
    }

    if ($method === 'POST') {
        responder(201, registrarFalta($db, $empresa, $local, $usuario['id'] ?? null, $body));
    }

    if ($method === 'PATCH') {
        $id = trim((string) ($body['id'] ?? ''));
        $nuevoEstado = trim((string) ($body['estado'] ?? ''));
        if ($id === '' || !preg_match('/^[1-9][0-9]{0,19}$/D', $id)) {
            responder(400, ['error' => 'ID de falta inválido.']);
        }
        responder(200, actualizarEstadoFalta($db, $empresa, $id, $nuevoEstado));
    }
} catch (InvalidArgumentException $e) {
    responder(400, ['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    responder(404, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    responder(500, ['error' => 'Error interno del servidor.']);
}
