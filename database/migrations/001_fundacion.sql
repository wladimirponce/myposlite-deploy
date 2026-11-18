CREATE TABLE IF NOT EXISTS empresas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rut VARCHAR(9) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    razon_social VARCHAR(160) NOT NULL,
    zona_horaria VARCHAR(64) NOT NULL DEFAULT 'America/Santiago',
    activa BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS locales (
    empresa_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(24) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    PRIMARY KEY (empresa_id, codigo),
    FOREIGN KEY (empresa_id) REFERENCES empresas (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cajas (
    empresa_id BIGINT UNSIGNED NOT NULL,
    local_codigo VARCHAR(24) NOT NULL,
    codigo VARCHAR(24) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    PRIMARY KEY (empresa_id, local_codigo, codigo),
    FOREIGN KEY (empresa_id, local_codigo) REFERENCES locales (empresa_id, codigo)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS usuarios (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    correo VARCHAR(254) NOT NULL UNIQUE,
    nombre VARCHAR(120) NOT NULL,
    clave_hash VARCHAR(255) NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS roles (
    codigo VARCHAR(32) PRIMARY KEY
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS permisos (
    codigo VARCHAR(64) PRIMARY KEY
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS roles_permisos (
    rol_codigo VARCHAR(32) NOT NULL,
    permiso_codigo VARCHAR(64) NOT NULL,
    PRIMARY KEY (rol_codigo, permiso_codigo),
    FOREIGN KEY (rol_codigo) REFERENCES roles (codigo),
    FOREIGN KEY (permiso_codigo) REFERENCES permisos (codigo)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS membresias (
    empresa_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    rol_codigo VARCHAR(32) NOT NULL,
    activa BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (empresa_id, usuario_id),
    KEY idx_membresias_usuario (usuario_id, activa),
    FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    FOREIGN KEY (rol_codigo) REFERENCES roles (codigo)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS membresias_locales (
    empresa_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    local_codigo VARCHAR(24) NOT NULL,
    PRIMARY KEY (empresa_id, usuario_id, local_codigo),
    FOREIGN KEY (empresa_id, usuario_id) REFERENCES membresias (empresa_id, usuario_id),
    FOREIGN KEY (empresa_id, local_codigo) REFERENCES locales (empresa_id, codigo)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS membresias_cajas (
    empresa_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    local_codigo VARCHAR(24) NOT NULL,
    caja_codigo VARCHAR(24) NOT NULL,
    PRIMARY KEY (empresa_id, usuario_id, local_codigo, caja_codigo),
    FOREIGN KEY (empresa_id, usuario_id, local_codigo)
        REFERENCES membresias_locales (empresa_id, usuario_id, local_codigo),
    FOREIGN KEY (empresa_id, local_codigo, caja_codigo)
        REFERENCES cajas (empresa_id, local_codigo, codigo)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS destinatarios_cierre (
    empresa_id BIGINT UNSIGNED NOT NULL,
    correo VARCHAR(254) NOT NULL,
    verificado_en DATETIME(6) NULL,
    PRIMARY KEY (empresa_id, correo),
    FOREIGN KEY (empresa_id) REFERENCES empresas (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS destinatario_cierre_principal (
    empresa_id BIGINT UNSIGNED PRIMARY KEY,
    correo VARCHAR(254) NOT NULL,
    FOREIGN KEY (empresa_id, correo) REFERENCES destinatarios_cierre (empresa_id, correo)
) ENGINE=InnoDB;
