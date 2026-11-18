<?php

declare(strict_types=1);

function permisosMaestros(PDO $db, string $usuario, string $empresa, string $local): array
{
    $query = $db->prepare("SELECT rp.permiso_codigo
        FROM membresias m
        JOIN usuarios u ON u.id = m.usuario_id AND u.activo = 1
        JOIN empresas e ON e.id = m.empresa_id AND e.activa = 1
        JOIN locales l ON l.empresa_id = e.id AND l.codigo = ?
        JOIN roles_permisos rp ON rp.rol_codigo = m.rol_codigo
        WHERE m.usuario_id = ? AND e.id = ? AND m.activa = 1
        AND (m.rol_codigo = 'administrador' OR EXISTS (
            SELECT 1 FROM membresias_locales ml
            WHERE ml.empresa_id = e.id AND ml.usuario_id = u.id AND ml.local_codigo = l.codigo
        ))");
    $query->execute([$local, $usuario, $empresa]);
    return $query->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Preámbulo común de los endpoints con contexto: sesión vigente, local seleccionado
 * y encabezado de empresa igual al de la sesión. Requiere que el endpoint defina responder().
 * Los endpoints anteriores aún repiten estos pasos; los nuevos deben usar esta función.
 *
 * @return array{db: PDO, usuario: string, empresa: string, local: string, permisos: string[]}
 */
function contextoAutorizado(): array
{
    if (!iniciarSesionLite()) {
        responder(503, ['error' => 'No se pudo iniciar la sesión.']);
    }
    $usuario = idSesionLite();
    $contexto = $_SESSION['contexto'] ?? null;
    if ($usuario === null || !$contexto) {
        responder($usuario === null ? 401 : 403, ['error' => 'Inicie sesión y seleccione un local.']);
    }
    if (($_SERVER['HTTP_X_MYPOSLITE_EMPRESA'] ?? null) !== $contexto['empresaId']) {
        responder(409, ['error' => 'Cambió la empresa activa.']);
    }
    $db = conectarBaseLite();
    $permisos = permisosMaestros($db, $usuario, $contexto['empresaId'], $contexto['localCodigo']);
    $_SESSION['actividad'] = time();
    session_write_close();
    return ['db' => $db, 'usuario' => $usuario, 'empresa' => $contexto['empresaId'],
        'local' => $contexto['localCodigo'], 'permisos' => $permisos];
}
