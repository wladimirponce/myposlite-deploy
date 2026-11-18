<?php

declare(strict_types=1);

require_once __DIR__ . '/Emision.php';

/**
 * Correos salientes de Lite por SMTP (MYPOSLITE_SMTP_*), con cola en correo_envios.
 *
 * - Cada correo tiene una clave por empresa: encolar dos veces el mismo aviso no lo duplica.
 * - Antes de enviar, el proceso toma la fila (proximo_intento al futuro, solo si nadie la tomó): dos procesos no envían
 *   el mismo correo. Un fallo temporal reintenta con espera creciente; tras 8 intentos queda «fallido».
 * - Los avisos a la empresa (trámite y cierre) solo salen a destinatarios verificados: los demás esperan la verificación.
 * - Los documentos al receptor llevan el EnvioDTE de intercambio (RutReceptor del receptor) y su PDF.
 */
final class CorreoNoDisponible extends RuntimeException
{
}

function configuracionCorreo(): array
{
    $host = (string) getenv('MYPOSLITE_SMTP_HOST');
    if ($host === '') {
        throw new CorreoNoDisponible('El envío de correo no está configurado.');
    }
    return ['host' => $host, 'puerto' => (int) (getenv('MYPOSLITE_SMTP_PORT') ?: 587), 'usuario' => (string) getenv('MYPOSLITE_SMTP_USER'),
        'clave' => (string) getenv('MYPOSLITE_SMTP_PASSWORD'), 'tls' => (string) (getenv('MYPOSLITE_SMTP_TLS') ?: 'starttls'),
        'remitente' => (string) (getenv('MYPOSLITE_SMTP_FROM') ?: 'no-responder@myposlite.cl')];
}

