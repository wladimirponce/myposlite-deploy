<?php

declare(strict_types=1);

// Otorga o retira el acceso del equipo de soporte de Lite a un usuario existente. Solo desde el servidor:
//   docker compose -p myposlite exec -T backend php database/soporte.php CORREO [quitar]
require __DIR__ . '/../backend/config/database.php';

$correo = strtolower(trim($argv[1] ?? ''));
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php database/soporte.php CORREO [quitar]\n");
    exit(2);
}
$cambio = conectarBaseLite()->prepare('UPDATE usuarios SET soporte = ? WHERE correo = ?');
$cambio->execute([($argv[2] ?? '') === 'quitar' ? 0 : 1, $correo]);
if ($cambio->rowCount() === 0) {
    fwrite(STDERR, "No hay un usuario con ese correo, o ya tenía ese acceso.\n");
    exit(1);
}
echo ($argv[2] ?? '') === 'quitar' ? "Acceso de soporte retirado.\n" : "Acceso de soporte otorgado.\n";
