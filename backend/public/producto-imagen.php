<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';

$dir = __DIR__ . '/../../storage/productos';

function responder(int $status, array $body): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// GET: servir imagen de producto almacenada
if ($method === 'GET') {
    $archivo = isset($_GET['f']) && is_string($_GET['f']) ? basename($_GET['f']) : '';
    if (!preg_match('/^[1-9][0-9]*_[a-f0-9]{64}\.(jpg|png|webp)$/D', $archivo)) {
        http_response_code(404);
        exit;
    }
    $ruta = $dir . '/' . $archivo;
    if (!is_file($ruta)) {
        http_response_code(404);
        exit;
    }
    $ext = pathinfo($archivo, PATHINFO_EXTENSION);
    $mime = match ($ext) {
        'png' => 'image/png',
        'webp' => 'image/webp',
        default => 'image/jpeg',
    };
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . (string) filesize($ruta));
    readfile($ruta);
    exit;
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    responder(405, ['error' => 'Método no disponible.']);
}

if (($_SERVER['HTTP_X_MYPOSLITE_REQUEST'] ?? '') !== '1') {
    responder(403, ['error' => 'Solicitud no autorizada.']);
}

try {
    ['empresa' => $empresa, 'permisos' => $permisos] = contextoAutorizado();
    if (!in_array('maestros.editar', $permisos, true)) {
        responder(403, ['error' => 'No tiene permiso para subir fotos de productos.']);
    }

    $file = $_FILES['imagen'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        responder(400, ['error' => 'Adjunte una imagen válida (JPG, PNG o WebP).']);
    }
    if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > 3 * 1024 * 1024) {
        responder(413, ['error' => 'La imagen debe pesar máximo 3 MB.']);
    }

    $tmp = (string) $file['tmp_name'];
    $info = @getimagesize($tmp);
    $mime = is_array($info) ? ($info['mime'] ?? '') : '';
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => null,
    };
    if ($ext === null) {
        responder(422, ['error' => 'Solo se admiten imágenes JPG, PNG o WebP.']);
    }

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        responder(500, ['error' => 'No se pudo preparar el directorio de imágenes.']);
    }

    $hash = hash_file('sha256', $tmp);
    $nombre = $empresa . '_' . $hash . '.' . $ext;
    $destino = $dir . '/' . $nombre;

    if (!is_file($destino)) {
        if (!move_uploaded_file($tmp, $destino)) {
            if (!copy($tmp, $destino)) {
                responder(500, ['error' => 'No se pudo guardar la imagen del producto.']);
            }
        }
    }

    responder(201, [
        'imagenUrl' => '/api/producto-imagen.php?f=' . $nombre,
        'archivo' => $nombre,
    ]);
} catch (Throwable $e) {
    error_log('MyPOS Lite producto-imagen: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo procesar la imagen del producto.']);
}
