<?php

declare(strict_types=1);

/*
 * Insumos ficticios para el QA del motor DTE, mientras Lite no tenga RUT de certificación, certificado ni CAF reales.
 * Nada de esto sirve ante el SII: el «SII» que firma los CAF es una clave generada en cada llamada.
 *
 *   php database/dte-ficticio.php certificado RUT CLAVE         → .pfx en base64 con el RUT del titular
 *   php database/dte-ficticio.php caf RUT TIPO DESDE HASTA IDK [danado] → XML del CAF
 *   php database/dte-ficticio.php validar < sobre.xml            → JSON con errores de esquema, firmas y timbres
 */

require __DIR__ . '/../backend/src/Emision.php';

use MyposLite\Dte\ValidadorEsquema;

/** Clave pública RSA desde módulo y exponente: PHP no la arma sola, así que se codifica el SubjectPublicKeyInfo en DER. */
function clavePublicaRsa(string $n, string $e): OpenSSLAsymmetricKey|false
{
    $largo = static function (int $l): string {
        if ($l < 128) {
            return chr($l);
        }
        $bytes = ltrim(pack('N', $l), "\0");
        return chr(0x80 | strlen($bytes)) . $bytes;
    };
    $entero = static function (string $v) use ($largo): string {
        $v = ltrim($v, "\0");
        if ($v === '' || ord($v[0]) > 0x7f) {
            $v = "\0" . $v;
        }
        return "\x02" . $largo(strlen($v)) . $v;
    };
    $secuencia = static fn (string $v): string => "\x30" . $largo(strlen($v)) . $v;
    $rsa = $secuencia($entero($n) . $entero($e));
    $algoritmo = $secuencia("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
    $der = $secuencia($algoritmo . "\x03" . $largo(strlen($rsa) + 1) . "\0" . $rsa);
    return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
}

$modo = $argv[1] ?? '';
$rsa = ['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

if ($modo === 'certificado') {
    [$rut, $clave] = [$argv[2] ?? '', $argv[3] ?? ''];
    // OPENSSL_CONF apunta a la configuración legacy del motor, que no tiene la sección [req] que exige una solicitud.
    $configuracion = tempnam(sys_get_temp_dir(), 'qa-openssl');
    file_put_contents($configuracion, "[req]\ndistinguished_name = dn\n[dn]\n");
    $opciones = ['config' => $configuracion, 'digest_alg' => 'sha256'];
    $llave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $opciones);
    $solicitud = openssl_csr_new(['commonName' => 'Firmante QA Lite', 'serialNumber' => $rut], $llave, $opciones);
    // Vigencia en días (30 por omisión); el QA de alertas pide certificados por vencer.
    $certificado = openssl_csr_sign($solicitud, null, $llave, (int) ($argv[4] ?? 30), $opciones);
    unlink($configuracion);
    openssl_pkcs12_export($certificado, $pfx, $llave, $clave);
    echo base64_encode($pfx);
    exit;
}

if ($modo === 'caf') {
    // Argumentos: RUT TIPO DESDE HASTA IDK [danado|-] [FECHA_AUTORIZACION].
    [$rut, $tipo, $desde, $hasta, $idk] = array_slice($argv, 2, 5);
    $rango = openssl_pkey_new($rsa);
    $detalles = openssl_pkey_get_details($rango);
    // Un CAF dañado trae una clave privada que no corresponde a su clave pública.
    openssl_pkey_export(($argv[7] ?? '') === 'danado' ? openssl_pkey_new($rsa) : $rango, $privada);
    $da = '<DA><RE>' . $rut . '</RE><RS>EMPRESA QA LITE</RS><TD>' . $tipo . '</TD><RNG><D>' . $desde . '</D><H>' . $hasta
        . '</H></RNG><FA>' . ($argv[8] ?? '2026-11-05') . '</FA><RSAPK><M>' . base64_encode($detalles['rsa']['n']) . '</M><E>'
        . base64_encode($detalles['rsa']['e']) . '</E></RSAPK><IDK>' . $idk . '</IDK></DA>';
    openssl_sign($da, $firma, openssl_pkey_new($rsa), OPENSSL_ALGO_SHA1);
    echo '<?xml version="1.0"?>' . "\n" . '<AUTORIZACION><CAF version="1.0">' . $da . '<FRMA algoritmo="SHA1withRSA">'
        . base64_encode($firma) . "</FRMA></CAF>\n<RSASK>" . $privada . "</RSASK>\n<RSAPUBK>" . $detalles['key'] . "</RSAPUBK>\n</AUTORIZACION>\n";
    exit;
}

if ($modo === 'validar') {
    $sobre = stream_get_contents(STDIN);
    $errores = ValidadorEsquema::validarSobreBoletas($sobre);
    // Cada timbre debe validar con la clave pública del CAF que lleva dentro, sobre su DD en ISO-8859-1.
    preg_match_all('#<TED version="1.0">(<DD>.*?</DD>)<FRMT algoritmo="SHA1withRSA">([^<]+)</FRMT>#s', $sobre, $teds, PREG_SET_ORDER);
    foreach ($teds as $i => [, $dd, $frmt]) {
        preg_match('#<M>([^<]+)</M><E>([^<]+)</E>#', $dd, $clave);
        $publica = clavePublicaRsa(base64_decode($clave[1]), base64_decode($clave[2]));
        // El sobre ya está en ISO-8859-1: el DD se verifica con esos mismos bytes.
        if ($publica === false || openssl_verify($dd, base64_decode($frmt), $publica, OPENSSL_ALGO_SHA1) !== 1) {
            $errores[] = 'Timbre ' . ($i + 1) . ': no valida con la clave del CAF.';
        }
    }
    echo json_encode(['errores' => $errores, 'timbres' => count($teds)], JSON_UNESCAPED_UNICODE);
    exit;
}

fwrite(STDERR, "Modo desconocido.\n");
exit(1);
