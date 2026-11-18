<?php

declare(strict_types=1);

require_once __DIR__ . '/Emision.php';

/**
 * Ayuda en línea del expediente tributario: el cliente pide ayuda desde un paso y la solicitud lleva una foto del
 * expediente sin secretos (el mismo estado que muestra el panel, que nunca trae certificados, claves ni CAF).
 * Soporte responde y ve el expediente vigente de la empresa mientras tenga una solicitud sin cerrar.
 */
const TRAMITES_SOPORTE = ['certificacion_boletas', 'etapa_2', 'cambio_proveedor', 'emision'];

function pedirAyudaSoporte(PDO $db, string $empresa, string $usuario, array $input): array
{
    $tramite = $input['tramite'] ?? null;
    $paso = is_string($input['paso'] ?? null) ? trim($input['paso']) : '';
    $mensaje = is_string($input['mensaje'] ?? null) ? trim($input['mensaje']) : '';
    if (!in_array($tramite, TRAMITES_SOPORTE, true) || $paso === '' || mb_strlen($paso) > 80) {
        throw new InvalidArgumentException('Indique el trámite y el paso en que necesita ayuda.');
    }
    if ($mensaje === '' || mb_strlen($mensaje) > 2000) {
        throw new InvalidArgumentException('Cuéntenos qué necesita, en hasta 2.000 caracteres.');
    }
    $abiertas = $db->prepare("SELECT COUNT(*) FROM soporte_solicitudes WHERE empresa_id = ? AND estado = 'abierta'");
    $abiertas->execute([$empresa]);
    if ((int) $abiertas->fetchColumn() >= 10) {
        throw new RuntimeException('Hay 10 solicitudes sin responder: soporte las verá antes de recibir más.', 409);
    }
    $empresaDatos = $db->prepare('SELECT razon_social FROM empresas WHERE id = ?');
    $empresaDatos->execute([$empresa]);
    $contexto = ['empresa' => ['razonSocial' => $empresaDatos->fetchColumn(), 'rut' => rutEmpresaDte($db, $empresa)],
        'tramite' => $tramite, 'paso' => $paso, 'expediente' => estadoDte($db, $empresa)];
    $db->prepare('INSERT INTO soporte_solicitudes (empresa_id, tramite, paso, mensaje, contexto, usuario_id) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$empresa, $tramite, $paso, $mensaje, json_encode($contexto, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $usuario]);
    $id = (string) $db->lastInsertId();
    // Aviso al equipo de soporte, sin el expediente: lo ven en la bandeja.
    $equipo = $db->query('SELECT correo FROM usuarios WHERE soporte AND activo')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($equipo as $correo) {
        encolarCorreo($db, $empresa, 'soporte', $correo, 'Pedido de ayuda de ' . $contexto['empresa']['razonSocial'],
            "Trámite: $tramite\nPaso: $paso\n\n$mensaje\n\nResponda desde la bandeja «Soporte de Lite».", "soporte:pedido:$id:$correo");
    }
    return ['id' => $id, 'estado' => 'abierta'];
}

/** Solicitudes de la empresa con su respuesta, más recientes primero. */
function solicitudesEmpresaSoporte(PDO $db, string $empresa): array
{
    $query = $db->prepare('SELECT s.id, s.tramite, s.paso, s.mensaje, s.estado, s.respuesta, s.creada_en, s.respondida_en, u.nombre AS autor
        FROM soporte_solicitudes s JOIN usuarios u ON u.id = s.usuario_id WHERE s.empresa_id = ? ORDER BY s.id DESC LIMIT 50');
    $query->execute([$empresa]);
    return array_map(static fn (array $f): array => ['id' => (string) $f['id']] + $f, $query->fetchAll(PDO::FETCH_ASSOC));
}

function esSoporte(PDO $db, string $usuario): bool
{
    $query = $db->prepare('SELECT soporte FROM usuarios WHERE id = ? AND activo');
    $query->execute([$usuario]);
    return (bool) $query->fetchColumn();
}

/** Bandeja de soporte: solicitudes de todas las empresas, primero las abiertas. */
function bandejaSoporte(PDO $db): array
{
    $query = $db->query("SELECT s.id, s.empresa_id, e.razon_social, s.tramite, s.paso, s.mensaje, s.contexto, s.estado, s.respuesta, s.creada_en,
            s.respondida_en, u.nombre AS autor, u.correo
        FROM soporte_solicitudes s JOIN empresas e ON e.id = s.empresa_id JOIN usuarios u ON u.id = s.usuario_id
        ORDER BY s.estado = 'abierta' DESC, s.id DESC LIMIT 100");
    return array_map(static fn (array $f): array => ['id' => (string) $f['id'], 'empresaId' => (string) $f['empresa_id'],
        'contexto' => json_decode($f['contexto'], true)] + array_diff_key($f, ['contexto' => true, 'empresa_id' => true]), $query->fetchAll(PDO::FETCH_ASSOC));
}

function responderSoporte(PDO $db, string $usuario, array $input): array
{
    $id = $input['id'] ?? null;
    $respuesta = is_string($input['respuesta'] ?? null) ? trim($input['respuesta']) : '';
    $cerrar = ($input['cerrar'] ?? false) === true;
    if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id) || $respuesta === '' || mb_strlen($respuesta) > 2000) {
        throw new InvalidArgumentException('Escriba la respuesta, en hasta 2.000 caracteres.');
    }
    $cambio = $db->prepare("UPDATE soporte_solicitudes SET respuesta = ?, estado = ?, respondida_por = ?, respondida_en = CURRENT_TIMESTAMP(6)
        WHERE id = ? AND estado <> 'cerrada'");
    $cambio->execute([$respuesta, $cerrar ? 'cerrada' : 'respondida', $usuario, $id]);
    if ($cambio->rowCount() === 0) {
        throw new RuntimeException('La solicitud no existe o ya está cerrada.', 404);
    }
    // Aviso a quien pidió la ayuda, con la respuesta; una vez por respuesta.
    $quien = $db->prepare('SELECT s.empresa_id, s.paso, u.correo, (SELECT COUNT(*) FROM correo_envios c WHERE c.empresa_id = s.empresa_id
            AND c.clave LIKE CONCAT(\'soporte:respuesta:\', s.id, \':%\')) AS previas
        FROM soporte_solicitudes s JOIN usuarios u ON u.id = s.usuario_id WHERE s.id = ?');
    $quien->execute([$id]);
    if ($fila = $quien->fetch(PDO::FETCH_ASSOC)) {
        encolarCorreo($db, (string) $fila['empresa_id'], 'soporte', $fila['correo'], 'Soporte respondió su pedido de ayuda',
            "Paso: {$fila['paso']}\n\n$respuesta\n\nPuede ver el pedido en Lite, en Documentos tributarios electrónicos.",
            'soporte:respuesta:' . $id . ':' . ((int) $fila['previas'] + 1));
    }
    return ['id' => $id, 'estado' => $cerrar ? 'cerrada' : 'respondida'];
}

/** Expediente vigente de una empresa, solo mientras tenga una solicitud sin cerrar. */
function expedienteSoporte(PDO $db, string $empresa): array
{
    $query = $db->prepare("SELECT 1 FROM soporte_solicitudes WHERE empresa_id = ? AND estado <> 'cerrada' LIMIT 1");
    $query->execute([$empresa]);
    if ($query->fetchColumn() === false) {
        throw new RuntimeException('Esa empresa no tiene solicitudes de ayuda abiertas.', 404);
    }
    return estadoDte($db, $empresa);
}
