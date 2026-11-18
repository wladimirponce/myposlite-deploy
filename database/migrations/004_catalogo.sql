CREATE TABLE IF NOT EXISTS productos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sku VARCHAR(48) NOT NULL,
    nombre VARCHAR(140) NOT NULL,
    unidad_base ENUM('unidad', 'kg') NOT NULL,
    afecto_iva BOOLEAN NOT NULL,
    verificar_edad BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE KEY (empresa_id, sku),
    UNIQUE KEY (empresa_id, id),
    FOREIGN KEY (empresa_id) REFERENCES empresas (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS producto_presentaciones (
    empresa_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    nombre VARCHAR(60) NOT NULL,
    codigo_barras VARCHAR(48) NULL,
    equivalencia DECIMAL(18,6) NOT NULL CHECK (equivalencia > 0),
    precio DECIMAL(14,0) NOT NULL CHECK (precio >= 0),
    PRIMARY KEY (empresa_id, producto_id, nombre),
    UNIQUE KEY (empresa_id, codigo_barras),
    FOREIGN KEY (empresa_id, producto_id) REFERENCES productos (empresa_id, id)
) ENGINE=InnoDB;
