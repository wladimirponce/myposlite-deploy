<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaHelper.php';

final class SembradorCatalogo
{
    /**
     * Siembra el catálogo maestro correspondiente al rubro para una empresa dada.
     * Retorna la cantidad de productos creados o actualizados.
     */
    public static function sembrar(PDO $db, string|int $empresaId, string $rubro): int
    {
        $rubro = strtolower(trim($rubro));
        $rubrosValidos = ['farmacia', 'minimarket', 'botilleria', 'ferreteria'];
        if (!in_array($rubro, $rubrosValidos, true)) {
            return 0;
        }

        $archivo = match ($rubro) {
            'farmacia' => dirname(__DIR__, 2) . '/storage/catalogos_maestros/catalogo_farmacia.json',
            'minimarket' => dirname(__DIR__, 2) . '/storage/catalogos_maestros/catalogo_minimarket.json',
            'botilleria' => dirname(__DIR__, 2) . '/storage/catalogos_maestros/catalogo_botilleria.json',
            'ferreteria' => dirname(__DIR__, 2) . '/storage/catalogos_maestros/catalogo_ferreteria.json',
            default => null,
        };

        if (!$archivo || !file_exists($archivo)) {
            return 0;
        }

        $items = json_decode((string) file_get_contents($archivo), true);
        if (!is_array($items) || empty($items)) {
            return 0;
        }

        // 1. Asegurar esquema en la base de datos (auto-reparación y compatibilidad multi-rubro)
        $colsDisponibles = SchemaHelper::asegurarColumnasProductos($db);

        // 2. Obtener y crear categorías únicas
        $categoriasUnicas = [];
        foreach ($items as $item) {
            $cat = trim((string) ($item['categoria'] ?? 'General'));
            if ($cat !== '') {
                $categoriasUnicas[$cat] = true;
            }
        }

        $stmtCat = $db->prepare('INSERT IGNORE INTO categorias_producto (empresa_id, nombre) VALUES (?, ?)');
        foreach (array_keys($categoriasUnicas) as $nombreCat) {
            $stmtCat->execute([$empresaId, $nombreCat]);
        }

        // Mapear categorías a IDs
        $stmtCats = $db->prepare('SELECT nombre, id FROM categorias_producto WHERE empresa_id = ?');
        $stmtCats->execute([$empresaId]);
        $mapCategorias = $stmtCats->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        // 3. Construir dinámicamente el INSERT de productos según las columnas existentes en el repositorio
        $colsInsert = ['empresa_id', 'sku', 'nombre', 'unidad_base', 'afecto_iva', 'verificar_edad'];
        $colsUpdate = ['nombre = VALUES(nombre)', 'unidad_base = VALUES(unidad_base)', 'afecto_iva = VALUES(afecto_iva)'];

        $hasCategoria = in_array('categoria_id', $colsDisponibles, true);
        $hasImagen = in_array('imagen_url', $colsDisponibles, true);
        $hasPrincipio = in_array('principio_activo', $colsDisponibles, true);
        $hasLab = in_array('laboratorio', $colsDisponibles, true);

        if ($hasCategoria) {
            $colsInsert[] = 'categoria_id';
            $colsUpdate[] = 'categoria_id = VALUES(categoria_id)';
        }
        if ($hasImagen) {
            $colsInsert[] = 'imagen_url';
            $colsUpdate[] = 'imagen_url = VALUES(imagen_url)';
        }
        if ($hasPrincipio) {
            $colsInsert[] = 'principio_activo';
            $colsUpdate[] = 'principio_activo = VALUES(principio_activo)';
        }
        if ($hasLab) {
            $colsInsert[] = 'laboratorio';
            $colsUpdate[] = 'laboratorio = VALUES(laboratorio)';
        }

        $placeholders = implode(', ', array_fill(0, count($colsInsert), '?'));
        $sqlProd = "INSERT INTO productos (" . implode(', ', $colsInsert) . ")
            VALUES ($placeholders)
            ON DUPLICATE KEY UPDATE " . implode(', ', $colsUpdate);

        $stmtProd = $db->prepare($sqlProd);

        $stmtPres = $db->prepare('INSERT INTO producto_presentaciones
            (empresa_id, producto_id, nombre, codigo_barras, equivalencia, precio)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                precio = VALUES(precio),
                codigo_barras = VALUES(codigo_barras)');

        $stmtPresFallback = $db->prepare('INSERT INTO producto_presentaciones
            (empresa_id, producto_id, nombre, codigo_barras, equivalencia, precio)
            VALUES (?, ?, ?, NULL, ?, ?)
            ON DUPLICATE KEY UPDATE 
                precio = VALUES(precio)');

        $stmtGetId = $db->prepare('SELECT id FROM productos WHERE empresa_id = ? AND sku = ?');

        // Prefijo para que productos de distintos rubros convivan sin colisionar de SKUs
        $prefijo = match ($rubro) {
            'farmacia' => 'FAR',
            'minimarket' => 'MIN',
            'botilleria' => 'BOT',
            'ferreteria' => 'FER',
            default => strtoupper(substr($rubro, 0, 3)),
        };

        $contador = 0;
        foreach ($items as $it) {
            $skuRaw = trim((string) ($it['sku'] ?? ''));
            $nombreRaw = trim((string) ($it['nombre'] ?? ''));
            if ($nombreRaw === '') {
                continue;
            }

            // Si no tiene SKU, generar uno a partir del código de barras o correlativo
            if ($skuRaw === '') {
                $skuRaw = !empty($it['codigo_barras']) ? (string) $it['codigo_barras'] : substr(md5($nombreRaw), 0, 10);
            }

            // Normalizar SKU con prefijo del rubro si no lo tiene
            if (!str_starts_with($skuRaw, $prefijo . '-')) {
                $sku = substr($prefijo . '-' . $skuRaw, 0, 48);
            } else {
                $sku = substr($skuRaw, 0, 48);
            }

            $nombre = substr($nombreRaw, 0, 140);
            $catName = (string) ($it['categoria'] ?? 'General');
            $catId = $mapCategorias[$catName] ?? null;
            $unidad = in_array($it['unidad_base'] ?? '', ['unidad', 'kg'], true) ? $it['unidad_base'] : 'unidad';
            $afecto = !empty($it['afecto_iva']) ? 1 : 0;
            $verificarEdad = ($rubro === 'botilleria' || !empty($it['verificar_edad'])) ? 1 : 0;

            // Armar parámetros según las columnas que existen en la base
            $params = [$empresaId, $sku, $nombre, $unidad, $afecto, $verificarEdad];
            if ($hasCategoria) {
                $params[] = $catId;
            }
            if ($hasImagen) {
                $params[] = !empty($it['imagen_url']) ? substr((string) $it['imagen_url'], 0, 255) : null;
            }
            if ($hasPrincipio) {
                $params[] = !empty($it['principio_activo']) ? substr((string) $it['principio_activo'], 0, 255) : null;
            }
            if ($hasLab) {
                $params[] = !empty($it['laboratorio']) ? substr((string) $it['laboratorio'], 0, 100) : null;
            }

            try {
                $stmtProd->execute($params);
            } catch (\Throwable $e) {
                // Si un producto específico falla, continuar con los demás
                continue;
            }

            $stmtGetId->execute([$empresaId, $sku]);
            $prodId = $stmtGetId->fetchColumn();

            if ($prodId !== false) {
                $precio = max(0, (float) ($it['precio'] ?? 0));
                $codigoBarras = !empty($it['codigo_barras']) ? substr(trim((string) $it['codigo_barras']), 0, 48) : null;

                try {
                    $stmtPres->execute([$empresaId, (int) $prodId, 'Unidad', $codigoBarras, 1.0, $precio]);
                    $contador++;
                } catch (\PDOException $e) {
                    // Si el código de barras ya pertenece a otro producto en la empresa, guardar la presentación sin código
                    if ($codigoBarras !== null) {
                        try {
                            $stmtPresFallback->execute([$empresaId, (int) $prodId, 'Unidad', 1.0, $precio]);
                            $contador++;
                        } catch (\Throwable) {
                            // Ignorar si ya existe
                        }
                    }
                }
            }
        }

        return $contador;
    }
}
