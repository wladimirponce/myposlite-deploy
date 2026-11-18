<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../src/AccesoMaestros.php';
require __DIR__ . '/../src/ImpuestosEspecificos.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

function fechaImpuesto(mixed $input): string
{
    if (!is_string($input) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $input)) {
        throw new InvalidArgumentException('Ingrese una fecha válida.');
    }
    [$year, $month, $day] = array_map('intval', explode('-', $input));
    if ($year < 1000 || !checkdate($month, $day, $year)) {
        throw new InvalidArgumentException('Ingrese una fecha válida.');
    }
    return $input;
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    responder(405, ['error' => 'Método no disponible.']);
}
if ($method === 'POST' && ($_SERVER['HTTP_X_MYPOSLITE_REQUEST'] ?? '') !== '1') {
    responder(403, ['error' => 'Solicitud no autorizada.']);
}

try {
    if (!iniciarSesionLite()) {
        responder(503, ['error' => 'No se pudo iniciar la sesión.']);
    }
    $usuario = idSesionLite();
    $contexto = $_SESSION['contexto'] ?? null;
    if ($usuario === null || !$contexto) {
        responder($usuario === null ? 401 : 403, ['error' => 'Inicie sesión y seleccione un local.']);
    }
    $empresa = $contexto['empresaId'];
    if (($_SERVER['HTTP_X_MYPOSLITE_EMPRESA'] ?? null) !== $empresa) {
        responder(409, ['error' => 'Cambió la empresa activa.']);
    }
    $db = conectarBaseLite();
    $permisos = permisosMaestros($db, $usuario, $empresa, $contexto['localCodigo']);
    $editar = in_array('empresa.configurar', $permisos, true);
    if (($method === 'POST' && !$editar) || !array_intersect(['empresa.configurar', 'maestros.editar', 'operacion.consultar'], $permisos)) {
        responder(403, ['error' => 'No tiene permiso para esta operación.']);
    }
    $_SESSION['actividad'] = time();
    session_write_close();

    if ($method === 'GET') {
        $zona = $db->prepare('SELECT zona_horaria FROM empresas WHERE id = ?');
        $zona->execute([$empresa]);
        $hoy = new DateTimeImmutable('now', new DateTimeZone((string) $zona->fetchColumn()));
        $fecha = fechaImpuesto($_GET['fecha'] ?? $hoy->format('Y-m-d'));
        $impuestos = tasasImpuestosEspecificos($db, $empresa, $fecha);
        $ajustes = $db->prepare('SELECT v.impuesto_codigo, i.nombre, v.vigente_desde, v.porcentaje, v.utm_por_unidad
            FROM empresa_impuestos_especificos_vigencias v
            JOIN impuestos_especificos i ON i.codigo = v.impuesto_codigo
            WHERE v.empresa_id = ? ORDER BY v.vigente_desde DESC, i.nombre');
        $ajustes->execute([$empresa]);
        responder(200, ['fecha' => $fecha, 'impuestos' => $impuestos, 'ajustes' => $ajustes->fetchAll(PDO::FETCH_ASSOC), 'puedeEditar' => $editar]);
    }

    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if ($raw === false || strlen($raw) > 4096) {
        responder(413, ['error' => 'Solicitud demasiado grande.']);
    }
    $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    $codigo = $input['codigo'] ?? null;
    $porcentaje = $input['porcentaje'] ?? null;
    $utm = $input['utmPorUnidad'] ?? '0';
    if (!is_string($codigo) || !preg_match('/^[a-z0-9-]{1,40}$/D', $codigo)
        || !is_string($porcentaje) || !preg_match('/^(?:[0-9]{1,2}(?:\.[0-9]{1,6})?|100(?:\.0{1,6})?)$/D', $porcentaje)
        || !is_string($utm) || !preg_match('/^[0-9]{1,4}(?:\.[0-9]{1,10})?$/D', $utm)) {
        throw new InvalidArgumentException('Revise el impuesto, la tasa y el componente UTM.');
    }
    $vigencia = fechaImpuesto($input['vigenteDesde'] ?? null);
    $base = $db->prepare('SELECT codigo_dte FROM impuestos_especificos_vigencias
        WHERE impuesto_codigo = ? AND vigente_desde <= ? ORDER BY vigente_desde DESC LIMIT 1');
    $base->execute([$codigo, $vigencia]);
    $oficial = $base->fetch(PDO::FETCH_ASSOC);
    if (!$oficial) {
        throw new InvalidArgumentException('No existe una tasa de referencia para este impuesto en esa fecha.');
    }
    $db->prepare('INSERT INTO empresa_impuestos_especificos_vigencias
        (empresa_id, impuesto_codigo, vigente_desde, porcentaje, utm_por_unidad, codigo_dte)
        VALUES (?, ?, ?, ?, ?, ?)')->execute([$empresa, $codigo, $vigencia, $porcentaje, $utm, $oficial['codigo_dte']]);
    responder(201, ['codigo' => $codigo, 'vigenteDesde' => $vigencia]);
} catch (InvalidArgumentException | JsonException $error) {
    responder(422, ['error' => $error instanceof JsonException ? 'JSON inválido.' : $error->getMessage()]);
} catch (Throwable $error) {
    if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
        responder(409, ['error' => 'Ya existe un ajuste para ese impuesto y esa fecha.']);
    }
    error_log('MyPOS Lite impuestos: ' . $error::class);
    responder(500, ['error' => 'No se pudo completar la solicitud.']);
}
