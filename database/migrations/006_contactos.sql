CREATE TABLE IF NOT EXISTS contactos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    rut VARCHAR(9) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    razon_social VARCHAR(160) NOT NULL,
    correo VARCHAR(254) NULL,
    proveedor BOOLEAN NOT NULL,
    receptor BOOLEAN NOT NULL,
    UNIQUE KEY (empresa_id, rut),
    UNIQUE KEY (empresa_id, id),
    FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CHECK (proveedor OR receptor)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contacto_eventos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    contacto_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    accion ENUM('crear', 'editar') NOT NULL,
    registrado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY (empresa_id, contacto_id) REFERENCES contactos (empresa_id, id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;
