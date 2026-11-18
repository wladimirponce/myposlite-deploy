CREATE TABLE IF NOT EXISTS dte_habilitaciones (
    empresa_id BIGINT UNSIGNED NOT NULL,
    tipo SMALLINT UNSIGNED NOT NULL,
    estado ENUM('sin_iniciar', 'en_certificacion', 'visto_bueno', 'produccion') NOT NULL,
    atencion_revision VARCHAR(40) NULL,
    visto_bueno_en DATE NULL,
    produccion_desde DATE NULL,
    actualizado_por BIGINT UNSIGNED NOT NULL,
    actualizado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (empresa_id, tipo),
    FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dte_habilitacion_eventos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    tipo SMALLINT UNSIGNED NOT NULL,
    estado_anterior VARCHAR(20) NOT NULL,
    estado_nuevo VARCHAR(20) NOT NULL,
    detalle VARCHAR(200) NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    registrado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY (empresa_id, tipo, id),
    FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;
