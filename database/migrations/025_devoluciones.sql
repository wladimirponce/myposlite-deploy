-- Facturas, guías y notas exigen la actividad económica del emisor.
ALTER TABLE dte_emisores ADD COLUMN IF NOT EXISTS acteco INT UNSIGNED NULL;
ALTER TABLE dte_documentos MODIFY origen ENUM('certificacion', 'venta', 'devolucion', 'emision') NOT NULL;
ALTER TABLE dte_envios MODIFY motivo ENUM('set_boletas', 'boletas', 'rcof_set', 'documentos') NOT NULL;

CREATE TABLE IF NOT EXISTS devoluciones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    venta_id BIGINT UNSIGNED NOT NULL,
    turno_id BIGINT UNSIGNED NOT NULL,
    clave VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    motivo VARCHAR(200) NOT NULL,
    medio ENUM('efectivo', 'tarjeta', 'transferencia') NOT NULL,
    monto BIGINT UNSIGNED NOT NULL,
    fisica BOOLEAN NOT NULL,
    documento_id BIGINT UNSIGNED NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    creada_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (empresa_id, id),
    UNIQUE KEY (empresa_id, clave),
    KEY (empresa_id, venta_id),
    FOREIGN KEY (empresa_id, venta_id) REFERENCES ventas (empresa_id, id),
    FOREIGN KEY (empresa_id, turno_id) REFERENCES turnos (empresa_id, id),
    FOREIGN KEY (empresa_id, documento_id) REFERENCES dte_documentos (empresa_id, id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS devolucion_lineas (
    empresa_id BIGINT UNSIGNED NOT NULL,
    devolucion_id BIGINT UNSIGNED NOT NULL,
    linea SMALLINT UNSIGNED NOT NULL,
    cantidad DECIMAL(18,6) NOT NULL CHECK (cantidad > 0),
    monto BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (empresa_id, devolucion_id, linea),
    FOREIGN KEY (empresa_id, devolucion_id) REFERENCES devoluciones (empresa_id, id)
) ENGINE=InnoDB;

ALTER TABLE stock_operaciones ADD COLUMN IF NOT EXISTS devolucion_id BIGINT UNSIGNED NULL;
ALTER TABLE stock_operaciones ADD CONSTRAINT fk_stock_operaciones_devolucion
    FOREIGN KEY IF NOT EXISTS (empresa_id, devolucion_id) REFERENCES devoluciones (empresa_id, id);
