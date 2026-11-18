-- Migración 036: Catálogo Demo Visual para POS Móvil y Doble Pantalla
-- Añade columna imagen_url opcional a productos y siembra catálogo visual para empresas demo.

ALTER TABLE productos ADD COLUMN IF NOT EXISTS imagen_url VARCHAR(255) NULL;

-- 1. Categorías visuales para la empresa demo (empresa_id = 2 o cualquier empresa demo)
INSERT IGNORE INTO categorias_producto (empresa_id, nombre)
SELECT id, 'Cafetería' FROM empresas WHERE id = 2 OR es_demo = 1
UNION ALL
SELECT id, 'Pastelería' FROM empresas WHERE id = 2 OR es_demo = 1
UNION ALL
SELECT id, 'Sandwiches' FROM empresas WHERE id = 2 OR es_demo = 1
UNION ALL
SELECT id, 'Bebidas' FROM empresas WHERE id = 2 OR es_demo = 1
UNION ALL
SELECT id, 'Snacks y Dulces' FROM empresas WHERE id = 2 OR es_demo = 1
UNION ALL
SELECT id, 'Farmacia' FROM empresas WHERE id = 2 OR es_demo = 1
UNION ALL
SELECT id, 'Abarrotes' FROM empresas WHERE id = 2 OR es_demo = 1;

-- 2. Procedimiento o INSERT seguro de productos demo
-- Cafetería
INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-LATTE', 'Café Latte Helado (Iced Latte)', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Cafetería' LIMIT 1),
       '/img/productos/DEMO-LATTE.svg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-CAPU', 'Capuchino Italiano', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Cafetería' LIMIT 1),
       '/img/productos/DEMO-CAPU.svg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-TE-VERDE', 'Té Verde Matcha Frío', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Cafetería' LIMIT 1),
       '/img/productos/DEMO-TE-VERDE.svg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

-- Pastelería
INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-CROISSANT', 'Croissant Mantequilla Horneado', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Pastelería' LIMIT 1),
       '/img/productos/DEMO-CROISSANT.svg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-MUFFIN', 'Muffin de Arándanos', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Pastelería' LIMIT 1),
       '/img/productos/DEMO-MUFFIN.svg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-TARTA', 'Tarta de Zanahoria Casera', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Pastelería' LIMIT 1),
       '/img/productos/DEMO-TARTA.svg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

-- Sandwiches
INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-AVOCADO', 'Avocado Toast (Tostadas con Palta)', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Sandwiches' LIMIT 1),
       '/img/productos/DEMO-AVOCADO.svg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-SANDWICH', 'BLT Sandwich Artesanal', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Sandwiches' LIMIT 1),
       '/img/productos/DEMO-SANDWICH.svg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

-- Bebidas con fotos reales de MyPOS
INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-BEB-COCAZERO', 'Bebida Coca-Cola Zero 591cc', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Bebidas' LIMIT 1),
       '/img/productos/7801610001016.jpg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-BEB-COCALIGHT', 'Bebida Coca-Cola Light 1.5L', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Bebidas' LIMIT 1),
       '/img/productos/7801610001023.jpg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-BEB-CACHANTUNGAS', 'Agua Cachantún con Gas 600cc', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Bebidas' LIMIT 1),
       '/img/productos/7801620000115.jpg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-BEB-CACHANTUNSIN', 'Agua Cachantún sin Gas 600cc', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Bebidas' LIMIT 1),
       '/img/productos/7801620000122.jpg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-BEB-AGUACOCO', 'Agua de Coco Pura Lata 320ml', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Bebidas' LIMIT 1),
       '/img/productos/7801630000201.jpg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

-- Snacks y Dulces con fotos reales
INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-SNACK-BONOBON', 'Galletas Bon o Bon 95g', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Snacks y Dulces' LIMIT 1),
       '/img/productos/7801700000301.jpg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-SNACK-NOTSNACK', 'Barra NotSnack Fudge Brownie 45g', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Snacks y Dulces' LIMIT 1),
       '/img/productos/7801700000318.jpg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-SNACK-CHEETOS', 'Cheetos Palitos de Queso 280g', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Snacks y Dulces' LIMIT 1),
       '/img/productos/7801700000325.jpg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-SNACK-ALFAJOR', 'Alfajorcito Chocolate 12 un', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Snacks y Dulces' LIMIT 1),
       '/img/productos/7801700000332.jpg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-SNACK-BARQUILLOS', 'Barquillos de Chocolate 70g', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Snacks y Dulces' LIMIT 1),
       '/img/productos/7801700000349.jpg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

-- Farmacia y Cuidado Personal
INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-FARMA-MENTHOL', 'Mentholatum Inhalador Nasal', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Farmacia' LIMIT 1),
       '/img/productos/10742206011.jpg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-FARMA-VICHY', 'Vichy Mineral 89 Booster 50ml', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Farmacia' LIMIT 1),
       '/img/productos/3337875543248.jpg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-FARMA-CERAVE', 'CeraVe Limpiador Hidratante 236ml', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Farmacia' LIMIT 1),
       '/img/productos/3337875597180.jpg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-FARMA-BICFLEX', 'Prestobarba Bic Flex3 Hybrid x 5', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Farmacia' LIMIT 1),
       '/img/productos/3086123549999.jpg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-FARMA-FLIXONASE', 'Flixonase 50mcg Spray Nasal 120DS', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Farmacia' LIMIT 1),
       '/img/productos/7800066022144.jpg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-FARMA-ALCOHOLGEL', 'Alcohol Gel Antibacterial 340ml', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Farmacia' LIMIT 1),
       '/img/productos/301320105049.jpg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

