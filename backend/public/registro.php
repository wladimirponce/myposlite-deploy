<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/SembradorCatalogo.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responder(405, ['error' => 'Método no disponible.']);
}

if (($_SERVER['HTTP_X_MYPOSLITE_REQUEST'] ?? '') !== '1') {
    responder(403, ['error' => 'Solicitud no autorizada.']);
}

try {
    $raw = file_get_contents('php://input', false, null, 0, 8192);
    $input = json_decode((string) $raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        responder(400, ['error' => 'Datos inválidos.']);
    }

    $nombreUsuario = trim((string) ($input['nombre'] ?? ''));
    $correo = strtolower(trim((string) ($input['correo'] ?? '')));
    $clave = (string) ($input['clave'] ?? '');
    $razonSocial = trim((string) ($input['razon_social'] ?? ''));
    $rut = strtoupper(preg_replace('/[^0-9kK]/', '', (string) ($input['rut'] ?? '')) ?: '');
    $rubro = strtolower(trim((string) ($input['rubro'] ?? 'otro')));

    if ($nombreUsuario === '' || strlen($nombreUsuario) < 2 || strlen($nombreUsuario) > 120) {
        responder(400, ['error' => 'Ingresa tu nombre (entre 2 y 120 caracteres).']);
    }
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || strlen($correo) > 254) {
        responder(400, ['error' => 'Ingresa un correo electrónico válido.']);
    }
    if (strlen($clave) < 8 || strlen($clave) > 72) {
        responder(400, ['error' => 'La contraseña debe tener al menos 8 caracteres.']);
    }
    if ($razonSocial === '' || strlen($razonSocial) < 2 || strlen($razonSocial) > 160) {
        responder(400, ['error' => 'Ingresa el nombre o razón social de tu negocio.']);
    }
    if ($rut === '' || strlen($rut) < 8 || strlen($rut) > 9) {
        $rut = '76' . substr((string) time(), -6) . 'K';
    }
    if (!in_array($rubro, ['minimarket', 'farmacia', 'otro'], true)) {
        $rubro = 'otro';
    }

    $db = conectarBaseLite();

    // Verificar si el correo ya existe
    $chk = $db->prepare('SELECT id FROM usuarios WHERE correo = ?');
    $chk->execute([$correo]);
    if ($chk->fetchColumn() !== false) {
        responder(409, ['error' => 'Este correo ya se encuentra registrado. Por favor inicia sesión.']);
    }

    $db->beginTransaction();

    // 1. Crear empresa
    $stmtEmp = $db->prepare('INSERT INTO empresas (rut, razon_social, rubro, activa, es_demo) 
        VALUES (?, ?, ?, 1, 0)');
    $stmtEmp->execute([$rut, $razonSocial, $rubro]);
    $empresaId = (int) $db->lastInsertId();

    // 2. Crear local inicial y caja inicial
    $db->prepare('INSERT INTO locales (empresa_id, codigo, nombre) VALUES (?, ?, ?)')
        ->execute([$empresaId, 'casa-matriz', 'Local Principal']);
    $db->prepare('INSERT INTO cajas (empresa_id, local_codigo, codigo, nombre) VALUES (?, ?, ?, ?)')
        ->execute([$empresaId, 'casa-matriz', '1', 'Caja 1']);

    // 3. Crear usuario
    $claveHash = password_hash($clave, PASSWORD_DEFAULT);
    $stmtUsr = $db->prepare('INSERT INTO usuarios (correo, nombre, clave_hash, activo) VALUES (?, ?, ?, 1)');
    $stmtUsr->execute([$correo, $nombreUsuario, $claveHash]);
    $usuarioId = (int) $db->lastInsertId();

    // 4. Asignar rol de administrador
    $db->prepare('INSERT INTO membresias (empresa_id, usuario_id, rol_codigo, activa) VALUES (?, ?, ?, 1)')
        ->execute([$empresaId, $usuarioId, 'administrador']);

    // 5. Sembrar catálogo según rubro
    $prodsSembrados = 0;
    if ($rubro === 'farmacia' || $rubro === 'minimarket') {
        $prodsSembrados = SembradorCatalogo::sembrar($db, $empresaId, $rubro);
    }

    $db->commit();

    // 6. Iniciar sesión automáticamente
    if (iniciarSesionLite()) {
        $_SESSION['usuario_id'] = (string) $usuarioId;
        $_SESSION['actividad'] = time();
    }

    responder(201, [
        'ok' => true,
        'empresa_id' => $empresaId,
        'rubro' => $rubro,
        'productos_sembrados' => $prodsSembrados,
        'mensaje' => 'Comercio registrado con éxito.',
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Registro MyPOS Lite error: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo completar el registro: ' . $e->getMessage()]);
}
