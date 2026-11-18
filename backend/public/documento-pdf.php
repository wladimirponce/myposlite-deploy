<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/Emision.php';

header('Cache-Control: no-store');

function responder(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    responder(405, ['error' => 'Método no disponible.']);
}

try {
    ['db' => $db, 'empresa' => $empresa, 'permisos' => $permisos] = contextoAutorizado();
    // Ve la representación quien vende, supervisa, emite o consulta la operación de la empresa.
    if (!array_intersect(['pos.vender', 'caja.supervisar', 'documentos.emitir', 'operacion.consultar', 'empresa.configurar'], $permisos)) {
        responder(403, ['error' => 'No tiene permiso para esta operación.']);
    }
    $id = $_GET['id'] ?? null;
    $copia = $_GET['copia'] ?? 'tributaria';
    $formato = $_GET['formato'] ?? 'carta';
    if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id) || !in_array($copia, ['tributaria', 'cedible'], true)
        || !in_array($formato, ['carta', '80'], true)) {
        responder(400, ['error' => 'Solicitud inválida.']);
    }
    ['pdf' => $pdf, 'nombre' => $nombre] = representacionDte($db, $empresa, $id, $copia, $formato);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $nombre . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
} catch (InvalidArgumentException $error) {
    responder(422, ['error' => $error->getMessage()]);
} catch (Throwable $error) {
    if ($error instanceof RuntimeException && in_array($error->getCode(), [404, 409], true)) {
        responder($error->getCode(), ['error' => $error->getMessage()]);
    }
    error_log('MyPOS Lite documento-pdf: ' . $error::class . ' ' . $error->getMessage());
    responder(500, ['error' => 'No se pudo generar el PDF.']);
}
