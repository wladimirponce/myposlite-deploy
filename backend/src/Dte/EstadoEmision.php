<?php

declare(strict_types=1);

// Copiado de Farmalider, api/src/Domain/Dte/EstadoEmision.php, revisión 0c27acd (2026-11-06).
// Cambios en Lite: namespace MyposLite\Dte.

namespace MyposLite\Dte;

/**
 * En qué punto del camino al SII está un documento ya timbrado.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * LOS SEIS ESTADOS
 * ────────────────────────────────────────────────────────────────────────────
 *
 *   ERROR      Nunca llegó a documento. Falló antes de firmar —certificado
 *              vencido, CAF corrupto—, así que no hay nada que mandar y el
 *              folio se declara anulado en el RCOF. Es el único estado del que
 *              no se sale reintentando.
 *
 *   PENDIENTE  Firmado y timbrado, todavía no entregado. **El documento existe
 *              y es válido**: la boleta impresa con su folio y su PDF417 se
 *              verifica sin conexión. Sólo falta el envío, que está en cola.
 *
 *   ENVIADO    El SII acusó recibo y devolvió un track id. Recibido no es
 *              aceptado: todavía no lo procesó.
 *
 *   ACEPTADO   Procesado y conforme.
 *   REPARO     Aceptado con observaciones. Vale, pero hay algo que corregir
 *              para los siguientes.
 *   RECHAZADO  No lo aceptó. Requiere decisión humana: ver la nota en
 *              {@see desdeRespuesta()}.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * POR QUÉ UN CÓDIGO DESCONOCIDO NO SE INTERPRETA
 * ────────────────────────────────────────────────────────────────────────────
 *
 * El SII devuelve un catálogo de códigos que cambia y que no está publicado
 * completo en ninguna parte. Ante uno que no reconocemos, este mapeo **deja el
 * documento en ENVIADO** y guarda la glosa tal cual vino.
 *
 * Es deliberado y no es pereza. Las dos alternativas son peores:
 *
 *   · Suponer aceptado ante lo desconocido esconde rechazos reales, y un
 *     rechazo que nadie ve se descubre en la declaración mensual.
 *   · Suponer rechazado dispara alarmas por documentos que estaban bien.
 *
 * «No sé» es una respuesta correcta, y queda visible en la pantalla como un
 * documento que lleva demasiado tiempo sin confirmar.
 */
final class EstadoEmision
{
    public const ERROR = 'ERROR';
    public const PENDIENTE = 'PENDIENTE';
    public const ENVIADO = 'ENVIADO';
    public const ACEPTADO = 'ACEPTADO';
    public const REPARO = 'REPARO';
    public const RECHAZADO = 'RECHAZADO';

    /**
     * Códigos que el SII usa para decir que terminó de procesar y quedó bien.
     *
     * `EPR` (Envío Procesado) es el que llega en la práctica; los demás
     * aparecen en respuestas intermedias de la carga.
     */
    private const ACEPTA = ['EPR', 'DOK', 'VOF', 'SOK', 'CRT', 'ACEPTADO', 'OK'];

    /** Procesado, con observaciones que no invalidan el documento. */
    private const REPARA = ['RPR', 'REPARO', 'REPAROS', 'ACEPTADOCONREPAROS'];

    /**
     * Rechazos.
     *
     * `RCT` es rechazo por schema, `RFR` por firma, `RSC` por repetido y `RCH`
     * el genérico. Todos significan lo mismo para nosotros: el documento no
     * quedó declarado.
     */
    private const RECHAZA = ['RCT', 'RFR', 'RSC', 'RCH', 'RECHAZADO', 'ANULADO'];

    /**
     * Todavía en curso. El SII lo tiene, no lo ha terminado de procesar.
     *
     * Se mantiene ENVIADO y se vuelve a preguntar más tarde.
     */
    private const EN_CURSO = ['REC', 'PDR', 'PRD', 'EN PROCESO', 'ENPROCESO', 'PROCESANDO'];

    /**
     * Traduce lo que respondió el SII a uno de nuestros estados.
     *
     * Devuelve `null` cuando el código no se reconoce, para que quien llama deje
     * el documento como estaba en vez de inventarle un desenlace.
     *
     * Hay un caso que no se resuelve sólo con el código: el SII responde `EPR`
     * —«envío procesado»— tanto cuando aceptó todo como cuando procesó el envío
     * y rechazó los documentos de adentro. El detalle viene en los contadores de
     * la misma respuesta, y por eso se miran acá y no sólo la sigla.
     */
    public static function desdeRespuesta(string $codigo, string $respuesta = ''): ?string
    {
        $normalizado = strtoupper(trim($codigo));

        if ($normalizado === '') {
            return null;
        }

        if (in_array($normalizado, self::EN_CURSO, true)) {
            return self::ENVIADO;
        }

        if (in_array($normalizado, self::RECHAZA, true)) {
            return self::RECHAZADO;
        }

        if (in_array($normalizado, self::REPARA, true)) {
            return self::REPARO;
        }

        if (in_array($normalizado, self::ACEPTA, true)) {
            // «Envío procesado» describe el sobre, no los documentos. Si el
            // detalle acusa rechazados, el sobre llegó bien y el documento no
            // quedó declarado: eso es un rechazo, no una aceptación.
            $contador = static function (string $etiqueta) use ($respuesta): int {
                $patron = sprintf('#["<]?%s["> :=]*\s*["]?(\d+)#i', $etiqueta);

                return preg_match($patron, $respuesta, $c) === 1 ? (int) $c[1] : 0;
            };

            if ($contador('RECHAZADOS') > 0) {
                return self::RECHAZADO;
            }

            if ($contador('REPAROS') > 0) {
                return self::REPARO;
            }

            return self::ACEPTADO;
        }

        return null;
    }

    /** ¿Este estado ya no va a cambiar solo? */
    public static function esDefinitivo(string $estado): bool
    {
        return in_array($estado, [self::ACEPTADO, self::REPARO, self::RECHAZADO, self::ERROR], true);
    }
}
