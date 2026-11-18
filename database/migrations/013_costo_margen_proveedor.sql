ALTER TABLE productos ADD COLUMN IF NOT EXISTS costo DECIMAL(14,2) NULL CHECK (costo >= 0);
ALTER TABLE productos ADD COLUMN IF NOT EXISTS margen_minimo DECIMAL(4,2) NULL CHECK (margen_minimo >= 0);
ALTER TABLE productos ADD COLUMN IF NOT EXISTS proveedor_habitual_id BIGINT UNSIGNED NULL;
ALTER TABLE productos ADD CONSTRAINT fk_productos_proveedor_habitual
    FOREIGN KEY IF NOT EXISTS (empresa_id, proveedor_habitual_id) REFERENCES contactos (empresa_id, id);
