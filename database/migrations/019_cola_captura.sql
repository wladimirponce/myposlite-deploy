ALTER TABLE archivos_recibidos ADD COLUMN IF NOT EXISTS descartado_en DATETIME(6) NULL;
ALTER TABLE archivos_recibidos ADD COLUMN IF NOT EXISTS descartado_por BIGINT UNSIGNED NULL;
ALTER TABLE archivos_recibidos ADD CONSTRAINT fk_archivos_recibidos_descartado_por
    FOREIGN KEY IF NOT EXISTS (descartado_por) REFERENCES usuarios (id);
ALTER TABLE archivos_recibidos ADD INDEX IF NOT EXISTS cola_local (empresa_id, local_codigo, documento_id, descartado_en);
