<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/BajaEmpresa.php';

header('Cache-Control: no-store');

function responder(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
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
    ['db' => $db, 'empresa' => $empresa, 'usuario' => $usuario, 'permisos' => $permisos] = contextoAutorizado();
    // Solo el administrador de la empresa configura y da de baja.
    if (!in_array('empresa.configurar', $permisos, true)) {
        responder(403, ['error' => 'Solo el administrador de la empresa puede darla de baja.']);
    }
    if ($method === 'GET' && isset($_GET['descargar'])) {
        $baja = archivoBajaEmpresa($db, $empresa);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="baja-' . $baja['rut'] . '.zip"');
        header('Content-Length: ' . filesize($baja['archivo']));
        header('X-Content-Type-Options: nosniff');
        header('X-MyPOSLite-Huella: ' . $baja['huella']);
        if (readfile($baja['archivo']) !== false && !connection_aborted()) {
            marcarDescargaBajaEmpresa($db, $empresa);
        }
        exit;
    }
    if ($method === 'GET') {
        responder(200, ['baja' => estadoBajaEmpresa($db, $empresa)]);
    }
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    $input = json_decode($raw === false ? '' : $raw, true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        responder(400, ['error' => 'Solicitud inválida.']);
    }
    if (($input['accion'] ?? null) === 'preparar') {
        responder(201, ['baja' => prepararBajaEmpresa($db, $empresa, $usuario)]);
    }
    if (($input['accion'] ?? null) === 'confirmar') {
        responder(200, ['baja' => confirmarBajaEmpresa($db, $empresa, $usuario, $input)]);
    }
    responder(400, ['error' => 'Acción inválida.']);
} catch (InvalidArgumentException $error) {
    responder(422, ['error' => $error->getMessage()]);
} catch (JsonException) {
    responder(400, ['error' => 'JSON inválido.']);
} catch (AlmacenamientoNoDisponible $error) {
    responder(503, ['error' => 'No se pudieron leer los archivos de ingreso: la exportación no quedaría completa. ' . $error->getMessage()]);
} catch (Throwable $error) {
    if ($error instanceof RuntimeException && in_array($error->getCode(), [404, 409], true)) {
        responder($error->getCode(), ['error' => $error->getMessage()]);
    }
    error_log('MyPOS Lite bajas: ' . $error::class . ' ' . $error->getMessage());
    responder(500, ['error' => 'No se pudo completar la solicitud.']);
}
