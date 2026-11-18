<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/Archivos.php';

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
// PHP descarta el cuerpo que supera post_max_size sin avisar: se informa en vez de responder "sin archivo".
if ($method === 'POST' && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 11 * 1024 * 1024) {
    responder(413, ['error' => 'El archivo supera los 10 MB.']);
}

try {
    $acceso = contextoAutorizado();
    ['db' => $db, 'empresa' => $empresa, 'local' => $local] = $acceso;
    if (rawurldecode($_SERVER['HTTP_X_MYPOSLITE_LOCAL'] ?? '') !== $local) {
        responder(409, ['error' => 'Cambió el local activo. Vuelva a abrir la recepción.']);
    }
    $puedeOperar = in_array('inventario.operar', $acceso['permisos'], true);
    if (($method !== 'GET' && !$puedeOperar) || (!$puedeOperar && !in_array('operacion.consultar', $acceso['permisos'], true))) {
        responder(403, ['error' => 'No tiene permiso para esta operación.']);
    }

    if ($method === 'GET') {
        foreach (['enlace', 'documento'] as $campo) {
            $valor = $_GET[$campo] ?? null;
            if ($valor !== null && (!is_string($valor) || !preg_match('/^[1-9][0-9]{0,19}$/D', $valor))) {
                responder(400, ['error' => 'Identificador inválido.']);
            }
        }
        if (isset($_GET['enlace'])) {
            responder(200, enlaceArchivoRecibido($db, $empresa, $_GET['enlace']));
        }
        responder(200, ['archivos' => listarArchivosRecibidos($db, $empresa, $_GET['documento'] ?? null, $local)]);
    }

    if ($method === 'PATCH') {
        // Descartar saca el archivo de la cola del local; el archivo sigue en la nube.
        $input = json_decode(file_get_contents('php://input', false, null, 0, 1025) ?: '', true);
        $id = is_array($input) ? ($input['id'] ?? null) : null;
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id) || ($input['descartar'] ?? null) !== true) {
            responder(400, ['error' => 'Solicitud inválida.']);
        }
        descartarArchivoRecibido($db, $empresa, $acceso['usuario'], $id);
        responder(200, ['id' => $id, 'descartado' => true]);
    }

    $resultado = respaldarArchivoRecibido($db, $empresa, $local, $acceso['usuario'], $_FILES['archivo'] ?? [],
        $_SERVER['HTTP_USER_AGENT'] ?? null);
    responder($resultado['duplicado'] ? 200 : 201, $resultado);
} catch (InvalidArgumentException $error) {
    responder(422, ['error' => $error->getMessage()]);
} catch (AlmacenamientoNoDisponible $error) {
    // El ingreso manual no depende del respaldo: el documento se puede registrar sin adjunto.
    responder(503, ['error' => $error->getMessage() . ' Puede ingresar el documento sin adjunto y subirlo después.']);
} catch (Throwable $error) {
    if ($error instanceof RuntimeException && in_array($error->getCode(), [404, 409, 410, 413], true)) {
        responder($error->getCode(), ['error' => $error->getMessage()]);
    }
    error_log('MyPOS Lite archivos: ' . $error::class);
    responder(500, ['error' => 'No se pudo completar la solicitud.']);
}
