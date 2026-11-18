<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/Emision.php';

// Primera tarea local. Cada ejecución abre su propia conexión y deja evidencia en Docker.
while (true) {
    try {
        $database = conectarBaseLite();
        $database->query('SELECT 1')->fetchColumn();
        fwrite(STDOUT, gmdate(DATE_ATOM) . " scheduler: MariaDB disponible\n");
        $database->exec('DELETE FROM intentos_sesion WHERE expira_en <= UNIX_TIMESTAMP() LIMIT 1000');
        // Boletas de venta: arma lotes, reintenta los pendientes y consulta su resultado en el SII.
        $empresas = $database->query("SELECT DISTINCT empresa_id FROM dte_documentos WHERE ambiente = 'produccion' AND origen <> 'certificacion'
            AND estado IN ('preparado', 'pendiente', 'enviado')")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($empresas as $empresa) {
            $resumen = enviarBoletasPendientesDte($database, (string) $empresa);
            if ($resumen['lotes'] + $resumen['enviados'] + $resumen['pendientes'] + $resumen['consultados'] > 0 || $resumen['errores']) {
                fwrite(STDOUT, gmdate(DATE_ATOM) . " scheduler: boletas de la empresa $empresa " . json_encode($resumen, JSON_UNESCAPED_UNICODE) . "\n");
            }
        }
        // Correos: avisos de trámites detenidos (uno por semana) y entrega de la cola con reintentos.
        avisarTramitesDetenidosCorreo($database);
        // Informe diario por RUT y su actualización cuando el SII responde los pendientes.
        require_once __DIR__ . '/../src/InformeDiario.php';
        revisarInformesDiariosCorreo($database);
        $correos = entregarCorreosPendientes($database, 50);
        if (array_sum($correos) > 0) {
            fwrite(STDOUT, gmdate(DATE_ATOM) . ' scheduler: correos ' . json_encode($correos) . "\n");
        }
    } catch (Throwable $error) {
        fwrite(STDERR, gmdate(DATE_ATOM) . " scheduler: comprobación o limpieza de sesión fallida\n");
    } finally {
        unset($database);
    }

    sleep(60);
}
