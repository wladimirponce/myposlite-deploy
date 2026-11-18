ALTER TABLE producto_eventos MODIFY accion ENUM('crear', 'editar', 'costo_recepcion') NOT NULL;
ALTER TABLE producto_eventos ADD COLUMN IF NOT EXISTS documento_id BIGINT UNSIGNED NULL;
ALTER TABLE producto_eventos ADD COLUMN IF NOT EXISTS costo_anterior DECIMAL(14,2) NULL;
ALTER TABLE producto_eventos ADD COLUMN IF NOT EXISTS costo_nuevo DECIMAL(14,2) NULL;
ALTER TABLE producto_eventos ADD CONSTRAINT fk_producto_eventos_documento
    FOREIGN KEY IF NOT EXISTS (empresa_id, documento_id) REFERENCES documentos_recibidos (empresa_id, id);
