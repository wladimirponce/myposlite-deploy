<?php

declare(strict_types=1);

final class SesionRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function autenticar(string $correo, string $clave): ?array
    {
        $query = $this->db->prepare('SELECT id, correo, nombre, clave_hash FROM usuarios WHERE correo = ? AND activo = 1');
        $query->execute([$correo]);
        $usuario = $query->fetch(PDO::FETCH_ASSOC);
        $hash = $usuario['clave_hash'] ?? '$2y$12$UuLsbt1gB.FoDzY3KAbku.3OB72.C5uIZDCSHZvJM6e5PcLZD09qi';
        if (!password_verify($clave, $hash) || !$usuario) {
            return null;
        }
        unset($usuario['clave_hash']);
        $usuario['id'] = (string) $usuario['id'];
        return $usuario;
    }

    public function usuarioActivo(string $id): ?array
    {
        $query = $this->db->prepare('SELECT correo, nombre FROM usuarios WHERE id = ? AND activo = 1');
        $query->execute([$id]);
        $usuario = $query->fetch(PDO::FETCH_ASSOC);
        return $usuario ? ['id' => $id] + $usuario : null;
    }

    public function contextos(string $id): array
    {
        $query = $this->db->prepare(
            "SELECT e.id, e.rut, e.razon_social AS nombre, m.rol_codigo AS rol,
                l.codigo AS local_codigo, l.nombre AS local_nombre
            FROM membresias m
            JOIN empresas e ON e.id = m.empresa_id AND e.activa = 1
            LEFT JOIN locales l ON l.empresa_id = e.id AND (
                m.rol_codigo = 'administrador' OR EXISTS (
                    SELECT 1 FROM membresias_locales ml
                    WHERE ml.empresa_id = m.empresa_id AND ml.usuario_id = m.usuario_id
                      AND ml.local_codigo = l.codigo
                )
            )
            WHERE m.usuario_id = ? AND m.activa = 1
            ORDER BY e.razon_social, e.id, l.nombre, l.codigo"
        );
        $query->execute([$id]);

        $queryCajas = $this->db->prepare("SELECT c.empresa_id, c.local_codigo, c.codigo, c.nombre
            FROM cajas c JOIN membresias m ON m.empresa_id = c.empresa_id AND m.activa = 1
            WHERE m.usuario_id = ? AND (m.rol_codigo = 'administrador' OR EXISTS (
                SELECT 1 FROM membresias_cajas mc WHERE mc.usuario_id = m.usuario_id
                    AND mc.empresa_id = c.empresa_id AND mc.local_codigo = c.local_codigo
                    AND mc.caja_codigo = c.codigo
            )) ORDER BY c.nombre, c.codigo");
        $queryCajas->execute([$id]);
        $cajas = [];
        foreach ($queryCajas->fetchAll(PDO::FETCH_ASSOC) as $caja) {
            $cajas[$caja['empresa_id']][$caja['local_codigo']][] = ['codigo' => $caja['codigo'], 'nombre' => $caja['nombre']];
        }

        $empresas = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $empresaId = (string) $row['id'];
            $empresas[$empresaId] ??= [
                'id' => $empresaId,
                'rut' => $row['rut'],
                'nombre' => $row['nombre'],
                'rol' => $row['rol'],
                'locales' => [],
            ];
            if ($row['local_codigo'] !== null) {
                $empresas[$empresaId]['locales'][] = ['codigo' => $row['local_codigo'], 'nombre' => $row['local_nombre'],
                    'cajas' => $cajas[$empresaId][$row['local_codigo']] ?? []];
            }
        }
        return array_values($empresas);
    }
}
