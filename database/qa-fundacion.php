<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';

try {
    $pdo = conectarBaseLite();
    $pdo->beginTransaction();
    try {
        $fixture = bin2hex(random_bytes(5));

        $empresa = $pdo->prepare('INSERT INTO empresas (rut, razon_social) VALUES (?, ?)');
        $ids = [];
        foreach ([0, 1] as $index) {
            $rut = str_pad((string) random_int(10000, 99999999), 8, '0', STR_PAD_LEFT) . 'K';
            $empresa->execute([$rut, "Empresa ficticia $fixture-$index"]);
            $ids[] = $pdo->lastInsertId();
        }
        [$primera, $segunda] = $ids;

        $local = $pdo->prepare('INSERT INTO locales (empresa_id, codigo, nombre) VALUES (?, ?, ?)');
        $local->execute([$primera, 'centro', 'Centro']);
        $local->execute([$primera, 'norte', 'Norte']);
        $local->execute([$segunda, 'sur', 'Sur']);

        $caja = $pdo->prepare('INSERT INTO cajas (empresa_id, local_codigo, codigo, nombre) VALUES (?, ?, ?, ?)');
        $caja->execute([$primera, 'centro', '1', 'Caja centro']);
        $caja->execute([$segunda, 'sur', '2', 'Caja sur']);

        $usuario = $pdo->prepare('INSERT INTO usuarios (correo, nombre, clave_hash) VALUES (?, ?, ?)');
        $usuario->execute(["qa-$fixture@example.invalid", 'Usuario ficticio', password_hash($fixture, PASSWORD_DEFAULT)]);
        $usuarioId = $pdo->lastInsertId();

        $membresia = $pdo->prepare('INSERT INTO membresias (empresa_id, usuario_id, rol_codigo) VALUES (?, ?, ?)');
        $membresia->execute([$primera, $usuarioId, 'vendedor']);
        $membresia->execute([$segunda, $usuarioId, 'contador']);

        $asignarLocal = $pdo->prepare('INSERT INTO membresias_locales VALUES (?, ?, ?)');
        $asignarLocal->execute([$primera, $usuarioId, 'centro']);

        $asignarCaja = $pdo->prepare('INSERT INTO membresias_cajas VALUES (?, ?, ?, ?)');
        $asignarCaja->execute([$primera, $usuarioId, 'centro', '1']);

        $rechazos = [
            'Caja con local ajeno' => [$caja, [$primera, 'sur', '2', 'Cruce inválido']],
            'Asignación de local ajeno' => [$asignarLocal, [$primera, $usuarioId, 'sur']],
            'Asignación de caja ajena' => [$asignarCaja, [$primera, $usuarioId, 'centro', '2']],
        ];
        foreach ($rechazos as $caso => [$statement, $args]) {
            try {
                $statement->execute($args);
                throw new RuntimeException("Se permitió: $caso.");
            } catch (PDOException $error) {
                if ((int) ($error->errorInfo[1] ?? 0) !== 1452) {
                    throw $error;
                }
                echo "OK: $caso fue rechazado.\n";
            }
        }

        $taxCode = "qa-$fixture";
        $impuesto = $pdo->prepare('INSERT INTO impuestos_especificos (codigo, nombre) VALUES (?, ?)');
        $impuesto->execute([$taxCode, 'Impuesto ficticio']);
        $base = $pdo->prepare('INSERT INTO impuestos_especificos_vigencias
            (impuesto_codigo, vigente_desde, porcentaje, fuente) VALUES (?, ?, ?, ?)');
        $base->execute([$taxCode, '2026-01-01', '10.000000', 'Datos ficticios de QA']);
        $ajuste = $pdo->prepare('INSERT INTO empresa_impuestos_especificos_vigencias
            (empresa_id, impuesto_codigo, vigente_desde, porcentaje) VALUES (?, ?, ?, ?)');
        $ajuste->execute([$primera, $taxCode, '2026-01-01', '9.500000']);
        $ajuste->execute([$primera, $taxCode, '2026-02-01', '8.250000']);
        $historial = $pdo->prepare('SELECT porcentaje FROM empresa_impuestos_especificos_vigencias
            WHERE empresa_id = ? AND impuesto_codigo = ? ORDER BY vigente_desde');
        $historial->execute([$primera, $taxCode]);
        if ($historial->fetchAll(PDO::FETCH_COLUMN) !== ['9.500000', '8.250000']) {
            throw new RuntimeException('El ajuste tributario no preservó el historial de tasas.');
        }
        $historial->execute([$segunda, $taxCode]);
        if ($historial->fetchColumn() !== false) {
            throw new RuntimeException('Se copió el ajuste tributario a otra empresa.');
        }
        echo "OK: tasas por vigencia y empresa verificadas.\n";
        echo "OK: dos empresas, varios locales y un usuario con dos membresías.\n";
    } finally {
        $pdo->rollBack();
    }
} catch (Throwable $error) {
    fwrite(STDERR, "QA de fundación fallido: " . $error->getMessage() . PHP_EOL);
    exit(1);
}
