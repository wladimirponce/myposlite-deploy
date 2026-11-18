-- Cuaderno / Listado de faltas: registro de productos solicitados por clientes que no hay en stock o no están en catálogo
CREATE TABLE IF NOT EXISTS faltas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    local_codigo VARCHAR(32) NOT NULL,
    producto_id BIGINT UNSIGNED NULL,
    descripcion VARCHAR(200) NOT NULL,
    cantidad DECIMAL(12, 3) NOT NULL DEFAULT 1.000,
    cliente_nombre VARCHAR(160) NULL,
    cliente_contacto VARCHAR(160) NULL,
    canal_preferido ENUM('llamada', 'whatsapp', 'correo', 'sms') NOT NULL DEFAULT 'llamada',
    observaciones VARCHAR(500) NULL,
    estado ENUM('pendiente', 'pedido', 'recibido', 'descartado') NOT NULL DEFAULT 'pendiente',
    creado_por BIGINT UNSIGNED NULL,
    creado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    actualizado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    KEY (empresa_id, local_codigo, estado),
    KEY (empresa_id, creado_en)
) ENGINE=InnoDB;
