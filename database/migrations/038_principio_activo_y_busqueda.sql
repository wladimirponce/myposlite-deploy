-- Migración 038: Principio Activo y Laboratorio para búsqueda contextual en Farmacia y Comercios
ALTER TABLE productos ADD COLUMN IF NOT EXISTS principio_activo VARCHAR(255) NULL;
ALTER TABLE productos ADD COLUMN IF NOT EXISTS laboratorio VARCHAR(100) NULL;
