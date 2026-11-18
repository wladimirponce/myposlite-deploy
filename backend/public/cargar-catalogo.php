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

    $raw = file_get_contents('php://input', false, null, 0, 4096);
    $input = json_decode((string) $raw, true, 8, JSON_THROW_ON_ERROR);
    $rubro = strtolower(trim((string) ($input['rubro'] ?? '')));

    if (!in_array($rubro, ['minimarket', 'farmacia'], true)) {
        responder(400, ['error' => 'Rubro no válido. Seleccione minimarket o farmacia.']);
    }

    // Actualizar el rubro en la empresa
    $stmtRubro = $acceso['db']->prepare('UPDATE empresas SET rubro = ? WHERE id = ?');
    $stmtRubro->execute([$rubro, $acceso['empresa']]);

    $total = SembradorCatalogo::sembrar($acceso['db'], $acceso['empresa'], $rubro);

    responder(200, [
        'ok' => true,
        'rubro' => $rubro,
        'productos_sembrados' => $total,
        'mensaje' => "Se cargaron {$total} productos del catálogo maestro de " . ucfirst($rubro) . ".",
    ]);
} catch (Throwable $e) {
    error_log('Cargar catalogo error: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo cargar el catálogo: ' . $e->getMessage()]);
}
