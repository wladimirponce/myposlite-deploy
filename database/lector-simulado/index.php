<?php

declare(strict_types=1);

// Lector simulado con la forma de la API generateContent de Gemini, solo para desarrollo y QA locales.
// No lee el contenido real: los archivos de QA llevan incrustada la lectura esperada como
// LECTURA-SIMULADA:<JSON en base64>. Otros marcadores: LECTURA-SIMULADA:FALLA (503) y LECTURA-SIMULADA:BASURA.
// Cualquier otro archivo recibe un ejemplo fijo rotulado como simulado.
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !preg_match('#/models/[^/]+:generateContent$#', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    http_response_code(404);
    exit(json_encode(['error' => ['message' => 'Ruta no simulada.']]));
}
if (($_SERVER['HTTP_X_GOOG_API_KEY'] ?? '') !== 'simulado') {
    http_response_code(403);
    exit(json_encode(['error' => ['message' => 'Clave inválida para el simulador.']]));
}
$peticion = json_decode((string) file_get_contents('php://input'), true);
$datos = $peticion['contents'][0]['parts'][1]['inline_data']['data'] ?? '';
$contenido = base64_decode((string) $datos, true) ?: '';

$ejemplo = [
    'tipo_documento' => 'GUIA_DESPACHO_COMPRA', 'proveedor_rut' => '76.086.428-5', 'proveedor_nombre' => 'Distribuidora Ejemplo Ltda.',
    'folio' => '1003', 'fecha_documento' => gmdate('Y-m-d'), 'neto' => 26000, 'iva' => 4940, 'exento' => 0, 'total' => 30940,
    'folios_guias_referenciadas' => [], 'folio_documento_referenciado' => null, 'confianza_global' => 0.9,
    'items' => [
        ['codigo_detectado' => null, 'codigo_barra_detectado' => '7800000000011', 'nombre_detectado' => 'BEBIDA COLA 1.5LT',
            'cantidad_detectada' => 12, 'costo_unitario_detectado' => 950, 'total_detectado' => 11400, 'confianza' => 0.95],
        ['codigo_detectado' => 'GAL-SUR-200', 'codigo_barra_detectado' => null, 'nombre_detectado' => 'GALLETAS SURTIDAS 200G',
            'cantidad_detectada' => 20, 'costo_unitario_detectado' => 730, 'total_detectado' => 14600, 'confianza' => 0.8],
    ],
    'observaciones' => ['Lectura simulada: no corresponde al contenido real del archivo.'],
];

if (str_contains($contenido, 'LECTURA-SIMULADA:FALLA')) {
    http_response_code(503);
    exit(json_encode(['error' => ['message' => 'The model is overloaded.']]));
}
$texto = json_encode($ejemplo, JSON_UNESCAPED_UNICODE);
if (str_contains($contenido, 'LECTURA-SIMULADA:BASURA')) {
    $texto = 'Lo siento, no puedo ayudar con eso.';
} elseif (preg_match('/LECTURA-SIMULADA:([A-Za-z0-9+\/=]+)/', $contenido, $m) && ($json = base64_decode($m[1], true)) !== false) {
    $texto = $json;
}
echo json_encode([
    'candidates' => [['content' => ['parts' => [['text' => $texto]]]]],
    'usageMetadata' => ['promptTokenCount' => 1200, 'candidatesTokenCount' => 300],
    'modelVersion' => 'simulador-local',
]);
