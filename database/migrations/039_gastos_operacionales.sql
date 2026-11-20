CREATE TABLE IF NOT EXISTS gastos_operacionales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    local_codigo VARCHAR(24) NOT NULL,
    fecha DATE NOT NULL,
    categoria ENUM('arriendo', 'servicios_basicos', 'sueldos', 'honorarios', 'impuestos', 'mantencion', 'publicidad', 'transporte', 'otros') NOT NULL DEFAULT 'otros',
    descripcion VARCHAR(200) NOT NULL,
    documento_ref VARCHAR(80) NULL,
    proveedor_nombre VARCHAR(140) NULL,
    medio ENUM('efectivo', 'transferencia', 'tarjeta', 'debito_automatico', 'cheque') NOT NULL DEFAULT 'transferencia',
    monto BIGINT UNSIGNED NOT NULL CHECK (monto > 0),
    usuario_id BIGINT UNSIGNED NOT NULL,
    creado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY (empresa_id, local_codigo, fecha),
    KEY (empresa_id, fecha, categoria),
    FOREIGN KEY (empresa_id, local_codigo) REFERENCES locales (empresa_id, codigo),
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;
