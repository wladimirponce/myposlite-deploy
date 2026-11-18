<?php

declare(strict_types=1);

function zonaCierre(PDO $db, string $empresa): DateTimeZone
{
    $zona = $db->prepare('SELECT zona_horaria FROM empresas WHERE id = ?');
    $zona->execute([$empresa]);
    return new DateTimeZone((string) $zona->fetchColumn());
}

function jornadaCierre(PDO $db, string $empresa, mixed $fecha): DateTimeImmutable
{
    $zona = zonaCierre($db, $empresa);
    if ($fecha === null) {
        return new DateTimeImmutable('today', $zona);
    }
    $dia = is_string($fecha) ? DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, $zona) : false;
    if (!$dia || $dia->format('Y-m-d') !== $fecha || $dia > new DateTimeImmutable('today', $zona)) {
        throw new InvalidArgumentException('Seleccione una fecha comercial válida.');
    }
    return $dia;
}

function bloquearLocalCierre(PDO $db, string $empresa, string $local): void
{
    $lock = $db->prepare('SELECT codigo FROM locales WHERE empresa_id = ? AND codigo = ? FOR UPDATE');
    $lock->execute([$empresa, $local]);
    $lock->fetchColumn();
}

function leerCierre(PDO $db, string $empresa, string $local, string $fecha): ?array
{
    $query = $db->prepare('SELECT totales, cerrado_en FROM cierres_diarios WHERE empresa_id = ? AND local_codigo = ? AND fecha = ?');
    $query->execute([$empresa, $local, $fecha]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

function totalesCierre(PDO $db, string $empresa, string $local, DateTimeImmutable $dia): array
{
    $utc = new DateTimeZone('UTC');
    $inicio = $dia->setTimezone($utc)->format('Y-m-d H:i:s');
    $fin = $dia->modify('tomorrow')->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s');
    $ventas = $db->prepare('SELECT COUNT(*) AS cantidad, COALESCE(SUM(total), 0) AS total
        FROM ventas WHERE empresa_id = ? AND local_codigo = ? AND creada_en >= ? AND creada_en < ?');
    $ventas->execute([$empresa, $local, $inicio, $fin]);
    $resultado = array_map('intval', $ventas->fetch(PDO::FETCH_ASSOC));

    $pagos = $db->prepare('SELECT p.medio, SUM(p.monto) FROM ventas v
        JOIN venta_pagos p ON p.empresa_id = v.empresa_id AND p.venta_id = v.id
        WHERE v.empresa_id = ? AND v.local_codigo = ? AND v.creada_en >= ? AND v.creada_en < ? GROUP BY p.medio');
    $pagos->execute([$empresa, $local, $inicio, $fin]);
    $resultado['pagos'] = array_map('intval', $pagos->fetchAll(PDO::FETCH_KEY_PAIR));

    $vendedores = $db->prepare('SELECT u.nombre, COUNT(*) AS cantidad, SUM(v.total) AS total FROM ventas v
        JOIN usuarios u ON u.id = v.vendedor_id
        WHERE v.empresa_id = ? AND v.local_codigo = ? AND v.creada_en >= ? AND v.creada_en < ?
        GROUP BY u.id, u.nombre ORDER BY u.nombre, u.id');
    $vendedores->execute([$empresa, $local, $inicio, $fin]);
    $resultado['vendedores'] = $vendedores->fetchAll(PDO::FETCH_ASSOC);

    $movimientos = $db->prepare('SELECT m.tipo, m.medio, SUM(m.monto) AS monto FROM caja_movimientos m
        JOIN turnos t ON t.empresa_id = m.empresa_id AND t.id = m.turno_id
        WHERE m.empresa_id = ? AND t.local_codigo = ? AND m.creado_en >= ? AND m.creado_en < ? GROUP BY m.tipo, m.medio');
    $movimientos->execute([$empresa, $local, $inicio, $fin]);
    $resultado['movimientos'] = $movimientos->fetchAll(PDO::FETCH_ASSOC);

    $turnos = $db->prepare('SELECT t.caja_codigo, c.nombre AS caja, t.abierto_en, t.cerrado_en, t.diferencia
        FROM turnos t JOIN cajas c ON c.empresa_id = t.empresa_id AND c.local_codigo = t.local_codigo AND c.codigo = t.caja_codigo
        WHERE t.empresa_id = ? AND t.local_codigo = ? AND t.abierto_en < ?
          AND (t.cerrado_en IS NULL OR t.cerrado_en > ?) ORDER BY t.abierto_en');
    $turnos->execute([$empresa, $local, $fin, $inicio]);
    $resultado['turnos'] = $turnos->fetchAll(PDO::FETCH_ASSOC);
    $resultado['abiertos'] = count(array_filter($resultado['turnos'], static fn(array $turno): bool => $turno['cerrado_en'] === null));
    return $resultado;
}

function foliosCierre(PDO $db, string $empresa, string $fecha, array $locales): array
{
    $query = $db->prepare("SELECT d.tipo, d.folio, d.estado, COALESCE(d.local_codigo, v.local_codigo, t.local_codigo) AS local
        FROM dte_documentos d
        LEFT JOIN ventas v ON v.empresa_id = d.empresa_id AND v.documento_id = d.id
        LEFT JOIN devoluciones dev ON dev.empresa_id = d.empresa_id AND dev.documento_id = d.id
        LEFT JOIN turnos t ON t.empresa_id = dev.empresa_id AND t.id = dev.turno_id
        WHERE d.empresa_id = ? AND d.ambiente = 'produccion' AND d.fecha_emision = ?
        ORDER BY d.tipo, d.folio");
    $query->execute([$empresa, $fecha]);
    $resumen = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $doc) {
        if (!in_array($doc['local'], $locales, true)) {
            continue;
        }
        $tipo = (int) $doc['tipo'];
        $folio = (int) $doc['folio'];
        $resumen[$tipo] ??= ['tipo' => $tipo, 'total' => 0, 'estados' => [], 'rangos' => []];
        $grupo = &$resumen[$tipo];
        $grupo['total']++;
        $grupo['estados'][$doc['estado']] = ($grupo['estados'][$doc['estado']] ?? 0) + 1;
        $ultimo = array_key_last($grupo['rangos']);
        if ($ultimo !== null && $grupo['rangos'][$ultimo][1] + 1 === $folio) {
            $grupo['rangos'][$ultimo][1] = $folio;
        } else {
            $grupo['rangos'][] = [$folio, $folio];
        }
        unset($grupo);
    }
    return array_values($resumen);
}
