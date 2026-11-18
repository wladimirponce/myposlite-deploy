<?php

declare(strict_types=1);

// Copiado de Farmalider, api/src/Infrastructure/Dte/GeneradorTimbre.php, revisión 0c27acd (2026-11-06).
// Cambios en Lite: recibe el XML del CAF y la marca de tiempo en vez del objeto Caf y el Reloj, y es estático.

namespace MyposLite\Dte;

/**
 * Timbre Electrónico (TED).
 *
 * Es el bloque que se imprime como código PDF417 en la boleta y que permite verificar el documento sin conexión.
 * Va firmado con la clave privada del CAF —no con la del certificado digital—, porque el SII entrega una por rango.
 *
 * Se conservan intactos tres detalles que parecen arbitrarios y no lo son; cada uno costó un rechazo en su momento:
 *
 * 1. El DD va en una sola línea, sin espacios entre etiquetas. El SII recanonicaliza antes de validar, y un salto de
 *    línea de más cambia los bytes firmados.
 * 2. Se firma en ISO-8859-1, no en UTF-8. Firmar el UTF-8 produce una firma que no valida en cuanto aparece una tilde
 *    o una ñ en la razón social o en el ítem.
 * 3. SHA1withRSA. Está obsoleto en general, pero es lo que el SII exige aquí.
 */
final class GeneradorTimbre
{
    public static function generar(
        string $cafXml,
        int $tipoDte,
        int $folio,
        string $fechaEmision,
        string $rutEmisor,
        string $rutReceptor,
        string $nombreReceptor,
        int $montoTotal,
        string $primerItem,
        \DateTimeImmutable $ahora,
    ): string {
        $escapar = static fn (string $valor): string => htmlspecialchars($valor, ENT_XML1, 'UTF-8');

        $dd = '<DD>'
            . '<RE>' . $escapar($rutEmisor) . '</RE>'
            . '<TD>' . $tipoDte . '</TD>'
            . '<F>' . $folio . '</F>'
            . '<FE>' . $fechaEmision . '</FE>'
            . '<RR>' . $escapar($rutReceptor) . '</RR>'
            . '<RSR>' . $escapar(mb_substr($nombreReceptor, 0, 40)) . '</RSR>'
            . '<MNT>' . $montoTotal . '</MNT>'
            . '<IT1>' . $escapar(mb_substr($primerItem, 0, 40)) . '</IT1>'
            . Caf::bloqueNormalizado($cafXml)
            . '<TSTED>' . $ahora->format('Y-m-d\TH:i:s') . '</TSTED>'
            . '</DD>';

        $clave = openssl_pkey_get_private(Caf::clavePrivada($cafXml));
        if ($clave === false) {
            throw ValidacionDte::campo('caf', 'La clave privada del CAF no es válida. Vuelva a descargar el archivo desde el SII.');
        }

        $paraFirmar = mb_convert_encoding($dd, 'ISO-8859-1', 'UTF-8');
        if (!openssl_sign($paraFirmar, $firma, $clave, OPENSSL_ALGO_SHA1)) {
            throw ValidacionDte::campo('caf', 'No se pudo firmar el timbre con la clave del CAF.');
        }

        return '<TED version="1.0">' . $dd
            . '<FRMT algoritmo="SHA1withRSA">' . base64_encode($firma) . '</FRMT>'
            . '</TED>';
    }
}
