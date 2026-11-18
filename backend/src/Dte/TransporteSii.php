<?php

declare(strict_types=1);

// Copiado de Farmalider, api/src/Infrastructure/Dte/TransporteSii.php, revisión 0c27acd (2026-11-06).
// Cambios en Lite: namespace MyposLite\Dte.

namespace MyposLite\Dte;


/**
 * El HTTP contra el SII, con las dos cosas que no se pueden olvidar.
 *
 * Existe separado de los canales porque {@see ClienteSii} y
 * {@see ClienteSiiSoap} hablan protocolos distintos sobre el mismo transporte, y
 * lo que hay acá es justo lo que no debe divergir entre los dos.
 */
final class TransporteSii
{
    /**
     * El User-Agent que el SII exige.
     *
     * No es cosmético ni un capricho: el servicio de recepción filtra por él y
     * con cualquier otro responde «401 NO ESTA AUTENTICADO», aunque el token sea
     * válido y recién obtenido. Está en el manual oficial (OI2003_UPDTE_MDE) y
     * es de los errores más difíciles de diagnosticar, porque el mensaje culpa a
     * la autenticación.
     */
    public const AGENTE_EXIGIDO =
        'User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Windows NT 5.0; YComp 5.0.2.4)';

    public function __construct(private readonly int $tiempoLimite = 30)
    {
    }

    /**
     * @param list<string> $cabeceras
     */
    public function peticion(
        string $url,
        string $metodo,
        string $cuerpo = '',
        array $cabeceras = [],
    ): string {
        if ($metodo === '') {
            throw ErrorSiiException::porque('Método HTTP vacío al contactar al SII.');
        }

        $canal = curl_init($url);

        if ($canal === false) {
            throw ErrorSiiException::porque('No se pudo iniciar la conexión con el SII.');
        }

        curl_setopt($canal, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($canal, CURLOPT_CUSTOMREQUEST, $metodo);
        curl_setopt($canal, CURLOPT_TIMEOUT, $this->tiempoLimite);
        curl_setopt($canal, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($canal, CURLOPT_HTTPHEADER, $cabeceras);

        // La verificación del certificado del SII NO se desactiva. Es la
        // "solución" habitual cuando aparece un error de CA, y convierte todo el
        // canal en interceptable: cualquiera en la ruta podría leer y alterar
        // los documentos tributarios que se envían.
        curl_setopt($canal, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($canal, CURLOPT_SSL_VERIFYHOST, 2);

        if ($cuerpo !== '') {
            curl_setopt($canal, CURLOPT_POSTFIELDS, $cuerpo);
        }

        $respuesta = curl_exec($canal);
        $error = curl_error($canal);
        $codigo = (int) curl_getinfo($canal, CURLINFO_HTTP_CODE);

        if ($respuesta === false || $error !== '') {
            throw ErrorSiiException::porque(
                'No se pudo contactar al SII. Reintente en unos minutos.',
                ['detalle' => $error],
            );
        }

        if ($codigo >= 400) {
            throw ErrorSiiException::porque(
                sprintf('El SII respondió con estado %d.', $codigo),
                ['codigo' => $codigo, 'respuesta' => mb_substr((string) $respuesta, 0, 500)],
            );
        }

        return (string) $respuesta;
    }
}
