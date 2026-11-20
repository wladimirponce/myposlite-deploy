<?php

declare(strict_types=1);

require_once __DIR__ . '/CierreDiario.php';
require_once __DIR__ . '/Rut.php';

const MEDIOS_CUENTA_CORRIENTE = ['fiado', 'efectivo', 'tarjeta', 'transferencia'];

/**
 * Lista todas las cuentas corrientes (fiado) de la empresa junto con resumen global
 * y listado de clientes disponibles para abrir cuenta.
 *
 * @return array<string, mixed>
 */
function listarCuentasCorrientes(PDO $db, string $empresa): array
{
    $zona = zonaCierre($db, $empresa);
    $utc = new DateTimeZone('UTC');

    $stmt = $db->prepare('SELECT cc.id, cc.contacto_id, c.rut, c.razon_social, c.correo,
            cc.limite_credito, cc.saldo, cc.ultima_operacion_en
        FROM cuentas_corrientes cc
        JOIN contactos c ON c.empresa_id = cc.empresa_id AND c.id = cc.contacto_id
        WHERE cc.empresa_id = ?
        ORDER BY cc.saldo DESC, c.razon_social ASC');
    $stmt->execute([$empresa]);

    $totalPorCobrar = 0;
    $conDeuda = 0;
    $cuentas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $saldo = (int) $r['saldo'];
        if ($saldo > 0) {
            $totalPorCobrar += $saldo;
            $conDeuda++;
        }
        $ultima = null;
        if ($r['ultima_operacion_en'] !== null) {
            $ultima = (new DateTimeImmutable((string) $r['ultima_operacion_en'], $utc))->setTimezone($zona)->format('d-m-Y H:i');
        }
        $cuentas[] = [
            'id' => (string) $r['id'],
            'contactoId' => (string) $r['contacto_id'],
            'rut' => (string) $r['rut'],
            'nombre' => (string) $r['razon_social'],
            'correo' => $r['correo'] !== null ? (string) $r['correo'] : null,
            'limiteCredito' => $r['limite_credito'] !== null ? (int) $r['limite_credito'] : null,
            'saldo' => $saldo,
            'ultimaOperacion' => $ultima,
        ];
    }

    $stmtCli = $db->prepare('SELECT id, rut, razon_social, correo FROM contactos
        WHERE empresa_id = ? AND receptor = 1
        ORDER BY razon_social ASC
        LIMIT 300');
    $stmtCli->execute([$empresa]);
    $clientes = array_map(static fn (array $c): array => [
        'id' => (string) $c['id'],
        'rut' => (string) $c['rut'],
        'nombre' => (string) $c['razon_social'],
        'correo' => $c['correo'] !== null ? (string) $c['correo'] : null,
    ], $stmtCli->fetchAll(PDO::FETCH_ASSOC));

    return [
        'resumen' => [
            'totalPorCobrar' => $totalPorCobrar,
            'clientesConDeuda' => $conDeuda,
            'totalCuentas' => count($cuentas),
        ],
        'cuentas' => $cuentas,
        'clientes' => $clientes,
    ];
}

/**
 * Obtiene el historial de movimientos (cargos y abonos) de una cuenta corriente específica.
 *
 * @return array<string, mixed>
 */
function historialCuentaCorriente(PDO $db, string $empresa, string $cuentaId): array
{
    if (!preg_match('/^[1-9][0-9]{0,19}$/D', $cuentaId)) {
        throw new InvalidArgumentException('Identificador de cuenta inválido.');
    }
    $zona = zonaCierre($db, $empresa);
    $utc = new DateTimeZone('UTC');

    $stmt = $db->prepare('SELECT m.id, m.tipo, m.monto, m.medio, m.descripcion, m.fecha_vencimiento,
            m.saldo_despues, m.local_codigo, m.creado_en, u.nombre AS usuario
        FROM cuenta_corriente_movimientos m
        JOIN usuarios u ON u.id = m.usuario_id
        WHERE m.empresa_id = ? AND m.cuenta_id = ?
        ORDER BY m.id DESC
        LIMIT 100');
    $stmt->execute([$empresa, $cuentaId]);

    $movimientos = array_map(static function (array $r) use ($utc, $zona): array {
        $dt = (new DateTimeImmutable((string) $r['creado_en'], $utc))->setTimezone($zona);
        return [
            'id' => (string) $r['id'],
            'tipo' => (string) $r['tipo'],
            'monto' => (int) $r['monto'],
            'medio' => (string) $r['medio'],
            'descripcion' => (string) $r['descripcion'],
            'fechaVencimiento' => $r['fecha_vencimiento'] !== null ? (string) $r['fecha_vencimiento'] : null,
            'saldoDespues' => (int) $r['saldo_despues'],
            'local' => (string) $r['local_codigo'],
            'fecha' => $dt->format('d-m-Y H:i'),
            'usuario' => (string) $r['usuario'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    return ['cuentaId' => $cuentaId, 'movimientos' => $movimientos];
}

/**
 * Registra un cargo (fiado) o abono (pago) en la cuenta corriente de un cliente.
 * Si el cliente aún no tiene cuenta corriente, la crea automáticamente.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function registrarMovimientoCuentaCorriente(PDO $db, string $empresa, string $local, string $usuario, array $input): array
{
    $contactoId = is_scalar($input['contactoId'] ?? null) ? trim((string) $input['contactoId']) : '';
    if (!preg_match('/^[1-9][0-9]{0,19}$/D', $contactoId)) {
        throw new InvalidArgumentException('Seleccione un cliente válido.');
    }

    $tipo = is_string($input['tipo'] ?? null) ? trim($input['tipo']) : '';
    if (!in_array($tipo, ['cargo', 'abono'], true)) {
        throw new InvalidArgumentException('Tipo de movimiento inválido (use cargo o abono).');
    }

    $montoRaw = $input['monto'] ?? null;
    if (!is_numeric($montoRaw) || (int) $montoRaw <= 0 || (int) $montoRaw > 999999999999) {
        throw new InvalidArgumentException('Ingrese un monto entero mayor que cero.');
    }
    $monto = (int) $montoRaw;

    $medio = is_string($input['medio'] ?? null) ? trim($input['medio']) : ($tipo === 'cargo' ? 'fiado' : 'efectivo');
    if (!in_array($medio, MEDIOS_CUENTA_CORRIENTE, true)) {
        throw new InvalidArgumentException('Medio inválido.');
    }

    $descripcion = is_string($input['descripcion'] ?? null) ? trim($input['descripcion']) : '';
    if ($descripcion === '') {
        $descripcion = $tipo === 'cargo' ? 'Compra fiada en cuenta corriente' : 'Abono a cuenta corriente';
    }
    $descripcion = mb_substr($descripcion, 0, 240);

    $vencimiento = is_string($input['fechaVencimiento'] ?? null) ? trim($input['fechaVencimiento']) : '';
    if ($vencimiento === '') {
        $vencimiento = null;
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vencimiento)) {
        throw new InvalidArgumentException('Fecha de compromiso de pago inválida.');
    }

    $db->beginTransaction();
    try {
        // Verificar contacto pertenece a la empresa
        $chk = $db->prepare('SELECT id FROM contactos WHERE empresa_id = ? AND id = ?');
        $chk->execute([$empresa, $contactoId]);
        if ($chk->fetchColumn() === false) {
            throw new RuntimeException('El cliente seleccionado no existe en esta empresa.', 404);
        }

        // Asegurar fila de cuenta corriente y bloquearla
        $db->prepare('INSERT IGNORE INTO cuentas_corrientes (empresa_id, contacto_id, saldo) VALUES (?, ?, 0)')
            ->execute([$empresa, $contactoId]);

        $sel = $db->prepare('SELECT id, saldo, limite_credito FROM cuentas_corrientes WHERE empresa_id = ? AND contacto_id = ? FOR UPDATE');
        $sel->execute([$empresa, $contactoId]);
        $cuenta = $sel->fetch(PDO::FETCH_ASSOC);

        $saldoActual = (int) $cuenta['saldo'];
        $limite = $cuenta['limite_credito'] !== null ? (int) $cuenta['limite_credito'] : null;
        $nuevoSaldo = $tipo === 'cargo' ? $saldoActual + $monto : $saldoActual - $monto;

        if ($tipo === 'cargo' && $limite !== null && $nuevoSaldo > $limite) {
            throw new RuntimeException(
                'El cargo excede el límite de crédito de $' . number_format($limite, 0, ',', '.') . ' del cliente.',
                409
            );
        }

        $db->prepare('UPDATE cuentas_corrientes SET saldo = ?, ultima_operacion_en = CURRENT_TIMESTAMP(6) WHERE empresa_id = ? AND id = ?')
            ->execute([$nuevoSaldo, $empresa, $cuenta['id']]);

        $db->prepare('INSERT INTO cuenta_corriente_movimientos
            (empresa_id, cuenta_id, local_codigo, tipo, monto, medio, descripcion, fecha_vencimiento, saldo_despues, usuario_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$empresa, $cuenta['id'], $local, $tipo, $monto, $medio, $descripcion, $vencimiento, $nuevoSaldo, $usuario]);

        $movId = (string) $db->lastInsertId();
        $db->commit();

        return [
            'id' => $movId,
            'cuentaId' => (string) $cuenta['id'],
            'saldo' => $nuevoSaldo,
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Crea un cliente rápido en `contactos` y abre su cuenta corriente en un solo paso.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function crearClienteCuentaCorriente(PDO $db, string $empresa, string $usuario, array $input): array
{
    $nombre = is_string($input['nombre'] ?? null) ? trim($input['nombre']) : '';
    if ($nombre === '' || mb_strlen($nombre) > 160) {
        throw new InvalidArgumentException('Ingrese el nombre del cliente.');
    }

    $rutRaw = is_string($input['rut'] ?? null) ? trim($input['rut']) : '';
    $rutLimpio = canonizarRut($rutRaw);

    $correo = is_string($input['correo'] ?? null) ? trim($input['correo']) : '';
    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('El correo ingresado no es válido.');
    }
    $correo = $correo === '' ? null : mb_substr($correo, 0, 254);

    $limiteRaw = $input['limiteCredito'] ?? null;
    $limite = null;
    if ($limiteRaw !== null && $limiteRaw !== '') {
        if (!is_numeric($limiteRaw) || (int) $limiteRaw < 0) {
            throw new InvalidArgumentException('El límite de crédito debe ser un monto entero positivo.');
        }
        $limite = (int) $limiteRaw;
    }

    $db->beginTransaction();
    try {
        $existe = $db->prepare('SELECT id FROM contactos WHERE empresa_id = ? AND rut = ?');
        $existe->execute([$empresa, $rutLimpio]);
        $contactoId = $existe->fetchColumn();

        if ($contactoId === false) {
            $db->prepare('INSERT INTO contactos (empresa_id, rut, razon_social, correo, proveedor, receptor) VALUES (?, ?, ?, ?, 0, 1)')
                ->execute([$empresa, $rutLimpio, $nombre, $correo]);
            $contactoId = (string) $db->lastInsertId();
            $db->prepare('INSERT INTO contacto_eventos (empresa_id, contacto_id, usuario_id, accion) VALUES (?, ?, ?, ?)')
                ->execute([$empresa, $contactoId, $usuario, 'crear']);
        } else {
            $contactoId = (string) $contactoId;
            $db->prepare('UPDATE contactos SET receptor = 1 WHERE empresa_id = ? AND id = ?')
                ->execute([$empresa, $contactoId]);
        }

        $db->prepare('INSERT INTO cuentas_corrientes (empresa_id, contacto_id, limite_credito, saldo)
            VALUES (?, ?, ?, 0)
            ON DUPLICATE KEY UPDATE limite_credito = VALUES(limite_credito)')
            ->execute([$empresa, $contactoId, $limite]);

        $db->commit();
        return ['contactoId' => $contactoId, 'rut' => $rutLimpio, 'nombre' => $nombre];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function actualizarLimiteCuentaCorriente(PDO $db, string $empresa, string $cuentaId, mixed $limiteRaw): array
{
    if (!preg_match('/^[1-9][0-9]{0,19}$/D', $cuentaId)) {
        throw new InvalidArgumentException('Identificador de cuenta inválido.');
    }
    $limite = null;
    if ($limiteRaw !== null && $limiteRaw !== '') {
        if (!is_numeric($limiteRaw) || (int) $limiteRaw < 0) {
            throw new InvalidArgumentException('El límite de crédito debe ser un número entero mayor o igual a cero.');
        }
        $limite = (int) $limiteRaw;
    }
    $stmt = $db->prepare('UPDATE cuentas_corrientes SET limite_credito = ? WHERE empresa_id = ? AND id = ?');
    $stmt->execute([$limite, $empresa, $cuentaId]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('La cuenta corriente no existe.', 404);
    }
    return ['cuentaId' => $cuentaId, 'limiteCredito' => $limite];
}
