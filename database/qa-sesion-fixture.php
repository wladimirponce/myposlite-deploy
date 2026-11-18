<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';

$mode = $argv[1] ?? '';
if (!in_array($mode, ['crear', 'crear-catalogo', 'borrar', 'auditoria-producto'], true)) {
    fwrite(STDERR, "Uso: php database/qa-sesion-fixture.php crear|crear-catalogo|borrar|auditoria-producto [id]\n");
    exit(1);
}

$crear = in_array($mode, ['crear', 'crear-catalogo'], true);
$tag = $crear ? bin2hex(random_bytes(8)) : ($argv[2] ?? '');
if (!preg_match('/^[a-f0-9]{16}$/D', $tag)) {
    fwrite(STDERR, "Identificador de prueba inválido.\n");
    exit(1);
}

$db = conectarBaseLite();
$db->beginTransaction();
$correo = "sesion-qa-$tag@example.invalid";
try {
    if ($crear) {
        $clave = bin2hex(random_bytes(24));
        $insert = $db->prepare('INSERT INTO usuarios (correo, nombre, clave_hash) VALUES (?, ?, ?)');
        $insert->execute([$correo, 'Vendedor ficticio QA', password_hash($clave, PASSWORD_DEFAULT)]);
        $usuario = $db->lastInsertId();
        $empresa = $db->prepare('INSERT INTO empresas (rut, razon_social) VALUES (?, ?)');
        foreach (['A', 'B', 'C'] as $letter) {
            $empresa->execute([random_int(10000000, 99999999) . 'K', "QA-$tag-$letter"]);
            $ids[] = $db->lastInsertId();
        }
        [$a, $b, $c] = $ids;
        $local = $db->prepare('INSERT INTO locales VALUES (?, ?, ?)');
        $local->execute([$a, 'centro', 'Centro']);
        $local->execute([$a, 'norte', 'Norte']);
        $local->execute([$b, 'sur', 'Sur']);
        $local->execute([$c, 'ajeno', 'Ajeno']);
        $membresia = $db->prepare('INSERT INTO membresias (empresa_id, usuario_id, rol_codigo) VALUES (?, ?, ?)');
        $membresia->execute([$a, $usuario, $mode === 'crear-catalogo' ? 'administrador' : 'vendedor']);
        $membresia->execute([$b, $usuario, 'contador']);
        $acceso = $db->prepare('INSERT INTO membresias_locales VALUES (?, ?, ?)');
        $acceso->execute([$a, $usuario, 'centro']);
        $acceso->execute([$b, $usuario, 'sur']);
        $resultado = compact('tag', 'correo', 'clave', 'a', 'b', 'c');
    } elseif ($mode === 'auditoria-producto') {
        $query = $db->prepare('SELECT ev.accion, ev.revision, ev.local_codigo
            FROM producto_eventos ev JOIN usuarios u ON u.id = ev.usuario_id
            JOIN empresas e ON e.id = ev.empresa_id JOIN productos p ON p.id = ev.producto_id
            WHERE u.correo = ? AND e.razon_social = ? AND p.sku = ? ORDER BY ev.revision');
        $query->execute([$correo, "QA-$tag-A", 'QA-AUDITORIA']);
        $resultado = $query->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $usuarioCreado = "qa-created-$tag@example.invalid";
        $empresas = ["QA-$tag-A", "QA-$tag-B", "QA-$tag-C"];
        $db->prepare('DELETE FROM cierres_diarios WHERE empresa_id IN
            (SELECT id FROM empresas WHERE razon_social IN (?, ?, ?))')->execute($empresas);
        // El inventario y los documentos recibidos referencian usuarios, contactos y productos: se retiran antes.
        foreach (['UPDATE stock_lotes SET lote_origen_id = NULL', 'UPDATE documentos_recibidos SET referido_id = NULL',
            'UPDATE producto_eventos SET documento_id = NULL', 'UPDATE ventas SET documento_id = NULL',
            'UPDATE devoluciones SET documento_id = NULL', 'UPDATE stock_operaciones SET dte_documento_id = NULL'] as $sql) {
            $db->prepare("$sql WHERE empresa_id IN
                (SELECT id FROM empresas WHERE razon_social IN (?, ?, ?))")->execute($empresas);
        }
        foreach (['correo_envios', 'correo_verificaciones', 'empresa_bajas', 'soporte_solicitudes', 'dte_cambios_proveedor', 'dte_rangos_externos', 'dte_guias_facturadas', 'dte_referencias', 'dte_documento_lineas', 'devolucion_lineas', 'venta_pagos', 'venta_lineas', 'caja_movimientos', 'dte_habilitacion_eventos', 'dte_habilitaciones', 'dte_intentos', 'dte_envio_documentos', 'dte_envios', 'dte_documentos', 'dte_cafs', 'dte_certificados', 'dte_emisores',
            'stock_consumos', 'stock_lotes', 'stock_movimientos', 'stock_saldos', 'stock_operaciones',
            'lecturas_documento', 'proveedor_producto_memoria', 'archivos_recibidos', 'documento_guias', 'documento_recibido_lineas', 'documentos_recibidos', 'devoluciones', 'ventas', 'turnos'] as $table) {
            $db->prepare("DELETE FROM $table WHERE empresa_id IN
                (SELECT id FROM empresas WHERE razon_social IN (?, ?, ?))")->execute($empresas);
        }
        $db->prepare('DELETE FROM intentos_sesion WHERE cuenta_hash IN (?, ?, ?)')
            ->execute(array_map(static fn(string $email): string => hash('sha256', $email),
                [$correo, $usuarioCreado, "sesion-inexistente-$tag@example.invalid"]));
        $db->prepare('DELETE FROM membresia_eventos WHERE actor_id =
            (SELECT id FROM usuarios WHERE correo = ?)')->execute([$correo]);
        foreach (['contacto_eventos', 'ubicacion_eventos', 'producto_eventos'] as $table) {
            $db->prepare("DELETE FROM $table WHERE usuario_id =
                (SELECT id FROM usuarios WHERE correo = ?)")->execute([$correo]);
        }
        foreach (['membresias_cajas', 'membresias_locales', 'membresias'] as $table) {
            $query = $db->prepare("DELETE FROM $table WHERE usuario_id IN (SELECT id FROM usuarios WHERE correo IN (?, ?))");
            $query->execute([$correo, $usuarioCreado]);
        }
        $db->prepare('DELETE FROM usuarios WHERE correo IN (?, ?)')->execute([$correo, $usuarioCreado]);
        foreach (['destinatario_cierre_principal', 'destinatarios_cierre', 'empresa_impuestos_especificos_vigencias'] as $table) {
            $db->prepare("DELETE FROM $table WHERE empresa_id IN
                (SELECT id FROM empresas WHERE razon_social IN (?, ?, ?))")->execute($empresas);
        }
        foreach (['promociones', 'producto_minimos', 'producto_presentaciones', 'productos', 'categorias_producto', 'contactos', 'cajas'] as $table) {
            $db->prepare("DELETE FROM $table WHERE empresa_id IN
                (SELECT id FROM empresas WHERE razon_social IN (?, ?, ?))")->execute($empresas);
        }
        $db->prepare('DELETE FROM locales WHERE empresa_id IN
            (SELECT id FROM empresas WHERE razon_social IN (?, ?, ?))')->execute($empresas);
        $db->prepare('DELETE FROM empresas WHERE razon_social IN (?, ?, ?)')->execute($empresas);
        $resultado = ['borrado' => true];
    }
    $db->commit();
    echo json_encode($resultado, JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $error;
}
