<?php

declare(strict_types=1);

require_once __DIR__ . '/Correo.php';
require_once __DIR__ . '/AlertasCaja.php';
require_once __DIR__ . '/InformeInventario.php';

/**
 * Correo del informe diario por RUT, sobre el cierre diario por local (`CierreDiario.php`).
 *
 * - Sale cuando todos los locales de la empresa cerraron la jornada, o al día siguiente desde las 06:00 de la zona de la
 *   empresa con los locales sin cerrar marcados como tales.
 * - El asunto nunca afirma aceptación con pendientes: «Documentos del día aceptados por el SII» solo si todos lo están;
 *   si no, las cantidades («118 boletas aceptadas y 2 pendientes de respuesta del SII»). Un día sin documentos se dice así.
 * - Si el informe salió con pendientes de respuesta del SII, una sola actualización sale cuando el SII los responde.
 */
const HORA_INFORME_DIARIO = 6;

function nombresInforme(int $tipo, int $cantidad): string
{
    $nombres = [39 => ['boleta', 'boletas'], 41 => ['boleta exenta', 'boletas exentas'], 33 => ['factura', 'facturas'],
        34 => ['factura exenta', 'facturas exentas'], 52 => ['guía', 'guías'], 56 => ['nota de débito', 'notas de débito'],
        61 => ['nota de crédito', 'notas de crédito']];
    return $cantidad . ' ' . ($nombres[$tipo][$cantidad === 1 ? 0 : 1] ?? 'documentos');
}

/**
 * Arma el informe de una jornada con lo guardado en cada cierre y el estado SII consultado en este momento.
 *
 * @return array{asunto: string, cuerpo: string, pendientes: int, listo: bool}
 */
function informeDiario(PDO $db, string $empresa, string $fecha, DateTimeImmutable $ahora): array
{
    require_once __DIR__ . '/CierreDiario.php';
    $empresaDatos = $db->prepare('SELECT razon_social, rut FROM empresas WHERE id = ?');
    $empresaDatos->execute([$empresa]);
    $datos = $empresaDatos->fetch(PDO::FETCH_ASSOC);
    $locales = $db->prepare('SELECT codigo, nombre FROM locales WHERE empresa_id = ? ORDER BY codigo');
    $locales->execute([$empresa]);
    $locales = $locales->fetchAll(PDO::FETCH_KEY_PAIR);
    $peso = static fn (int $monto): string => '$' . number_format($monto, 0, ',', '.');
    $lineas = [];
    $sinCerrar = [];
    $total = 0;
    foreach ($locales as $codigo => $nombre) {
        $cierre = leerCierre($db, $empresa, (string) $codigo, $fecha);
        if ($cierre === null) {
            $sinCerrar[] = $nombre;
            $lineas[] = "$nombre: jornada sin cerrar.";
            continue;
        }
        $t = json_decode($cierre['totales'], true);
        $total += (int) ($t['total'] ?? 0);
        $linea = "$nombre: " . (int) ($t['cantidad'] ?? 0) . ' ventas por ' . $peso((int) ($t['total'] ?? 0));
        $pagos = array_map(static fn ($medio, $monto): string => "$medio " . $peso((int) $monto), array_keys($t['pagos'] ?? []), $t['pagos'] ?? []);
        if ($pagos) {
            $linea .= ' (' . implode(', ', $pagos) . ')';
        }
        foreach ($t['turnos'] ?? [] as $turno) {
            if ((int) ($turno['diferencia'] ?? 0) !== 0) {
                $linea .= '. Diferencia de caja en ' . $turno['caja'] . ': ' . $peso((int) $turno['diferencia']);
            }
        }
        $lineas[] = $linea . '. Cerrado el ' . substr((string) $cierre['cerrado_en'], 0, 16) . '.';
    }
    $folios = foliosCierre($db, $empresa, $fecha, array_map('strval', array_keys($locales)));
    $aceptados = $pendientes = $rechazados = $reparos = 0;
    $partes = [];
    $detalle = [];
    foreach ($folios as $grupo) {
        $e = $grupo['estados'];
        $ok = ($e['aceptado'] ?? 0) + ($e['reparo'] ?? 0);
        $mal = $e['rechazado'] ?? 0;
        $pend = $grupo['total'] - $ok - $mal;
        $aceptados += $ok;
        $reparos += $e['reparo'] ?? 0;
        $rechazados += $mal;
        $pendientes += $pend;
        $partes[] = ['tipo' => $grupo['tipo'], 'ok' => $ok, 'pend' => $pend, 'mal' => $mal];
        $detalle[] = nombresInforme($grupo['tipo'], $grupo['total']) . ', folios ' . implode(', ', array_map(static fn (array $r): string =>
            $r[0] === $r[1] ? (string) $r[0] : $r[0] . ' a ' . $r[1], $grupo['rangos'])) . ": $ok aceptados" . (($e['reparo'] ?? 0) ? ' (' . $e['reparo'] . ' con reparos)' : '')
            . ", $pend pendientes de respuesta del SII, $mal rechazados.";
    }
    if ($folios === []) {
        $estado = 'Sin documentos tributarios';
    } elseif ($pendientes === 0 && $rechazados === 0) {
        $estado = 'Documentos del día aceptados por el SII';
    } else {
        $estado = implode('; ', array_map(static fn (array $p): string => nombresInforme($p['tipo'], $p['ok']) . ' aceptadas'
            . ($p['pend'] ? ' y ' . $p['pend'] . ' pendientes de respuesta del SII' : '') . ($p['mal'] ? ' y ' . $p['mal'] . ' rechazadas por el SII' : ''), $partes));
    }
    $asunto = ($sinCerrar ? 'Jornada con locales sin cerrar en MyPOS Lite' : 'Día cerrado en MyPOS Lite') . ' · ' . $estado;
    $cuerpo = "{$datos['razon_social']} (RUT {$datos['rut']}) · jornada del " . implode('-', array_reverse(explode('-', $fecha))) . "\n"
        . 'Consultado el ' . $ahora->format('d-m-Y H:i') . ".\n\nPor local:\n- " . implode("\n- ", $lineas)
        . "\n\nTotal de la empresa: " . $peso($total) . ".\n\nConciliación con el SII:\n"
        . ($detalle ? '- ' . implode("\n- ", $detalle) : 'Sin documentos tributarios en la jornada.')
        . ($pendientes ? "\n\nLe avisaremos cuando el SII responda los pendientes." : '')
        . ($rechazados ? "\n\nHay documentos rechazados: revíselos en Lite." : '');
    $alertas = alertasCaja($db, $empresa, $fecha, $ahora->getTimezone());
    if ($alertas) {
        $cuerpo .= "\n\nAlertas de caja:\n- " . implode("\n- ", $alertas);
    }
    $inventario = inventarioInformeDiario($db, $empresa, $fecha, $ahora->getTimezone());
    if ($inventario) {
        $cuerpo .= "\n\nInventario:\n- " . implode("\n- ", $inventario);
    }
    return ['asunto' => $asunto, 'cuerpo' => $cuerpo, 'pendientes' => $pendientes, 'listo' => $sinCerrar === [],
        'sinCerrar' => $sinCerrar];
}

