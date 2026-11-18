CREATE TABLE IF NOT EXISTS dte_emisores (
    empresa_id BIGINT UNSIGNED NOT NULL,
    ambiente ENUM('certificacion', 'produccion') NOT NULL,
    giro VARCHAR(80) NOT NULL,
    direccion VARCHAR(70) NOT NULL,
    comuna VARCHAR(20) NOT NULL,
    numero_resolucion INT UNSIGNED NOT NULL,
    fecha_resolucion DATE NOT NULL,
    actualizado_por BIGINT UNSIGNED NOT NULL,
    actualizado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (empresa_id, ambiente),
    FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dte_certificados (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    ambiente ENUM('certificacion', 'produccion') NOT NULL,
    pfx_cifrado LONGBLOB NOT NULL,
    clave_cifrada BLOB NOT NULL,
    huella CHAR(64) CHARACTER SET ascii NOT NULL,
    rut_titular VARCHAR(10) CHARACTER SET ascii NOT NULL,
    nombre_titular VARCHAR(160) NOT NULL,
    valido_desde DATETIME NOT NULL,
    valido_hasta DATETIME NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    cargado_por BIGINT UNSIGNED NOT NULL,
    cargado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (empresa_id, ambiente, huella),
    KEY (empresa_id, ambiente, activo),
    FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    FOREIGN KEY (cargado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dte_cafs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    ambiente ENUM('certificacion', 'produccion') NOT NULL,
    tipo SMALLINT UNSIGNED NOT NULL,
    desde BIGINT UNSIGNED NOT NULL,
    hasta BIGINT UNSIGNED NOT NULL,
    siguiente BIGINT UNSIGNED NOT NULL,
    fecha_autorizacion DATE NOT NULL,
    idk INT UNSIGNED NOT NULL,
    xml_cifrado BLOB NOT NULL,
    huella CHAR(64) CHARACTER SET ascii NOT NULL,
    cargado_por BIGINT UNSIGNED NOT NULL,
    cargado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (empresa_id, id),
    UNIQUE KEY (empresa_id, huella),
    KEY (empresa_id, ambiente, tipo, desde),
    CHECK (desde <= hasta AND siguiente BETWEEN desde AND hasta + 1),
    FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    FOREIGN KEY (cargado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dte_documentos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    ambiente ENUM('certificacion', 'produccion') NOT NULL,
    tipo SMALLINT UNSIGNED NOT NULL,
    folio BIGINT UNSIGNED NOT NULL,
    caf_id BIGINT UNSIGNED NOT NULL,
    clave VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    origen ENUM('certificacion', 'venta') NOT NULL,
    referencia VARCHAR(120) NULL,
    fecha_emision DATE NOT NULL,
    monto_total BIGINT UNSIGNED NOT NULL,
    xml LONGBLOB NOT NULL,
    estado ENUM('preparado', 'enviado', 'aceptado', 'reparo', 'rechazado', 'pendiente') NOT NULL DEFAULT 'preparado',
    track_id VARCHAR(40) NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    creado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (empresa_id, id),
    UNIQUE KEY (empresa_id, ambiente, tipo, folio),
    UNIQUE KEY (empresa_id, clave),
    FOREIGN KEY (empresa_id, caf_id) REFERENCES dte_cafs (empresa_id, id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dte_envios (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    ambiente ENUM('certificacion', 'produccion') NOT NULL,
    motivo ENUM('set_boletas', 'boletas') NOT NULL,
    clave VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    xml LONGBLOB NOT NULL,
    huella CHAR(64) CHARACTER SET ascii NOT NULL,
    rut_envia VARCHAR(10) CHARACTER SET ascii NOT NULL,
    estado ENUM('preparado', 'enviado', 'aceptado', 'reparo', 'rechazado', 'pendiente') NOT NULL DEFAULT 'preparado',
    track_id VARCHAR(40) NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    creado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (empresa_id, id),
    UNIQUE KEY (empresa_id, clave),
    FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dte_envio_documentos (
    empresa_id BIGINT UNSIGNED NOT NULL,
    envio_id BIGINT UNSIGNED NOT NULL,
    documento_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (empresa_id, envio_id, documento_id),
    FOREIGN KEY (empresa_id, envio_id) REFERENCES dte_envios (empresa_id, id),
    FOREIGN KEY (empresa_id, documento_id) REFERENCES dte_documentos (empresa_id, id)
) ENGINE=InnoDB;
