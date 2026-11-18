<?php

declare(strict_types=1);

// QA: medidas del timbre PDF417 que la representación dibuja para un documento guardado.
// Uso: php database/qa-timbre.php EMPRESA DOCUMENTO -> JSON con columnas, módulo y tamaño en mm, para hoja carta y ticket de 80 mm.
require __DIR__ . '/../backend/config/database.php';
require __DIR__ . '/../backend/src/Emision.php';
require_once __DIR__ . '/../backend/lib/tcpdf/tcpdf_barcodes_2d.php';

use MyposLite\Dte\RepresentacionPdf;

[, $empresa, $documento] = $argv;
$query = conectarBaseLite()->prepare('SELECT xml FROM dte_documentos WHERE empresa_id = ? AND id = ?');
$query->execute([$empresa, $documento]);
$xml = (string) $query->fetchColumn();
preg_match('/<TED[\s\S]*?<\/TED>/', $xml, $m);
$ted = $m[0] ?? '';
$codigo = (new TCPDF2DBarcode($ted, 'PDF417,2,5'))->getBarcodeArray();
[$ancho, $alto] = RepresentacionPdf::medidas($ted, 2, 0.26, 0.30, 90.0);
[$anchoTicket, $altoTicket] = RepresentacionPdf::medidas($ted, 1.5, 0.25, 0.30, 74.0);
echo json_encode([
    'firmaEnTed' => str_contains($ted, '<FRMT algoritmo="SHA1withRSA">'),
    'columnas' => $codigo['num_cols'], 'modulo' => round($ancho / $codigo['num_cols'], 3),
    'carta' => [round($ancho, 1), round($alto, 1)], 'ticket' => [round($anchoTicket, 1), round($altoTicket, 1)],
]), "\n";
