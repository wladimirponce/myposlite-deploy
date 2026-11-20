<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/CuentasCorrientes.php';

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
    ['db' => $db, 'empresa' => $empresa, 'local' => $local, 'usuario' => $usuario, 'permisos' => $permisos] = contextoAutorizado();
    if (rawurldecode($_SERVER['HTTP_X_MYPOSLITE_LOCAL'] ?? '') !== $local) {
        responder(409, ['error' => 'Cambió el local activo.']);
    }

    $puedeSupervisar = in_array('caja.supervisar', $permisos, true);
    $puedeOperar = $puedeSupervisar || in_array('ventas.operar', $permisos, true) || in_array('operacion.consultar', $permisos, true);
    if (!$puedeOperar) {
        responder(403, ['error' => 'No tiene permiso para acceder a cuentas corrientes.']);
    }

    if ($method === 'GET') {
        $cuentaId = isset($_GET['cuenta_id']) && is_string($_GET['cuenta_id']) ? trim($_GET['cuenta_id']) : '';
        if ($cuentaId !== '') {
            responder(200, historialCuentaCorriente($db, $empresa, $cuentaId));
        }
        $data = listarCuentasCorrientes($db, $empresa);
        $data['puedeSupervisar'] = $puedeSupervisar;
        responder(200, $data);
    }

    $raw = file_get_contents('php://input', false, null, 0, 65537);
    $body = json_decode((string) $raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        responder(400, ['error' => 'Solicitud inválida.']);
    }

    if ($method === 'PATCH') {
        if (!$puedeSupervisar) {
            responder(403, ['error' => 'Solo administradores o supervisores pueden modificar límites de crédito.']);
        }
        $cuentaId = is_scalar($body['cuentaId'] ?? null) ? trim((string) $body['cuentaId']) : '';
        responder(200, actualizarLimiteCuentaCorriente($db, $empresa, $cuentaId, $body['limiteCredito'] ?? null));
    }

    $accion = is_string($body['accion'] ?? null) ? $body['accion'] : 'movimiento';
    if ($accion === 'crear_cliente') {
        responder(201, crearClienteCuentaCorriente($db, $empresa, $usuario, $body));
    }

    responder(201, registrarMovimientoCuentaCorriente($db, $empresa, $local, $usuario, $body));
} catch (InvalidArgumentException $e) {
    responder(422, ['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    responder($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('MyPOS Lite cuentas corrientes: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo procesar la cuenta corriente.']);
}
