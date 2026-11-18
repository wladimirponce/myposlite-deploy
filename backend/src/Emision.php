<?php

declare(strict_types=1);

require_once __DIR__ . '/Rut.php';
foreach (['ValidacionDte', 'CodificacionSii', 'Caf', 'GeneradorTimbre', 'FirmadorXml', 'FormatoFirmaCertificacion', 'RutCertificado',
    'ConstructorBoleta', 'ConstructorDte', 'ConstructorSobre', 'ValidadorEsquema', 'SetBoletasCertificacion', 'ErrorSiiException', 'EstadoEmision',
    'TransporteSii', 'RespuestaSii', 'ClienteSiiSoap', 'ClienteSii', 'ConstructorRcof', 'RepresentacionPdf'] as $claseDte) {
    require_once __DIR__ . "/Dte/$claseDte.php";
}

use MyposLite\Dte\Caf;
use MyposLite\Dte\ConstructorBoleta;
use MyposLite\Dte\ConstructorSobre;
use MyposLite\Dte\ConstructorDte;
use MyposLite\Dte\FirmadorXml;
use MyposLite\Dte\FormatoFirmaCertificacion;
use MyposLite\Dte\GeneradorTimbre;
use MyposLite\Dte\RutCertificado;
use MyposLite\Dte\SetBoletasCertificacion;
use MyposLite\Dte\ValidacionDte;
use MyposLite\Dte\ValidadorEsquema;

const AMBIENTES_DTE = ['certificacion', 'produccion'];
// Tipos que Lite acepta en un CAF. Las boletas son la etapa 1; el resto, la etapa 2 (04-B).
const TIPOS_DTE = [39 => 'Boleta afecta', 41 => 'Boleta exenta', 33 => 'Factura', 34 => 'Factura exenta',
    52 => 'Guía de despacho', 56 => 'Nota de débito', 61 => 'Nota de crédito'];

/** Falta la llave de cifrado del motor o algo que el motor necesita para operar. */
final class MotorDteNoDisponible extends RuntimeException
{
}

/**
 * Cifra con la llave del motor (MYPOSLITE_DTE_SECRET). Certificados, contraseñas y CAF se guardan solo así:
 * un respaldo de la base no basta para firmar documentos.
 */
function cifrarDte(string $texto): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return $nonce . sodium_crypto_secretbox($texto, $nonce, llaveDte());
}

function descifrarDte(string $cifrado): string
{
    $texto = sodium_crypto_secretbox_open(substr($cifrado, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        substr($cifrado, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), llaveDte());
    if ($texto === false) {
        throw new MotorDteNoDisponible('No se pudo abrir un secreto del motor DTE: la llave cambió.');
    }
    return $texto;
}

function llaveDte(): string
{
    $secreto = getenv('MYPOSLITE_DTE_SECRET') ?: '';
    if (strlen($secreto) < 32) {
        throw new MotorDteNoDisponible('El motor DTE no está configurado en este servidor.');
    }
    return hash_hmac('sha256', 'myposlite-dte-v1', $secreto, true);
}

function ambienteDte(mixed $ambiente): string
{
    if (!is_string($ambiente) || !in_array($ambiente, AMBIENTES_DTE, true)) {
        throw new InvalidArgumentException('Seleccione el ambiente: certificación o producción.');
    }
    return $ambiente;
}

/** RUT de la empresa en el formato del SII, 12345678-9. */
function rutEmpresaDte(PDO $db, string $empresa): string
{
    $query = $db->prepare('SELECT rut FROM empresas WHERE id = ?');
    $query->execute([$empresa]);
    $rut = (string) $query->fetchColumn();
    return substr($rut, 0, -1) . '-' . substr($rut, -1);
}

/** Datos del emisor para un ambiente: los de la empresa más los tributarios configurados. */
function emisorDte(PDO $db, string $empresa, string $ambiente): ?array
{
    $query = $db->prepare('SELECT e.razon_social, d.giro, d.acteco, d.direccion, d.comuna, d.unidad_sii, d.numero_resolucion, d.fecha_resolucion
        FROM empresas e JOIN dte_emisores d ON d.empresa_id = e.id AND d.ambiente = ? WHERE e.id = ?');
    $query->execute([$ambiente, $empresa]);
    $fila = $query->fetch(PDO::FETCH_ASSOC);
    if (!$fila) {
        return null;
    }
    return ['rut' => rutEmpresaDte($db, $empresa), 'razonSocial' => $fila['razon_social'], 'giro' => $fila['giro'],
        'acteco' => $fila['acteco'] === null ? null : (int) $fila['acteco'],
        'direccion' => $fila['direccion'], 'comuna' => $fila['comuna'], 'unidadSii' => $fila['unidad_sii'], 'numeroResolucion' => (int) $fila['numero_resolucion'],
        'fechaResolucion' => $fila['fecha_resolucion']];
}

function guardarEmisorDte(PDO $db, string $empresa, string $usuario, array $input): void
{
    $ambiente = ambienteDte($input['ambiente'] ?? null);
    $campos = [];
    foreach (['giro' => 80, 'direccion' => 70, 'comuna' => 20] as $campo => $largo) {
        $valor = is_string($input[$campo] ?? null) ? trim($input[$campo]) : '';
        if ($valor === '' || mb_strlen($valor) > $largo) {
            throw new InvalidArgumentException("Ingrese $campo, con hasta $largo caracteres.");
        }
        $campos[$campo] = $valor;
    }
    $numero = $input['numeroResolucion'] ?? null;
    $fecha = $input['fechaResolucion'] ?? null;
    // Actividad económica del SII (Acteco): opcional para boletas, obligatoria en facturas, guías y notas.
    $acteco = $input['acteco'] ?? null;
    if ($acteco !== null && (!is_int($acteco) || $acteco < 1 || $acteco > 999999)) {
        throw new InvalidArgumentException('Ingrese el código de actividad económica de seis dígitos del SII.');
    }
    // Unidad regional del SII del domicilio del emisor, que va impresa bajo el recuadro del documento.
    $unidad = $input['unidadSii'] ?? null;
    if ($unidad !== null && (!is_string($unidad) || mb_strlen(trim($unidad)) > 40)) {
        throw new InvalidArgumentException('Ingrese la unidad del SII con hasta 40 caracteres.');
    }
    $unidad = $unidad === null || trim($unidad) === '' ? null : trim($unidad);
    if (!is_int($numero) || $numero < 0 || $numero > 999999 || ($ambiente === 'certificacion' && $numero !== 0)) {
        throw new InvalidArgumentException('Ingrese el número de resolución. En certificación es 0.');
    }
    if (!is_string($fecha) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $fecha, $partes)
        || !checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])) {
        throw new InvalidArgumentException('Ingrese la fecha de resolución que indica el comprobante de timbraje del SII.');
    }
    $db->prepare('INSERT INTO dte_emisores (empresa_id, ambiente, giro, acteco, direccion, comuna, unidad_sii, numero_resolucion, fecha_resolucion,
        actualizado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE giro = VALUES(giro), acteco = VALUES(acteco),
        direccion = VALUES(direccion), comuna = VALUES(comuna), unidad_sii = VALUES(unidad_sii), numero_resolucion = VALUES(numero_resolucion),
        fecha_resolucion = VALUES(fecha_resolucion), actualizado_por = VALUES(actualizado_por)')
        ->execute([$empresa, $ambiente, $campos['giro'], $acteco, $campos['direccion'], $campos['comuna'], $unidad, $numero, $fecha, $usuario]);
}

/**
 * Representación impresa en PDF de un documento de la empresa, generada desde el XML firmado que se guardó.
 *
 * @return array{pdf: string, nombre: string}
 */
function representacionDte(PDO $db, string $empresa, string $id, string $copia, string $formato): array
{
    $query = $db->prepare('SELECT ambiente, tipo, folio, xml FROM dte_documentos WHERE empresa_id = ? AND id = ?');
    $query->execute([$empresa, $id]);
    $documento = $query->fetch(PDO::FETCH_ASSOC);
    if (!$documento) {
        throw new RuntimeException('Documento no encontrado.', 404);
    }
    $emisor = emisorDte($db, $empresa, $documento['ambiente']);
    if ($emisor === null) {
        throw new RuntimeException('Faltan los datos del emisor para imprimir el documento.', 409);
    }
    $pdf = \MyposLite\Dte\RepresentacionPdf::generar($documento['xml'], $emisor, $copia, $formato);
    return ['pdf' => $pdf, 'nombre' => 'dte-' . $documento['tipo'] . '-' . $documento['folio'] . ($copia === 'cedible' ? '-cedible' : '') . '.pdf'];
}

/**
 * Guarda el certificado digital (.pfx o .p12) cifrado y deja de usar los anteriores del mismo ambiente.
 * El RUT del titular sale del certificado: es el RutEnvia de cada sobre.
 *
 * @return array{id: string, rutTitular: string, nombreTitular: string, validoHasta: string}
 */
