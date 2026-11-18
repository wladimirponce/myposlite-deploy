<?php

declare(strict_types=1);

// Copiado de Farmalider, api/src/Infrastructure/Dte/FirmadorXml.php, revisión 0c27acd (2026-11-06).
// Cambios en Lite: namespace MyposLite\Dte y ValidacionDte en lugar de ValidacionException.

namespace MyposLite\Dte;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use OpenSSLAsymmetricKey;

/**
 * Firma XMLDSig.
 *
 * Portado del núcleo de `central`, con su detalle más importante intacto y
 * documentado, porque es la clase de cosa que se "simplifica" en una refactor y
 * produce rechazos del SII imposibles de diagnosticar:
 *
 * **El SignedInfo se canonicaliza YA INSERTADO en el documento, no aislado.**
 * La canonicalización inclusiva (C14N) hereda los namespaces de los elementos
 * ancestros —por ejemplo el `xmlns:xsi` declarado en `<EnvioDTE>`—. Firmar el
 * SignedInfo suelto produce bytes distintos de los que el SII obtiene al
 * recanonicalizar el documento completo, y el resultado es un rechazo por firma
 * inválida sobre un XML que a simple vista está perfecto.
 *
 * SHA-1 está obsoleto en general, pero es lo que el SII exige: cambiarlo por
 * SHA-256 produce rechazo inmediato.
 */
final class FirmadorXml
{
    private const NS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';

    /** Firma el nodo identificado por `ID`. Es el caso del DTE. */
    public function firmar(
        string $xml,
        string $certificadoPem,
        OpenSSLAsymmetricKey $clavePrivada,
        string $idAFirmar,
    ): string {
        $documento = $this->cargar($xml, 'ISO-8859-1');

        $xpath = new DOMXPath($documento);
        $nodo = $this->primero($xpath->query(sprintf("//*[@ID='%s']", $idAFirmar)));

        if (!$nodo instanceof DOMElement) {
            throw ValidacionDte::campo(
                'xml',
                sprintf('No se encontró el nodo con ID="%s" para firmar.', $idAFirmar),
            );
        }

        $digest = base64_encode(sha1($nodo->C14N(), true));

        $this->insertarFirma($documento, $certificadoPem, $clavePrivada, '#' . $idAFirmar, $digest);

        return $this->completar($documento, $clavePrivada);
    }

    /**
     * Firma el documento completo, sin referencia a un ID.
     *
     * La usa el intercambio de semilla por token, donde el SII espera una firma
     * sobre todo el documento (`URI=""`) en lugar de sobre un nodo concreto.
     */
    public function firmarRaiz(
        string $xml,
        string $certificadoPem,
        OpenSSLAsymmetricKey $clavePrivada,
    ): string {
        $documento = $this->cargar($xml, 'UTF-8');
        $raiz = $documento->documentElement;

        if ($raiz === null) {
            throw ValidacionDte::campo('xml', 'El documento a firmar está vacío.');
        }

        $digest = base64_encode(sha1($raiz->C14N(), true));

        $this->insertarFirma($documento, $certificadoPem, $clavePrivada, '', $digest);

        return $this->completar($documento, $clavePrivada);
    }

