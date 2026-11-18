-- 035_folios_pool_descentralizado.sql
-- Gestión de Bolsón de Folios Descentralizado para Modo Offline Resiliente

CREATE TABLE IF NOT EXISTS dte_folios_pool (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    empresa_id VARCHAR(64) NOT NULL,
    tipo_dte INT NOT NULL DEFAULT 39, -- 39: Boleta Electrónica, 33: Factura, 41: Exenta
    folio_desde INT NOT NULL,
    folio_hasta INT NOT NULL,
    ultimo_asignado INT NOT NULL DEFAULT 0,
    disponibles INT NOT NULL,
    caf_xml MEDIUMTEXT NULL,
    umbral_alerta INT NOT NULL DEFAULT 3000,
    alerta_enviada_en DATETIME NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pool_empresa_tipo (empresa_id, tipo_dte)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dte_terminal_folios (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    empresa_id VARCHAR(64) NOT NULL,
    local_codigo VARCHAR(64) NOT NULL,
    terminal_id VARCHAR(64) NOT NULL,
    tipo_dte INT NOT NULL DEFAULT 39,
    rango_desde INT NOT NULL,
    rango_hasta INT NOT NULL,
    cantidad INT NOT NULL,
    ultimo_folio_usado INT NULL,
    asignado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_term_asig (empresa_id, local_codigo, terminal_id, tipo_dte)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sembrar bolsa inicial de 50.000 folios para la empresa de demostración piloto
INSERT INTO dte_folios_pool (id, empresa_id, tipo_dte, folio_desde, folio_hasta, ultimo_asignado, disponibles, umbral_alerta)
SELECT 'pool-demo-39', id, 39, 1, 50000, 0, 50000, 3000
FROM empresas
WHERE id = 'empresa-demo'
ON DUPLICATE KEY UPDATE disponibles = disponibles;