INSERT INTO productos (empresa_id, sku, nombre, unidad_base, afecto_iva, verificar_edad, categoria_id, imagen_url)
SELECT e.id, 'DEMO-FARMA-SOLAR', 'Protector Solar FPS 50+ 120ml', 'unidad', 1, 0,
       (SELECT id FROM categorias_producto c WHERE c.empresa_id = e.id AND c.nombre = 'Farmacia' LIMIT 1),
       '/img/productos/1003643434392.jpg'
FROM empresas e WHERE e.id = 2 OR e.es_demo = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen_url = VALUES(imagen_url);

-- 3. Insertar presentaciones con precios y códigos de barra
INSERT INTO producto_presentaciones (empresa_id, producto_id, nombre, codigo_barras, equivalencia, precio)
SELECT p.empresa_id, p.id, 'Unidad',
       CASE p.sku
         WHEN 'DEMO-LATTE' THEN '7809900000010'
         WHEN 'DEMO-CAPU' THEN '7809900000027'
         WHEN 'DEMO-TE-VERDE' THEN '7809900000034'
         WHEN 'DEMO-CROISSANT' THEN '7809900000041'
         WHEN 'DEMO-MUFFIN' THEN '7809900000058'
         WHEN 'DEMO-TARTA' THEN '7809900000065'
         WHEN 'DEMO-AVOCADO' THEN '7809900000072'
         WHEN 'DEMO-SANDWICH' THEN '7809900000089'
         WHEN 'DEMO-BEB-COCAZERO' THEN '7801610001016'
         WHEN 'DEMO-BEB-COCALIGHT' THEN '7801610001023'
         WHEN 'DEMO-BEB-CACHANTUNGAS' THEN '7801620000115'
         WHEN 'DEMO-BEB-CACHANTUNSIN' THEN '7801620000122'
         WHEN 'DEMO-BEB-AGUACOCO' THEN '7801630000201'
         WHEN 'DEMO-SNACK-BONOBON' THEN '7801700000301'
         WHEN 'DEMO-SNACK-NOTSNACK' THEN '7801700000318'
         WHEN 'DEMO-SNACK-CHEETOS' THEN '7801700000325'
         WHEN 'DEMO-SNACK-ALFAJOR' THEN '7801700000332'
         WHEN 'DEMO-SNACK-BARQUILLOS' THEN '7801700000349'
         WHEN 'DEMO-FARMA-MENTHOL' THEN '10742206011'
         WHEN 'DEMO-FARMA-VICHY' THEN '3337875543248'
         WHEN 'DEMO-FARMA-CERAVE' THEN '3337875597180'
         WHEN 'DEMO-FARMA-BICFLEX' THEN '3086123549999'
         WHEN 'DEMO-FARMA-FLIXONASE' THEN '7800066022144'
         WHEN 'DEMO-FARMA-ALCOHOLGEL' THEN '301320105049'
         WHEN 'DEMO-FARMA-SOLAR' THEN '1003643434392'
         ELSE NULL
       END,
       1.000000,
       CASE p.sku
         WHEN 'DEMO-LATTE' THEN 2490
         WHEN 'DEMO-CAPU' THEN 2290
         WHEN 'DEMO-TE-VERDE' THEN 2190
         WHEN 'DEMO-CROISSANT' THEN 1890
         WHEN 'DEMO-MUFFIN' THEN 1990
         WHEN 'DEMO-TARTA' THEN 2890
         WHEN 'DEMO-AVOCADO' THEN 3490
         WHEN 'DEMO-SANDWICH' THEN 4290
         WHEN 'DEMO-BEB-COCAZERO' THEN 1490
         WHEN 'DEMO-BEB-COCALIGHT' THEN 1990
         WHEN 'DEMO-BEB-CACHANTUNGAS' THEN 1190
         WHEN 'DEMO-BEB-CACHANTUNSIN' THEN 1190
         WHEN 'DEMO-BEB-AGUACOCO' THEN 1790
         WHEN 'DEMO-SNACK-BONOBON' THEN 1250
         WHEN 'DEMO-SNACK-NOTSNACK' THEN 1890
         WHEN 'DEMO-SNACK-CHEETOS' THEN 1590
         WHEN 'DEMO-SNACK-ALFAJOR' THEN 3290
         WHEN 'DEMO-SNACK-BARQUILLOS' THEN 1190
         WHEN 'DEMO-FARMA-MENTHOL' THEN 4290
         WHEN 'DEMO-FARMA-VICHY' THEN 20990
         WHEN 'DEMO-FARMA-CERAVE' THEN 13590
         WHEN 'DEMO-FARMA-BICFLEX' THEN 5390
         WHEN 'DEMO-FARMA-FLIXONASE' THEN 16500
         WHEN 'DEMO-FARMA-ALCOHOLGEL' THEN 2490
         WHEN 'DEMO-FARMA-SOLAR' THEN 8990
         ELSE 1000
       END
FROM productos p
WHERE p.sku LIKE 'DEMO-%'
ON DUPLICATE KEY UPDATE
    precio = VALUES(precio),
    codigo_barras = VALUES(codigo_barras);
