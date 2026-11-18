<?php

declare(strict_types=1);

// Copiado de Farmalider, api/src/Infrastructure/Dte/RutCertificado.php, revisión 0c27acd (2026-11-06).
// Cambios en Lite: namespace MyposLite\Dte y ValidacionDte en lugar de ValidacionException.

namespace MyposLite\Dte;

/**
 * El RUT del titular de un certificado digital.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * POR QUÉ NO ALCANZA CON openssl_x509_parse()
 * ────────────────────────────────────────────────────────────────────────────
 *
 * El SII exige que el `RutEnvia` del sobre sea el del titular del certificado
 * que firma. Si no coinciden, rechaza el envío.
 *
 * Algunas entidades certificadoras ponen el RUT en el `serialNumber` del
 * subject, y ahí `openssl_x509_parse()` lo encuentra. Pero E-Certchile lo pone
 * en un `othername` del subjectAltName, que OpenSSL reporta como
 * «othername:<unsupported>» y no expone. Un certificado así parece no tener
 * RUT, y entonces el dato termina saliendo de un archivo de configuración
 * escrito a mano.
 *
 * Eso ya se rompió en la práctica: un `certs.json` heredado declaraba el RUT de
 * una persona apuntando al certificado de otra. Ningún documento firmado con él
 * habría pasado.
 *
 * Los bytes están igual en el DER, en texto plano. Buscarlos ahí es feo pero es
 * la única fuente que no puede desincronizarse del certificado, porque **es** el
 * certificado.
 */
final class RutCertificado
{
    /**
     * RUT del titular, o null si el certificado no lo declara en ninguna parte.
     *
     * @param string $certificadoPem el certificado, no la clave privada
     */
    public static function extraer(string $certificadoPem): ?string
    {
        $datos = openssl_x509_parse($certificadoPem);

        // Camino feliz: la CA lo puso donde corresponde.
        if (is_array($datos) && isset($datos['subject']['serialNumber'])) {
            $delSubject = self::normalizar((string) $datos['subject']['serialNumber']);

            if ($delSubject !== null) {
                return $delSubject;
            }
        }

        $der = self::aDer($certificadoPem);

        if ($der === null) {
            return null;
        }

        // El RUT de la propia CA también viaja en el DER (issuerAltName), así
        // que hay que descartarlo antes de quedarse con el del titular.
        $rutCa = self::rutDe(is_array($datos) ? ($datos['issuer'] ?? []) : []);

        if (preg_match_all('/(\d{7,9}-[\dkK])/', $der, $coincidencias) === 0) {
            return null;
        }

        foreach ($coincidencias[1] as $candidato) {
            $rut = self::normalizar($candidato);

            if ($rut !== null && $rut !== $rutCa) {
                return $rut;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $issuer */
    private static function rutDe(array $issuer): ?string
    {
        $json = json_encode($issuer);

        if ($json === false || preg_match('/(\d{7,9}-[\dkK])/', $json, $m) !== 1) {
            return null;
        }

        return self::normalizar($m[1]);
    }

    /** Quita ceros a la izquierda y deja el dígito verificador en mayúscula. */
    private static function normalizar(string $crudo): ?string
    {
        $crudo = strtoupper(trim($crudo));

        if (preg_match('/^0*(\d{7,9})-([\dK])$/', $crudo, $m) !== 1) {
            return null;
        }

        return $m[1] . '-' . $m[2];
    }

    private static function aDer(string $pem): ?string
    {
        $base64 = preg_replace('/-----[^-]+-----|\s+/', '', $pem);

        if ($base64 === null || $base64 === '') {
            return null;
        }

        $der = base64_decode($base64, true);

        return $der === false ? null : $der;
    }
}
