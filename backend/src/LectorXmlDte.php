<?php

declare(strict_types=1);

require_once __DIR__ . '/Emision.php';

/**
 * Lectura de un DTE de proveedor en XML (EnvioDTE o DTE suelto), sin servicio externo ni costo: el XML trae los datos
 * exactos. Devuelve la misma estructura que normalizarLectura, así que el borrador, el aviso de duplicado, la asociación
 * de productos y las guías sugeridas funcionan igual que con una foto. Nada entra al stock hasta confirmar la recepción.
 *
 * Lo que no calza va a las observaciones del borrador: firma o esquema inválidos, un receptor que no es la empresa, o
 * un sobre con varios documentos (se toma el primero).
 *
 * @return array{resultado: array, modelo: string, tokensEntrada: null, tokensSalida: null}
 */
function leerDocumentoXmlDte(string $xml, string $rutEmpresa): array
{
    $dom = new DOMDocument();
    if (strlen($xml) > 5 * 1024 * 1024 || !@$dom->loadXML($xml, LIBXML_NONET)) {
        throw new InvalidArgumentException('El XML no se pudo leer.');
    }
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('s', 'http://www.sii.cl/SiiDte');
    $documentos = $xp->query('//s:DTE/s:Documento');
    if ($documentos->length === 0) {
        throw new InvalidArgumentException('El XML no es un documento tributario electrónico.');
    }
    $doc = $documentos->item(0);
    $q = static fn (string $ruta, ?DOMNode $nodo = null): string => trim((string) $xp->evaluate("string($ruta)", $nodo ?? $doc));
    $entero = static fn (string $v): int => is_numeric($v) && (int) $v > 0 ? (int) $v : 0;
    $observaciones = [];
    if ($documentos->length > 1) {
        $observaciones[] = 'El sobre trae ' . $documentos->length . ' documentos: se tomó el primero.';
    }
    if ($dom->documentElement->localName === 'EnvioDTE') {
        $errores = \MyposLite\Dte\ValidadorEsquema::validarEnvioDte($xml);
        if ($errores !== []) {
            $observaciones[] = 'El XML no pasó la validación del SII (esquema o firma): ' . mb_substr(implode(' ', array_slice($errores, 0, 2)), 0, 250);
        }
    }
    $normalizar = static fn (string $rut): string => strtoupper(preg_replace('/[^0-9kK]/', '', $rut) ?? '');
    $receptor = $q('.//s:Encabezado/s:Receptor/s:RUTRecep');
    if ($normalizar($receptor) !== $normalizar($rutEmpresa)) {
        $observaciones[] = "El documento está emitido a otro RUT ($receptor), no a esta empresa.";
    }
    $tipo = (int) $q('.//s:Encabezado/s:IdDoc/s:TipoDTE');
    $referencias = $xp->query('.//s:Referencia', $doc);
    $guias = [];
    $referido = null;
    foreach ($referencias as $ref) {
        $tipoRef = $q('.//s:TpoDocRef', $ref);
        $folioRef = $q('.//s:FolioRef', $ref);
        if ($tipoRef === '52' && ctype_digit($folioRef)) {
            $guias[] = ltrim($folioRef, '0');
        }
        if (in_array($tipoRef, ['33', '34'], true) && ctype_digit($folioRef)) {
            $referido = ltrim($folioRef, '0');
        }
    }
    // Con MntBruto = 1 los precios de las líneas incluyen IVA; el costo de la recepción es neto.
    $bruto = $q('.//s:Encabezado/s:IdDoc/s:MntBruto') === '1' && $tipo !== 34;
    $items = [];
    foreach ($xp->query('.//s:Detalle', $doc) as $i => $det) {
        if ($i >= 100) {
            break;
        }
        $codigo = null;
        $barras = null;
        foreach ($xp->query('.//s:CdgItem', $det) as $cdg) {
            $tipoCodigo = strtoupper($q('.//s:TpoCodigo', $cdg));
            $valor = mb_substr($q('.//s:VlrCodigo', $cdg), 0, 60);
            if ($valor === '') {
                continue;
            }
            if (str_starts_with($tipoCodigo, 'EAN') || (preg_match('/^\d{8,14}$/D', $valor) && $barras === null)) {
                $barras ??= mb_substr($valor, 0, 48);
            } else {
                $codigo ??= $valor;
            }
        }
        $cantidad = $q('.//s:QtyItem', $det);
        $vencimiento = $q('.//s:FchVencim', $det);
        $items[] = ['codigo' => $codigo, 'codigoBarras' => $barras, 'nombre' => mb_substr($q('.//s:NmbItem', $det), 0, 160) ?: 'Producto',
            'cantidad' => is_numeric($cantidad) && (float) $cantidad > 0 ? rtrim(rtrim(number_format((float) $cantidad, 6, '.', ''), '0'), '.') : null,
            'costoUnitario' => (int) round((float) $q('.//s:PrcItem', $det) / ($bruto && $q('.//s:IndExe', $det) === '' ? 1.19 : 1)),
            'total' => $entero($q('.//s:MontoItem', $det)),
            'vencimiento' => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $vencimiento) ? $vencimiento : null, 'confianza' => 1.0];
    }
    $folio = ltrim($q('.//s:Encabezado/s:IdDoc/s:Folio'), '0');
    $fecha = $q('.//s:Encabezado/s:IdDoc/s:FchEmis');
    return ['resultado' => [
        'tipo' => in_array($tipo, [33, 34, 52, 56, 61], true) ? $tipo : null,
        'proveedorRut' => $q('.//s:Encabezado/s:Emisor/s:RUTEmisor') ?: null,
        'proveedorNombre' => mb_substr($q('.//s:Encabezado/s:Emisor/s:RznSoc'), 0, 160) ?: null,
        'folio' => ctype_digit($folio) && $folio !== '' ? $folio : null,
        'fecha' => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fecha) ? $fecha : null,
        'neto' => $entero($q('.//s:Totales/s:MntNeto')), 'iva' => $entero($q('.//s:Totales/s:IVA')),
        'exento' => $entero($q('.//s:Totales/s:MntExe')), 'total' => $entero($q('.//s:Totales/s:MntTotal')),
        'foliosGuias' => array_values(array_unique($guias)), 'folioReferido' => $referido,
        'confianza' => $observaciones === [] ? 1.0 : 0.5, 'items' => $items, 'observaciones' => $observaciones,
    ], 'modelo' => 'xml-dte', 'tokensEntrada' => null, 'tokensSalida' => null];
}
