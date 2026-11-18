-- Baja de empresa: registro que sobrevive a la eliminación, por eso no tiene llaves foráneas a la empresa ni a usuarios.
CREATE TABLE IF NOT EXISTS empresa_bajas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    rut VARCHAR(9) CHARACTER SET ascii NOT NULL,
    razon_social VARCHAR(160) NOT NULL,
    estado ENUM('preparada', 'descargada', 'confirmada', 'eliminada') NOT NULL,
    archivo VARCHAR(255) NULL,
    huella CHAR(64) CHARACTER SET ascii NULL,
    tamano BIGINT UNSIGNED NULL,
    resumen JSON NOT NULL,
    preparada_por BIGINT UNSIGNED NOT NULL,
    preparada_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    descargada_en DATETIME(6) NULL,
    confirmada_por BIGINT UNSIGNED NULL,
    confirmada_en DATETIME(6) NULL,
    eliminada_en DATETIME(6) NULL,
    eliminado JSON NULL,
    UNIQUE KEY (empresa_id)
) ENGINE=InnoDB;
