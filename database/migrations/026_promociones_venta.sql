-- Promoción aplicada a cada línea de venta: una por línea, la de mayor descuento vigente.
ALTER TABLE venta_lineas ADD COLUMN IF NOT EXISTS descuento BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE venta_lineas ADD COLUMN IF NOT EXISTS promocion_id BIGINT UNSIGNED NULL;
