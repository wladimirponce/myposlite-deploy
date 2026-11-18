<?php

declare(strict_types=1);

// Adaptado de MyPOS, admin/api.php generateRCOF, revisión 0db4a67 (2026-07-15), con la agrupación de folios en tramos
// de Farmalider (api/src/Infrastructure/Dte/ConstructorRcof.php::tramos, revisión 0c27acd). Se usa la estructura
// Resumen/TipoDocumento de ConsumoFolio_v10.xsd, la que el SII aceptó en el set de Farmacéutica Libertad. Cambios en
// Lite: sin espacios entre etiquetas, varios tipos, folios anulados por lista y resolución recibida del emisor.

namespace MyposLite\Dte;

/**
 * Resumen de consumo de folios (RCOF) de boletas, sin firmar.
 *
 * El SII ya no lo exige a diario en producción (desde agosto de 2022), pero lo pide junto con el set de
 * certificación. Todo folio entregado debe quedar justificado: como utilizado o como anulado.
 */
final class ConstructorRcof
{
    public static function id(string $fecha, int $secuencia): string
    {
        return 'RCOF' . str_replace('-', '', $fecha) . '_' . $secuencia;
    }

    /**
     * @param list<array{tipo: int, neto: int, iva: int, exento: int, total: int, emitidos: list<int>, anulados: list<int>}> $resumenes
     */
    public static function construir(string $rutEmisor, string $rutEnvia, int $numeroResolucion, string $fechaResolucion,
        string $fecha, int $secuencia, array $resumenes, \DateTimeImmutable $ahora): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_XML1, 'UTF-8');
        $xml = '';
        foreach ($resumenes as $r) {
            $emitidos = $r['emitidos'];
            $anulados = $r['anulados'];
            sort($emitidos);
            sort($anulados);
            $xml .= '<Resumen><TipoDocumento>' . $r['tipo'] . '</TipoDocumento>';
            if ($emitidos !== []) {
                $xml .= ($r['neto'] > 0 ? '<MntNeto>' . $r['neto'] . '</MntNeto>' : '')
                    . ($r['iva'] > 0 ? '<MntIva>' . $r['iva'] . '</MntIva><TasaIVA>19</TasaIVA>' : '')
                    . ($r['exento'] > 0 ? '<MntExento>' . $r['exento'] . '</MntExento>' : '');
            }
            $xml .= '<MntTotal>' . $r['total'] . '</MntTotal><FoliosEmitidos>' . count($emitidos) . '</FoliosEmitidos>'
                . '<FoliosAnulados>' . count($anulados) . '</FoliosAnulados>'
                . '<FoliosUtilizados>' . (count($emitidos) + count($anulados)) . '</FoliosUtilizados>';
            foreach (self::tramos($emitidos) as [$desde, $hasta]) {
                $xml .= '<RangoUtilizados><Inicial>' . $desde . '</Inicial><Final>' . $hasta . '</Final></RangoUtilizados>';
            }
            foreach (self::tramos($anulados) as [$desde, $hasta]) {
                $xml .= '<RangoAnulados><Inicial>' . $desde . '</Inicial><Final>' . $hasta . '</Final></RangoAnulados>';
            }
            $xml .= '</Resumen>';
        }

        return '<?xml version="1.0" encoding="ISO-8859-1"?>'
            . '<ConsumoFolios version="1.0" xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            . ' xsi:schemaLocation="http://www.sii.cl/SiiDte ConsumoFolio_v10.xsd">'
            . '<DocumentoConsumoFolios ID="' . self::id($fecha, $secuencia) . '"><Caratula version="1.0">'
            . '<RutEmisor>' . $e($rutEmisor) . '</RutEmisor><RutEnvia>' . $e($rutEnvia) . '</RutEnvia>'
            . '<FchResol>' . $e($fechaResolucion) . '</FchResol><NroResol>' . $numeroResolucion . '</NroResol>'
            . '<FchInicio>' . $fecha . '</FchInicio><FchFinal>' . $fecha . '</FchFinal><SecEnvio>' . $secuencia . '</SecEnvio>'
            . '<TmstFirmaEnv>' . $ahora->format('Y-m-d\TH:i:s') . '</TmstFirmaEnv></Caratula>'
            . $xml . '</DocumentoConsumoFolios></ConsumoFolios>';
    }

    /**
     * Folios consecutivos agrupados en tramos.
     *
     * @param list<int> $folios Ordenados.
     * @return list<array{0: int, 1: int}>
     */
    private static function tramos(array $folios): array
    {
        $tramos = [];
        foreach ($folios as $folio) {
            $ultimo = array_key_last($tramos);
            if ($ultimo !== null && $tramos[$ultimo][1] === $folio - 1) {
                $tramos[$ultimo][1] = $folio;
            } else {
                $tramos[] = [$folio, $folio];
            }
        }
        return $tramos;
    }
}
