CREATE TABLE IF NOT EXISTS producto_eventos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    local_codigo VARCHAR(24) NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    revision BIGINT UNSIGNED NOT NULL,
    accion ENUM('crear', 'editar') NOT NULL,
    registrado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (empresa_id, producto_id, revision),
    FOREIGN KEY (empresa_id, producto_id) REFERENCES productos (empresa_id, id),
    FOREIGN KEY (empresa_id, local_codigo) REFERENCES locales (empresa_id, codigo),
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS intentos_sesion (
    cuenta_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    fallidos TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expira_en BIGINT UNSIGNED NOT NULL,
    KEY (expira_en)
) ENGINE=InnoDB;