function guardarCertificadoDte(PDO $db, string $empresa, string $usuario, string $ambiente, string $pfx, string $clave): array
{
    $ambiente = ambienteDte($ambiente);
    if ($pfx === '' || strlen($pfx) > 65536 || strlen($clave) > 200) {
        throw new InvalidArgumentException('Seleccione el archivo .pfx o .p12 del certificado, de hasta 64 KB.');
    }
    if (!openssl_pkcs12_read($pfx, $paquete, $clave)) {
        throw new InvalidArgumentException('No se pudo abrir el certificado: revise el archivo y la contraseña.');
    }
    $datos = openssl_x509_parse($paquete['cert']);
    $rut = RutCertificado::extraer($paquete['cert']);
    if (!is_array($datos) || $rut === null) {
        throw new InvalidArgumentException('El certificado no identifica el RUT de su titular.');
    }
    $desde = (new DateTimeImmutable('@' . $datos['validFrom_time_t']))->format('Y-m-d H:i:s');
    $hasta = (new DateTimeImmutable('@' . $datos['validTo_time_t']))->format('Y-m-d H:i:s');
    if ($datos['validTo_time_t'] < time()) {
        throw new InvalidArgumentException('El certificado está vencido.');
    }
    $nombre = (string) ($datos['subject']['CN'] ?? $rut);
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE dte_certificados SET activo = FALSE WHERE empresa_id = ? AND ambiente = ?')->execute([$empresa, $ambiente]);
        $db->prepare('INSERT INTO dte_certificados (empresa_id, ambiente, pfx_cifrado, clave_cifrada, huella, rut_titular, nombre_titular,
            valido_desde, valido_hasta, cargado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE activo = TRUE, clave_cifrada = VALUES(clave_cifrada), id = LAST_INSERT_ID(id)')
            ->execute([$empresa, $ambiente, cifrarDte($pfx), cifrarDte($clave), hash('sha256', $pfx), $rut,
                mb_substr($nombre, 0, 160), $desde, $hasta, $usuario]);
        $id = $db->lastInsertId();
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    return ['id' => (string) $id, 'rutTitular' => $rut, 'nombreTitular' => $nombre, 'validoHasta' => $hasta];
}

/** @return array{certificado: string, clave: OpenSSLAsymmetricKey, rut: string} */
function abrirCertificadoDte(PDO $db, string $empresa, string $ambiente): array
{
    $query = $db->prepare('SELECT pfx_cifrado, clave_cifrada, rut_titular, valido_hasta FROM dte_certificados
        WHERE empresa_id = ? AND ambiente = ? AND activo ORDER BY id DESC LIMIT 1');
    $query->execute([$empresa, $ambiente]);
    $fila = $query->fetch(PDO::FETCH_ASSOC);
    if (!$fila || strtotime($fila['valido_hasta'] . ' UTC') < time()) {
        throw new RuntimeException('Cargue un certificado digital vigente para este ambiente.', 409);
    }
    if (!openssl_pkcs12_read(descifrarDte($fila['pfx_cifrado']), $paquete, descifrarDte($fila['clave_cifrada']))) {
        throw new MotorDteNoDisponible('No se pudo abrir el certificado guardado.');
    }
    $clave = openssl_pkey_get_private($paquete['pkey']);
    if ($clave === false) {
        throw new MotorDteNoDisponible('No se pudo abrir la clave del certificado guardado.');
    }
    return ['certificado' => $paquete['cert'], 'clave' => $clave, 'rut' => $fila['rut_titular']];
}

/**
 * Carga un CAF del SII. Se rechaza si es de otro RUT, de un tipo que Lite no emite, ya cargado, o si su rango se
 * solapa con otro del mismo tipo y ambiente: un RUT emite cada folio desde un solo sistema.
 *
 * @return array{id: string, tipo: int, ambiente: string, desde: int, hasta: int}
 */
function cargarCafDte(PDO $db, string $empresa, string $usuario, string $xml): array
{
    $datos = Caf::analizar($xml);
    if ($datos['rut'] !== rutEmpresaDte($db, $empresa)) {
        throw new InvalidArgumentException('El CAF es de otro RUT: ' . $datos['rut'] . '.');
    }
    if (!isset(TIPOS_DTE[$datos['tipo']])) {
        throw new InvalidArgumentException('Lite no emite el tipo ' . $datos['tipo'] . '.');
    }
    // Un candado por empresa serializa las cargas: dos CAF solapados subidos a la vez no pueden entrar ambos.
    $candado = 'myposlite-caf-' . $empresa;
    $db->prepare('SELECT GET_LOCK(?, 10)')->execute([$candado]);
    try {
        $solape = $db->prepare('SELECT desde, hasta FROM dte_cafs WHERE empresa_id = ? AND ambiente = ? AND tipo = ?
            AND desde <= ? AND hasta >= ? LIMIT 1');
        $solape->execute([$empresa, $datos['ambiente'], $datos['tipo'], $datos['hasta'], $datos['desde']]);
        if ($previo = $solape->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException("El rango {$datos['desde']}-{$datos['hasta']} se solapa con el CAF {$previo['desde']}-{$previo['hasta']} ya cargado.", 409);
        }
        // Un RUT emite cada folio desde un solo sistema: los rangos que conserva otro sistema no se cargan en Lite.
        $externo = $db->prepare("SELECT desde, hasta, sistema FROM dte_rangos_externos WHERE empresa_id = ? AND tipo = ? AND desde <= ? AND hasta >= ?
            LIMIT 1");
        $externo->execute([$empresa, $datos['tipo'], $datos['hasta'], $datos['desde']]);
        if ($datos['ambiente'] === 'produccion' && ($ajeno = $externo->fetch(PDO::FETCH_ASSOC))) {
            throw new RuntimeException("El rango {$datos['desde']}-{$datos['hasta']} toca los folios {$ajeno['desde']}-{$ajeno['hasta']}, asignados a "
                . $ajeno['sistema'] . '.', 409);
        }
        try {
            $db->prepare('INSERT INTO dte_cafs (empresa_id, ambiente, tipo, desde, hasta, siguiente, fecha_autorizacion, idk, xml_cifrado,
                huella, cargado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$empresa, $datos['ambiente'], $datos['tipo'], $datos['desde'], $datos['hasta'], $datos['desde'],
                    $datos['fecha'], $datos['idk'], cifrarDte($xml), $datos['huella'], $usuario]);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new RuntimeException('Ese CAF ya estaba cargado.', 409, $error);
            }
            throw $error;
        }
        $id = $db->lastInsertId();
    } finally {
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$candado]);
    }
    return ['id' => (string) $id, 'tipo' => $datos['tipo'], 'ambiente' => $datos['ambiente'], 'desde' => $datos['desde'], 'hasta' => $datos['hasta']];
}

/**
 * Reserva el siguiente folio del CAF más antiguo con saldo. Requiere una transacción abierta: si después falla el
 * timbre, la firma o el esquema, la reversión devuelve el folio.
 *
 * @return array{caf: string, folio: int, xml: string}
 */
function reservarFolioDte(PDO $db, string $empresa, string $ambiente, int $tipo): array
{
    $query = $db->prepare('SELECT id, siguiente, xml_cifrado FROM dte_cafs WHERE empresa_id = ? AND ambiente = ? AND tipo = ?
        AND siguiente <= hasta ORDER BY desde LIMIT 1 FOR UPDATE');
    $query->execute([$empresa, $ambiente, $tipo]);
    $caf = $query->fetch(PDO::FETCH_ASSOC);
    if (!$caf) {
        throw new RuntimeException('No quedan folios del tipo ' . $tipo . ' en ' . $ambiente . '. Cargue un CAF nuevo.', 409);
    }
    $db->prepare('UPDATE dte_cafs SET siguiente = siguiente + 1 WHERE empresa_id = ? AND id = ?')->execute([$empresa, $caf['id']]);
    return ['caf' => (string) $caf['id'], 'folio' => (int) $caf['siguiente'], 'xml' => descifrarDte($caf['xml_cifrado'])];
}

/**
 * Genera una boleta timbrada y firmada y la guarda antes de cualquier envío. Requiere una transacción abierta.
 * La misma clave devuelve la boleta ya generada sin consumir otro folio.
 *
 * @param array{tipo: int, clave: string, origen: string, referencia?: ?string, lineas: list<array<string, mixed>>,
 *   receptor?: array{rut: string, nombre: string}, referencias?: list<array{codigo: string, razon: string}>} $datos
 * @return array{id: string, tipo: int, folio: int, total: int, xml: string, repetida: bool}
 */
function generarDocumentoDte(PDO $db, string $empresa, string $ambiente, string $usuario, array $emisor, array $firma, array $datos,
    DateTimeImmutable $ahora): array
{
    $previa = $db->prepare('SELECT id, tipo, folio, monto_total, xml FROM dte_documentos WHERE empresa_id = ? AND clave = ? FOR UPDATE');
    $previa->execute([$empresa, $datos['clave']]);
    if ($fila = $previa->fetch(PDO::FETCH_ASSOC)) {
        return ['id' => (string) $fila['id'], 'tipo' => (int) $fila['tipo'], 'folio' => (int) $fila['folio'],
            'total' => (int) $fila['monto_total'], 'xml' => $fila['xml'], 'repetida' => true];
    }
    $tipo = $datos['tipo'];
    $esBoleta = in_array($tipo, ConstructorBoleta::TIPOS, true);
    $montos = $esBoleta ? ConstructorBoleta::calcular($tipo, $datos['lineas']) : ConstructorDte::calcular($tipo, $datos['lineas']);
    $receptor = $datos['receptor'] ?? ['rut' => '66666666-6', 'nombre' => 'Consumidor final'];
    $reserva = reservarFolioDte($db, $empresa, $ambiente, $tipo);
    $fecha = $ahora->format('Y-m-d');
    $ted = GeneradorTimbre::generar($reserva['xml'], $tipo, $reserva['folio'], $fecha, $emisor['rut'], $receptor['rut'],
        $receptor['nombre'], $montos['total'], $montos['lineas'][0]['nombre'], $ahora);
    $xml = $esBoleta
        ? ConstructorBoleta::construir($tipo, $reserva['folio'], $fecha, $emisor, $receptor, $montos, $datos['referencias'] ?? [], $ted, $ahora)
        : ConstructorDte::construir($tipo, $reserva['folio'], $fecha, $emisor, $receptor, $montos, $datos['referencias'] ?? [], $ted, $ahora,
            $datos['opciones'] ?? []);
    $xml = FormatoFirmaCertificacion::aplicar((new FirmadorXml())->firmar($xml, $firma['certificado'], $firma['clave'],
        ConstructorBoleta::idDocumento($tipo, $reserva['folio'])));
    // Las boletas se validan en su sobre; los demás documentos, uno a uno contra DTE_v10.xsd antes de guardarse.
    if (!$esBoleta && ($errores = ValidadorEsquema::validarDte($xml)) !== []) {
        throw new ValidacionDte('dte', 'El documento no pasó la validación: ' . implode(' ', array_slice($errores, 0, 3)));
    }
    $db->prepare('INSERT INTO dte_documentos (empresa_id, ambiente, tipo, folio, caf_id, clave, origen, referencia, fecha_emision,
        monto_total, xml, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$empresa, $ambiente, $tipo, $reserva['folio'], $reserva['caf'], $datos['clave'], $datos['origen'],
            $datos['referencia'] ?? null, $fecha, $montos['total'], $xml, $usuario]);
    return ['id' => (string) $db->lastInsertId(), 'tipo' => $tipo, 'folio' => $reserva['folio'], 'total' => $montos['total'],
        'xml' => $xml, 'repetida' => false];
}

