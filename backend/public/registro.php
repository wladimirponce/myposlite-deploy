<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/SembradorCatalogo.php';
require_once __DIR__ . '/../src/Emision.php';

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
    $input = [];
    $pfxBytes = '';
    $claveCertificado = '';

    // Soporte para multipart/form-data (con subida de archivo .pfx) y application/json
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'multipart/form-data') || !empty($_POST)) {
        $input = $_POST;
        if (!empty($_FILES['certificado']['tmp_name']) && is_uploaded_file($_FILES['certificado']['tmp_name'])) {
            $pfxBytes = (string) file_get_contents($_FILES['certificado']['tmp_name']);
        }
        $claveCertificado = trim((string) ($_POST['clave_certificado'] ?? ''));
    } else {
        $raw = file_get_contents('php://input', false, null, 0, 1048576); // hasta 1MB
        $input = json_decode((string) $raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($input)) {
            responder(400, ['error' => 'Datos inválidos.']);
        }
        if (!empty($input['certificado_base64'])) {
            $pfxBytes = (string) base64_decode((string) $input['certificado_base64']);
        }
        $claveCertificado = trim((string) ($input['clave_certificado'] ?? ''));
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

    // Si viene certificado, validar la contraseña antes de crear nada en la base de datos
    $datosCertificado = null;
    if ($pfxBytes !== '') {
        if ($claveCertificado === '') {
            responder(400, ['error' => 'Si adjuntas el certificado digital, debes ingresar su contraseña.']);
        }
        if (!openssl_pkcs12_read($pfxBytes, $paqueteCert, $claveCertificado)) {
            responder(400, ['error' => 'No se pudo abrir el certificado digital. Verifica que el archivo sea .pfx o .p12 y que la contraseña sea correcta.']);
        }
        $parsed = openssl_x509_parse($paqueteCert['cert']);
        if (!is_array($parsed) || ($parsed['validTo_time_t'] ?? 0) <= time()) {
            responder(400, ['error' => 'El certificado digital está vencido.']);
        }
        $rutTitular = RutCertificado::extraer($paqueteCert['cert']);
        if (!$rutTitular) {
            responder(400, ['error' => 'El certificado digital no contiene un RUT de persona válido.']);
        }
        $datosCertificado = [
            'nombre' => (string) ($parsed['subject']['CN'] ?? $rutTitular),
            'rut' => $rutTitular,
            'validoHasta' => date('Y-m-d', $parsed['validTo_time_t']),
        ];
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

    // 5. Configurar emisores DTE base
    $stmtEmisor = $db->prepare('INSERT INTO dte_emisores 
        (empresa_id, ambiente, giro, direccion, comuna, numero_resolucion, fecha_resolucion, actualizado_por)
        VALUES (?, ?, ?, ?, ?, 0, CURDATE(), ?)
        ON DUPLICATE KEY UPDATE giro = VALUES(giro)');
    $stmtEmisor->execute([$empresaId, 'certificacion', 'Comercio al por menor', 'Casa Matriz', 'Santiago', $usuarioId]);
    $stmtEmisor->execute([$empresaId, 'produccion', 'Comercio al por menor', 'Casa Matriz', 'Santiago', $usuarioId]);

    // 6. Si se adjuntó certificado digital, guardarlo de inmediato en ambos ambientes
    $certGuardado = false;
    if ($pfxBytes !== '' && $claveCertificado !== '') {
        guardarCertificadoDte($db, (string) $empresaId, (string) $usuarioId, 'certificacion', $pfxBytes, $claveCertificado);
        guardarCertificadoDte($db, (string) $empresaId, (string) $usuarioId, 'produccion', $pfxBytes, $claveCertificado);
        $certGuardado = true;
    }

    // 7. Sembrar catálogo según rubro
    $prodsSembrados = 0;
    if ($rubro === 'farmacia' || $rubro === 'minimarket') {
        $prodsSembrados = SembradorCatalogo::sembrar($db, $empresaId, $rubro);
    }

    $db->commit();

    // 8. Iniciar sesión automáticamente
    if (iniciarSesionLite()) {
        $_SESSION['usuario_id'] = (string) $usuarioId;
        $_SESSION['actividad'] = time();
    }

    responder(201, [
        'ok' => true,
        'empresa_id' => $empresaId,
        'rubro' => $rubro,
        'productos_sembrados' => $prodsSembrados,
        'certificado_cargado' => $certGuardado,
        'titular_certificado' => $datosCertificado,
        'mensaje' => $certGuardado
            ? '¡Comercio registrado con éxito! Tu certificado digital quedó vinculado para la certificación de boletas.'
            : '¡Comercio registrado con éxito! Ya puedes comenzar a utilizar tu punto de venta.',
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Registro MyPOS Lite error: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo completar el registro: ' . $e->getMessage()]);
}