/** Encola un correo. Devuelve false si ya existía uno con la misma clave. */
function encolarCorreo(PDO $db, string $empresa, string $tipo, string $destinatario, string $asunto, string $cuerpo, string $clave,
    ?string $documento = null): bool
{
    $insertar = $db->prepare('INSERT IGNORE INTO correo_envios (empresa_id, tipo, destinatario, asunto, cuerpo, documento_id, clave)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insertar->execute([$empresa, $tipo, strtolower(trim($destinatario)), mb_substr($asunto, 0, 200), $cuerpo, $documento, mb_substr($clave, 0, 160)]);
    return $insertar->rowCount() === 1;
}

/** Aviso a todos los destinatarios de la empresa; los no verificados lo reciben al verificarse. */
function avisarEmpresaCorreo(PDO $db, string $empresa, string $tipo, string $asunto, string $cuerpo, string $clave): int
{
    $destinatarios = $db->prepare('SELECT correo FROM destinatarios_cierre WHERE empresa_id = ?');
    $destinatarios->execute([$empresa]);
    $encolados = 0;
    foreach ($destinatarios->fetchAll(PDO::FETCH_COLUMN) as $correo) {
        $encolados += (int) encolarCorreo($db, $empresa, $tipo, $correo, $asunto, $cuerpo, $clave . ':' . $correo);
    }
    return $encolados;
}

/** Pide la verificación de un destinatario con un enlace válido por 48 horas. */
function solicitarVerificacionCorreo(PDO $db, string $empresa, string $correo): void
{
    $correo = strtolower(trim($correo));
    $existe = $db->prepare('SELECT verificado_en FROM destinatarios_cierre WHERE empresa_id = ? AND correo = ?');
    $existe->execute([$empresa, $correo]);
    $fila = $existe->fetch(PDO::FETCH_ASSOC);
    if ($fila === false) {
        throw new InvalidArgumentException('Ese correo no está entre los destinatarios de la empresa.');
    }
    if ($fila['verificado_en'] !== null) {
        throw new RuntimeException('Ese correo ya está verificado.', 409);
    }
    $recientes = $db->prepare('SELECT COUNT(*) FROM correo_verificaciones WHERE empresa_id = ? AND correo = ? AND creado_en > UTC_TIMESTAMP(6) - INTERVAL 1 HOUR');
    $recientes->execute([$empresa, $correo]);
    if ((int) $recientes->fetchColumn() >= 3) {
        throw new RuntimeException('Ya se enviaron tres verificaciones en la última hora: revise la bandeja de entrada y el correo no deseado.', 409);
    }
    $token = bin2hex(random_bytes(32));
    $razon = $db->prepare('SELECT razon_social FROM empresas WHERE id = ?');
    $razon->execute([$empresa]);
    $nombre = (string) $razon->fetchColumn();
    $db->prepare('INSERT INTO correo_verificaciones (token_hash, empresa_id, correo, vence_en) VALUES (?, ?, ?, UTC_TIMESTAMP(6) + INTERVAL 48 HOUR)')
        ->execute([hash('sha256', $token), $empresa, $correo]);
    $enlace = rtrim((string) (getenv('MYPOSLITE_PUBLIC_URL') ?: 'http://127.0.0.1:5177'), '/') . '/api/verificar-correo.php?token=' . $token;
    encolarCorreo($db, $empresa, 'verificacion', $correo, 'Confirme su correo para los avisos de ' . $nombre,
        "Para recibir los cierres diarios y avisos de MyPOS Lite de " . $nombre . ", abra este enlace dentro de 48 horas:\n\n$enlace\n\n"
        . "Si no esperaba este correo, ignórelo.", 'verificacion:' . $correo . ':' . substr($token, 0, 16));
}

/** Confirma un destinatario con el token del enlace. Devuelve la empresa y el correo. */
function confirmarVerificacionCorreo(PDO $db, string $token): array
{
    if (!preg_match('/^[0-9a-f]{64}$/D', $token)) {
        throw new InvalidArgumentException('El enlace no es válido.');
    }
    $db->beginTransaction();
    try {
        $buscar = $db->prepare('SELECT v.empresa_id, v.correo, e.razon_social FROM correo_verificaciones v JOIN empresas e ON e.id = v.empresa_id
            WHERE v.token_hash = ? AND v.usado_en IS NULL AND v.vence_en > UTC_TIMESTAMP(6) FOR UPDATE');
        $buscar->execute([hash('sha256', $token)]);
        $fila = $buscar->fetch(PDO::FETCH_ASSOC);
        if (!$fila) {
            throw new RuntimeException('El enlace venció o ya se usó. Pida otro desde Lite.', 404);
        }
        $db->prepare('UPDATE correo_verificaciones SET usado_en = UTC_TIMESTAMP(6) WHERE token_hash = ?')->execute([hash('sha256', $token)]);
        $db->prepare('UPDATE destinatarios_cierre SET verificado_en = UTC_TIMESTAMP(6) WHERE empresa_id = ? AND correo = ? AND verificado_en IS NULL')
            ->execute([$fila['empresa_id'], $fila['correo']]);
        // Los avisos que esperaban la verificación salen en la próxima pasada.
        $db->prepare("UPDATE correo_envios SET proximo_intento = UTC_TIMESTAMP(6) WHERE empresa_id = ? AND destinatario = ? AND estado = 'pendiente'")
            ->execute([$fila['empresa_id'], $fila['correo']]);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    return ['empresa' => $fila['razon_social'], 'correo' => $fila['correo']];
}

/** Arma el mensaje MIME: texto en UTF-8 y adjuntos en base64. */
function mensajeCorreo(string $remitente, string $destinatario, string $asunto, string $texto, array $adjuntos): string
{
    $limite = 'lite-' . bin2hex(random_bytes(12));
    $cabeceras = 'From: MyPOS Lite <' . $remitente . ">\r\nTo: <" . $destinatario . ">\r\nSubject: " . mb_encode_mimeheader($asunto, 'UTF-8', 'B', "\r\n")
        . "\r\nDate: " . gmdate('D, d M Y H:i:s') . " +0000\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . '@myposlite>' . "\r\nMIME-Version: 1.0\r\n";
    $cuerpoTexto = "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texto));
    if ($adjuntos === []) {
        return $cabeceras . $cuerpoTexto;
    }
    $partes = "--$limite\r\n" . $cuerpoTexto;
    foreach ($adjuntos as $adjunto) {
        $partes .= "--$limite\r\nContent-Type: " . $adjunto['tipo'] . '; name="' . $adjunto['nombre'] . "\"\r\nContent-Transfer-Encoding: base64\r\n"
            . 'Content-Disposition: attachment; filename="' . $adjunto['nombre'] . "\"\r\n\r\n" . chunk_split(base64_encode($adjunto['contenido']));
    }
    return $cabeceras . "Content-Type: multipart/mixed; boundary=\"$limite\"\r\n\r\n" . $partes . "--$limite--\r\n";
}

/** Entrega por SMTP. Lanza RuntimeException con código 4xx (temporal) o 5xx (definitivo). */
function enviarSmtp(array $config, string $destinatario, string $mensaje): void
{
    $contexto = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $esquema = $config['tls'] === 'tls' ? 'ssl://' : 'tcp://';
    $socket = @stream_socket_client($esquema . $config['host'] . ':' . $config['puerto'], $errno, $error, 15, STREAM_CLIENT_CONNECT, $contexto);
    if ($socket === false) {
        throw new RuntimeException('No se pudo conectar al servidor de correo.', 421);
    }
    stream_set_timeout($socket, 30);
    $leer = static function () use ($socket): array {
        $texto = '';
        while (($linea = fgets($socket)) !== false) {
            $texto .= $linea;
            if (strlen($linea) < 4 || $linea[3] !== '-') {
                break;
            }
        }
        return [(int) substr($texto, 0, 3), trim($texto)];
    };
    $orden = static function (string $linea, array $esperados) use ($socket, $leer): void {
        fwrite($socket, $linea . "\r\n");
        [$codigo, $texto] = $leer();
        if (!in_array($codigo, $esperados, true)) {
            throw new RuntimeException(mb_substr($texto, 0, 250), $codigo ?: 421);
        }
    };
    try {
        [$codigo] = $leer();
        if ($codigo !== 220) {
            throw new RuntimeException('El servidor de correo no respondió.', 421);
        }
        $orden('EHLO myposlite', [250]);
        if ($config['tls'] === 'starttls') {
            $orden('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('No se pudo cifrar la conexión con el servidor de correo.', 421);
            }
            $orden('EHLO myposlite', [250]);
        }
        if ($config['usuario'] !== '') {
            $orden('AUTH LOGIN', [334]);
            $orden(base64_encode($config['usuario']), [334]);
            $orden(base64_encode($config['clave']), [235]);
        }
        $orden('MAIL FROM:<' . $config['remitente'] . '>', [250]);
        $orden('RCPT TO:<' . $destinatario . '>', [250, 251]);
        $orden('DATA', [354]);
        // Transparencia SMTP: una línea que empieza con punto se duplica.
        $orden(preg_replace('/^\./m', '..', $mensaje) . "\r\n.", [250]);
        fwrite($socket, "QUIT\r\n");
    } finally {
        fclose($socket);
    }
}

/** Adjuntos de un documento para el receptor: EnvioDTE de intercambio firmado y su PDF. */
function adjuntosDocumentoCorreo(PDO $db, string $empresa, string $documento): array
{
    $query = $db->prepare('SELECT tipo, folio, xml FROM dte_documentos WHERE empresa_id = ? AND id = ?');
    $query->execute([$empresa, $documento]);
    $fila = $query->fetch(PDO::FETCH_ASSOC);
    $nombre = 'DTE-' . $fila['tipo'] . '-' . $fila['folio'];
    return [['nombre' => $nombre . '.xml', 'tipo' => 'application/xml', 'contenido' => sobreReceptorDte($db, $empresa, $documento)],
        ['nombre' => $nombre . '.pdf', 'tipo' => 'application/pdf', 'contenido' => representacionDte($db, $empresa, $documento, 'tributaria', 'carta')['pdf']]];
}

/**
 * Entrega los correos pendientes. Llamado por el programador cada minuto y por el QA.
 *
 * @return array{enviados: int, reintentos: int, fallidos: int, esperando: int}
 */
function entregarCorreosPendientes(PDO $db, int $limite = 20): array
{
    $resumen = ['enviados' => 0, 'reintentos' => 0, 'fallidos' => 0, 'esperando' => 0];
    try {
        $config = configuracionCorreo();
    } catch (CorreoNoDisponible) {
        return $resumen;
    }
    $pendientes = $db->query("SELECT c.id, c.empresa_id, c.tipo, c.destinatario, c.asunto, c.cuerpo, c.documento_id, c.intentos,
            d.verificado_en IS NOT NULL AS verificado
        FROM correo_envios c LEFT JOIN destinatarios_cierre d ON d.empresa_id = c.empresa_id AND d.correo = c.destinatario
        WHERE c.estado = 'pendiente' AND c.proximo_intento <= UTC_TIMESTAMP(6) ORDER BY c.id LIMIT " . max(1, min(100, $limite)))->fetchAll(PDO::FETCH_ASSOC);
    $tomar = $db->prepare("UPDATE correo_envios SET proximo_intento = UTC_TIMESTAMP(6) + INTERVAL 10 MINUTE
        WHERE id = ? AND estado = 'pendiente' AND proximo_intento <= UTC_TIMESTAMP(6)");
    foreach ($pendientes as $correo) {
        if (in_array($correo['tipo'], ['tramite', 'cierre'], true) && !$correo['verificado']) {
            // Espera la verificación del destinatario; la confirmación lo reactiva.
            $db->prepare('UPDATE correo_envios SET proximo_intento = UTC_TIMESTAMP(6) + INTERVAL 1 DAY WHERE id = ?')->execute([$correo['id']]);
            $resumen['esperando']++;
            continue;
        }
        $tomar->execute([$correo['id']]);
        if ($tomar->rowCount() !== 1) {
            continue;
        }
        try {
            $adjuntos = $correo['documento_id'] !== null ? adjuntosDocumentoCorreo($db, (string) $correo['empresa_id'], (string) $correo['documento_id']) : [];
            enviarSmtp($config, $correo['destinatario'], mensajeCorreo($config['remitente'], $correo['destinatario'], $correo['asunto'], $correo['cuerpo'], $adjuntos));
            $db->prepare("UPDATE correo_envios SET estado = 'enviado', enviado_en = UTC_TIMESTAMP(6), intentos = intentos + 1, ultimo_error = NULL WHERE id = ?")
                ->execute([$correo['id']]);
            $resumen['enviados']++;
        } catch (Throwable $error) {
            $intentos = (int) $correo['intentos'] + 1;
            $definitivo = ($error->getCode() >= 500 && $error->getCode() < 600) || $intentos >= 8;
            $db->prepare("UPDATE correo_envios SET estado = ?, intentos = ?, ultimo_error = ?,
                proximo_intento = UTC_TIMESTAMP(6) + INTERVAL ? MINUTE WHERE id = ?")
                ->execute([$definitivo ? 'fallido' : 'pendiente', $intentos, mb_substr($error->getMessage(), 0, 300), min(60, 2 ** $intentos), $correo['id']]);
            $resumen[$definitivo ? 'fallidos' : 'reintentos']++;
        }
    }
    return $resumen;
}

/**
 * Avisos del trámite tributario: el SII respondió un envío de certificación, o el expediente lleva cinco días sin
 * movimiento. Un aviso por respuesta y uno por semana de detención.
 */
function avisarRespuestaTramiteCorreo(PDO $db, string $empresa, string $envio, string $motivo, string $estado, ?string $glosa): void
{
    if (!in_array($motivo, ['set_boletas', 'rcof_set'], true) || !in_array($estado, ['aceptado', 'reparo', 'rechazado'], true)) {
        return;
    }
    $que = $motivo === 'set_boletas' ? 'el set de pruebas de boletas' : 'el RCOF del set de boletas';
    $resultado = ['aceptado' => 'lo aceptó', 'reparo' => 'lo aceptó con reparos', 'rechazado' => 'lo rechazó'][$estado];
    avisarEmpresaCorreo($db, $empresa, 'tramite', "El SII respondió $que", "El SII $resultado." . ($glosa ? "\nDetalle del SII: $glosa" : '')
        . "\n\nRevise el siguiente paso en Lite, en Documentos tributarios electrónicos.", "tramite:envio:$envio:$estado");
}

function avisarTramitesDetenidosCorreo(PDO $db): int
{
    $detenidas = $db->query("SELECT h.empresa_id, MAX(GREATEST(h.actualizado_en, COALESCE(e.ultimo, h.actualizado_en))) AS ultimo
        FROM dte_habilitaciones h
        LEFT JOIN (SELECT empresa_id, MAX(actualizado_en) AS ultimo FROM dte_envios WHERE ambiente = 'certificacion' GROUP BY empresa_id) e
          ON e.empresa_id = h.empresa_id
        WHERE h.estado IN ('en_certificacion', 'visto_bueno')
        GROUP BY h.empresa_id HAVING ultimo < UTC_TIMESTAMP(6) - INTERVAL 5 DAY")->fetchAll(PDO::FETCH_ASSOC);
    $avisos = 0;
    foreach ($detenidas as $fila) {
        $avisos += avisarEmpresaCorreo($db, (string) $fila['empresa_id'], 'tramite', 'Su trámite de documentos tributarios está detenido',
            'El trámite con el SII no tiene movimiento desde el ' . substr((string) $fila['ultimo'], 0, 10) . ".\n\nAbra Lite, en Documentos tributarios "
            . 'electrónicos, para ver el paso que falta, o pida ayuda a soporte desde ese mismo paso.', 'tramite:detenido:' . gmdate('o-W'));
    }
    return $avisos;
}

/** Al quedar aceptado por el SII, cada factura, guía o nota va al correo de intercambio del receptor. */
function encolarDocumentosReceptorCorreo(PDO $db, string $empresa, string $envio): int
{
    $documentos = $db->prepare("SELECT d.id, d.tipo, d.folio, c.correo, c.razon_social, e.razon_social AS emisor FROM dte_envio_documentos x
        JOIN dte_documentos d ON d.empresa_id = x.empresa_id AND d.id = x.documento_id
        JOIN contactos c ON c.empresa_id = d.empresa_id AND c.id = d.receptor_id
        JOIN empresas e ON e.id = d.empresa_id
        WHERE x.empresa_id = ? AND x.envio_id = ? AND d.ambiente = 'produccion' AND d.tipo IN (33, 34, 52, 56, 61)
          AND d.estado IN ('aceptado', 'reparo') AND c.correo IS NOT NULL AND c.correo <> ''");
    $documentos->execute([$empresa, $envio]);
    $encolados = 0;
    foreach ($documentos->fetchAll(PDO::FETCH_ASSOC) as $doc) {
        $nombre = \MyposLite\Dte\RepresentacionPdf::NOMBRES[(int) $doc['tipo']] ?? 'DTE';
        $encolados += (int) encolarCorreo($db, $empresa, 'documento', $doc['correo'], mb_convert_case($nombre, MB_CASE_TITLE) . ' N° ' . $doc['folio']
            . ' de ' . $doc['emisor'], "Adjuntamos el documento tributario electrónico N° {$doc['folio']} emitido por {$doc['emisor']} a {$doc['razon_social']}"
            . ", en XML de intercambio y en PDF.", 'documento:' . $doc['id'], (string) $doc['id']);
    }
    return $encolados;
}
