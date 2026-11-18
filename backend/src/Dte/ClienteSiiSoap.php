<?php

declare(strict_types=1);

// Copiado de Farmalider, api/src/Infrastructure/Dte/ClienteSiiSoap.php, revisión 0c27acd (2026-11-06).
// Cambios en Lite: namespace MyposLite\Dte; sin la interfaz CanalSii; frontera multipart propia.

namespace MyposLite\Dte;

use OpenSSLAsymmetricKey;
use Throwable;

/**
 * Canal SOAP: maullin en certificación, palena en producción.
 *
 * Es por donde viaja todo lo que no es boleta —facturas, guías y, lo que importa
 * acá, la nota de crédito 61 que da efecto tributario a una anulación—.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * NO ES «EL MISMO SII CON OTRA URL»
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Comparte con el canal REST el ritual —semilla, token, envío— y casi nada más:
 *
 *   · La semilla y el token se piden a dos servicios SOAP distintos
 *     (`CrSeed.jws` y `GetTokenFromSeed.jws`), no a un endpoint REST.
 *   · La subida no va al mismo host que la autenticación: los .jws viven en
 *     `/DTEWS/` y el archivo se sube a `/cgi_dte/UPL/DTEUpload`.
 *   · El estado se pregunta con otro servicio SOAP (`QueryEstUp.jws`) que exige
 *     el RUT del consultante además del de la empresa.
 *
 * Lo único que se mantiene igual es el multipart de la subida, que es idéntico
 * al de boletas, y el User-Agent, que el SII filtra en los dos canales.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * POR QUÉ SOBRES A MANO Y NO ext-soap
 * ────────────────────────────────────────────────────────────────────────────
 *
 * `SoapClient` descarga el WSDL en cada arranque, lo que agrega una dependencia
 * de red a un servicio que ya es el punto frágil, y falla de formas opacas
 * cuando el SII devuelve HTML en vez del WSDL. Son tres llamadas con tres
 * cuerpos fijos: armarlas a mano cuesta menos que diagnosticar eso, y no obliga
 * a que la extensión esté instalada en el cPanel.
 */
final class ClienteSiiSoap
{
    private const ENDPOINTS = [
        'CERTIFICACION' => [
            'semilla' => 'https://maullin.sii.cl/DTEWS/CrSeed.jws',
            'token' => 'https://maullin.sii.cl/DTEWS/GetTokenFromSeed.jws',
            'envio' => 'https://maullin.sii.cl/cgi_dte/UPL/DTEUpload',
            'estado' => 'https://maullin.sii.cl/DTEWS/QueryEstUp.jws',
        ],
        'PRODUCCION' => [
            'semilla' => 'https://palena.sii.cl/DTEWS/CrSeed.jws',
            'token' => 'https://palena.sii.cl/DTEWS/GetTokenFromSeed.jws',
            'envio' => 'https://palena.sii.cl/cgi_dte/UPL/DTEUpload',
            'estado' => 'https://palena.sii.cl/DTEWS/QueryEstUp.jws',
        ],
    ];

    /** Recursos que se pueden redirigir desde la configuración. */
    public const RECURSOS = ['semilla', 'token', 'envio', 'estado'];

    private readonly TransporteSii $http;

    /**
     * @param array<string, string> $sobrescrituras
     */
    public function __construct(
        private readonly string $ambiente,
        int $tiempoLimite = 30,
        private readonly array $sobrescrituras = [],
    ) {
        $this->http = new TransporteSii($tiempoLimite);
    }

