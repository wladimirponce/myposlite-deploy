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
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    responder(405, ['error' => 'Método no disponible.']);
}

try {
    $db = conectarBaseLite();
    $acceso = contextoAutorizado();
    ['empresa' => $empresa, 'local' => $local, 'usuario' => $usuario, 'permisos' => $permisos] = $acceso;

    // 1. GET: Consultar estado del bolsón central de folios
    if ($method === 'GET') {
        $tipoDte = isset($_GET['tipo_dte']) ? (int) $_GET['tipo_dte'] : 39;
        $stmt = $db->prepare('SELECT id, empresa_id, tipo_dte, folio_desde, folio_hasta, ultimo_asignado, disponibles, umbral_alerta, alerta_enviada_en, actualizado_en 
                              FROM dte_folios_pool WHERE empresa_id = ? AND tipo_dte = ?');
        $stmt->execute([$empresa, $tipoDte]);
        $pool = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pool) {
            // Si aún no existe, inicializar bolsa estándar para la empresa
            $poolId = 'pool_' . bin2hex(random_bytes(8));
            $init = $db->prepare('INSERT INTO dte_folios_pool (id, empresa_id, tipo_dte, folio_desde, folio_hasta, ultimo_asignado, disponibles, umbral_alerta)
                                  VALUES (?, ?, ?, 1, 50000, 0, 50000, 3000)');
            $init->execute([$poolId, $empresa, $tipoDte]);
            $pool = [
                'id' => $poolId,
                'empresa_id' => $empresa,
                'tipo_dte' => $tipoDte,
                'folio_desde' => 1,
                'folio_hasta' => 50000,
                'ultimo_asignado' => 0,
                'disponibles' => 50000,
                'umbral_alerta' => 3000,
            ];
        }

        // Consultar también folios asignados a los terminales de este local
        $stmtTerm = $db->prepare('SELECT terminal_id, rango_desde, rango_hasta, cantidad, asignado_en 
                                  FROM dte_terminal_folios 
                                  WHERE empresa_id = ? AND local_codigo = ? AND tipo_dte = ? 
                                  ORDER BY asignado_en DESC LIMIT 10');
        $stmtTerm->execute([$empresa, $local, $tipoDte]);
        $asignaciones = $stmtTerm->fetchAll(PDO::FETCH_ASSOC);

        responder(200, [
            'ok' => true,
            'pool' => $pool,
            'asignaciones_recientes' => $asignaciones,
        ]);
    }

    // 2. POST: Solicitar lote de folios para operar offline en terminal o celular
    $input = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    $accion = $input['accion'] ?? 'solicitar_lote';
    $tipoDte = isset($input['tipo_dte']) ? (int) $input['tipo_dte'] : 39;
    $cantidadDeseada = isset($input['cantidad']) ? max(50, min(5000, (int) $input['cantidad'])) : 1000;
    $terminalId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($input['terminal_id'] ?? 'pos_mobile_1'));

    if ($accion === 'solicitar_lote') {
        $db->beginTransaction();

        // Bloqueo atómico con FOR UPDATE para garantizar 0 colisiones en asignación concurrente
        $stmt = $db->prepare('SELECT id, folio_desde, folio_hasta, ultimo_asignado, disponibles, caf_xml, umbral_alerta, alerta_enviada_en 
                              FROM dte_folios_pool 
                              WHERE empresa_id = ? AND tipo_dte = ? FOR UPDATE');
        $stmt->execute([$empresa, $tipoDte]);
        $pool = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pool) {
            // Inicializar bolsa si no existía
            $poolId = 'pool_' . bin2hex(random_bytes(8));
            $db->prepare('INSERT INTO dte_folios_pool (id, empresa_id, tipo_dte, folio_desde, folio_hasta, ultimo_asignado, disponibles, umbral_alerta)
                          VALUES (?, ?, ?, 1, 50000, 0, 50000, 3000)')
               ->execute([$poolId, $empresa, $tipoDte]);

            $stmt->execute([$empresa, $tipoDte]);
            $pool = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        $disponibles = (int) $pool['disponibles'];
        if ($disponibles <= 0) {
            $db->rollBack();
            // Emitir alerta urgente si no hay folios
            enviarAlertaFoliosAgotados($db, $empresa, $tipoDte, 0);
            responder(409, [
                'ok' => false,
                'error' => 'La central se ha quedado sin folios disponibles. Se ha emitido una alerta urgente al administrador.',
            ]);
        }

        // Asignar hasta el máximo disponible si la central tiene menos de lo solicitado
        $cantidadAsignar = min($cantidadDeseada, $disponibles);
        $ultimoAsignado = (int) $pool['ultimo_asignado'];
        $rangoDesde = $ultimoAsignado + 1;
        $rangoHasta = $ultimoAsignado + $cantidadAsignar;
        $nuevosDisponibles = $disponibles - $cantidadAsignar;

        // Actualizar pool central
        $updatePool = $db->prepare('UPDATE dte_folios_pool 
                                    SET ultimo_asignado = ?, disponibles = ?, actualizado_en = CURRENT_TIMESTAMP 
                                    WHERE id = ?');
        $updatePool->execute([$rangoHasta, $nuevosDisponibles, $pool['id']]);

        // Registrar asignación en histórico de terminales
        $asigId = 'asig_' . bin2hex(random_bytes(8));
        $insertAsig = $db->prepare('INSERT INTO dte_terminal_folios (id, empresa_id, local_codigo, terminal_id, tipo_dte, rango_desde, rango_hasta, cantidad)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $insertAsig->execute([$asigId, $empresa, $local, $terminalId, $tipoDte, $rangoDesde, $rangoHasta, $cantidadAsignar]);

        $db->commit();

        // 3. Monitorear umbral mínimo en la central y alertar al administrador
        $umbralAlerta = (int) ($pool['umbral_alerta'] ?: 3000);
        if ($nuevosDisponibles <= $umbralAlerta) {
            enviarAlertaFoliosAgotados($db, $empresa, $tipoDte, $nuevosDisponibles);
        }

        responder(200, [
            'ok' => true,
            'mensaje' => "Se asignó exitosamente lote de {$cantidadAsignar} folios a la terminal.",
            'tipo_dte' => $tipoDte,
            'rango_desde' => $rangoDesde,
            'rango_hasta' => $rangoHasta,
            'cantidad' => $cantidadAsignar,
            'caf_xml' => $pool['caf_xml'] ?? '',
            'central_disponibles' => $nuevosDisponibles,
        ]);
    }

    responder(400, ['error' => 'Acción no soportada.']);
} catch (Throwable $e) {
    responder(500, ['error' => 'Error en el servidor de folios: ' . $e->getMessage()]);
}

/**
 * Emite una alerta automática al correo del administrador general
 */
function enviarAlertaFoliosAgotados(PDO $db, string $empresaId, int $tipoDte, int $foliosRestantes): void
{
    $destinatario = 'wladimirponce@gmail.com';

    // Verificar si ya se envió una alerta en las últimas 24 horas para no saturar la casilla
    $check = $db->prepare('SELECT alerta_enviada_en FROM dte_folios_pool WHERE empresa_id = ? AND tipo_dte = ?');
    $check->execute([$empresaId, $tipoDte]);
    $ultimoEnvio = $check->fetchColumn();

    if ($ultimoEnvio) {
        $horas = (time() - strtotime((string) $ultimoEnvio)) / 3600;
        if ($horas < 24 && $foliosRestantes > 100) {
            // Ya fue notificado recientemente a menos que queden menos de 100 folios
            return;
        }
    }

    // Obtener datos de la empresa
    $stmtEmp = $db->prepare('SELECT nombre, rut FROM empresas WHERE id = ?');
    $stmtEmp->execute([$empresaId]);
    $empresaData = $stmtEmp->fetch(PDO::FETCH_ASSOC) ?: ['nombre' => $empresaId, 'rut' => ''];

    $nombreDte = match ($tipoDte) {
        33 => 'Factura Electrónica',
        39 => 'Boleta Electrónica',
        41 => 'Boleta Exenta Electrónica',
        default => "DTE Tipo {$tipoDte}",
    };

    $asunto = "⚠️ [URGENTE MyPOS Lite] Quedan {$foliosRestantes} folios de {$nombreDte} ({$empresaData['nombre']})";
    
    $cuerpo = "Estimado Administrador,\n\n"
            . "El bolsón central de folios de MyPOS Lite para la empresa {$empresaData['nombre']} (RUT: {$empresaData['rut']}) "
            . "ha cruzado el umbral mínimo de seguridad.\n\n"
            . "DETALLES:\n"
            . "- Tipo de Documento: {$nombreDte} (Tipo {$tipoDte})\n"
            . "- Folios Disponibles en Central: {$foliosRestantes} folios\n"
            . "- Fecha y Hora: " . date('Y-m-d H:i:s') . "\n\n"
            . "ACCIÓN REQUERIDA:\n"
            . "1. Ingrese al portal del SII (sii.cl) con el certificado digital de la empresa.\n"
            . "2. Solicite una nueva autorización y timbraje de folios (archivo CAF) para {$nombreDte}.\n"
            . "3. Ingrese a MyPOS Lite (https://lite.mypos.cl) y cargue el nuevo archivo CAF para reponer la disponibilidad central.\n\n"
            . "Este mensaje fue emitido automáticamente por el sistema de contingencia y resiliencia de MyPOS Lite.\n";

    $headers = "From: noreply@mypos.cl\r\n"
             . "Reply-To: wladimirponce@gmail.com\r\n"
             . "X-Mailer: MyPOS-Lite-Resilience-Engine/1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";

    @mail($destinatario, $asunto, $cuerpo, $headers);

    // Actualizar marca de tiempo de la alerta enviada
    $updateAlert = $db->prepare('UPDATE dte_folios_pool SET alerta_enviada_en = CURRENT_TIMESTAMP WHERE empresa_id = ? AND tipo_dte = ?');
    $updateAlert->execute([$empresaId, $tipoDte]);
}