/**
 * Revisa las jornadas recientes y encola el informe y, si correspondía, su actualización. Llamado por el programador.
 * Sin el cierre diario instalado no hace nada.
 */
function revisarInformesDiariosCorreo(PDO $db, ?DateTimeImmutable $ahora = null): int
{
    if (!is_file(__DIR__ . '/CierreDiario.php')) {
        return 0;
    }
    $encolados = 0;
    $jornadas = $db->query("SELECT DISTINCT c.empresa_id, c.fecha, e.zona_horaria FROM cierres_diarios c JOIN empresas e ON e.id = c.empresa_id
        WHERE e.activa AND c.fecha >= CURRENT_DATE - INTERVAL 3 DAY")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($jornadas as $j) {
        $empresa = (string) $j['empresa_id'];
        $local = ($ahora ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone($j['zona_horaria'] ?: 'America/Santiago'));
        $informe = informeDiario($db, $empresa, $j['fecha'], $local);
        $plazo = (new DateTimeImmutable($j['fecha'], $local->getTimezone()))->modify('+1 day')->setTime(HORA_INFORME_DIARIO, 0);
        if (!$informe['listo'] && $local < $plazo) {
            continue;
        }
        $previo = $db->prepare("SELECT asunto FROM correo_envios WHERE empresa_id = ? AND tipo = 'cierre' AND clave LIKE ? ORDER BY id LIMIT 1");
        $previo->execute([$empresa, 'cierre:' . $j['fecha'] . ':inicial:%']);
        $asuntoPrevio = $previo->fetchColumn();
        if ($asuntoPrevio === false) {
            $encolados += avisarEmpresaCorreo($db, $empresa, 'cierre', $informe['asunto'], $informe['cuerpo'], 'cierre:' . $j['fecha'] . ':inicial');
        } elseif ($informe['pendientes'] === 0 && $informe['listo'] && str_contains((string) $asuntoPrevio, 'pendientes de respuesta')) {
            $encolados += avisarEmpresaCorreo($db, $empresa, 'cierre', 'Actualización · ' . $informe['asunto'], $informe['cuerpo'],
                'cierre:' . $j['fecha'] . ':actualizacion');
        }
    }
    return $encolados;
}
