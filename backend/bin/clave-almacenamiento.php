<?php

declare(strict_types=1);

// Imprime la contraseña derivada del usuario de almacenamiento de una empresa, para aprovisionarla en MinIO.
// Uso interno de database/aprovisionar-almacenamiento.sh; no la registra en ningún archivo.
require __DIR__ . '/../src/Almacenamiento.php';

try {
    echo credencialAlmacenamiento($argv[1] ?? '')[1];
} catch (AlmacenamientoNoDisponible $error) {
    fwrite(STDERR, $error->getMessage() . " Indique el ID numérico de empresa y defina MYPOSLITE_STORAGE_SECRET.\n");
    exit(1);
}
