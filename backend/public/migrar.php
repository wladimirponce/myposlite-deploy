<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';

header('Content-Type: text/plain; charset=utf-8');

// Seguridad: Solo permite ejecutar migraciones si coincide el token secreto de la empresa
$tokenEsperado = getenv('MYPOSLITE_MIGRATE_TOKEN') ?: getenv('MYPOSLITE_DTE_SECRET');
$tokenRecibido = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

$esDemoHabilitado = getenv('MYPOSLITE_LOCAL_DEMO') === '1' && in_array($tokenRecibido, ['demo', 'myposlite'], true);

if (!$esDemoHabilitado && (!$tokenEsperado || !hash_equals((string) $tokenEsperado, $tokenRecibido))) {
    http_response_code(403);
    echo "Acceso denegado. Proporcione el token correcto:\n";
    echo "Uso: ?token=<MYPOSLITE_DTE_SECRET>\n";
    exit;
}

echo "============================================================\n";
echo "   MYPOS LITE - EJECUTOR DE MIGRACIONES DE BASE DE DATOS\n";
echo "============================================================\n\n";

try {
    require __DIR__ . '/../../database/migrate.php';
    echo "\n[OK] Todas las migraciones fueron verificadas y aplicadas.\n";

    if (getenv('MYPOSLITE_LOCAL_DEMO') === '1') {
        echo "\nInicializando empresa de prueba y usuario piloto...\n";
        require __DIR__ . '/../bin/crear-demo.php';
        echo "\n[OK] Datos de piloto/demostracion listos.\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "\n[ERROR] Fallo en la ejecucion: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . " (linea " . $e->getLine() . ")\n";
}
