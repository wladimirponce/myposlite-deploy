UPDATE productos
SET nombre = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
    nombre,
    'Ã±', 'ñ'), 'Ã¡', 'á'), 'Ã©', 'é'), 'Ã­', 'í'), 'Ã³', 'ó'), 'Ãº', 'ú'), 'Ã¼', 'ü'),
    'Ã‘', 'Ñ'), 'Ã', 'Á'), 'Ã‰', 'É'), 'Ã', 'Í'), 'Ã“', 'Ó'), 'Ãš', 'Ú'), 'Â°', '°'), 'Â´', '´')
WHERE BINARY nombre LIKE '%Ã%' OR BINARY nombre LIKE '%Â%';

UPDATE productos
SET principio_activo = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
    principio_activo,
    'Ã±', 'ñ'), 'Ã¡', 'á'), 'Ã©', 'é'), 'Ã­', 'í'), 'Ã³', 'ó'), 'Ãº', 'ú'), 'Ã¼', 'ü'),
    'Ã‘', 'Ñ'), 'Ã', 'Á'), 'Ã‰', 'É'), 'Ã', 'Í'), 'Ã“', 'Ó'), 'Ãš', 'Ú'), 'Â°', '°'), 'Â´', '´')
WHERE principio_activo IS NOT NULL AND (BINARY principio_activo LIKE '%Ã%' OR BINARY principio_activo LIKE '%Â%');

UPDATE productos
SET laboratorio = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
    laboratorio,
    'Ã±', 'ñ'), 'Ã¡', 'á'), 'Ã©', 'é'), 'Ã­', 'í'), 'Ã³', 'ó'), 'Ãº', 'ú'), 'Ã¼', 'ü'),
    'Ã‘', 'Ñ'), 'Ã', 'Á'), 'Ã‰', 'É'), 'Ã', 'Í'), 'Ã“', 'Ó'), 'Ãš', 'Ú'), 'Â°', '°'), 'Â´', '´')
WHERE laboratorio IS NOT NULL AND (BINARY laboratorio LIKE '%Ã%' OR BINARY laboratorio LIKE '%Â%');

UPDATE producto_presentaciones
SET nombre = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
    nombre,
    'Ã±', 'ñ'), 'Ã¡', 'á'), 'Ã©', 'é'), 'Ã­', 'í'), 'Ã³', 'ó'), 'Ãº', 'ú'), 'Ã¼', 'ü'),
    'Ã‘', 'Ñ'), 'Ã', 'Á'), 'Ã‰', 'É'), 'Ã', 'Í'), 'Ã“', 'Ó'), 'Ãš', 'Ú'), 'Â°', '°'), 'Â´', '´')
WHERE BINARY nombre LIKE '%Ã%' OR BINARY nombre LIKE '%Â%';

UPDATE IGNORE categorias_producto
SET nombre = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
    nombre,
    'Ã±', 'ñ'), 'Ã¡', 'á'), 'Ã©', 'é'), 'Ã­', 'í'), 'Ã³', 'ó'), 'Ãº', 'ú'), 'Ã¼', 'ü'),
    'Ã‘', 'Ñ'), 'Ã', 'Á'), 'Ã‰', 'É'), 'Ã', 'Í'), 'Ã“', 'Ó'), 'Ãš', 'Ú'), 'Â°', '°'), 'Â´', '´')
WHERE BINARY nombre LIKE '%Ã%' OR BINARY nombre LIKE '%Â%';
