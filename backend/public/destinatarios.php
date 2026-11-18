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
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

function destinatarios(PDO $db, string $empresa): array
{
    $query = $db->prepare('SELECT d.correo, d.verificado_en, p.correo IS NOT NULL AS principal
        FROM destinatarios_cierre d
        LEFT JOIN destinatario_cierre_principal p ON p.empresa_id = d.empresa_id AND p.correo = d.correo
        WHERE d.empresa_id = ? ORDER BY principal DESC, d.correo');
    $query->execute([$empresa]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['principal'] = (bool) $row['principal'];
    }
    unset($row);
    return ['destinatarios' => $rows, 'revision' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
}

function correoDestinatario(mixed $value): string
{
    if (!is_string($value) || strlen(trim($value)) > 254 || !filter_var(trim($value), FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Ingrese un correo válido.');
    }
    return strtolower(trim($value));
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'PUT'], true)) {
    header('Allow: GET, PUT');
    responder(405, ['error' => 'Método no disponible.']);
}
if ($method === 'PUT' && ($_SERVER['HTTP_X_MYPOSLITE_REQUEST'] ?? '') !== '1') {
    responder(403, ['error' => 'Solicitud no autorizada.']);
}

try {
    if (!iniciarSesionLite()) {
        responder(503, ['error' => 'No se pudo iniciar la sesión.']);
    }
    $usuario = idSesionLite();
    $contexto = $_SESSION['contexto'] ?? null;
    if ($usuario === null || !$contexto) {
        responder($usuario === null ? 401 : 403, ['error' => 'Inicie sesión y seleccione un local.']);
    }
    $empresa = $contexto['empresaId'];
    if (($_SERVER['HTTP_X_MYPOSLITE_EMPRESA'] ?? null) !== $empresa) {
        responder(409, ['error' => 'Cambió la empresa activa.']);
    }
    $db = conectarBaseLite();
    if (!in_array('empresa.configurar', permisosMaestros($db, $usuario, $empresa, $contexto['localCodigo']), true)) {
        responder(403, ['error' => 'No tiene permiso para configurar destinatarios.']);
    }
    $_SESSION['actividad'] = time();
    session_write_close();
    if ($method === 'GET') {
        responder(200, destinatarios($db, $empresa));
    }

    $raw = file_get_contents('php://input', false, null, 0, 32769);
    if ($raw === false || strlen($raw) > 32768) {
        responder(413, ['error' => 'Solicitud demasiado grande.']);
    }
    $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    $adicionales = $input['adicionales'] ?? null;
    $principal = $input['principal'] ?? null;
    $revision = $input['revision'] ?? null;
    if (!is_array($adicionales) || !array_is_list($adicionales) || !is_string($revision)) {
        throw new InvalidArgumentException('Revise los destinatarios.');
    }
    $principal = $principal === null || $principal === '' ? null : correoDestinatario($principal);
    $correos = array_map('correoDestinatario', $adicionales);
    if ($principal === null && $correos) {
        throw new InvalidArgumentException('Indique un correo principal.');
    }
    if ($principal !== null) {
        array_unshift($correos, $principal);
    }
    if (count(array_unique($correos)) !== count($correos)) {
        throw new InvalidArgumentException('Cada correo puede aparecer una sola vez.');
    }

    $db->beginTransaction();
    $lock = $db->prepare('SELECT id FROM empresas WHERE id = ? FOR UPDATE');
    $lock->execute([$empresa]);
    if (!in_array('empresa.configurar', permisosMaestros($db, $usuario, $empresa, $contexto['localCodigo']), true)) {
        $db->rollBack();
        responder(403, ['error' => 'Ya no tiene permiso para configurar destinatarios.']);
    }
    $actual = destinatarios($db, $empresa);
    if (!hash_equals($actual['revision'], $revision)) {
        $db->rollBack();
        responder(409, ['error' => 'La configuración cambió. Recargue los destinatarios antes de guardar.']);
    }
    $existentes = array_column($actual['destinatarios'], 'correo');
    $agregar = $db->prepare('INSERT INTO destinatarios_cierre (empresa_id, correo) VALUES (?, ?)');
    foreach (array_diff($correos, $existentes) as $correo) {
        $agregar->execute([$empresa, $correo]);
    }
    $db->prepare('DELETE FROM destinatario_cierre_principal WHERE empresa_id = ?')->execute([$empresa]);
    if ($principal !== null) {
        $db->prepare('INSERT INTO destinatario_cierre_principal (empresa_id, correo) VALUES (?, ?)')
            ->execute([$empresa, $principal]);
    }
    $eliminar = $db->prepare('DELETE FROM destinatarios_cierre WHERE empresa_id = ? AND correo = ?');
    foreach (array_diff($existentes, $correos) as $correo) {
        $eliminar->execute([$empresa, $correo]);
    }
    $resultado = destinatarios($db, $empresa);
    $db->commit();
    responder(200, $resultado);
} catch (InvalidArgumentException | JsonException $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    responder(422, ['error' => $error instanceof JsonException ? 'JSON inválido.' : $error->getMessage()]);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('MyPOS Lite destinatarios: ' . $error::class);
    responder(500, ['error' => 'No se pudo completar la solicitud.']);
}
