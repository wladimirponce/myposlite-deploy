ALTER TABLE producto_presentaciones
    ADD COLUMN IF NOT EXISTS id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE;
ALTER TABLE producto_presentaciones
    ADD UNIQUE INDEX IF NOT EXISTS ux_presentacion_empresa (empresa_id, id);

CREATE TABLE IF NOT EXISTS promociones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    presentacion_id BIGINT UNSIGNED NOT NULL,
    local_codigo VARCHAR(24) NULL,
    nombre VARCHAR(100) NOT NULL,
    tipo ENUM('2x1', 'pack', 'horario') NOT NULL,
    cantidad INT UNSIGNED NOT NULL,
    precio DECIMAL(14,0) NULL,
    desde DATE NOT NULL,
    hasta DATE NOT NULL,
    hora_inicio TIME NULL,
    hora_fin TIME NULL,
    activa BOOLEAN NOT NULL DEFAULT TRUE,
    FOREIGN KEY (empresa_id, presentacion_id) REFERENCES producto_presentaciones (empresa_id, id),
    FOREIGN KEY (empresa_id, local_codigo) REFERENCES locales (empresa_id, codigo),
    KEY (empresa_id, activa, desde, hasta),
    CHECK (desde <= hasta),
    CHECK (precio IS NULL OR precio >= 0),
    CHECK (
        (tipo = '2x1' AND cantidad = 2 AND precio IS NULL AND hora_inicio IS NULL AND hora_fin IS NULL)
        OR (tipo = 'pack' AND cantidad >= 2 AND precio IS NOT NULL AND hora_inicio IS NULL AND hora_fin IS NULL)
        OR (tipo = 'horario' AND cantidad = 1 AND precio IS NOT NULL
            AND hora_inicio IS NOT NULL AND hora_fin IS NOT NULL AND hora_inicio <> hora_fin)
    )
) ENGINE=InnoDB;
