INSERT IGNORE INTO impuestos_especificos (codigo, nombre) VALUES
    ('licores', 'Licores y destilados'),
    ('vinos', 'Vinos, chichas y sidras'),
    ('cervezas', 'Cervezas y otras bebidas alcohólicas'),
    ('bebidas', 'Bebidas analcohólicas y productos similares'),
    ('bebidas-azucar', 'Bebidas con elevado contenido de azúcares'),
    ('cigarrillos', 'Cigarrillos');

INSERT IGNORE INTO impuestos_especificos_vigencias
    (impuesto_codigo, vigente_desde, porcentaje, utm_por_unidad, codigo_dte, fuente)
VALUES
    ('licores', '2014-10-01', 31.5, 0, 24, 'https://www.sii.cl/preguntas_frecuentes/impuestos_mensuales/001_130_1250.htm'),
    ('vinos', '2014-10-01', 20.5, 0, 25, 'https://www.sii.cl/preguntas_frecuentes/impuestos_mensuales/001_130_1250.htm'),
    ('cervezas', '2014-10-01', 20.5, 0, 26, 'https://www.sii.cl/preguntas_frecuentes/impuestos_mensuales/001_130_1250.htm'),
    ('bebidas', '2014-10-01', 10, 0, 27, 'https://www.sii.cl/preguntas_frecuentes/impuestos_mensuales/001_130_1250.htm'),
    ('bebidas-azucar', '2014-10-01', 18, 0, 271, 'https://www.sii.cl/preguntas_frecuentes/impuestos_mensuales/001_130_1250.htm'),
    ('cigarrillos', '2014-10-01', 30, 0.0010304240, NULL, 'https://www.sii.cl/preguntas_frecuentes/impuestos_mensuales/001_130_0671.htm');

ALTER TABLE productos ADD COLUMN IF NOT EXISTS impuesto_codigo VARCHAR(40) NULL;
ALTER TABLE productos ADD CONSTRAINT fk_productos_impuesto
    FOREIGN KEY IF NOT EXISTS (impuesto_codigo) REFERENCES impuestos_especificos (codigo);
