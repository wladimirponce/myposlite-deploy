-- Migración 037: Rubro de negocio para empresas y carga de catálogos maestros
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS rubro VARCHAR(32) NOT NULL DEFAULT 'otro';
