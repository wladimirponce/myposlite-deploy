CREATE TABLE IF NOT EXISTS lecturas_documento (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    archivo_id BIGINT UNSIGNED NOT NULL,
    estado ENUM('procesando', 'lista', 'error') NOT NULL,
    modelo VARCHAR(80) NOT NULL,
    resultado LONGTEXT NULL,
    error VARCHAR(300) NULL,
    tokens_entrada INT UNSIGNED NULL,
    tokens_salida INT UNSIGNED NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    creada_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    terminada_en DATETIME(6) NULL,
    documento_id BIGINT UNSIGNED NULL,
    UNIQUE KEY (empresa_id, id),
    KEY (empresa_id, archivo_id, id),
    FOREIGN KEY (empresa_id, archivo_id) REFERENCES archivos_recibidos (empresa_id, id),
    FOREIGN KEY (empresa_id, documento_id) REFERENCES documentos_recibidos (empresa_id, id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS proveedor_producto_memoria (
    empresa_id BIGINT UNSIGNED NOT NULL,
    proveedor_id BIGINT UNSIGNED NOT NULL,
    clave VARCHAR(160) NOT NULL,
    presentacion_id BIGINT UNSIGNED NOT NULL,
    usos INT UNSIGNED NOT NULL DEFAULT 1,
    actualizada_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (empresa_id, proveedor_id, clave),
    FOREIGN KEY (empresa_id, proveedor_id) REFERENCES contactos (empresa_id, id),
    FOREIGN KEY (empresa_id, presentacion_id) REFERENCES producto_presentaciones (empresa_id, id)
) ENGINE=InnoDB;
