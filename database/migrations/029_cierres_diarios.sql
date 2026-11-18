CREATE TABLE IF NOT EXISTS cierres_diarios (
    empresa_id BIGINT UNSIGNED NOT NULL,
    local_codigo VARCHAR(24) NOT NULL,
    fecha DATE NOT NULL,
    totales JSON NOT NULL,
    cerrado_por BIGINT UNSIGNED NOT NULL,
    cerrado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (empresa_id, local_codigo, fecha),
    FOREIGN KEY (empresa_id, local_codigo) REFERENCES locales (empresa_id, codigo),
    FOREIGN KEY (cerrado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB;

ALTER TABLE ventas ADD INDEX IF NOT EXISTS ix_ventas_jornada (empresa_id, local_codigo, creada_en);
ALTER TABLE turnos ADD INDEX IF NOT EXISTS ix_turnos_jornada (empresa_id, local_codigo, abierto_en);
ALTER TABLE dte_documentos ADD INDEX IF NOT EXISTS ix_dte_jornada (empresa_id, ambiente, fecha_emision, tipo, folio);
ALTER TABLE devoluciones ADD INDEX IF NOT EXISTS ix_devoluciones_documento (empresa_id, documento_id);
