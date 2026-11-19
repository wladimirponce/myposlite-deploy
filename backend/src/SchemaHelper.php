<?php

declare(strict_types=1);

final class SchemaHelper
{
    private static bool $productosAsegurado = false;

    /**
     * Asegura que la tabla productos y empresas cuenten con todas las columnas
     * necesarias para los distintos rubros (Farmacia, Minimarket, Botillería, Ferretería, etc.),
     * garantizando que cualquier catálogo heterogéneo quepa sin errores en el repositorio común.
     * 
     * Retorna el listado de columnas disponibles en la tabla productos (en minúsculas).
     */
    public static function asegurarColumnasProductos(PDO $db): array
    {
        try {
            $cols = $db->query('SHOW COLUMNS FROM productos')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $colsLower = array_map('strtolower', $cols);
        } catch (\Throwable $e) {
            return [];
        }

        // Columnas necesarias para soportar múltiples industrias en el mismo repositorio
        $columnasRequeridas = [
            'principio_activo' => 'VARCHAR(255) NULL',
            'laboratorio' => 'VARCHAR(100) NULL',
            'imagen_url' => 'VARCHAR(255) NULL',
            'categoria_id' => 'BIGINT UNSIGNED NULL',
            'impuesto_codigo' => 'VARCHAR(40) NULL',
            'costo' => 'DECIMAL(14,2) NULL',
            'margen_minimo' => 'DECIMAL(4,2) NULL',
            'proveedor_habitual_id' => 'BIGINT UNSIGNED NULL',
            'revision' => 'BIGINT UNSIGNED NOT NULL DEFAULT 1',
        ];

        foreach ($columnasRequeridas as $col => $definicion) {
            if (!in_array(strtolower($col), $colsLower, true)) {
                try {
                    $db->exec("ALTER TABLE productos ADD COLUMN {$col} {$definicion}");
                    $colsLower[] = strtolower($col);
                } catch (\Throwable $e) {
                    // Si ya existe por concurrencia o no tiene permiso ALTER, continuar
                }
            }
        }

        // Asegurar también columna 'rubro' en tabla empresas si no existiera
        try {
            $colsEmp = $db->query('SHOW COLUMNS FROM empresas')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $colsEmpLower = array_map('strtolower', $colsEmp);
            if (!in_array('rubro', $colsEmpLower, true)) {
                $db->exec("ALTER TABLE empresas ADD COLUMN rubro VARCHAR(100) NOT NULL DEFAULT 'farmacia'");
            }
        } catch (\Throwable $e) {
            // Ignorar silenciosamente
        }

        self::$productosAsegurado = true;
        return $colsLower;
    }
}
