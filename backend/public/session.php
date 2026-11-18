<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/SesionRepository.php';
require __DIR__ . '/../src/LimiteSesion.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true)) {
    header('Allow: GET, POST, PATCH, DELETE');
    responder(405, ['error' => 'Método no disponible.']);
}

// Un navegador de otro origen no puede enviar este encabezado sin un preflight autorizado.
if ($method !== 'GET' && ($_SERVER['HTTP_X_MYPOSLITE_REQUEST'] ?? '') !== '1') {
    responder(403, ['error' => 'Solicitud no autorizada.']);
}

try {
    $input = [];
    if ($method === 'POST' || $method === 'PATCH') {
        $raw = file_get_contents('php://input', false, null, 0, 8193);
        if ($raw === false || strlen($raw) > 8192) {
            responder(413, ['error' => 'Solicitud demasiado grande.']);
        }
        $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($input)) {
            responder(400, ['error' => 'Solicitud inválida.']);
        }
    }

    if (!iniciarSesionLite()) {
        responder(503, ['error' => 'No se pudo iniciar la sesión.']);
    }

    if ($method === 'DELETE') {
        $_SESSION = [];
        session_destroy();
        setcookie(session_name(), '', ['expires' => 1, 'path' => '/api/', 'httponly' => true,
            'secure' => getenv('MYPOSLITE_COOKIE_SECURE') === '1', 'samesite' => 'Strict']);
        responder(200, ['usuario' => null]);
    }

    $db = conectarBaseLite();
    $repository = new SesionRepository($db);
    if ($method === 'POST') {
        $correo = $input['correo'] ?? null;
        $clave = $input['clave'] ?? null;
        if (!is_string($correo) || !is_string($clave) || strlen($correo) > 254 ||
            strlen($clave) > 72 || str_contains($clave, "\0") || !filter_var(trim($correo), FILTER_VALIDATE_EMAIL)) {
            responder(400, ['error' => 'Ingrese un correo y una contraseña válidos.']);
        }
        $resultado = (new LimiteSesion($db))->autenticar($repository, strtolower(trim($correo)), $clave);
        if ($resultado['espera'] > 0) {
            header('Retry-After: ' . $resultado['espera']);
            responder(429, ['error' => 'Demasiados intentos. Vuelva a intentar en unos minutos.']);
        }
        $user = $resultado['usuario'];
        if ($user === null) {
            responder(401, ['error' => 'Correo o contraseña incorrectos.']);
        }
        session_regenerate_id(true);
        $_SESSION = ['usuario_id' => $user['id'], 'actividad' => time()];
    } else {
        $id = idSesionLite();
        $user = $id !== null ? $repository->usuarioActivo($id) : null;
        if ($user === null) {
            $_SESSION = [];
            responder($method === 'GET' ? 200 : 401, ['usuario' => null]);
        }
        $_SESSION['actividad'] = time();
    }

    $contextos = $repository->contextos($user['id']);
    $seleccion = $method === 'PATCH' ? $input : ($_SESSION['contexto'] ?? []);
    $contexto = null;
    foreach ($contextos as $empresa) {
        if ($empresa['id'] !== ($seleccion['empresaId'] ?? null)) {
            continue;
        }
        foreach ($empresa['locales'] as $local) {
            if ($local['codigo'] === ($seleccion['localCodigo'] ?? null)) {
                $contexto = ['empresaId' => $empresa['id'], 'localCodigo' => $local['codigo']];
                break 2;
            }
        }
    }
    if ($method === 'PATCH' && $contexto === null) {
        responder(403, ['error' => 'El local no está disponible para este usuario.']);
    }
    $_SESSION['contexto'] = $contexto;
    responder(200, ['usuario' => $user, 'empresas' => $contextos, 'contexto' => $contexto]);
} catch (JsonException $error) {
    responder(400, ['error' => 'JSON inválido.']);
} catch (Throwable $error) {
    error_log('MyPOS Lite sesión: ' . $error::class);
    responder(500, ['error' => 'No se pudo completar la solicitud.']);
}
