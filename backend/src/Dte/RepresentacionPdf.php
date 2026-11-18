<?php

declare(strict_types=1);

namespace MyposLite\Dte;

use DOMDocument;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use RuntimeException;

/**
 * Representación impresa de un DTE firmado, como PDF con texto seleccionable y el timbre PDF417.
 *
 * Adaptado de MyPOS admin/src/Services/MuestraPdfGenerator.php (revisión 0db4a67), que el SII aceptó en
 * certificación y en producción: hoja carta real para que el módulo del timbre no se escale al imprimir
 * (lección del 2026-06-28), timbre con los bytes ISO-8859-1 exactos del TED firmado, acuse de recibo solo
 * en la copia cedible, y una página de hasta 500 KB. Lite dibuja el timbre en vectores, sin depender de GD.
 */
final class RepresentacionPdf
{
    public const NOMBRES = [
        33 => 'FACTURA ELECTRÓNICA', 34 => 'FACTURA NO AFECTA O EXENTA ELECTRÓNICA', 39 => 'BOLETA ELECTRÓNICA',
        41 => 'BOLETA NO AFECTA O EXENTA ELECTRÓNICA', 52 => 'GUÍA DE DESPACHO ELECTRÓNICA', 56 => 'NOTA DE DÉBITO ELECTRÓNICA',
        61 => 'NOTA DE CRÉDITO ELECTRÓNICA',
    ];
    /** Tipos con ejemplar cedible además del tributario. */
    public const CEDIBLES = [33, 34, 52];
    private const TRASLADOS = [1 => 'Operación constituye venta', 2 => 'Ventas por efectuar', 3 => 'Consignaciones',
        4 => 'Entrega gratuita', 5 => 'Traslados internos', 6 => 'Otros traslados no venta', 7 => 'Guía de devolución'];
    private const DESPACHOS = [1 => 'Despacho por cuenta del receptor', 2 => 'Despacho por cuenta del emisor a instalaciones del cliente',
        3 => 'Despacho por cuenta del emisor a otras instalaciones'];
    private const CARTA = [215.9, 279.4];

    /**
     * @param string $xml Documento firmado tal como se guardó, en ISO-8859-1.
     * @param array{numeroResolucion: int, fechaResolucion: string, unidadSii: ?string} $emisor
     * @param string $copia 'tributaria' o 'cedible'
     * @param string $formato 'carta' o '80' (ticket térmico, solo boletas)
     */
    public static function generar(string $xml, array $emisor, string $copia = 'tributaria', string $formato = 'carta'): string
    {
        require_once __DIR__ . '/../../lib/tcpdf/tcpdf.php';
        require_once __DIR__ . '/../../lib/tcpdf/tcpdf_barcodes_2d.php';
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('El XML del documento no se pudo leer.');
        }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('s', 'http://www.sii.cl/SiiDte');
        $q = static fn (string $ruta, ?DOMNode $nodo = null): string => trim((string) $xp->evaluate("string($ruta)", $nodo ?? $dom->documentElement));
        $tipo = (int) $q('//s:Encabezado/s:IdDoc/s:TipoDTE');
        $folio = (int) $q('//s:Encabezado/s:IdDoc/s:Folio');
        if (!isset(self::NOMBRES[$tipo]) || $folio < 1) {
            throw new RuntimeException('El documento no tiene un tipo o folio reconocible.');
        }
        // El timbre se arma con los bytes firmados: recodificarlo invalida su firma.
        if (!preg_match('/<TED[\s\S]*?<\/TED>/', $xml, $m)) {
            throw new RuntimeException('El documento no tiene timbre electrónico.');
        }
        $ted = $m[0];
        $copia = strtolower($copia);
        $traslado = (int) $q('//s:Encabezado/s:IdDoc/s:IndTraslado');
        if ($copia === 'cedible' && (!in_array($tipo, self::CEDIBLES, true) || ($tipo === 52 && $traslado === 5))) {
            throw new InvalidArgumentException('Este documento no tiene copia cedible.');
        }
        if ($copia !== 'tributaria' && $copia !== 'cedible') {
            throw new InvalidArgumentException('Copia inválida.');
        }
        $resolucion = 'Res. ' . (int) $emisor['numeroResolucion'] . ' de ' . substr((string) $emisor['fechaResolucion'], 0, 4);
        $unidad = trim((string) ($emisor['unidadSii'] ?? ''));
        if ($formato === '80') {
            if (!in_array($tipo, [39, 41], true)) {
                throw new InvalidArgumentException('El ticket térmico es solo para boletas.');
            }
            return self::ticket($xp, $q, $tipo, $folio, $ted, $resolucion, $unidad);
        }
        if ($formato !== 'carta') {
            throw new InvalidArgumentException('Formato inválido.');
        }

