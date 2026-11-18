CREATE TABLE IF NOT EXISTS archivos_recibidos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    huella CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tipo_mime VARCHAR(40) CHARACTER SET ascii NOT NULL,
    tamano INT UNSIGNED NOT NULL CHECK (tamano > 0),
    nombre VARCHAR(160) NOT NULL,
    local_codigo VARCHAR(24) NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    dispositivo VARCHAR(200) NULL,
    subido_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    documento_id BIGINT UNSIGNED NULL,
    UNIQUE KEY (empresa_id, huella),
    UNIQUE KEY (empresa_id, id),
    KEY (empresa_id, documento_id),
    FOREIGN KEY (empresa_id, local_codigo) REFERENCES locales (empresa_id, codigo),
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    FOREIGN KEY (empresa_id, documento_id) REFERENCES documentos_recibidos (empresa_id, id)
) ENGINE=InnoDB;
