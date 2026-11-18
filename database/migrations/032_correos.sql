-- Correos salientes: cola con una clave por empresa, para que un reintento nunca duplique un aviso.
CREATE TABLE IF NOT EXISTS correo_envios (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    tipo ENUM('verificacion', 'documento', 'tramite', 'cierre') NOT NULL,
    destinatario VARCHAR(254) NOT NULL,
    asunto VARCHAR(200) NOT NULL,
    cuerpo TEXT NOT NULL,
    documento_id BIGINT UNSIGNED NULL,
    clave VARCHAR(160) NOT NULL,
    estado ENUM('pendiente', 'enviado', 'fallido') NOT NULL DEFAULT 'pendiente',
    intentos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_error VARCHAR(300) NULL,
    proximo_intento DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    creado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    enviado_en DATETIME(6) NULL,
    UNIQUE KEY (empresa_id, clave),
    KEY (estado, proximo_intento),
    KEY (empresa_id, id),
    FOREIGN KEY (empresa_id) REFERENCES empresas (id)
) ENGINE=InnoDB;

-- Verificación de destinatarios por enlace: se guarda solo la huella del token.
CREATE TABLE IF NOT EXISTS correo_verificaciones (
    token_hash CHAR(64) CHARACTER SET ascii PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    correo VARCHAR(254) NOT NULL,
    vence_en DATETIME(6) NOT NULL,
    usado_en DATETIME(6) NULL,
    creado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY (empresa_id, correo),
    FOREIGN KEY (empresa_id) REFERENCES empresas (id)
) ENGINE=InnoDB;
