<?php

declare(strict_types=1);

require_once __DIR__ . '/CierreDiario.php';

const CATEGORIAS_GASTO = [
    'arriendo' => 'Arriendo',
    'servicios_basicos' => 'Luz / Agua / Internet',
    'sueldos' => 'Sueldos y Leyes Sociales',
    'honorarios' => 'Boletas de Honorarios',
    'impuestos' => 'Impuestos / Patentes',
    'mantencion' => 'Mantención y Reparaciones',
    'publicidad' => 'Publicidad y Marketing',
    'transporte' => 'Transporte y Fletes',
    'otros' => 'Otros Gastos Fijos',
];

const MEDIOS_GASTO = ['efectivo', 'transferencia', 'tarjeta', 'debito_automatico', 'cheque'];

/**
 * Lista los gastos operacionales de un local en el rango indicado y el resumen por categoría.
 *
 * @return array<string, mixed>
 */
function listarGastosOperacionales(PDO $db, string $empresa, string $local, ?string $desdeParam, ?string $hastaParam, ?string $categoria): array
{
    $zona = zonaCierre($db, $empresa);
    $hoy = new DateTimeImmutable('today', $zona);
    $hasta = $hastaParam && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hastaParam) ? $hastaParam : $hoy->format('Y-m-d');
    $desde = $desdeParam && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desdeParam) ? $desdeParam : $hoy->modify('first day of this month')->format('Y-m-d');

    $sql = 'SELECT g.id, g.fecha, g.categoria, g.descripcion, g.documento_ref, g.proveedor_nombre, g.medio, g.monto,
            u.nombre AS usuario, g.creado_en
        FROM gastos_operacionales g
        JOIN usuarios u ON u.id = g.usuario_id
        WHERE g.empresa_id = ? AND g.local_codigo = ? AND g.fecha >= ? AND g.fecha <= ?';
    $params = [$empresa, $local, $desde, $hasta];

    if ($categoria !== null && $categoria !== '' && isset(CATEGORIAS_GASTO[$categoria])) {
        $sql .= ' AND g.categoria = ?';
        $params[] = $categoria;
    }

    $sql .= ' ORDER BY g.fecha DESC, g.id DESC LIMIT 200';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $items = array_map(static fn (array $r): array => [
        'id' => (string) $r['id'],
        'fecha' => (string) $r['fecha'],
        'categoria' => (string) $r['categoria'],
        'categoriaNombre' => CATEGORIAS_GASTO[(string) $r['categoria']] ?? (string) $r['categoria'],
        'descripcion' => (string) $r['descripcion'],
        'documentoRef' => $r['documento_ref'] !== null ? (string) $r['documento_ref'] : null,
        'proveedorNombre' => $r['proveedor_nombre'] !== null ? (string) $r['proveedor_nombre'] : null,
        'medio' => (string) $r['medio'],
        'monto' => (int) $r['monto'],
        'usuario' => (string) $r['usuario'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmtRes = $db->prepare('SELECT categoria, COUNT(*) AS cantidad, COALESCE(SUM(monto), 0) AS total
        FROM gastos_operacionales
        WHERE empresa_id = ? AND local_codigo = ? AND fecha >= ? AND fecha <= ?
        GROUP BY categoria
        ORDER BY total DESC');
    $stmtRes->execute([$empresa, $local, $desde, $hasta]);
    $totalPeriodo = 0;
    $porCategoria = [];
    foreach ($stmtRes->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $tot = (int) $c['total'];
        $totalPeriodo += $tot;
        $porCategoria[] = [
            'categoria' => (string) $c['categoria'],
            'nombre' => CATEGORIAS_GASTO[(string) $c['categoria']] ?? (string) $c['categoria'],
            'cantidad' => (int) $c['cantidad'],
            'total' => $tot,
        ];
    }

    return [
        'desde' => $desde,
        'hasta' => $hasta,
        'hoy' => $hoy->format('Y-m-d'),
        'total' => $totalPeriodo,
        'cantidad' => count($items),
        'porCategoria' => $porCategoria,
        'gastos' => $items,
    ];
}

/**
 * Registra un nuevo gasto operacional fuera de caja.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function registrarGastoOperacional(PDO $db, string $empresa, string $local, string $usuario, array $input): array
{
    $zona = zonaCierre($db, $empresa);
    $fecha = is_string($input['fecha'] ?? null) ? trim($input['fecha']) : (new DateTimeImmutable('today', $zona))->format('Y-m-d');
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, $zona);
    if (!$dt || $dt->format('Y-m-d') !== $fecha) {
        throw new InvalidArgumentException('Ingrese una fecha válida (AAAA-MM-DD).');
    }

    $categoria = is_string($input['categoria'] ?? null) ? trim($input['categoria']) : 'otros';
    if (!isset(CATEGORIAS_GASTO[$categoria])) {
        throw new InvalidArgumentException('Categoría de gasto inválida.');
    }

    $descripcion = is_string($input['descripcion'] ?? null) ? trim($input['descripcion']) : '';
    if ($descripcion === '' || mb_strlen($descripcion) > 200) {
        throw new InvalidArgumentException('Ingrese una descripción del gasto (máximo 200 caracteres).');
    }

    $montoRaw = $input['monto'] ?? null;
    if (!is_numeric($montoRaw) || (int) $montoRaw <= 0 || (int) $montoRaw > 999999999999) {
        throw new InvalidArgumentException('Ingrese un monto entero mayor que cero.');
    }
    $monto = (int) $montoRaw;

    $medio = is_string($input['medio'] ?? null) ? trim($input['medio']) : 'transferencia';
    if (!in_array($medio, MEDIOS_GASTO, true)) {
        throw new InvalidArgumentException('Medio de pago inválido.');
    }

    $docRef = is_string($input['documentoRef'] ?? null) ? trim($input['documentoRef']) : '';
    $docRef = $docRef === '' ? null : mb_substr($docRef, 0, 80);

    $prov = is_string($input['proveedorNombre'] ?? null) ? trim($input['proveedorNombre']) : '';
    $prov = $prov === '' ? null : mb_substr($prov, 0, 140);

    $stmt = $db->prepare('INSERT INTO gastos_operacionales
        (empresa_id, local_codigo, fecha, categoria, descripcion, documento_ref, proveedor_nombre, medio, monto, usuario_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$empresa, $local, $fecha, $categoria, $descripcion, $docRef, $prov, $medio, $monto, $usuario]);

    return [
        'id' => (string) $db->lastInsertId(),
        'fecha' => $fecha,
        'categoria' => $categoria,
        'monto' => $monto,
    ];
}

function eliminarGastoOperacional(PDO $db, string $empresa, string $local, string $id): void
{
    if (!preg_match('/^[1-9][0-9]{0,19}$/D', $id)) {
        throw new InvalidArgumentException('Identificador de gasto inválido.');
    }
    $stmt = $db->prepare('DELETE FROM gastos_operacionales WHERE empresa_id = ? AND local_codigo = ? AND id = ?');
    $stmt->execute([$empresa, $local, $id]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('El gasto no existe en este local.', 404);
    }
}
