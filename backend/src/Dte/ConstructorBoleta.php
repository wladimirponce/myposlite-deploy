<?php

declare(strict_types=1);

// Adaptado de Farmalider, api/src/Infrastructure/Dte/ConstructorBoletaCertificacion.php y SetBoletasCertificacion::totales,
// revisión 0c27acd (2026-11-06). Se tomó el formato del set que el SII aceptó para Farmacéutica Libertad el 2026-11-06,
// no el de ConstructorBoleta.php de Farmalider, que agrega Acteco y TasaIVA (ausentes en EnvioBOLETA_v11.xsd) y pone
// IndExe después del precio. Cambios en Lite: líneas y receptor genéricos para el set y la venta, boleta exenta 41,
// cantidades decimales con bcmath y totales calculados aquí, en un solo lugar.

namespace MyposLite\Dte;

/**
 * XML de la boleta electrónica afecta (39) o exenta (41), sin firmar.
 *
 * Los precios de boleta incluyen IVA. El neto se obtiene del total afecto y el IVA es la diferencia, de modo que el
 * total del documento es exactamente la suma de sus líneas.
 */
final class ConstructorBoleta
{
    public const TIPOS = [39, 41];

    public static function idDocumento(int $tipo, int $folio): string
    {
        return sprintf('F%dT%d', $folio, $tipo);
    }

