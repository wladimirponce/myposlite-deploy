INSERT IGNORE INTO permisos (codigo) VALUES ('pos.devolver');
INSERT IGNORE INTO roles_permisos (rol_codigo, permiso_codigo) VALUES ('administrador', 'pos.devolver'), ('supervisor', 'pos.devolver');

ALTER TABLE stock_operaciones MODIFY tipo
    ENUM('inicial', 'ajuste_entrada', 'ajuste_salida', 'merma', 'traspaso', 'recepcion', 'devolucion_proveedor', 'venta', 'devolucion_venta') NOT NULL;

-- El envío automático de boletas lo hace el programador, sin usuario.
ALTER TABLE dte_envios MODIFY usuario_id BIGINT UNSIGNED NULL;
ALTER TABLE dte_intentos MODIFY usuario_id BIGINT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS turnos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    local_codigo VARCHAR(24) NOT NULL,
    caja_codigo VARCHAR(24) NOT NULL,
    -- 1 mientras está abierto y NULL al cerrar: el índice único admite un solo turno abierto por caja.
    abierto TINYINT NULL DEFAULT 1,
    monto_inicial BIGINT UNSIGNED NOT NULL,
    abierto_por BIGINT UNSIGNED NOT NULL,
    abierto_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    efectivo_esperado BIGINT NULL,
    efectivo_contado BIGINT UNSIGNED NULL,
    diferencia BIGINT NULL,
    cerrado_por BIGINT UNSIGNED NULL,
    cerrado_en DATETIME(6) NULL,
    UNIQUE KEY (empresa_id, id),
    UNIQUE KEY caja_abierta (empresa_id, local_codigo, caja_codigo, abierto),
    FOREIGN KEY (empresa_id, local_codigo, caja_codigo) REFERENCES cajas (empresa_id, local_codigo, codigo),
    FOREIGN KEY (abierto_por) REFERENCES usuarios (id),
    FOREIGN KEY (cerrado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ventas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    local_codigo VARCHAR(24) NOT NULL,
    turno_id BIGINT UNSIGNED NOT NULL,
    clave VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    vendedor_id BIGINT UNSIGNED NOT NULL,
    neto BIGINT UNSIGNED NOT NULL,
    iva BIGINT UNSIGNED NOT NULL,
    exento BIGINT UNSIGNED NOT NULL,
    total BIGINT UNSIGNED NOT NULL,
    documento_id BIGINT UNSIGNED NULL,
    edad_confirmada_por BIGINT UNSIGNED NULL,
    creada_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (empresa_id, id),
    UNIQUE KEY (empresa_id, clave),
    KEY (empresa_id, turno_id),
    FOREIGN KEY (empresa_id, turno_id) REFERENCES turnos (empresa_id, id),
    FOREIGN KEY (empresa_id, documento_id) REFERENCES dte_documentos (empresa_id, id),
    FOREIGN KEY (vendedor_id) REFERENCES usuarios (id),
    FOREIGN KEY (edad_confirmada_por) REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS venta_lineas (
    empresa_id BIGINT UNSIGNED NOT NULL,
    venta_id BIGINT UNSIGNED NOT NULL,
    linea SMALLINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    presentacion_id BIGINT UNSIGNED NOT NULL,
    nombre VARCHAR(200) NOT NULL,
    presentacion VARCHAR(60) NOT NULL,
    cantidad DECIMAL(18,6) NOT NULL CHECK (cantidad > 0),
    equivalencia DECIMAL(18,6) NOT NULL,
    precio_unitario BIGINT UNSIGNED NOT NULL,
    monto BIGINT UNSIGNED NOT NULL,
    exento BOOLEAN NOT NULL,
    devuelta DECIMAL(18,6) NOT NULL DEFAULT 0,
    PRIMARY KEY (empresa_id, venta_id, linea),
    FOREIGN KEY (empresa_id, venta_id) REFERENCES ventas (empresa_id, id),
    FOREIGN KEY (empresa_id, producto_id) REFERENCES productos (empresa_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS venta_pagos (
    empresa_id BIGINT UNSIGNED NOT NULL,
    venta_id BIGINT UNSIGNED NOT NULL,
    linea SMALLINT UNSIGNED NOT NULL,
    medio ENUM('efectivo', 'tarjeta', 'transferencia') NOT NULL,
    monto BIGINT UNSIGNED NOT NULL,
    recibido BIGINT UNSIGNED NULL,
    PRIMARY KEY (empresa_id, venta_id, linea),
    FOREIGN KEY (empresa_id, venta_id) REFERENCES ventas (empresa_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS caja_movimientos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    turno_id BIGINT UNSIGNED NOT NULL,
    tipo ENUM('ingreso', 'retiro', 'devolucion') NOT NULL,
    medio ENUM('efectivo', 'tarjeta', 'transferencia') NOT NULL DEFAULT 'efectivo',
    monto BIGINT UNSIGNED NOT NULL CHECK (monto > 0),
    causa VARCHAR(200) NOT NULL,
    clave VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    creado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (empresa_id, clave),
    KEY (empresa_id, turno_id),
    FOREIGN KEY (empresa_id, turno_id) REFERENCES turnos (empresa_id, id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

ALTER TABLE stock_operaciones ADD COLUMN IF NOT EXISTS venta_id BIGINT UNSIGNED NULL;
ALTER TABLE stock_operaciones ADD CONSTRAINT fk_stock_operaciones_venta
    FOREIGN KEY IF NOT EXISTS (empresa_id, venta_id) REFERENCES ventas (empresa_id, id);
