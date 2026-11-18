CREATE TABLE IF NOT EXISTS categorias_producto (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    nombre VARCHAR(80) NOT NULL,
    UNIQUE KEY (empresa_id, id),
    UNIQUE KEY (empresa_id, nombre),
    FOREIGN KEY (empresa_id) REFERENCES empresas (id)
) ENGINE=InnoDB;

ALTER TABLE productos ADD COLUMN IF NOT EXISTS categoria_id BIGINT UNSIGNED NULL;
ALTER TABLE productos ADD COLUMN IF NOT EXISTS revision BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE productos ADD CONSTRAINT fk_productos_categoria
    FOREIGN KEY IF NOT EXISTS (empresa_id, categoria_id) REFERENCES categorias_producto (empresa_id, id);

CREATE TABLE IF NOT EXISTS producto_minimos (
    empresa_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    local_codigo VARCHAR(24) NOT NULL,
    minimo DECIMAL(18,6) NOT NULL CHECK (minimo >= 0),
    PRIMARY KEY (empresa_id, producto_id, local_codigo),
    FOREIGN KEY (empresa_id, producto_id) REFERENCES productos (empresa_id, id),
    FOREIGN KEY (empresa_id, local_codigo) REFERENCES locales (empresa_id, codigo)
) ENGINE=InnoDB;
