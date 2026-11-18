<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/Correo.php';

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
    ['db' => $db, 'empresa' => $empresa, 'permisos' => $permisos] = contextoAutorizado();
    $configura = in_array('empresa.configurar', $permisos, true);
    if (!$configura && !($method === 'GET' && in_array('operacion.consultar', $permisos, true))) {
        responder(403, ['error' => 'No tiene permiso para esta operación.']);
    }
    if ($method === 'GET') {
        $destinatarios = $db->prepare('SELECT correo, verificado_en FROM destinatarios_cierre WHERE empresa_id = ? ORDER BY correo');
        $destinatarios->execute([$empresa]);
        $envios = $db->prepare('SELECT id, tipo, destinatario, asunto, estado, intentos, ultimo_error, creado_en, enviado_en
            FROM correo_envios WHERE empresa_id = ? ORDER BY id DESC LIMIT 100');
        $envios->execute([$empresa]);
        responder(200, ['configurado' => (string) getenv('MYPOSLITE_SMTP_HOST') !== '', 'puedeConfigurar' => $configura,
            'destinatarios' => $destinatarios->fetchAll(PDO::FETCH_ASSOC),
            'envios' => array_map(static fn (array $f): array => ['id' => (string) $f['id'], 'intentos' => (int) $f['intentos']] + $f,
                $envios->fetchAll(PDO::FETCH_ASSOC))]);
    }
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    $input = json_decode($raw === false ? '' : $raw, true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        responder(400, ['error' => 'Solicitud inválida.']);
    }
    if (($input['accion'] ?? null) === 'verificar') {
        solicitarVerificacionCorreo($db, $empresa, is_string($input['correo'] ?? null) ? $input['correo'] : '');
        responder(202, ['enviado' => true]);
    }
    if (($input['accion'] ?? null) === 'reintentar') {
        // Reenvío controlado: solo un correo fallido vuelve a la cola, con la misma clave; nunca se duplica uno enviado.
        $id = $input['id'] ?? null;
        $cambio = $db->prepare("UPDATE correo_envios SET estado = 'pendiente', intentos = 0, proximo_intento = UTC_TIMESTAMP(6)
            WHERE empresa_id = ? AND id = ? AND estado = 'fallido'");
        $cambio->execute([$empresa, is_string($id) ? $id : '']);
        if ($cambio->rowCount() !== 1) {
            responder(409, ['error' => 'Solo se reintenta un correo fallido de esta empresa.']);
        }
        responder(200, ['estado' => 'pendiente']);
    }
    responder(400, ['error' => 'Acción inválida.']);
} catch (InvalidArgumentException $error) {
    responder(422, ['error' => $error->getMessage()]);
} catch (JsonException) {
    responder(400, ['error' => 'JSON inválido.']);
} catch (Throwable $error) {
    if ($error instanceof RuntimeException && in_array($error->getCode(), [404, 409], true)) {
        responder($error->getCode(), ['error' => $error->getMessage()]);
    }
    error_log('MyPOS Lite correos: ' . $error::class . ' ' . $error->getMessage());
    responder(500, ['error' => 'No se pudo completar la solicitud.']);
}
