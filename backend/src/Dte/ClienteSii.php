<?php

declare(strict_types=1);

// Copiado de Farmalider, api/src/Infrastructure/Dte/ClienteSii.php, revisión 0c27acd (2026-11-06).
// Cambios en Lite: namespace MyposLite\Dte, sin la interfaz CanalSii, frontera multipart propia y estadoPorFolio,
// adaptado de MyPOS admin/api.php queryEstadoBoletaPorFolio (revisión 0db4a67).

namespace MyposLite\Dte;

use OpenSSLAsymmetricKey;
use Throwable;

/**
 * Canal REST de boletas: pangal en certificación, rahue en producción.
 *
 * El flujo es siempre el mismo: pedir una semilla, firmarla para obtener un
 * token, y enviar el documento con ese token. El token dura poco, así que se
 * obtiene en cada operación en vez de cachearse: cachear uno vencido produce un
 * rechazo que parece un problema del documento y no de la sesión.
 *
 * Se aísla tras {@see CanalSii} para que el dominio no sepa nada de REST, de
 * multipart ni de los dos ambientes. Cambiar de endpoint —o simularlo en una
 * prueba— no toca una línea del servicio de emisión.
 *
 * Sólo transporta boletas (39 y 41). El resto de los tipos viaja por
 * {@see ClienteSiiSoap}.
 */
final class ClienteSii
{
    private const ENDPOINTS = [
        'CERTIFICACION' => [
            'semilla' => 'https://apicert.sii.cl/recursos/v1/boleta.electronica.semilla',
            'token' => 'https://apicert.sii.cl/recursos/v1/boleta.electronica.token',
            'envio' => 'https://pangal.sii.cl/recursos/v1/boleta.electronica.envio',
            'estado' => 'https://apicert.sii.cl/recursos/v1/boleta.electronica.envio/%s-%s-%s',
            'folio' => 'https://apicert.sii.cl/recursos/v1/boleta.electronica/%s-%s-%s-%s/estado',
        ],
        'PRODUCCION' => [
            'semilla' => 'https://api.sii.cl/recursos/v1/boleta.electronica.semilla',
            'token' => 'https://api.sii.cl/recursos/v1/boleta.electronica.token',
            'envio' => 'https://rahue.sii.cl/recursos/v1/boleta.electronica.envio',
            'estado' => 'https://api.sii.cl/recursos/v1/boleta.electronica.envio/%s-%s-%s',
            'folio' => 'https://api.sii.cl/recursos/v1/boleta.electronica/%s-%s-%s-%s/estado',
        ],
    ];

    private readonly TransporteSii $http;

    /**
     * @param array<string, string> $sobrescrituras URLs que reemplazan a las
     *        de la tabla, por recurso: `semilla`, `token`, `envio`, `estado`.
     */
    public function __construct(
        private readonly string $ambiente,
        int $tiempoLimite = 30,
        private readonly array $sobrescrituras = [],
    ) {
        $this->http = new TransporteSii($tiempoLimite);
    }

    /** Recursos que se pueden redirigir desde la configuración. */
    public const RECURSOS = ['semilla', 'token', 'envio', 'estado', 'folio'];