/**
 * Prepara el set de certificación de boletas: los cinco casos del SII en un solo sobre firmado, validado contra el
 * esquema y con las seis firmas verificadas. No toca la red. Si algo falla, no queda ningún folio consumido.
 * Prepararlo de nuevo devuelve el mismo sobre.
 *
 * @return array{envio: string, documentos: list<array{caso: int, folio: int, total: int}>, repetido: bool}
 */
function prepararSetBoletasDte(PDO $db, string $empresa, string $usuario): array
{
    $ambiente = 'certificacion';
    $emisor = emisorDte($db, $empresa, $ambiente);
    if ($emisor === null) {
        throw new RuntimeException('Complete los datos del emisor para certificación antes de preparar el set.', 409);
    }
    $firma = abrirCertificadoDte($db, $empresa, $ambiente);
    $zona = $db->prepare('SELECT zona_horaria FROM empresas WHERE id = ?');
    $zona->execute([$empresa]);
    // La fecha de emisión es la del comercio, no la del servidor.
    $ahora = new DateTimeImmutable('now', new DateTimeZone((string) $zona->fetchColumn() ?: 'America/Santiago'));
    $db->beginTransaction();
    try {
        $previo = $db->prepare("SELECT id FROM dte_envios WHERE empresa_id = ? AND clave = 'set-boletas' FOR UPDATE");
        $previo->execute([$empresa]);
        $existente = $previo->fetchColumn();
        $documentos = [];
        $resumen = [];
        foreach (SetBoletasCertificacion::CASOS as $caso) {
            $boleta = generarDocumentoDte($db, $empresa, $ambiente, $usuario, $emisor, $firma, ['tipo' => 39, 'clave' => "set-boletas-caso-$caso",
                'origen' => 'certificacion', 'referencia' => "Set de boletas, caso $caso", 'lineas' => SetBoletasCertificacion::caso($caso),
                'referencias' => [['codigo' => 'SET', 'razon' => "CASO-$caso"]]], $ahora);
            $documentos[] = ['tipo' => 39, 'xml' => $boleta['xml'], 'id' => $boleta['id']];
            $resumen[] = ['caso' => $caso, 'folio' => $boleta['folio'], 'total' => $boleta['total']];
        }
        if ($existente !== false) {
            $db->commit();
            return ['envio' => (string) $existente, 'documentos' => $resumen, 'repetido' => true];
        }
        $sobre = ConstructorSobre::boletas($documentos, $emisor['rut'], $firma['rut'], $emisor['numeroResolucion'],
            $emisor['fechaResolucion'], $ahora);
        $sobre = FormatoFirmaCertificacion::aplicar((new FirmadorXml())->firmar($sobre, $firma['certificado'], $firma['clave'], 'SetDoc'));
        $errores = ValidadorEsquema::validarSobreBoletas($sobre);
        if ($errores !== []) {
            throw new ValidacionDte('sobre', 'El sobre no pasó la validación: ' . implode(' ', array_slice($errores, 0, 3)));
        }
        $db->prepare("INSERT INTO dte_envios (empresa_id, ambiente, motivo, clave, xml, huella, rut_envia, usuario_id)
            VALUES (?, ?, 'set_boletas', 'set-boletas', ?, ?, ?, ?)")
            ->execute([$empresa, $ambiente, $sobre, hash('sha256', $sobre), $firma['rut'], $usuario]);
        $envio = $db->lastInsertId();
        $enlazar = $db->prepare('INSERT INTO dte_envio_documentos (empresa_id, envio_id, documento_id) VALUES (?, ?, ?)');
        foreach ($documentos as $documento) {
            $enlazar->execute([$empresa, $envio, $documento['id']]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
    return ['envio' => (string) $envio, 'documentos' => $resumen, 'repetido' => false];
}

/** Estado del motor para el panel: emisor, certificado y CAF por ambiente, sin ningún secreto. */
function estadoDte(PDO $db, string $empresa): array
{
    $demo = $db->prepare('SELECT es_demo FROM empresas WHERE id = ?');
    $demo->execute([$empresa]);
    $estado = ['rut' => rutEmpresaDte($db, $empresa), 'configurado' => strlen((string) getenv('MYPOSLITE_DTE_SECRET')) >= 32,
        'demo' => (bool) $demo->fetchColumn(), 'habilitaciones' => habilitacionesDte($db, $empresa), 'ambientes' => []];
    $certificado = $db->prepare('SELECT rut_titular, nombre_titular, valido_desde, valido_hasta FROM dte_certificados
        WHERE empresa_id = ? AND ambiente = ? AND activo ORDER BY id DESC LIMIT 1');
    $cafs = $db->prepare('SELECT id, tipo, desde, hasta, siguiente, fecha_autorizacion, cargado_en FROM dte_cafs
        WHERE empresa_id = ? AND ambiente = ? ORDER BY tipo, desde');
    $envios = $db->prepare('SELECT e.id, e.motivo, e.estado, e.track_id, e.glosa, e.intentos, e.creado_en, COUNT(d.documento_id) AS documentos
        FROM dte_envios e LEFT JOIN dte_envio_documentos d ON d.empresa_id = e.empresa_id AND d.envio_id = e.id
        WHERE e.empresa_id = ? AND e.ambiente = ? GROUP BY e.id ORDER BY e.id DESC LIMIT 20');
    foreach (AMBIENTES_DTE as $ambiente) {
        $certificado->execute([$empresa, $ambiente]);
        $cafs->execute([$empresa, $ambiente]);
        $envios->execute([$empresa, $ambiente]);
        $estado['ambientes'][$ambiente] = [
            'emisor' => emisorDte($db, $empresa, $ambiente),
            'certificado' => $certificado->fetch(PDO::FETCH_ASSOC) ?: null,
            'cafs' => array_map(static fn (array $fila): array => ['id' => (string) $fila['id'], 'tipo' => (int) $fila['tipo'],
                'desde' => (int) $fila['desde'], 'hasta' => (int) $fila['hasta'], 'disponibles' => (int) $fila['hasta'] - (int) $fila['siguiente'] + 1,
                'fechaAutorizacion' => $fila['fecha_autorizacion'], 'cargadoEn' => $fila['cargado_en']], $cafs->fetchAll(PDO::FETCH_ASSOC)),
            'envios' => array_map(static fn (array $fila): array => ['id' => (string) $fila['id'], 'documentos' => (int) $fila['documentos'], 'intentos' => (int) $fila['intentos']] + $fila,
                $envios->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }
    $estado['cambioProveedor'] = cambioProveedorDte($db, $empresa);
    $estado['alertas'] = alertasDte($db, $empresa, new DateTimeImmutable('now', new DateTimeZone('America/Santiago')));
    return $estado;
}

/**
 * Alertas de producción para no quedar sin emitir: certificado vencido o por vencer, folios por agotarse según el
 * consumo de las últimas dos semanas, y CAF por vencer.
 *
 * Vigencia del CAF: los autorizados desde el 1 de julio de 2018 duran 6 meses desde su autorización. Esto es seguro
 * para facturas y notas (33, 56 y 61); para boletas, guías y facturas exentas las fuentes no coinciden, así que
 * Lite solo pide confirmarlo en sii.cl. Los folios de un CAF vencido que no se usaron se anulan en el sitio del SII.
 *
 * @return list<array{nivel: string, tipo: ?int, mensaje: string}>
 */
function alertasDte(PDO $db, string $empresa, DateTimeImmutable $ahora): array
{
    $alertas = [];
    $tipos = array_values(array_map(static fn (array $h): int => $h['tipo'],
        array_filter(habilitacionesDte($db, $empresa), static fn (array $h): bool => $h['estado'] === 'produccion')));
    if ($tipos === []) {
        return [];
    }
    $certificado = $db->prepare("SELECT valido_hasta FROM dte_certificados WHERE empresa_id = ? AND ambiente = 'produccion' AND activo
        ORDER BY id DESC LIMIT 1");
    $certificado->execute([$empresa]);
    $hasta = $certificado->fetchColumn();
    if ($hasta === false) {
        $alertas[] = ['nivel' => 'critico', 'tipo' => null, 'mensaje' => 'No hay certificado digital de producción: no se pueden firmar documentos.'];
    } else {
        $dias = (int) floor(((new DateTimeImmutable((string) $hasta, $ahora->getTimezone()))->getTimestamp() - $ahora->getTimestamp()) / 86400);
        if ($dias < 0) {
            $alertas[] = ['nivel' => 'critico', 'tipo' => null, 'mensaje' => 'El certificado digital venció: cargue el renovado para seguir emitiendo.'];
        } elseif ($dias <= 30) {
            $alertas[] = ['nivel' => 'aviso', 'tipo' => null,
                'mensaje' => "El certificado digital vence en $dias " . ($dias === 1 ? 'día' : 'días') . ': renuévelo con su proveedor y cárguelo.'];
        }
    }
    $nombres = TIPOS_DTE;
    $cafs = $db->prepare("SELECT tipo, desde, hasta, siguiente, fecha_autorizacion FROM dte_cafs WHERE empresa_id = ? AND ambiente = 'produccion'
        AND siguiente <= hasta ORDER BY tipo, desde");
    $cafs->execute([$empresa]);
    $porTipo = [];
    foreach ($cafs->fetchAll(PDO::FETCH_ASSOC) as $caf) {
        $tipo = (int) $caf['tipo'];
        $porTipo[$tipo] = ($porTipo[$tipo] ?? 0) + (int) $caf['hasta'] - (int) $caf['siguiente'] + 1;
        if (!in_array($tipo, $tipos, true) || $caf['fecha_autorizacion'] === null) {
            continue;
        }
        $vence = (new DateTimeImmutable((string) $caf['fecha_autorizacion'], $ahora->getTimezone()))->modify('+6 months');
        $rango = 'los folios ' . $caf['siguiente'] . ' a ' . $caf['hasta'] . ' de ' . mb_strtolower($nombres[$tipo]);
        $seguro = in_array($tipo, [33, 56, 61], true);
        if ($vence <= $ahora) {
            $alertas[] = $seguro
                ? ['nivel' => 'critico', 'tipo' => $tipo, 'mensaje' => "El CAF de $rango venció el " . $vence->format('d-m-Y')
                    . ': anule esos folios en sii.cl y cargue un CAF nuevo.']
                : ['nivel' => 'aviso', 'tipo' => $tipo, 'mensaje' => "El CAF de $rango tiene más de 6 meses: confirme su vigencia en sii.cl."];
        } elseif ($vence <= $ahora->modify('+30 days')) {
            $alertas[] = ['nivel' => 'aviso', 'tipo' => $tipo, 'mensaje' => "El CAF de $rango " . ($seguro ? 'vence' : 'podría vencer')
                . ' el ' . $vence->format('d-m-Y') . '.'];
        }
    }
    // Consumo de las últimas dos semanas, para avisar con una semana de margen.
    $consumo = $db->prepare("SELECT tipo, COUNT(*) FROM dte_documentos WHERE empresa_id = ? AND ambiente = 'produccion' AND fecha_emision >= ?
        GROUP BY tipo");
    $consumo->execute([$empresa, $ahora->modify('-14 days')->format('Y-m-d')]);
    $usados = array_map('intval', $consumo->fetchAll(PDO::FETCH_KEY_PAIR));
    foreach ($tipos as $tipo) {
        $quedan = $porTipo[$tipo] ?? 0;
        $diario = ($usados[$tipo] ?? 0) / 14;
        $nombre = mb_strtolower($nombres[$tipo]);
        if ($quedan === 0) {
            $alertas[] = ['nivel' => 'critico', 'tipo' => $tipo, 'mensaje' => "No quedan folios de $nombre: pida un CAF en sii.cl y cárguelo."];
        } elseif ($quedan < 10 || ($diario > 0 && $quedan / $diario < 7)) {
            $alertas[] = ['nivel' => 'aviso', 'tipo' => $tipo, 'mensaje' => "Quedan $quedan folios de $nombre"
                . ($diario > 0 ? ', unos ' . max(1, (int) floor($quedan / $diario)) . ' días al ritmo actual' : '') . ': pida un CAF nuevo en sii.cl.'];
        }
    }
    usort($alertas, static fn (array $a, array $b): int => ($a['nivel'] === 'critico' ? 0 : 1) <=> ($b['nivel'] === 'critico' ? 0 : 1));
    return $alertas;
}

/**
 * Canal SOAP del SII para el ambiente. MYPOSLITE_SII_BASE, solo en desarrollo y QA, apunta todos sus recursos a un SII
 * simulado; vacío usa Maullín (certificación) o Palena (producción).
 */
function canalSiiDte(string $ambiente, bool $rest = false): \MyposLite\Dte\ClienteSiiSoap|\MyposLite\Dte\ClienteSii
{
    $base = rtrim((string) getenv('MYPOSLITE_SII_BASE'), '/');
    if ($rest) {
        // Las boletas de venta viajan por el canal REST del SII; el set de certificación, por el UPLOAD.
        $recursos = "$base/recursos/v1/boleta.electronica";
        $simulado = $base === '' ? [] : ['semilla' => "$recursos.semilla", 'token' => "$recursos.token", 'envio' => "$recursos.envio",
            'estado' => "$recursos.envio/%s-%s-%s", 'folio' => "$recursos/%s-%s-%s-%s/estado"];
        return new \MyposLite\Dte\ClienteSii(strtoupper($ambiente), 30, $simulado);
    }
    $simulado = $base === '' ? [] : ['semilla' => "$base/DTEWS/CrSeed.jws", 'token' => "$base/DTEWS/GetTokenFromSeed.jws",
        'envio' => "$base/cgi_dte/UPL/DTEUpload", 'estado' => "$base/DTEWS/QueryEstUp.jws"];
    return new \MyposLite\Dte\ClienteSiiSoap(strtoupper($ambiente), 30, $simulado);
}

function registrarIntentoDte(PDO $db, string $empresa, string $envio, ?string $usuario, string $accion, string $estado,
    ?string $track, ?string $huella, string $respuesta): void
{
    $db->prepare('INSERT INTO dte_intentos (empresa_id, envio_id, accion, estado, track_id, huella_enviada, respuesta, usuario_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$empresa, $envio, $accion, $estado, $track, $huella, mb_substr($respuesta, 0, 4000), $usuario]);
}

/**
 * Envía un sobre ya firmado. Siempre los mismos bytes guardados: un reintento nunca genera otro documento ni gasta folio.
 *
 * - Con track ID ya obtenido no se reenvía: se consulta.
 * - Un envío en curso (estado «enviando» de menos de 15 minutos) no se duplica. Pasado ese plazo se asume que el
 *   proceso murió y se puede reintentar.
 * - Sin respuesta del SII el sobre queda «pendiente» y se puede reenviar. «Enviado» significa recibido, no aceptado.
 *
 * @return array{estado: string, trackId: ?string, glosa: ?string, repetido: bool}
 */
function enviarEnvioDte(PDO $db, string $empresa, ?string $usuario, string $id): array
{
    $demo = $db->prepare('SELECT es_demo FROM empresas WHERE id = ?');
    $demo->execute([$empresa]);
    if ((bool) $demo->fetchColumn()) {
        throw new RuntimeException('La empresa de demostración no envía documentos al SII.', 409);
    }
    $consulta = $db->prepare('SELECT ambiente, motivo, intentos, xml, huella, rut_envia FROM dte_envios WHERE empresa_id = ? AND id = ?');
    $consulta->execute([$empresa, $id]);
    $previo = $consulta->fetch(PDO::FETCH_ASSOC);
    if (!$previo) {
        throw new RuntimeException('El envío no existe en esta empresa.', 404);
    }
    // El certificado debe ser del mismo titular que firmó el sobre: RutEnvia va dentro de la carátula firmada.
    $firma = abrirCertificadoDte($db, $empresa, $previo['ambiente']);
    if ($firma['rut'] !== $previo['rut_envia']) {
        throw new RuntimeException('El certificado activo es de otro titular que el que firmó este sobre.', 409);
    }

    $db->beginTransaction();
    try {
        $consulta = $db->prepare('SELECT estado, track_id, glosa, actualizado_en > UTC_TIMESTAMP(6) - INTERVAL 15 MINUTE AS reciente
            FROM dte_envios WHERE empresa_id = ? AND id = ? FOR UPDATE');
        $consulta->execute([$empresa, $id]);
        $fila = $consulta->fetch(PDO::FETCH_ASSOC);
        if ($fila['track_id'] !== null) {
            $db->commit();
            return ['estado' => $fila['estado'], 'trackId' => $fila['track_id'], 'glosa' => $fila['glosa'], 'repetido' => true];
        }
        if ($fila['estado'] === 'enviando' && $fila['reciente']) {
            throw new RuntimeException('Este sobre tiene un envío en curso. Espere su resultado antes de reintentar.', 409);
        }
        $db->prepare("UPDATE dte_envios SET estado = 'enviando', intentos = intentos + 1, actualizado_en = UTC_TIMESTAMP(6)
            WHERE empresa_id = ? AND id = ?")->execute([$empresa, $id]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    $rest = $previo['motivo'] === 'boletas';
    // Un lote de boletas que ya se intentó sin respuesta se concilia por folio antes de reenviarlo: si el SII ya lo
    // tiene, no se manda otra vez. Si la consulta falla, se reenvía el mismo sobre, que no gasta folios.
    if ($rest && (int) $previo['intentos'] > 0) {
        try {
            if (($conciliado = conciliarPorFolioDte($db, $empresa, $id, $previo['ambiente'], $firma)) !== null) {
                $db->prepare("UPDATE dte_envios SET estado = 'enviado', glosa = ?, actualizado_en = UTC_TIMESTAMP(6) WHERE empresa_id = ? AND id = ?")
                    ->execute(['El SII ya había recibido este sobre: conciliado por folio.', $empresa, $id]);
                $db->prepare("UPDATE dte_documentos d JOIN dte_envio_documentos e ON e.empresa_id = d.empresa_id AND e.documento_id = d.id
                    SET d.estado = 'enviado' WHERE e.empresa_id = ? AND e.envio_id = ?")->execute([$empresa, $id]);
                registrarIntentoDte($db, $empresa, $id, $usuario, 'consultar', 'conciliado', null, null, $conciliado);
                return ['estado' => 'enviado', 'trackId' => null, 'glosa' => 'El SII ya había recibido este sobre: conciliado por folio.',
                    'repetido' => false];
            }
        } catch (\MyposLite\Dte\ErrorSiiException) {
            // Sin respuesta tampoco a la consulta: se reenvía el mismo sobre.
        }
    }
    // Fuera de la transacción: la red puede tardar y no debe retener bloqueos.
    $respuesta = canalSiiDte($previo['ambiente'], $rest)->enviar($previo['xml'], rutEmpresaDte($db, $empresa), $previo['rut_envia'],
        $firma['certificado'], $firma['clave']);
    $track = $respuesta['track_id'];
    $estado = $track === null ? 'pendiente' : 'enviado';
    $glosa = $track === null ? 'No se pudo confirmar la recepción del SII. Reintente: se enviará el mismo sobre.' : 'Recibido por el SII.';
    $db->prepare('UPDATE dte_envios SET estado = ?, track_id = ?, glosa = ?, actualizado_en = UTC_TIMESTAMP(6) WHERE empresa_id = ? AND id = ?')
        ->execute([$estado, $track, $glosa, $empresa, $id]);
    if ($track !== null) {
        $db->prepare("UPDATE dte_documentos d JOIN dte_envio_documentos e ON e.empresa_id = d.empresa_id AND e.documento_id = d.id
            SET d.estado = 'enviado', d.track_id = ? WHERE e.empresa_id = ? AND e.envio_id = ?")->execute([$track, $empresa, $id]);
    }
    registrarIntentoDte($db, $empresa, $id, $usuario, 'enviar', $estado, $track, $previo['huella'], (string) $respuesta['respuesta']);
    return ['estado' => $estado, 'trackId' => $track, 'glosa' => $glosa, 'repetido' => false];
}

/**
 * Consulta en el SII el estado de un sobre enviado y lo traslada a sus documentos. Un código desconocido o en proceso
 * deja el estado como estaba.
 *
 * @return array{estado: string, glosa: string}
 */
function consultarEnvioDte(PDO $db, string $empresa, ?string $usuario, string $id): array
{
    $consulta = $db->prepare('SELECT ambiente, motivo, estado, track_id FROM dte_envios WHERE empresa_id = ? AND id = ?');
    $consulta->execute([$empresa, $id]);
    $envio = $consulta->fetch(PDO::FETCH_ASSOC);
    if (!$envio) {
        throw new RuntimeException('El envío no existe en esta empresa.', 404);
    }
    if ($envio['track_id'] === null && $envio['motivo'] === 'boletas' && $envio['estado'] === 'enviado') {
        // Conciliado por folio, sin track ID: el estado se consulta boleta por boleta.
        return consultarPorFolioDte($db, $empresa, $usuario, $id, $envio['ambiente']);
    }
    if ($envio['track_id'] === null) {
        throw new RuntimeException('El SII aún no entrega un número de seguimiento para este sobre.', 409);
    }
    $firma = abrirCertificadoDte($db, $empresa, $envio['ambiente']);
    try {
        $respuesta = canalSiiDte($envio['ambiente'], $envio['motivo'] === 'boletas')->estado($envio['track_id'], rutEmpresaDte($db, $empresa), $firma['certificado'], $firma['clave']);
    } catch (\MyposLite\Dte\ErrorSiiException $error) {
        registrarIntentoDte($db, $empresa, $id, $usuario, 'consultar', 'sin_respuesta', $envio['track_id'], null, $error->getMessage());
        throw $error;
    }
    $estado = $respuesta['estado'] === null || $respuesta['estado'] === \MyposLite\Dte\EstadoEmision::ERROR
        ? $envio['estado'] : strtolower($respuesta['estado']);
    $glosa = mb_substr((string) $respuesta['glosa'], 0, 400);
    $db->prepare('UPDATE dte_envios SET estado = ?, glosa = ?, actualizado_en = UTC_TIMESTAMP(6) WHERE empresa_id = ? AND id = ?')
        ->execute([$estado, $glosa, $empresa, $id]);
    $db->prepare('UPDATE dte_documentos d JOIN dte_envio_documentos e ON e.empresa_id = d.empresa_id AND e.documento_id = d.id
        SET d.estado = ? WHERE e.empresa_id = ? AND e.envio_id = ?')->execute([$estado, $empresa, $id]);
    registrarIntentoDte($db, $empresa, $id, $usuario, 'consultar', $estado, $envio['track_id'], null, (string) $respuesta['respuesta']);
    // Correos: el receptor recibe sus documentos aceptados y la empresa, la respuesta del SII a su trámite.
    if ($estado !== $envio['estado']) {
        if ($envio['ambiente'] === 'produccion') {
            encolarDocumentosReceptorCorreo($db, $empresa, $id);
        }
        avisarRespuestaTramiteCorreo($db, $empresa, $id, $envio['motivo'], $estado, $glosa);
    }
    return ['estado' => $estado, 'glosa' => $glosa];
}

/**
 * Prepara el RCOF que el SII pide junto con el set de certificación: solo los documentos del set, con sus montos
 * sumados documento por documento (como el RCOF aceptado de Libertad: neto 43.831, IVA 8.329, exento 2.000 y total
 * 54.160), firmado y validado contra ConsumoFolio_v10.xsd. Se envía y consulta con enviarEnvioDte y consultarEnvioDte.
 *
 * @return array{envio: string, neto: int, iva: int, exento: int, total: int, folios: list<int>, repetido: bool}
 */
function prepararRcofSetDte(PDO $db, string $empresa, string $usuario): array
{
    $ambiente = 'certificacion';
    $previo = $db->prepare("SELECT id, xml FROM dte_envios WHERE empresa_id = ? AND clave = 'rcof-set-boletas'");
    $previo->execute([$empresa]);
    $documentos = $db->prepare("SELECT d.folio, d.tipo, d.fecha_emision, d.xml FROM dte_envios e
        JOIN dte_envio_documentos x ON x.empresa_id = e.empresa_id AND x.envio_id = e.id
        JOIN dte_documentos d ON d.empresa_id = x.empresa_id AND d.id = x.documento_id
        WHERE e.empresa_id = ? AND e.clave = 'set-boletas' ORDER BY d.folio");
    $documentos->execute([$empresa]);
    $filas = $documentos->fetchAll(PDO::FETCH_ASSOC);
    if ($filas === []) {
        throw new RuntimeException('Prepare el set de boletas antes de su RCOF.', 409);
    }
    $monto = static fn (string $xml, string $etiqueta): int => preg_match("#<$etiqueta>(\d+)</$etiqueta>#", $xml, $m) ? (int) $m[1] : 0;
    $resumen = ['tipo' => 39, 'neto' => 0, 'iva' => 0, 'exento' => 0, 'total' => 0, 'emitidos' => [], 'anulados' => []];
    foreach ($filas as $fila) {
        $resumen['neto'] += $monto($fila['xml'], 'MntNeto');
        $resumen['iva'] += $monto($fila['xml'], 'IVA');
        $resumen['exento'] += $monto($fila['xml'], 'MntExe');
        $resumen['total'] += $monto($fila['xml'], 'MntTotal');
        $resumen['emitidos'][] = (int) $fila['folio'];
    }
    $salida = ['neto' => $resumen['neto'], 'iva' => $resumen['iva'], 'exento' => $resumen['exento'], 'total' => $resumen['total'],
        'folios' => $resumen['emitidos']];
    if ($id = $previo->fetchColumn()) {
        return ['envio' => (string) $id] + $salida + ['repetido' => true];
    }
    $emisor = emisorDte($db, $empresa, $ambiente);
    $firma = abrirCertificadoDte($db, $empresa, $ambiente);
    $fecha = $filas[0]['fecha_emision'];
    $zona = $db->prepare('SELECT zona_horaria FROM empresas WHERE id = ?');
    $zona->execute([$empresa]);
    $ahora = new DateTimeImmutable('now', new DateTimeZone((string) $zona->fetchColumn() ?: 'America/Santiago'));
    $xml = \MyposLite\Dte\ConstructorRcof::construir($emisor['rut'], $firma['rut'], $emisor['numeroResolucion'],
        $emisor['fechaResolucion'], $fecha, 1, [$resumen], $ahora);
    $xml = FormatoFirmaCertificacion::aplicar((new FirmadorXml())->firmar($xml, $firma['certificado'], $firma['clave'],
        \MyposLite\Dte\ConstructorRcof::id($fecha, 1)));
    $errores = ValidadorEsquema::validarRcof($xml);
    if ($errores !== []) {
        throw new ValidacionDte('rcof', 'El RCOF no pasó la validación: ' . implode(' ', array_slice($errores, 0, 3)));
    }
    try {
        $db->prepare("INSERT INTO dte_envios (empresa_id, ambiente, motivo, clave, xml, huella, rut_envia, usuario_id)
            VALUES (?, ?, 'rcof_set', 'rcof-set-boletas', ?, ?, ?, ?)")
            ->execute([$empresa, $ambiente, $xml, hash('sha256', $xml), $firma['rut'], $usuario]);
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) !== 1062) {
            throw $error;
        }
        $previo->execute([$empresa]);
        return ['envio' => (string) $previo->fetchColumn()] + $salida + ['repetido' => true];
    }
    return ['envio' => (string) $db->lastInsertId()] + $salida + ['repetido' => false];
}

const ESTADOS_HABILITACION = ['sin_iniciar', 'en_certificacion', 'visto_bueno', 'produccion'];

/** Estado de habilitación de cada tipo que Lite emite. Sin registro, el tipo está sin iniciar. */
function habilitacionesDte(PDO $db, string $empresa): array
{
    $habilitaciones = [];
    foreach (TIPOS_DTE as $tipo => $nombre) {
        $habilitaciones[$tipo] = ['tipo' => $tipo, 'nombre' => $nombre, 'estado' => 'sin_iniciar', 'atencion' => null,
            'vistoBuenoEn' => null, 'produccionDesde' => null];
    }
    $query = $db->prepare('SELECT tipo, estado, atencion_revision, visto_bueno_en, produccion_desde FROM dte_habilitaciones WHERE empresa_id = ?');
    $query->execute([$empresa]);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $habilitaciones[(int) $fila['tipo']] = ['estado' => $fila['estado'], 'atencion' => $fila['atencion_revision'],
            'vistoBuenoEn' => $fila['visto_bueno_en'], 'produccionDesde' => $fila['produccion_desde']] + $habilitaciones[(int) $fila['tipo']];
    }
    return array_values($habilitaciones);
}

/** ¿Puede la empresa emitir este tipo ante el SII en producción? El POS y la emisión lo consultan antes de ofrecerlo. */
function tipoHabilitadoDte(PDO $db, string $empresa, int $tipo): bool
{
    $query = $db->prepare("SELECT 1 FROM dte_habilitaciones WHERE empresa_id = ? AND tipo = ? AND estado = 'produccion'");
    $query->execute([$empresa, $tipo]);
    return $query->fetchColumn() !== false;
}

/**
 * Avanza la habilitación de un tipo, un paso a la vez: sin iniciar → en certificación → visto bueno → producción.
 * Las boletas 39 y 41 se certifican juntas con el mismo set, así que avanzan juntas.
 *
 * - El visto bueno de boletas exige el set y su RCOF aceptados por el SII y el número de atención de la revisión
 *   solicitada en el portal, con la fecha del correo del SII. La aceptación técnica no es el visto bueno: lo registra
 *   el representante cuando llega.
 * - Producción exige emisor y certificado vigente en producción. El visto bueno y la producción se registran por
 *   separado porque el SII los otorga por separado.
 * - Volver de «en certificación» a «sin iniciar» deshace un inicio por error; los demás pasos no se retroceden.
 */
function cambiarHabilitacionDte(PDO $db, string $empresa, string $usuario, array $input): array
{
    $tipo = $input['tipo'] ?? null;
    $nuevo = $input['estado'] ?? null;
    if (!is_int($tipo) || !isset(TIPOS_DTE[$tipo]) || !in_array($nuevo, ESTADOS_HABILITACION, true)) {
        throw new InvalidArgumentException('Seleccione el tipo de documento y el estado.');
    }
    $tipos = in_array($tipo, [39, 41], true) ? [39, 41] : [$tipo];
    $actual = 'sin_iniciar';
    foreach (habilitacionesDte($db, $empresa) as $fila) {
        if ($fila['tipo'] === $tipo) {
            $actual = $fila['estado'];
        }
    }
    $orden = array_flip(ESTADOS_HABILITACION);
    $retroceso = $actual === 'en_certificacion' && $nuevo === 'sin_iniciar';
    if (!$retroceso && $orden[$nuevo] !== $orden[$actual] + 1) {
        throw new RuntimeException('El tipo ' . $tipo . ' está ' . str_replace('_', ' ', $actual) . ': el siguiente paso es otro.', 409);
    }
    $atencion = null;
    $vistoBueno = null;
    if ($nuevo === 'visto_bueno') {
        $atencion = is_string($input['atencion'] ?? null) ? trim($input['atencion']) : '';
        $vistoBueno = $input['fecha'] ?? null;
        if (!preg_match('/^[A-Za-z0-9-]{1,40}$/D', $atencion) || !is_string($vistoBueno)
            || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $vistoBueno, $partes) || !checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])) {
            throw new InvalidArgumentException('Ingrese el número de atención de la revisión y la fecha del visto bueno.');
        }
        if ($tipos === [39, 41]) {
            $listos = $db->prepare("SELECT COUNT(*) FROM dte_envios WHERE empresa_id = ? AND ambiente = 'certificacion'
                AND ((motivo = 'set_boletas' AND estado = 'aceptado') OR (motivo = 'rcof_set' AND estado IN ('aceptado', 'reparo')))");
            $listos->execute([$empresa]);
            if ((int) $listos->fetchColumn() < 2) {
                throw new RuntimeException('El set de boletas y su RCOF deben estar aceptados por el SII antes del visto bueno.', 409);
            }
        }
    }
    if ($nuevo === 'produccion') {
        if (emisorDte($db, $empresa, 'produccion') === null) {
            throw new RuntimeException('Complete los datos del emisor en producción.', 409);
        }
        abrirCertificadoDte($db, $empresa, 'produccion');
    }
    $db->beginTransaction();
    try {
        foreach ($tipos as $cada) {
            $db->prepare('INSERT INTO dte_habilitaciones (empresa_id, tipo, estado, atencion_revision, visto_bueno_en, produccion_desde, actualizado_por)
                VALUES (?, ?, ?, ?, ?, IF(? = \'produccion\', CURRENT_DATE, NULL), ?)
                ON DUPLICATE KEY UPDATE estado = VALUES(estado), atencion_revision = COALESCE(VALUES(atencion_revision), atencion_revision),
                visto_bueno_en = COALESCE(VALUES(visto_bueno_en), visto_bueno_en),
                produccion_desde = COALESCE(VALUES(produccion_desde), produccion_desde), actualizado_por = VALUES(actualizado_por)')
                ->execute([$empresa, $cada, $nuevo, $atencion, $vistoBueno, $nuevo, $usuario]);
            $db->prepare('INSERT INTO dte_habilitacion_eventos (empresa_id, tipo, estado_anterior, estado_nuevo, detalle, usuario_id)
                VALUES (?, ?, ?, ?, ?, ?)')->execute([$empresa, $cada, $actual, $nuevo, $atencion === null ? null : 'Atención ' . $atencion, $usuario]);
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    return ['tipos' => $tipos, 'estado' => $nuevo];
}

/** Documentos de un envío con lo que pide la consulta por folio. */
function documentosEnvioDte(PDO $db, string $empresa, string $envio): array
{
    $query = $db->prepare('SELECT d.id, d.tipo, d.folio, d.fecha_emision, d.monto_total, d.xml FROM dte_envio_documentos e
        JOIN dte_documentos d ON d.empresa_id = e.empresa_id AND d.id = e.documento_id WHERE e.empresa_id = ? AND e.envio_id = ? ORDER BY d.folio');
    $query->execute([$empresa, $envio]);
    return array_map(static fn (array $d): array => $d + ['receptor' => preg_match('#<RUTRecep>([^<]+)</RUTRecep>#', $d['xml'], $m) ? $m[1] : '66666666-6'],
        $query->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * ¿El SII ya recibió este lote? Consulta el primer folio. Devuelve la respuesta si lo conoce y null si responde que no
 * lo recibió (FAU) o con un código que no indica recepción.
 */
function conciliarPorFolioDte(PDO $db, string $empresa, string $envio, string $ambiente, array $firma): ?string
{
    $primero = documentosEnvioDte($db, $empresa, $envio)[0] ?? null;
    if ($primero === null) {
        return null;
    }
    $estado = canalSiiDte($ambiente, true)->estadoPorFolio((int) $primero['tipo'], (int) $primero['folio'], rutEmpresaDte($db, $empresa),
        $primero['receptor'], $primero['fecha_emision'], (int) $primero['monto_total'], $firma['certificado'], $firma['clave']);
    return $estado['estado'] === null || $estado['codigo'] === 'FAU' ? null : $estado['respuesta'];
}

/** Estado de cada boleta de un envío sin track ID; el envío toma el peor: rechazado, luego en proceso, luego aceptado. */
function consultarPorFolioDte(PDO $db, string $empresa, ?string $usuario, string $envio, string $ambiente): array
{
    $firma = abrirCertificadoDte($db, $empresa, $ambiente);
    $canal = canalSiiDte($ambiente, true);
    $actualizar = $db->prepare('UPDATE dte_documentos SET estado = ? WHERE empresa_id = ? AND id = ?');
    $estados = [];
    foreach (documentosEnvioDte($db, $empresa, $envio) as $documento) {
        $r = $canal->estadoPorFolio((int) $documento['tipo'], (int) $documento['folio'], rutEmpresaDte($db, $empresa), $documento['receptor'],
            $documento['fecha_emision'], (int) $documento['monto_total'], $firma['certificado'], $firma['clave']);
        $estado = $r['estado'] === null ? 'enviado' : strtolower($r['estado']);
        $actualizar->execute([$estado, $empresa, $documento['id']]);
        $estados[] = $estado;
    }
    $general = in_array('rechazado', $estados, true) ? 'rechazado' : (in_array('enviado', $estados, true) ? 'enviado'
        : (in_array('reparo', $estados, true) ? 'reparo' : 'aceptado'));
    $glosa = count($estados) . ' boletas consultadas por folio.';
    $db->prepare('UPDATE dte_envios SET estado = ?, glosa = ?, actualizado_en = UTC_TIMESTAMP(6) WHERE empresa_id = ? AND id = ?')
        ->execute([$general, $glosa, $empresa, $envio]);
    registrarIntentoDte($db, $empresa, $envio, $usuario, 'consultar', $general, null, null, implode(',', $estados));
    return ['estado' => $general, 'glosa' => $glosa];
}

/**
 * Trabajo de envío de boletas de venta de una empresa, que corre el programador cada minuto y se puede pedir a mano:
 *
 * 1. Reintenta los lotes pendientes (sin respuesta del SII o con un envío colgado), conciliando por folio.
 * 2. Arma un lote con hasta 50 boletas de producción que todavía no están en ningún sobre, lo firma, lo valida y lo envía.
 * 3. Consulta el resultado de los lotes recibidos hace más de 20 segundos.
 *
 * Un candado por empresa evita que dos procesos armen el mismo lote. Los errores de un paso no detienen los demás.
 *
 * @return array{lotes: int, enviados: int, pendientes: int, consultados: int, errores: list<string>}
 */
function enviarBoletasPendientesDte(PDO $db, string $empresa, ?string $usuario = null): array
{
    $resumen = ['lotes' => 0, 'enviados' => 0, 'pendientes' => 0, 'consultados' => 0, 'errores' => []];
    $candado = 'myposlite-boletas-' . $empresa;
    $tomar = $db->prepare('SELECT GET_LOCK(?, 0)');
    $tomar->execute([$candado]);
    if ((int) $tomar->fetchColumn() !== 1) {
        return $resumen;
    }
    try {
        $reintentos = $db->prepare("SELECT id FROM dte_envios WHERE empresa_id = ? AND motivo IN ('boletas', 'documentos') AND track_id IS NULL
            AND (estado = 'pendiente' OR (estado IN ('preparado', 'enviando') AND actualizado_en < UTC_TIMESTAMP(6) - INTERVAL 15 MINUTE))
            ORDER BY id LIMIT 5");
        $reintentos->execute([$empresa]);
        $porEnviar = $reintentos->fetchAll(PDO::FETCH_COLUMN);

        $nuevas = $db->prepare("SELECT d.id, d.tipo, d.xml FROM dte_documentos d
            LEFT JOIN dte_envio_documentos x ON x.empresa_id = d.empresa_id AND x.documento_id = d.id
            WHERE d.empresa_id = ? AND d.ambiente = 'produccion' AND d.tipo IN (39, 41) AND d.origen <> 'certificacion'
            AND x.documento_id IS NULL ORDER BY d.id LIMIT 50");
        $nuevas->execute([$empresa]);
        $documentos = $nuevas->fetchAll(PDO::FETCH_ASSOC);
        if ($documentos !== []) {
            try {
                $emisor = emisorDte($db, $empresa, 'produccion');
                $firma = abrirCertificadoDte($db, $empresa, 'produccion');
                $zona = $db->prepare('SELECT zona_horaria FROM empresas WHERE id = ?');
                $zona->execute([$empresa]);
                $ahora = new DateTimeImmutable('now', new DateTimeZone((string) $zona->fetchColumn() ?: 'America/Santiago'));
                $sobre = ConstructorSobre::boletas(array_map(static fn (array $d): array => ['tipo' => (int) $d['tipo'], 'xml' => $d['xml']],
                    $documentos), $emisor['rut'], $firma['rut'], $emisor['numeroResolucion'], $emisor['fechaResolucion'], $ahora);
                $sobre = FormatoFirmaCertificacion::aplicar((new FirmadorXml())->firmar($sobre, $firma['certificado'], $firma['clave'], 'SetDoc'));
                $errores = ValidadorEsquema::validarSobreBoletas($sobre);
                if ($errores !== []) {
                    throw new ValidacionDte('sobre', 'El lote de boletas no pasó la validación: ' . implode(' ', array_slice($errores, 0, 3)));
                }
                $db->beginTransaction();
                $db->prepare("INSERT INTO dte_envios (empresa_id, ambiente, motivo, clave, xml, huella, rut_envia, usuario_id)
                    VALUES (?, 'produccion', 'boletas', ?, ?, ?, ?, ?)")->execute([$empresa, 'boletas-' . $documentos[0]['id'] . '-'
                    . end($documentos)['id'], $sobre, hash('sha256', $sobre), $firma['rut'], $usuario]);
                $envio = (string) $db->lastInsertId();
                $enlazar = $db->prepare('INSERT INTO dte_envio_documentos (empresa_id, envio_id, documento_id) VALUES (?, ?, ?)');
                foreach ($documentos as $documento) {
                    $enlazar->execute([$empresa, $envio, $documento['id']]);
                }
                $db->commit();
                $resumen['lotes']++;
                $porEnviar[] = $envio;
            } catch (Throwable $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $resumen['errores'][] = $error->getMessage();
            }
        }
        // Facturas, guías y notas: un sobre EnvioDTE por documento, por el UPLOAD.
        $otros = $db->prepare("SELECT d.id, d.tipo, d.xml FROM dte_documentos d
            LEFT JOIN dte_envio_documentos x ON x.empresa_id = d.empresa_id AND x.documento_id = d.id
            WHERE d.empresa_id = ? AND d.ambiente = 'produccion' AND d.tipo NOT IN (39, 41) AND x.documento_id IS NULL ORDER BY d.id LIMIT 10");
        $otros->execute([$empresa]);
        foreach ($otros->fetchAll(PDO::FETCH_ASSOC) as $documento) {
            try {
                $emisor ??= emisorDte($db, $empresa, 'produccion');
                $firma ??= abrirCertificadoDte($db, $empresa, 'produccion');
                $zona = $db->prepare('SELECT zona_horaria FROM empresas WHERE id = ?');
                $zona->execute([$empresa]);
                $ahora = new DateTimeImmutable('now', new DateTimeZone((string) $zona->fetchColumn() ?: 'America/Santiago'));
                $sobre = ConstructorSobre::dte([['tipo' => (int) $documento['tipo'], 'xml' => $documento['xml']]], $emisor['rut'], $firma['rut'],
                    $emisor['numeroResolucion'], $emisor['fechaResolucion'], $ahora);
                $sobre = FormatoFirmaCertificacion::aplicar((new FirmadorXml())->firmar($sobre, $firma['certificado'], $firma['clave'], 'SetDoc'));
                $errores = ValidadorEsquema::validarEnvioDte($sobre);
                if ($errores !== []) {
                    throw new ValidacionDte('sobre', 'El sobre del documento no pasó la validación: ' . implode(' ', array_slice($errores, 0, 3)));
                }
                $db->beginTransaction();
                $db->prepare("INSERT INTO dte_envios (empresa_id, ambiente, motivo, clave, xml, huella, rut_envia, usuario_id)
                    VALUES (?, 'produccion', 'documentos', ?, ?, ?, ?, ?)")
                    ->execute([$empresa, 'documento-' . $documento['id'], $sobre, hash('sha256', $sobre), $firma['rut'], $usuario]);
                $envio = (string) $db->lastInsertId();
                $db->prepare('INSERT INTO dte_envio_documentos (empresa_id, envio_id, documento_id) VALUES (?, ?, ?)')
                    ->execute([$empresa, $envio, $documento['id']]);
                $db->commit();
                $resumen['lotes']++;
                $porEnviar[] = $envio;
            } catch (Throwable $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $resumen['errores'][] = $error->getMessage();
            }
        }
        foreach ($porEnviar as $envio) {
            try {
                $resultado = enviarEnvioDte($db, $empresa, $usuario, (string) $envio);
                $resultado['estado'] === 'pendiente' ? $resumen['pendientes']++ : $resumen['enviados']++;
            } catch (Throwable $error) {
                $resumen['errores'][] = $error->getMessage();
            }
        }

        $recibidos = $db->prepare("SELECT id FROM dte_envios WHERE empresa_id = ? AND motivo IN ('boletas', 'documentos') AND estado = 'enviado'
            AND actualizado_en < UTC_TIMESTAMP(6) - INTERVAL 20 SECOND ORDER BY id LIMIT 10");
        $recibidos->execute([$empresa]);
        foreach ($recibidos->fetchAll(PDO::FETCH_COLUMN) as $envio) {
            try {
                consultarEnvioDte($db, $empresa, $usuario, (string) $envio);
                $resumen['consultados']++;
            } catch (Throwable $error) {
                $resumen['errores'][] = $error->getMessage();
            }
        }
    } finally {
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$candado]);
    }
    return $resumen;
}

/**
 * Alta por cambio de proveedor: la empresa ya emitía estos tipos con otro sistema y el SII ya la autorizó, así que pasan
 * a producción sin certificar. Exige emisor y certificado de producción. Los rangos que conserva el sistema anterior
 * (convivencia o folios pendientes) quedan registrados y Lite no carga un CAF que los toque.
 *
 * @return array{tipos: list<int>}
 */
function registrarCambioProveedorDte(PDO $db, string $empresa, string $usuario, array $input): array
{
    $proveedor = is_string($input['proveedorAnterior'] ?? null) ? trim($input['proveedorAnterior']) : '';
    $tipos = $input['tipos'] ?? null;
    $corte = $input['fechaCorte'] ?? null;
    $pendientes = $input['pendientes'] ?? null;
    $rangos = $input['rangos'] ?? [];
    if (mb_strlen($proveedor) < 2 || mb_strlen($proveedor) > 80) {
        throw new InvalidArgumentException('Ingrese el nombre del proveedor anterior.');
    }
    if (!is_array($tipos) || !array_is_list($tipos) || $tipos === [] || count($tipos) !== count(array_unique($tipos, SORT_REGULAR))
        || array_filter($tipos, static fn ($t): bool => !is_int($t) || !isset(TIPOS_DTE[$t])) !== []) {
        throw new InvalidArgumentException('Seleccione los tipos que el SII ya le autorizó con el proveedor anterior.');
    }
    if (!is_string($corte) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $corte, $partes)
        || !checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])) {
        throw new InvalidArgumentException('Ingrese la fecha de corte: desde ese día emite Lite.');
    }
    if ($pendientes !== null && (!is_string($pendientes) || mb_strlen($pendientes) > 1000)) {
        throw new InvalidArgumentException('Los pendientes del proveedor anterior admiten hasta 1.000 caracteres.');
    }
    if (!is_array($rangos) || !array_is_list($rangos) || count($rangos) > 20) {
        throw new InvalidArgumentException('Revise los rangos que conserva el proveedor anterior.');
    }
    foreach ($rangos as $i => $rango) {
        if (!is_array($rango) || !in_array($rango['tipo'] ?? null, $tipos, true) || !is_int($rango['desde'] ?? null) || !is_int($rango['hasta'] ?? null)
            || $rango['desde'] < 1 || $rango['hasta'] < $rango['desde'] || $rango['hasta'] > 999999999999) {
            throw new InvalidArgumentException('Revise el rango ' . ($i + 1) . ': tipo de los seleccionados y folios desde y hasta.');
        }
        foreach (array_slice($rangos, 0, $i) as $otro) {
            if ($otro['tipo'] === $rango['tipo'] && $otro['desde'] <= $rango['hasta'] && $otro['hasta'] >= $rango['desde']) {
                throw new InvalidArgumentException('Los rangos ' . ($i + 1) . ' y anteriores se solapan.');
            }
        }
    }
    $demo = $db->prepare('SELECT es_demo FROM empresas WHERE id = ?');
    $demo->execute([$empresa]);
    if ($demo->fetchColumn()) {
        throw new RuntimeException('La empresa de demostración no registra cambios de proveedor.', 409);
    }
    if (emisorDte($db, $empresa, 'produccion') === null) {
        throw new RuntimeException('Complete los datos del emisor en producción.', 409);
    }
    abrirCertificadoDte($db, $empresa, 'produccion');
    $estados = array_column(habilitacionesDte($db, $empresa), 'estado', 'tipo');
    foreach ($tipos as $tipo) {
        if ($estados[$tipo] === 'produccion') {
            throw new RuntimeException('El tipo ' . $tipo . ' ya está en producción en Lite.', 409);
        }
    }
    $propio = $db->prepare("SELECT desde, hasta FROM dte_cafs WHERE empresa_id = ? AND ambiente = 'produccion' AND tipo = ? AND desde <= ? AND hasta >= ? LIMIT 1");
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO dte_cambios_proveedor (empresa_id, proveedor_anterior, tipos, fecha_corte, pendientes, registrado_por) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$empresa, $proveedor, json_encode($tipos), $corte, $pendientes === null || trim($pendientes) === '' ? null : trim($pendientes), $usuario]);
        foreach ($rangos as $rango) {
            $propio->execute([$empresa, $rango['tipo'], $rango['hasta'], $rango['desde']]);
            if ($caf = $propio->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException("El rango {$rango['desde']}-{$rango['hasta']} se solapa con el CAF {$caf['desde']}-{$caf['hasta']} de Lite.", 409);
            }
            $db->prepare('INSERT INTO dte_rangos_externos (empresa_id, tipo, desde, hasta, sistema, registrado_por) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$empresa, $rango['tipo'], $rango['desde'], $rango['hasta'], $proveedor, $usuario]);
        }
        foreach ($tipos as $tipo) {
            $db->prepare("INSERT INTO dte_habilitaciones (empresa_id, tipo, estado, produccion_desde, actualizado_por) VALUES (?, ?, 'produccion', ?, ?)
                ON DUPLICATE KEY UPDATE estado = 'produccion', produccion_desde = VALUES(produccion_desde), actualizado_por = VALUES(actualizado_por)")
                ->execute([$empresa, $tipo, $corte, $usuario]);
            $db->prepare('INSERT INTO dte_habilitacion_eventos (empresa_id, tipo, estado_anterior, estado_nuevo, detalle, usuario_id) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$empresa, $tipo, $estados[$tipo], 'produccion', mb_substr('Cambio de proveedor desde ' . $proveedor, 0, 200), $usuario]);
        }
        $db->commit();
    } catch (PDOException $error) {
        $db->rollBack();
        if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
            throw new RuntimeException('El cambio de proveedor ya está registrado.', 409, $error);
        }
        throw $error;
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    return ['tipos' => $tipos];
}

/**
 * Expediente del cambio de proveedor: qué está hecho y qué falta hasta la primera emisión real aceptada.
 */
function cambioProveedorDte(PDO $db, string $empresa): ?array
{
    $query = $db->prepare('SELECT proveedor_anterior, tipos, fecha_corte, pendientes, registrado_en FROM dte_cambios_proveedor WHERE empresa_id = ?');
    $query->execute([$empresa]);
    $fila = $query->fetch(PDO::FETCH_ASSOC);
    if (!$fila) {
        return null;
    }
    $tipos = array_map('intval', json_decode($fila['tipos'], true));
    $marcas = implode(',', array_fill(0, count($tipos), '?'));
    $rangos = $db->prepare('SELECT tipo, desde, hasta, sistema FROM dte_rangos_externos WHERE empresa_id = ? ORDER BY tipo, desde');
    $rangos->execute([$empresa]);
    $certificado = $db->prepare("SELECT valido_hasta FROM dte_certificados WHERE empresa_id = ? AND ambiente = 'produccion' AND activo ORDER BY id DESC LIMIT 1");
    $certificado->execute([$empresa]);
    $vigente = ($hasta = $certificado->fetchColumn()) !== false && strtotime((string) $hasta) > time();
    $conCaf = $db->prepare("SELECT DISTINCT tipo FROM dte_cafs WHERE empresa_id = ? AND ambiente = 'produccion' AND siguiente <= hasta AND tipo IN ($marcas)");
    $conCaf->execute([$empresa, ...$tipos]);
    $faltan = array_values(array_diff($tipos, array_map('intval', $conCaf->fetchAll(PDO::FETCH_COLUMN))));
    $primera = $db->prepare("SELECT tipo, folio FROM dte_documentos WHERE empresa_id = ? AND ambiente = 'produccion' AND tipo IN ($marcas)
        AND estado IN ('aceptado', 'reparo') ORDER BY id LIMIT 1");
    $primera->execute([$empresa, ...$tipos]);
    $aceptada = $primera->fetch(PDO::FETCH_ASSOC);
    $hoy = (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('Y-m-d');
    return [
        'proveedorAnterior' => $fila['proveedor_anterior'], 'tipos' => $tipos, 'fechaCorte' => $fila['fecha_corte'],
        'pendientes' => $fila['pendientes'], 'registradoEn' => $fila['registrado_en'],
        'rangos' => array_map(static fn (array $r): array => ['tipo' => (int) $r['tipo'], 'desde' => (int) $r['desde'], 'hasta' => (int) $r['hasta'],
            'sistema' => $r['sistema']], $rangos->fetchAll(PDO::FETCH_ASSOC)),
        'pasos' => [
            ['clave' => 'registro', 'hecho' => true, 'detalle' => 'Proveedor anterior y tipos ya autorizados por el SII.'],
            ['clave' => 'certificado', 'hecho' => $vigente, 'detalle' => 'Certificado digital de producción vigente, cargado por el titular.'],
            ['clave' => 'caf', 'hecho' => $faltan === [], 'detalle' => $faltan === [] ? 'CAF de producción propio para cada tipo.'
                : 'Falta pedir en sii.cl y cargar el CAF de: ' . implode(', ', array_map(static fn (int $t): string => TIPOS_DTE[$t], $faltan)) . '.'],
            ['clave' => 'corte', 'hecho' => $fila['fecha_corte'] <= $hoy, 'detalle' => 'Desde el ' . implode('-', array_reverse(explode('-', $fila['fecha_corte']))) . ' emite Lite; el proveedor anterior cierra sus pendientes.'],
            ['clave' => 'primera', 'hecho' => $aceptada !== false, 'detalle' => $aceptada
                ? 'Primera emisión real aceptada: ' . TIPOS_DTE[(int) $aceptada['tipo']] . ' N° ' . $aceptada['folio'] . '.'
                : 'Falta la primera emisión real aceptada por el SII.'],
        ],
    ];
}

/**
 * EnvioDTE de intercambio para el receptor: el mismo DTE firmado, en un sobre cuya carátula lleva el RUT del receptor,
 * firmado por el titular del certificado y validado contra EnvioDTE_v10.
 */
function sobreReceptorDte(PDO $db, string $empresa, string $documento): string
{
    $query = $db->prepare('SELECT ambiente, tipo, xml FROM dte_documentos WHERE empresa_id = ? AND id = ?');
    $query->execute([$empresa, $documento]);
    $fila = $query->fetch(PDO::FETCH_ASSOC);
    if (!$fila || !preg_match('#<RUTRecep>([0-9]{1,8}-[0-9K])</RUTRecep>#', $fila['xml'], $receptor)) {
        throw new RuntimeException('El documento no tiene receptor.', 409);
    }
    $emisor = emisorDte($db, $empresa, $fila['ambiente']);
    $firma = abrirCertificadoDte($db, $empresa, $fila['ambiente']);
    $sobre = ConstructorSobre::dte([['tipo' => (int) $fila['tipo'], 'xml' => $fila['xml']]], $emisor['rut'], $firma['rut'],
        $emisor['numeroResolucion'], $emisor['fechaResolucion'], new DateTimeImmutable('now', new DateTimeZone('America/Santiago')), $receptor[1]);
    $sobre = FormatoFirmaCertificacion::aplicar((new FirmadorXml())->firmar($sobre, $firma['certificado'], $firma['clave'], 'SetDoc'));
    if (($errores = ValidadorEsquema::validarEnvioDte($sobre)) !== []) {
        throw new ValidacionDte('sobre', 'El sobre para el receptor no pasó la validación: ' . implode(' ', array_slice($errores, 0, 3)));
    }
    return $sobre;
}

require_once __DIR__ . '/Correo.php';
