<?php

declare(strict_types=1);

// Enlace del correo de verificación de un destinatario. Público: el token es la prueba de acceso al buzón.
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/Correo.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");

$e = static fn (string $texto): string => htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
try {
    $resultado = confirmarVerificacionCorreo(conectarBaseLite(), is_string($_GET['token'] ?? null) ? $_GET['token'] : '');
    $titulo = 'Correo verificado';
    $mensaje = $e($resultado['correo']) . ' recibirá los cierres y avisos de ' . $e($resultado['empresa']) . '.';
} catch (InvalidArgumentException | RuntimeException $error) {
    http_response_code($error->getCode() === 404 ? 404 : 400);
    $titulo = 'No se pudo verificar';
    $mensaje = $e($error->getCode() === 404 || $error instanceof InvalidArgumentException ? $error->getMessage() : 'Intente más tarde.');
} catch (Throwable $error) {
    error_log('MyPOS Lite verificar-correo: ' . $error::class);
    http_response_code(500);
    $titulo = 'No se pudo verificar';
    $mensaje = 'Intente más tarde.';
}
echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>' . $titulo . ' · MyPOS Lite</title><style>body{font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;'
    . 'color:#1b2430;background:#f7f9fc}</style></head><body><h1>' . $titulo . '</h1><p>' . $mensaje . '</p></body></html>';