    /**
     * Igual que en el canal REST, un fallo de red no lanza: el documento ya está
     * timbrado y el folio ya está gastado. Ver la nota en {@see ClienteSii}.
     *
     * @return array{track_id: ?string, estado: string, respuesta: string}
     */
    public function enviar(
        string $sobreXml,
        string $rutEmisor,
        string $rutEnvia,
        string $certificadoPem,
        OpenSSLAsymmetricKey $clave,
    ): array {
        try {
            $token = $this->autenticar($certificadoPem, $clave);
            $respuesta = $this->subir($token, $sobreXml, $rutEmisor, $rutEnvia);
        } catch (Throwable $e) {
            return [
                'track_id' => null,
                'estado' => EstadoEmision::PENDIENTE,
                'respuesta' => $e->getMessage(),
            ];
        }

        $trackId = RespuestaSii::trackId($respuesta);

        return [
            'track_id' => $trackId,
            'estado' => $trackId === null ? EstadoEmision::PENDIENTE : EstadoEmision::ENVIADO,
            'respuesta' => mb_substr($respuesta, 0, 4000),
        ];
    }

    /**
     * @return array{estado: ?string, glosa: string, respuesta: string}
     */
    public function estado(
        string $trackId,
        string $rutEmisor,
        string $certificadoPem,
        OpenSSLAsymmetricKey $clave,
    ): array {
        $token = $this->autenticar($certificadoPem, $clave);
        [$rut, $dv] = $this->partirRut($rutEmisor);

        // `getEstUp` pide el RUT del consultante **y** el de la compañía. Son el
        // mismo cuando la empresa consulta por sus propios documentos, que es
        // siempre nuestro caso.
        $cuerpo = $this->sobreSoap('getEstUp', [
            'RutConsultante' => $rut,
            'DvConsultante' => $dv,
            'RutCompania' => $rut,
            'DvCompania' => $dv,
            'TrackId' => $trackId,
            'Token' => $token,
        ]);

        $respuesta = $this->desanidar($this->http->peticion(
            $this->url('estado'),
            'POST',
            $cuerpo,
            [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: ""',
                TransporteSii::AGENTE_EXIGIDO,
            ],
        ));

        return [
            'estado' => EstadoEmision::desdeRespuesta(RespuestaSii::codigo($respuesta), $respuesta),
            'glosa' => RespuestaSii::glosa($respuesta),
            'respuesta' => mb_substr($respuesta, 0, 4000),
        ];
    }

    private function autenticar(string $certificadoPem, OpenSSLAsymmetricKey $clave): string
    {
        $respuesta = $this->desanidar($this->http->peticion(
            $this->url('semilla'),
            'POST',
            $this->sobreSoap('getSeed', []),
            [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: ""',
                TransporteSii::AGENTE_EXIGIDO,
            ],
        ));

        if (preg_match('#<SEMILLA>(.*?)</SEMILLA>#s', $respuesta, $coincidencias) !== 1) {
            throw ErrorSiiException::porque(
                'El SII no entregó una semilla válida.',
                ['respuesta' => mb_substr($respuesta, 0, 500)],
            );
        }

        $sinFirmar = '<getToken><item><Semilla>'
            . htmlspecialchars(trim($coincidencias[1]), ENT_XML1)
            . '</Semilla></item></getToken>';

        $firmado = (new FirmadorXml())->firmarSemilla($sinFirmar, $certificadoPem, $clave);

        // El XML firmado viaja como **parámetro** de la llamada SOAP, no como
        // cuerpo. Va en CDATA porque lleva sus propias etiquetas y escaparlo
        // cambiaría los bytes sobre los que se calculó la firma.
        $respuesta = $this->desanidar($this->http->peticion(
            $this->url('token'),
            'POST',
            $this->sobreSoap('getToken', ['pszXml' => $firmado], true),
            [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: ""',
                TransporteSii::AGENTE_EXIGIDO,
            ],
        ));

        if (preg_match('#<TOKEN>(.*?)</TOKEN>#s', $respuesta, $coincidencias) !== 1) {
            throw ErrorSiiException::porque(
                'El SII no entregó un token. Verifique que el certificado esté vigente y autorizado.',
                ['respuesta' => mb_substr($respuesta, 0, 500)],
            );
        }

        return trim($coincidencias[1]);
    }

