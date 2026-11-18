<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/Ventas.php';

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
    ['db' => $db, 'empresa' => $empresa, 'local' => $local, 'usuario' => $usuario, 'permisos' => $permisos] = contextoAutorizado();
    if (rawurldecode($_SERVER['HTTP_X_MYPOSLITE_LOCAL'] ?? '') !== $local) {
        responder(409, ['error' => 'Cambió el local activo.']);
    }
    if (!array_intersect(['pos.vender', 'caja.supervisar', 'operacion.consultar'], $permisos)) {
        responder(403, ['error' => 'No tiene permiso para consultar el ticket.']);
    }
    $id = $_GET['id'] ?? null;
    if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id)) {
        responder(400, ['error' => 'Identificador de venta inválido.']);
    }
    $query = $db->prepare('SELECT e.razon_social, e.zona_horaria, l.nombre AS local, t.caja_codigo, v.documento_id
        FROM ventas v JOIN empresas e ON e.id = v.empresa_id
        JOIN locales l ON l.empresa_id = v.empresa_id AND l.codigo = v.local_codigo
        JOIN turnos t ON t.empresa_id = v.empresa_id AND t.id = v.turno_id
        WHERE v.empresa_id = ? AND v.local_codigo = ? AND v.id = ?');
    $query->execute([$empresa, $local, $id]);
    $datos = $query->fetch(PDO::FETCH_ASSOC);
    if (!$datos) {
        responder(404, ['error' => 'La venta no existe en este local.']);
    }
    if (!array_intersect(['caja.supervisar', 'operacion.consultar'], $permisos)) {
        exigirCaja($db, $empresa, $local, $usuario, $permisos, $datos['caja_codigo']);
    }
    if ($datos['documento_id'] !== null) {
        responder(409, ['error' => 'Para esta venta descargue su documento tributario.']);
    }

    $venta = detalleVenta($db, $empresa, $id);
    $fecha = (new DateTimeImmutable($venta['fecha'], new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone($datos['zona_horaria']))->format('d-m-Y H:i');
    $monto = static fn (int $valor): string => '$' . number_format($valor, 0, ',', '.');
    $filas = [$datos['razon_social'], $datos['local'] . ' · Caja ' . $venta['caja'],
        'Venta interna #' . $id . ' · ' . $fecha, 'Vendedor: ' . $venta['vendedor'], ''];
    foreach ($venta['lineas'] as $linea) {
        $cantidad = (string) $linea['cantidad'];
        if (str_contains($cantidad, '.')) {
            $cantidad = rtrim(rtrim($cantidad, '0'), '.');
        }
        $filas[] = $linea['nombre'] . ' · ' . $linea['presentacion'];
        $filas[] = $cantidad . ' × ' . $monto($linea['precio']) . ' = ' . $monto($linea['monto']);
        if ($linea['descuento'] > 0) {
            $filas[] = 'Descuento: ' . $monto($linea['descuento']);
        }
    }
    $filas[] = '';
    $filas[] = 'Total de la venta: ' . $monto($venta['total']);
    foreach ($venta['pagos'] as $pago) {
        $filas[] = ucfirst($pago['medio']) . ': ' . $monto($pago['monto']);
        if ($pago['recibido'] !== null && $pago['recibido'] > $pago['monto']) {
            $filas[] = 'Vuelto: ' . $monto($pago['recibido'] - $pago['monto']);
        }
    }
    $filas[] = 'Comprobante interno sin validez tributaria.';

    require_once __DIR__ . '/../lib/tcpdf/tcpdf.php';
    $pdf = new TCPDF('P', 'mm', [80, 160], true, 'UTF-8', false);
    $pdf->SetTitle('Ticket interno de venta ' . $id);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(4, 4, 4);
    $pdf->SetFont('helvetica', '', 9);
    $alto = 24;
    foreach ($filas as $texto) {
        $alto += $pdf->getStringHeight(72, $texto) + 1;
    }
    $pdf->AddPage('P', [80, max(85, $alto)]);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->MultiCell(72, 7, 'TICKET DE VENTA', 0, 'C');
    $pdf->SetFont('helvetica', '', 9);
    foreach ($filas as $texto) {
        $pdf->MultiCell(72, 4, $texto);
    }
    $bytes = $pdf->Output('', 'S');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="ticket-venta-' . $id . '.pdf"');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
} catch (Throwable $error) {
    if ($error instanceof RuntimeException && in_array($error->getCode(), [403, 404], true)) {
        responder($error->getCode(), ['error' => $error->getMessage()]);
    }
    error_log('MyPOS Lite ticket-venta: ' . $error::class);
    responder(500, ['error' => 'No se pudo generar el ticket.']);
}
