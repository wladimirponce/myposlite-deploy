<?php

declare(strict_types=1);

require_once __DIR__ . '/Almacenamiento.php';

// El XML es el DTE que el proveedor envía por correo de intercambio: se lee sin servicio externo.
const TIPOS_ARCHIVO_RECIBIDO = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'text/xml', 'application/xml'];
const MAXIMO_ARCHIVO_RECIBIDO = 10 * 1024 * 1024;
const DIAS_ARCHIVO_RECIBIDO = 365;

/**
 * Respalda en la nube una foto o PDF de un documento recibido, antes de cualquier lectura.
 *
 * El objeto se guarda con su huella SHA-256 como nombre y escritura condicional: subir el mismo archivo
 * otra vez no lo reemplaza y devuelve el registro original con duplicado en verdadero.
 * Primero se escribe en el almacenamiento y luego el registro: si el almacenamiento falla, no queda nada.
 *
 * @return array{id: string, duplicado: bool}
 */
function respaldarArchivoRecibido(PDO $db, string $empresa, string $local, string $usuario, array $archivo, ?string $dispositivo): array
{
    $error = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('El archivo supera los 10 MB.', 413);
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'] ?? '')) {
        throw new InvalidArgumentException('Adjunte una foto o un PDF.');
    }
    $tamano = (int) filesize($archivo['tmp_name']);
    if ($tamano > MAXIMO_ARCHIVO_RECIBIDO) {
        throw new RuntimeException('El archivo supera los 10 MB.', 413);
    }
    if ($tamano <= 0) {
        throw new InvalidArgumentException('El archivo está vacío.');
    }
    // El tipo se determina por el contenido, no por el nombre ni por lo que declara el navegador.
    $tipo = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
    if (!in_array($tipo, TIPOS_ARCHIVO_RECIBIDO, true)) {
        throw new InvalidArgumentException('Solo se aceptan PDF, XML del SII y fotos JPEG, PNG, WEBP o HEIC.');
    }
    $nombre = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($archivo['name'] ?? '')) ?? '');
    $nombre = mb_substr($nombre === '' ? 'documento' : $nombre, 0, 160);
    $dispositivo = $dispositivo === null ? null : mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $dispositivo) ?? ''), 0, 200);
    $contenido = file_get_contents($archivo['tmp_name']);
    $huella = hash('sha256', $contenido);

    $previo = $db->prepare('SELECT id FROM archivos_recibidos WHERE empresa_id = ? AND huella = ?');
    $previo->execute([$empresa, $huella]);
    if (($id = $previo->fetchColumn()) !== false) {
        return ['id' => (string) $id, 'duplicado' => true];
    }
    subirObjetoAlmacenamiento($empresa, $huella, $contenido, $tipo);
    try {
        $db->prepare('INSERT INTO archivos_recibidos
            (empresa_id, huella, tipo_mime, tamano, nombre, local_codigo, usuario_id, dispositivo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$empresa, $huella, $tipo, $tamano, $nombre, $local, $usuario, $dispositivo ?: null]);
    } catch (PDOException $error) {
        // Dos subidas simultáneas del mismo archivo: la segunda reconoce el registro de la primera.
        if ((int) ($error->errorInfo[1] ?? 0) !== 1062) {
            throw $error;
        }
        $previo->execute([$empresa, $huella]);
        return ['id' => (string) $previo->fetchColumn(), 'duplicado' => true];
    }
    return ['id' => $db->lastInsertId(), 'duplicado' => false];
}

/** Archivos de la empresa, sin documento o de un documento, más recientes primero. */
/**
 * Archivos de un documento o, sin documento, la cola de captura: los archivos del local todavía sin documento
 * ni descartados, con el estado de su última lectura, para revisarlos en otro equipo.
 */
