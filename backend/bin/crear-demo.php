<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';

if (getenv('MYPOSLITE_LOCAL_DEMO') !== '1') {
    fwrite(STDERR, "La cuenta de demostración solo puede crearse en Docker local.\n");
    exit(1);
}

$db = conectarBaseLite();
$correo = 'demo@myposlite.test';
$rut = '999999999';
// MYPOSLITE_DEMO_CLAVE fija una contraseña conocida; si no se indica, se genera una al azar.
$clave = getenv('MYPOSLITE_DEMO_CLAVE') ?: bin2hex(random_bytes(9));
if (strlen($clave) < 12 || strlen($clave) > 72 || str_contains($clave, "\0")) {
    fwrite(STDERR, "La contraseña de demostración debe tener entre 12 y 72 caracteres.\n");
    exit(1);
}
$db->beginTransaction();
try {
    $insertarEmpresa = $db->prepare('INSERT INTO empresas (rut, razon_social, es_demo)
        VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
    $insertarEmpresa->execute([$rut, 'Comercio de demostración']);
    $empresa = $db->lastInsertId();
    $consulta = $db->prepare('SELECT es_demo FROM empresas WHERE id = ?');
    $consulta->execute([$empresa]);
    if ((int) $consulta->fetchColumn() !== 1) {
        throw new RuntimeException('El identificador de demostración ya pertenece a otra empresa.');
    }
    $db->prepare('INSERT INTO locales (empresa_id, codigo, nombre) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE codigo = VALUES(codigo)')->execute([$empresa, 'centro', 'Local Centro']);
    $db->prepare('INSERT INTO cajas (empresa_id, local_codigo, codigo, nombre) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE codigo = VALUES(codigo)')->execute([$empresa, 'centro', '1', 'Caja 1']);

    $consulta = $db->prepare('SELECT id FROM usuarios WHERE correo = ? FOR UPDATE');
    $consulta->execute([$correo]);
    $usuario = $consulta->fetchColumn();
    if ($usuario !== false) {
        $consulta = $db->prepare('SELECT empresa_id FROM membresias WHERE usuario_id = ?');
        $consulta->execute([$usuario]);
        $empresas = $consulta->fetchAll(PDO::FETCH_COLUMN);
        if (count($empresas) !== 1 || (string) $empresas[0] !== $empresa) {
            throw new RuntimeException('El correo de demostración ya pertenece a otro usuario.');
        }
        $db->prepare('UPDATE usuarios SET clave_hash = ? WHERE id = ?')
            ->execute([password_hash($clave, PASSWORD_DEFAULT), $usuario]);
    } else {
        $db->prepare('INSERT INTO usuarios (correo, nombre, clave_hash) VALUES (?, ?, ?)')
            ->execute([$correo, 'Administrador de demostración', password_hash($clave, PASSWORD_DEFAULT)]);
        $usuario = $db->lastInsertId();
        $db->prepare('INSERT INTO membresias (empresa_id, usuario_id, rol_codigo) VALUES (?, ?, ?)')
            ->execute([$empresa, $usuario, 'administrador']);
    }
    $db->commit();
    echo json_encode(['correo' => $correo, 'clave' => $clave], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    $db->rollBack();
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
