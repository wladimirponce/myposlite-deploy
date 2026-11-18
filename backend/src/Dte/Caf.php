<?php

declare(strict_types=1);

// Adaptado de Farmalider, api/src/Domain/Dte/Caf.php y DteService::analizarCaf, revisión 0c27acd (2026-11-06).
// Cambios en Lite: lectura con DOM en vez de expresiones regulares, validación del RUT emisor, del tipo, del ambiente
// (IDK) y de que la clave privada RSASK corresponda a la pública RSAPK del rango. Sin repositorio: el CAF se guarda
// cifrado en dte_cafs (ver backend/src/Emision.php).

namespace MyposLite\Dte;

/**
 * Código de Autorización de Folios.
 *
 * El XML que entrega el SII contiene, además del rango autorizado, la clave privada RSA con la que se firma el timbre
 * de cada documento. Es material tan sensible como el certificado: quien lo tenga puede emitir documentos timbrados
 * de ese rango. Por eso nunca se registra, nunca se expone por la API y se guarda cifrado.
 */
final class Caf
{
    /** IDK con que el SII firma los CAF del ambiente de certificación. Los de producción usan otra clave. */
    private const IDK_CERTIFICACION = 100;

    /**
     * Lee y valida el XML de un CAF.
     *
     * @return array{rut: string, razonSocial: string, tipo: int, desde: int, hasta: int, fecha: string, idk: int,
     *   ambiente: string, huella: string}
     */
    public static function analizar(string $xml): array
    {
        $documento = new \DOMDocument();
        if (strlen($xml) > 65536 || !@$documento->loadXML($xml, LIBXML_NONET)) {
            throw ValidacionDte::campo('caf', 'El archivo no es un CAF válido del SII.');
        }
        $texto = static function (string $etiqueta) use ($documento): ?string {
            $nodo = $documento->getElementsByTagName($etiqueta)->item(0);
            return $nodo === null ? null : trim($nodo->textContent);
        };
        $rut = strtoupper((string) $texto('RE'));
        $tipo = $texto('TD');
        $desde = $texto('D');
        $hasta = $texto('H');
        $fecha = $texto('FA');
        $idk = $texto('IDK');
        if (!preg_match('/^\d{7,8}-[\dK]$/', $rut) || !ctype_digit((string) $tipo) || !ctype_digit((string) $desde)
            || !ctype_digit((string) $hasta) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fecha) || !ctype_digit((string) $idk)) {
            throw ValidacionDte::campo('caf', 'El archivo no parece un CAF válido: faltan el RUT, el tipo, el rango, la fecha o el IDK.');
        }
        if ((int) $desde < 1 || (int) $desde > (int) $hasta) {
            throw ValidacionDte::campo('caf', 'El rango de folios del CAF es inconsistente.');
        }
        $clave = $texto('RSASK');
        $modulo = $texto('M');
        $exponente = $texto('E');
        if ($clave === null || $modulo === null || $exponente === null) {
            throw ValidacionDte::campo('caf', 'El CAF no contiene sus claves. Descargue el archivo completo desde el SII.');
        }
        // La clave privada debe ser la del rango: si no, los timbres no validarían.
        $privada = openssl_pkey_get_private($clave);
        $detalles = $privada === false ? false : openssl_pkey_get_details($privada);
        $n = is_array($detalles) ? ltrim((string) ($detalles['rsa']['n'] ?? ''), "\0") : '';
        $e = is_array($detalles) ? ltrim((string) ($detalles['rsa']['e'] ?? ''), "\0") : '';
        if ($n === '' || $n !== ltrim((string) base64_decode($modulo, true), "\0") || $e !== ltrim((string) base64_decode($exponente, true), "\0")) {
            throw ValidacionDte::campo('caf', 'La clave privada del CAF no corresponde a su rango. Vuelva a descargarlo desde el SII.');
        }

        return ['rut' => $rut, 'razonSocial' => (string) $texto('RS'), 'tipo' => (int) $tipo, 'desde' => (int) $desde,
            'hasta' => (int) $hasta, 'fecha' => (string) $fecha, 'idk' => (int) $idk,
            'ambiente' => (int) $idk === self::IDK_CERTIFICACION ? 'certificacion' : 'produccion',
            'huella' => hash('sha256', $xml)];
    }

    /** Clave privada RSA del rango, que firma el TED. Es distinta de la del certificado digital. */
    public static function clavePrivada(string $xml): string
    {
        if (!preg_match('#<RSASK>([\s\S]*?)</RSASK>#i', $xml, $coincidencias)) {
            throw ValidacionDte::campo('caf', 'El archivo CAF no contiene la clave privada (RSASK).');
        }

        return trim($coincidencias[1]);
    }

    /**
     * Bloque <CAF> normalizado, listo para incrustar en el timbre.
     *
     * Se eliminan los espacios entre etiquetas. No es cosmético: el SII canonicaliza el documento antes de validar
     * la firma, y un salto de línea de más dentro del bloque cambia los bytes firmados.
     */
    public static function bloqueNormalizado(string $xml): string
    {
        if (!preg_match('#<CAF.*?>[\s\S]*?</CAF>#i', $xml, $coincidencias)) {
            throw ValidacionDte::campo('caf', 'No se pudo extraer el bloque <CAF> del archivo.');
        }

        return (string) preg_replace('/>\s+</', '><', trim($coincidencias[0]));
    }
}
