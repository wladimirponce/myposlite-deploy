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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    responder(405, ['error' => 'Método no disponible.']);
}

try {
    $acceso = contextoAutorizado();
    if (!array_intersect(['empresa.configurar', 'maestros.editar', 'operacion.consultar'], $acceso['permisos'])) {
        responder(403, ['error' => 'No tiene permiso para esta operación.']);
    }
    // El avance se deriva de los datos existentes. No hay estado propio que pueda quedar desfasado.
    $query = $acceso['db']->prepare('SELECT e.es_demo,
        EXISTS (SELECT 1 FROM cajas c WHERE c.empresa_id = e.id) AS caja,
        EXISTS (SELECT 1 FROM productos p WHERE p.empresa_id = e.id) AS catalogo,
        EXISTS (SELECT 1 FROM destinatario_cierre_principal d WHERE d.empresa_id = e.id) AS correo
        FROM empresas e WHERE e.id = ?');
    $query->execute([$acceso['empresa']]);
    $estado = $query->fetch(PDO::FETCH_ASSOC);
    $demo = (bool) $estado['es_demo'];
    $paso = static fn(string $codigo, bool $hecho, bool $disponible = true): array
        => ['codigo' => $codigo, 'hecho' => $hecho, 'disponible' => $disponible];
    $pasos = [
        $paso('local-caja', (bool) $estado['caja']),
        $paso('catalogo', (bool) $estado['catalogo']),
        $paso('correo-cierre', (bool) $estado['correo']),
    ];
    if (!$demo) {
        // Hecho cuando el SII aceptó el set de boletas. La revisión y el visto bueno en el portal los registra el expediente.
        $set = $acceso['db']->prepare("SELECT 1 FROM dte_envios WHERE empresa_id = ? AND ambiente = 'certificacion' AND motivo = 'set_boletas'
            AND estado = 'aceptado'");
        $set->execute([$acceso['empresa']]);
        $pasos[] = $paso('certificacion', $set->fetchColumn() !== false);
    }
    // El Sprint 05 lo marca con la primera venta emitida.
    $pasos[] = $paso('primera-boleta', false, false);
    responder(200, ['demo' => $demo, 'pasos' => $pasos]);
} catch (Throwable $error) {
    error_log('MyPOS Lite puesta en marcha: ' . $error::class);
    responder(500, ['error' => 'No se pudo completar la solicitud.']);
}
