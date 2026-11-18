<?php

declare(strict_types=1);

final class SembradorCatalogo
{
    /**
     * Siembra el catálogo maestro correspondiente al rubro para una empresa dada.
     * Retorna la cantidad de productos creados o vinculados.
     */
    public static function sembrar(PDO $db, string|int $empresaId, string $rubro): int
    {
        $rubro = strtolower(trim($rubro));
        if ($rubro !== 'farmacia' && $rubro !== 'minimarket') {
            return 0;
        }

        $archivo = match ($rubro) {
            'farmacia' => dirname(__DIR__, 2) . '/storage/catalogos_maestros/catalogo_farmacia.json',
            'minimarket' => dirname(__DIR__, 2) . '/storage/catalogos_maestros/catalogo_minimarket.json',
            default => null,
        };

        if (!$archivo || !file_exists($archivo)) {
            return 0;
        }

        $items = json_decode((string) file_get_contents($archivo), true);
        if (!is_array($items) || empty($items)) {
            return 0;
        }

        // 1. Obtener y crear categorías únicas
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

        // 2. Sentencias preparadas para productos y presentaciones
        $stmtProd = $db->prepare('INSERT INTO productos 
            (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                nombre = VALUES(nombre),
                categoria_id = VALUES(categoria_id),
                imagen_url = VALUES(imagen_url)');

        $stmtPres = $db->prepare('INSERT INTO producto_presentaciones
            (empresa_id, producto_id, nombre, codigo_barras, equivalencia, precio)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                precio = VALUES(precio),
                codigo_barras = VALUES(codigo_barras)');

        $stmtGetId = $db->prepare('SELECT id FROM productos WHERE empresa_id = ? AND sku = ?');

        $contador = 0;
        foreach ($items as $it) {
            $sku = (string) ($it['sku'] ?? '');
            $nombre = (string) ($it['nombre'] ?? '');
            if ($sku === '' || $nombre === '') {
                continue;
            }
            $catName = (string) ($it['categoria'] ?? 'General');
            $catId = $mapCategorias[$catName] ?? null;
            $unidad = in_array($it['unidad_base'] ?? '', ['unidad', 'kg'], true) ? $it['unidad_base'] : 'unidad';
            $afecto = !empty($it['afecto_iva']) ? 1 : 0;
            $img = !empty($it['imagen_url']) ? (string) $it['imagen_url'] : null;

            $stmtProd->execute([$empresaId, $sku, $nombre, $unidad, $afecto, 0, $catId, $img]);
            
            $stmtGetId->execute([$empresaId, $sku]);
            $prodId = $stmtGetId->fetchColumn();

            if ($prodId !== false) {
                $precio = max(0, (float) ($it['precio'] ?? 0));
                $codigoBarras = !empty($it['codigo_barras']) ? (string) $it['codigo_barras'] : null;
                $stmtPres->execute([$empresaId, (int) $prodId, 'Unidad', $codigoBarras, 1.0, $precio]);
                $contador++;
            }
        }

        return $contador;
    }
}
