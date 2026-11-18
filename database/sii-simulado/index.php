<?php

declare(strict_types=1);

/*
 * SII simulado para desarrollo y QA del motor DTE (perfil simulador de compose). Imita los cuatro recursos SOAP del
 * UPLOAD clásico que usa el set de certificación: semilla, token, subida y estado del envío. No reemplaza al SII:
 * sirve para probar lo que no se puede probar contra el organismo, como caídas y respuestas perdidas.
 *
 * Verifica de verdad la firma de la semilla (enveloped-signature), el token en cada subida, y el esquema y las firmas
 * de cada sobre con ValidadorEsquema. Guarda la huella de cada sobre recibido.
 *
 * Control, solo dentro de la red de Docker: GET /_control?modo=normal|caido|perder|rechazar y GET /_control para leer
 * el estado. «perder» recibe y registra el próximo sobre pero responde 504, como una respuesta perdida en la red.
 * «rechazar» hace que el próximo sobre quede en RCT.
 */

require __DIR__ . '/../../backend/src/Emision.php';

use MyposLite\Dte\ValidadorEsquema;

const ARCHIVO_ESTADO = '/tmp/sii-simulado.json';

function estadoSimulado(callable $cambiar): array
{
    $archivo = fopen(ARCHIVO_ESTADO, 'c+');
    flock($archivo, LOCK_EX);
    $estado = json_decode((string) stream_get_contents($archivo), true) ?: ['modo' => 'normal', 'tokens' => [], 'envios' => []];
    $resultado = $cambiar($estado);
    ftruncate($archivo, 0);
    rewind($archivo);
    fwrite($archivo, json_encode($estado));
    flock($archivo, LOCK_UN);
    fclose($archivo);
    return $resultado ?? $estado;
}