        $pdf = self::nuevo('P', self::CARTA, "{$tipo}-{$folio}");
        $pdf->SetMargins(12, 10, 12);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->AddPage();

        $pdf->SetXY(12, 12);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->MultiCell(110, 5, $q('//s:Emisor/s:RznSoc') . $q('//s:Emisor/s:RznSocEmisor'), 0, 'L');
        $pdf->SetFont('helvetica', '', 8);
        $giro = $q('//s:Emisor/s:GiroEmis') . $q('//s:Emisor/s:GiroEmisor');
        if ($giro !== '') {
            $pdf->MultiCell(110, 3.6, 'Giro: ' . $giro, 0, 'L', false, 1, 12);
        }
        $direccion = trim($q('//s:Emisor/s:DirOrigen') . ', ' . $q('//s:Emisor/s:CmnaOrigen'), ', ');
        if ($direccion !== '') {
            $pdf->MultiCell(110, 3.6, 'Casa matriz: ' . $direccion, 0, 'L', false, 1, 12);
        }

        // Recuadro del SII: RUT con puntos, tipo y folio.
        [$bx, $by, $bw, $bh] = [128, 12, 76, 27];
        $pdf->SetDrawColor(200, 0, 0);
        $pdf->SetLineWidth(0.7);
        $pdf->Rect($bx, $by, $bw, $bh);
        $pdf->SetTextColor(200, 0, 0);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetXY($bx, $by + 3);
        $pdf->Cell($bw, 5, 'R.U.T.: ' . self::rut($q('//s:Emisor/s:RUTEmisor')), 0, 2, 'C');
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY($bx + 2, $by + 9);
        $pdf->MultiCell($bw - 4, 4.5, self::NOMBRES[$tipo], 0, 'C');
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetXY($bx, $by + $bh - 8);
        $pdf->Cell($bw, 5, 'N° ' . $folio, 0, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetXY($bx, $by + $bh + 1.5);
        $pdf->Cell($bw, 4, 'S.I.I.' . ($unidad !== '' ? ' - ' . $unidad : ''), 0, 0, 'C');
        $pdf->SetDrawColor(120, 120, 120);
        $pdf->SetLineWidth(0.2);

        $y = max(46.0, $pdf->GetY() + 6);
        $pdf->Rect(12, $y, 192, 18);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetXY(14, $y + 2);
        $pdf->Cell(120, 4, 'Señor(es): ' . $q('//s:Receptor/s:RznSocRecep'), 0, 0, 'L');
        $pdf->Cell(70, 4, 'R.U.T.: ' . self::rut($q('//s:Receptor/s:RUTRecep')), 0, 1, 'L');
        $pdf->SetX(14);
        $pdf->Cell(120, 4, 'Dirección: ' . trim($q('//s:Receptor/s:DirRecep') . ', ' . $q('//s:Receptor/s:CmnaRecep'), ', '), 0, 0, 'L');
        $pdf->Cell(70, 4, 'Fecha de emisión: ' . self::fecha($q('//s:IdDoc/s:FchEmis')), 0, 1, 'L');
        $pdf->SetX(14);
        $pdf->Cell(120, 4, 'Giro: ' . $q('//s:Receptor/s:GiroRecep'), 0, 0, 'L');
        $pago = (int) $q('//s:IdDoc/s:FmaPago');
        $pdf->Cell(70, 4, $pago === 2 ? 'Forma de pago: crédito' : ($pago === 1 ? 'Forma de pago: contado' : ''), 0, 1, 'L');
        $y += 20;

        $referencias = $xp->query('//s:Referencia');
        if ($referencias->length > 0) {
            $pdf->SetXY(12, $y);
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->Cell(192, 4, 'Referencias:', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 8);
            foreach ($referencias as $ref) {
                $tipoRef = $q('.//s:TpoDocRef', $ref);
                $razon = $q('.//s:RazonRef', $ref);
                if (strtoupper($tipoRef) === 'SET') {
                    $linea = 'Referencia certificación SII: SET' . ($razon !== '' ? ' - ' . $razon : '');
                } else {
                    $linea = (self::NOMBRES[(int) $tipoRef] ?? 'Documento ' . $tipoRef) . ' N° ' . $q('.//s:FolioRef', $ref)
                        . ' del ' . self::fecha($q('.//s:FchRef', $ref));
                    $codigo = (int) $q('.//s:CodRef', $ref);
                    $linea .= [1 => ' · anula el documento', 2 => ' · corrige texto', 3 => ' · corrige montos'][$codigo] ?? '';
                    if ($razon !== '') {
                        $linea .= ' · ' . $razon;
                    }
                }
                $pdf->SetX(14);
                $pdf->MultiCell(190, 3.8, $linea, 0, 'L');
            }
            $y = $pdf->GetY() + 2;
        }

        if ($tipo === 52) {
            $pdf->SetXY(12, $y);
            $pdf->SetFont('helvetica', '', 8);
            if ($traslado) {
                $pdf->Cell(192, 4, 'Tipo de traslado: ' . (self::TRASLADOS[$traslado] ?? $traslado), 0, 1, 'L');
            }
            $despacho = (int) $q('//s:IdDoc/s:TipoDespacho');
            if ($despacho) {
                $pdf->SetX(12);
                $pdf->Cell(192, 4, 'Tipo de despacho: ' . (self::DESPACHOS[$despacho] ?? $despacho), 0, 1, 'L');
            }
            $y = $pdf->GetY() + 2;
        }

        // Detalle: el manual de muestras pide el descuento de cada línea en monto.
        $pdf->SetXY(12, $y);
        $pdf->SetFont('helvetica', 'B', 8);
        foreach ([[8, '#', 'L'], [88, 'Descripción', 'L'], [18, 'Cantidad', 'R'], [26, 'Precio unitario', 'R'], [22, 'Descuento', 'R']] as [$w, $t, $a]) {
            $pdf->Cell($w, 5, $t, 'B', 0, $a);
        }
        $pdf->Cell(30, 5, 'Total', 'B', 1, 'R');
        $pdf->SetFont('helvetica', '', 8);
        foreach ($xp->query('//s:Detalle') as $det) {
            $nombre = $q('.//s:NmbItem', $det) . ($q('.//s:IndExe', $det) === '1' ? ' (exento)' : '');
            $cantidad = $q('.//s:QtyItem', $det);
            $unidadItem = $q('.//s:UnmdItem', $det);
            $precio = $q('.//s:PrcItem', $det);
            $descuento = $q('.//s:DescuentoMonto', $det);
            $pdf->SetX(12);
            $pdf->Cell(8, 4.5, $q('.//s:NroLinDet', $det), 0, 0, 'L');
            $pdf->Cell(88, 4.5, mb_substr($nombre, 0, 58), 0, 0, 'L');
            $pdf->Cell(18, 4.5, trim(($cantidad !== '' ? self::cantidad($cantidad) : '') . ' ' . $unidadItem), 0, 0, 'R');
            $pdf->Cell(26, 4.5, $precio !== '' ? self::pesos($precio) : '', 0, 0, 'R');
            $pdf->Cell(22, 4.5, $descuento !== '' && (float) $descuento > 0 ? self::pesos($descuento) : '', 0, 0, 'R');
            $pdf->Cell(30, 4.5, self::pesos($q('.//s:MontoItem', $det)), 0, 1, 'R');
        }
        $pdf->Line(12, $pdf->GetY() + 0.5, 204, $pdf->GetY() + 0.5);
        $y = $pdf->GetY() + 2;

        $ty = $y + 2;
        $pdf->SetFont('helvetica', '', 9);
        foreach ([['Monto neto', 'MntNeto'], ['Monto exento', 'MntExe'], ['IVA (' . ($q('//s:Totales/s:TasaIVA') ?: '19') . '%)', 'IVA']] as [$rotulo, $campo]) {
            $valor = $q('//s:Totales/s:' . $campo);
            if ($valor !== '' && (float) $valor > 0) {
                $pdf->SetXY(128, $ty);
                $pdf->Cell(40, 5, $rotulo, 1, 0, 'L');
                $pdf->Cell(36, 5, self::pesos($valor), 1, 1, 'R');
                $ty += 5;
            }
        }
        foreach ($xp->query('//s:Totales/s:ImptoReten') as $impuesto) {
            $pdf->SetXY(128, $ty);
            $pdf->Cell(40, 5, 'Impuesto adicional ' . $q('.//s:TasaImp', $impuesto) . '%', 1, 0, 'L');
            $pdf->Cell(36, 5, self::pesos($q('.//s:MontoImp', $impuesto)), 1, 1, 'R');
            $ty += 5;
        }
        $pdf->SetXY(128, $ty);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(40, 6, 'TOTAL', 1, 0, 'L');
        $pdf->Cell(36, 6, self::pesos($q('//s:Totales/s:MntTotal')), 1, 1, 'R');
        $y = max($y + 4, $ty + 10);

        // Ley 19.983: el acuse de recibo va solo en la copia cedible.
        if ($copia === 'cedible') {
            $pdf->Rect(12, $y, 192, 26);
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetXY(14, $y + 1.5);
            $pdf->Cell(100, 4, 'Acuse de recibo', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetX(14);
            $pdf->Cell(100, 4.5, 'Nombre: ____________________________________', 0, 0, 'L');
            $pdf->Cell(88, 4.5, 'R.U.T.: ____________________', 0, 1, 'L');
            $pdf->SetX(14);
            $pdf->Cell(100, 4.5, 'Fecha: ______ / ______ / __________', 0, 0, 'L');
            $pdf->Cell(88, 4.5, 'Recinto: ____________________', 0, 1, 'L');
            $pdf->SetX(14);
            $pdf->Cell(188, 4.5, 'Firma: ____________________', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 6.2);
            $pdf->SetXY(14, $y + 21);
            $pdf->MultiCell(188, 2.6, 'El acuse de recibo que se declara en este acto, de acuerdo a lo dispuesto en la letra b) del Art. 4°, y la letra c) del Art. 5° de la Ley 19.983, acredita que la entrega de mercaderías o servicio(s) prestado(s) ha(n) sido recibido(s).', 0, 'L');
            $y += 30;
        }

        // Timbre: mínimo 2 × 5 cm y módulo de al menos 0,25 mm sin escalar (hoja carta real).
        [$ancho, $alto] = self::medidas($ted, 2, 0.26, 0.30, 90.0);
        $tedY = max(0.0, min($y + 2, 249.0 - $alto));
        self::timbre($pdf, $ted, 2, 20, $tedY, $ancho, $alto);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetXY(20, $tedY + $alto + 1.5);
        $pdf->Cell($ancho, 3.5, 'Timbre Electrónico SII', 0, 2, 'C');
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell($ancho, 3.5, $resolucion, 0, 2, 'C');
        $pdf->Cell($ancho, 3.5, 'Verifique documento: www.sii.cl', 0, 2, 'C');
        if ($copia === 'cedible') {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetLineWidth(0.4);
            $pdf->SetXY(128, $tedY + 18);
            $pdf->Cell(76, 9, $tipo === 52 ? 'CEDIBLE CON SU FACTURA' : 'CEDIBLE', 1, 0, 'C');
        }
        return self::cerrar($pdf, $tipo, $folio);
    }

    /** Ticket de 80 mm para boletas, con el mismo timbre. */
    private static function ticket(DOMXPath $xp, callable $q, int $tipo, int $folio, string $ted, string $resolucion, string $unidad): string
    {
        [$ancho, $mg] = [80.0, 3.0];
        $cw = $ancho - 2 * $mg;
        $lineas = $xp->query('//s:Detalle');
        $pdf = self::nuevo('P', [$ancho, 132.0 + $lineas->length * 9.0], "{$tipo}-{$folio}");
        $pdf->SetMargins($mg, 4, $mg);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();
        $by = $pdf->GetY();
        $pdf->SetLineWidth(0.3);
        $pdf->Rect($mg, $by, $cw, 17);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetXY($mg, $by + 1.5);
        $pdf->Cell($cw, 4, 'R.U.T.: ' . self::rut($q('//s:Emisor/s:RUTEmisor')), 0, 2, 'C');
        $pdf->Cell($cw, 4, self::NOMBRES[$tipo], 0, 2, 'C');
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell($cw, 5, 'N° ' . $folio, 0, 2, 'C');
        $pdf->SetY($by + 18);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->Cell($cw, 3.5, 'S.I.I.' . ($unidad !== '' ? ' - ' . $unidad : ''), 0, 2, 'C');
        $pdf->Ln(1.5);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->MultiCell($cw, 3.6, $q('//s:Emisor/s:RznSocEmisor') . $q('//s:Emisor/s:RznSoc'), 0, 'L');
        $pdf->SetFont('helvetica', '', 7);
        $giro = $q('//s:Emisor/s:GiroEmisor') . $q('//s:Emisor/s:GiroEmis');
        if ($giro !== '') {
            $pdf->MultiCell($cw, 3.2, 'Giro: ' . $giro, 0, 'L');
        }
        $direccion = trim($q('//s:Emisor/s:DirOrigen') . ', ' . $q('//s:Emisor/s:CmnaOrigen'), ', ');
        if ($direccion !== '') {
            $pdf->MultiCell($cw, 3.2, $direccion, 0, 'L');
        }
        $pdf->Cell($cw, 3.6, 'Fecha: ' . self::fecha($q('//s:IdDoc/s:FchEmis')), 0, 2, 'L');
        self::raya($pdf, $mg, $cw);
        [$colTotal, $colCant] = [20.0, 11.0];
        $colNombre = $cw - $colTotal - $colCant;
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->Cell($colNombre, 4, 'Producto', 0, 0, 'L');
        $pdf->Cell($colCant, 4, 'Cant.', 0, 0, 'R');
        $pdf->Cell($colTotal, 4, 'Total', 0, 1, 'R');
        $pdf->SetFont('helvetica', '', 7);
        foreach ($lineas as $det) {
            $y0 = $pdf->GetY();
            $pdf->MultiCell($colNombre, 3.4, mb_substr($q('.//s:NmbItem', $det) . ($q('.//s:IndExe', $det) === '1' ? ' (exento)' : ''), 0, 70), 0, 'L');
            $yNombre = $pdf->GetY();
            $pdf->SetXY($mg + $colNombre, $y0);
            $pdf->Cell($colCant, 3.4, self::cantidad($q('.//s:QtyItem', $det) ?: '1'), 0, 0, 'R');
            $pdf->Cell($colTotal, 3.4, self::pesos($q('.//s:MontoItem', $det)), 0, 0, 'R');
            $pdf->SetY(max($yNombre, $y0 + 3.4));
            $descuento = $q('.//s:DescuentoMonto', $det);
            if ($descuento !== '' && (float) $descuento > 0) {
                $pdf->Cell($cw, 3.2, '  Descuento ' . self::pesos($descuento), 0, 1, 'L');
            }
        }
        self::raya($pdf, $mg, $cw);
        $pdf->SetFont('helvetica', '', 8);
        foreach ([['Monto neto', 'MntNeto'], ['IVA', 'IVA'], ['Monto exento', 'MntExe']] as [$rotulo, $campo]) {
            $valor = $q('//s:Totales/s:' . $campo);
            if ($valor !== '' && (float) $valor > 0) {
                $pdf->Cell($cw * 0.6, 4.4, $rotulo, 0, 0, 'L');
                $pdf->Cell($cw * 0.4, 4.4, self::pesos($valor), 0, 1, 'R');
            }
        }
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell($cw * 0.6, 4.4, 'TOTAL', 0, 0, 'L');
        $pdf->Cell($cw * 0.4, 4.4, self::pesos($q('//s:Totales/s:MntTotal')), 0, 1, 'R');
        $pdf->Ln(2.5);
        // 80 mm: proporción 1,5 y módulo de al menos 0,25 mm, unos 74 × 52 mm.
        [$tw, $th] = self::medidas($ted, 1.5, 0.25, 0.30, 74.0);
        $tw = min($cw, $tw);
        $tedY = $pdf->GetY();
        self::timbre($pdf, $ted, 1.5, $mg + ($cw - $tw) / 2, $tedY, $tw, $th);
        $pdf->SetY($tedY + $th + 1.5);
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->Cell($cw, 3.2, 'Timbre Electrónico SII', 0, 2, 'C');
        $pdf->SetFont('helvetica', '', 6.5);
        $pdf->Cell($cw, 3, $resolucion, 0, 2, 'C');
        $pdf->Cell($cw, 3, 'Verifique documento: www.sii.cl', 0, 2, 'C');
        return self::cerrar($pdf, $tipo, $folio);
    }

    /**
     * Ancho y alto del timbre en mm con el módulo entre $minimo y $maximo.
     *
     * @return array{0: float, 1: float}
     */
    public static function medidas(string $ted, float $proporcion, float $minimo, float $maximo, float $objetivo): array
    {
        require_once __DIR__ . '/../../lib/tcpdf/tcpdf_barcodes_2d.php';
        $codigo = (new \TCPDF2DBarcode($ted, 'PDF417,' . $proporcion . ',5'))->getBarcodeArray();
        $columnas = (int) ($codigo['num_cols'] ?? 0);
        $filas = (int) ($codigo['num_rows'] ?? 0);
        if ($columnas < 1 || $filas < 1) {
            throw new RuntimeException('No se pudo construir el timbre PDF417.');
        }
        $modulo = max($minimo, min($maximo, $objetivo / $columnas));
        return [$columnas * $modulo, $filas * $modulo];
    }

    private static function timbre(\TCPDF $pdf, string $ted, float $proporcion, float $x, float $y, float $ancho, float $alto): void
    {
        // Vectores con la misma proporción del símbolo: no se deforma y no depende de GD.
        $pdf->write2DBarcode($ted, 'PDF417,' . $proporcion . ',5', $x, $y, $ancho, $alto,
            ['border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false], 'N');
    }

    private static function nuevo(string $orientacion, array $hoja, string $titulo): \TCPDF
    {
        // Sin el enlace oculto "Powered by TCPDF" que la librería agrega al pie.
        $pdf = new class($orientacion, 'mm', $hoja, true, 'UTF-8', false) extends \TCPDF {
            public function sinEnlace(): void
            {
                $this->tcpdflink = false;
            }
        };
        $pdf->sinEnlace();
        $pdf->SetCreator('MyPOS Lite');
        $pdf->SetTitle('DTE ' . $titulo);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        return $pdf;
    }

    private static function cerrar(\TCPDF $pdf, int $tipo, int $folio): string
    {
        if ($pdf->getNumPages() !== 1) {
            throw new RuntimeException("El documento $tipo N° $folio no cabe en una página.");
        }
        $bytes = $pdf->Output('', 'S');
        if (strlen($bytes) > 500 * 1024) {
            throw new RuntimeException("El PDF del documento $tipo N° $folio supera los 500 KB.");
        }
        return $bytes;
    }

    private static function raya(\TCPDF $pdf, float $mg, float $cw): void
    {
        $y = $pdf->GetY() + 0.6;
        $pdf->Line($mg, $y, $mg + $cw, $y);
        $pdf->SetY($y + 1.2);
    }

    public static function rut(string $rut): string
    {
        return preg_match('/^(\d+)-([\dkK])$/D', trim($rut), $m) ? number_format((float) $m[1], 0, ',', '.') . '-' . strtoupper($m[2]) : $rut;
    }

    private static function pesos(string $monto): string
    {
        return '$' . number_format((float) $monto, 0, ',', '.');
    }

    private static function cantidad(string $valor): string
    {
        $f = (float) $valor;
        return $f == (int) $f ? number_format($f, 0, ',', '.') : rtrim(rtrim(number_format($f, 3, ',', '.'), '0'), ',');
    }

    private static function fecha(string $iso): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $iso, $m) ? "$m[3]-$m[2]-$m[1]" : $iso;
    }
}
