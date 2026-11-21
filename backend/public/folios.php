<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../src/AccesoMaestros.php';
require_once __DIR__ . '/../src/Emision.php';
require_once __DIR__ . '/../src/Correo.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Calcula el promedio de consumo diario/mensual real de la empresa y la cantidad exacta
 * a solicitar al SII (siempre >= 1 mes y nunca > 2 meses).
 *
 * @return array{promedio_diario: float, promedio_mensual: int, min_un_mes: int, max_dos_meses: int, cantidad_sugerida: int, origen_calculo: string}
 */
function calcularDemandaFolios(PDO $db, string $empresaId, int $tipoDte): array
{
    $promedioMensualBase = 300; // Base conservadora para empresas nuevas sin historial

    // 1. Medir consumo real por documentos emitidos en los últimos 60 días
    $stmtDocs = $db->prepare(
        "SELECT COUNT(*) AS total, MIN(creado_en) AS primero
         FROM dte_documentos
         WHERE empresa_id = ? AND ambiente = 'produccion' AND tipo = ?
           AND creado_en >= UTC_TIMESTAMP() - INTERVAL 60 DAY"
    );
    $stmtDocs->execute([$empresaId, $tipoDte]);
    $docs = $stmtDocs->fetch(PDO::FETCH_ASSOC);
    $totalDocs = (int) ($docs['total'] ?? 0);

    // 2. Complementar con folios efectivamente asignados a locales en últimos 60 días
    $stmtAsig = $db->prepare(
        "SELECT COALESCE(SUM(cantidad), 0) AS total, MIN(asignado_en) AS primero
         FROM dte_terminal_folios
         WHERE empresa_id = ? AND tipo_dte = ?
           AND asignado_en >= UTC_TIMESTAMP() - INTERVAL 60 DAY"
    );
    $stmtAsig->execute([$empresaId, $tipoDte]);
    $asig = $stmtAsig->fetch(PDO::FETCH_ASSOC);
    $totalAsig = (int) ($asig['total'] ?? 0);

    $origen = 'base_inicial';
    $promedioDiario = $promedioMensualBase / 30.0;

    if ($totalDocs >= 10 && !empty($docs['primero'])) {
        $dias = max(7.0, min(60.0, (time() - strtotime((string) $docs['primero'])) / 86400.0));
        $promedioDiario = max(1.0, $totalDocs / $dias);
        $origen = 'historial_emision';
    } elseif ($totalAsig > 0 && !empty($asig['primero'])) {
        $dias = max(15.0, min(60.0, (time() - strtotime((string) $asig['primero'])) / 86400.0));
        $promedioDiario = max(1.0, $totalAsig / $dias);
        $origen = 'historial_tramos';
    }

    $promedioMensual = max(100, (int) ceil($promedioDiario * 30.0));
    $minUnMes = $promedioMensual;
    $maxDosMeses = $promedioMensual * 2;
    // Objetivo: 1,5 meses redondeado a la centena superior, acotado estrictamente entre [1 mes, 2 meses]
    $objetivo = (int) (ceil(($promedioMensual * 1.5) / 100.0) * 100);
    $sugerida = max($minUnMes, min($maxDosMeses, $objetivo));

    return [
        'promedio_diario' => round($promedioDiario, 2),
        'promedio_mensual' => $promedioMensual,
        'min_un_mes' => $minUnMes,
        'max_dos_meses' => $maxDosMeses,
        'cantidad_sugerida' => $sugerida,
        'origen_calculo' => $origen,
    ];
}

/**
 * Total de folios disponibles en el almacén central (CAFs reales de producción si existen; si no, pool demo).
 *
 * @return array{origen: string, disponibles: int}
 */
function disponiblesCentral(PDO $db, string $empresaId, int $tipoDte): array
{
    $stmtCaf = $db->prepare(
        "SELECT COALESCE(SUM(GREATEST(0, hasta - siguiente + 1)), 0)
         FROM dte_cafs
         WHERE empresa_id = ? AND ambiente = 'produccion' AND tipo = ? AND siguiente <= hasta"
    );
    $stmtCaf->execute([$empresaId, $tipoDte]);
    $enCaf = (int) $stmtCaf->fetchColumn();

    $stmtTieneCaf = $db->prepare(
        "SELECT COUNT(*) FROM dte_cafs WHERE empresa_id = ? AND ambiente = 'produccion' AND tipo = ?"
    );
    $stmtTieneCaf->execute([$empresaId, $tipoDte]);
    if ((int) $stmtTieneCaf->fetchColumn() > 0) {
        return ['origen' => 'caf_produccion', 'disponibles' => $enCaf];
    }

    $stmtPool = $db->prepare(
        'SELECT disponibles FROM dte_folios_pool WHERE empresa_id = ? AND tipo_dte = ? LIMIT 1'
    );
    $stmtPool->execute([$empresaId, $tipoDte]);
    $poolDisp = $stmtPool->fetchColumn();
    if ($poolDisp !== false) {
        return ['origen' => 'pool_central', 'disponibles' => (int) $poolDisp];
    }

    return ['origen' => 'pool_central', 'disponibles' => 0];
}

