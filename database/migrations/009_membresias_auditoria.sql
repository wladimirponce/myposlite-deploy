CREATE TABLE IF NOT EXISTS membresia_eventos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    accion ENUM('crear', 'editar') NOT NULL,
    registrado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY (empresa_id, usuario_id) REFERENCES membresias (empresa_id, usuario_id),
    FOREIGN KEY (actor_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;
