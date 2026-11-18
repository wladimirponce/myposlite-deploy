<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "============================================================\n";
echo "   MYPOS LITE - DIAGNOSTICO Y MIGRACIONES DE BASE DE DATOS\n";
echo "============================================================\n\n";

require_once __DIR__ . '/../config/database.php';

$envUser = getenv('MYPOSLITE_DB_USER') ?: 'zylajdcb';
$envPass = getenv('MYPOSLITE_DB_PASSWORD') ?: 'z7MS(7eWy7.8pB';
$dbName  = 'zylajdcb_lite';

$candidatos = [
    ['host' => 'localhost', 'user' => 'zylajdcb', 'pass' => 'z7MS(7eWy7.8pB'],
    ['host' => 'localhost', 'user' => $envUser, 'pass' => $envPass],
    ['host' => '127.0.0.1', 'user' => 'zylajdcb', 'pass' => 'z7MS(7eWy7.8pB'],
    ['host' => '127.0.0.1', 'user' => $envUser, 'pass' => $envPass],
];

$pdo = null;
$credencialExitosa = null;

echo "1. Conectando a MariaDB / MySQL...\n";
foreach ($candidatos as $cand) {
    $dsn = "mysql:host={$cand['host']};dbname={$dbName};charset=utf8mb4";
    try {
        $p = new PDO($dsn, $cand['user'], $cand['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo = $p;
        $credencialExitosa = $cand;
        echo "   [OK] Conexión establecida usando usuario {$cand['user']} en {$cand['host']}.\n\n";
        break;
    } catch (Throwable $e) {
        // continuar
    }
}

if (!$pdo) {
    echo "[ERROR] No se pudo conectar a la base de datos con las credenciales.\n";
    exit(1);
}

// 2. Ejecutar las 34 migraciones
echo "2. Verificando y aplicando migraciones...\n";
try {
    $lock = 'myposlite:' . sha1($dbName);
    $acquire = $pdo->prepare('SELECT GET_LOCK(?, 15)');
    $acquire->execute([$lock]);

    $pdo->exec('CREATE TABLE IF NOT EXISTS lite_migraciones (
        nombre VARCHAR(190) PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        aplicada_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
    ) ENGINE=InnoDB');

    $find = $pdo->prepare('SELECT checksum FROM lite_migraciones WHERE nombre = ?');
    $record = $pdo->prepare('INSERT INTO lite_migraciones (nombre, checksum) VALUES (?, ?)');
    $paths = glob(__DIR__ . '/../../database/migrations/*.sql');
    if (!$paths) {
        throw new RuntimeException('No se encontraron archivos en database/migrations/.');
    }
    sort($paths);

    $aplicadas = 0;
    $yaEstaban = 0;

    foreach ($paths as $path) {
        $name = basename($path);
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("No se pudo leer $name.");
        }

        $sql = str_replace("\r\n", "\n", $sql);
        $checksum = hash('sha256', $sql);
        $find->execute([$name]);
        $applied = $find->fetchColumn();
        if ($applied !== false) {
            $yaEstaban++;
            continue;
        }

        foreach (preg_split('/;[ \t]*(?:\n|$)/', $sql) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
        $record->execute([$name, $checksum]);
        echo "   -> Aplicada: $name\n";
        $aplicadas++;
    }

    echo "   [OK] Migraciones al dia. $aplicadas aplicadas ahora, $yaEstaban ya registradas.\n\n";

    // 3. Inicializar empresa de demostración y usuario
    echo "3. Inicializando empresa de demostración y usuario piloto...\n";
    $correo = 'demo@myposlite.test';
    $clavePlana = 'Demo12345678';
    $rut = '999999999';

    $pdo->beginTransaction();
    
    // Empresa
    $stmt = $pdo->prepare('INSERT INTO empresas (rut, razon_social, es_demo) VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
    $stmt->execute([$rut, 'Comercio de demostración']);
    $empresa = (int) $pdo->lastInsertId();

    // Local y caja
    $pdo->prepare('INSERT INTO locales (empresa_id, codigo, nombre) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE codigo = VALUES(codigo)')->execute([$empresa, 'centro', 'Local Centro']);
    $pdo->prepare('INSERT INTO cajas (empresa_id, local_codigo, codigo, nombre) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE codigo = VALUES(codigo)')->execute([$empresa, 'centro', '1', 'Caja 1']);

    // Usuario demo
    $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE correo = ?');
    $stmt->execute([$correo]);
    $userId = $stmt->fetchColumn();
    $hash = password_hash($clavePlana, PASSWORD_DEFAULT);

    if ($userId !== false) {
        $pdo->prepare('UPDATE usuarios SET clave_hash = ? WHERE id = ?')->execute([$hash, $userId]);
    } else {
        $pdo->prepare('INSERT INTO usuarios (correo, nombre, clave_hash) VALUES (?, ?, ?)')
            ->execute([$correo, 'Administrador Demo', $hash]);
        $userId = (int) $pdo->lastInsertId();
    }

    // Membresía
    $pdo->prepare('INSERT INTO membresias (empresa_id, usuario_id, rol_codigo) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE rol_codigo = VALUES(rol_codigo)')->execute([$empresa, $userId, 'administrador']);

    $pdo->commit();

    echo "   [OK] Empresa, local 'centro', caja '1' y usuario administrador creados exitosamente.\n";

    // Actualizar .env con el usuario zylajdcb si no estaba
    $rutaEnv = '/home/zylajdcb/lite/.env';
    if (file_exists($rutaEnv) && is_writable($rutaEnv)) {
        $cEnv = file_get_contents($rutaEnv);
        $cEnv = preg_replace('/MYPOSLITE_DB_USER=.*/', 'MYPOSLITE_DB_USER=zylajdcb', $cEnv);
        $cEnv = preg_replace('/MYPOSLITE_DB_PASSWORD=.*/', 'MYPOSLITE_DB_PASSWORD=z7MS(7eWy7.8pB', $cEnv);
        file_put_contents($rutaEnv, $cEnv);
    }

    echo "\n============================================================\n";
    echo "   ¡DESPLIEGUE A PRODUCCION COMPLETADO CON EXITO!\n";
    echo "============================================================\n\n";
    echo "URL de la aplicacion: https://lite.mypos.cl\n\n";
    echo "Credenciales de acceso:\n";
    echo "   Correo:      $correo\n";
    echo "   Contraseña:  $clavePlana\n\n";
    echo "Empresa: Comercio de demostracion\n";
    echo "Local:   Local Centro\n";
    echo "Caja:    Caja 1\n";
    echo "Modo:    Demostracion / Piloto (Tickets internos, sin envio al SII)\n";
    echo "============================================================\n";

} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . " (linea " . $e->getLine() . ")\n";
    exit(1);
}
