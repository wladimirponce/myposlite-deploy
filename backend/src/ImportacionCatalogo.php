<?php

declare(strict_types=1);

function importarCatalogo(PDO $db, string $empresa, string $local, string $usuario, array $entrada, bool $confirmar): array
{
    $filas = $entrada['filas'] ?? null;
    if (!is_array($filas) || !array_is_list($filas) || !$filas || count($filas) > 500) {
        throw new InvalidArgumentException('La importación requiere entre 1 y 500 filas.');
    }
    $correctas = 0;
    $errores = [];
    $ultimaFila = 1;
    $db->beginTransaction();
    foreach ($filas as $fila) {
        $numero = $fila['fila'] ?? null;
        $producto = $fila['producto'] ?? null;
        if (!is_int($numero) || $numero <= $ultimaFila || $numero > 501 || !is_array($producto)) {
            throw new InvalidArgumentException('Las filas deben conservar el orden y número de la planilla.');
        }
        $ultimaFila = $numero;
        $db->exec('SAVEPOINT fila_catalogo');
        try {
            guardarProducto($db, $empresa, $local, $usuario, $producto);
            ++$correctas;
        } catch (InvalidArgumentException | PDOException $error) {
            if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) !== 1062) {
                throw $error;
            }
            $db->exec('ROLLBACK TO SAVEPOINT fila_catalogo');
            $errores[] = ['fila' => $numero, 'mensaje' => $error instanceof PDOException
                ? 'SKU, código de barras o presentación duplicados.'
                : $error->getMessage()];
        }
    }
    $guardar = $confirmar && !$errores;
    if ($guardar) {
        $db->commit();
    } else {
        $db->rollBack();
    }
    return ['correctas' => $correctas, 'errores' => $errores, 'importados' => $guardar ? $correctas : 0];
}
