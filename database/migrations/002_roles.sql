INSERT IGNORE INTO roles (codigo) VALUES
    ('administrador'), ('supervisor'), ('vendedor'), ('contador');

INSERT IGNORE INTO permisos (codigo) VALUES
    ('empresa.configurar'), ('maestros.editar'), ('pos.vender'),
    ('caja.supervisar'), ('operacion.consultar'), ('operacion.exportar');

INSERT IGNORE INTO roles_permisos (rol_codigo, permiso_codigo)
    SELECT 'administrador', codigo FROM permisos;

INSERT IGNORE INTO roles_permisos (rol_codigo, permiso_codigo) VALUES
    ('supervisor', 'maestros.editar'), ('supervisor', 'operacion.consultar'),
    ('supervisor', 'caja.supervisar'), ('vendedor', 'pos.vender'),
    ('contador', 'operacion.consultar'), ('contador', 'operacion.exportar');
