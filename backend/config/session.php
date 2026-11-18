<?php

declare(strict_types=1);

function iniciarSesionLite(): bool
{
    return session_start([
        'name' => 'MYPOSLITE_SESSION',
        'use_strict_mode' => 1,
        'use_only_cookies' => 1,
        'cookie_httponly' => true,
        'cookie_secure' => getenv('MYPOSLITE_COOKIE_SECURE') === '1',
        'cookie_samesite' => 'Strict',
        'cookie_path' => '/api/',
        'gc_maxlifetime' => 1800,
    ]);
}

function idSesionLite(): ?string
{
    return time() - ($_SESSION['actividad'] ?? 0) < 1800 ? ($_SESSION['usuario_id'] ?? null) : null;
}
