<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/SembradorCatalogo.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responder(405, ['error' => 'Método no disponible.']);
}

try {
    $acceso = contextoAutorizado();
    if (!array_intersect(['empresa.configurar', 'maestros.editar'], $acceso['permisos'])) {
        responder(403, ['error' => 'No tiene permiso para cargar catálogos en esta empresa.']);
    }

    $raw = file_get_contents('php://input', false, null, 0, 8192);
    $input = json_decode((string) $raw, true, 8, JSON_THROW_ON_ERROR);

    $rubrosPermitidos = ['farmacia', 'minimarket', 'botilleria', 'ferreteria'];
    $rubros = [];

    if (isset($input['rubros']) && is_array($input['rubros'])) {
        foreach ($input['rubros'] as $r) {
            $limpio = strtolower(trim((string) $r));
            if (in_array($limpio, $rubrosPermitidos, true) && !in_array($limpio, $rubros, true)) {
                $rubros[] = $limpio;
            }
        }
    } elseif (isset($input['rubro'])) {
        $limpio = strtolower(trim((string) $input['rubro']));
        if (in_array($limpio, $rubrosPermitidos, true)) {
            $rubros[] = $limpio;
        }
    }

    if (empty($rubros)) {
        responder(400, ['error' => 'Seleccione al menos un rubro válido: farmacia, minimarket, botilleria o ferreteria.']);
    }

    // Actualizar el rubro en la empresa (puede ser compuesto si son varios)
    $stmtRubro = $acceso['db']->prepare('UPDATE empresas SET rubro = ? WHERE id = ?');
    $stmtRubro->execute([implode(',', $rubros), $acceso['empresa']]);

    $totalGeneral = 0;
    $detalle = [];
    foreach ($rubros as $rubro) {
        $cant = SembradorCatalogo::sembrar($acceso['db'], $acceso['empresa'], $rubro);
        $totalGeneral += $cant;
        $detalle[$rubro] = $cant;
    }

    $nombresRubros = implode(' y ', array_map('ucfirst', $rubros));
    responder(200, [
        'ok' => true,
        'rubros' => $rubros,
        'productos_sembrados' => $totalGeneral,
        'detalle' => $detalle,
        'mensaje' => "Se cargaron exitosamente {$totalGeneral} productos del catálogo de {$nombresRubros}.",
    ]);
} catch (Throwable $e) {
    error_log('Cargar catalogo error: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo cargar el catálogo: ' . $e->getMessage()]);
}
