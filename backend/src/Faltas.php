<?php

declare(strict_types=1);

function listarFaltas(PDO $db, string $empresa, string $local, ?string $estado = null): array
{
    $sql = 'SELECT f.id, f.empresa_id, f.local_codigo, f.producto_id, f.descripcion, f.cantidad,
                   f.cliente_nombre, f.cliente_contacto, f.canal_preferido, f.observaciones,
                   f.estado, f.creado_en, f.actualizado_en,
                   p.nombre AS producto_catalogo, p.sku AS producto_sku,
                   u.nombre AS registrado_por
            FROM faltas f
            LEFT JOIN productos p ON p.empresa_id = f.empresa_id AND p.id = f.producto_id
            LEFT JOIN usuarios u ON u.id = f.creado_por
            WHERE f.empresa_id = ? AND f.local_codigo = ?';
    $params = [$empresa, $local];

    if ($estado !== null && in_array($estado, ['pendiente', 'pedido', 'recibido', 'descartado'], true)) {
        $sql .= ' AND f.estado = ?';
        $params[] = $estado;
    }

    $sql .= ' ORDER BY f.estado = "pendiente" DESC, f.creado_en DESC LIMIT 200';
    $query = $db->prepare($sql);
    $query->execute($params);
    $items = $query->fetchAll(PDO::FETCH_ASSOC);

    // Contadores rápidos por estado
    $contadoresQuery = $db->prepare('SELECT estado, COUNT(*) as cantidad FROM faltas WHERE empresa_id = ? AND local_codigo = ? GROUP BY estado');
    $contadoresQuery->execute([$empresa, $local]);
    $resumen = ['pendiente' => 0, 'pedido' => 0, 'recibido' => 0, 'descartado' => 0];
    foreach ($contadoresQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $resumen[$row['estado']] = (int) $row['cantidad'];
    }

    return [
        'faltas' => array_map(static function (array $f): array {
            return [
                'id' => (string) $f['id'],
                'local' => $f['local_codigo'],
                'productoId' => $f['producto_id'] !== null ? (string) $f['producto_id'] : null,
                'descripcion' => $f['descripcion'],
                'cantidad' => (float) $f['cantidad'],
                'clienteNombre' => $f['cliente_nombre'],
                'clienteContacto' => $f['cliente_contacto'],
                'canalPreferido' => $f['canal_preferido'],
                'observaciones' => $f['observaciones'],
                'estado' => $f['estado'],
                'creadoEn' => $f['creado_en'],
                'productoCatalogo' => $f['producto_catalogo'],
                'productoSku' => $f['producto_sku'],
                'registradoPor' => $f['registrado_por'],
            ];
        }, $items),
        'resumen' => $resumen,
    ];
}

function registrarFalta(PDO $db, string $empresa, string $local, ?string $usuarioId, array $datos): array
{
    $descripcion = trim((string) ($datos['descripcion'] ?? ''));
    if (strlen($descripcion) < 2 || strlen($descripcion) > 200) {
        throw new InvalidArgumentException('Indique el producto solicitado (entre 2 y 200 caracteres).');
    }

    $cantidad = (float) ($datos['cantidad'] ?? 1);
    if ($cantidad <= 0 || $cantidad > 99999) {
        $cantidad = 1.0;
    }

    $productoId = null;
    if (!empty($datos['productoId'])) {
        $checkProd = $db->prepare('SELECT id, nombre FROM productos WHERE empresa_id = ? AND id = ?');
        $checkProd->execute([$empresa, $datos['productoId']]);
        if ($checkProd->fetch()) {
            $productoId = (int) $datos['productoId'];
        }
    }

    $clienteNombre = trim((string) ($datos['clienteNombre'] ?? '')) ?: null;
    $clienteContacto = trim((string) ($datos['clienteContacto'] ?? '')) ?: null;
    $canal = (string) ($datos['canalPreferido'] ?? 'llamada');
    if (!in_array($canal, ['llamada', 'whatsapp', 'correo', 'sms'], true)) {
        $canal = 'llamada';
    }

    $observaciones = trim((string) ($datos['observaciones'] ?? '')) ?: null;

    $query = $db->prepare('INSERT INTO faltas 
        (empresa_id, local_codigo, producto_id, descripcion, cantidad, cliente_nombre, cliente_contacto, canal_preferido, observaciones, creado_por)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $query->execute([
        $empresa,
        $local,
        $productoId,
        $descripcion,
        $cantidad,
        $clienteNombre,
        $clienteContacto,
        $canal,
        $observaciones,
        $usuarioId ? (int) $usuarioId : null,
    ]);

    $id = (string) $db->lastInsertId();

    return [
        'id' => $id,
        'descripcion' => $descripcion,
        'cantidad' => $cantidad,
        'mensaje' => "Falta #$id registrada exitosamente para reposición.",
    ];
}

function actualizarEstadoFalta(PDO $db, string $empresa, string $id, string $nuevoEstado): array
{
    if (!in_array($nuevoEstado, ['pendiente', 'pedido', 'recibido', 'descartado'], true)) {
        throw new InvalidArgumentException('Estado inválido.');
    }

    $query = $db->prepare('UPDATE faltas SET estado = ? WHERE empresa_id = ? AND id = ?');
    $query->execute([$nuevoEstado, $empresa, $id]);

    if ($query->rowCount() === 0) {
        $check = $db->prepare('SELECT id FROM faltas WHERE empresa_id = ? AND id = ?');
        $check->execute([$empresa, $id]);
        if (!$check->fetch()) {
            throw new RuntimeException('No se encontró el registro de falta solicitado.');
        }
    }

    return [
        'id' => $id,
        'estado' => $nuevoEstado,
        'mensaje' => 'Estado actualizado a ' . $nuevoEstado . '.',
    ];
}
