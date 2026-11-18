<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/SesionRepository.php';
require __DIR__ . '/../src/CierreDiario.php';

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
    ['db' => $db, 'empresa' => $empresa, 'local' => $local, 'usuario' => $usuario, 'permisos' => $permisos] = contextoAutorizado();
    if (rawurldecode($_SERVER['HTTP_X_MYPOSLITE_LOCAL'] ?? '') !== $local) {
        responder(409, ['error' => 'Cambió el local activo.']);
    }
    $puedeCerrar = in_array('caja.supervisar', $permisos, true);
    if ((!$puedeCerrar && !in_array('operacion.consultar', $permisos, true)) || ($method === 'POST' && !$puedeCerrar)) {
        responder(403, ['error' => 'No tiene permiso para esta operación.']);
    }
    $dia = jornadaCierre($db, $empresa, $_GET['fecha'] ?? null);
    $fecha = $dia->format('Y-m-d');
    if ($method === 'POST') {
        $db->beginTransaction();
        bloquearLocalCierre($db, $empresa, $local);
        $existe = leerCierre($db, $empresa, $local, $fecha);
        if ($existe === null) {
            $resumen = totalesCierre($db, $empresa, $local, $dia);
            if ($resumen['abiertos'] > 0) {
                $db->rollBack();
                responder(409, ['error' => 'Cierre los turnos de caja antes de cerrar la jornada.']);
            }
            $db->prepare('INSERT INTO cierres_diarios (empresa_id, local_codigo, fecha, totales, cerrado_por) VALUES (?, ?, ?, ?, ?)')
                ->execute([$empresa, $local, $fecha, json_encode($resumen, JSON_THROW_ON_ERROR), $usuario]);
        }
        $db->commit();
    }

    $contextos = (new SesionRepository($db))->contextos($usuario);
    $locales = [];
    $total = ['cantidad' => 0, 'total' => 0, 'reintegros' => 0, 'pagos' => []];
    foreach ($contextos as $contexto) {
        if ($contexto['id'] !== $empresa) {
            continue;
        }
        foreach ($contexto['locales'] as $ubicacion) {
            $codigo = $ubicacion['codigo'];
            $cierre = leerCierre($db, $empresa, $codigo, $fecha);
            $resumen = $cierre === null ? totalesCierre($db, $empresa, $codigo, $dia) : json_decode($cierre['totales'], true, 16, JSON_THROW_ON_ERROR);
            $locales[] = ['codigo' => $codigo, 'nombre' => $ubicacion['nombre'], 'cerradoEn' => $cierre['cerrado_en'] ?? null, 'totales' => $resumen];
            $total['cantidad'] += $resumen['cantidad'];
            $total['total'] += $resumen['total'];
            foreach ($resumen['pagos'] as $medio => $monto) {
                $total['pagos'][$medio] = ($total['pagos'][$medio] ?? 0) + $monto;
            }
            foreach ($resumen['movimientos'] as $movimiento) {
                if ($movimiento['tipo'] === 'devolucion') {
                    $total['reintegros'] += (int) $movimiento['monto'];
                }
            }
        }
        break;
    }
    responder(200, ['fecha' => $fecha, 'hoy' => (new DateTimeImmutable('today', $dia->getTimezone()))->format('Y-m-d'),
        'puedeCerrar' => $puedeCerrar, 'locales' => $locales, 'consolidado' => $total,
        'folios' => foliosCierre($db, $empresa, $fecha, array_column($locales, 'codigo'))]);
} catch (InvalidArgumentException $error) {
    responder(422, ['error' => $error->getMessage()]);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('MyPOS Lite cierres: ' . $error::class);
    responder(500, ['error' => 'No se pudo calcular el cierre diario.']);
}
