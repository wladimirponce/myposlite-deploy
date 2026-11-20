<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaHelper.php';

function campo(mixed $value, string $label, int $max): string
{
    if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $max) {
        throw new InvalidArgumentException("Revise $label.");
    }
    return trim($value);
}

function guardarProducto(PDO $db, string $empresa, string $local, string $id, array $input, bool $editar = false): string
{
    $sku = campo($input['sku'] ?? null, 'el SKU', 48);
    $nombre = campo($input['nombre'] ?? null, 'el nombre del producto', 140);
    $unidad = $input['unidadBase'] ?? null;
    $afecto = $input['afectoIva'] ?? null;
    $edad = $input['verificarEdad'] ?? false;
    $variantes = $input['presentaciones'] ?? null;
    $categoria = $input['categoria'] ?? null;
    // Campos opcionales: omitir conserva el valor, null lo retira.
    $opcionales = [];
    $impuesto = $input['impuestoCodigo'] ?? null;
    if ($impuesto !== null) {
        if (!is_string($impuesto) || !preg_match('/^[a-z0-9-]{1,40}$/D', $impuesto)) {
            throw new InvalidArgumentException('Seleccione un impuesto válido.');
        }
        $validar = $db->prepare('SELECT codigo FROM impuestos_especificos WHERE codigo = ?');
        $validar->execute([$impuesto]);
        if ($validar->fetchColumn() === false) {
            throw new InvalidArgumentException('Seleccione un impuesto del catálogo.');
        }
    }
    if (array_key_exists('impuestoCodigo', $input)) {
        $opcionales['impuesto_codigo'] = $impuesto;
    }
    $costo = $input['costo'] ?? null;
    if ($costo !== null && (!is_string($costo) || !preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $costo))) {
        throw new InvalidArgumentException('Ingrese un costo en pesos con hasta dos decimales.');
    }
    if (array_key_exists('costo', $input)) {
        $opcionales['costo'] = $costo;
    }
    $margen = $input['margenMinimo'] ?? null;
    if ($margen !== null && (!is_string($margen) || !preg_match('/^\d{1,2}(?:\.\d{1,2})?$/D', $margen))) {
        throw new InvalidArgumentException('Ingrese un margen mínimo entre 0 y 99,99 %.');
    }
    if (array_key_exists('margenMinimo', $input)) {
        $opcionales['margen_minimo'] = $margen;
    }
    $proveedor = $input['proveedorId'] ?? null;
    if ($proveedor !== null) {
        if (!is_string($proveedor) || !preg_match('/^[1-9][0-9]{0,19}$/D', $proveedor)) {
            throw new InvalidArgumentException('Seleccione un proveedor válido.');
        }
        $validar = $db->prepare('SELECT proveedor FROM contactos WHERE empresa_id = ? AND id = ? LOCK IN SHARE MODE');
        $validar->execute([$empresa, $proveedor]);
        if (!$validar->fetchColumn()) {
            throw new InvalidArgumentException('Seleccione un proveedor de la empresa.');
        }
    }
    if (array_key_exists('proveedorId', $input)) {
        $opcionales['proveedor_habitual_id'] = $proveedor;
    }
    $principioActivo = $input['principioActivo'] ?? null;
    if ($principioActivo !== null && (!is_string($principioActivo) || strlen(trim($principioActivo)) > 255)) {
        throw new InvalidArgumentException('Revise el principio activo.');
    }
    if (array_key_exists('principioActivo', $input)) {
        $opcionales['principio_activo'] = $principioActivo === null || trim($principioActivo) === '' ? null : trim($principioActivo);
    }
    $laboratorio = $input['laboratorio'] ?? null;
    if ($laboratorio !== null && (!is_string($laboratorio) || strlen(trim($laboratorio)) > 100)) {
        throw new InvalidArgumentException('Revise el laboratorio o marca.');
    }
    if (array_key_exists('laboratorio', $input)) {
        $opcionales['laboratorio'] = $laboratorio === null || trim($laboratorio) === '' ? null : trim($laboratorio);
    }
    $imagenUrl = $input['imagenUrl'] ?? null;
    if ($imagenUrl !== null && (!is_string($imagenUrl) || strlen(trim($imagenUrl)) > 500)) {
        throw new InvalidArgumentException('Revise la URL de imagen del producto.');
    }
    if (array_key_exists('imagenUrl', $input)) {
        $opcionales['imagen_url'] = $imagenUrl === null || trim($imagenUrl) === '' ? null : trim($imagenUrl);
    }
    $cambiaMinimo = array_key_exists('minimoLocal', $input);
    $minimo = $input['minimoLocal'] ?? null;
    if ($minimo !== null && (!is_string($minimo) || !preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', $minimo))) {
        throw new InvalidArgumentException('Ingrese un mínimo de stock válido.');
    }
    if ($categoria !== null && $categoria !== '') {
        $categoria = campo($categoria, 'la categoría', 80);
    }
    if (!in_array($unidad, ['unidad', 'kg'], true) || !is_bool($afecto) || !is_bool($edad)
        || !is_array($variantes) || !array_is_list($variantes) || !$variantes || count($variantes) > 20) {
        throw new InvalidArgumentException('Revise unidad base, impuestos y presentaciones.');
    }
    $normalizadas = [];
    foreach ($variantes as $variante) {
        if (!is_array($variante)) {
            throw new InvalidArgumentException('Presentación inválida.');
        }
        $equivalencia = $variante['equivalencia'] ?? null;
        $precio = $variante['precio'] ?? null;
        $codigo = $variante['codigoBarras'] ?? null;
        $presentacionId = $variante['id'] ?? null;
        if ($presentacionId !== null && (!$editar || !is_string($presentacionId) || !preg_match('/^[1-9][0-9]{0,19}$/D', $presentacionId))) {
            throw new InvalidArgumentException('Presentación inválida.');
        }
        if (!is_string($equivalencia) || !preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', $equivalencia)
            || !preg_match('/[1-9]/', $equivalencia)
            || !is_string($precio) || !preg_match('/^\d{1,14}$/D', $precio)) {
            throw new InvalidArgumentException('Ingrese equivalencia positiva y precio entero para cada presentación.');
        }
        $normalizadas[] = [campo($variante['nombre'] ?? null, 'el nombre de presentación', 60),
            $codigo === '' || $codigo === null ? null : campo($codigo, 'el código de barras', 48),
            $equivalencia, $precio, $presentacionId];
    }
    $categoriaId = null;
    if ($categoria !== null && $categoria !== '') {
        $db->prepare('INSERT INTO categorias_producto (empresa_id, nombre) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')->execute([$empresa, $categoria]);
        $categoriaId = $db->lastInsertId();
    }
    $valores = [$sku, $nombre, $unidad, (int) $afecto, (int) $edad, $categoriaId];
    if ($editar) {
        $producto = $input['id'] ?? null;
        $revision = $input['revision'] ?? null;
        if (!is_string($producto) || !preg_match('/^\d{1,20}$/D', $producto) ||
            !is_string($revision) || !preg_match('/^\d{1,20}$/D', $revision)) {
            throw new InvalidArgumentException('Producto o revisión inválidos.');
        }
        $query = $db->prepare('SELECT revision, unidad_base FROM productos WHERE empresa_id = ? AND id = ? FOR UPDATE');
        $query->execute([$empresa, $producto]);
        $vigente = $query->fetch(PDO::FETCH_ASSOC);
        $actual = $vigente ? $vigente['revision'] : false;
        if ($actual === false || (string) $actual !== $revision) {
            throw new RuntimeException($actual === false ? 'Producto no encontrado.' : 'El producto fue actualizado. Recargue el catálogo.',
                $actual === false ? 404 : 409);
        }
        if ($vigente['unidad_base'] !== $unidad) {
            // Los saldos y movimientos están en la unidad base: cambiarla los dejaría sin sentido.
            $query = $db->prepare('SELECT 1 FROM stock_movimientos WHERE empresa_id = ? AND producto_id = ? LIMIT 1');
            $query->execute([$empresa, $producto]);
            if ($query->fetchColumn() !== false) {
                throw new RuntimeException('El producto ya tiene movimientos de inventario. No se puede cambiar su unidad base.', 409);
            }
        }
        $db->prepare('UPDATE productos SET sku = ?, nombre = ?, unidad_base = ?, afecto_iva = ?,
            verificar_edad = ?, categoria_id = ?, revision = revision + 1 WHERE empresa_id = ? AND id = ?')
            ->execute([...$valores, $empresa, $producto]);
    } else {
        $db->prepare('INSERT INTO productos
            (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$empresa, ...$valores]);
        $producto = $db->lastInsertId();
    }
    guardarPresentaciones($db, $empresa, $producto, $normalizadas, $editar);
    if ($opcionales) {
        $cols = SchemaHelper::asegurarColumnasProductos($db);
        $opcionalesFiltrados = [];
        foreach ($opcionales as $col => $val) {
            if (in_array(strtolower($col), $cols, true)) {
                $opcionalesFiltrados[$col] = $val;
            }
        }
        if ($opcionalesFiltrados) {
            $columnas = implode(', ', array_map(static fn(string $columna): string => "$columna = ?", array_keys($opcionalesFiltrados)));
            $db->prepare("UPDATE productos SET $columnas WHERE empresa_id = ? AND id = ?")
                ->execute([...array_values($opcionalesFiltrados), $empresa, $producto]);
        }
    }
    if ($cambiaMinimo) {
        if ($minimo === null) {
            $db->prepare('DELETE FROM producto_minimos WHERE empresa_id = ? AND producto_id = ? AND local_codigo = ?')
                ->execute([$empresa, $producto, $local]);
        } else {
            $db->prepare('INSERT INTO producto_minimos (empresa_id, producto_id, local_codigo, minimo) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE minimo = VALUES(minimo)')->execute([$empresa, $producto, $local, $minimo]);
        }
    }
    $db->prepare('INSERT INTO producto_eventos (empresa_id, producto_id, local_codigo, usuario_id, revision, accion)
        SELECT empresa_id, id, ?, ?, revision, ? FROM productos WHERE empresa_id = ? AND id = ?')
        ->execute([$local, $id, $editar ? 'editar' : 'crear', $empresa, $producto]);
    return $producto;
}

function guardarPresentaciones(PDO $db, string $empresa, string $producto, array $presentaciones, bool $editar): void
{
    if ($editar) {
        $query = $db->prepare('SELECT id, nombre FROM producto_presentaciones WHERE empresa_id = ? AND producto_id = ?');
        $query->execute([$empresa, $producto]);
        $existentes = $query->fetchAll(PDO::FETCH_KEY_PAIR);
        $porNombre = array_flip($existentes);
        $conservar = [];
        foreach ($presentaciones as &$fila) {
            $fila[4] ??= isset($porNombre[$fila[0]]) ? (string) $porNombre[$fila[0]] : null;
            if ($fila[4] === null) {
                continue;
            }
            if (!isset($existentes[$fila[4]]) || isset($conservar[$fila[4]])) {
                throw new InvalidArgumentException('La presentación no pertenece al producto o está duplicada.');
            }
            $conservar[$fila[4]] = true;
        }
        unset($fila);
        $borrar = $db->prepare('DELETE FROM producto_presentaciones WHERE empresa_id = ? AND producto_id = ? AND id = ?');
        foreach (array_diff_key($existentes, $conservar) as $id => $nombre) {
            try {
                $borrar->execute([$empresa, $producto, $id]);
            } catch (PDOException $error) {
                if ((int) ($error->errorInfo[1] ?? 0) === 1451) {
                    throw new RuntimeException('La presentación tiene promociones vinculadas.', 409, $error);
                }
                throw $error;
            }
        }
        $db->prepare('UPDATE producto_presentaciones SET codigo_barras = NULL WHERE empresa_id = ? AND producto_id = ?')
            ->execute([$empresa, $producto]);
    }
    $insert = $db->prepare('INSERT INTO producto_presentaciones
        (empresa_id, producto_id, nombre, codigo_barras, equivalencia, precio) VALUES (?, ?, ?, ?, ?, ?)');
    $update = $db->prepare('UPDATE producto_presentaciones SET nombre = ?, codigo_barras = ?, equivalencia = ?, precio = ?
        WHERE empresa_id = ? AND producto_id = ? AND id = ?');
    foreach ($presentaciones as [$nombre, $codigo, $equivalencia, $precio, $id]) {
        if ($id === null) {
            $insert->execute([$empresa, $producto, $nombre, $codigo, $equivalencia, $precio]);
        } else {
            $update->execute([$nombre, $codigo, $equivalencia, $precio, $empresa, $producto, $id]);
        }
    }
}
