<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/SesionRepository.php';
require __DIR__ . '/../src/Informes.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    responder(405, ['error' => 'Método no disponible.']);
}

try {
    ['db' => $db, 'empresa' => $empresa, 'local' => $local, 'usuario' => $usuario, 'permisos' => $permisos] = contextoAutorizado();
    if (rawurldecode($_SERVER['HTTP_X_MYPOSLITE_LOCAL'] ?? '') !== $local) {
        responder(409, ['error' => 'Cambió el local activo.']);
    }

    $puedeVer = in_array('caja.supervisar', $permisos, true) || in_array('operacion.consultar', $permisos, true);
    if (!$puedeVer) {
        responder(403, ['error' => 'No tiene permiso para consultar informes de gestión.']);
    }

    // Obtener locales autorizados para este usuario en la empresa activa
    $contextos = (new SesionRepository($db))->contextos($usuario);
    $localesAutorizados = [];
    foreach ($contextos as $ctx) {
        if ($ctx['id'] === $empresa) {
            foreach ($ctx['locales'] as $loc) {
                $localesAutorizados[$loc['codigo']] = $loc['nombre'];
            }
            break;
        }
    }

    $alcance = isset($_GET['alcance']) && is_string($_GET['alcance']) ? trim($_GET['alcance']) : 'local';
    if ($alcance === 'todos') {
        $localesConsulta = array_keys($localesAutorizados);
    } elseif ($alcance === 'local' || !isset($localesAutorizados[$alcance])) {
        $localesConsulta = [$local];
        $alcance = 'local';
    } else {
        $localesConsulta = [$alcance];
    }

    $rango = rangoInforme(
        $db,
        $empresa,
        isset($_GET['desde']) && is_string($_GET['desde']) ? $_GET['desde'] : null,
        isset($_GET['hasta']) && is_string($_GET['hasta']) ? $_GET['hasta'] : null
    );

    $informe = generarInformeCompleto(
        $db,
        $empresa,
        $localesConsulta,
        $rango['desde'],
        $rango['hasta'],
        $rango['dias'],
        $rango['zona'],
        $rango['hoy']
    );

    $informe['alcance'] = $alcance;
    $informe['localesDisponibles'] = array_map(
        static fn (string $codigo, string $nombre): array => ['codigo' => $codigo, 'nombre' => $nombre],
        array_keys($localesAutorizados),
        array_values($localesAutorizados)
    );

    responder(200, $informe);
} catch (InvalidArgumentException $error) {
    responder(422, ['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('MyPOS Lite informes: ' . $error->getMessage());
    responder(500, ['error' => 'No se pudo generar el informe de gestión.']);
}