    /**
     * Entrega el sobre.
     *
     * ────────────────────────────────────────────────────────────────────────
     * POR QUÉ ESTE MÉTODO NO LANZA ANTE UN FALLO DE RED
     * ────────────────────────────────────────────────────────────────────────
     *
     * Cuando se llama a esto el documento **ya existe**: está construido,
     * timbrado con la clave del CAF y firmado. Un corte de red no lo deshace, y
     * el folio ya está gastado.
     *
     * Propagar la excepción obligaría a cada punto de llamada a decidir qué
     * hacer con un documento válido que no se pudo entregar, y en el mesón esa
     * decisión siempre es la misma: imprimir la boleta y reintentar el envío
     * después. Así que se devuelve PENDIENTE con el motivo guardado, y la cola
     * se encarga.
     *
     * {@see estado()} sí lanza: ahí no hay nada en juego más que una consulta.
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
            // Sin track id el SII no se hizo cargo del sobre, aunque haya
            // contestado 200: queda pendiente y se reintenta.
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

        $respuesta = $this->peticion(
            sprintf($this->url('estado'), $rut, $dv, $trackId),
            'GET',
            '',
            [
                'Cookie: TOKEN=' . $token,
                'Accept: application/json',
                TransporteSii::AGENTE_EXIGIDO,
            ],
        );

        return [
            'estado' => EstadoEmision::desdeRespuesta(RespuestaSii::codigo($respuesta), $respuesta),
            'glosa' => RespuestaSii::glosa($respuesta),
            'respuesta' => mb_substr($respuesta, 0, 4000),
        ];
    }

    /** Semilla + token, que es lo que el SII pide antes de cualquier operación. */
    /**
     * Estado de una boleta por su folio, sin track ID. Sirve para conciliar un envío cuya respuesta se perdió: si el
     * SII ya conoce el folio, el sobre llegó y no se reenvía; FAU (documento no recibido) permite reenviar el mismo XML.
     *
     * @return array{codigo: string, estado: ?string, glosa: string, respuesta: string}
     */
    public function estadoPorFolio(int $tipo, int $folio, string $rutEmisor, string $rutReceptor, string $fechaEmision, int $monto,
        string $certificadoPem, OpenSSLAsymmetricKey $clave): array
    {
        $token = $this->autenticar($certificadoPem, $clave);
        [$rut, $dv] = $this->partirRut($rutEmisor);
        [$rutRecep, $dvRecep] = $this->partirRut($rutReceptor);
        $consulta = http_build_query(['rut_receptor' => $rutRecep, 'dv_receptor' => $dvRecep, 'monto' => $monto,
            'fechaEmision' => date('d-m-Y', (int) strtotime($fechaEmision))]);
        $respuesta = $this->peticion(sprintf($this->url('folio'), $rut, $dv, $tipo, $folio) . '?' . $consulta, 'GET', '', [
            'Cookie: TOKEN=' . $token,
            'Accept: application/json',
            TransporteSii::AGENTE_EXIGIDO,
        ]);
        $codigo = strtoupper(RespuestaSii::codigo($respuesta));
        return ['codigo' => $codigo, 'estado' => EstadoEmision::desdeRespuesta($codigo, $respuesta),
            'glosa' => RespuestaSii::glosa($respuesta), 'respuesta' => mb_substr($respuesta, 0, 4000)];
    }

    private function autenticar(string $certificadoPem, OpenSSLAsymmetricKey $clave): string
    {
        $respuesta = $this->peticion($this->url('semilla'), 'GET', '', [
            'Accept: application/xml',
            TransporteSii::AGENTE_EXIGIDO,
        ]);

        if (preg_match('#<SEMILLA>(.*?)</SEMILLA>#s', $respuesta, $coincidencias) !== 1) {
            throw ErrorSiiException::porque('El SII no entregó una semilla válida.', ['respuesta' => $respuesta]);
        }

        $semilla = trim($coincidencias[1]);

        $sinFirmar = '<getToken><item><Semilla>' . htmlspecialchars($semilla, ENT_XML1) . '</Semilla></item></getToken>';

        // `firmarSemilla`, no `firmarRaiz`: el servicio de autenticación valida
        // con XMLDSig clásico, al revés que el sobre del DTE. Ver el comentario
        // en FirmadorXml.
        $firmado = (new FirmadorXml())->firmarSemilla($sinFirmar, $certificadoPem, $clave);

        $respuesta = $this->peticion($this->url('token'), 'POST', $firmado, [
            'Content-Type: application/xml',
            'Accept: application/xml',
            TransporteSii::AGENTE_EXIGIDO,
        ]);

        if (preg_match('#<TOKEN>(.*?)</TOKEN>#s', $respuesta, $coincidencias) !== 1) {
            throw ErrorSiiException::porque(
                'El SII no entregó un token. Verifique que el certificado esté vigente y autorizado.',
                ['respuesta' => mb_substr($respuesta, 0, 500)],
            );
        }

        return trim($coincidencias[1]);
    }

    private function subir(string $token, string $sobreXml, string $rutEmisor, string $rutEnvia): string
    {
        // Son dos RUT distintos y el SII los distingue: `Sender` es la persona
        // dueña del certificado que firma, `Company` la empresa que emite.
        // Mandar el mismo en ambos hace que el envío se rechace.
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

        return $this->peticion(
            $this->url('envio'),
            'POST',
            $cuerpo,
            [
                'Content-Type: multipart/form-data; boundary=' . $frontera,
                'Cookie: TOKEN=' . $token,
                'Accept: application/json',
                TransporteSii::AGENTE_EXIGIDO,
            ],
        );
    }

    /**
     * @param list<string> $cabeceras
     */
    private function peticion(
        string $url,
        string $metodo,
        string $cuerpo = '',
        array $cabeceras = [],
    ): string {
        return $this->http->peticion($url, $metodo, $cuerpo, $cabeceras);
    }

    private function url(string $recurso): string
    {
        if (isset($this->sobrescrituras[$recurso])) {
            return $this->sobrescrituras[$recurso];
        }

        // Ante un ambiente desconocido se cae a certificación, nunca a
        // producción: equivocarse hacia el lado que no emite documentos con
        // validez tributaria es el error barato.
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
