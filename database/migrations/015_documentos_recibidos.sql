CREATE TABLE IF NOT EXISTS documentos_recibidos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    proveedor_id BIGINT UNSIGNED NOT NULL,
    tipo SMALLINT UNSIGNED NOT NULL CHECK (tipo IN (33, 34, 52, 56, 61)),
    folio BIGINT UNSIGNED NOT NULL CHECK (folio > 0),
    fecha DATE NOT NULL,
    monto_total DECIMAL(14,0) NOT NULL CHECK (monto_total >= 0),
    referido_id BIGINT UNSIGNED NULL,
    local_codigo VARCHAR(24) NOT NULL,
    clave VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    registrado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (empresa_id, proveedor_id, tipo, folio),
    UNIQUE KEY (empresa_id, clave),
    UNIQUE KEY (empresa_id, id),
    FOREIGN KEY (empresa_id, proveedor_id) REFERENCES contactos (empresa_id, id),
    FOREIGN KEY (empresa_id, referido_id) REFERENCES documentos_recibidos (empresa_id, id),
    FOREIGN KEY (empresa_id, local_codigo) REFERENCES locales (empresa_id, codigo),
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS documento_recibido_lineas (
    empresa_id BIGINT UNSIGNED NOT NULL,
    documento_id BIGINT UNSIGNED NOT NULL,
    linea SMALLINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    presentacion VARCHAR(60) NOT NULL,
    equivalencia DECIMAL(18,6) NOT NULL CHECK (equivalencia > 0),
    cantidad DECIMAL(18,6) NOT NULL CHECK (cantidad > 0),
    recibida DECIMAL(18,6) NOT NULL CHECK (recibida >= 0),
    precio_unitario DECIMAL(14,2) NULL CHECK (precio_unitario >= 0),
    CHECK (recibida <= cantidad),
    PRIMARY KEY (empresa_id, documento_id, linea),
    FOREIGN KEY (empresa_id, documento_id) REFERENCES documentos_recibidos (empresa_id, id),
    FOREIGN KEY (empresa_id, producto_id) REFERENCES productos (empresa_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS documento_guias (
    empresa_id BIGINT UNSIGNED NOT NULL,
    factura_id BIGINT UNSIGNED NOT NULL,
    guia_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (empresa_id, factura_id, guia_id),
    UNIQUE KEY (empresa_id, guia_id),
    FOREIGN KEY (empresa_id, factura_id) REFERENCES documentos_recibidos (empresa_id, id),
    FOREIGN KEY (empresa_id, guia_id) REFERENCES documentos_recibidos (empresa_id, id)
) ENGINE=InnoDB;

ALTER TABLE stock_operaciones MODIFY tipo
    ENUM('inicial', 'ajuste_entrada', 'ajuste_salida', 'merma', 'traspaso', 'recepcion', 'devolucion_proveedor') NOT NULL;
ALTER TABLE stock_operaciones ADD COLUMN IF NOT EXISTS documento_id BIGINT UNSIGNED NULL;
ALTER TABLE stock_operaciones ADD CONSTRAINT fk_stock_operaciones_documento
    FOREIGN KEY IF NOT EXISTS (empresa_id, documento_id) REFERENCES documentos_recibidos (empresa_id, id);
ALTER TABLE stock_movimientos ADD COLUMN IF NOT EXISTS documento_linea SMALLINT UNSIGNED NULL;