    /**
     * Firma la petición de token del SII (`<getToken>`).
     *
     * ────────────────────────────────────────────────────────────────────────
     * POR QUÉ NO SIRVE `firmarRaiz()`
     * ────────────────────────────────────────────────────────────────────────
     *
     * Parecen el mismo problema —firmar el documento entero— y no lo son. El
     * servicio de autenticación del SII valida con XMLDSig clásico:
     *
     *  · el `Transform` debe ser `enveloped-signature`, no una canonicalización;
     *  · el `SignedInfo` se canonicaliza **aislado**, no dentro del documento.
     *
     * Es exactamente lo contrario de lo que exige el sobre del DTE, donde
     * firmar el SignedInfo suelto produce un rechazo por firma inválida. Dos
     * servicios del mismo organismo, dos reglas opuestas.
     *
     * Cuando esto está mal, el SII no dice que la firma no valida: responde
     * «XML Inválido, elemento Certificate no existe, función getCertificado»,
     * aunque el `<X509Certificate>` esté ahí. El mensaje manda a buscar al lugar
     * equivocado.
     */
    public function firmarSemilla(
        string $xml,
        string $certificadoPem,
        OpenSSLAsymmetricKey $clavePrivada,
    ): string {
        $documento = $this->cargar($xml, 'UTF-8');
        $raiz = $documento->documentElement;

        if ($raiz === null) {
            throw ValidacionDte::campo('xml', 'El documento a firmar está vacío.');
        }

        // El digest se calcula sobre el documento SIN la firma: es lo que
        // significa `enveloped-signature`.
        $digest = base64_encode(sha1((string) $raiz->C14N(), true));

        preg_match(
            '/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s',
            $certificadoPem,
            $coincidencias,
        );
        $certificadoB64 = (string) preg_replace('/\s+/', '', $coincidencias[1] ?? '');

        if ($certificadoB64 === '') {
            throw ValidacionDte::campo('certificado', 'No se pudo extraer el certificado X.509.');
        }

        $signedInfo = '<SignedInfo xmlns="' . self::NS_DSIG . '">'
            . '<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
            . '<SignatureMethod Algorithm="' . self::NS_DSIG . 'rsa-sha1"/>'
            . '<Reference URI="">'
            . '<Transforms>'
            . '<Transform Algorithm="' . self::NS_DSIG . 'enveloped-signature"/>'
            . '</Transforms>'
            . '<DigestMethod Algorithm="' . self::NS_DSIG . 'sha1"/>'
            . '<DigestValue>' . $digest . '</DigestValue>'
            . '</Reference>'
            . '</SignedInfo>';

        $documentoSignedInfo = new DOMDocument();
        $documentoSignedInfo->loadXML($signedInfo);
        $raizSignedInfo = $documentoSignedInfo->documentElement;

        if ($raizSignedInfo === null) {
            throw ValidacionDte::campo('xml', 'No se pudo construir el SignedInfo.');
        }

        if (!openssl_sign((string) $raizSignedInfo->C14N(), $bytes, $clavePrivada, OPENSSL_ALGO_SHA1)) {
            throw ValidacionDte::campo('certificado', 'No se pudo firmar la semilla.');
        }

        $detalles = openssl_pkey_get_details($clavePrivada);
        $modulo = isset($detalles['rsa']['n']) ? base64_encode((string) $detalles['rsa']['n']) : '';
        $exponente = isset($detalles['rsa']['e']) ? base64_encode((string) $detalles['rsa']['e']) : '';

        $firma = '<Signature xmlns="' . self::NS_DSIG . '">'
            . $signedInfo
            . '<SignatureValue>' . base64_encode($bytes) . '</SignatureValue>'
            . '<KeyInfo>'
            . '<KeyValue><RSAKeyValue>'
            . '<Modulus>' . $modulo . '</Modulus>'
            . '<Exponent>' . $exponente . '</Exponent>'
            . '</RSAKeyValue></KeyValue>'
            . '<X509Data><X509Certificate>' . $certificadoB64 . '</X509Certificate></X509Data>'
            . '</KeyInfo>'
            . '</Signature>';

        $documentoFirma = new DOMDocument();
        $documentoFirma->loadXML($firma);
        $raizFirma = $documentoFirma->documentElement;

        if ($raizFirma === null) {
            throw ValidacionDte::campo('xml', 'No se pudo construir el bloque de firma.');
        }

        $raiz->appendChild($documento->importNode($raizFirma, true));

        // La declaración lleva el encoding explícito: sin él el SII rechaza.
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . (string) $documento->saveXML($raiz);
    }

    private function cargar(string $xml, string $codificacion): DOMDocument
    {
        $documento = new DOMDocument('1.0', $codificacion);

        // Los espacios se conservan tal cual: alterarlos cambiaría la
        // canonicalización y, con ella, la firma.
        if (!@$documento->loadXML($xml)) {
            throw ValidacionDte::campo('xml', 'El documento generado no es XML válido.');
        }

        return $documento;
    }