/**
 * Evalúa si la empresa necesita reposición anticipada en la central y envía aviso a wladimirponce@gmail.com
 * indicando razón social, RUT, promedio de consumo y cantidad a solicitar (entre 1 y 2 meses).
 */
function evaluarYAlertarReposicionCentral(PDO $db, string $empresaId, int $tipoDte, int $centralDisponibles, array $demanda): bool
{
    $promedioDiario = max(1.0, (float) $demanda['promedio_diario']);
    $diasCobertura = (int) floor($centralDisponibles / $promedioDiario);

    // Avisar con anticipación cuando quedan <= 15 días de operación o <= 200 folios en la central
    if ($diasCobertura > 15 && $centralDisponibles > 200) {
        return false;
    }

    $destinatario = 'wladimirponce@gmail.com';
    $esCritico = $centralDisponibles <= 50;
    $fechaClave = (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('Y-m-d');
    $claveCorreo = 'alerta-folios-central:' . $tipoDte . ':' . $fechaClave . ($esCritico ? ':critico' : '');

    // Obtener datos de la empresa
    $stmtEmp = $db->prepare('SELECT razon_social, rut FROM empresas WHERE id = ?');
    $stmtEmp->execute([$empresaId]);
    $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC) ?: ['razon_social' => $empresaId, 'rut' => ''];
    $razonSocial = (string) ($emp['razon_social'] ?: $empresaId);
    $rutRaw = (string) ($emp['rut'] ?? '');
    $rutFormateado = strlen($rutRaw) >= 2 && !str_contains($rutRaw, '-')
        ? substr($rutRaw, 0, -1) . '-' . substr($rutRaw, -1)
        : $rutRaw;

    $nombreDte = match ($tipoDte) {
        33 => 'Factura Electrónica',
        39 => 'Boleta Electrónica',
        41 => 'Boleta Exenta Electrónica',
        default => "DTE Tipo {$tipoDte}",
    };

    $nivel = $centralDisponibles <= 0 ? 'AGOTADO' : ($esCritico ? 'CRÍTICO' : 'ANTICIPADO');
    $asunto = "[Folios {$nivel} MyPOS Lite] {$razonSocial} ({$rutFormateado}) - Pedir {$demanda['cantidad_sugerida']} folios {$nombreDte}";

    $cuerpo = "Hola Wladimir,\n\n"
        . "El almacén central de folios de MyPOS Lite requiere reposición anticipada para la siguiente empresa:\n\n"
        . "DATOS DE LA EMPRESA:\n"
        . "- Empresa: {$razonSocial}\n"
        . "- RUT: {$rutFormateado}\n"
        . "- Documento: {$nombreDte} (Tipo {$tipoDte})\n\n"
        . "ESTADO ACTUAL Y CONSUMO:\n"
        . "- Folios disponibles en almacén central: {$centralDisponibles}\n"
        . "- Cobertura estimada restante: {$diasCobertura} días\n"
        . "- Promedio de consumo diario: {$demanda['promedio_diario']} folios/día\n"
        . "- Promedio de consumo mensual: {$demanda['promedio_mensual']} folios/mes\n\n"
        . "SOLICITUD RECOMENDADA AL SII (REGLA 1 A 2 MESES):\n"
        . "- Cantidad recomendada a pedir: {$demanda['cantidad_sugerida']} folios\n"
        . "- Rango permitido (1 a 2 meses): mínimo {$demanda['min_un_mes']} folios / máximo {$demanda['max_dos_meses']} folios\n\n"
        . "Fecha y hora: " . (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('Y-m-d H:i:s') . "\n"
        . "Una vez descargado el nuevo CAF desde sii.cl, cárgalo en MyPOS Lite -> Documentos tributarios para reponer el almacén central.\n";

    // 1. Encolar en correo_envios (idempotente por clave diaria, se despacha por SMTP via scheduler)
    $encolado = encolarCorreo($db, $empresaId, 'soporte', $destinatario, $asunto, $cuerpo, $claveCorreo);

    // 2. Respaldo opcional con mail() nativo si es un aviso nuevo
    if ($encolado) {
        $headers = "From: noreply@mypos.cl\r\n"
            . "Reply-To: wladimirponce@gmail.com\r\n"
            . "X-Mailer: MyPOS-Lite-Resilience-Engine/2.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";
        @mail($destinatario, $asunto, $cuerpo, $headers);

        $db->prepare('UPDATE dte_folios_pool SET alerta_enviada_en = CURRENT_TIMESTAMP WHERE empresa_id = ? AND tipo_dte = ?')
            ->execute([$empresaId, $tipoDte]);
    }

    return $encolado;
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
    ['db' => $db, 'empresa' => $empresa, 'local' => $local] = $acceso;

    if (($_SERVER['HTTP_X_MYPOSLITE_EMPRESA'] ?? null) !== null && $_SERVER['HTTP_X_MYPOSLITE_EMPRESA'] !== $empresa) {
        responder(409, ['error' => 'Cambió la empresa activa.']);
    }
    if (($_SERVER['HTTP_X_MYPOSLITE_LOCAL'] ?? null) !== null && rawurldecode($_SERVER['HTTP_X_MYPOSLITE_LOCAL']) !== $local) {
        responder(409, ['error' => 'Cambió el local activo.']);
    }

    // 1. GET: Consultar estado del almacén central de folios y tramos del local
    if ($method === 'GET') {
        $tipoDte = isset($_GET['tipo_dte']) ? (int) $_GET['tipo_dte'] : 39;
        $central = disponiblesCentral($db, $empresa, $tipoDte);
        $demanda = calcularDemandaFolios($db, $empresa, $tipoDte);

        $stmtTerm = $db->prepare(
            'SELECT terminal_id, caf_id, rango_desde, rango_hasta, cantidad, ultimo_folio_usado, asignado_en
             FROM dte_terminal_folios
             WHERE empresa_id = ? AND local_codigo = ? AND tipo_dte = ?
             ORDER BY asignado_en DESC LIMIT 10'
        );
        $stmtTerm->execute([$empresa, $local, $tipoDte]);
        $asignaciones = $stmtTerm->fetchAll(PDO::FETCH_ASSOC);

        responder(200, [
            'ok' => true,
            'central' => $central,
            'demanda' => $demanda,
            'asignaciones_recientes' => $asignaciones,
        ]);
    }

    // 2. POST: Solicitar tramo exclusivo de folios para este local/terminal
    $input = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    $accion = $input['accion'] ?? 'solicitar_lote';
    $tipoDte = isset($input['tipo_dte']) ? (int) $input['tipo_dte'] : 39;
    if (!in_array($tipoDte, [39, 41], true)) {
        responder(400, ['error' => 'Tipo de documento no soportado para tramos locales.']);
    }
    $cantidadDeseada = isset($input['cantidad']) ? max(50, min(1000, (int) $input['cantidad'])) : 200;
    $terminalId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($input['terminal_id'] ?? 'pos_local_1')) ?: 'pos_local_1';
    $terminalId = substr($terminalId, 0, 64);

    if ($accion === 'solicitar_lote') {
        $demanda = calcularDemandaFolios($db, $empresa, $tipoDte);

        $db->beginTransaction();
        try {
            // Prioridad 1: Almacén central real en dte_cafs (producción)
            $stmtTieneCaf = $db->prepare(
                "SELECT COUNT(*) FROM dte_cafs WHERE empresa_id = ? AND ambiente = 'produccion' AND tipo = ?"
            );
            $stmtTieneCaf->execute([$empresa, $tipoDte]);
            $usaCafReal = (int) $stmtTieneCaf->fetchColumn() > 0;

            $cafId = null;
            $rangoDesde = 0;
            $rangoHasta = 0;
            $cantidadAsignar = 0;
            $centralRestantes = 0;

            if ($usaCafReal) {
                $stmtCaf = $db->prepare(
                    "SELECT id, desde, hasta, siguiente
                     FROM dte_cafs
                     WHERE empresa_id = ? AND ambiente = 'produccion' AND tipo = ? AND siguiente <= hasta
                     ORDER BY desde ASC
                     LIMIT 1 FOR UPDATE"
                );
                $stmtCaf->execute([$empresa, $tipoDte]);
                $caf = $stmtCaf->fetch(PDO::FETCH_ASSOC);

                if (!$caf) {
                    $db->rollBack();
                    evaluarYAlertarReposicionCentral($db, $empresa, $tipoDte, 0, $demanda);
                    responder(409, [
                        'ok' => false,
                        'error' => 'El almacén central se ha quedado sin folios disponibles. Se notificó a la administración para solicitar un nuevo CAF.',
                    ]);
                }

                $disponiblesEnCaf = (int) $caf['hasta'] - (int) $caf['siguiente'] + 1;
                $cantidadAsignar = min($cantidadDeseada, $disponiblesEnCaf);
                $rangoDesde = (int) $caf['siguiente'];
                $rangoHasta = $rangoDesde + $cantidadAsignar - 1;
                $cafId = (string) $caf['id'];

                $db->prepare('UPDATE dte_cafs SET siguiente = ? WHERE empresa_id = ? AND id = ?')
                    ->execute([$rangoHasta + 1, $empresa, $cafId]);

                // Sumar saldo total restante en todos los CAFs activos
                $stmtRest = $db->prepare(
                    "SELECT COALESCE(SUM(GREATEST(0, hasta - siguiente + 1)), 0)
                     FROM dte_cafs
                     WHERE empresa_id = ? AND ambiente = 'produccion' AND tipo = ? AND siguiente <= hasta"
                );
                $stmtRest->execute([$empresa, $tipoDte]);
                $centralRestantes = (int) $stmtRest->fetchColumn();
            } else {
                // Prioridad 2: Fallback a dte_folios_pool (empresas demo / antes de certificar SII)
                $stmtPool = $db->prepare(
                    'SELECT id, folio_desde, folio_hasta, ultimo_asignado, disponibles
                     FROM dte_folios_pool
                     WHERE empresa_id = ? AND tipo_dte = ? FOR UPDATE'
                );
                $stmtPool->execute([$empresa, $tipoDte]);
                $pool = $stmtPool->fetch(PDO::FETCH_ASSOC);

                if (!$pool) {
                    $poolId = 'pool_' . bin2hex(random_bytes(8));
                    $db->prepare(
                        'INSERT INTO dte_folios_pool (id, empresa_id, tipo_dte, folio_desde, folio_hasta, ultimo_asignado, disponibles, umbral_alerta)
                         VALUES (?, ?, ?, 1, 50000, 0, 50000, 3000)'
                    )->execute([$poolId, $empresa, $tipoDte]);

                    $stmtPool->execute([$empresa, $tipoDte]);
                    $pool = $stmtPool->fetch(PDO::FETCH_ASSOC);
                }

                $disponibles = (int) $pool['disponibles'];
                if ($disponibles <= 0) {
                    $db->rollBack();
                    evaluarYAlertarReposicionCentral($db, $empresa, $tipoDte, 0, $demanda);
                    responder(409, [
                        'ok' => false,
                        'error' => 'La central se ha quedado sin folios disponibles. Se emitió una alerta urgente al administrador.',
                    ]);
                }

                $cantidadAsignar = min($cantidadDeseada, $disponibles);
                $ultimoAsignado = (int) $pool['ultimo_asignado'];
                $rangoDesde = $ultimoAsignado + 1;
                $rangoHasta = $ultimoAsignado + $cantidadAsignar;
                $centralRestantes = $disponibles - $cantidadAsignar;

                $db->prepare(
                    'UPDATE dte_folios_pool
                     SET ultimo_asignado = ?, disponibles = ?, actualizado_en = CURRENT_TIMESTAMP
                     WHERE id = ?'
                )->execute([$rangoHasta, $centralRestantes, $pool['id']]);
            }

            // Registrar tramo asignado a este local/terminal
            $asigId = 'asig_' . bin2hex(random_bytes(8));
            $db->prepare(
                'INSERT INTO dte_terminal_folios (id, empresa_id, local_codigo, terminal_id, tipo_dte, caf_id, rango_desde, rango_hasta, cantidad)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$asigId, $empresa, $local, $terminalId, $tipoDte, $cafId, $rangoDesde, $rangoHasta, $cantidadAsignar]);

            $db->commit();
        } catch (Throwable $txErr) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $txErr;
        }

        // Evaluar reposición anticipada y avisar a wladimirponce@gmail.com si corresponde
        $alertaEnviada = evaluarYAlertarReposicionCentral($db, $empresa, $tipoDte, $centralRestantes, $demanda);

        responder(200, [
            'ok' => true,
            'mensaje' => "Se asignó tramo de {$cantidadAsignar} folios ({$rangoDesde}–{$rangoHasta}) al local.",
            'origen' => $usaCafReal ? 'caf_produccion' : 'pool_central',
            'tipo_dte' => $tipoDte,
            'caf_id' => $cafId,
            'rango_desde' => $rangoDesde,
            'rango_hasta' => $rangoHasta,
            'cantidad' => $cantidadAsignar,
            'umbral_recarga' => max(20, (int) floor($cantidadAsignar * 0.25)),
            'central_disponibles' => $centralRestantes,
            'alerta_central_enviada' => $alertaEnviada,
            'demanda' => $demanda,
        ]);
    }

    responder(400, ['error' => 'Acción no soportada.']);
} catch (Throwable $e) {
    responder(500, ['error' => 'Error en el servidor de folios: ' . $e->getMessage()]);
}
