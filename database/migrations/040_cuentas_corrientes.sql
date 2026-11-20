CREATE TABLE IF NOT EXISTS cuentas_corrientes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    contacto_id BIGINT UNSIGNED NOT NULL,
    limite_credito BIGINT UNSIGNED NULL,
    saldo BIGINT NOT NULL DEFAULT 0,
    ultima_operacion_en DATETIME(6) NULL,
    creado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (empresa_id, contacto_id),
    UNIQUE KEY (empresa_id, id),
    FOREIGN KEY (empresa_id, contacto_id) REFERENCES contactos (empresa_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cuenta_corriente_movimientos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    cuenta_id BIGINT UNSIGNED NOT NULL,
    local_codigo VARCHAR(24) NOT NULL,
    tipo ENUM('cargo', 'abono') NOT NULL,
    monto BIGINT UNSIGNED NOT NULL CHECK (monto > 0),
    medio ENUM('fiado', 'efectivo', 'tarjeta', 'transferencia') NOT NULL DEFAULT 'fiado',
    descripcion VARCHAR(240) NOT NULL,
    venta_id BIGINT UNSIGNED NULL,
    fecha_vencimiento DATE NULL,
    saldo_despues BIGINT NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    creado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY (empresa_id, cuenta_id, creado_en),
    KEY (empresa_id, local_codigo, creado_en),
    FOREIGN KEY (empresa_id, cuenta_id) REFERENCES cuentas_corrientes (empresa_id, id),
    FOREIGN KEY (empresa_id, local_codigo) REFERENCES locales (empresa_id, codigo),
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;