function soap(string $operacion, string $contenido): never
{
    header('Content-Type: text/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
        . '<soapenv:Body><ns1:' . $operacion . 'Response xmlns:ns1="urn:simulado"><ns1:' . $operacion . 'Return>'
        . htmlspecialchars($contenido, ENT_XML1) . '</ns1:' . $operacion . 'Return></ns1:' . $operacion . 'Response></soapenv:Body></soapenv:Envelope>';
    exit;
}

$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$modo = estadoSimulado(static fn (array $e) => ['modo' => $e['modo']])['modo'];

if ($ruta === '/_control') {
    header('Content-Type: application/json');
    $nuevo = $_GET['modo'] ?? null;
    echo json_encode(estadoSimulado(static function (array &$e) use ($nuevo) {
        if (in_array($nuevo, ['normal', 'caido', 'perder', 'rechazar'], true)) {
            $e['modo'] = $nuevo;
        }
        return null;
    }));
    exit;
}

if ($modo === 'caido') {
    http_response_code(503);
    exit;
}

$cuerpo = (string) file_get_contents('php://input');

if ($ruta === '/DTEWS/CrSeed.jws') {
    soap('getSeed', '<?xml version="1.0" encoding="UTF-8"?><SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema"><SII:RESP_BODY><SEMILLA>'
        . sprintf('%012d', random_int(1, 999999999999)) . '</SEMILLA></SII:RESP_BODY><SII:RESP_HDR><ESTADO>00</ESTADO></SII:RESP_HDR></SII:RESPUESTA>');
}

/**
 * Verifica la semilla firmada como lo hace el SII: digest del documento sin la firma (enveloped-signature) y SignedInfo
 * canonicalizado aislado, con el certificado que trae. Devuelve un token nuevo o null.
 */
function tokenParaSemilla(string $xml): ?string
{
    $documento = new DOMDocument();
    if (!@$documento->loadXML($xml, LIBXML_NONET)) {
        return null;
    }
    $xpath = new DOMXPath($documento);
    $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
    $firma = $xpath->query('//ds:Signature')->item(0);
    if (!$firma instanceof DOMElement) {
        return null;
    }
    $signedInfo = $xpath->query('ds:SignedInfo', $firma)->item(0);
    $digest = trim((string) $xpath->query('.//ds:DigestValue', $firma)->item(0)?->textContent);
    $valor = base64_decode(trim((string) $xpath->query('ds:SignatureValue', $firma)->item(0)?->textContent));
    $cert = preg_replace('/\s+/', '', (string) $xpath->query('.//ds:X509Certificate', $firma)->item(0)?->textContent);
    $aislado = new DOMDocument();
    $aislado->appendChild($aislado->importNode($signedInfo, true));
    $firma->parentNode->removeChild($firma);
    $publica = openssl_pkey_get_public("-----BEGIN CERTIFICATE-----\n" . chunk_split($cert, 64, "\n") . "-----END CERTIFICATE-----\n");
    if (base64_encode(sha1($documento->documentElement->C14N(), true)) !== $digest || $publica === false
        || openssl_verify($aislado->documentElement->C14N(), $valor, $publica, OPENSSL_ALGO_SHA1) !== 1) {
        return null;
    }
    $token = strtoupper(bin2hex(random_bytes(6)));
    estadoSimulado(static function (array &$e) use ($token) {
        $e['tokens'][] = $token;
        return null;
    });
    return $token;
}

/**
 * Recibe un sobre por UPLOAD o REST: valida token, esquema y firmas, registra la huella y los folios, y aplica los
 * modos de falla. Devuelve status (0 recibido, 5 sin token válido), track y si la respuesta se pierde.
 */
function recibirSobre(string $canal): array
{
    preg_match('/TOKEN=([A-Z0-9]+)/', $_SERVER['HTTP_COOKIE'] ?? '', $m);
    $token = $m[1] ?? '';
    $sobre = is_uploaded_file($_FILES['archivo']['tmp_name'] ?? '') ? (string) file_get_contents($_FILES['archivo']['tmp_name']) : '';
    // El mismo UPLOAD recibe sobres de boletas y el RCOF del set de certificación.
    $esRcof = str_contains($sobre, '<ConsumoFolios');
    $errores = $sobre === '' ? ['Sin archivo.'] : ($esRcof ? ValidadorEsquema::validarRcof($sobre)
        : (str_contains($sobre, '<EnvioDTE') ? ValidadorEsquema::validarEnvioDte($sobre) : ValidadorEsquema::validarSobreBoletas($sobre)));
    preg_match_all('#<TipoDTE>(\d+)</TipoDTE><Folio>(\d+)</Folio>#', $sobre, $folios, PREG_SET_ORDER);
    $cantidad = $esRcof ? (preg_match('#<FoliosEmitidos>(\d+)</FoliosEmitidos>#', $sobre, $c) ? (int) $c[1] : 0) : count($folios);
    return estadoSimulado(static function (array &$e) use ($token, $sobre, $errores, $cantidad, $folios, $canal) {
        if (!in_array($token, $e['tokens'], true)) {
            return ['status' => 5];
        }
        $track = (string) (263900000 + count($e['envios']) + 1);
        $e['envios'][$track] = ['huella' => hash('sha256', $sobre), 'informados' => $cantidad,
            'estado' => $e['modo'] === 'rechazar' || $errores !== [] ? 'RCT' : 'EPR', 'errores' => $errores,
            // Como en el SII real, los folios se buscan por canal (UPLOAD de certificación o REST de producción) y RUT emisor.
            'canal' => $canal, 'folios' => array_map(static fn (array $f): string => $f[1] . '-' . $f[2], $folios),
            'rut_sender' => ($_POST['rutSender'] ?? '') . '-' . ($_POST['dvSender'] ?? ''),
            'rut_company' => ($_POST['rutCompany'] ?? '') . '-' . ($_POST['dvCompany'] ?? '')];
        $perder = $e['modo'] === 'perder';
        if (in_array($e['modo'], ['perder', 'rechazar'], true)) {
            $e['modo'] = 'normal';
        }
        return ['status' => 0, 'track' => $track, 'perder' => $perder];
    });
}

function json(array $datos, int $codigo = 200): never
{
    http_response_code($codigo);
    header('Content-Type: application/json');
    echo json_encode($datos);
    exit;
}

if ($ruta === '/DTEWS/GetTokenFromSeed.jws') {
    $token = preg_match('#<pszXml><!\[CDATA\[(.*)\]\]></pszXml>#s', $cuerpo, $m) ? tokenParaSemilla($m[1]) : null;
    if ($token === null) {
        soap('getToken', '<SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema"><SII:RESP_HDR><ESTADO>-07</ESTADO>'
            . '<GLOSA>Firma de la semilla inválida</GLOSA></SII:RESP_HDR></SII:RESPUESTA>');
    }
    soap('getToken', '<SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema"><SII:RESP_BODY><TOKEN>' . $token
        . '</TOKEN></SII:RESP_BODY><SII:RESP_HDR><ESTADO>00</ESTADO></SII:RESP_HDR></SII:RESPUESTA>');
}

if ($ruta === '/cgi_dte/UPL/DTEUpload') {
    $resultado = recibirSobre('upload');
    if ($resultado['perder'] ?? false) {
        http_response_code(504);
        exit;
    }
    header('Content-Type: text/html; charset=ISO-8859-1');
    echo '<?xml version="1.0"?><RECEPCIONDTE><RUTSENDER>' . htmlspecialchars($_POST['rutSender'] ?? '') . '</RUTSENDER><STATUS>'
        . $resultado['status'] . '</STATUS>' . (isset($resultado['track']) ? '<TRACKID>' . $resultado['track'] . '</TRACKID>' : '')
        . '</RECEPCIONDTE>';
    exit;
}

// Canal REST de boletas: semilla, token, envío, estado por track y estado por folio.
if ($ruta === '/recursos/v1/boleta.electronica.semilla') {
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema"><SII:RESP_BODY><SEMILLA>'
        . sprintf('%012d', random_int(1, 999999999999)) . '</SEMILLA></SII:RESP_BODY><SII:RESP_HDR><ESTADO>00</ESTADO></SII:RESP_HDR></SII:RESPUESTA>';
    exit;
}
if ($ruta === '/recursos/v1/boleta.electronica.token') {
    $token = tokenParaSemilla($cuerpo);
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema">'
        . ($token === null ? '<SII:RESP_HDR><ESTADO>-07</ESTADO><GLOSA>Firma de la semilla inválida</GLOSA></SII:RESP_HDR>'
            : '<SII:RESP_BODY><TOKEN>' . $token . '</TOKEN></SII:RESP_BODY><SII:RESP_HDR><ESTADO>00</ESTADO></SII:RESP_HDR>')
        . '</SII:RESPUESTA>';
    exit;
}
if ($ruta === '/recursos/v1/boleta.electronica.envio' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $resultado = recibirSobre('rest');
    if ($resultado['perder'] ?? false) {
        http_response_code(504);
        exit;
    }
    if ($resultado['status'] !== 0) {
        json(['codigo' => 401, 'mensaje' => 'Token inválido'], 401);
    }
    json(['rut_emisor' => ($_POST['rutCompany'] ?? '') . '-' . ($_POST['dvCompany'] ?? ''), 'trackid' => (int) $resultado['track'],
        'fecha_recepcion' => gmdate('Y-m-d H:i:s'), 'estado' => 'REC', 'file' => 'envio.xml']);
}
$tokenRest = preg_match('/TOKEN=([A-Z0-9]+)/', $_SERVER['HTTP_COOKIE'] ?? '', $m) ? $m[1] : '';
if (preg_match('#^/recursos/v1/boleta\.electronica\.envio/\d+-[\dK]-(\d+)$#', $ruta, $t)) {
    $estado = estadoSimulado(static fn (array $e) => ['token' => in_array($tokenRest, $e['tokens'], true), 'envio' => $e['envios'][$t[1]] ?? null]);
    if (!$estado['token']) {
        json(['codigo' => 401, 'mensaje' => 'Token inválido'], 401);
    }
    if ($estado['envio'] === null) {
        json(['codigo' => 404, 'mensaje' => 'Track ID no encontrado'], 404);
    }
    $envio = $estado['envio'];
    $rechazados = $envio['estado'] === 'RCT' ? $envio['informados'] : 0;
    json(['trackid' => (int) $t[1], 'estado' => 'EPR', 'estadistica' => [['tipo' => 39, 'informados' => $envio['informados'],
        'aceptados' => $envio['informados'] - $rechazados, 'rechazados' => $rechazados, 'reparos' => 0]], 'detalle_rep_rech' => []]);
}
if (preg_match('#^/recursos/v1/boleta\.electronica/(\d+-[\dK])-(\d+)-(\d+)/estado$#', $ruta, $f)) {
    $estado = estadoSimulado(static function (array $e) use ($tokenRest, $f) {
        $conocido = null;
        foreach ($e['envios'] as $envio) {
            if (($envio['canal'] ?? '') === 'rest' && $envio['rut_company'] === $f[1] && in_array($f[2] . '-' . $f[3], $envio['folios'] ?? [], true)) {
                $conocido = $envio['estado'];
            }
        }
        return ['token' => in_array($tokenRest, $e['tokens'], true), 'estado' => $conocido];
    });
    if (!$estado['token']) {
        json(['codigo' => 401, 'mensaje' => 'Token inválido'], 401);
    }
    json($estado['estado'] === null ? ['estado' => 'FAU', 'descripcion' => 'Documento No Recibido por el SII']
        : ($estado['estado'] === 'RCT' ? ['estado' => 'RCH', 'descripcion' => 'Documento Rechazado']
            : ['estado' => 'DOK', 'descripcion' => 'Documento Recibido por el SII. Datos Coinciden con los Registrados']));
}

if ($ruta === '/DTEWS/QueryEstUp.jws') {
    preg_match('#<TrackId>(\d+)</TrackId>#', $cuerpo, $t);
    preg_match('#<Token>([A-Z0-9]+)</Token>#', $cuerpo, $k);
    $estado = estadoSimulado(static fn (array $e) => ['token' => in_array($k[1] ?? '', $e['tokens'], true),
        'envio' => $e['envios'][$t[1] ?? ''] ?? null]);
    if (!$estado['token'] || $estado['envio'] === null) {
        soap('getEstUp', '<SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema"><SII:RESP_HDR><ESTADO>-11</ESTADO>'
            . '<GLOSA>Track ID o token desconocido</GLOSA></SII:RESP_HDR></SII:RESPUESTA>');
    }
    $envio = $estado['envio'];
    $rechazados = $envio['estado'] === 'RCT' ? $envio['informados'] : 0;
    soap('getEstUp', '<SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema"><SII:RESP_HDR><TRACKID>' . $t[1] . '</TRACKID><ESTADO>'
        . $envio['estado'] . '</ESTADO><GLOSA>' . ($envio['estado'] === 'EPR' ? 'Envio Procesado' : 'Rechazado por Error en Schema o Firma')
        . '</GLOSA></SII:RESP_HDR><SII:RESP_BODY><INFORMADOS>' . $envio['informados'] . '</INFORMADOS><ACEPTADOS>'
        . ($envio['informados'] - $rechazados) . '</ACEPTADOS><RECHAZADOS>' . $rechazados . '</RECHAZADOS><REPAROS>0</REPAROS>'
        . '</SII:RESP_BODY></SII:RESPUESTA>');
}

http_response_code(404);
