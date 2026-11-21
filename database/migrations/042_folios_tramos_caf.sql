-- 042_folios_tramos_caf.sql
-- Vincula los tramos de folios asignados a cada local/terminal con el CAF real de producción (dte_cafs)
-- y agrega soporte para auditoría de alertas de reposición de folios por empresa.

ALTER TABLE dte_terminal_folios
    ADD COLUMN IF NOT EXISTS caf_id BIGINT UNSIGNED NULL AFTER tipo_dte,
    ADD COLUMN IF NOT EXISTS ultimo_folio_usado INT NULL AFTER cantidad;

CREATE INDEX IF NOT EXISTS idx_term_folios_rango
    ON dte_terminal_folios (empresa_id, local_codigo, terminal_id, tipo_dte, rango_desde, rango_hasta);
