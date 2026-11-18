<?php

declare(strict_types=1);

/**
 * Cliente mínimo de MinIO (API S3 con firma SigV4) para el respaldo de archivos de ingreso.
 *
 * Cada empresa tiene su bucket lite-recepcion-<id> y su usuario lite-empresa-<id>, limitado a leer y crear
 * objetos en ese bucket. La contraseña de cada empresa se deriva de MYPOSLITE_STORAGE_SECRET con HMAC:
 * el backend no guarda secretos por empresa y MinIO niega el acceso cruzado aunque la aplicación se equivoque.
 * La misma derivación se usa al aprovisionar (backend/bin/clave-almacenamiento.php).
 */
final class AlmacenamientoNoDisponible extends RuntimeException
{
}

function credencialAlmacenamiento(string $empresa): array
{
    $secreto = getenv('MYPOSLITE_STORAGE_SECRET');
    if (!$secreto || strlen($secreto) < 32 || !preg_match('/^[1-9][0-9]{0,19}$/D', $empresa)) {
        throw new AlmacenamientoNoDisponible('El respaldo de archivos no está configurado.');
    }
    // MinIO acepta claves secretas de hasta 40 caracteres.
    return ["lite-empresa-$empresa", substr(hash_hmac('sha256', "lite-empresa-$empresa", $secreto), 0, 40)];
}

function bucketAlmacenamiento(string $empresa): string
{
    return "lite-recepcion-$empresa";
}

/** Firma SigV4 para S3. Devuelve la firma y el alcance de la credencial. */
function firmarAlmacenamiento(string $clave, string $fecha, string $canonica): array
{
    $dia = substr($fecha, 0, 8);
    $alcance = "$dia/us-east-1/s3/aws4_request";
    $texto = "AWS4-HMAC-SHA256\n$fecha\n$alcance\n" . hash('sha256', $canonica);
    $llave = hash_hmac('sha256', 'aws4_request',
        hash_hmac('sha256', 's3', hash_hmac('sha256', 'us-east-1', hash_hmac('sha256', $dia, "AWS4$clave", true), true), true), true);
    return [hash_hmac('sha256', $texto, $llave), $alcance];
}

/**
 * Crea el objeto solo si la clave no existe (If-None-Match: *). Nunca reemplaza.
 * Devuelve true si lo creó y false si ya existía.
 */
function subirObjetoAlmacenamiento(string $empresa, string $objeto, string $contenido, string $tipo): bool
{
    [$usuario, $clave] = credencialAlmacenamiento($empresa);
    $base = rtrim((string) getenv('MYPOSLITE_STORAGE_ENDPOINT'), '/');
    $host = parse_url($base, PHP_URL_HOST) . (parse_url($base, PHP_URL_PORT) ? ':' . parse_url($base, PHP_URL_PORT) : '');
    $ruta = '/' . bucketAlmacenamiento($empresa) . '/' . rawurlencode($objeto);
    $fecha = gmdate('Ymd\THis\Z');
    $huella = hash('sha256', $contenido);
    $encabezados = ['content-type' => $tipo, 'host' => $host, 'if-none-match' => '*',
        'x-amz-content-sha256' => $huella, 'x-amz-date' => $fecha];
    $nombres = implode(';', array_keys($encabezados));
    $canonica = "PUT\n$ruta\n\n" . implode('', array_map(static fn($k, $v) => "$k:$v\n", array_keys($encabezados), $encabezados))
        . "\n$nombres\n$huella";
    [$firma, $alcance] = firmarAlmacenamiento($clave, $fecha, $canonica);
    $lineas = ["Authorization: AWS4-HMAC-SHA256 Credential=$usuario/$alcance, SignedHeaders=$nombres, Signature=$firma"];
    foreach ($encabezados as $nombre => $valor) {
        if ($nombre !== 'host') {
            $lineas[] = "$nombre: $valor";
        }
    }
    // curl envía exactamente los encabezados firmados; el envoltorio http de PHP agrega los suyos.
    $lineas[] = 'Expect:';
    $peticion = curl_init($base . $ruta);
    curl_setopt_array($peticion, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $contenido, CURLOPT_HTTPHEADER => $lineas,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30]);
    $respuesta = curl_exec($peticion);
    $estado = (int) curl_getinfo($peticion, CURLINFO_RESPONSE_CODE);
    if ($estado === 200) {
        return true;
    }
    if ($estado === 412) {
        return false;
    }
    error_log('MyPOS Lite almacenamiento: PUT ' . $estado . ' ' . substr((string) $respuesta, 0, 200));
    throw new AlmacenamientoNoDisponible($estado === 0
        ? 'No se pudo conectar con el respaldo de archivos.'
        : 'El respaldo de archivos de esta empresa no está aprovisionado o rechazó el archivo.');
}

/** Descarga un objeto desde la red interna, para entregarlo al servicio de lectura. */
function obtenerObjetoAlmacenamiento(string $empresa, string $objeto): string
{
    $url = enlaceAlmacenamiento($empresa, $objeto, 'application/octet-stream', 60, (string) getenv('MYPOSLITE_STORAGE_ENDPOINT'));
    $peticion = curl_init($url);
    curl_setopt_array($peticion, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30]);
    $contenido = curl_exec($peticion);
    $estado = (int) curl_getinfo($peticion, CURLINFO_RESPONSE_CODE);
    if ($estado !== 200 || !is_string($contenido)) {
        error_log('MyPOS Lite almacenamiento: GET ' . $estado);
        throw new AlmacenamientoNoDisponible('No se pudo recuperar el archivo respaldado.');
    }
    return $contenido;
}

/** Enlace temporal de lectura, firmado para el host público del almacenamiento. */
function enlaceAlmacenamiento(string $empresa, string $objeto, string $tipo, int $segundos = 300, ?string $base = null): string
{
    [$usuario, $clave] = credencialAlmacenamiento($empresa);
    $base = rtrim($base ?? (string) getenv('MYPOSLITE_STORAGE_PUBLIC_URL'), '/');
    $host = parse_url($base, PHP_URL_HOST) . (parse_url($base, PHP_URL_PORT) ? ':' . parse_url($base, PHP_URL_PORT) : '');
    $ruta = '/' . bucketAlmacenamiento($empresa) . '/' . rawurlencode($objeto);
    $fecha = gmdate('Ymd\THis\Z');
    $alcance = substr($fecha, 0, 8) . '/us-east-1/s3/aws4_request';
    $parametros = ['X-Amz-Algorithm' => 'AWS4-HMAC-SHA256', 'X-Amz-Credential' => "$usuario/$alcance", 'X-Amz-Date' => $fecha,
        'X-Amz-Expires' => (string) $segundos, 'X-Amz-SignedHeaders' => 'host', 'response-content-type' => $tipo];
    ksort($parametros);
    $consulta = implode('&', array_map(static fn($k, $v) => rawurlencode($k) . '=' . rawurlencode($v), array_keys($parametros), $parametros));
    [$firma] = firmarAlmacenamiento($clave, $fecha, "GET\n$ruta\n$consulta\nhost:$host\n\nhost\nUNSIGNED-PAYLOAD");
    return "$base$ruta?$consulta&X-Amz-Signature=$firma";
}
