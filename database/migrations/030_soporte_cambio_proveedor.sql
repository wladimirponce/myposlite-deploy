-- Equipo de soporte de Lite: se otorga solo desde el servidor (database/soporte.php), nunca desde la aplicación.
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS soporte BOOLEAN NOT NULL DEFAULT FALSE;

-- Pedidos de ayuda desde el expediente, con una foto del expediente sin secretos.
CREATE TABLE IF NOT EXISTS soporte_solicitudes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    tramite ENUM('certificacion_boletas', 'etapa_2', 'cambio_proveedor', 'emision') NOT NULL,
    paso VARCHAR(80) NOT NULL,
    mensaje VARCHAR(2000) NOT NULL,
    contexto JSON NOT NULL,
    estado ENUM('abierta', 'respondida', 'cerrada') NOT NULL DEFAULT 'abierta',
    respuesta VARCHAR(2000) NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    respondida_por BIGINT UNSIGNED NULL,
    creada_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    respondida_en DATETIME(6) NULL,
    KEY (empresa_id, id),
    KEY (estado, id),
    FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    FOREIGN KEY (respondida_por) REFERENCES usuarios (id)
) ENGINE=InnoDB;

-- Alta de una empresa que ya emitía con otro proveedor: los tipos ya autorizados por el SII pasan a producción sin certificar.
CREATE TABLE IF NOT EXISTS dte_cambios_proveedor (
    empresa_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    proveedor_anterior VARCHAR(80) NOT NULL,
    tipos JSON NOT NULL,
    fecha_corte DATE NOT NULL,
    pendientes VARCHAR(1000) NULL,
    registrado_por BIGINT UNSIGNED NOT NULL,
    registrado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    FOREIGN KEY (registrado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB;

-- Rangos de folios de producción asignados a otro sistema del mismo RUT: Lite no carga un CAF que los toque.
CREATE TABLE IF NOT EXISTS dte_rangos_externos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    tipo SMALLINT UNSIGNED NOT NULL,
    desde BIGINT UNSIGNED NOT NULL,
    hasta BIGINT UNSIGNED NOT NULL,
    sistema VARCHAR(80) NOT NULL,
    registrado_por BIGINT UNSIGNED NOT NULL,
    registrado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY (empresa_id, tipo, desde),
    FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    FOREIGN KEY (registrado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB;
