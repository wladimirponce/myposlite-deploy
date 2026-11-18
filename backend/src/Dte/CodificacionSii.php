<?php

declare(strict_types=1);

// Copiado de Farmalider, api/src/Infrastructure/Dte/CodificacionSii.php, revisión 0c27acd (2026-11-06).
// Cambios en Lite: namespace MyposLite\Dte y ValidacionDte en lugar de ValidacionException.

namespace MyposLite\Dte;

/**
 * Convierte a la codificación que el SII exige.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * POR QUÉ HACE FALTA
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Los DTE se declaran `encoding="ISO-8859-1"`, pero todo lo que sale de la base
 * viene en UTF-8. Concatenar UTF-8 dentro de un documento declarado ISO-8859-1
 * produce un archivo mentiroso: libxml lee los bytes según la declaración, así
 * que «Conchalí» (`63 68 ... c3 ad`) se convierte en «ConchalÃ­».
 *
 * No es un problema cosmético. La firma se calcula sobre esos bytes y el SII
 * recanonicaliza antes de validar: basta una tilde en la comuna, en la razón
 * social o en el nombre de un producto para que el documento sea rechazado por
 * firma inválida, sobre un XML que a simple vista está bien.
 *
 * El sistema de origen tiene exactamente este defecto: sus boletas archivadas
 * declaran ISO-8859-1 y contienen UTF-8.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * QUÉ PASA CON LO QUE NO SE PUEDE REPRESENTAR
 * ────────────────────────────────────────────────────────────────────────────
 *
 * ISO-8859-1 no tiene todos los caracteres de UTF-8. Un producto con «€» o con
 * un guion largo tipográfico no tiene equivalente. Se translitera a algo
 * parecido antes de convertir, en vez de dejar que `mb_convert_encoding`
 * silenciosamente ponga `?`: un `?` en el nombre del ítem llega al cliente
 * impreso en su boleta.
 */
final class CodificacionSii
{
    /** Convierte texto UTF-8 a los bytes ISO-8859-1 que el SII espera. */
    public static function aIso(string $utf8): string
    {
        // //TRANSLIT busca el carácter parecido más cercano ("€" → "EUR");
        // //IGNORE descarta lo que ni así se pueda representar, en vez de
        // cortar la conversión a la mitad y devolver una cadena truncada.
        $convertido = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $utf8);

        if ($convertido !== false) {
            return $convertido;
        }

        return mb_convert_encoding($utf8, 'ISO-8859-1', 'UTF-8');
    }
}