    /**
     * Valida las líneas y calcula montos por línea y totales.
     *
     * Una línea puede traer descuento en pesos (promoción): se descuenta del monto y se informa como DescuentoMonto.
     *
     * @param list<array{nombre: string, cantidad: string, precio: int, exento?: bool, unidad?: string, descuento?: int}> $lineas
     * @return array{lineas: list<array{nombre: string, cantidad: string, precio: int, exento: bool, unidad: string,
     *   descuento: int, monto: int}>, neto: int, exento: int, iva: int, total: int}
     */
    public static function calcular(int $tipo, array $lineas): array
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            throw ValidacionDte::campo('tipo', 'Solo se emiten boletas 39 y 41.');
        }
        if ($lineas === [] || count($lineas) > 1000) {
            throw ValidacionDte::campo('lineas', 'La boleta debe tener entre 1 y 1000 líneas.');
        }
        $afecto = 0;
        $exento = 0;
        $calculadas = [];
        foreach ($lineas as $indice => $linea) {
            $numero = $indice + 1;
            $nombre = trim((string) ($linea['nombre'] ?? ''));
            $cantidad = (string) ($linea['cantidad'] ?? '');
            $precio = $linea['precio'] ?? null;
            if ($nombre === '' || !preg_match('/^\d{1,9}(?:\.\d{1,6})?$/D', $cantidad) || bccomp($cantidad, '0', 6) <= 0
                || !is_int($precio) || $precio < 0) {
                throw ValidacionDte::campo('lineas', "Línea $numero: revise nombre, cantidad y precio.");
            }
            $esExento = $tipo === 41 || (bool) ($linea['exento'] ?? false);
            // Monto redondeado al peso, mitad hacia arriba, menos el descuento de la línea.
            $bruto = (int) bcadd(bcmul($cantidad, (string) $precio, 6), '0.5', 0);
            $descuento = $linea['descuento'] ?? 0;
            if (!is_int($descuento) || $descuento < 0 || $descuento > $bruto) {
                throw ValidacionDte::campo('lineas', "Línea $numero: descuento inválido.");
            }
            $monto = $bruto - $descuento;
            $esExento ? $exento += $monto : $afecto += $monto;
            $calculadas[] = ['nombre' => $nombre, 'cantidad' => $cantidad, 'precio' => $precio, 'exento' => $esExento,
                'unidad' => trim((string) ($linea['unidad'] ?? '')), 'descuento' => $descuento, 'monto' => $monto];
        }
        $neto = (int) round($afecto / 1.19);

        return ['lineas' => $calculadas, 'neto' => $neto, 'exento' => $exento, 'iva' => $afecto - $neto, 'total' => $afecto + $exento];
    }

    /**
     * @param array{rut: string, razonSocial: string, giro: string, direccion: string, comuna: string} $emisor
     * @param array{rut: string, nombre: string} $receptor
     * @param array{lineas: list<array<string, mixed>>, neto: int, exento: int, iva: int, total: int} $montos Resultado de calcular().
     * @param list<array{codigo: string, razon: string}> $referencias
     */
    public static function construir(int $tipo, int $folio, string $fechaEmision, array $emisor, array $receptor,
        array $montos, array $referencias, string $ted, \DateTimeImmutable $ahora): string
    {
        $e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_XML1, 'UTF-8');
        $detalle = '';
        foreach ($montos['lineas'] as $i => $l) {
            $cantidad = str_contains($l['cantidad'], '.') ? rtrim(rtrim($l['cantidad'], '0'), '.') : $l['cantidad'];
            // En la boleta exenta 41 todas las líneas lo son y no se marcan.
            $detalle .= '<Detalle><NroLinDet>' . ($i + 1) . '</NroLinDet>'
                . ($l['exento'] && $tipo === 39 ? '<IndExe>1</IndExe>' : '')
                . '<NmbItem>' . $e(mb_substr($l['nombre'], 0, 80)) . '</NmbItem><QtyItem>' . $cantidad . '</QtyItem>'
                . ($l['unidad'] === '' ? '' : '<UnmdItem>' . $e(mb_substr($l['unidad'], 0, 4)) . '</UnmdItem>')
                . '<PrcItem>' . $l['precio'] . '</PrcItem>' . ($l['descuento'] > 0 ? '<DescuentoMonto>' . $l['descuento'] . '</DescuentoMonto>' : '')
                . '<MontoItem>' . $l['monto'] . '</MontoItem></Detalle>';
        }
        $totales = $tipo === 41
            ? '<MntExe>' . $montos['exento'] . '</MntExe>'
            : '<MntNeto>' . $montos['neto'] . '</MntNeto>' . ($montos['exento'] > 0 ? '<MntExe>' . $montos['exento'] . '</MntExe>' : '')
                . '<IVA>' . $montos['iva'] . '</IVA>';
        $refs = '';
        foreach ($referencias as $i => $referencia) {
            $refs .= '<Referencia><NroLinRef>' . ($i + 1) . '</NroLinRef><CodRef>' . $e($referencia['codigo']) . '</CodRef>'
                . '<RazonRef>' . $e(mb_substr($referencia['razon'], 0, 90)) . '</RazonRef></Referencia>';
        }

        // Declarado ISO-8859-1 y convertido de verdad: una tilde en UTF-8 invalidaría la firma. Ver CodificacionSii.
        return CodificacionSii::aIso('<?xml version="1.0" encoding="ISO-8859-1"?>'
            . '<DTE version="1.0" xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<Documento ID="' . self::idDocumento($tipo, $folio) . '">'
            . '<Encabezado><IdDoc><TipoDTE>' . $tipo . '</TipoDTE><Folio>' . $folio . '</Folio><FchEmis>' . $fechaEmision
            . '</FchEmis><IndServicio>3</IndServicio></IdDoc>'
            . '<Emisor><RUTEmisor>' . $e($emisor['rut']) . '</RUTEmisor><RznSocEmisor>' . $e(mb_substr($emisor['razonSocial'], 0, 100))
            . '</RznSocEmisor><GiroEmisor>' . $e(mb_substr($emisor['giro'], 0, 80)) . '</GiroEmisor><DirOrigen>'
            . $e(mb_substr($emisor['direccion'], 0, 70)) . '</DirOrigen><CmnaOrigen>' . $e(mb_substr($emisor['comuna'], 0, 20))
            . '</CmnaOrigen></Emisor>'
            . '<Receptor><RUTRecep>' . $e($receptor['rut']) . '</RUTRecep><RznSocRecep>' . $e(mb_substr($receptor['nombre'], 0, 100))
            . '</RznSocRecep></Receptor>'
            . '<Totales>' . $totales . '<MntTotal>' . $montos['total'] . '</MntTotal></Totales></Encabezado>'
            . $detalle . $refs . $ted . '<TmstFirma>' . $ahora->format('Y-m-d\TH:i:s') . '</TmstFirma></Documento></DTE>');
    }
}
