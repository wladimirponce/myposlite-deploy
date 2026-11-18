<?php

declare(strict_types=1);

// Copiado de Farmalider, api/src/Infrastructure/Dte/RespuestaSii.php, revisión 0c27acd (2026-11-06).
// Cambios en Lite: namespace MyposLite\Dte.

namespace MyposLite\Dte;

/**
 * Lee el track id, el código y la glosa de lo que responde el SII.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * POR QUÉ SE LEE «A LA BRAVA» Y NO CON UN PARSER
 * ────────────────────────────────────────────────────────────────────────────
 *
 * La misma información llega en formatos distintos según el canal, el ambiente
 * y el día:
 *
 *   · el canal REST de boletas responde JSON, a veces con el track id como
 *     número y a veces como texto entrecomillado;
 *   · el canal SOAP responde un XML que trae otro XML escapado adentro;
 *   · ante un error, cualquiera de los dos puede devolver una página HTML.
 *
 * Comprometerse con un parser obliga a acertar el formato antes de saber qué
 * llegó, y falla justo cuando hace falta leer el mensaje: cuando el SII devolvió
 * algo inesperado. Buscar la etiqueta donde esté es feo y es lo que aguanta.
 *
 * Nada de lo que sale de acá se usa para decidir por sí solo: el código pasa por
 * {@see EstadoEmision}, que ante lo desconocido no
 * interpreta.
 */
final class RespuestaSii
{
    /** Nombres con que cada canal llama al mismo código de estado. */
    private const ETIQUETAS_ESTADO = ['ESTADO', 'estadoEnvio', 'estado_envio', 'codigo_estado', 'STATUS'];

    /** Y a la explicación en castellano. */
    private const ETIQUETAS_GLOSA = ['GLOSA', 'glosa', 'descripcionEnvio', 'descripcion', 'mensaje', 'DETALLE'];

    /**
     * El número de seguimiento del envío.
     *
     * Es lo único que permite volver a preguntar por un documento después, así
     * que se busca en las tres formas en que aparece.
     */
    public static function trackId(string $cuerpo): ?string
    {
        $patrones = [
            '#<\s*trackid\s*>\s*(\d+)\s*<#i',
            '#"track_?id"\s*:\s*"?(\d+)"?#i',
            '#\btrackid\b["\s:=>]+(\d+)#i',
        ];

        foreach ($patrones as $patron) {
            if (preg_match($patron, $cuerpo, $coincidencias) === 1) {
                return $coincidencias[1];
            }
        }

        return null;
    }

    /** El código de estado, o cadena vacía si la respuesta no trae ninguno. */
    public static function codigo(string $cuerpo): string
    {
        return self::buscar($cuerpo, self::ETIQUETAS_ESTADO);
    }

    /** La explicación que acompaña al código. */
    public static function glosa(string $cuerpo): string
    {
        $glosa = self::buscar($cuerpo, self::ETIQUETAS_GLOSA);

        if ($glosa !== '') {
            return $glosa;
        }

        // Sin glosa, un recorte del cuerpo sirve más que una cadena vacía: es lo
        // que va a leer quien tenga que averiguar por qué no pasó.
        return trim(mb_substr(strip_tags($cuerpo), 0, 200));
    }

    /**
     * @param list<string> $etiquetas
     */
    private static function buscar(string $cuerpo, array $etiquetas): string
    {
        foreach ($etiquetas as $etiqueta) {
            $escapada = preg_quote($etiqueta, '#');

            // <ETIQUETA>valor</ETIQUETA>
            if (preg_match(sprintf('#<\s*%s\s*>(.*?)<\s*/#is', $escapada), $cuerpo, $coincidencias) === 1) {
                $valor = trim(html_entity_decode($coincidencias[1]));

                if ($valor !== '') {
                    return $valor;
                }
            }

            // "etiqueta": "valor"
            if (preg_match(sprintf('#"%s"\s*:\s*"([^"]*)"#i', $escapada), $cuerpo, $coincidencias) === 1) {
                $valor = trim($coincidencias[1]);

                if ($valor !== '') {
                    return $valor;
                }
            }
        }

        return '';
    }
}
