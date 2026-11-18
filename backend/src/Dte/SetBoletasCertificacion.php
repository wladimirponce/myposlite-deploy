<?php

declare(strict_types=1);

// Copiado de Farmalider, api/src/Domain/Dte/SetBoletasCertificacion.php, revisión 0c27acd (2026-11-06), que a su vez
// portó los casos de MyPOS getCertCaseData. Cambios en Lite: estático, cantidades como texto para ConstructorBoleta y
// sin cálculo de totales propio (lo hace ConstructorBoleta::calcular, que cuadra con los totales de Farmalider).

namespace MyposLite\Dte;

/** Los cinco casos del «Set Prueba BE» que el SII pide para certificar boletas. */
final class SetBoletasCertificacion
{
    public const CASOS = [1, 2, 3, 4, 5];

    /** @return list<array{nombre: string, cantidad: string, precio: int, exento: bool, unidad: string}> */
    public static function caso(int $numero): array
    {
        $lineas = match ($numero) {
            1 => [['Cambio de aceite', 1, 19900], ['Alineacion y balanceo', 1, 9900]],
            2 => [['Papel de regalo', 17, 120]],
            3 => [['Sandwic', 2, 1500], ['Bebida', 2, 550]],
            4 => [['item afecto 1', 8, 1590], ['item exento 2', 2, 1000, true]],
            5 => [['Arroz', 5, 700, false, 'Kg']],
            default => throw ValidacionDte::campo('caso', 'El caso debe estar entre 1 y 5.'),
        };

        return array_map(static fn (array $l): array => [
            'nombre' => $l[0], 'cantidad' => (string) $l[1], 'precio' => $l[2],
            'exento' => $l[3] ?? false, 'unidad' => $l[4] ?? '',
        ], $lineas);
    }
}