function listarArchivosRecibidos(PDO $db, string $empresa, ?string $documento, ?string $local = null): array
{
    $filtro = $documento === null ? 'a.documento_id IS NULL AND a.descartado_en IS NULL AND (? IS NULL OR a.local_codigo = ?)' : 'a.documento_id = ?';
    $query = $db->prepare("SELECT a.id, a.nombre, a.tipo_mime, a.tamano, a.local_codigo, a.subido_en, a.documento_id,
            a.dispositivo, u.nombre AS usuario, a.subido_en > UTC_TIMESTAMP() - INTERVAL " . DIAS_ARCHIVO_RECIBIDO . " DAY AS disponible,
            l.id AS lectura_id, l.estado AS lectura_estado, l.error AS lectura_error
        FROM archivos_recibidos a JOIN usuarios u ON u.id = a.usuario_id
        LEFT JOIN lecturas_documento l ON l.empresa_id = a.empresa_id AND l.id =
            (SELECT MAX(x.id) FROM lecturas_documento x WHERE x.empresa_id = a.empresa_id AND x.archivo_id = a.id)
        WHERE a.empresa_id = ? AND $filtro ORDER BY a.id DESC LIMIT 50");
    $query->execute($documento === null ? [$empresa, $local, $local] : [$empresa, $documento]);
    $archivos = $query->fetchAll(PDO::FETCH_ASSOC);
    foreach ($archivos as &$archivo) {
        $archivo['id'] = (string) $archivo['id'];
        $archivo['lectura_id'] = $archivo['lectura_id'] === null ? null : (string) $archivo['lectura_id'];
        $archivo['documento_id'] = $archivo['documento_id'] === null ? null : (string) $archivo['documento_id'];
        $archivo['tamano'] = (int) $archivo['tamano'];
        $archivo['disponible'] = (bool) $archivo['disponible'];
    }
    unset($archivo);
    return $archivos;
}

/**
 * Saca un archivo de la cola sin borrarlo: sigue respaldado y conserva su registro, como exige la conservación.
 * Solo se descarta un archivo de la empresa todavía sin documento.
 */
function descartarArchivoRecibido(PDO $db, string $empresa, string $usuario, string $id): void
{
    $descartar = $db->prepare('UPDATE archivos_recibidos SET descartado_en = UTC_TIMESTAMP(6), descartado_por = ?
        WHERE empresa_id = ? AND id = ? AND documento_id IS NULL AND descartado_en IS NULL');
    $descartar->execute([$usuario, $empresa, $id]);
    if ($descartar->rowCount() === 1) {
        return;
    }
    $existe = $db->prepare('SELECT 1 FROM archivos_recibidos WHERE empresa_id = ? AND id = ?');
    $existe->execute([$empresa, $id]);
    throw $existe->fetchColumn() === false
        ? new RuntimeException('Archivo no encontrado.', 404)
        : new RuntimeException('El archivo ya está adjunto a un documento o fue descartado.', 409);
}

/** Enlace de lectura de 5 minutos, solo para un archivo de la empresa activa todavía conservado. */
function enlaceArchivoRecibido(PDO $db, string $empresa, string $id): array
{
    $query = $db->prepare('SELECT huella, tipo_mime, subido_en > UTC_TIMESTAMP() - INTERVAL ' . DIAS_ARCHIVO_RECIBIDO . ' DAY AS disponible
        FROM archivos_recibidos WHERE empresa_id = ? AND id = ?');
    $query->execute([$empresa, $id]);
    $archivo = $query->fetch(PDO::FETCH_ASSOC);
    if (!$archivo) {
        throw new RuntimeException('Archivo no encontrado.', 404);
    }
    if (!$archivo['disponible']) {
        throw new RuntimeException('El archivo superó el año de conservación y ya no está disponible.', 410);
    }
    return ['url' => enlaceAlmacenamiento($empresa, $archivo['huella'], $archivo['tipo_mime'], 300), 'expiraEn' => 300];
}

/** Asocia archivos sin documento al documento recién creado. Requiere la transacción del documento. */
function adjuntarArchivosRecibidos(PDO $db, string $empresa, string $documento, mixed $archivos): void
{
    if ($archivos === null || $archivos === []) {
        return;
    }
    if (!is_array($archivos) || !array_is_list($archivos) || count($archivos) > 20
        || count(array_unique($archivos)) !== count($archivos)) {
        throw new InvalidArgumentException('Adjunte hasta 20 archivos distintos.');
    }
    foreach ($archivos as $archivo) {
        if (!is_string($archivo) || !preg_match('/^[1-9][0-9]{0,19}$/D', $archivo)) {
            throw new InvalidArgumentException('Archivo adjunto inválido.');
        }
    }
    $marcas = implode(',', array_fill(0, count($archivos), '?'));
    $adjuntar = $db->prepare("UPDATE archivos_recibidos SET documento_id = ?
        WHERE empresa_id = ? AND documento_id IS NULL AND id IN ($marcas)");
    $adjuntar->execute([$documento, $empresa, ...$archivos]);
    if ($adjuntar->rowCount() !== count($archivos)) {
        throw new RuntimeException('Un archivo ya está adjunto a otro documento o no pertenece a la empresa.', 409);
    }
}
