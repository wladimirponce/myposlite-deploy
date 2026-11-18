<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';

try {
    $pdo = conectarBaseLite();
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!$db) {
        throw new RuntimeException('El DSN debe indicar la base exclusiva de Lite.');
    }

    $lock = 'myposlite:' . sha1((string) $db);
    $acquire = $pdo->prepare('SELECT GET_LOCK(?, 15)');
    $acquire->execute([$lock]);
    if ((int) $acquire->fetchColumn() !== 1) {
        throw new RuntimeException('Otra instancia está aplicando migraciones.');
    }

    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS lite_migraciones (
            nombre VARCHAR(190) PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            aplicada_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ) ENGINE=InnoDB');
        $find = $pdo->prepare('SELECT checksum FROM lite_migraciones WHERE nombre = ?');
        $record = $pdo->prepare('INSERT INTO lite_migraciones (nombre, checksum) VALUES (?, ?)');
        $paths = glob(__DIR__ . '/migrations/*.sql');
        if (!$paths) {
            throw new RuntimeException('No se encontraron migraciones.');
        }

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
                if ($applied !== $checksum) {
                    throw new RuntimeException("Cambió una migración aplicada: $name.");
                }
                echo "Ya aplicada: $name\n";
                continue;
            }

            // Convención de estos SQL: una sentencia termina con punto y coma al final de la línea.
            // El DDL de MariaDB hace commit implícito. Cada sentencia debe poder reanudarse.
            foreach (preg_split('/;[ \t]*(?:\n|$)/', $sql) as $statement) {
                if (trim($statement) !== '') {
                    $pdo->exec($statement);
                }
            }
            $record->execute([$name, $checksum]);
            echo "Aplicada: $name\n";
        }
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lock]);
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Migración fallida: " . $error->getMessage() . PHP_EOL);
    exit(1);
}
