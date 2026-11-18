<?php

declare(strict_types=1);

// Adaptado de Farmalider, api/src/Infrastructure/Dte/ConstructorNotaCredito.php, revisión 0c27acd (2026-11-06), y del
// buildDocumentoXML de MyPOS admin/api.php (revisión 0db4a67). Cambios en Lite: orden de DTE_v10.xsd (IndExe antes del
// nombre, Acteco tras el giro, dirección después), precios con IVA declarados con MntBruto, varios tipos (33, 34, 52,
// 56, 61) y referencias genéricas. Los montos salen de ConstructorBoleta::calcular, la misma regla de la venta.

namespace MyposLite\Dte;

/** XML de un DTE que no es boleta, sin firmar: facturas, guía de despacho y notas. */
final class ConstructorDte
{
    public const TIPOS = [33, 34, 52, 56, 61];

    /**
     * Montos con precios que incluyen IVA: misma regla que la boleta. Si todo es exento se calcula como exento.
     *
     * @param list<array{nombre: string, cantidad: string, precio: int, exento?: bool, unidad?: string}> $lineas
     */
    public static function calcular(int $tipo, array $lineas): array
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            throw ValidacionDte::campo('tipo', 'Tipo de documento no soportado.');
        }
        $todoExento = $tipo === 34 || array_filter($lineas, static fn (array $l): bool => !($l['exento'] ?? false)) === [];
        return ConstructorBoleta::calcular($todoExento ? 41 : 39, $tipo === 34
            ? array_map(static fn (array $l): array => ['exento' => true] + $l, $lineas) : $lineas);
    }

    /**
     * @param array{rut: string, razonSocial: string, giro: string, acteco: ?int, direccion: string, comuna: string} $emisor
     * @param array{rut: string, nombre: string, giro?: ?string, direccion?: ?string, comuna?: ?string} $receptor
     * @param array{lineas: list<array<string, mixed>>, neto: int, exento: int, iva: int, total: int} $montos
     * @param list<array{tipo: int|string, folio: string|int, fecha: string, codigo?: ?int, razon: string}> $referencias
     * @param array{indTraslado?: int, tipoDespacho?: int} $opciones
     */
    public static function construir(int $tipo, int $folio, string $fechaEmision, array $emisor, array $receptor, array $montos,
        array $referencias, string $ted, \DateTimeImmutable $ahora, array $opciones = []): string
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            throw ValidacionDte::campo('tipo', 'Tipo de documento no soportado.');
        }
        if (($emisor['acteco'] ?? null) === null) {
            throw ValidacionDte::campo('acteco', 'Ingrese el código de actividad económica del emisor: es obligatorio en facturas, guías y notas.');
        }
        $e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_XML1, 'UTF-8');
        $detalle = '';
        foreach ($montos['lineas'] as $i => $l) {
            $cantidad = str_contains($l['cantidad'], '.') ? rtrim(rtrim($l['cantidad'], '0'), '.') : $l['cantidad'];
            $detalle .= '<Detalle><NroLinDet>' . ($i + 1) . '</NroLinDet>'
                . ($l['exento'] ? '<IndExe>1</IndExe>' : '')
                . '<NmbItem>' . $e(mb_substr($l['nombre'], 0, 80)) . '</NmbItem><QtyItem>' . $cantidad . '</QtyItem>'
                . ($l['unidad'] === '' ? '' : '<UnmdItem>' . $e(mb_substr($l['unidad'], 0, 4)) . '</UnmdItem>')
                . '<PrcItem>' . $l['precio'] . '</PrcItem>' . ($l['descuento'] > 0 ? '<DescuentoMonto>' . $l['descuento'] . '</DescuentoMonto>' : '')
                . '<MontoItem>' . $l['monto'] . '</MontoItem></Detalle>';
        }
        $totales = ($montos['neto'] > 0 ? '<MntNeto>' . $montos['neto'] . '</MntNeto>' : '')
            . ($montos['exento'] > 0 ? '<MntExe>' . $montos['exento'] . '</MntExe>' : '')
            . ($montos['neto'] > 0 ? '<TasaIVA>19</TasaIVA><IVA>' . $montos['iva'] . '</IVA>' : '')
            . '<MntTotal>' . $montos['total'] . '</MntTotal>';
        $refs = '';
        foreach ($referencias as $i => $r) {
            $refs .= '<Referencia><NroLinRef>' . ($i + 1) . '</NroLinRef><TpoDocRef>' . $e((string) $r['tipo']) . '</TpoDocRef>'
                . '<FolioRef>' . $e((string) $r['folio']) . '</FolioRef><FchRef>' . $e($r['fecha']) . '</FchRef>'
                . (isset($r['codigo']) ? '<CodRef>' . $r['codigo'] . '</CodRef>' : '')
                . '<RazonRef>' . $e(mb_substr($r['razon'], 0, 90)) . '</RazonRef></Referencia>';
        }
        // Los precios del detalle incluyen IVA: MntBruto=1 lo declara. En la guía, el tipo de traslado es obligatorio.
        $idDoc = '<TipoDTE>' . $tipo . '</TipoDTE><Folio>' . $folio . '</Folio><FchEmis>' . $fechaEmision . '</FchEmis>'
            . (isset($opciones['tipoDespacho']) ? '<TipoDespacho>' . $opciones['tipoDespacho'] . '</TipoDespacho>' : '')
            . (isset($opciones['indTraslado']) ? '<IndTraslado>' . $opciones['indTraslado'] . '</IndTraslado>' : '')
            . ($montos['neto'] > 0 ? '<MntBruto>1</MntBruto>' : '');
        $receptorXml = '<RUTRecep>' . $e($receptor['rut']) . '</RUTRecep><RznSocRecep>' . $e(mb_substr($receptor['nombre'], 0, 100)) . '</RznSocRecep>'
            . (!empty($receptor['giro']) ? '<GiroRecep>' . $e(mb_substr($receptor['giro'], 0, 40)) . '</GiroRecep>' : '')
            . (!empty($receptor['direccion']) ? '<DirRecep>' . $e(mb_substr($receptor['direccion'], 0, 70)) . '</DirRecep>' : '')
            . (!empty($receptor['comuna']) ? '<CmnaRecep>' . $e(mb_substr($receptor['comuna'], 0, 20)) . '</CmnaRecep>' : '');

        return CodificacionSii::aIso('<?xml version="1.0" encoding="ISO-8859-1"?>'
            . '<DTE version="1.0" xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<Documento ID="' . ConstructorBoleta::idDocumento($tipo, $folio) . '">'
            . '<Encabezado><IdDoc>' . $idDoc . '</IdDoc>'
            . '<Emisor><RUTEmisor>' . $e($emisor['rut']) . '</RUTEmisor><RznSoc>' . $e(mb_substr($emisor['razonSocial'], 0, 100)) . '</RznSoc>'
            . '<GiroEmis>' . $e(mb_substr($emisor['giro'], 0, 80)) . '</GiroEmis><Acteco>' . (int) $emisor['acteco'] . '</Acteco>'
            . '<DirOrigen>' . $e(mb_substr($emisor['direccion'], 0, 70)) . '</DirOrigen><CmnaOrigen>' . $e(mb_substr($emisor['comuna'], 0, 20))
            . '</CmnaOrigen></Emisor>'
            . '<Receptor>' . $receptorXml . '</Receptor>'
            . '<Totales>' . $totales . '</Totales></Encabezado>'
            . $detalle . $refs . $ted . '<TmstFirma>' . $ahora->format('Y-m-d\TH:i:s') . '</TmstFirma></Documento></DTE>');
    }
}
