<?php

declare(strict_types=1);

require_once __DIR__ . '/Almacenamiento.php';
require_once __DIR__ . '/Rut.php';

/**
 * Baja de empresa en cuatro pasos:
 * 1. El administrador prepara un ZIP con la exportación de datos, los XML firmados que emitió y los archivos de ingreso.
 * 2. Lo descarga; Lite registra la descarga.
 * 3. Confirma con el RUT y la huella del ZIP: la empresa queda inactiva y nadie vuelve a entrar.
 * 4. Un operador elimina datos y archivos con database/baja-empresa.sh, que usa las credenciales de administración del
 *    almacenamiento. La aplicación nunca las tiene. El registro de la baja queda en empresa_bajas.
 */
const COLUMNAS_SECRETAS_BAJA = ['pfx_cifrado', 'clave_cifrada', 'xml_cifrado', 'clave_hash'];

function carpetaBajas(): string
{
    $carpeta = sys_get_temp_dir() . '/lite-bajas';
    if (!is_dir($carpeta) && !mkdir($carpeta, 0700, true) && !is_dir($carpeta)) {
        throw new RuntimeException('No se pudo preparar la carpeta de exportación.');
    }
    return $carpeta;
}

/** Tablas de la empresa: todas las que tienen empresa_id, salvo el registro de bajas. */
function tablasEmpresaBaja(PDO $db): array
{
    return array_values(array_diff($db->query("SELECT table_name FROM information_schema.columns WHERE table_schema = DATABASE()
        AND column_name = 'empresa_id' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN), ['empresa_bajas']));
}

