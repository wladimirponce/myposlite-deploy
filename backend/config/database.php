<?php

declare(strict_types=1);

if (!function_exists('cargarEnvSiExiste')) {
    function cargarEnvSiExiste(): void
    {
        if (getenv('MYPOSLITE_DB_DSN')) {
            return;
        }
        $candidatos = [
            __DIR__ . '/../../.env',
            __DIR__ . '/../.env',
            dirname(__DIR__, 2) . '/.env',
            dirname(__DIR__, 3) . '/.env',
        ];
        foreach ($candidatos as $ruta) {
            if (is_file($ruta)) {
                $lineas = file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lineas) {
                    foreach ($lineas as $l) {
                        $l = trim($l);
                        if ($l === '' || $l[0] === '#') {
                            continue;
                        }
                        if (str_contains($l, '=')) {
                            [$k, $v] = explode('=', $l, 2);
                            $k = trim($k);
                            $v = trim($v, " \t\n\r\0\x0B\"'");
                            if (getenv($k) === false) {
                                putenv("$k=$v");
                                $_ENV[$k] = $v;
                                $_SERVER[$k] = $v;
                            }
                        }
                    }
                }
                break;
            }
        }
    }
    cargarEnvSiExiste();
}

if (!function_exists('conectarBaseLite')) {
    function conectarBaseLite(): PDO
    {
        $dsn = getenv('MYPOSLITE_DB_DSN');
        $usuario = getenv('MYPOSLITE_DB_USER');
        $clave = getenv('MYPOSLITE_DB_PASSWORD');

        if ($dsn === false || !str_starts_with($dsn, 'mysql:') || !$usuario || $clave === false) {
            throw new RuntimeException('Configure las variables MYPOSLITE_DB_DSN, MYPOSLITE_DB_USER y MYPOSLITE_DB_PASSWORD.');
        }

        $pdo = new PDO($dsn, $usuario, $clave, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            Pdo\Mysql::ATTR_MULTI_STATEMENTS => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }
}
