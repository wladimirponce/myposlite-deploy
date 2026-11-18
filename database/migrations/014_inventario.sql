INSERT IGNORE INTO permisos (codigo) VALUES ('inventario.operar');

INSERT IGNORE INTO roles_permisos (rol_codigo, permiso_codigo) VALUES
    ('administrador', 'inventario.operar'), ('supervisor', 'inventario.operar');

CREATE TABLE IF NOT EXISTS stock_operaciones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    local_codigo VARCHAR(24) NOT NULL,
    tipo ENUM('inicial', 'ajuste_entrada', 'ajuste_salida', 'merma', 'traspaso') NOT NULL,
    local_destino VARCHAR(24) NULL,
    causa VARCHAR(200) NULL,
    clave VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    registrada_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (empresa_id, clave),
    UNIQUE KEY (empresa_id, id),
    FOREIGN KEY (empresa_id, local_codigo) REFERENCES locales (empresa_id, codigo),
    FOREIGN KEY (empresa_id, local_destino) REFERENCES locales (empresa_id, codigo),
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_movimientos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    operacion_id BIGINT UNSIGNED NOT NULL,
    local_codigo VARCHAR(24) NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    cantidad DECIMAL(18,6) NOT NULL CHECK (cantidad <> 0),
    presentacion VARCHAR(60) NOT NULL,
    cantidad_presentacion DECIMAL(18,6) NOT NULL CHECK (cantidad_presentacion > 0),
    equivalencia DECIMAL(18,6) NOT NULL CHECK (equivalencia > 0),
    UNIQUE KEY (empresa_id, id),
    KEY (empresa_id, local_codigo, producto_id, id),
    FOREIGN KEY (empresa_id, operacion_id) REFERENCES stock_operaciones (empresa_id, id),
    FOREIGN KEY (empresa_id, local_codigo) REFERENCES locales (empresa_id, codigo),
    FOREIGN KEY (empresa_id, producto_id) REFERENCES productos (empresa_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_lotes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    local_codigo VARCHAR(24) NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    movimiento_id BIGINT UNSIGNED NOT NULL,
    lote_origen_id BIGINT UNSIGNED NULL,
    vencimiento DATE NULL,
    cantidad_inicial DECIMAL(18,6) NOT NULL CHECK (cantidad_inicial > 0),
    saldo DECIMAL(18,6) NOT NULL CHECK (saldo >= 0),
    UNIQUE KEY (empresa_id, id),
    KEY (empresa_id, local_codigo, producto_id, vencimiento, id),
    FOREIGN KEY (empresa_id, local_codigo) REFERENCES locales (empresa_id, codigo),
    FOREIGN KEY (empresa_id, producto_id) REFERENCES productos (empresa_id, id),
    FOREIGN KEY (empresa_id, movimiento_id) REFERENCES stock_movimientos (empresa_id, id),
    FOREIGN KEY (empresa_id, lote_origen_id) REFERENCES stock_lotes (empresa_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_consumos (
    empresa_id BIGINT UNSIGNED NOT NULL,
    movimiento_id BIGINT UNSIGNED NOT NULL,
    lote_id BIGINT UNSIGNED NOT NULL,
    cantidad DECIMAL(18,6) NOT NULL CHECK (cantidad > 0),
    PRIMARY KEY (empresa_id, movimiento_id, lote_id),
    FOREIGN KEY (empresa_id, movimiento_id) REFERENCES stock_movimientos (empresa_id, id),
    FOREIGN KEY (empresa_id, lote_id) REFERENCES stock_lotes (empresa_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_saldos (
    empresa_id BIGINT UNSIGNED NOT NULL,
    local_codigo VARCHAR(24) NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    saldo DECIMAL(18,6) NOT NULL DEFAULT 0,
    PRIMARY KEY (empresa_id, local_codigo, producto_id),
    FOREIGN KEY (empresa_id, local_codigo) REFERENCES locales (empresa_id, codigo),
    FOREIGN KEY (empresa_id, producto_id) REFERENCES productos (empresa_id, id)
) ENGINE=InnoDB;
