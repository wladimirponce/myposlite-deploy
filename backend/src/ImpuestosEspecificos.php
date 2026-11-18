<?php

declare(strict_types=1);

/**
 * Tasas de impuestos específicos vigentes en una fecha: el ajuste más reciente de la empresa
 * prevalece sobre la tabla base del SII.
 *
 * @return list<array{codigo: string, nombre: string, vigente_desde: string, porcentaje: string, utm_por_unidad: string,
 *   codigo_dte: ?string, fuente: ?string, origen: string}>
 */
function tasasImpuestosEspecificos(PDO $db, string $empresa, string $fecha): array
{
    $query = $db->prepare("WITH reglas AS (
        SELECT impuesto_codigo, vigente_desde, porcentaje, utm_por_unidad, codigo_dte, fuente, 'sii' AS origen, 0 AS prioridad
        FROM impuestos_especificos_vigencias WHERE vigente_desde <= ?
        UNION ALL
        SELECT impuesto_codigo, vigente_desde, porcentaje, utm_por_unidad, codigo_dte, NULL, 'empresa', 1
        FROM empresa_impuestos_especificos_vigencias WHERE empresa_id = ? AND vigente_desde <= ?
    ), ordenadas AS (
        SELECT reglas.*, ROW_NUMBER() OVER (PARTITION BY impuesto_codigo ORDER BY prioridad DESC, vigente_desde DESC) AS numero
        FROM reglas
    )
    SELECT i.codigo, i.nombre, r.vigente_desde, r.porcentaje, r.utm_por_unidad, r.codigo_dte, r.fuente, r.origen
    FROM impuestos_especificos i JOIN ordenadas r ON r.impuesto_codigo = i.codigo AND r.numero = 1
    ORDER BY i.nombre");
    $query->execute([$fecha, $empresa, $fecha]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Porcentaje del impuesto adicional que el comercio paga en la compra y suma a su costo, en una fecha.
 *
 * Según el SII, el minorista no recarga el impuesto adicional del artículo 42 en sus ventas con boleta y solo
 * recupera como crédito la proporción de sus ventas afectas, con factura, a otros comerciantes. Hasta que Lite
 * emita facturas (Sprint 06) se trata como no recuperable: todo entra al costo. Solo cuentan los impuestos que la
 * factura de compra recarga por separado, los que tienen código DTE; el impuesto al tabaco ya viene en el precio.
 * Devuelve '0' sin impuesto o sin tasa vigente.
 */
function porcentajeImpuestoEnCosto(PDO $db, string $empresa, ?string $codigo, string $fecha): string
{
    static $cache = [];
    if ($codigo === null) {
        return '0';
    }
    $cache[$empresa][$fecha] ??= array_column(tasasImpuestosEspecificos($db, $empresa, $fecha), null, 'codigo');
    $tasa = $cache[$empresa][$fecha][$codigo] ?? null;
    return $tasa === null || $tasa['codigo_dte'] === null ? '0' : (string) $tasa['porcentaje'];
}
