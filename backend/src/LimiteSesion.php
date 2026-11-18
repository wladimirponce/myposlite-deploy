<?php

declare(strict_types=1);

final class LimiteSesion
{
    private const MAX_FALLOS = 5;
    private const VENTANA = 900;

    public function __construct(private readonly PDO $db)
    {
    }

    public function autenticar(SesionRepository $repository, string $correo, string $clave): array
    {
        $cuenta = hash('sha256', $correo);
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO intentos_sesion (cuenta_hash, expira_en) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE cuenta_hash = VALUES(cuenta_hash)')
                ->execute([$cuenta, time() + self::VENTANA]);
            $query = $this->db->prepare('SELECT fallidos, expira_en FROM intentos_sesion WHERE cuenta_hash = ? FOR UPDATE');
            $query->execute([$cuenta]);
            $intentos = $query->fetch(PDO::FETCH_ASSOC);
            $ahora = time();
            $expira = (int) $intentos['expira_en'];
            $fallos = (int) $intentos['fallidos'];
            if ($expira <= $ahora) {
                $expira = $ahora + self::VENTANA;
                $fallos = 0;
            }
            if ($fallos >= self::MAX_FALLOS) {
                $this->db->commit();
                return ['usuario' => null, 'espera' => max(1, $expira - $ahora)];
            }
            $usuario = $repository->autenticar($correo, $clave);
            if ($usuario === null) {
                $this->db->prepare('UPDATE intentos_sesion SET fallidos = ?, expira_en = ? WHERE cuenta_hash = ?')
                    ->execute([$fallos + 1, $expira, $cuenta]);
            } else {
                $this->db->prepare('DELETE FROM intentos_sesion WHERE cuenta_hash = ?')->execute([$cuenta]);
            }
            $this->db->commit();
            return ['usuario' => $usuario, 'espera' => 0];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
