INSERT IGNORE INTO permisos (codigo) VALUES ('documentos.emitir');
INSERT IGNORE INTO roles_permisos (rol_codigo, permiso_codigo) VALUES ('administrador', 'documentos.emitir'), ('supervisor', 'documentos.emitir');

-- La factura y la guía exigen giro, dirección y comuna del receptor.
ALTER TABLE contactos ADD COLUMN IF NOT EXISTS giro VARCHAR(40) NULL;
ALTER TABLE contactos ADD COLUMN IF NOT EXISTS direccion VARCHAR(70) NULL;
ALTER TABLE contactos ADD COLUMN IF NOT EXISTS comuna VARCHAR(20) NULL;

ALTER TABLE dte_documentos ADD COLUMN IF NOT EXISTS receptor_id BIGINT UNSIGNED NULL;
ALTER TABLE dte_documentos ADD COLUMN IF NOT EXISTS local_codigo VARCHAR(24) NULL;
ALTER TABLE dte_documentos ADD CONSTRAINT fk_dte_documentos_receptor
    FOREIGN KEY IF NOT EXISTS (empresa_id, receptor_id) REFERENCES contactos (empresa_id, id);

CREATE TABLE IF NOT EXISTS dte_documento_lineas (
    empresa_id BIGINT UNSIGNED NOT NULL,
    documento_id BIGINT UNSIGNED NOT NULL,
    linea SMALLINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NULL,
    presentacion_id BIGINT UNSIGNED NULL,
    nombre VARCHAR(200) NOT NULL,
    presentacion VARCHAR(60) NULL,
    cantidad DECIMAL(18,6) NOT NULL,
    equivalencia DECIMAL(18,6) NULL,
    precio_unitario BIGINT UNSIGNED NOT NULL,
    monto BIGINT UNSIGNED NOT NULL,
    exento BOOLEAN NOT NULL,
    PRIMARY KEY (empresa_id, documento_id, linea),
    FOREIGN KEY (empresa_id, documento_id) REFERENCES dte_documentos (empresa_id, id),
    FOREIGN KEY (empresa_id, producto_id) REFERENCES productos (empresa_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dte_referencias (
    empresa_id BIGINT UNSIGNED NOT NULL,
    documento_id BIGINT UNSIGNED NOT NULL,
    linea SMALLINT UNSIGNED NOT NULL,
    tipo SMALLINT UNSIGNED NOT NULL,
    folio BIGINT UNSIGNED NOT NULL,
    fecha DATE NOT NULL,
    codigo TINYINT UNSIGNED NULL,
    razon VARCHAR(90) NOT NULL,
    -- Documento emitido por Lite al que se refiere, o archivo que prueba una referencia histórica emitida en otro sistema.
    referido_id BIGINT UNSIGNED NULL,
    archivo_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (empresa_id, documento_id, linea),
    FOREIGN KEY (empresa_id, documento_id) REFERENCES dte_documentos (empresa_id, id),
    FOREIGN KEY (empresa_id, referido_id) REFERENCES dte_documentos (empresa_id, id)
) ENGINE=InnoDB;

-- Una guía emitida se factura una sola vez.
CREATE TABLE IF NOT EXISTS dte_guias_facturadas (
    empresa_id BIGINT UNSIGNED NOT NULL,
    guia_id BIGINT UNSIGNED NOT NULL,
    factura_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (empresa_id, guia_id),
    FOREIGN KEY (empresa_id, guia_id) REFERENCES dte_documentos (empresa_id, id),
    FOREIGN KEY (empresa_id, factura_id) REFERENCES dte_documentos (empresa_id, id)
) ENGINE=InnoDB;

ALTER TABLE stock_operaciones MODIFY tipo
    ENUM('inicial', 'ajuste_entrada', 'ajuste_salida', 'merma', 'traspaso', 'recepcion', 'devolucion_proveedor', 'venta',
        'devolucion_venta', 'despacho', 'devolucion_cliente') NOT NULL;
ALTER TABLE stock_operaciones ADD COLUMN IF NOT EXISTS dte_documento_id BIGINT UNSIGNED NULL;
ALTER TABLE stock_operaciones ADD CONSTRAINT fk_stock_operaciones_dte
    FOREIGN KEY IF NOT EXISTS (empresa_id, dte_documento_id) REFERENCES dte_documentos (empresa_id, id);
