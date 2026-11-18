<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

function codigoUbicacion(mixed $codigo): string
{
    if (!is_string($codigo) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,23}$/D', $codigo)) {
        throw new InvalidArgumentException('El código admite letras minúsculas, números, guion y guion bajo.');
    }
    return $codigo;
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST', 'PATCH'], true)) {
    header('Allow: GET, POST, PATCH');
    responder(405, ['error' => 'Método no disponible.']);
}
if ($method !== 'GET' && ($_SERVER['HTTP_X_MYPOSLITE_REQUEST'] ?? '') !== '1') {
    responder(403, ['error' => 'Solicitud no autorizada.']);
}

try {
    if (!iniciarSesionLite()) {
        responder(503, ['error' => 'No se pudo iniciar la sesión.']);
    }
    $usuario = idSesionLite();
    if ($usuario === null) {
        responder(401, ['error' => 'Inicie sesión.']);
    }
    $contexto = $_SESSION['contexto'] ?? null;
    if (!$contexto) {
        responder(403, ['error' => 'Seleccione un local autorizado.']);
    }
    $empresa = $contexto['empresaId'];
    if (($_SERVER['HTTP_X_MYPOSLITE_EMPRESA'] ?? null) !== $empresa) {
        responder(409, ['error' => 'Cambió la empresa activa. Vuelva a abrir la administración.']);
    }
    $db = conectarBaseLite();
    if (!in_array('empresa.configurar', permisosMaestros($db, $usuario, $empresa, $contexto['localCodigo']), true)) {
        responder(403, ['error' => 'No tiene permiso para administrar locales y cajas.']);
    }
    $_SESSION['actividad'] = time();
    session_write_close();

    if ($method === 'GET') {
        $cajas = $db->prepare('SELECT local_codigo, codigo, nombre FROM cajas
            WHERE empresa_id = ? ORDER BY nombre, codigo');
        $cajas->execute([$empresa]);
        $porLocal = [];
        foreach ($cajas->fetchAll(PDO::FETCH_ASSOC) as $caja) {
            $porLocal[$caja['local_codigo']][] = ['codigo' => $caja['codigo'], 'nombre' => $caja['nombre']];
        }
        $locales = $db->prepare('SELECT codigo, nombre FROM locales WHERE empresa_id = ? ORDER BY nombre, codigo');
        $locales->execute([$empresa]);
        $resultado = $locales->fetchAll(PDO::FETCH_ASSOC);
        foreach ($resultado as &$local) {
            $local['cajas'] = $porLocal[$local['codigo']] ?? [];
        }
        unset($local);
        responder(200, ['locales' => $resultado]);
    }

    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if ($raw === false || strlen($raw) > 4096) {
        responder(413, ['error' => 'Solicitud demasiado grande.']);
    }
    $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        responder(400, ['error' => 'Solicitud inválida.']);
    }
    $tipo = $input['tipo'] ?? null;
    $nombre = $input['nombre'] ?? null;
    if (!in_array($tipo, ['local', 'caja'], true) || !is_string($nombre)
        || !preg_match('/^.{1,120}$/usD', trim($nombre))) {
        throw new InvalidArgumentException('Revise el tipo y el nombre.');
    }
    $codigo = codigoUbicacion($input['codigo'] ?? null);
    $local = $tipo === 'local' ? $codigo : codigoUbicacion($input['localCodigo'] ?? null);
    $caja = $tipo === 'caja' ? $codigo : null;
    $db->beginTransaction();
    $accion = $method === 'POST' ? 'crear' : 'editar';
    if ($method === 'POST' && $tipo === 'local') {
        $db->prepare('INSERT INTO locales (empresa_id, codigo, nombre) VALUES (?, ?, ?)')
            ->execute([$empresa, $local, trim($nombre)]);
    } else {
        $buscar = $db->prepare('SELECT codigo FROM locales WHERE empresa_id = ? AND codigo = ? FOR UPDATE');
        $buscar->execute([$empresa, $local]);
        if ($buscar->fetchColumn() === false) {
            $db->rollBack();
            responder(404, ['error' => 'Local no encontrado.']);
        }
        if ($tipo === 'local') {
            $db->prepare('UPDATE locales SET nombre = ? WHERE empresa_id = ? AND codigo = ?')
                ->execute([trim($nombre), $empresa, $local]);
        } elseif ($method === 'POST') {
            $db->prepare('INSERT INTO cajas (empresa_id, local_codigo, codigo, nombre) VALUES (?, ?, ?, ?)')
                ->execute([$empresa, $local, $caja, trim($nombre)]);
        } else {
            $buscar = $db->prepare('SELECT codigo FROM cajas WHERE empresa_id = ? AND local_codigo = ? AND codigo = ? FOR UPDATE');
            $buscar->execute([$empresa, $local, $caja]);
            if ($buscar->fetchColumn() === false) {
                $db->rollBack();
                responder(404, ['error' => 'Caja no encontrada.']);
            }
            $db->prepare('UPDATE cajas SET nombre = ? WHERE empresa_id = ? AND local_codigo = ? AND codigo = ?')
                ->execute([trim($nombre), $empresa, $local, $caja]);
        }
    }
    $db->prepare('INSERT INTO ubicacion_eventos (empresa_id, usuario_id, local_codigo, caja_codigo, accion)
        VALUES (?, ?, ?, ?, ?)')->execute([$empresa, $usuario, $local, $caja, $accion]);
    $db->commit();
    responder($method === 'POST' ? 201 : 200, ['codigo' => $codigo]);
} catch (InvalidArgumentException $error) {
    responder(422, ['error' => $error->getMessage()]);
} catch (JsonException) {
    responder(400, ['error' => 'JSON inválido.']);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
        responder(409, ['error' => 'Este código ya existe.']);
    }
    error_log('MyPOS Lite ubicaciones: ' . $error::class);
    responder(500, ['error' => 'No se pudo completar la solicitud.']);
}
