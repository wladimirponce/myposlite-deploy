<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/Rut.php';

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
    $usuario = idSesionLite();
    $contexto = $_SESSION['contexto'] ?? null;
    if ($usuario === null) {
        responder(401, ['error' => 'Inicie sesión.']);
    }
    if (!$contexto) {
        responder(403, ['error' => 'Seleccione un local autorizado.']);
    }
    $empresa = $contexto['empresaId'];
    if (($_SERVER['HTTP_X_MYPOSLITE_EMPRESA'] ?? null) !== $empresa) {
        responder(409, ['error' => 'Cambió la empresa activa. Vuelva a abrir los contactos.']);
    }
    $db = conectarBaseLite();
    $permisos = permisosMaestros($db, $usuario, $empresa, $contexto['localCodigo']);
    $editar = in_array('maestros.editar', $permisos, true);
    $leer = $editar || in_array('operacion.consultar', $permisos, true);
    if (!$leer || ($method !== 'GET' && !$editar)) {
        responder(403, ['error' => 'No tiene permiso para esta operación.']);
    }
    $_SESSION['actividad'] = time();
    session_write_close();

    if ($method === 'GET') {
        $cursor = $_GET['desde'] ?? '0';
        if (!is_string($cursor) || !preg_match('/^[0-9]{1,20}$/D', $cursor)) {
            responder(400, ['error' => 'Paginación inválida.']);
        }
        $soloProveedores = ($_GET['proveedores'] ?? null) === '1' ? ' AND proveedor = 1' : '';
        $query = $db->prepare("SELECT id, rut, razon_social, correo, proveedor, receptor, giro, direccion, comuna
            FROM contactos WHERE empresa_id = ? AND id > ?$soloProveedores ORDER BY id LIMIT 51");
        $query->execute([$empresa, $cursor]);
        $contactos = $query->fetchAll(PDO::FETCH_ASSOC);
        $hayMas = count($contactos) === 51;
        if ($hayMas) {
            array_pop($contactos);
        }
        foreach ($contactos as &$contacto) {
            $contacto['id'] = (string) $contacto['id'];
            $contacto['proveedor'] = (bool) $contacto['proveedor'];
            $contacto['receptor'] = (bool) $contacto['receptor'];
        }
        unset($contacto);
        responder(200, ['contactos' => $contactos, 'puedeEditar' => $editar,
            'siguiente' => $hayMas ? $contactos[49]['id'] : null]);
    }

    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if ($raw === false || strlen($raw) > 4096) {
        responder(413, ['error' => 'Solicitud demasiado grande.']);
    }
    $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        responder(400, ['error' => 'Solicitud inválida.']);
    }
    $rut = canonizarRut($input['rut'] ?? null);
    $nombre = $input['razonSocial'] ?? null;
    $correo = $input['correo'] ?? '';
    $proveedor = $input['proveedor'] ?? false;
    $receptor = $input['receptor'] ?? false;
    if (!is_string($nombre) || trim($nombre) === '' || mb_strlen(trim($nombre)) > 160
        || !is_string($correo) || strlen(trim($correo)) > 254
        || (trim($correo) !== '' && !filter_var(trim($correo), FILTER_VALIDATE_EMAIL))
        || !is_bool($proveedor) || !is_bool($receptor) || (!$proveedor && !$receptor)) {
        throw new InvalidArgumentException('Revise la razón social, el correo y el tipo de contacto.');
    }
    $valores = [$rut, trim($nombre), trim($correo) === '' ? null : trim($correo), (int) $proveedor, (int) $receptor];
    $db->beginTransaction();
    $accion = 'crear';
    if ($method === 'POST') {
        $db->prepare('INSERT INTO contactos (empresa_id, rut, razon_social, correo, proveedor, receptor)
            VALUES (?, ?, ?, ?, ?, ?)')->execute([$empresa, ...$valores]);
        $id = $db->lastInsertId();
    } else {
        $id = $input['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[0-9]{1,20}$/D', $id)) {
            throw new InvalidArgumentException('Contacto inválido.');
        }
        $query = $db->prepare('SELECT id FROM contactos WHERE empresa_id = ? AND id = ? FOR UPDATE');
        $query->execute([$empresa, $id]);
        if ($query->fetchColumn() === false) {
            $db->rollBack();
            responder(404, ['error' => 'Contacto no encontrado.']);
        }
        if (!$proveedor) {
            $query = $db->prepare('SELECT 1 FROM productos WHERE empresa_id = ? AND proveedor_habitual_id = ? LIMIT 1');
            $query->execute([$empresa, $id]);
            if ($query->fetchColumn() !== false) {
                $db->rollBack();
                responder(409, ['error' => 'Es proveedor habitual de productos del catálogo. Cámbielo en esos productos antes de quitarle la marca de proveedor.']);
            }
        }
        $db->prepare('UPDATE contactos SET rut = ?, razon_social = ?, correo = ?, proveedor = ?, receptor = ?
            WHERE empresa_id = ? AND id = ?')->execute([...$valores, $empresa, $id]);
        $accion = 'editar';
    }
    // Datos para facturas y guías, opcionales: omitirlos conserva el valor y null lo retira.
    foreach (['giro' => 40, 'direccion' => 70, 'comuna' => 20] as $campo => $largo) {
        if (!array_key_exists($campo, $input)) {
            continue;
        }
        $valor = $input[$campo];
        if ($valor !== null && (!is_string($valor) || mb_strlen(trim($valor)) > $largo)) {
            throw new InvalidArgumentException("Revise $campo: hasta $largo caracteres.");
        }
        $valor = $valor === null || trim($valor) === '' ? null : trim($valor);
        $db->prepare("UPDATE contactos SET $campo = ? WHERE empresa_id = ? AND id = ?")->execute([$valor, $empresa, $id]);
    }
    $db->prepare('INSERT INTO contacto_eventos (empresa_id, contacto_id, usuario_id, accion)
        VALUES (?, ?, ?, ?)')->execute([$empresa, $id, $usuario, $accion]);
    $db->commit();
    responder($method === 'POST' ? 201 : 200, ['id' => $id]);
} catch (InvalidArgumentException $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    responder(422, ['error' => $error->getMessage()]);
} catch (JsonException) {
    responder(400, ['error' => 'JSON inválido.']);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
        responder(409, ['error' => 'Ya existe un contacto con este RUT en la empresa.']);
    }
    error_log('MyPOS Lite contactos: ' . $error::class);
    responder(500, ['error' => 'No se pudo completar la solicitud.']);
}
