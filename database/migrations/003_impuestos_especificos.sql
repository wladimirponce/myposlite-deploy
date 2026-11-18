CREATE TABLE IF NOT EXISTS impuestos_especificos (
    codigo VARCHAR(40) PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS impuestos_especificos_vigencias (
    impuesto_codigo VARCHAR(40) NOT NULL,
    vigente_desde DATE NOT NULL,
    porcentaje DECIMAL(9,6) NOT NULL,
    utm_por_unidad DECIMAL(14,10) NOT NULL DEFAULT 0,
    codigo_dte SMALLINT UNSIGNED NULL,
    fuente VARCHAR(500) NOT NULL,
    PRIMARY KEY (impuesto_codigo, vigente_desde),
    FOREIGN KEY (impuesto_codigo) REFERENCES impuestos_especificos (codigo),
    CHECK (porcentaje BETWEEN 0 AND 100),
    CHECK (utm_por_unidad >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS empresa_impuestos_especificos_vigencias (
    empresa_id BIGINT UNSIGNED NOT NULL,
    impuesto_codigo VARCHAR(40) NOT NULL,
    vigente_desde DATE NOT NULL,
    porcentaje DECIMAL(9,6) NOT NULL,
    utm_por_unidad DECIMAL(14,10) NOT NULL DEFAULT 0,
    codigo_dte SMALLINT UNSIGNED NULL,
    PRIMARY KEY (empresa_id, impuesto_codigo, vigente_desde),
    FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    FOREIGN KEY (impuesto_codigo) REFERENCES impuestos_especificos (codigo),
    CHECK (porcentaje BETWEEN 0 AND 100),
    CHECK (utm_por_unidad >= 0)
) ENGINE=InnoDB;
