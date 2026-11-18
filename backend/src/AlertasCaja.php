<?php

declare(strict_types=1);

/**
 * Alertas de caja del informe diario:
 * - Diferencias repetidas: un vendedor con diferencia de arqueo en sus últimos tres turnos cerrados hasta la jornada.
 * - Devoluciones fuera de patrón: tres o más devoluciones de un vendedor en la jornada y más del triple de su promedio
 *   diario de los 30 días anteriores.
 * Las ventas en contingencia sin sincronizar viven en el equipo de la caja: las avisa la caja al cerrar el turno.
 *
 * @return list<string>
 */
function alertasCaja(PDO $db, string $empresa, string $fecha, DateTimeZone $zona): array
{
    $fin = (new DateTimeImmutable($fecha, $zona))->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $inicio = (new DateTimeImmutable($fecha, $zona))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $peso = static fn (int $monto): string => ($monto < 0 ? '-$' : '$') . number_format(abs($monto), 0, ',', '.');
    $alertas = [];
    $cajeros = $db->prepare('SELECT DISTINCT t.abierto_por, u.nombre FROM turnos t JOIN usuarios u ON u.id = t.abierto_por
        WHERE t.empresa_id = ? AND t.cerrado_en >= ? AND t.cerrado_en < ?');
    $cajeros->execute([$empresa, $inicio, $fin]);
    $ultimos = $db->prepare('SELECT diferencia FROM turnos WHERE empresa_id = ? AND abierto_por = ? AND cerrado_en IS NOT NULL AND cerrado_en < ?
        ORDER BY cerrado_en DESC LIMIT 3');
    foreach ($cajeros->fetchAll(PDO::FETCH_ASSOC) as $cajero) {
        $ultimos->execute([$empresa, $cajero['abierto_por'], $fin]);
        $diferencias = array_map('intval', $ultimos->fetchAll(PDO::FETCH_COLUMN));
        if (count($diferencias) === 3 && !in_array(0, $diferencias, true)) {
            $alertas[] = $cajero['nombre'] . ' tuvo diferencia de caja en sus últimos tres turnos: '
                . implode(', ', array_map($peso, array_reverse($diferencias))) . '.';
        }
    }
    $devoluciones = $db->prepare('SELECT d.usuario_id, u.nombre, COUNT(*) AS hoy,
            (SELECT COUNT(*) FROM devoluciones p WHERE p.empresa_id = d.empresa_id AND p.usuario_id = d.usuario_id
               AND p.creada_en >= ? - INTERVAL 30 DAY AND p.creada_en < ?) AS previas
        FROM devoluciones d JOIN usuarios u ON u.id = d.usuario_id
        WHERE d.empresa_id = ? AND d.creada_en >= ? AND d.creada_en < ? GROUP BY d.usuario_id, u.nombre');
    $devoluciones->execute([$inicio, $inicio, $empresa, $inicio, $fin]);
    foreach ($devoluciones->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $promedio = (int) $fila['previas'] / 30;
        if ((int) $fila['hoy'] >= 3 && (int) $fila['hoy'] > 3 * $promedio) {
            $alertas[] = $fila['nombre'] . ' registró ' . $fila['hoy'] . ' devoluciones en la jornada, sobre su promedio de '
                . number_format($promedio, 1, ',', '.') . ' por día.';
        }
    }
    return $alertas;
}
