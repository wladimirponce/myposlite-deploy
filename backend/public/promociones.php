<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/Promociones.php';

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
    if ($usuario === null || !$contexto) {
        responder($usuario === null ? 401 : 403, ['error' => 'Inicie sesión y seleccione un local.']);
    }
    $empresa = $contexto['empresaId'];
    $local = $contexto['localCodigo'];
    if (($_SERVER['HTTP_X_MYPOSLITE_EMPRESA'] ?? null) !== $empresa ||
        rawurldecode($_SERVER['HTTP_X_MYPOSLITE_LOCAL'] ?? '') !== $local) {
        responder(409, ['error' => 'Cambió la empresa o el local activo.']);
    }
    $db = conectarBaseLite();
    $permisos = permisosMaestros($db, $usuario, $empresa, $local);
    $editar = in_array('maestros.editar', $permisos, true);
    $puedeEmpresa = in_array('empresa.configurar', $permisos, true);
    $leer = $editar || array_intersect(['operacion.consultar', 'pos.vender'], $permisos);
    if (!$leer || ($method !== 'GET' && !$editar)) {
        responder(403, ['error' => 'No tiene permiso para esta operación.']);
    }
    $_SESSION['actividad'] = time();
    session_write_close();

    if ($method === 'GET') {
        $zona = $db->prepare('SELECT zona_horaria FROM empresas WHERE id = ?');
        $zona->execute([$empresa]);
        $ahora = new DateTimeImmutable('now', new DateTimeZone((string) $zona->fetchColumn()));
        $sql = 'SELECT r.*, p.nombre AS producto, v.nombre AS presentacion
            FROM promociones r JOIN producto_presentaciones v ON v.empresa_id = r.empresa_id AND v.id = r.presentacion_id
            JOIN productos p ON p.empresa_id = v.empresa_id AND p.id = v.producto_id
            WHERE r.empresa_id = ? AND (r.local_codigo IS NULL OR r.local_codigo = ?)';
        $soloVigentes = ($_GET['vigentes'] ?? '') === '1' || !$editar;
        if ($soloVigentes) {
            $sql .= ' AND r.activa = 1 AND r.desde <= ? AND r.hasta >= ?';
        }
        $query = $db->prepare($sql . ' ORDER BY r.desde DESC, r.id DESC');
        $params = [$empresa, $local];
        if ($soloVigentes) {
            $params[] = $ahora->format('Y-m-d');
            $params[] = $ahora->modify('-1 day')->format('Y-m-d');
        }
        $query->execute($params);
        $reglas = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['vigente'] = promocionVigente($row, $local, $ahora);
            if ($soloVigentes && !$row['vigente']) {
                continue;
            }
            $row['activa'] = (bool) $row['activa'];
            $row['id'] = (string) $row['id'];
            $row['presentacion_id'] = (string) $row['presentacion_id'];
            unset($row['empresa_id']);
            $reglas[] = $row;
        }
        responder(200, ['promociones' => $reglas, 'puedeEditar' => $editar, 'puedeEmpresa' => $puedeEmpresa]);
    }

    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if ($raw === false || strlen($raw) > 4096) {
        responder(413, ['error' => 'Solicitud demasiado grande.']);
    }
    $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    $id = $input['id'] ?? null;
    $activa = $input['activa'] ?? true;
    if (!is_bool($activa)) {
        throw new InvalidArgumentException('Estado inválido.');
    }
    if ($method === 'PATCH') {
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id)) {
            throw new InvalidArgumentException('Promoción inválida.');
        }
        $actualizar = $db->prepare('UPDATE promociones SET activa = ? WHERE empresa_id = ? AND id = ?
            AND ((local_codigo IS NULL AND ? = 1) OR local_codigo = ?)');
        $actualizar->execute([(int) $activa, $empresa, $id, (int) $puedeEmpresa, $local]);
        if ($actualizar->rowCount() === 0) {
            $check = $db->prepare('SELECT id FROM promociones WHERE empresa_id = ? AND id = ?
                AND ((local_codigo IS NULL AND ? = 1) OR local_codigo = ?)');
            $check->execute([$empresa, $id, (int) $puedeEmpresa, $local]);
            if ($check->fetchColumn() === false) {
                responder(404, ['error' => 'Promoción no encontrada.']);
            }
        }
        responder(200, ['id' => $id]);
    }

    $nombre = $input['nombre'] ?? null;
    $presentacion = $input['presentacionId'] ?? null;
    $tipo = $input['tipo'] ?? null;
    $alcance = $input['alcance'] ?? null;
    if (!is_string($nombre) || !preg_match('/^.{1,100}$/usD', trim($nombre))
        || !is_string($presentacion) || !preg_match('/^[1-9][0-9]{0,19}$/D', $presentacion)
        || !in_array($tipo, ['2x1', 'pack', 'horario'], true) || !in_array($alcance, ['empresa', 'local'], true)) {
        throw new InvalidArgumentException('Revise el nombre, la presentación, el tipo y el alcance.');
    }
    if ($alcance === 'empresa' && !$puedeEmpresa) {
        responder(403, ['error' => 'Solo un administrador puede configurar promociones para toda la empresa.']);
    }
    $desde = fechaPromocion($input['desde'] ?? null);
    $hasta = fechaPromocion($input['hasta'] ?? null);
    if ($hasta < $desde) {
        throw new InvalidArgumentException('El fin de la promoción debe ser igual o posterior al inicio.');
    }
    $cantidad = match ($tipo) { '2x1' => 2, 'horario' => 1, default => $input['cantidad'] ?? null };
    $precio = $tipo === '2x1' ? null : $input['precio'] ?? null;
    if (!is_int($cantidad) || $cantidad < 1 || $cantidad > 4294967295 || ($tipo === 'pack' && $cantidad < 2)
        || ($tipo !== '2x1' && (!is_string($precio) || !preg_match('/^[0-9]{1,14}$/D', $precio)))) {
        throw new InvalidArgumentException('Revise la cantidad y el precio en pesos.');
    }
    $inicio = $tipo === 'horario' ? horaPromocion($input['horaInicio'] ?? null) : null;
    $fin = $tipo === 'horario' ? horaPromocion($input['horaFin'] ?? null) : null;
    if ($inicio !== null && $inicio === $fin) {
        throw new InvalidArgumentException('El inicio y fin del horario deben ser distintos.');
    }

    $insert = $db->prepare('INSERT INTO promociones
        (empresa_id, presentacion_id, local_codigo, nombre, tipo, cantidad, precio, desde, hasta, hora_inicio, hora_fin, activa)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([$empresa, $presentacion, $alcance === 'local' ? $local : null, trim($nombre), $tipo,
        $cantidad, $precio, $desde, $hasta, $inicio, $fin, (int) $activa]);
    responder(201, ['id' => $db->lastInsertId()]);
} catch (InvalidArgumentException | JsonException $error) {
    responder(422, ['error' => $error instanceof JsonException ? 'JSON inválido.' : $error->getMessage()]);
} catch (Throwable $error) {
    if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1452) {
        responder(422, ['error' => 'La presentación o el local no pertenecen a la empresa activa.']);
    }
    error_log('MyPOS Lite promociones: ' . $error::class);
    responder(500, ['error' => 'No se pudo completar la solicitud.']);
}
