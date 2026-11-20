<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/Gastos.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    header('Allow: GET, POST, DELETE');
    responder(405, ['error' => 'Método no disponible.']);
}

if (in_array($method, ['POST', 'DELETE'], true) && ($_SERVER['HTTP_X_MYPOSLITE_REQUEST'] ?? '') !== '1') {
    responder(403, ['error' => 'Solicitud no autorizada.']);
}

try {
    ['db' => $db, 'empresa' => $empresa, 'local' => $local, 'usuario' => $usuario, 'permisos' => $permisos] = contextoAutorizado();
    if (rawurldecode($_SERVER['HTTP_X_MYPOSLITE_LOCAL'] ?? '') !== $local) {
        responder(409, ['error' => 'Cambió el local activo.']);
    }

    $puedeGestionar = in_array('caja.supervisar', $permisos, true);
    $puedeVer = $puedeGestionar || in_array('operacion.consultar', $permisos, true);
    if (!$puedeVer || ($method !== 'GET' && !$puedeGestionar)) {
        responder(403, ['error' => 'No tiene permiso para operar gastos operacionales.']);
    }

    if ($method === 'GET') {
        $desde = isset($_GET['desde']) && is_string($_GET['desde']) ? $_GET['desde'] : null;
        $hasta = isset($_GET['hasta']) && is_string($_GET['hasta']) ? $_GET['hasta'] : null;
        $cat = isset($_GET['categoria']) && is_string($_GET['categoria']) ? $_GET['categoria'] : null;
        $data = listarGastosOperacionales($db, $empresa, $local, $desde, $hasta, $cat);
        $data['puedeGestionar'] = $puedeGestionar;
        responder(200, $data);
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) && is_string($_GET['id']) ? trim($_GET['id']) : '';
        eliminarGastoOperacional($db, $empresa, $local, $id);
        responder(200, ['eliminado' => true]);
    }

    $raw = file_get_contents('php://input', false, null, 0, 65537);
    $body = json_decode((string) $raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        responder(400, ['error' => 'Solicitud inválida.']);
    }

    responder(201, registrarGastoOperacional($db, $empresa, $local, $usuario, $body));
} catch (InvalidArgumentException $e) {
    responder(422, ['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    responder($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('MyPOS Lite gastos: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo procesar los gastos operacionales.']);
}
