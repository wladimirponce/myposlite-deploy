<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/Emision.php';

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
if ($method === 'POST' && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1024 * 1024) {
    responder(413, ['error' => 'El archivo es demasiado grande.']);
}

try {
    $acceso = contextoAutorizado();
    ['db' => $db, 'empresa' => $empresa] = $acceso;
    $configurar = in_array('empresa.configurar', $acceso['permisos'], true);
    if (($method === 'POST' && !$configurar) || (!$configurar && !in_array('operacion.consultar', $acceso['permisos'], true))) {
        responder(403, ['error' => 'No tiene permiso para esta operación.']);
    }

    if ($method === 'GET') {
        $envio = $_GET['envio'] ?? null;
        if ($envio !== null) {
            // Descarga del sobre firmado para revisarlo o subirlo a mano. Contiene el certificado público, no secretos.
            if (!is_string($envio) || !preg_match('/^[1-9][0-9]{0,19}$/D', $envio)) {
                responder(400, ['error' => 'Envío inválido.']);
            }
            $query = $db->prepare('SELECT xml, huella FROM dte_envios WHERE empresa_id = ? AND id = ?');
            $query->execute([$empresa, $envio]);
            $fila = $query->fetch(PDO::FETCH_ASSOC);
            if (!$fila) {
                responder(404, ['error' => 'El envío no existe en esta empresa.']);
            }
            header('Content-Type: application/xml; charset=ISO-8859-1');
            header('Content-Disposition: attachment; filename="envio-' . $envio . '.xml"');
            header('X-MyPOSLite-Huella: ' . $fila['huella']);
            echo $fila['xml'];
            exit;
        }
        responder(200, estadoDte($db, $empresa) + ['puedeConfigurar' => $configurar]);
    }

    $accion = $_POST['accion'] ?? null;
    if ($accion === 'certificado' || $accion === 'caf') {
        $demo = $db->prepare('SELECT es_demo FROM empresas WHERE id = ?');
        $demo->execute([$empresa]);
        if ($demo->fetchColumn()) {
            responder(409, ['error' => 'La empresa de demostración no admite certificados ni CAF.']);
        }
        $archivo = $_FILES['archivo'] ?? null;
        if (!is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
            throw new InvalidArgumentException('Seleccione el archivo.');
        }
        $contenido = (string) file_get_contents($archivo['tmp_name'], false, null, 0, 65537);
        if ($accion === 'certificado') {
            responder(201, guardarCertificadoDte($db, $empresa, $acceso['usuario'], (string) ($_POST['ambiente'] ?? ''), $contenido,
                (string) ($_POST['clave'] ?? '')));
        }
        responder(201, cargarCafDte($db, $empresa, $acceso['usuario'], $contenido));
    }

    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if ($raw === false || strlen($raw) > 8192) {
        responder(413, ['error' => 'Solicitud demasiado grande.']);
    }
    $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        responder(400, ['error' => 'Solicitud inválida.']);
    }
    if (($input['accion'] ?? null) === 'emisor') {
        guardarEmisorDte($db, $empresa, $acceso['usuario'], $input);
        responder(200, ['guardado' => true]);
    }
    if (($input['accion'] ?? null) === 'cambio-proveedor') {
        responder(201, registrarCambioProveedorDte($db, $empresa, $acceso['usuario'], $input));
    }
    if (in_array($input['accion'] ?? null, ['enviar', 'consultar'], true)) {
        $envio = $input['envio'] ?? null;
        if (!is_string($envio) || !preg_match('/^[1-9][0-9]{0,19}$/D', $envio)) {
            responder(400, ['error' => 'Envío inválido.']);
        }
        responder(200, $input['accion'] === 'enviar' ? enviarEnvioDte($db, $empresa, $acceso['usuario'], $envio)
            : consultarEnvioDte($db, $empresa, $acceso['usuario'], $envio));
    }
    if (($input['accion'] ?? null) === 'enviar-boletas') {
        responder(200, enviarBoletasPendientesDte($db, $empresa, $acceso['usuario']));
    }
    if (($input['accion'] ?? null) === 'habilitacion') {
        responder(200, cambiarHabilitacionDte($db, $empresa, $acceso['usuario'], $input));
    }
    if (($input['accion'] ?? null) === 'preparar-rcof') {
        $resultado = prepararRcofSetDte($db, $empresa, $acceso['usuario']);
        responder($resultado['repetido'] ? 200 : 201, $resultado);
    }
    if (($input['accion'] ?? null) === 'preparar-set') {
        $resultado = prepararSetBoletasDte($db, $empresa, $acceso['usuario']);
        responder($resultado['repetido'] ? 200 : 201, $resultado);
    }
    responder(400, ['error' => 'Acción desconocida.']);
} catch (InvalidArgumentException $error) {
    responder(422, ['error' => $error->getMessage()]);
} catch (JsonException) {
    responder(400, ['error' => 'JSON inválido.']);
} catch (MotorDteNoDisponible $error) {
    responder(503, ['error' => $error->getMessage()]);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($error instanceof RuntimeException && in_array($error->getCode(), [404, 409, 502], true)) {
        responder($error->getCode(), ['error' => $error->getMessage()]);
    }
    if ($error instanceof PDOException && in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true)) {
        header('Retry-After: 1');
        responder(503, ['error' => 'El motor está ocupado. Vuelva a intentar.']);
    }
    error_log('MyPOS Lite dte: ' . $error::class);
    responder(500, ['error' => 'No se pudo completar la solicitud.']);
}