    private function insertarFirma(
        DOMDocument $documento,
        string $certificadoPem,
        OpenSSLAsymmetricKey $clavePrivada,
        string $uri,
        string $digest,
    ): void {
        preg_match(
            '/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s',
            $certificadoPem,
            $coincidencias,
        );
        $certificadoB64 = (string) preg_replace('/\s+/', '', $coincidencias[1] ?? '');

        if ($certificadoB64 === '') {
            throw ValidacionDte::campo('certificado', 'No se pudo extraer el certificado X.509.');
        }

        $detalles = openssl_pkey_get_details($clavePrivada);
        $modulo = isset($detalles['rsa']['n']) ? base64_encode($detalles['rsa']['n']) : '';
        $exponente = isset($detalles['rsa']['e']) ? base64_encode($detalles['rsa']['e']) : '';

        $firmaXml = '<Signature xmlns="' . self::NS_DSIG . '">'
            . '<SignedInfo xmlns="' . self::NS_DSIG . '">'
            . '<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
            . '<SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>'
            . '<Reference URI="' . $uri . '">'
            . '<Transforms>'
            . '<Transform Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
            . '</Transforms>'
            . '<DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>'
            . '<DigestValue>' . $digest . '</DigestValue>'
            . '</Reference>'
            . '</SignedInfo>'
            // Se inserta vacío: se completa tras canonicalizar en contexto.
            . '<SignatureValue></SignatureValue>'
            . '<KeyInfo>'
            . '<KeyValue><RSAKeyValue>'
            . '<Modulus>' . $modulo . '</Modulus>'
            . '<Exponent>' . $exponente . '</Exponent>'
            . '</RSAKeyValue></KeyValue>'
            . '<X509Data><X509Certificate>' . $certificadoB64 . '</X509Certificate></X509Data>'
            . '</KeyInfo>'
            . '</Signature>';

        $documentoFirma = new DOMDocument();
        $documentoFirma->loadXML($firmaXml);

        $raizFirma = $documentoFirma->documentElement;

        if ($raizFirma === null) {
            throw ValidacionDte::campo('xml', 'No se pudo construir el bloque de firma.');
        }

        $documento->documentElement?->appendChild($documento->importNode($raizFirma, true));
    }

    private function completar(DOMDocument $documento, OpenSSLAsymmetricKey $clavePrivada): string
    {
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('dsig', self::NS_DSIG);

        $signedInfo = $this->ultimo($xpath->query('//dsig:SignedInfo'));
        $signatureValue = $this->ultimo($xpath->query('//dsig:SignatureValue'));

        if ($signedInfo === null || $signatureValue === null) {
            throw ValidacionDte::campo('xml', 'No se pudo localizar el bloque de firma insertado.');
        }

        // El paso crítico: canonicalizar el SignedInfo ya dentro del documento,
        // para que herede los namespaces de sus ancestros igual que hará el SII.
        if (!openssl_sign($signedInfo->C14N(), $bytes, $clavePrivada, OPENSSL_ALGO_SHA1)) {
            throw ValidacionDte::campo('certificado', 'No se pudo firmar el documento.');
        }

        $signatureValue->textContent = base64_encode($bytes);

        $resultado = $documento->saveXML();

        if ($resultado === false) {
            throw ValidacionDte::campo('xml', 'No se pudo serializar el documento firmado.');
        }

        return $resultado;
    }

    /**
     * Primer elemento de una consulta XPath.
     *
     * DOMXPath::query devuelve `false` ante una expresión inválida y una lista
     * que puede contener nodos de namespace. Los helpers normalizan eso a
     * DOMNode o null, para que quien llama no tenga que repetir la comprobación.
     *
     * @param \DOMNodeList<\DOMNameSpaceNode|DOMNode>|false $lista
     */
    private function primero(mixed $lista): ?DOMNode
    {
        if ($lista === false || $lista->length === 0) {
            return null;
        }

        $nodo = $lista->item(0);

        return $nodo instanceof DOMNode ? $nodo : null;
    }

    /** @param \DOMNodeList<\DOMNameSpaceNode|DOMNode>|false $lista */
    private function ultimo(mixed $lista): ?DOMNode
    {
        if ($lista === false || $lista->length === 0) {
            return null;
        }

        $nodo = $lista->item($lista->length - 1);

        return $nodo instanceof DOMNode ? $nodo : null;
    }
}
