<?php

declare(strict_types=1);

// Adaptado de MyPOS, admin/api.php validateXmlAgainstXSD, revisión 0db4a67 (2026-07-15). Cambios en Lite: solo el
// esquema de boletas copiado en backend/resources/sii y verificación de cada firma del sobre.

namespace MyposLite\Dte;

/**
 * Valida un sobre de boletas contra EnvioBOLETA_v11.xsd y verifica las firmas XMLDSig de cada Documento y del SetDTE.
 *
 * Validar el esquema no prueba que el SII lo acepte, pero detecta antes de gastar un envío los errores de orden,
 * etiquetas y formatos. Verificar las firmas confirma que armar el sobre no alteró los bytes de ningún DTE.
 */
final class ValidadorEsquema
{
    private const NS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';

    /** @return list<string> Errores; vacía si el sobre es válido. */
    public static function validarSobreBoletas(string $xml): array
    {
        return self::validar($xml, 'EnvioBOLETA_v11.xsd');
    }

    /** Un DTE suelto contra DTE_v10.xsd, con su firma. @return list<string> */
    public static function validarDte(string $xml): array
    {
        return self::validar($xml, 'DTE_v10.xsd');
    }

    /** Sobre EnvioDTE (facturas, guías y notas) contra EnvioDTE_v10.xsd, con sus firmas. @return list<string> */
    public static function validarEnvioDte(string $xml): array
    {
        return self::validar($xml, 'EnvioDTE_v10.xsd');
    }

    /** RCOF contra ConsumoFolio_v10.xsd, con su firma. @return list<string> */
    public static function validarRcof(string $xml): array
    {
        return self::validar($xml, 'ConsumoFolio_v10.xsd');
    }

    /** @return list<string> */
    private static function validar(string $xml, string $esquema): array
    {
        $documento = new \DOMDocument();
        if (!@$documento->loadXML($xml, LIBXML_NONET)) {
            return ['El sobre no es XML válido.'];
        }
        $anterior = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $valido = $documento->schemaValidate(dirname(__DIR__, 2) . '/resources/sii/' . $esquema);
        $errores = $valido ? [] : array_map(static fn (\LibXMLError $error): string => 'Esquema, línea ' . $error->line . ': '
            . trim($error->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return [...$errores, ...self::verificarFirmas($documento)];
    }

    /** @return list<string> */
    private static function verificarFirmas(\DOMDocument $documento): array
    {
        $xpath = new \DOMXPath($documento);
        $xpath->registerNamespace('ds', self::NS_DSIG);
        $errores = [];
        foreach ($xpath->query('//ds:Signature') ?: [] as $firma) {
            $referencia = $xpath->query('ds:SignedInfo/ds:Reference', $firma)->item(0);
            $uri = $referencia instanceof \DOMElement ? ltrim($referencia->getAttribute('URI'), '#') : '';
            $nodo = $xpath->query("//*[@ID='$uri']")->item(0);
            $digest = trim((string) $xpath->query('ds:SignedInfo/ds:Reference/ds:DigestValue', $firma)->item(0)?->textContent);
            $valor = base64_decode(trim((string) $xpath->query('ds:SignatureValue', $firma)->item(0)?->textContent), true);
            $certificado = preg_replace('/\s+/', '', (string) $xpath->query('.//ds:X509Certificate', $firma)->item(0)?->textContent);
            $signedInfo = $xpath->query('ds:SignedInfo', $firma)->item(0);
            if (!$nodo instanceof \DOMElement || !$signedInfo instanceof \DOMElement || $valor === false || $certificado === '') {
                $errores[] = "Firma de $uri: incompleta.";
                continue;
            }
            // La firma del DTE es hermana del Documento y la del sobre, del SetDTE: el nodo firmado no la contiene.
            // Dentro del sobre el Documento hereda los mismos namespaces que tenía en su DTE, por eso su digest se conserva.
            $publica = openssl_pkey_get_public("-----BEGIN CERTIFICATE-----\n" . chunk_split($certificado, 64, "\n") . "-----END CERTIFICATE-----\n");
            if (base64_encode(sha1($nodo->C14N(), true)) !== $digest) {
                $errores[] = "Firma de $uri: el contenido firmado cambió.";
            } elseif ($publica === false || openssl_verify($signedInfo->C14N(), $valor, $publica, OPENSSL_ALGO_SHA1) !== 1) {
                $errores[] = "Firma de $uri: no valida con su certificado.";
            }
        }

        return $errores;
    }
}
