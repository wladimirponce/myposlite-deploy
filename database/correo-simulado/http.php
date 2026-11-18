<?php

declare(strict_types=1);

// Consulta del buzón de prueba: GET / lista los mensajes, ?id= entrega uno, /_control?modo= cambia el modo y /_vaciar lo vacía.
const BUZON = '/tmp/buzon';
@mkdir(BUZON, 0777, true);
header('Content-Type: application/json; charset=utf-8');
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($ruta === '/_control') {
    if (isset($_GET['modo']) && in_array($_GET['modo'], ['normal', 'caido', 'rechazar'], true)) {
        file_put_contents(BUZON . '/.modo', $_GET['modo']);
    }
    echo json_encode(['modo' => trim((string) @file_get_contents(BUZON . '/.modo')) ?: 'normal']);
    exit;
}
if ($ruta === '/_vaciar') {
    array_map('unlink', glob(BUZON . '/*.eml') ?: []);
    echo json_encode(['vaciado' => true]);
    exit;
}
if (isset($_GET['id'])) {
    $archivo = BUZON . '/' . basename((string) $_GET['id']) . '.eml';
    header('Content-Type: message/rfc822');
    echo is_file($archivo) ? file_get_contents($archivo) : '';
    exit;
}
$mensajes = [];
foreach (glob(BUZON . '/*.eml') ?: [] as $archivo) {
    $crudo = (string) file_get_contents($archivo);
    // Cabeceras desplegadas y decodificadas: un asunto largo viene en varias líneas codificadas.
    $cabeceras = iconv_mime_decode_headers(explode("\r\n\r\n", $crudo, 2)[0], ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: [];
    $cabecera = static function (string $nombre) use ($cabeceras): string {
        $valor = $cabeceras[$nombre] ?? '';
        return trim((string) (is_array($valor) ? $valor[0] : $valor));
    };
    preg_match_all('/filename="([^"]+)"/', $crudo, $adjuntos);
    $mensajes[] = ['id' => basename($archivo, '.eml'), 'de' => $cabecera('X-Simulado-De'), 'para' => $cabecera('X-Simulado-Para'),
        'asunto' => $cabecera('Subject'), 'adjuntos' => $adjuntos[1], 'bytes' => strlen($crudo)];
}
echo json_encode(['mensajes' => $mensajes], JSON_UNESCAPED_UNICODE);
