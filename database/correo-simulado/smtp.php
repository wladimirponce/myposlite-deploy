<?php

declare(strict_types=1);

// Buzón de prueba (solo desarrollo y QA): servidor SMTP mínimo que guarda cada mensaje en /tmp/buzon como .eml.
// El modo de /tmp/buzon/.modo simula fallas: «caido» responde 451 al remitente (falla temporal) y «rechazar» 550.
const BUZON = '/tmp/buzon';
@mkdir(BUZON, 0777, true);
$servidor = stream_socket_server('tcp://0.0.0.0:2525', $errno, $error);
if ($servidor === false) {
    fwrite(STDERR, "correo-simulado: $error\n");
    exit(1);
}
while (true) {
    $cliente = @stream_socket_accept($servidor, -1);
    if ($cliente === false) {
        continue;
    }
    stream_set_timeout($cliente, 30);
    $decir = static fn (string $linea) => fwrite($cliente, $linea . "\r\n");
    $decir('220 correo-simulado ESMTP');
    $de = '';
    $para = [];
    while (($linea = fgets($cliente)) !== false) {
        $orden = strtoupper(substr(trim($linea), 0, 4));
        $modo = trim((string) @file_get_contents(BUZON . '/.modo')) ?: 'normal';
        if ($orden === 'EHLO' || $orden === 'HELO') {
            $decir('250-correo-simulado');
            $decir('250 AUTH LOGIN PLAIN');
        } elseif ($orden === 'AUTH') {
            $decir('235 Autenticado');
        } elseif ($orden === 'MAIL') {
            if ($modo === 'caido') {
                $decir('451 Servicio no disponible por ahora');
                continue;
            }
            if ($modo === 'rechazar') {
                $decir('550 Rechazado');
                continue;
            }
            $de = trim(substr(trim($linea), 10), '<> ');
            $para = [];
            $decir('250 OK');
        } elseif ($orden === 'RCPT') {
            $para[] = trim(substr(trim($linea), 8), '<> ');
            $decir('250 OK');
        } elseif ($orden === 'DATA') {
            $decir('354 Termine con un punto');
            $cuerpo = '';
            while (($dato = fgets($cliente)) !== false && rtrim($dato, "\r\n") !== '.') {
                $cuerpo .= str_starts_with($dato, '..') ? substr($dato, 1) : $dato;
            }
            $id = sprintf('%s-%s', microtime(true) * 1000 | 0, bin2hex(random_bytes(4)));
            file_put_contents(BUZON . "/$id.eml", "X-Simulado-De: $de\r\nX-Simulado-Para: " . implode(',', $para) . "\r\n" . $cuerpo);
            $decir('250 OK ' . $id);
        } elseif ($orden === 'RSET' || $orden === 'NOOP') {
            $decir('250 OK');
        } elseif ($orden === 'QUIT') {
            $decir('221 Adios');
            break;
        } else {
            $decir('502 No implementado');
        }
    }
    fclose($cliente);
}