function estadoBajaEmpresa(PDO $db, string $empresa): ?array
{
    $query = $db->prepare('SELECT id, estado, huella, tamano, resumen, preparada_en, descargada_en, confirmada_en, eliminada_en
        FROM empresa_bajas WHERE empresa_id = ?');
    $query->execute([$empresa]);
    $fila = $query->fetch(PDO::FETCH_ASSOC);
    return $fila ? ['id' => (string) $fila['id'], 'resumen' => json_decode($fila['resumen'], true), 'tamano' => (int) $fila['tamano']] + $fila : null;
}

/**
 * Arma el ZIP de la baja. Si falta un archivo de ingreso, no hay ZIP: la exportación debe estar completa.
 *
 * @return array<string, mixed>
 */
function prepararBajaEmpresa(PDO $db, string $empresa, string $usuario): array
{
    $datos = $db->prepare('SELECT id, rut, razon_social, es_demo FROM empresas WHERE id = ?');
    $datos->execute([$empresa]);
    $fila = $datos->fetch(PDO::FETCH_ASSOC);
    if ($fila['es_demo']) {
        throw new RuntimeException('La empresa de demostración no se da de baja.', 409);
    }
    $previa = estadoBajaEmpresa($db, $empresa);
    if ($previa !== null && in_array($previa['estado'], ['confirmada', 'eliminada'], true)) {
        throw new RuntimeException('La baja ya está confirmada.', 409);
    }
    $ruta = carpetaBajas() . '/empresa-' . $empresa . '-' . bin2hex(random_bytes(8)) . '.zip';
    $zip = new PharData($ruta, 0, null, Phar::ZIP);
    $resumen = ['tablas' => [], 'emitidos' => 0, 'recibidos' => 0];
    // Datos: una fila por línea, sin columnas secretas.
    foreach (tablasEmpresaBaja($db) as $tabla) {
        $filas = $db->prepare("SELECT * FROM `$tabla` WHERE empresa_id = ?");
        $filas->execute([$empresa]);
        $lineas = [];
        foreach ($filas->fetchAll(PDO::FETCH_ASSOC) as $registro) {
            foreach (COLUMNAS_SECRETAS_BAJA as $columna) {
                unset($registro[$columna]);
            }
            if ($tabla === 'dte_documentos' || $tabla === 'dte_envios') {
                unset($registro['xml']);
            }
            $lineas[] = json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $zip->addFromString("datos/$tabla.jsonl", implode("\n", $lineas) . ($lineas ? "\n" : ''));
        $resumen['tablas'][$tabla] = count($lineas);
    }
    $usuarios = $db->prepare('SELECT u.id, u.correo, u.nombre, m.rol_codigo AS rol FROM membresias m JOIN usuarios u ON u.id = m.usuario_id WHERE m.empresa_id = ?');
    $usuarios->execute([$empresa]);
    $zip->addFromString('datos/usuarios.jsonl', implode("\n", array_map(static fn (array $u): string => json_encode($u, JSON_UNESCAPED_UNICODE),
        $usuarios->fetchAll(PDO::FETCH_ASSOC))) . "\n");
    $zip->addFromString('datos/empresa.json', json_encode(['id' => $fila['id'], 'rut' => $fila['rut'], 'razonSocial' => $fila['razon_social']],
        JSON_UNESCAPED_UNICODE));
    // Documentos emitidos: los XML firmados, con sus bytes originales. El PDF se genera desde ellos.
    $emitidos = $db->prepare('SELECT ambiente, tipo, folio, xml FROM dte_documentos WHERE empresa_id = ? ORDER BY id');
    $emitidos->execute([$empresa]);
    while ($doc = $emitidos->fetch(PDO::FETCH_ASSOC)) {
        $zip->addFromString("emitidos/{$doc['ambiente']}/{$doc['tipo']}-{$doc['folio']}.xml", $doc['xml']);
        $resumen['emitidos']++;
    }
    // Archivos de ingreso desde el almacenamiento, con su huella comprobada.
    $archivos = $db->prepare('SELECT id, huella, nombre FROM archivos_recibidos WHERE empresa_id = ? ORDER BY id');
    $archivos->execute([$empresa]);
    try {
        while ($archivo = $archivos->fetch(PDO::FETCH_ASSOC)) {
            $contenido = obtenerObjetoAlmacenamiento($empresa, $archivo['huella']);
            if (!hash_equals($archivo['huella'], hash('sha256', $contenido))) {
                throw new RuntimeException('El archivo ' . $archivo['id'] . ' no coincide con su huella.');
            }
            $nombre = preg_replace('/[^A-Za-z0-9._-]+/', '_', $archivo['nombre']) ?: 'archivo';
            $zip->addFromString("recibidos/{$archivo['id']}-$nombre", $contenido);
            $resumen['recibidos']++;
        }
    } catch (Throwable $error) {
        unset($zip);
        @unlink($ruta);
        throw $error;
    }
    $zip->addFromString('LEEME.txt', "Exportación de baja de {$fila['razon_social']} (RUT {$fila['rut']}).\n"
        . "datos/: una fila JSON por línea de cada tabla. Certificados, contraseñas y CAF no se incluyen.\n"
        . "emitidos/: XML firmados de cada documento, sin modificar. Consérvelos el plazo que exige el SII.\n"
        . "recibidos/: fotos y PDF de documentos recibidos.\n");
    unset($zip);
    $huella = hash_file('sha256', $ruta);
    $tamano = filesize($ruta);
    if ($previa !== null && $previa['estado'] !== 'eliminada') {
        $anterior = $db->prepare('SELECT archivo FROM empresa_bajas WHERE empresa_id = ?');
        $anterior->execute([$empresa]);
        if (is_string($viejo = $anterior->fetchColumn()) && is_file($viejo)) {
            @unlink($viejo);
        }
    }
    $db->prepare("INSERT INTO empresa_bajas (empresa_id, rut, razon_social, estado, archivo, huella, tamano, resumen, preparada_por)
        VALUES (?, ?, ?, 'preparada', ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE estado = 'preparada', archivo = VALUES(archivo), huella = VALUES(huella),
        tamano = VALUES(tamano), resumen = VALUES(resumen), preparada_por = VALUES(preparada_por), preparada_en = CURRENT_TIMESTAMP(6),
        descargada_en = NULL")
        ->execute([$empresa, $fila['rut'], $fila['razon_social'], $ruta, $huella, $tamano, json_encode($resumen), $usuario]);
    return estadoBajaEmpresa($db, $empresa);
}

/** Ruta del ZIP para descargarlo; marca la descarga. */
function archivoBajaEmpresa(PDO $db, string $empresa): array
{
    $query = $db->prepare("SELECT archivo, huella, rut FROM empresa_bajas WHERE empresa_id = ? AND estado IN ('preparada', 'descargada')");
    $query->execute([$empresa]);
    $fila = $query->fetch(PDO::FETCH_ASSOC);
    if (!$fila || !is_file((string) $fila['archivo'])) {
        throw new RuntimeException('Prepare la exportación antes de descargarla.', 409);
    }
    return $fila;
}

function marcarDescargaBajaEmpresa(PDO $db, string $empresa): void
{
    $db->prepare("UPDATE empresa_bajas SET estado = 'descargada', descargada_en = CURRENT_TIMESTAMP(6) WHERE empresa_id = ? AND estado IN ('preparada', 'descargada')")
        ->execute([$empresa]);
}

/** El administrador confirma con el RUT y la huella del ZIP descargado: la empresa queda inactiva. */
function confirmarBajaEmpresa(PDO $db, string $empresa, string $usuario, array $input): array
{
    $baja = estadoBajaEmpresa($db, $empresa);
    if ($baja === null || $baja['estado'] !== 'descargada') {
        throw new RuntimeException('Descargue la exportación antes de confirmar la baja.', 409);
    }
    $rut = is_string($input['rut'] ?? null) ? strtoupper(preg_replace('/[^0-9kK]/', '', $input['rut'])) : '';
    $propio = $db->prepare('SELECT rut FROM empresas WHERE id = ?');
    $propio->execute([$empresa]);
    if ($rut === '' || $rut !== strtoupper(preg_replace('/[^0-9kK]/', '', (string) $propio->fetchColumn()))) {
        throw new InvalidArgumentException('Escriba el RUT de la empresa para confirmar la baja.');
    }
    if (!is_string($input['huella'] ?? null) || !hash_equals((string) $baja['huella'], strtolower(trim($input['huella'])))) {
        throw new InvalidArgumentException('La huella no corresponde al último archivo preparado: descárguelo de nuevo.');
    }
    $db->beginTransaction();
    try {
        $archivo = $db->prepare('SELECT archivo FROM empresa_bajas WHERE empresa_id = ? FOR UPDATE');
        $archivo->execute([$empresa]);
        $ruta = (string) $archivo->fetchColumn();
        $db->prepare("UPDATE empresa_bajas SET estado = 'confirmada', confirmada_por = ?, confirmada_en = CURRENT_TIMESTAMP(6), archivo = NULL WHERE empresa_id = ?")
            ->execute([$usuario, $empresa]);
        $db->prepare('UPDATE empresas SET activa = FALSE WHERE id = ?')->execute([$empresa]);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    // El ZIP ya está en manos del administrador: no queda otra copia en el servidor.
    if ($ruta !== '' && is_file($ruta)) {
        @unlink($ruta);
    }
    return estadoBajaEmpresa($db, $empresa);
}

/**
 * Elimina los datos de una empresa con la baja confirmada. Solo desde la línea de comandos del operador.
 * Los usuarios sin otra empresa ni acceso de soporte se eliminan; los demás conservan sus otras membresías.
 */
function eliminarEmpresaBaja(PDO $db, string $empresa): array
{
    $baja = estadoBajaEmpresa($db, $empresa);
    if ($baja === null || $baja['estado'] !== 'confirmada') {
        throw new RuntimeException('La empresa no tiene una baja confirmada.', 409);
    }
    $solos = $db->prepare('SELECT u.id FROM usuarios u JOIN membresias m ON m.usuario_id = u.id AND m.empresa_id = ?
        WHERE NOT u.soporte AND NOT EXISTS (SELECT 1 FROM membresias o WHERE o.usuario_id = u.id AND o.empresa_id <> ?)');
    $solos->execute([$empresa, $empresa]);
    $usuarios = $solos->fetchAll(PDO::FETCH_COLUMN);
    $eliminado = [];
    $db->beginTransaction();
    try {
        // Toda la empresa se va: las llaves foráneas entre sus propias filas no imponen un orden.
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (tablasEmpresaBaja($db) as $tabla) {
            $borrar = $db->prepare("DELETE FROM `$tabla` WHERE empresa_id = ?");
            $borrar->execute([$empresa]);
            $eliminado[$tabla] = $borrar->rowCount();
        }
        $db->prepare('DELETE FROM empresas WHERE id = ?')->execute([$empresa]);
        foreach ($usuarios as $id) {
            $db->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        $eliminado['usuarios'] = count($usuarios);
        $db->prepare("UPDATE empresa_bajas SET estado = 'eliminada', eliminada_en = CURRENT_TIMESTAMP(6), eliminado = ? WHERE empresa_id = ?")
            ->execute([json_encode($eliminado), $empresa]);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        throw $error;
    }
    return $eliminado;
}
