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
    $actor = idSesionLite();
    $contexto = $_SESSION['contexto'] ?? null;
    if ($actor === null || !$contexto) {
        responder($actor === null ? 401 : 403, ['error' => 'Inicie sesión y seleccione un local.']);
    }
    $empresa = $contexto['empresaId'];
    if (($_SERVER['HTTP_X_MYPOSLITE_EMPRESA'] ?? null) !== $empresa) {
        responder(409, ['error' => 'Cambió la empresa activa.']);
    }
    $db = conectarBaseLite();
    if (!in_array('empresa.configurar', permisosMaestros($db, $actor, $empresa, $contexto['localCodigo']), true)) {
        responder(403, ['error' => 'No tiene permiso para administrar usuarios.']);
    }
    $_SESSION['actividad'] = time();
    session_write_close();

    if ($method === 'GET') {
        $usuarios = $db->prepare('SELECT u.id, u.nombre, u.correo, m.rol_codigo AS rol, m.activa
            FROM membresias m JOIN usuarios u ON u.id = m.usuario_id
            WHERE m.empresa_id = ? ORDER BY u.nombre, u.id');
        $usuarios->execute([$empresa]);
        $locales = $db->prepare('SELECT usuario_id, local_codigo FROM membresias_locales WHERE empresa_id = ?');
        $locales->execute([$empresa]);
        $cajas = $db->prepare('SELECT usuario_id, local_codigo, caja_codigo FROM membresias_cajas WHERE empresa_id = ?');
        $cajas->execute([$empresa]);
        $porUsuario = [];
        foreach ($locales->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $porUsuario[$row['usuario_id']]['locales'][] = $row['local_codigo'];
        }
        foreach ($cajas->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $porUsuario[$row['usuario_id']]['cajas'][] = ['localCodigo' => $row['local_codigo'], 'codigo' => $row['caja_codigo']];
        }
        $resultado = $usuarios->fetchAll(PDO::FETCH_ASSOC);
        foreach ($resultado as &$row) {
            $row['id'] = (string) $row['id'];
            $row['activa'] = (bool) $row['activa'];
            $row['locales'] = $porUsuario[$row['id']]['locales'] ?? [];
            $row['cajas'] = $porUsuario[$row['id']]['cajas'] ?? [];
        }
        unset($row);
        responder(200, ['usuarios' => $resultado, 'actor' => $actor]);
    }

    $raw = file_get_contents('php://input', false, null, 0, 32769);
    if ($raw === false || strlen($raw) > 32768) {
        responder(413, ['error' => 'Solicitud demasiado grande.']);
    }
    $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    $rol = $input['rol'] ?? null;
    $activa = $input['activa'] ?? true;
    $locales = $input['locales'] ?? [];
    $cajas = $input['cajas'] ?? [];
    if (!in_array($rol, ['administrador', 'supervisor', 'vendedor', 'contador'], true)
        || !is_bool($activa) || !is_array($locales) || !array_is_list($locales)
        || !is_array($cajas) || !array_is_list($cajas)) {
        throw new InvalidArgumentException('Revise el rol y las asignaciones.');
    }

    $db->beginTransaction();
    // Serializa cambios de membresía y vuelve a comprobar si el actor conserva acceso.
    $lock = $db->prepare('SELECT id FROM empresas WHERE id = ? FOR UPDATE');
    $lock->execute([$empresa]);
    if (!in_array('empresa.configurar', permisosMaestros($db, $actor, $empresa, $contexto['localCodigo']), true)) {
        $db->rollBack();
        responder(403, ['error' => 'Ya no tiene permiso para administrar usuarios.']);
    }
    if ($method === 'POST') {
        $correo = $input['correo'] ?? null;
        $nombre = $input['nombre'] ?? null;
        $clave = $input['clave'] ?? '';
        if (!is_string($correo) || strlen(trim($correo)) > 254 || !filter_var(trim($correo), FILTER_VALIDATE_EMAIL)
            || !is_string($clave) || strlen($clave) > 72 || str_contains($clave, "\0")) {
            throw new InvalidArgumentException('Revise el correo y la contraseña.');
        }
        $correo = strtolower(trim($correo));
        $query = $db->prepare('SELECT id FROM usuarios WHERE correo = ? FOR UPDATE');
        $query->execute([$correo]);
        $usuario = $query->fetchColumn();
        if ($usuario === false) {
            if (!is_string($nombre) || !preg_match('/^.{1,120}$/usD', trim($nombre)) || strlen($clave) < 12) {
                throw new InvalidArgumentException('Ingrese el nombre y una contraseña inicial de al menos 12 caracteres para la cuenta nueva.');
            }
            $db->prepare('INSERT INTO usuarios (correo, nombre, clave_hash) VALUES (?, ?, ?)')
                ->execute([$correo, trim($nombre), password_hash($clave, PASSWORD_DEFAULT)]);
            $usuario = $db->lastInsertId();
        } elseif ($clave !== '') {
            $db->rollBack();
            responder(409, ['error' => 'El correo ya tiene una cuenta. Déjelo sin contraseña para asignarle acceso.']);
        }
        $db->prepare('INSERT INTO membresias (empresa_id, usuario_id, rol_codigo, activa) VALUES (?, ?, ?, ?)')
            ->execute([$empresa, $usuario, $rol, (int) $activa]);
    } else {
        $usuario = $input['id'] ?? null;
        if (!is_string($usuario) || !preg_match('/^[1-9][0-9]{0,19}$/D', $usuario)) {
            throw new InvalidArgumentException('Usuario inválido.');
        }
        $query = $db->prepare('SELECT rol_codigo FROM membresias WHERE empresa_id = ? AND usuario_id = ? FOR UPDATE');
        $query->execute([$empresa, $usuario]);
        if ($query->fetchColumn() === false) {
            $db->rollBack();
            responder(404, ['error' => 'El usuario no pertenece a esta empresa.']);
        }
        if ($usuario === $actor && ($rol !== 'administrador' || !$activa)) {
            $db->rollBack();
            responder(409, ['error' => 'No puede quitarse su propio acceso de administración.']);
        }
        $db->prepare('UPDATE membresias SET rol_codigo = ?, activa = ? WHERE empresa_id = ? AND usuario_id = ?')
            ->execute([$rol, (int) $activa, $empresa, $usuario]);
        foreach (['membresias_cajas', 'membresias_locales'] as $tabla) {
            $db->prepare("DELETE FROM $tabla WHERE empresa_id = ? AND usuario_id = ?")->execute([$empresa, $usuario]);
        }
    }
    $local = $db->prepare('INSERT INTO membresias_locales (empresa_id, usuario_id, local_codigo) VALUES (?, ?, ?)');
    foreach ($locales as $codigo) {
        if (!is_string($codigo) || strlen($codigo) > 24 || $codigo === '') {
            throw new InvalidArgumentException('Asignación de local inválida.');
        }
        $local->execute([$empresa, $usuario, $codigo]);
    }
    $caja = $db->prepare('INSERT INTO membresias_cajas (empresa_id, usuario_id, local_codigo, caja_codigo) VALUES (?, ?, ?, ?)');
    foreach ($cajas as $item) {
        if (!is_array($item) || !is_string($item['localCodigo'] ?? null) || !is_string($item['codigo'] ?? null)
            || !in_array($item['localCodigo'], $locales, true) || strlen($item['codigo']) > 24) {
            throw new InvalidArgumentException('Cada caja debe pertenecer a un local asignado.');
        }
        $caja->execute([$empresa, $usuario, $item['localCodigo'], $item['codigo']]);
    }
    $db->prepare('INSERT INTO membresia_eventos (empresa_id, usuario_id, actor_id, accion) VALUES (?, ?, ?, ?)')
        ->execute([$empresa, $usuario, $actor, $method === 'POST' ? 'crear' : 'editar']);
    $db->commit();
    responder($method === 'POST' ? 201 : 200, ['id' => (string) $usuario]);
} catch (InvalidArgumentException | JsonException $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    responder(422, ['error' => $error instanceof JsonException ? 'JSON inválido.' : $error->getMessage()]);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    $codigo = $error instanceof PDOException ? (int) ($error->errorInfo[1] ?? 0) : 0;
    match ($codigo) {
        1062 => responder(409, ['error' => 'La membresía o asignación ya existe.']),
        1452 => responder(422, ['error' => 'La ubicación indicada no pertenece a esta empresa.']),
        default => null,
    };
    error_log('MyPOS Lite usuarios: ' . $error::class);
    responder(500, ['error' => 'No se pudo completar la solicitud.']);
}
