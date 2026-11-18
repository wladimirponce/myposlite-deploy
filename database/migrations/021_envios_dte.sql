ALTER TABLE dte_envios MODIFY estado
    ENUM('preparado', 'enviando', 'enviado', 'aceptado', 'reparo', 'rechazado', 'pendiente') NOT NULL DEFAULT 'preparado';
ALTER TABLE dte_envios ADD COLUMN IF NOT EXISTS glosa VARCHAR(400) NULL;
ALTER TABLE dte_envios ADD COLUMN IF NOT EXISTS intentos INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE dte_envios ADD COLUMN IF NOT EXISTS actualizado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6);

CREATE TABLE IF NOT EXISTS dte_intentos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    envio_id BIGINT UNSIGNED NOT NULL,
    accion ENUM('enviar', 'consultar') NOT NULL,
    estado VARCHAR(20) NOT NULL,
    track_id VARCHAR(40) NULL,
    huella_enviada CHAR(64) CHARACTER SET ascii NULL,
    respuesta TEXT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    registrado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY (empresa_id, envio_id, id),
    FOREIGN KEY (empresa_id, envio_id) REFERENCES dte_envios (empresa_id, id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;
