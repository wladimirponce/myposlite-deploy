<?php

declare(strict_types=1);

require_once __DIR__ . '/Recepcion.php';

/**
 * Inventario del informe diario: lotes vencidos o que vencen en los próximos siete días por local (informeVencimientos)
 * y presentaciones que quedaron bajo su margen mínimo por un costo recibido en la jornada (margenPresentacion).
 *
 * @return list<string>
 */
function inventarioInformeDiario(PDO $db, string $empresa, string $fecha, DateTimeZone $zona): array
{
    $lineas = [];
    $locales = $db->prepare('SELECT codigo, nombre FROM locales WHERE empresa_id = ? ORDER BY codigo');
    $locales->execute([$empresa]);
    foreach ($locales->fetchAll(PDO::FETCH_KEY_PAIR) as $codigo => $nombre) {
        $informe = informeVencimientos($db, $empresa, (string) $codigo, 7);
        if ($informe['lotes'] === []) {
            continue;
        }
        $detalle = array_map(static fn (array $l): string => $l['nombre'] . ' ' . rtrim(rtrim($l['saldo'], '0'), '.') . ' ' . $l['unidad_base']
            . ($l['dias'] < 0 ? ', vencido el ' : ', vence el ') . implode('-', array_reverse(explode('-', $l['vencimiento']))), array_slice($informe['lotes'], 0, 5));
        $lineas[] = "$nombre: " . count($informe['lotes']) . ' lotes vencidos o por vencer en 7 días'
            . ($informe['vencidos'] ? ' (' . $informe['vencidos'] . ' vencidos)' : '') . ': ' . implode('; ', $detalle)
            . (count($informe['lotes']) > 5 ? '; y otros.' : '.');
    }
    $inicio = (new DateTimeImmutable($fecha, $zona))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $fin = (new DateTimeImmutable($fecha, $zona))->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $productos = $db->prepare("SELECT DISTINCT p.id, p.nombre, p.costo, p.margen_minimo, p.afecto_iva FROM producto_eventos e
        JOIN productos p ON p.empresa_id = e.empresa_id AND p.id = e.producto_id
        WHERE e.empresa_id = ? AND e.accion = 'costo_recepcion' AND e.registrado_en >= ? AND e.registrado_en < ?
          AND p.margen_minimo IS NOT NULL AND p.costo IS NOT NULL ORDER BY p.nombre");
    $productos->execute([$empresa, $inicio, $fin]);
    $presentaciones = $db->prepare('SELECT nombre, precio, equivalencia FROM producto_presentaciones WHERE empresa_id = ? AND producto_id = ? ORDER BY equivalencia, id');
    foreach ($productos->fetchAll(PDO::FETCH_ASSOC) as $producto) {
        $presentaciones->execute([$empresa, $producto['id']]);
        foreach ($presentaciones->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $margen = margenPresentacion((string) $p['precio'], (bool) $producto['afecto_iva'], (string) $producto['costo'], (string) $p['equivalencia']);
            if ($margen !== null && bccomp($margen, (string) $producto['margen_minimo'], 6) < 0) {
                $lineas[] = $producto['nombre'] . ' · ' . $p['nombre'] . ': margen de ' . numeroChileno($margen, 1) . ' % con el costo recibido hoy, bajo el mínimo de '
                    . numeroChileno((string) $producto['margen_minimo'], 2) . ' %.';
            }
        }
    }
    return $lineas;
}
