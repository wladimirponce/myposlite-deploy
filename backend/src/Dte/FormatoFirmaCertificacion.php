<?php

declare(strict_types=1);

// Copiado de Farmalider, api/src/Infrastructure/Dte/FormatoFirmaCertificacion.php, revisión 0c27acd (2026-11-06).
// Cambios en Lite: namespace MyposLite\Dte y ValidacionDte en lugar de ValidacionException.

namespace MyposLite\Dte;

/** Evita CHR-00002 sin alterar Documento, SignedInfo ni el TED firmado. */
final class FormatoFirmaCertificacion
{
    public static function aplicar(string $xml): string
    {
        // X509Certificate admite espacios en su base64 y no pertenece a SignedInfo.
        // Aplicar antes de firmar SetDTE; al aplicarlo de nuevo es idempotente.
        $xml = (string) preg_replace_callback(
            '~<X509Certificate>(.*?)</X509Certificate>~s',
            static fn (array $m): string => '<X509Certificate>' . "\n"
                . chunk_split((string) preg_replace('/\s+/', '', $m[1]), 64, "\n")
                . '</X509Certificate>',
            $xml,
        );

        return (string) preg_replace('/(?<!\n)<Signature xmlns=/', "\n<Signature xmlns=", $xml);
    }
}
