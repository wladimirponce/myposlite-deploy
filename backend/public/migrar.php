<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "============================================================\n";
echo "   MYPOS LITE - DIAGNOSTICO Y MIGRACIONES DE BASE DE DATOS\n";
echo "============================================================\n\n";

require __DIR__ . '/../config/database.php';

// 1. Diagnóstico de variables de entorno
$dsn = getenv('MYPOSLITE_DB_DSN');
$usr = getenv('MYPOSLITE_DB_USER');
$pwd = getenv('MYPOSLITE_DB_PASSWORD');

echo "1. Variables de entorno detectadas:\n";
echo "   MYPOSLITE_DB_DSN: " . ($dsn ?: '(NO CONFIGURADO)') . "\n";
echo "   MYPOSLITE_DB_USER: " . ($usr ?: '(NO CONFIGURADO)') . "\n";
echo "   MYPOSLITE_DB_PASSWORD: " . ($pwd !== false ? '(CONFIGURADO, longitud ' . strlen($pwd) . ')' : '(NO CONFIGURADO)') . "\n\n";

if (!$dsn || !$usr || $pwd === false) {
    echo "[ERROR] El archivo .env no contiene las variables requeridas o no existe en la raiz.\n";
    echo "Rutas buscadas para .env:\n";
    $candidatos = [
        realpath(__DIR__ . '/../../') . '/.env',
        realpath(__DIR__ . '/../') . '/.env',
        '/home/zylajdcb/lite/.env',
    ];
    foreach ($candidatos as $c) {
        echo "   - $c : " . (file_exists($c) ? "EXISTE (tamaño: " . filesize($c) . " bytes)" : "NO EXISTE") . "\n";
    }
    exit(1);
}

// 2. Probar conexión PDO
echo "2. Probando conexión a MariaDB / MySQL...\n";
try {
    $pdo = conectarBaseLite();
    echo "   [OK] Conexión establecida exitosamente con la base de datos.\n";
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    echo "   Base de datos actual: $db\n\n";
} catch (Throwable $e) {
    echo "   [ERROR DE CONEXION] " . $e->getMessage() . "\n";
    echo "   Revisa que el usuario y la contraseña en .env coincidan con cPanel.\n";
    exit(1);
}

// 3. Ejecutar migraciones
echo "3. Ejecutando migraciones de base de datos...\n";
try {
    $lock = 'myposlite:' . sha1((string) $db);
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

    // 4. Inicializar demo si está activo
    if (getenv('MYPOSLITE_LOCAL_DEMO') === '1') {
        echo "4. Inicializando datos de prueba (modo demo)...\n";
        require __DIR__ . '/../bin/crear-demo.php';
        echo "\n   [OK] Cuenta de demostración lista para ingresar.\n";
    }

    echo "\n=== PROCESO COMPLETADO EXITOSAMENTE ===\n";
} catch (Throwable $e) {
    echo "\n[ERROR EN MIGRACION] " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . " (linea " . $e->getLine() . ")\n";
    exit(1);
}
