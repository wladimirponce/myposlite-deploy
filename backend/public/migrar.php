<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "============================================================\n";
echo "   MYPOS LITE - DIAGNOSTICO Y MIGRACIONES DE BASE DE DATOS\n";
echo "============================================================\n\n";

require __DIR__ . '/../config/database.php';

$envUser = getenv('MYPOSLITE_DB_USER') ?: 'zylajdcb_mypos';
$envPass = getenv('MYPOSLITE_DB_PASSWORD') ?: 'z7MS(7eWy7.8pB';
$dbName  = 'zylajdcb_lite';

$candidatos = [
    // 1. Lo que viene en .env
    ['host' => 'localhost', 'user' => $envUser, 'pass' => $envPass],
    ['host' => '127.0.0.1', 'user' => $envUser, 'pass' => $envPass],
    // 2. Probar con zylajdcb_mypos y z7MS(7eWy7.8pB
    ['host' => 'localhost', 'user' => 'zylajdcb_mypos', 'pass' => 'z7MS(7eWy7.8pB'],
    ['host' => '127.0.0.1', 'user' => 'zylajdcb_mypos', 'pass' => 'z7MS(7eWy7.8pB'],
    // 3. Probar con zylajdcb y z7MS(7eWy7.8pB
    ['host' => 'localhost', 'user' => 'zylajdcb', 'pass' => 'z7MS(7eWy7.8pB'],
    ['host' => '127.0.0.1', 'user' => 'zylajdcb', 'pass' => 'z7MS(7eWy7.8pB'],
];

$pdo = null;
$credencialExitosa = null;

echo "1. Probando combinaciones de conexion a MySQL/MariaDB...\n";
foreach ($candidatos as $idx => $cand) {
    $dsn = "mysql:host={$cand['host']};dbname={$dbName};charset=utf8mb4";
    try {
        $p = new PDO($dsn, $cand['user'], $cand['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo = $p;
        $credencialExitosa = $cand;
        echo "   -> Intento " . ($idx + 1) . ": CONEXION EXITOSA usando host={$cand['host']}, usuario={$cand['user']}!\n";
        break;
    } catch (Throwable $e) {
        echo "   -> Intento " . ($idx + 1) . " (host={$cand['host']}, user={$cand['user']}): " . $e->getMessage() . "\n";
    }
}

if (!$pdo) {
    echo "\n[ERROR] Ninguna de las credenciales conocidas pudo autenticar.\n";
    echo "Por favor ve a cPanel -> Bases de datos MySQL -> Usuarios actuales,\n";
    echo "haz clic en 'Cambiar contraseña' junto a 'zylajdcb_mypos',\n";
    echo "establece una clave conocida y ponla en tu archivo .env.\n";
    exit(1);
}

// Actualizar .env automáticamente con la credencial que funcionó si es posible
$rutaEnv = '/home/zylajdcb/lite/.env';
if (file_exists($rutaEnv) && is_writable($rutaEnv)) {
    $cEnv = file_get_contents($rutaEnv);
    $cEnv = preg_replace('/MYPOSLITE_DB_USER=.*/', 'MYPOSLITE_DB_USER=' . $credencialExitosa['user'], $cEnv);
    $cEnv = preg_replace('/MYPOSLITE_DB_PASSWORD=.*/', 'MYPOSLITE_DB_PASSWORD=' . $credencialExitosa['pass'], $cEnv);
    $cEnv = preg_replace('/host=[^;]+/', 'host=' . $credencialExitosa['host'], $cEnv);
    file_put_contents($rutaEnv, $cEnv);
    echo "   [OK] Archivo .env actualizado automaticamente con las credenciales que funcionaron.\n\n";
}

// 2. Ejecutar las 34 migraciones
echo "2. Ejecutando migraciones de base de datos...\n";
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

    echo "\n   [OK] Migraciones completadas. $aplicadas aplicadas, $yaEstaban ya registradas.\n\n";

    // 3. Inicializar demo
    echo "3. Inicializando datos de prueba (modo demo)...\n";
    putenv('MYPOSLITE_LOCAL_DEMO=1');
    putenv('MYPOSLITE_DB_DSN=mysql:host=' . $credencialExitosa['host'] . ';dbname=' . $dbName . ';charset=utf8mb4');
    putenv('MYPOSLITE_DB_USER=' . $credencialExitosa['user']);
    putenv('MYPOSLITE_DB_PASSWORD=' . $credencialExitosa['pass']);

    require __DIR__ . '/../bin/crear-demo.php';
    echo "\n   [OK] Cuenta de demostración lista para ingresar.\n";

    echo "\n=== PROCESO COMPLETADO EXITOSAMENTE ===\n";
    echo "Accede a: https://lite.mypos.cl\n";
    echo "Usuario demo: demo@myposlite.test\n";
} catch (Throwable $e) {
    echo "\n[ERROR EN MIGRACION] " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . " (linea " . $e->getLine() . ")\n";
    exit(1);
}
