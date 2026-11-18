<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/Ventas.php';

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

try {
    $acceso = contextoAutorizado();
    ['db' => $db, 'empresa' => $empresa, 'local' => $local, 'usuario' => $usuario, 'permisos' => $permisos] = $acceso;
    if (rawurldecode($_SERVER['HTTP_X_MYPOSLITE_LOCAL'] ?? '') !== $local) {
        responder(409, ['error' => 'Cambió el local activo. Vuelva a abrir la caja.']);
    }
    $vende = in_array('pos.vender', $permisos, true);
    $consulta = $vende || array_intersect(['caja.supervisar', 'operacion.consultar'], $permisos);
    if (($method === 'POST' && !$vende) || !$consulta) {
        responder(403, ['error' => 'No tiene permiso para esta operación.']);
    }

    if ($method === 'GET') {
        foreach (['venta', 'turno'] as $campo) {
            if (isset($_GET[$campo]) && (!is_string($_GET[$campo]) || !preg_match('/^[1-9][0-9]{0,19}$/D', $_GET[$campo]))) {
                responder(400, ['error' => 'Identificador inválido.']);
            }
        }
        if (isset($_GET['venta'])) {
            responder(200, detalleVenta($db, $empresa, $_GET['venta']));
        }
        if (isset($_GET['turno'])) {
            responder(200, resumenTurno($db, $empresa, $_GET['turno']));
        }
        // Cajas del local que el usuario puede operar, con su turno abierto y sus ventas.
        $supervisa = in_array('caja.supervisar', $permisos, true) || !$vende;
        $cajas = $db->prepare('SELECT c.codigo, c.nombre FROM cajas c WHERE c.empresa_id = ? AND c.local_codigo = ?
            AND (? OR EXISTS (SELECT 1 FROM membresias_cajas m WHERE m.empresa_id = c.empresa_id AND m.usuario_id = ?
                AND m.local_codigo = c.local_codigo AND m.caja_codigo = c.codigo)) ORDER BY c.nombre');
        $cajas->execute([$empresa, $local, (int) $supervisa, $usuario]);
        $resultado = [];
        foreach ($cajas->fetchAll(PDO::FETCH_ASSOC) as $caja) {
            $turno = turnoAbierto($db, $empresa, $local, $caja['codigo']);
            $resultado[] = $caja + ['turno' => $turno === null ? null : resumenTurno($db, $empresa, (string) $turno['id']),
                'ventas' => $turno === null ? [] : ventasDelTurno($db, $empresa, (string) $turno['id'])];
        }
        $habilitada = tipoHabilitadoDte($db, $empresa, 39);
        $demo = $db->prepare('SELECT es_demo FROM empresas WHERE id = ?');
        $demo->execute([$empresa]);
        // La caja ve las alertas que pueden detener la venta: certificado y folios o CAF de boletas.
        $alertas = array_values(array_filter(alertasDte($db, $empresa, new DateTimeImmutable('now', new DateTimeZone('America/Santiago'))),
            static fn (array $a): bool => $a['tipo'] === null || in_array($a['tipo'], [39, 41], true)));
        responder(200, ['cajas' => $resultado, 'boletasHabilitadas' => $habilitada, 'alertasDte' => $alertas, 'demo' => (bool) $demo->fetchColumn(),
            'puedeVender' => $vende, 'supervisa' => in_array('caja.supervisar', $permisos, true),
            'puedeDevolver' => in_array('pos.devolver', $permisos, true), 'notasHabilitadas' => tipoHabilitadoDte($db, $empresa, 61)]);
    }

    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if ($raw === false || strlen($raw) > 65536) {
        responder(413, ['error' => 'Solicitud demasiado grande.']);
    }
    $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        responder(400, ['error' => 'Solicitud inválida.']);
    }
    $resultado = match ($input['accion'] ?? null) {
        'abrir' => abrirTurno($db, $empresa, $local, $usuario, $permisos, $input),
        'movimiento' => registrarMovimientoCaja($db, $empresa, $local, $usuario, $permisos, $input),
        'cerrar' => cerrarTurno($db, $empresa, $local, $usuario, $permisos, $input),
        'vender' => registrarVenta($db, $empresa, $local, $usuario, $permisos, $input),
        'devolver' => devolverVenta($db, $empresa, $local, $usuario, $permisos, $input),
        'cotizar' => cotizacionCaja($db, $empresa, $local, $input),
        default => responder(400, ['error' => 'Acción desconocida.']),
    };
    $creado = $input['accion'] === 'abrir' || (in_array($input['accion'], ['vender', 'devolver'], true) && !$resultado['repetida']);
    responder($creado ? 201 : 200, $resultado);
} catch (InvalidArgumentException $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    responder(422, ['error' => $error->getMessage()]);
} catch (JsonException) {
    responder(400, ['error' => 'JSON inválido.']);
} catch (MotorDteNoDisponible $error) {
    responder(503, ['error' => $error->getMessage()]);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($error instanceof RuntimeException && in_array($error->getCode(), [403, 404, 409, 422], true)) {
        responder($error->getCode(), ['error' => $error->getMessage()]);
    }
    if ($error instanceof PDOException && in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true)) {
        header('Retry-After: 1');
        responder(503, ['error' => 'La caja está ocupada. Vuelva a intentar: la venta no se duplicará.']);
    }
    error_log('MyPOS Lite pos: ' . $error::class . ' ' . $error->getMessage());
    responder(500, ['error' => 'No se pudo completar la solicitud.']);
}
