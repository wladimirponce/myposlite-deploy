<?php

declare(strict_types=1);

// Adaptado de MyPOS, admin/api.php buildEnvioBoletaSet y siiCaratulaResolucion, revisión 0db4a67 (2026-07-15), con las
// reglas del sobre de Farmalider (api/src/Infrastructure/Dte/ConstructorSobre.php, revisión 0c27acd) y lo aprendido en
// la certificación de Farmacéutica Libertad (docs/CERTIFICACION_BOLETAS_LIBERTAD.md de Farmalider). Cambios en Lite:
// sin espacios entre etiquetas, un SubTotDTE por tipo, resolución recibida del emisor configurado y EnvioDTE para los
// documentos que no son boletas (EnvioDTE_v10.xsd), con la misma regla de conservar los bytes de cada DTE firmado.

namespace MyposLite\Dte;

/**
 * Sobre de envío con uno o más DTE ya firmados: EnvioBOLETA para boletas 39 y 41, EnvioDTE para 33, 34, 52, 56 y 61.
 *
 * - Cada DTE se concatena con sus bytes tal como se firmaron. No se importan nodos DOM: libxml agrega un namespace
 *   `default` que cambia la canonicalización y rompe las firmas (expediente de Libertad).
 * - RutReceptor es el del SII, 60803000-K, salvo en el sobre de intercambio que va al receptor del documento.
 *   RutEnvia es el titular del certificado que firma.
 * - En certificación NroResol es 0 y FchResol es la fecha del comprobante de timbraje del emisor. Una fecha fija
 *   (2021-01-04) fue rechazada por el SII con CRT-3-19 en el expediente de Libertad.
 * - El sobre se firma después sobre SetDoc.
 */
final class ConstructorSobre
{
    private const RUT_SII = '60803000-K';

    /**
     * @param list<array{tipo: int, xml: string}> $documentos DTE firmados, en ISO-8859-1.
     */
    public static function boletas(array $documentos, string $rutEmisor, string $rutEnvia, int $numeroResolucion,
        string $fechaResolucion, \DateTimeImmutable $ahora): string
    {
        return self::armar('EnvioBOLETA', 'EnvioBOLETA_v11.xsd', ConstructorBoleta::TIPOS, $documentos, $rutEmisor, $rutEnvia,
            $numeroResolucion, $fechaResolucion, $ahora, self::RUT_SII);
    }

    /** @param list<array{tipo: int, xml: string}> $documentos DTE firmados que no son boletas. */
    public static function dte(array $documentos, string $rutEmisor, string $rutEnvia, int $numeroResolucion,
        string $fechaResolucion, \DateTimeImmutable $ahora, ?string $rutReceptor = null): string
    {
        return self::armar('EnvioDTE', 'EnvioDTE_v10.xsd', ConstructorDte::TIPOS, $documentos, $rutEmisor, $rutEnvia,
            $numeroResolucion, $fechaResolucion, $ahora, $rutReceptor ?? self::RUT_SII);
    }

    /** @param list<int> $tipos */
    private static function armar(string $etiqueta, string $esquema, array $tipos, array $documentos, string $rutEmisor,
        string $rutEnvia, int $numeroResolucion, string $fechaResolucion, \DateTimeImmutable $ahora, string $rutReceptor): string
    {
        if ($documentos === [] || count($documentos) > 500) {
            throw ValidacionDte::campo('sobre', 'El sobre debe llevar entre 1 y 500 documentos.');
        }
        $porTipo = [];
        $interior = '';
        foreach ($documentos as $documento) {
            if (!in_array($documento['tipo'], $tipos, true)) {
                throw ValidacionDte::campo('sobre', 'El sobre ' . $etiqueta . ' no admite el tipo ' . $documento['tipo'] . '.');
            }
            $porTipo[$documento['tipo']] = ($porTipo[$documento['tipo']] ?? 0) + 1;
            $interior .= (string) preg_replace('/^\s*<\?xml.*?\?>\s*/i', '', $documento['xml']);
        }
        ksort($porTipo);
        $subtotales = '';
        foreach ($porTipo as $tipo => $cantidad) {
            $subtotales .= '<SubTotDTE><TpoDTE>' . $tipo . '</TpoDTE><NroDTE>' . $cantidad . '</NroDTE></SubTotDTE>';
        }
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'ISO-8859-1');

        return '<?xml version="1.0" encoding="ISO-8859-1"?>'
            . '<' . $etiqueta . ' version="1.0" xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            . ' xsi:schemaLocation="http://www.sii.cl/SiiDte ' . $esquema . '">'
            . '<SetDTE ID="SetDoc"><Caratula version="1.0">'
            . '<RutEmisor>' . $e($rutEmisor) . '</RutEmisor><RutEnvia>' . $e($rutEnvia) . '</RutEnvia>'
            . '<RutReceptor>' . $e($rutReceptor) . '</RutReceptor><FchResol>' . $e($fechaResolucion) . '</FchResol>'
            . '<NroResol>' . $numeroResolucion . '</NroResol><TmstFirmaEnv>' . $ahora->format('Y-m-d\TH:i:s') . '</TmstFirmaEnv>'
            . $subtotales . '</Caratula>' . $interior . '</SetDTE></' . $etiqueta . '>';
    }
}