    /** El multipart de la subida es el mismo que el de boletas. */
    private function subir(string $token, string $sobreXml, string $rutEmisor, string $rutEnvia): string
    {
        [$rutSender, $dvSender] = $this->partirRut($rutEnvia);
        [$rutCompany, $dvCompany] = $this->partirRut($rutEmisor);

        $frontera = '----MyposLiteBoundary' . bin2hex(random_bytes(8));
        $nombreArchivo = sprintf('envio_%s.xml', date('YmdHis'));

        $cuerpo = "--{$frontera}\r\n"
            . "Content-Disposition: form-data; name=\"rutSender\"\r\n\r\n{$rutSender}\r\n"
            . "--{$frontera}\r\n"
            . "Content-Disposition: form-data; name=\"dvSender\"\r\n\r\n{$dvSender}\r\n"
            . "--{$frontera}\r\n"
            . "Content-Disposition: form-data; name=\"rutCompany\"\r\n\r\n{$rutCompany}\r\n"
            . "--{$frontera}\r\n"
            . "Content-Disposition: form-data; name=\"dvCompany\"\r\n\r\n{$dvCompany}\r\n"
            . "--{$frontera}\r\n"
            . "Content-Disposition: form-data; name=\"archivo\"; filename=\"{$nombreArchivo}\"\r\n"
            . "Content-Type: application/xml\r\n\r\n{$sobreXml}\r\n"
            . "--{$frontera}--\r\n";

        return $this->http->peticion(
            $this->url('envio'),
            'POST',
            $cuerpo,
            [
                'Content-Type: multipart/form-data; boundary=' . $frontera,
                'Cookie: TOKEN=' . $token,
                TransporteSii::AGENTE_EXIGIDO,
            ],
        );
    }

    /**
     * Arma el sobre SOAP de una llamada.
     *
     * @param array<string, string> $parametros
     * @param bool $enCdata Los XML que van como parámetro no se escapan: se
     *        envuelven en CDATA, porque escaparlos cambiaría los bytes firmados.
     */
    private function sobreSoap(string $operacion, array $parametros, bool $enCdata = false): string
    {
        $cuerpo = '';

        foreach ($parametros as $nombre => $valor) {
            $contenido = $enCdata ? '<![CDATA[' . $valor . ']]>' : htmlspecialchars($valor, ENT_XML1);
            $cuerpo .= '<' . $nombre . '>' . $contenido . '</' . $nombre . '>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soapenv:Body>'
            . '<' . $operacion . '>' . $cuerpo . '</' . $operacion . '>'
            . '</soapenv:Body>'
            . '</soapenv:Envelope>';
    }

    /**
     * Saca el XML que el SII mete escapado dentro de la respuesta SOAP.
     *
     * Los servicios .jws no devuelven la respuesta como estructura: devuelven un
     * string que **contiene** otro XML, con sus `<` convertidos en `&lt;`.
     * Buscar `<SEMILLA>` sobre el crudo no encuentra nada, y el síntoma es un
     * «el SII no entregó una semilla válida» con una respuesta que a simple
     * vista trae la semilla.
     */
    private function desanidar(string $respuesta): string
    {
        return html_entity_decode($respuesta, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function url(string $recurso): string
    {
        if (isset($this->sobrescrituras[$recurso])) {
            return $this->sobrescrituras[$recurso];
        }

        // Igual que en el canal REST: ante un ambiente desconocido se cae a
        // certificación, nunca a producción.
        $ambiente = isset(self::ENDPOINTS[$this->ambiente]) ? $this->ambiente : 'CERTIFICACION';

        return self::ENDPOINTS[$ambiente][$recurso];
    }

    /** @return array{string, string} */
    private function partirRut(string $rut): array
    {
        $partes = explode('-', $rut);

        return [$partes[0] ?? '', $partes[1] ?? ''];
    }
}
