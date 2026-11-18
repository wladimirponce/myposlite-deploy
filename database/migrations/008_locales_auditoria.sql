CREATE TABLE IF NOT EXISTS ubicacion_eventos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    local_codigo VARCHAR(24) NOT NULL,
    caja_codigo VARCHAR(24) NULL,
    accion ENUM('crear', 'editar') NOT NULL,
    registrado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY (empresa_id, local_codigo) REFERENCES locales (empresa_id, codigo),
    FOREIGN KEY (empresa_id, local_codigo, caja_codigo) REFERENCES cajas (empresa_id, local_codigo, codigo),
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;
